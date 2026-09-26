import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import crypto from 'node:crypto';
import { createRunner } from '../src/server.js';
import { envelope, sign, verify } from '../src/signature.js';
import { SECRETS, baseConfig, html, startSite } from './helpers.js';

function listen(runner) {
  return new Promise((resolve) => {
    const server = http.createServer(runner.handle);
    server.listen(0, '127.0.0.1', () => resolve({ server, url: `http://127.0.0.1:${server.address().port}` }));
  });
}

async function post(url, obj, secret, extraHeaders = {}) {
  const body = typeof obj === 'string' ? obj : JSON.stringify(obj);
  const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-MSUG-Signature': sign(body, secret), 'X-MSUG-Key-Id': 'k1', ...extraHeaders }, body });
  return { status: res.status, body: await res.text(), headers: res.headers };
}

const job = (over = {}) => envelope({ run_id: crypto.randomUUID(), test_id: crypto.randomUUID(), site_id: 43, profile_id: 'http-only', trigger: 'update_finished_or_timeout', ...over });

async function waitFor(fn, ms = 5000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await fn()) return true;
    await new Promise((r) => setTimeout(r, 25));
  }
  return false;
}

test('signed job is accepted (202), tested, and reported with a verifiable callback', async () => {
  const site = await startSite({ '/': html('Beispiel GmbH') });
  const cfg = baseConfig(site.port);
  const callbacks = [];
  const runner = createRunner(cfg, {
    sleep: async () => {},
    fetch: async (url, init) => {
      callbacks.push(init);
      return { status: 200, json: async () => ({ status: 'recorded' }) };
    },
    log: () => {},
  });
  const { server, url } = await listen(runner);
  const msg = job();
  const r = await post(url + '/v1/runs', msg, SECRETS.trigger);
  assert.equal(r.status, 202);
  assert.ok(await waitFor(() => callbacks.length === 1));
  const cb = callbacks[0];
  assert.ok(verify(cb.body, cb.headers['x-msug-signature'], [SECRETS.callback]), 'callback signed with callback secret');
  const payload = JSON.parse(cb.body);
  assert.equal(payload.run_id, msg.run_id);
  assert.equal(payload.result.http, 'HTTP_PASS');
  assert.equal(payload.result.smoke, 'SMOKE_SKIPPED');
  assert.equal(payload.result.smoke_mandatory, false);

  // Status endpoint answers signed with the status secret.
  const s = await post(url + '/v1/status', envelope({ run_id: msg.run_id, test_id: msg.test_id }), SECRETS.status);
  assert.equal(s.status, 200);
  assert.ok(verify(s.body, s.headers.get('x-msug-signature'), [SECRETS.status]));
  assert.equal(JSON.parse(s.body).state, 'done');
  server.close();
  await site.close();
});

test('wrong secret, replay, expired envelope, unknown site and profile are rejected', async () => {
  const cfg = baseConfig(1);
  const runner = createRunner(cfg, { sleep: async () => {}, fetch: async () => ({ status: 200 }), log: () => {}, httpCheck: async () => ({ http: 'HTTP_PASS', http_status: 200, checks: [] }) });
  const { server, url } = await listen(runner);

  assert.equal((await post(url + '/v1/runs', job(), SECRETS.status)).status, 401, 'status key cannot trigger');
  assert.equal((await post(url + '/v1/runs', job(), 'x'.repeat(40))).status, 401);

  const msg = job();
  const body = JSON.stringify(msg);
  assert.equal((await post(url + '/v1/runs', body, SECRETS.trigger)).status, 202);
  assert.equal((await post(url + '/v1/runs', body, SECRETS.trigger)).status, 409, 'same bytes twice = replay');

  const again = job({ run_id: msg.run_id, test_id: msg.test_id });
  const dup = await post(url + '/v1/runs', again, SECRETS.trigger);
  assert.equal(dup.status, 200, 'same run/test with new event id is idempotent');
  assert.equal(JSON.parse(dup.body).status, 'duplicate');

  const expired = { ...job(), issued_at: '2020-01-01T00:00:00Z', expires_at: '2020-01-01T00:05:00Z' };
  assert.equal((await post(url + '/v1/runs', expired, SECRETS.trigger)).status, 401);

  assert.equal((await post(url + '/v1/runs', job({ site_id: 99 }), SECRETS.trigger)).status, 422);
  assert.equal((await post(url + '/v1/runs', job({ profile_id: 'woocommerce' }), SECRETS.trigger)).status, 422);
  assert.equal((await post(url + '/v1/runs', job({ base_url: 'https://evil.example' }), SECRETS.trigger)).status, 202, 'extra fields are ignored, never used as target');
  server.close();
});

