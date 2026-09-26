import test from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { httpCheck } from '../src/http-check.js';
import { baseConfig, html, startSite, tmpDir } from './helpers.js';

const noSleep = { sleep: async () => {} };

test('200 with marker passes', async () => {
  const site = await startSite({ '/': html('<nav>x</nav> Beispiel GmbH') });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_PASS');
  await site.close();
});

test('HTTP 500 fails with status_5xx after retries', async () => {
  let hits = 0;
  const site = await startSite({ '/': (req, res) => { hits++; html('There has been a critical error on this website.', 500)(req, res); } });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.equal(r.http_status, 500);
  assert.match(r.checks[0].message, /status_5xx/);
  assert.equal(hits, 2, 'bounded retries');
  await site.close();
});

test('200 alone is not enough: missing marker fails', async () => {
  const site = await startSite({ '/': html('white screen') });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.match(r.checks[0].message, /marker_missing/);
  await site.close();
});

test('WordPress fatal error page with 200 fails', async () => {
  const site = await startSite({ '/': html('Beispiel GmbH <b>Fatal error</b>: Uncaught Error') });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.match(r.checks[0].message, /fatal_marker/);
  await site.close();
});

test('redirect to a foreign host fails', async () => {
  const site = await startSite({ '/': (req, res) => { res.writeHead(302, { Location: 'https://evil.example/' }); res.end(); } });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.match(r.checks[0].message, /redirect_host/);
  await site.close();
});

test('redirect on the same host is followed', async () => {
  const site = await startSite({
    '/': (req, res) => { res.writeHead(301, { Location: '/start/' }); res.end(); },
    '/start/': html('Beispiel GmbH'),
  });
  const cfg = baseConfig(site.port);
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_PASS');
  await site.close();
});

test('timeout fails', async () => {
  const site = await startSite({ '/': () => {} });
  const cfg = baseConfig(site.port);
  cfg.http.timeout_seconds = 0.3;
  const r = await httpCheck(cfg.sites[43], cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.match(r.checks[0].message, /timeout/);
  site.server.closeAllConnections();
  await site.close();
});

test('invalid TLS certificate fails', async (t) => {
  const dir = tmpDir();
  try {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', path.join(dir, 'k.pem'), '-out', path.join(dir, 'c.pem'), '-days', '1', '-subj', '/CN=127.0.0.1'], { stdio: 'ignore' });
  } catch {
    t.skip('openssl not available');
    return;
  }
  const server = https.createServer({ key: fs.readFileSync(path.join(dir, 'k.pem')), cert: fs.readFileSync(path.join(dir, 'c.pem')) }, (req, res) => res.end('Beispiel GmbH'));
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const cfg = baseConfig(1);
  const site = { ...cfg.sites[43], base_url: `https://127.0.0.1:${server.address().port}` };
  const r = await httpCheck(site, cfg, 'abcdef0123456789', noSleep);
  assert.equal(r.http, 'HTTP_FAIL');
  assert.match(r.checks[0].message, /tls/);
  await new Promise((r2) => server.close(r2));
});
