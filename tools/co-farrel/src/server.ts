import { createHmac, timingSafeEqual } from 'node:crypto';
import { promises as fs } from 'node:fs';
import http from 'node:http';
import { askAgent } from './agent.ts';
import { loadConfig, type Config } from './config.ts';
import { mentionsBot, senderNumber, stripMentions, type WahaMessage } from './whatsapp.ts';

const config = loadConfig();

function validSignature(raw: Buffer, signature: string | undefined, key: string): boolean {
  if (!signature) return false;
  const expected = Buffer.from(createHmac('sha512', key).update(raw).digest('hex'));
  const given = Buffer.from(signature.toLowerCase());
  return expected.length === given.length && timingSafeEqual(expected, given);
}

async function sendText(cfg: Config, chatId: string, text: string): Promise<void> {
  const response = await fetch(`${cfg.waha.baseUrl}/api/sendText`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Api-Key': cfg.waha.apiKey },
    body: JSON.stringify({ session: cfg.waha.session, chatId, text }),
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok) console.error('WAHA sendText failed', response.status);
}

// One conversation per group per day, so the agent keeps context within a day without growing forever.
async function loadState(): Promise<{ date?: string; sessionId?: string }> {
  try {
    return JSON.parse(await fs.readFile(config.stateFile, 'utf8'));
  } catch {
    return {};
  }
}

const seen = new Set<string>();
let queue = Promise.resolve();

async function handle(message: WahaMessage): Promise<void> {
  const member = config.members.get(senderNumber(message));
  const body = message.body ?? '';
  if (!member) {
    console.warn('ignored message from a number not in MEMBERS');
    return;
  }

  const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Jakarta' });
  const state = await loadState();
  const reply = await askAgent(config, member, stripMentions(body, config.botIds),
    state.date === today ? state.sessionId : undefined);
  await fs.writeFile(config.stateFile, JSON.stringify({ date: today, sessionId: reply.sessionId }));
  console.log(`answered ${member.name}, cost $${reply.costUsd?.toFixed(4) ?? '?'}`);

  await sendText(config, config.groupId, `${member.name}, ${reply.text}`);
}

http.createServer((req, res) => {
  if (req.method !== 'POST' || req.url !== '/waha') {
    res.writeHead(404).end();
    return;
  }
  const chunks: Buffer[] = [];
  req.on('data', (chunk) => chunks.push(chunk));
  req.on('end', () => {
    const raw = Buffer.concat(chunks);
    if (!validSignature(raw, req.headers['x-webhook-hmac'] as string | undefined, config.waha.hmacKey)) {
      res.writeHead(401).end();
      return;
    }
    res.writeHead(200).end('ok');

    let event: { event?: string; payload?: WahaMessage };
    try {
      event = JSON.parse(raw.toString('utf8'));
    } catch {
      return;
    }
    const message = event.payload ?? {};
    const isNew = message.id ? !seen.has(message.id) : true;
    if (event.event !== 'message' || message.fromMe || message.from !== config.groupId || !isNew
      || !mentionsBot(message.body ?? '', config.botIds)) {
      return;
    }
    if (message.id) {
      if (seen.size > 1000) seen.clear();
      seen.add(message.id);
    }

    // One question at a time, in arrival order.
    queue = queue.then(() => handle(message)).catch((error) => console.error('handle failed', error));
  });
}).listen(config.port, config.host, () => console.log(`Co Farrel listening on ${config.host}:${config.port}/waha`));
