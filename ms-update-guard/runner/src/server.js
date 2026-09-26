// MS Update Guard runner: accepts signed jobs from the Parent, tests the site from outside,
// reports back with a signed callback. Run behind a TLS-terminating reverse proxy.
import http from 'node:http';
import { pathToFileURL } from 'node:url';
import { loadConfig } from './config.js';
import { HEADER, KEY_HEADER, MAX_BODY, checkEnvelope, envelope, sign, verify } from './signature.js';
import { Store } from './store.js';
import { httpCheck } from './http-check.js';
import { runSmoke } from './smoke.js';

const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
const TRIGGERS = new Set(['preflight', 'update_finished_or_timeout']);
const iso = (ms = Date.now()) => new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        reject(new Error('too_large'));
        req.destroy();
      } else chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function secretsFor(keys, kid) {
  if (kid && keys[kid]) return [keys[kid]];
  return Object.values(keys);
}

export function createRunner(cfg, deps = {}) {
  const store = deps.store || new Store(cfg.stateDir);
  const fetchImpl = deps.fetch || fetch;
  const sleep = deps.sleep || ((ms) => new Promise((r) => setTimeout(r, ms)));
  const smoke = deps.runSmoke || runSmoke;
  const check = deps.httpCheck || httpCheck;
  const queue = [];
  let active = 0;
  const log = deps.log || ((...a) => console.log(iso(), ...a));

  async function execute(job) {
    const site = cfg.sites[String(job.site_id)];
    const started = Date.now();
    const result = { run_id: job.run_id, test_id: job.test_id, trigger: job.trigger, started_at: iso(started) };
    try {
      if (job.trigger === 'update_finished_or_timeout' && cfg.http.initial_delay_seconds > 0) {
        await sleep(cfg.http.initial_delay_seconds * 1000);
      }
      const h = await check(site, cfg, job.test_id, { sleep });
      Object.assign(result, { http: h.http, http_status: h.http_status, checks: [...h.checks] });
      if (h.http === 'HTTP_PASS') {
        const s = await smoke(site, job.profile_id, cfg, job.test_id);
        result.smoke = s.smoke;
        result.smoke_mandatory = s.smoke_mandatory;
        result.checks.push(...s.checks);
      } else {
        // Spec 4 VERIFYING: after HTTP 500/timeout the browser run is skipped and marked as such.
        result.smoke = 'SMOKE_SKIPPED';
        result.smoke_mandatory = job.profile_id !== 'http-only';
        result.checks.push({ name: 'smoke', kind: 'smoke', status: 'skipped', message: 'SKIPPED_HTTP_FAILURE' });
      }
    } catch (e) {
      // A runner fault is never a pass.
      Object.assign(result, { http: result.http || 'HTTP_UNKNOWN', smoke: 'SMOKE_UNKNOWN', smoke_mandatory: true, checks: result.checks || [] });
      result.runner_error = String(e.message || e).split('\n')[0].slice(0, 180);
    }
    result.finished_at = iso();
    return result;
  }

  async function deliver(job) {
    for (let attempt = 1; attempt <= 10; attempt++) {
      // Fresh event id per attempt: the Parent de-duplicates by run_id/test_id, not by bytes.
      const body = JSON.stringify(envelope({ run_id: job.run_id, test_id: job.test_id, result: job.result }, 300));
      try {
        const res = await fetchImpl(cfg.parent.callback_url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', [HEADER]: sign(body, cfg.callbackSecret), [KEY_HEADER]: 'callback' },
          body,
          redirect: 'error',
        });
        // Only a Guard answer counts; a 200 HTML page (wrong URL, no permalinks) is not a delivery.
        let answer = null;
        try {
          answer = typeof res.json === 'function' ? await res.json() : res.body;
        } catch {
          answer = null;
        }
        const known = answer && ['recorded', 'duplicate', 'already_recorded', 'ignored'].includes(answer.status);
        if ((res.status === 200 || res.status === 409) && known) {
          job.delivered = true;
          job.delivered_status = res.status;
          store.putJob(job);
          log('callback delivered', job.run_id, res.status);
          return true;
        }
        log('callback refused', job.run_id, res.status, answer && answer.status ? answer.status : 'no guard answer');
      } catch (e) {
        log('callback failed', job.run_id, e.message);
      }
      await sleep(Math.min(120000, 5000 * 2 ** (attempt - 1)));
    }
    // The Parent polls /v1/status after its deadline, so the result is not lost.
    return false;
  }

  async function pump() {
    while (active < cfg.concurrency && queue.length) {
      const job = queue.shift();
      active++;
      (async () => {
        try {
          if (job.state !== 'done') {
            job.state = 'running';
            store.putJob(job);
            job.result = await execute(job);
            job.state = 'done';
            store.putJob(job);
          }
          if (!job.delivered) await deliver(job);
        } finally {
          active--;
          pump();
        }
      })();
    }
  }

  function enqueue(job) {
    queue.push(job);
    pump();
  }

  // Resume after restart: tests are read-only, so re-running an interrupted one is safe.
  for (const job of store.unfinished()) {
    if (job.state === 'running') job.state = 'queued';
    enqueue(job);
  }

  async function authenticate(req, keys) {
    const body = await readBody(req);
    if (!verify(body, req.headers[HEADER], secretsFor(keys, req.headers[KEY_HEADER]))) return { error: 401, why: 'signature' };
    let msg;
    try {
      msg = JSON.parse(body);
    } catch {
      return { error: 400, why: 'json' };
    }
    const env = checkEnvelope(msg);
    if (env !== true) return { error: 401, why: env };
    if (!store.claimEvent(msg.event_id)) return { error: 409, why: 'replay' };
    return { msg };
  }

  function reply(res, status, obj, signWith) {
    const body = JSON.stringify(obj);
    const headers = { 'Content-Type': 'application/json' };
    if (signWith) headers[HEADER] = sign(body, signWith);
    res.writeHead(status, headers);
    res.end(body);
  }

  async function handle(req, res) {
    try {
      if (req.method === 'GET' && req.url === '/healthz') return reply(res, 200, { ok: true, queued: queue.length, active });
      if (req.method !== 'POST') return reply(res, 405, { error: 'method' });

      if (req.url === '/v1/runs') {
        const a = await authenticate(req, cfg.triggerKeys);
        if (a.error) return reply(res, a.error, { error: a.why });
        const m = a.msg;
        const site = cfg.sites[String(m.site_id)];
        if (!UUID.test(m.run_id || '') || !UUID.test(m.test_id || '') || !TRIGGERS.has(m.trigger)) return reply(res, 400, { error: 'fields' });
        // Only configured sites and profiles; no URL is ever taken from the request.
        if (!site) return reply(res, 422, { error: 'unknown_site' });
        if (!site.profiles.includes(m.profile_id)) return reply(res, 422, { error: 'profile_not_allowed' });
        const existing = store.getJob(m.run_id, m.test_id);
        if (existing) return reply(res, 200, { status: 'duplicate', state: existing.state });
        const job = store.putJob({ run_id: m.run_id, test_id: m.test_id, site_id: Number(m.site_id), profile_id: m.profile_id, trigger: m.trigger, state: 'queued', created_ms: Date.now(), delivered: false });
        log('job accepted', job.run_id, job.trigger, `site ${job.site_id}`);
        enqueue(job);
        return reply(res, 202, { status: 'accepted' });
      }

      if (req.url === '/v1/status') {
        const a = await authenticate(req, cfg.statusKeys);
        if (a.error) return reply(res, a.error, { error: a.why });
        const job = store.getJob(a.msg.run_id, a.msg.test_id);
        const secret = secretsFor(cfg.statusKeys, req.headers[KEY_HEADER])[0];
        const out = { run_id: a.msg.run_id, test_id: a.msg.test_id, state: job ? job.state : 'unknown' };
        if (job && job.state === 'done') out.result = job.result;
        return reply(res, 200, out, secret);
      }
      return reply(res, 404, { error: 'not_found' });
    } catch (e) {
      return reply(res, e.message === 'too_large' ? 413 : 500, { error: e.message === 'too_large' ? 'too_large' : 'internal' });
    }
  }

  return { handle, store, queue, execute, deliver, enqueue, idle: () => active === 0 && queue.length === 0 };
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  const cfg = loadConfig();
  const runner = createRunner(cfg);
  http.createServer(runner.handle).listen(cfg.listen.port, cfg.listen.host, () => {
    console.log(iso(), `runner listening on ${cfg.listen.host}:${cfg.listen.port}, ${Object.keys(cfg.sites).length} sites`);
  });
}
