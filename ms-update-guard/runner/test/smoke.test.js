// Real browser tests against a local site. Needs a Chromium binary (MSUG_CHROMIUM_PATH).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { runSmoke } from '../src/smoke.js';
import { baseConfig, html, startSite } from './helpers.js';

const chromium = process.env.MSUG_CHROMIUM_PATH;
const skip = !chromium || !fs.existsSync(chromium) ? 'MSUG_CHROMIUM_PATH not set' : false;

const shopRoutes = (cartOk = true) => ({
  '/': html('<header><nav><a class="cta" href="/kontakt/">Anfrage</a></nav></header> Beispiel GmbH'),
  '/leistungen/': html('<header></header><h1>Leistungen</h1>'),
  '/kontakt/': html('<header></header><form class="wpcf7-form"><input id="name"></form>'),
  '/produkt/test/': html('<form method="post" action="/cart/"><button type="submit" name="add-to-cart" class="single_add_to_cart_button">In den Warenkorb</button></form>'),
  '/cart/': (req, res) => html(cartOk ? '<table><tr class="cart_item"><td>Test</td></tr></table>' : '<p>Warenkorb ist leer</p>')(req, res),
  '/checkout/': html('<form class="checkout"><div id="payment"></div></form>'),
  '/js-error/': html('<header></header><script>undefinedFunction()</script>'),
});

test('corporate profile passes on a healthy site', { skip }, async () => {
  const site = await startSite(shopRoutes());
  const cfg = baseConfig(site.port, {
    corporate: {
      pages: [
        { name: 'home', path: '/', expect_selector: 'header nav' },
        { name: 'leistungen', path: '/leistungen/', expect_text: 'Leistungen' },
        { name: 'kontakt', path: '/kontakt/', expect_selector: 'form' },
      ],
      cta_selector: 'a.cta',
      form: { path: '/kontakt/', form_selector: 'form.wpcf7-form' },
    },
  });
  const r = await runSmoke(cfg.sites[43], 'corporate-basic', cfg, '0123456789abcdef');
  assert.equal(r.smoke, 'SMOKE_PASS', JSON.stringify(r.checks));
  assert.equal(r.checks.length, 5);
  assert.ok(!r.checks.some((c) => c.name === 'form:submit'), 'form is never submitted without explicit safe target');
  await site.close();
});

test('missing element fails with a screenshot artifact; critical JS error fails', { skip }, async () => {
  const site = await startSite(shopRoutes());
  const cfg = baseConfig(site.port, {
    critical_js: ['is not defined'],
    corporate: {
      pages: [
        { name: 'home', path: '/', expect_selector: '#does-not-exist' },
        { name: 'js', path: '/js-error/', expect_selector: 'header' },
      ],
    },
  });
  cfg.browser.navigation_timeout_seconds = 5;
  const r = await runSmoke(cfg.sites[43], 'corporate-basic', cfg, '0123456789abcdef');
  assert.equal(r.smoke, 'SMOKE_FAIL');
  const home = r.checks.find((c) => c.name === 'page:home');
  assert.equal(home.status, 'fail');
  assert.ok(fs.existsSync(path.join(cfg.artifactsDir, `${home.artifact}.png`)));
  const js = r.checks.find((c) => c.name === 'page:js');
  assert.equal(js.kind, 'js');
  assert.equal(js.status, 'fail');
  await site.close();
});

test('woocommerce profile reaches checkout; empty cart fails', { skip }, async () => {
  for (const cartOk of [true, false]) {
    const site = await startSite(shopRoutes(cartOk));
    const cfg = baseConfig(site.port, {
      profiles: ['woocommerce'],
      woocommerce: { product_path: '/produkt/test/', cart_path: '/cart/', checkout_path: '/checkout/', payment_selector: '#payment' },
    });
    const r = await runSmoke(cfg.sites[43], 'woocommerce', cfg, '0123456789abcdef');
    if (cartOk) {
      assert.equal(r.smoke, 'SMOKE_PASS', JSON.stringify(r.checks));
      assert.deepEqual(r.checks.map((c) => c.name), ['shop:product', 'shop:add_to_cart', 'shop:cart', 'shop:checkout']);
    } else {
      assert.equal(r.smoke, 'SMOKE_FAIL');
      assert.equal(r.checks.find((c) => c.name === 'shop:cart').status, 'fail');
      assert.ok(!r.checks.some((c) => c.name === 'shop:checkout'));
    }
    await site.close();
  }
});

test('navigation and POSTs to hosts outside the allowlist never leave the browser', { skip }, async () => {
  let foreignHits = 0;
  const foreign = await startSite({ '/': (req, res) => { foreignHits++; res.end('foreign'); } });
  // "localhost" is a different host than the allowed 127.0.0.1.
  const target = `http://localhost:${foreign.port}/`;
  const site = await startSite({
    '/': html(`<header></header><script>fetch(${JSON.stringify(target)}, {method: 'POST', body: 'x'}).catch(() => {}); setTimeout(() => { location.href = ${JSON.stringify(target)}; }, 50);</script>`),
  });
  const cfg = baseConfig(site.port, { corporate: { pages: [{ name: 'home', path: '/', expect_selector: 'header' }] } });
  const r = await runSmoke(cfg.sites[43], 'corporate-basic', cfg, '0123456789abcdef');
  assert.equal(foreignHits, 0, 'foreign host never contacted');
  assert.equal(r.checks.length, 1);
  await site.close();
  await foreign.close();
});
