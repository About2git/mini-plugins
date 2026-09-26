// External HTTP check (spec 6): valid TLS, expected host after redirects, status, content marker.
import http from 'node:http';
import https from 'node:https';
import { siteUrl } from './config.js';

const FATAL_PATTERNS = [
  'There has been a critical error on this website',
  'Es gab einen kritischen Fehler auf deiner Website',
  'Error establishing a database connection',
  'Fehler beim Aufbau einer Datenbankverbindung',
  '<b>Fatal error</b>',
  'Briefly unavailable for scheduled maintenance',
  'Wegen Wartungsarbeiten ist diese Website kurzzeitig nicht verfügbar',
];

function request(url, timeoutMs) {
  return new Promise((resolve) => {
    const lib = url.protocol === 'https:' ? https : http;
    const req = lib.request(
      url,
      {
        method: 'GET',
        timeout: timeoutMs,
        rejectUnauthorized: true,
        headers: {
          'User-Agent': 'MS-Update-Guard-Runner/0.1 (+uptime check)',
          Accept: 'text/html,application/xhtml+xml',
          'Cache-Control': 'no-cache',
        },
      },
      (res) => {
        const chunks = [];
        let size = 0;
        res.on('data', (c) => {
          size += c.length;
          if (size <= 2 * 1024 * 1024) chunks.push(c);
        });
        res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString('utf8') }));
        res.on('error', (e) => resolve({ error: 'connection', message: e.code || e.message }));
      },
    );
    req.on('timeout', () => {
      req.destroy();
      resolve({ error: 'timeout', message: `no response within ${timeoutMs} ms` });
    });
    req.on('error', (e) => {
      const tls = /CERT|SSL|TLS|self.signed|UNABLE_TO_VERIFY|ERR_TLS/i.test(`${e.code} ${e.message}`);
      resolve({ error: tls ? 'tls' : 'connection', message: String(e.code || e.message).slice(0, 120) });
    });
    req.end();
  });
}

// One page: follow at most 5 redirects, all on allowed hosts.
export async function checkPage(site, page, opts) {
  let url = siteUrl(site, page.path || '/', opts.allowHttp);
  if (page.cache_bust ?? site.http?.cache_bust) url.searchParams.set('msug', opts.testId.slice(0, 8));
  for (let hop = 0; hop < 6; hop++) {
    const res = await request(url, opts.timeoutMs);
    if (res.error) return { ok: false, code: res.error, message: res.message, url: url.pathname };
    if (res.status >= 300 && res.status < 400 && res.headers.location) {
      const next = new URL(res.headers.location, url);
      if (!site.allowed_hosts.includes(next.hostname.toLowerCase())) {
        return { ok: false, code: 'redirect_host', message: `redirect to ${next.hostname}`, status: res.status, url: url.pathname };
      }
      if (next.protocol !== 'https:' && !opts.allowHttp) return { ok: false, code: 'redirect_insecure', status: res.status, url: url.pathname };
      url = next;
      continue;
    }
    const expected = page.expect_status || [200];
    if (!expected.includes(res.status)) {
      return { ok: false, code: res.status >= 500 ? 'status_5xx' : 'status', status: res.status, message: `HTTP ${res.status}`, url: url.pathname };
    }
    const fatal = FATAL_PATTERNS.find((p) => res.body.includes(p));
    if (fatal) return { ok: false, code: 'fatal_marker', status: res.status, message: fatal.slice(0, 60), url: url.pathname };
    const marker = page.marker ?? site.http?.marker;
    if (marker && !res.body.includes(marker)) {
      return { ok: false, code: 'marker_missing', status: res.status, message: 'expected marker not found', url: url.pathname };
    }
    return { ok: true, status: res.status, url: url.pathname };
  }
  return { ok: false, code: 'redirect_loop', url: url.pathname };
}

// All configured HTTP paths with bounded retries (cache warming, short restarts).
export async function httpCheck(site, cfg, testId, { sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
  const pages = site.http?.pages || [{ path: '/', name: 'home' }];
  const attempts = Math.max(1, cfg.http.attempts);
  let last = null;
  for (let attempt = 1; attempt <= attempts; attempt++) {
    const checks = [];
    for (const page of pages) {
      const r = await checkPage(site, page, { testId, timeoutMs: cfg.http.timeout_seconds * 1000, allowHttp: cfg.allowHttp });
      checks.push({
        name: `http:${page.name || page.path}`,
        kind: 'http',
        status: r.ok ? 'pass' : 'fail',
        message: r.ok ? `HTTP ${r.status}` : `${r.code}${r.message ? ': ' + r.message : ''}`,
        http_status: r.status || 0,
        code: r.code,
      });
    }
    last = checks;
    if (checks.every((c) => c.status === 'pass')) break;
    if (attempt < attempts) await sleep(cfg.http.retry_delay_seconds * 1000);
  }
  const failed = last.filter((c) => c.status !== 'pass');
  return {
    http: failed.length ? 'HTTP_FAIL' : 'HTTP_PASS',
    http_status: (failed[0] || last[0]).http_status,
    checks: last.map(({ code, http_status, ...rest }) => rest),
  };
}
