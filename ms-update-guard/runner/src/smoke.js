// Playwright smoke tests per site profile (spec 6).
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { siteUrl } from './config.js';
import corporateBasic from './profiles/corporate-basic.js';
import woocommerce from './profiles/woocommerce.js';

const PROFILES = { 'corporate-basic': corporateBasic, woocommerce };

async function launch(cfg) {
  const { chromium } = await import('playwright-core');
  return chromium.launch({ headless: true, executablePath: cfg.browser.executable_path });
}

export async function runSmoke(site, profileId, cfg, testId, { launchBrowser = launch } = {}) {
  const profile = PROFILES[profileId];
  if (!profile) return { smoke: 'SMOKE_SKIPPED', smoke_mandatory: false, checks: [] };

  const checks = [];
  let browser;
  try {
    browser = await launchBrowser(cfg);
    const context = await browser.newContext({ ignoreHTTPSErrors: false, locale: 'de-DE', userAgent: 'MS-Update-Guard-Runner/0.1 Playwright' });
    context.setDefaultNavigationTimeout(cfg.browser.navigation_timeout_seconds * 1000);
    context.setDefaultTimeout(15000);

    // Never navigate or POST to hosts outside the site's allowlist (SSRF, no external automations).
    await context.route('**/*', (route) => {
      const req = route.request();
      const host = new URL(req.url()).hostname.toLowerCase();
      const allowed = site.allowed_hosts.includes(host);
      if (!allowed && (req.isNavigationRequest() || req.method() !== 'GET')) return route.abort('blockedbyclient');
      return route.continue();
    });

    const page = await context.newPage();
    const jsErrors = [];
    page.on('pageerror', (err) => jsErrors.push(String(err.message || err).slice(0, 200)));

    const critical = (site.critical_js || []).map((p) => new RegExp(p));
    const artifact = async () => {
      const id = crypto.randomBytes(12).toString('hex');
      fs.mkdirSync(cfg.artifactsDir, { recursive: true });
      await page.screenshot({ path: path.join(cfg.artifactsDir, `${id}.png`), fullPage: false }).catch(() => {});
      fs.writeFileSync(path.join(cfg.artifactsDir, `${id}.console.txt`), jsErrors.join('\n'));
      return id;
    };

    const step = async (name, fn) => {
      const before = jsErrors.length;
      try {
        await fn();
        const newErrors = jsErrors.slice(before);
        const hit = newErrors.find((e) => critical.some((re) => re.test(e)));
        if (hit) {
          checks.push({ name, kind: 'js', status: 'fail', message: `critical JS error: ${hit.slice(0, 120)}`, artifact: await artifact() });
          return false;
        }
        checks.push({ name, kind: 'smoke', status: 'pass', message: newErrors.length ? `${newErrors.length} uncritical JS error(s)` : '' });
        return true;
      } catch (e) {
        // Messages from Playwright never contain typed form values; keep them short anyway.
        const message = String(e.message || e).split('\n')[0].slice(0, 180);
        checks.push({ name, kind: 'smoke', status: 'fail', message, artifact: await artifact() });
        return false;
      }
    };

    const url = (p) => siteUrl(site, p, cfg.allowHttp).toString();
    if (site.login) {
      const ok = await step('login', async () => {
        const user = process.env[site.login.user_env];
        const pass = process.env[site.login.pass_env];
        if (!user || !pass) throw new Error('test credentials not configured');
        await page.goto(url(site.login.path || '/wp-login.php'));
        await page.fill(site.login.user_selector || '#user_login', user);
        await page.fill(site.login.pass_selector || '#user_pass', pass);
        await Promise.all([page.waitForLoadState('load'), page.click(site.login.submit_selector || '#wp-submit')]);
        if (site.login.expect_selector) await page.waitForSelector(site.login.expect_selector);
      });
      if (!ok) return finish(checks);
    }

    await profile({ page, site, url, step, testId });
  } catch (e) {
    checks.push({ name: 'runner', kind: 'runner', status: 'error', message: String(e.message || e).split('\n')[0].slice(0, 180) });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
  return finish(checks);
}

function finish(checks) {
  let smoke = 'SMOKE_PASS';
  if (checks.some((c) => c.status === 'error')) smoke = 'SMOKE_UNKNOWN';
  if (checks.some((c) => c.status === 'fail')) smoke = 'SMOKE_FAIL';
  if (!checks.length) smoke = 'SMOKE_UNKNOWN';
  return { smoke, smoke_mandatory: true, checks };
}
