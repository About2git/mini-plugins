// WooCommerce profile: product -> add to cart -> cart -> checkout, stopping before any real payment.
// A full order is only placed with an explicit sandbox gateway and test product.
import corporateBasic from './corporate-basic.js';

export default async function woocommerce(ctx) {
  const { page, site, url, step } = ctx;
  const w = site.woocommerce || {};
  if (site.corporate) await corporateBasic(ctx);

  const product = await step('shop:product', async () => {
    if (!w.product_path) throw new Error('product_path not configured');
    const res = await page.goto(url(w.product_path));
    if (!res || res.status() >= 400) throw new Error(`HTTP ${res ? res.status() : 'no response'}`);
    await page.waitForSelector(w.add_to_cart_selector || 'button.single_add_to_cart_button, button[name="add-to-cart"]', { state: 'visible' });
  });
  if (!product) return;

  const added = await step('shop:add_to_cart', async () => {
    await page.click(w.add_to_cart_selector || 'button.single_add_to_cart_button, button[name="add-to-cart"]');
    await page.waitForLoadState('load');
    if (w.added_selector) await page.waitForSelector(w.added_selector, { state: 'visible' });
  });
  if (!added) return;

  const cart = await step('shop:cart', async () => {
    await page.goto(url(w.cart_path || '/cart/'));
    await page.waitForSelector(w.cart_item_selector || '.cart_item, .wc-block-cart-items__row', { state: 'attached' });
  });
  if (!cart) return;

  await step('shop:checkout', async () => {
    await page.goto(url(w.checkout_path || '/checkout/'));
    await page.waitForSelector(w.checkout_selector || 'form.checkout, .wc-block-checkout', { state: 'attached' });
    if (w.payment_selector) await page.waitForSelector(w.payment_selector, { state: 'attached' });
  });

  if (w.sandbox_order && w.sandbox_order.enabled === true && w.sandbox_order.gateway_confirmed_sandbox === true) {
    await step('shop:sandbox_order', async () => {
      for (const [selector, value] of Object.entries(w.sandbox_order.fields || {})) {
        await page.fill(selector, String(value));
      }
      if (w.sandbox_order.gateway_selector) await page.check(w.sandbox_order.gateway_selector);
      await page.click(w.sandbox_order.place_order_selector || '#place_order');
      await page.waitForURL(/order-received|bestellung-erhalten/, { timeout: 45000 });
    });
  }

  // Leave no test item in a persistent cart.
  if (w.empty_cart_selector) {
    await page.goto(url(w.cart_path || '/cart/')).catch(() => {});
    await page.click(w.empty_cart_selector).catch(() => {});
  }
}
