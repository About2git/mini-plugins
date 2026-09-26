// Runner configuration. Target URLs live only here; the Parent sends site_id/profile_id.
import fs from 'node:fs';
import path from 'node:path';

const PROFILE_IDS = new Set(['corporate-basic', 'woocommerce', 'http-only']);

function parseKeys(value) {
  // "k2:secret2,k1:secret1" -> { k2: 'secret2', k1: 'secret1' }
  const keys = {};
  for (const part of String(value || '').split(',')) {
    const i = part.indexOf(':');
    if (i > 0) keys[part.slice(0, i).trim()] = part.slice(i + 1).trim();
  }
  return keys;
}

export function hostOf(url) {
  return new URL(url).hostname.toLowerCase();
}

export function loadConfig(env = process.env) {
  const file = env.MSUG_RUNNER_CONFIG || path.resolve('config/runner.local.json');
  const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
  const allowHttp = env.MSUG_ALLOW_HTTP_FOR_TESTS === '1';

  const cfg = {
    listen: { host: '127.0.0.1', port: 8787, ...(raw.listen || {}) },
    concurrency: Math.max(1, Math.min(4, raw.concurrency || 2)),
    stateDir: path.resolve(raw.state_dir || './state'),
    artifactsDir: path.resolve(raw.artifacts_dir || './artifacts'),
    parent: raw.parent || {},
    http: { initial_delay_seconds: 0, attempts: 3, retry_delay_seconds: 20, timeout_seconds: 20, ...(raw.http || {}) },
    browser: { executable_path: env.MSUG_CHROMIUM_PATH || raw.browser?.executable_path || undefined, navigation_timeout_seconds: 30, ...(raw.browser || {}) },
    sites: {},
    triggerKeys: parseKeys(env.MSUG_TRIGGER_KEYS),
    statusKeys: parseKeys(env.MSUG_STATUS_KEYS),
    callbackSecret: env.MSUG_CALLBACK_SECRET || '',
    allowHttp,
  };

  if (!cfg.parent.callback_url || !/^https:\/\//.test(cfg.parent.callback_url) && !allowHttp) {
    throw new Error('parent.callback_url must be an https URL');
  }
  if (!Object.keys(cfg.triggerKeys).length || !Object.keys(cfg.statusKeys).length || cfg.callbackSecret.length < 32) {
    throw new Error('MSUG_TRIGGER_KEYS, MSUG_STATUS_KEYS and MSUG_CALLBACK_SECRET (>= 32 chars) are required');
  }
  for (const [id, site] of Object.entries(raw.sites || {})) {
    if (!/^\d+$/.test(id)) throw new Error(`site id ${id} must be numeric`);
    const base = new URL(site.base_url);
    if (base.protocol !== 'https:' && !(allowHttp && base.protocol === 'http:')) throw new Error(`site ${id}: base_url must be https`);
    const allowed = new Set([base.hostname.toLowerCase(), ...(site.allowed_hosts || []).map((h) => h.toLowerCase())]);
    for (const p of site.profiles || []) {
      if (!PROFILE_IDS.has(p)) throw new Error(`site ${id}: unknown profile ${p}`);
    }
    cfg.sites[id] = { ...site, id: Number(id), base_url: base.origin, allowed_hosts: [...allowed], profiles: site.profiles || ['http-only'] };
  }
  return cfg;
}

// Build an absolute URL on the site and refuse anything that leaves the allowlist.
export function siteUrl(site, pathname, allowHttp = false) {
  const url = new URL(pathname || '/', site.base_url);
  if (url.protocol !== 'https:' && !(allowHttp && url.protocol === 'http:')) throw new Error('non-https target');
  if (!site.allowed_hosts.includes(url.hostname.toLowerCase())) throw new Error('host not allowed');
  return url;
}
