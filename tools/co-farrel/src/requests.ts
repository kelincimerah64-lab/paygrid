import { randomInt } from 'node:crypto';
import { promises as fs } from 'node:fs';

export type RequestStatus = 'pending' | 'approved' | 'rejected' | 'done' | 'failed' | 'expired';

/** One action the agent proposed after investigating, waiting for the owner's decision. */
export interface ActionRequest {
  code: string;
  status: RequestStatus;
  requester: { number: string; name: string };
  question: string;
  summary: string;
  plan: string;
  risk: string;
  sessionId?: string;
  createdAt: string;
  decidedAt?: string;
  note?: string;
}

export interface Proposal {
  summary: string;
  plan: string;
  risk: string;
}

/** A pending request older than this can no longer be approved: the situation may have changed. */
export const APPROVAL_WINDOW_MS = 24 * 60 * 60 * 1000;

// No 0/O/1/I so codes are easy to read and type on a phone.
const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export class RequestStore {
  constructor(private readonly file: string) {}

  async all(): Promise<ActionRequest[]> {
    try {
      return JSON.parse(await fs.readFile(this.file, 'utf8'));
    } catch {
      return [];
    }
  }

  async create(input: Omit<ActionRequest, 'code' | 'status' | 'createdAt'>, now = new Date()): Promise<ActionRequest> {
    const requests = await this.all();
    const taken = new Set(requests.map((r) => r.code));
    let code = '';
    do {
      code = Array.from({ length: 4 }, () => CODE_ALPHABET[randomInt(CODE_ALPHABET.length)]).join('');
    } while (taken.has(code));

    const request: ActionRequest = { ...input, code, status: 'pending', createdAt: now.toISOString() };
    // Keep the file small: the last 200 requests are plenty for an audit trail.
    await this.save([...requests, request].slice(-200));
    return request;
  }

  /** The pending request with this code, after marking stale ones expired. */
  async findPending(code: string, now = new Date()): Promise<ActionRequest | undefined> {
    const requests = await this.all();
    let changed = false;
    for (const request of requests) {
      if (request.status === 'pending' && now.getTime() - Date.parse(request.createdAt) > APPROVAL_WINDOW_MS) {
        request.status = 'expired';
        changed = true;
      }
    }
    if (changed) await this.save(requests);
    return requests.find((r) => r.code === code.toUpperCase() && r.status === 'pending');
  }

  async update(code: string, changes: Partial<ActionRequest>): Promise<void> {
    const requests = await this.all();
    const request = requests.find((r) => r.code === code);
    if (request) {
      Object.assign(request, changes);
      await this.save(requests);
    }
  }

  private async save(requests: ActionRequest[]): Promise<void> {
    await fs.writeFile(this.file, JSON.stringify(requests, null, 2));
  }
}

export type Decision = { kind: 'approve' | 'reject'; code: string; note: string };

/** "approve AB7K", "ok ab7k", "tolak AB7K alasannya ..." from the owner's private chat. */
export function parseDecision(text: string): Decision | null {
  const match = text.trim().match(/^(approve|setuju|ok|acc|tolak|reject)\s+([A-Za-z0-9]{4})\b\s*(.*)$/is);
  if (!match) return null;
  const kind = /^(tolak|reject)$/i.test(match[1]) ? 'reject' : 'approve';
  return { kind, code: match[2].toUpperCase(), note: match[3].trim() };
}
