/**
 * Places real orders and checks which bank accounts the customer is really given.
 *
 * Runs each case through both checkouts, because the two reach the order confirmation page by
 * different routes: the classic one through WC_Shortcode_Checkout, the block one through the
 * Order Confirmation block, which re-fires woocommerce_thankyou_bacs from its "Additional
 * information" inner block. The customer email is rendered server side afterwards.
 *
 * Requires tests/bin/setup-site.php. run-all.sh points the store's checkout page at the right
 * page for each half.
 *
 *   node tests/e2e/order-placement.test.js block
 *   node tests/e2e/order-placement.test.js classic
 *
 * Orders are real and are left in place, because that is the evidence that the flow worked.
 */
const fs = require('fs');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { wpEval, setSwitch, setAccountCountries } = require('../lib/wp.js');
const { SHAPES, expectedPrinted } = require('../lib/expected.js');
const config = require('../config.js');

const KIND = process.argv[2] === 'classic' ? 'classic' : 'block';
const CHECKOUT = config.checkoutUrl(KIND);
const MARKER = config.instructionsMarker;

const PAGE_API = `
  const nonce = window.wcBlocksMiddlewareConfig.storeApiNonce;
  const api = async (p, method, body) => {
    const res = await fetch('/wp-json/wc/store/v1' + p, {
      method, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Nonce': nonce },
      body: body ? JSON.stringify(body) : undefined,
    });
    return { status: res.status, body: await res.json() };
  };
`;

/** Which of the test accounts' markers appear in a piece of text. */
function markersIn(text) {
  return config.accounts.filter((a) => text.includes(a.iban)).map((a) => a.ref);
}

async function placeBlockOrder(chrome, country) {
  const { email, phone, ...addr } = config.address;
  await chrome.goto(CHECKOUT);
  const setup = await chrome.eval(`
    ${PAGE_API}
    await api('/cart/items', 'DELETE');
    await api('/cart/add-item', 'POST', { id: ${config.productId}, quantity: 1 });
    const addr = { ...${JSON.stringify(addr)}, country: ${JSON.stringify(country)} };
    await api('/cart/update-customer', 'POST', {
      billing_address: { ...addr, email: ${JSON.stringify(email)}, phone: ${JSON.stringify(phone)} },
      shipping_address: addr,
    });
    const cart = (await api('/cart', 'GET')).body;
    return { paymentMethods: cart.payment_methods, itemsCount: cart.items_count };
  `);

  await chrome.goto(CHECKOUT);
  await chrome.waitFor(`document.querySelector('#radio-control-wc-payment-method-options-bacs')`);
  await sleep(800);

  // The address is set through the Store API above and the Checkout block is expected to hydrate
  // its fields from the cart. It does not always manage it, and an empty field fails validation
  // instead of placing the order, so fill in whatever did not arrive.
  const address = await chrome.eval(`
    const wanted = {
      email: ${JSON.stringify(email)},
      'billing-first_name': ${JSON.stringify(addr.first_name)},
      'billing-last_name': ${JSON.stringify(addr.last_name)},
      'billing-address_1': ${JSON.stringify(addr.address_1)},
      'billing-city': ${JSON.stringify(addr.city)},
      'billing-postcode': ${JSON.stringify(addr.postcode)},
      'billing-phone': ${JSON.stringify(phone)},
    };
    const filled = [];
    for (const [id, value] of Object.entries(wanted)) {
      const el = document.getElementById(id);
      if (!el || el.value === value) continue;
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(el, value);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      el.dispatchEvent(new Event('blur', { bubbles: true }));
      filled.push(id);
    }
    if (filled.length) await new Promise((r) => setTimeout(r, 1500));
    return { hydrated: filled.length === 0, filled };
  `);
  const picked = await chrome.eval(`
    document.querySelector('#radio-control-wc-payment-method-options-bacs').click();
    const terms = document.querySelector('#terms-and-conditions');
    if (terms && !terms.checked) terms.click();
    await new Promise(r => setTimeout(r, 500));
    return { selected: document.querySelector('#radio-control-wc-payment-method-options-bacs').checked };
  `);
  // The Checkout block re-renders the payment section whenever the cart data settles, and a
  // click that lands on a node React is replacing is swallowed: the button stays enabled, the
  // checkout stays idle and nothing is submitted. Click, give it a moment to leave "idle", and
  // click again if it did not - reporting how many it took, so a swallowed click is visible
  // rather than looking like a timeout.
  let clicks = 0;
  let status = 'idle';

  for (; clicks < 2; clicks++) {
    const click = await chrome.eval(`
      const b = document.querySelector('.wc-block-components-checkout-place-order-button');
      if (!b || b.disabled) return { clicked: false, disabled: b ? b.disabled : null };
      b.click();
      for (let i = 0; i < 20; i++) {
        await new Promise((r) => setTimeout(r, 100));
        const s = wp.data.select('wc/store/checkout').getCheckoutStatus();
        if (s !== 'idle') return { clicked: true, status: s };
      }
      return { clicked: true, status: wp.data.select('wc/store/checkout').getCheckoutStatus() };
    `).catch(() => ({ clicked: true, status: 'navigated' }));

    status = click.status || 'none';
    if (click.clicked && status !== 'idle') break;
  }

  try {
    await chrome.waitFor(`location.pathname.includes('order-received')`, 40000);
  } catch (e) {
    const why = await chrome.eval(`
      return {
        url: location.pathname + location.search,
        checkoutStatus: wp.data.select('wc/store/checkout').getCheckoutStatus(),
        hasError: wp.data.select('wc/store/checkout').hasError ? wp.data.select('wc/store/checkout').hasError() : null,
        orderId: wp.data.select('wc/store/checkout').getOrderId ? wp.data.select('wc/store/checkout').getOrderId() : null,
        notices: [...document.querySelectorAll('.wc-block-components-notice-banner, .woocommerce-error, .wc-block-components-validation-error')].map((n) => n.textContent.trim().slice(0, 120)),
        buttonDisabled: (document.querySelector('.wc-block-components-checkout-place-order-button') || {}).disabled,
        cartItems: wp.data.select('wc/store/cart').getCartData().itemsCount,
      };
    `).catch((err) => ({ evalFailed: String(err.message).slice(0, 80) }));
    console.log('   place order did not complete:', JSON.stringify(why));
    console.log('   clicks:', clicks + 1, 'status after clicking:', status);
    console.log('   console errors:', JSON.stringify(chrome.consoleErrors.slice(0, 3)));
    console.log('   page errors:', JSON.stringify(chrome.pageErrors.slice(0, 3)));
    throw e;
  }
  await sleep(1200);

  return { setup, picked, address, clicks: clicks + 1, status };
}

