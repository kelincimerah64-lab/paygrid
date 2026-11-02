import assert from 'node:assert/strict';
import { mkdtemp } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { APPROVAL_WINDOW_MS, parseDecision, RequestStore } from '../src/requests.ts';
import { chatNumber } from '../src/whatsapp.ts';

const sample = {
  requester: { number: '62822', name: 'Koh' },
  question: 'report gagal terkirim',
  summary: 'Queue worker berhenti',
  plan: '1. php artisan queue:restart',
  risk: 'Job yang sedang jalan diulang',
};

test('owner decisions are parsed from a private reply', () => {
  assert.deepEqual(parseDecision('approve ab7k'), { kind: 'approve', code: 'AB7K', note: '' });
  assert.deepEqual(parseDecision('ok AB7K tapi jangan restart php-fpm'), { kind: 'approve', code: 'AB7K', note: 'tapi jangan restart php-fpm' });
  assert.deepEqual(parseDecision('tolak AB7K belum perlu'), { kind: 'reject', code: 'AB7K', note: 'belum perlu' });
  assert.equal(parseDecision('approve'), null);
  assert.equal(parseDecision('halo'), null);
  assert.equal(parseDecision('approve AB7KX'), null);
});

test('a request can be approved once, only while it is fresh', async () => {
  const store = new RequestStore(path.join(await mkdtemp(path.join(os.tmpdir(), 'cofarrel-')), 'requests.json'));
  const created = new Date('2026-11-02T09:00:00Z');
  const request = await store.create(sample, created);

  assert.match(request.code, /^[A-HJ-NP-Z2-9]{4}$/);
  assert.equal(request.status, 'pending');
  assert.equal((await store.findPending(request.code.toLowerCase(), created))?.code, request.code);

  await store.update(request.code, { status: 'approved' });
  assert.equal(await store.findPending(request.code, created), undefined);

  const stale = await store.create(sample, created);
  assert.equal(await store.findPending(stale.code, new Date(created.getTime() + APPROVAL_WINDOW_MS + 1)), undefined);
  assert.equal((await store.all()).find((r) => r.code === stale.code)?.status, 'expired');
});

test('the owner is recognised from the private chat id', () => {
  assert.equal(chatNumber({ from: '6281100000000@c.us' }), '6281100000000');
});
