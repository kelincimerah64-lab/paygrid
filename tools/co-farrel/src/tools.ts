import { execFile } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { promisify } from 'node:util';
import { createSdkMcpServer, tool } from '@anthropic-ai/claude-agent-sdk';
import { z } from 'zod';
import { redact, resolveReadablePath, SEARCH_ROOTS } from './guard.ts';
import type { Proposal } from './requests.ts';

const run = promisify(execFile);
const text = (value: string) => ({ content: [{ type: 'text' as const, text: redact(value) }] });
const MAX_SEARCH_HITS = 50;
const MAX_FILE_BYTES = 512 * 1024;

/**
 * Read-only tools for stage 1. Each one reads a fixed thing in the PayGrid folder and redacts
 * the output; none writes a file, changes the database or calls an API that changes state.
 */
export function paygridTools(paygridDir: string, onProposal?: (proposal: Proposal) => void, phpBin = process.env.PHP_BIN || 'php') {
  const artisan = async (args: string[]) => {
    const { stdout } = await run(phpBin, ['artisan', ...args], { cwd: paygridDir, timeout: 30_000, maxBuffer: 2 * 1024 * 1024 });
    return stdout;
  };

  const readCode = tool(
    'read_code',
    'Read a source file of the PayGrid app (app, bootstrap, config, database, docs, resources, routes, tests). Path is relative to the app root. .env, keys, storage and vendor are not readable.',
    {
      path: z.string().describe('Relative path, e.g. app/Services/BotTele/SupportFlow.php'),
      start_line: z.number().int().min(1).optional(),
      max_lines: z.number().int().min(1).max(400).optional(),
    },
    async ({ path: requested, start_line = 1, max_lines = 200 }) => {
      const file = resolveReadablePath(paygridDir, requested);
      if (!file) {
        return text(`Refused: ${requested} is outside the readable folders or is a secret file.`);
      }
      const lines = (await fs.readFile(file, 'utf8')).split('\n');
      const slice = lines.slice(start_line - 1, start_line - 1 + max_lines);
      return text(`${requested} (lines ${start_line}-${start_line + slice.length - 1} of ${lines.length})\n` +
        slice.map((line, i) => `${start_line + i}: ${line}`).join('\n'));
    },
    { annotations: { readOnlyHint: true } },
  );

  const searchCode = tool(
    'search_code',
    'Search the PayGrid source for a regular expression (case-insensitive). Returns up to 50 matching lines with file and line number.',
    {
      pattern: z.string().min(2),
      dir: z.string().optional().describe('Limit to one folder, e.g. app/Services/BotTele'),
    },
    async ({ pattern, dir }) => {
      let regex: RegExp;
      try {
        regex = new RegExp(pattern, 'i');
      } catch {
        return text(`Invalid regular expression: ${pattern}`);
      }
      const roots = dir ? [dir] : SEARCH_ROOTS;
      const hits: string[] = [];
      for (const root of roots) {
        const start = resolveReadablePath(paygridDir, root);
        if (start && (await fs.stat(start).catch(() => null))) {
          await walk(start, async (file) => {
            const relative = path.relative(paygridDir, file).split(path.sep).join('/');
            if (hits.length >= MAX_SEARCH_HITS || !resolveReadablePath(paygridDir, relative)) return;
            const stat = await fs.stat(file);
            if (stat.size > MAX_FILE_BYTES) return;
            (await fs.readFile(file, 'utf8')).split('\n').forEach((line, i) => {
              if (hits.length < MAX_SEARCH_HITS && regex.test(line)) hits.push(`${relative}:${i + 1}: ${line.trim()}`);
            });
          });
        }
      }
      return text(hits.length ? hits.join('\n') : 'No matches.');
    },
    { annotations: { readOnlyHint: true } },
  );

  const getLogs = tool(
    'get_logs',
    'Recent lines of storage/logs/laravel.log (UTC timestamps), optionally only lines containing a word such as "bottele". Secrets are redacted.',
    {
      contains: z.string().optional().describe('Case-insensitive filter, default "bottele"'),
      lines: z.number().int().min(1).max(200).optional(),
    },
    async ({ contains = 'bottele', lines = 50 }) => {
      const logFile = path.join(paygridDir, 'storage', 'logs', 'laravel.log');
      const handle = await fs.open(logFile, 'r');
      try {
        const { size } = await handle.stat();
        const length = Math.min(size, 4 * 1024 * 1024);
        const buffer = Buffer.alloc(length);
        await handle.read(buffer, 0, length, size - length);
        const needle = contains.toLowerCase();
        const matching = buffer.toString('utf8').split('\n').filter((line) => line.toLowerCase().includes(needle));
        return text(matching.length ? matching.slice(-lines).join('\n') : `No log lines containing "${contains}" in the last 4 MB.`);
      } finally {
        await handle.close();
      }
    },
    { annotations: { readOnlyHint: true } },
  );

  const getBotStatus = tool(
    'get_bot_status',
    'Health of the Telegram support bot: scheduled bottele jobs, Telegram webhook state, and ticket/attendance counts from the bot database.',
    {},
    async () => {
      const parts: string[] = [];

      try {
        const schedule = (await artisan(['schedule:list'])).split('\n').filter((line) => line.includes('bottele'));
        parts.push(`Scheduler (bottele):\n${schedule.length ? schedule.join('\n') : 'NONE scheduled'}`);
      } catch (error) {
        parts.push(`Scheduler: could not read (${(error as Error).message})`);
      }

      try {
        // The token is read here and used only for this request; it never goes into the output.
        const env = await fs.readFile(path.join(paygridDir, '.env'), 'utf8');
        const token = env.match(/^BOTTELE_TELEGRAM_BOT_TOKEN=(.*)$/m)?.[1]?.trim().replace(/^"|"$/g, '');
        if (!token) {
          parts.push('Telegram webhook: BOTTELE_TELEGRAM_BOT_TOKEN is empty');
        } else {
          const response = await fetch(`https://api.telegram.org/bot${token}/getWebhookInfo`, { signal: AbortSignal.timeout(10_000) });
          const info = ((await response.json()) as { result?: Record<string, unknown> }).result ?? {};
          parts.push('Telegram webhook: ' + JSON.stringify({
            url: info.url,
            pending_update_count: info.pending_update_count,
            last_error_date: info.last_error_date,
            last_error_message: info.last_error_message,
          }));
        }
      } catch (error) {
        parts.push(`Telegram webhook: could not check (${(error as Error).message})`);
      }

      try {
        const php = [
          '$t = App\\Models\\BotTicket::query();',
          '$today = App\\Services\\BotTele\\BotTime::now()->format("Y-m-d");',
          'echo json_encode([',
          '"tickets_today_by_status" => (clone $t)->where("created_at", ">=", $today)->selectRaw("status, count(*) n")->groupBy("status")->pluck("n", "status"),',
          '"open_not_taken" => (clone $t)->where("status", "OPEN")->whereNull("assigned_user_id")->count(),',
          '"waiting_approval" => (clone $t)->where("status", "WAITING_APPROVAL")->count(),',
          '"technicians_hadir" => App\\Models\\BotAttendance::where("status", "HADIR")->count(),',
          ']);',
        ].join(' ');
        parts.push(`Bot database: ${(await artisan(['tinker', `--execute=${php}`])).trim()}`);
      } catch (error) {
        parts.push(`Bot database: could not read (${(error as Error).message})`);
      }

      return text(parts.join('\n\n'));
    },
    { annotations: { readOnlyHint: true, openWorldHint: true } },
  );

  // Only offered while investigating: the agent cannot change anything itself, it files a proposal
  // that goes to the owner for approval.
  const proposeAction = tool(
    'propose_action',
    'File a proposal for an action that changes something on the server (fix code, run a command, restart, re-sync). It is sent to the owner for approval; nothing runs until they approve. Call it at most once, after investigating.',
    {
      summary: z.string().describe('One line: the problem found, in plain Indonesian'),
      plan: z.string().describe('Numbered, concrete steps: files to change, commands to run, how to check it worked'),
      risk: z.string().describe('What could go wrong and how to undo it'),
    },
    async (proposal) => {
      onProposal?.(proposal);
      return text('Proposal filed. Tell the requester it is waiting for the owner\'s approval. Do not attempt the change yourself.');
    },
  );

  return createSdkMcpServer({
    name: 'paygrid',
    version: '0.2.0',
    tools: onProposal ? [readCode, searchCode, getLogs, getBotStatus, proposeAction] : [readCode, searchCode, getLogs, getBotStatus],
  });
}

async function walk(dir: string, visit: (file: string) => Promise<void>): Promise<void> {
  if ((await fs.stat(dir)).isFile()) {
    await visit(dir);
    return;
  }
  for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      await walk(full, visit);
    } else if (entry.isFile()) {
      await visit(full);
    }
  }
}