async function placeClassicOrder(chrome, country) {
  const a = config.address;
  await chrome.goto(`${config.baseUrl}/?add-to-cart=${config.productId}`);
  await chrome.goto(CHECKOUT);
  await chrome.waitFor(`document.querySelector('form.checkout') && typeof window.jQuery === 'function'`);
  await sleep(700);

  const filled = await chrome.eval(`
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (el) { el.value = value; el.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    window.__updated = false;
    jQuery(document.body).on('updated_checkout', () => { window.__updated = true; });
    set('billing_first_name', ${JSON.stringify(a.first_name)});
    set('billing_last_name', ${JSON.stringify(a.last_name)});
    set('billing_address_1', ${JSON.stringify(a.address_1)});
    set('billing_city', ${JSON.stringify(a.city)});
    set('billing_postcode', ${JSON.stringify(a.postcode)});
    set('billing_email', ${JSON.stringify(a.email)});
    set('billing_phone', ${JSON.stringify(a.phone)});
    jQuery('#billing_country').val(${JSON.stringify(country)}).trigger('change');
    return { ok: true };
  `);
  if (!filled.ok) return { setup: { error: 'could not fill the form' } };
  await chrome.waitFor('window.__updated === true', 25000);
  await sleep(600);

  const picked = await chrome.eval(`
    const radio = document.querySelector('#payment_method_bacs');
    if (!radio) return { selected: false, error: 'bacs not offered' };
    radio.click();
    const terms = document.querySelector('#terms');
    if (terms && !terms.checked) terms.click();
    await new Promise(r => setTimeout(r, 400));
    return { selected: radio.checked };
  `);
  if (picked.error) return { setup: { error: picked.error }, picked };

  const methods = await chrome.eval(`return [...document.querySelectorAll('input[name="payment_method"]')].map(i => i.value);`);
  await chrome.eval(`document.querySelector('#place_order').click(); return true;`);
  await chrome.waitFor(`location.pathname.includes('order-received') || document.querySelector('.woocommerce-error')`, 40000);
  await sleep(1000);

  return { setup: { paymentMethods: methods }, picked };
}

