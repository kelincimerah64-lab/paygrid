import path from 'node:path';

/**
 * What the agent may read inside the PayGrid folder. Everything else, above all .env, storage
 * (keys, sessions, logs other than through get_logs) and vendor, is refused before any file is opened.
 */
const ALLOWED_TOP_DIRS = ['app', 'bootstrap', 'config', 'database', 'docs', 'resources', 'routes', 'tests'];
const DENIED_NAMES = /(^|\/)(\.env[^/]*|.*\.(key|pem|p12|sqlite|bak[^/]*)|auth\.json)$/i;

/** Resolves a requested path against the PayGrid folder, or returns null when it may not be read. */
export function resolveReadablePath(paygridDir: string, requested: string): string | null {
  const root = path.resolve(paygridDir);
  const absolute = path.resolve(root, requested);
  const relative = path.relative(root, absolute).split(path.sep).join('/');

  if (relative === '' || relative.startsWith('..') || path.isAbsolute(relative)) {
    return null;
  }
  if (!ALLOWED_TOP_DIRS.includes(relative.split('/')[0]) || DENIED_NAMES.test(relative)) {
    return null;
  }
  return absolute;
}

export const SEARCH_ROOTS = ALLOWED_TOP_DIRS;

const SECRET_PATTERNS: Array<[RegExp, string]> = [
  // Telegram bot token, also inside api.telegram.org/bot<token>/ URLs.
  [/(?<!\d)\d{8,10}:[A-Za-z0-9_-]{30,}/g, '[telegram-token]'],
  // Anthropic and other "sk-" style keys.
  [/\bsk-[A-Za-z0-9_-]{16,}\b/g, '[api-key]'],
  // Bearer / X-Api-Key style headers echoed into logs.
  [/((?:authorization|x-api-key|x-webhook-hmac|secret[_-]?token)["'\s:=]+(?:bearer\s+)?)[^\s"',}]+/gi, '$1[redacted]'],
  // KEY=value lines for anything that looks like a secret.
  [/\b([A-Z0-9_]*(?:TOKEN|SECRET|PASSWORD|API_KEY|HMAC_KEY|PRIVATE_KEY)[A-Z0-9_]*\s*[=:]\s*)\S+/g, '$1[redacted]'],
  // merchant_key values in JSON-ish log context.
  [/("merchant_key"\s*:\s*")[^"]*"/gi, '$1[redacted]"'],
];

/** Strips anything that looks like a credential before text leaves the server. */
export function redact(text: string): string {
  return SECRET_PATTERNS.reduce((out, [pattern, replacement]) => out.replace(pattern, replacement), text);
}
