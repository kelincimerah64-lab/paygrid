import { createHmac, timingSafeEqual } from 'node:crypto';
import { promises as fs } from 'node:fs';
import http from 'node:http';
import { execute, investigate } from './agent.ts';
import { loadConfig } from './config.ts';
import { parseDecision, RequestStore, type ActionRequest } from './requests.ts';
import { chatNumber, mentionsBot, senderNumber, stripMentions, type WahaMessage } from './whatsapp.ts';

const config = loadConfig();
const requests = new RequestStore(config.requestsFile);
const owners = [...config.members].filter(([, m]) => m.role === 'pemilik').map(([number]) => number);

function validSignature(raw: Buffer, signature: string | undefined, key: string): boolean {
  if (!signature) return false;
  const expected = Buffer.from(createHmac('sha512', key).update(raw).digest('hex'));
  const given = Buffer.from(signature.toLowerCase());
  return expected.length === given.length && timingSafeEqual(expected, given);
}

async function sendText(chatId: string, text: string): Promise<void> {
  try {
    const response = await fetch(`${config.waha.baseUrl}/api/sendText`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Api-Key': config.waha.apiKey },
      body: JSON.stringify({ session: config.waha.session, chatId, text }),
      signal: AbortSignal.timeout(15_000),
    });
    if (!response.ok) console.error('WAHA sendText failed', response.status);
  } catch (error) {
    console.error('WAHA sendText failed', (error as Error).message);
  }
}

const toGroup = (text: string) => sendText(config.groupId, text);
const toOwners = (text: string) => Promise.all(owners.map((number) => sendText(`${number}@c.us`, text)));

// One conversation per group per day, so the agent keeps context within a day without growing forever.
async function loadState(): Promise<{ date?: string; sessionId?: string }> {
  try {
    return JSON.parse(await fs.readFile(config.stateFile, 'utf8'));
  } catch {
    return {};
  }
}

/** A tagged message in the group: investigate read-only, answer, and file a proposal if action is needed. */
async function handleGroupMessage(message: WahaMessage): Promise<void> {
  const number = senderNumber(message);
  const member = config.members.get(number);
  if (!member) {
    console.warn('ignored message from a number not in MEMBERS');
    return;
  }

  const question = stripMentions(message.body ?? '', config.botIds);
  const today = new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Jakarta' });
  const state = await loadState();
  const reply = await investigate(config, member, question, state.date === today ? state.sessionId : undefined);
  await fs.writeFile(config.stateFile, JSON.stringify({ date: today, sessionId: reply.sessionId }));
  console.log(`answered ${member.name}, cost $${reply.costUsd?.toFixed(4) ?? '?'}`);

  if (!reply.proposal) {
    await toGroup(`${member.name}, ${reply.text}`);
    return;
  }

  const request = await requests.create({
    requester: { number, name: member.name },
    question,
    ...reply.proposal,
    sessionId: reply.sessionId,
  });
  await toGroup(`${member.name}, ${reply.text}\n\n⏳ Menunggu persetujuan Farrel (kode ${request.code}).`);
  await toOwners([
    `🔔 Permintaan ${request.code} dari ${member.name}`,
    `Pesan: ${question}`,
    '',
    `🔎 ${request.summary}`,
    '',
    `🛠 Rencana:\n${request.plan}`,
    '',
    `⚠️ Risiko: ${request.risk}`,
    '',
    `Balas "approve ${request.code}" untuk menjalankan, atau "tolak ${request.code} <alasan>". Berlaku 24 jam.`,
  ].join('\n'));
}

/** The owner's private reply: approve runs the plan, reject tells the requester. */
async function handleOwnerMessage(message: WahaMessage): Promise<void> {
  const decision = parseDecision(message.body ?? '');
  const ownerChat = `${chatNumber(message)}@c.us`;
  if (!decision) {
    await sendText(ownerChat, 'Format: "approve KODE" atau "tolak KODE alasan".');
    return;
  }
  const request = await requests.findPending(decision.code);
  if (!request) {
    await sendText(ownerChat, `Kode ${decision.code} tidak ditemukan, sudah diputuskan, atau sudah lewat 24 jam.`);
    return;
  }

  const decidedAt = new Date().toISOString();
  if (decision.kind === 'reject') {
    await requests.update(request.code, { status: 'rejected', decidedAt, note: decision.note });
    await toGroup(`${request.requester.name}, permintaan ${request.code} tidak disetujui Farrel.${decision.note ? ` Alasan: ${decision.note}` : ''}`);
    await sendText(ownerChat, `Permintaan ${request.code} ditolak dan pelapor sudah dikabari.`);
    return;
  }

  await requests.update(request.code, { status: 'approved', decidedAt, note: decision.note });
  await toGroup(`${request.requester.name}, permintaan ${request.code} sudah disetujui Farrel dan sedang dikerjakan.`);
  await sendText(ownerChat, `Permintaan ${request.code} sedang dijalankan. Saya kabari kalau selesai.`);
  await runApproved({ ...request, note: decision.note });
}

async function runApproved(request: ActionRequest): Promise<void> {
  const result = await execute(config, request);
  await requests.update(request.code, { status: result.ok ? 'done' : 'failed' });
  console.log(`executed ${request.code} ok=${result.ok}, cost $${result.costUsd?.toFixed(4) ?? '?'}`);
  const header = result.ok ? `✅ ${request.code} selesai` : `❌ ${request.code} tidak selesai`;
  await toGroup(`${request.requester.name}, ${header}.\n${result.text}`);
  await toOwners(`${header}.\n${result.text}`);
}

const seen = new Set<string>();
let queue = Promise.resolve();
const enqueue = (job: () => Promise<void>) => {
  // One job at a time, in arrival order, so two runs never touch the server at once.
  queue = queue.then(job).catch((error) => console.error('job failed', error));
};

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
    if (event.event !== 'message' || message.fromMe || (message.id && seen.has(message.id))) {
      return;
    }

    const inGroup = message.from === config.groupId;
    const fromOwnerPrivately = !String(message.from ?? '').endsWith('@g.us') && owners.includes(chatNumber(message));
    if (!(inGroup && mentionsBot(message.body ?? '', config.botIds)) && !fromOwnerPrivately) {
      return;
    }
    if (message.id) {
      if (seen.size > 1000) seen.clear();
      seen.add(message.id);
    }
    enqueue(() => (inGroup ? handleGroupMessage(message) : handleOwnerMessage(message)));
  });
}).listen(config.port, config.host, () => console.log(`Co Farrel listening on ${config.host}:${config.port}/waha`));
