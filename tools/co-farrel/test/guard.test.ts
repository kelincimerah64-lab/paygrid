import assert from 'node:assert/strict';
import path from 'node:path';
import { test } from 'node:test';
import { parseMembers } from '../src/config.ts';
import { redact, resolveReadablePath } from '../src/guard.ts';
import { mentionsBot, senderNumber, stripMentions } from '../src/whatsapp.ts';

const root = path.resolve('/var/www/paygrid');

test('code folders are readable', () => {
  assert.equal(resolveReadablePath(root, 'app/Services/BotTele/SupportFlow.php'), path.join(root, 'app/Services/BotTele/SupportFlow.php'));
  assert.ok(resolveReadablePath(root, 'config/bottele.php'));
  assert.ok(resolveReadablePath(root, 'docs/bot-tele.md'));
});

test('secrets, storage, vendor and paths outside the app are refused', () => {
  for (const requested of [
    '.env', '.env.backup', 'app/../.env', 'storage/logs/laravel.log', 'storage/oauth-private.key',
    'vendor/autoload.php', '../../etc/passwd', '/etc/passwd', 'config/../.env.bak-20261028',
    'database/database.sqlite', 'app/Services/BotTele/SupportFlow.php.bak-20261102', 'auth.json', '',
  ]) {
    assert.equal(resolveReadablePath(root, requested), null, requested);
  }
});

test('credentials are redacted', () => {
  const token = '8790072895:AAHF_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
  const out = redact([
    `POST https://api.telegram.org/bot${token}/sendMessage failed`,
    'BOTTELE_TELEGRAM_WEBHOOK_SECRET=abc123secretvalue',
    'X-Api-Key: waha-key-123456',
    '{"merchant_key":"hg-secret-key","name":"Toko"}',
    'key sk-ant-api03-abcdefghijklmnopqrstuv',
  ].join('\n'));

  for (const secret of [token, 'abc123secretvalue', 'waha-key-123456', 'hg-secret-key', 'sk-ant-api03-abcdefghijklmnopqrstuv']) {
    assert.ok(!out.includes(secret), `leaked ${secret}`);
  }
  assert.ok(out.includes('"name":"Toko"'));
});

test('members are parsed by phone number and invalid roles are dropped', () => {
  const members = parseMembers('+62 811-111:Farrel:pemilik, 62822:Koh:atasan,62833:X:admin');
  assert.deepEqual(members.get('62811111'), { name: 'Farrel', role: 'pemilik' });
  assert.deepEqual(members.get('62822'), { name: 'Koh', role: 'atasan' });
  assert.equal(members.has('62833'), false);
});

test('mentions and sender are read from the WAHA message', () => {
  const ids = ['6281234', '99887766'];
  assert.equal(mentionsBot('@6281234 cek bot dong', ids), true);
  assert.equal(mentionsBot('@99887766 cek bot dong', ids), true);
  assert.equal(mentionsBot('Co Farrel, status bot?', ids), true);
  assert.equal(mentionsBot('halo semua', ids), false);
  assert.equal(stripMentions('@6281234 cek bot dong', ids), 'cek bot dong');
  assert.equal(senderNumber({ participant: '62811111@c.us' }), '62811111');
  assert.equal(senderNumber({ author: '62822@s.whatsapp.net' }), '62822');
});
