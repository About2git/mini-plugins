// Corporate basic profile: key pages load, navigation/CTA/form exist, no critical JS error.
// A form is only submitted when the site config names an explicit test form and target.
export default async function corporateBasic({ page, site, url, step, testId }) {
  const c = site.corporate || {};
  const pages = c.pages || [{ name: 'home', path: '/', expect_selector: 'nav, header' }];

  for (const p of pages) {
    await step(`page:${p.name || p.path}`, async () => {
      const res = await page.goto(url(p.path));
      if (!res || res.status() >= 400) throw new Error(`HTTP ${res ? res.status() : 'no response'}`);
      if (p.expect_selector) await page.waitForSelector(p.expect_selector, { state: 'attached' });
      if (p.expect_text) {
        const text = await page.textContent('body');
        if (!text || !text.includes(p.expect_text)) throw new Error('expected text missing');
      }
    });
  }

  if (c.cta_selector) {
    await step('cta', async () => {
      await page.goto(url(c.cta_path || '/'));
      await page.waitForSelector(c.cta_selector, { state: 'visible' });
    });
  }

  if (c.form) {
    await step('form:present', async () => {
      await page.goto(url(c.form.path));
      await page.waitForSelector(c.form.form_selector || 'form', { state: 'attached' });
    });
    // Submitting needs an explicit, safe test target (no real leads or automations).
    if (c.form.submit === true && c.form.safe_target_confirmed === true) {
      await step('form:submit', async () => {
        await page.goto(url(c.form.path));
        for (const [selector, value] of Object.entries(c.form.fields || {})) {
          await page.fill(selector, String(value).replace('{test_id}', testId.slice(0, 8)));
        }
        await page.click(c.form.submit_selector);
        await page.waitForSelector(c.form.success_selector, { state: 'visible' });
      });
    }
  }
}