test('HTTP failure skips the browser and a runner fault is UNKNOWN, never PASS', async () => {
  const cfg = baseConfig(1);
  let smokeCalled = false;
  const runner = createRunner(cfg, {
    sleep: async () => {},
    fetch: async () => ({ status: 200 }),
    log: () => {},
    httpCheck: async () => ({ http: 'HTTP_FAIL', http_status: 500, checks: [{ name: 'http:home', kind: 'http', status: 'fail', message: 'status_5xx' }] }),
    runSmoke: async () => { smokeCalled = true; },
  });
  const r1 = await runner.execute({ run_id: 'r', test_id: 't', site_id: 43, profile_id: 'corporate-basic', trigger: 'update_finished_or_timeout' });
  assert.equal(r1.http, 'HTTP_FAIL');
  assert.equal(r1.smoke, 'SMOKE_SKIPPED');
  assert.equal(r1.smoke_mandatory, true);
  assert.equal(smokeCalled, false);

  const broken = createRunner(cfg, { sleep: async () => {}, fetch: async () => ({ status: 200 }), log: () => {}, httpCheck: async () => { throw new Error('boom'); } });
  const r2 = await broken.execute({ run_id: 'r', test_id: 't', site_id: 43, profile_id: 'corporate-basic', trigger: 'preflight' });
  assert.equal(r2.http, 'HTTP_UNKNOWN');
  assert.equal(r2.smoke, 'SMOKE_UNKNOWN');
  assert.equal(r2.runner_error, 'boom');
});

test('restart resumes unfinished jobs and redelivers results', async () => {
  const site = await startSite({ '/': html('Beispiel GmbH') });
  const cfg = baseConfig(site.port);
  const first = createRunner(cfg, { sleep: async () => {}, fetch: async () => { throw new Error('parent down'); }, log: () => {} });
  const m = job();
  first.store.putJob({ run_id: m.run_id, test_id: m.test_id, site_id: 43, profile_id: 'http-only', trigger: 'preflight', state: 'running', created_ms: Date.now(), delivered: false });

  const delivered = [];
  const second = createRunner(cfg, { sleep: async () => {}, fetch: async (u, init) => { delivered.push(JSON.parse(init.body)); return { status: 200, json: async () => ({ status: 'recorded' }) }; }, log: () => {} });
  assert.ok(await waitFor(() => delivered.length === 1));
  assert.equal(delivered[0].run_id, m.run_id);
  assert.ok(second.store.getJob(m.run_id, m.test_id).delivered);
  await site.close();
});

test('a 200 that is not a Guard answer is not counted as delivered', async () => {
  const cfg = baseConfig(1);
  let calls = 0;
  const runner = createRunner(cfg, {
    sleep: async () => {},
    log: () => {},
    fetch: async () => { calls++; return { status: 200, json: async () => { throw new Error('html'); } }; },
  });
  const ok = await runner.deliver({ run_id: crypto.randomUUID(), test_id: crypto.randomUUID(), result: {} });
  assert.equal(ok, false);
  assert.equal(calls, 10, 'bounded retries');
});
