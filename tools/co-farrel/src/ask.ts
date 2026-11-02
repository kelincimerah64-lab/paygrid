// Try the agent from a terminal, without WhatsApp: npm run ask -- "pertanyaan"
// Only the investigate step runs (read-only); a proposal is printed, never executed.
import { investigate } from './agent.ts';
import type { Config } from './config.ts';

const question = process.argv.slice(2).join(' ').trim();
if (!question) {
  console.error('Usage: npm run ask -- "pertanyaan"');
  process.exit(1);
}

const config = {
  paygridDir: process.env.PAYGRID_DIR ?? '../..',
  model: process.env.AGENT_MODEL?.trim() || 'claude-opus-5-5',
  maxBudgetUsd: Number(process.env.MAX_BUDGET_USD ?? 1),
} as Config;

const reply = await investigate(config, { name: process.env.ASK_AS ?? 'Farrel', role: 'pemilik' }, question);

console.log('\n--- Balasan di grup ---\n' + reply.text);
if (reply.proposal) {
  console.log('\n--- Usulan ke pemilik (TIDAK dijalankan) ---');
  console.log(`Ringkasan: ${reply.proposal.summary}\nRencana:\n${reply.proposal.plan}\nRisiko: ${reply.proposal.risk}`);
}
console.log(`\nok=${reply.ok} biaya=$${reply.costUsd?.toFixed(4) ?? '?'}`);
