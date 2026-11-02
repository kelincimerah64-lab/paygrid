// Every setting comes from the environment (see .env.example). Nothing secret lives in code.

export type Role = 'pemilik' | 'atasan' | 'anggota';

export interface Member {
  name: string;
  role: Role;
}

function required(name: string): string {
  const value = process.env[name]?.trim();
  if (!value) {
    throw new Error(`Missing environment variable ${name}`);
  }
  return value;
}

/** "628111:Farrel:pemilik,628222:Koh:atasan" -> phone number => member. Unknown numbers are not answered. */
export function parseMembers(raw: string): Map<string, Member> {
  const members = new Map<string, Member>();
  for (const entry of raw.split(',')) {
    const [phone, name, role] = entry.split(':').map((part) => part.trim());
    if (phone && name && (role === 'pemilik' || role === 'atasan' || role === 'anggota')) {
      members.set(phone.replace(/\D/g, ''), { name, role });
    }
  }
  return members;
}

export function loadConfig() {
  return {
    port: Number(process.env.PORT ?? 8787),
    // 127.0.0.1 when WAHA runs on the same host; use the Docker bridge address if WAHA runs in a container.
    host: process.env.HOST?.trim() || '127.0.0.1',
    paygridDir: required('PAYGRID_DIR'),
    waha: {
      baseUrl: required('WAHA_BASE_URL').replace(/\/$/, ''),
      apiKey: required('WAHA_API_KEY'),
      session: process.env.WAHA_SESSION?.trim() || 'default',
      hmacKey: required('WAHA_WEBHOOK_HMAC_KEY'),
    },
    groupId: required('WA_GROUP_ID'),
    // The bot's phone number plus, optionally, its WhatsApp "lid" id: mentions can use either.
    botIds: [required('BOT_NUMBER'), process.env.BOT_LID ?? ''].map((id) => id.replace(/\D/g, '')),
    members: parseMembers(required('MEMBERS')),
    model: process.env.AGENT_MODEL?.trim() || 'claude-opus-5-5',
    maxBudgetUsd: Number(process.env.MAX_BUDGET_USD ?? 1),
    stateFile: process.env.STATE_FILE?.trim() || './state.json',
  };
}

export type Config = ReturnType<typeof loadConfig>;