(async () => {
  const cases = [
    // The everyday case: the country has accounts of its own.
    { shape: 'all-assigned', country: config.countries.a, hide: false },
    // The one that used to print nothing at all: no account is assigned to this country.
    { shape: 'all-assigned', country: config.countries.unassigned, hide: false },
    // An account with no country is the account for the rest of the world.
    { shape: 'one-unassigned', country: config.countries.unassigned, hide: false },
    // The same normal case with the gateway hidden for other countries, which must not change
    // what a customer whose country does have accounts is given.
    { shape: 'all-assigned', country: config.countries.a, hide: true },
  ];

  const chrome = await new Chrome({ profile: profilePath(`orders-${KIND}`), port: KIND === 'block' ? 9338 : 9339 }).launch();
  const results = [];
  let pass = 0, fail = 0;
  try {
    for (const c of cases) {
      chrome.clearErrors();
      setSwitch(c.hide);
      setAccountCountries(SHAPES[c.shape]);
      await chrome.send('Network.clearBrowserCookies');

      const placed = KIND === 'block' ? await placeBlockOrder(chrome, c.country) : await placeClassicOrder(chrome, c.country);
      if (placed.setup && placed.setup.error) {
        console.log(`SETUP FAIL ${KIND} ${c.shape}/${c.country}: ${placed.setup.error}`);
        fail++;
        continue;
      }

      const page = await chrome.eval(`
        return {
          text: document.body.innerText,
          orderId: (location.pathname.match(/order-received\\/(\\d+)/) || [])[1] || (location.search.match(/order-received=(\\d+)/) || [])[1],
        };
      `);
      const orderId = parseInt(page.orderId, 10);
      if (!orderId) {
        console.log(`FAIL ${KIND} ${c.shape}/${c.country}: no order was created`);
        fail++;
        continue;
      }

      // The customer email, rendered the way WooCommerce renders it when the order goes on hold.
      const server = JSON.parse(wpEval(`
        add_filter( 'pre_wp_mail', '__return_false' );
        $order = wc_get_order( ${orderId} );
        $emails = WC()->mailer()->get_emails();
        $email = $emails['WC_Email_Customer_On_Hold_Order'];
        $email->object = $order;
        $email->recipient = $order->get_billing_email();
        ob_start(); $body = $email->get_content_html(); ob_end_clean();
        echo wp_json_encode( array(
          'status' => $order->get_status(),
          'payment_method' => $order->get_payment_method(),
          'payment_title' => $order->get_payment_method_title(),
          'billing_country' => $order->get_billing_country(),
          'email_body' => $body,
        ) );
      `));

      const cartAfter = await chrome.eval(`
        const c = await (await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' })).json();
        return { itemsCount: c.items_count };
      `);

      const want = expectedPrinted(c.shape, c.country).sort();
      const onPage = markersIn(page.text).sort();
      const inEmail = markersIn(server.email_body).sort();
      const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));

      const checks = {
        bacsOffered: (placed.setup.paymentMethods || []).includes('bacs'),
        bacsSelected: placed.picked.selected,
        statusOnHold: server.status === 'on-hold',
        paymentMethod: server.payment_method === 'bacs',
        paymentTitle: server.payment_title === config.bacsSettings.title,
        billingCountry: server.billing_country === c.country,
        confirmationAccounts: JSON.stringify(onPage) === JSON.stringify(want),
        emailAccounts: JSON.stringify(inEmail) === JSON.stringify(want),
        confirmationInstructions: page.text.includes(MARKER),
        emailInstructions: server.email_body.includes(MARKER),
        cartEmptied: cartAfter.itemsCount === 0,
        noJsErrors: errs.length === 0,
      };
      const failed = Object.entries(checks).filter(([, v]) => !v).map(([k]) => k);
      failed.length ? fail++ : pass++;

      results.push({ kind: KIND, case: `${c.shape}/${c.country}/switch=${c.hide ? 'hide' : 'offer'}`, orderId, want, onPage, inEmail, checks, failed, errs });
      console.log(
        `${failed.length ? 'FAIL' : 'OK  '} ${KIND} ${c.shape} billed=${c.country} switch=${c.hide ? 'hide' : 'offer'}` +
          ` -> order ${orderId}, printed ${onPage.join('+') || 'none'} (want ${want.join('+')})` +
          (failed.length ? `  failed: ${failed.join(', ')}` : '')
      );
      if (failed.includes('emailAccounts')) {
        console.log(`     email had ${inEmail.join('+') || 'none'}`);
      }
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath(`results-orders-${KIND}.json`), JSON.stringify(results, null, 1));
  console.log(`\nORDER PLACEMENT (${KIND}): ${pass} passed, ${fail} failed`);
  console.log('orders created: ' + results.map((r) => r.orderId).filter(Boolean).join(', '));
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
