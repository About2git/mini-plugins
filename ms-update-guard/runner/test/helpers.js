import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const SECRETS = {
  trigger: 'trigger-secret-0123456789abcdef0123456789',
  status: 'status-secret-0123456789abcdef01234567890',
  callback: 'callback-secret-0123456789abcdef012345678',
};

// Local test site. routes: { '/path': (req, res) => ... }
export function startSite(routes) {
  return new Promise((resolve) => {
    const server = http.createServer((req, res) => {
      const u = new URL(req.url, 'http://x');
      const h = routes[u.pathname];
      if (h) return h(req, res, u);
      res.writeHead(404);
      res.end('not found');
    });
    server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port, close: () => new Promise((r) => server.close(r)) }));
  });
}

export function html(body, status = 200, headers = {}) {
  return (req, res) => {
    res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8', ...headers });
    res.end(`<!doctype html><html><head><title>t</title></head><body>${body}</body></html>`);
  };
}

export function tmpDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'msug-runner-'));
}

export function baseConfig(port, extraSite = {}) {
  const dir = tmpDir();
  return {
    listen: { host: '127.0.0.1', port: 0 },
    concurrency: 1,
    stateDir: path.join(dir, 'state'),
    artifactsDir: path.join(dir, 'artifacts'),
    parent: { callback_url: 'http://127.0.0.1:9/cb' },
    http: { initial_delay_seconds: 0, attempts: 2, retry_delay_seconds: 0, timeout_seconds: 2 },
    browser: { executable_path: process.env.MSUG_CHROMIUM_PATH, navigation_timeout_seconds: 10 },
    triggerKeys: { k1: SECRETS.trigger },
    statusKeys: { k1: SECRETS.status },
    callbackSecret: SECRETS.callback,
    allowHttp: true,
    sites: {
      43: {
        id: 43,
        base_url: `http://127.0.0.1:${port}`,
        allowed_hosts: ['127.0.0.1'],
        profiles: ['corporate-basic', 'http-only'],
        http: { pages: [{ name: 'home', path: '/', marker: 'Beispiel GmbH' }] },
        ...extraSite,
      },
    },
  };
}
