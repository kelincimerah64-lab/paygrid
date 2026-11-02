export interface WahaMessage {
  id?: string;
  from?: string;
  participant?: string;
  author?: string;
  fromMe?: boolean;
  body?: string;
}

/** Group sender as a bare phone number. WAHA puts it in participant (or author, depending on engine). */
export function senderNumber(message: WahaMessage): string {
  return String(message.participant ?? message.author ?? '').split('@')[0].replace(/\D/g, '');
}

/**
 * True when the message tags the bot. A WhatsApp mention shows in the body as "@<id>", where the id
 * is the phone number or, on newer WhatsApp, a separate "lid" number; writing the name also counts.
 */
export function mentionsBot(body: string, botIds: string[]): boolean {
  return botIds.some((id) => id !== '' && body.includes(`@${id}`)) || /\bco\s*farrel\b/i.test(body);
}

/** The question without the "@<id>" mention tokens. */
export function stripMentions(body: string, botIds: string[]): string {
  return botIds.reduce((out, id) => (id === '' ? out : out.replaceAll(`@${id}`, '')), body).trim();
}
