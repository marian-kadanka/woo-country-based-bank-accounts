/**
 * Availability matrix for the BLOCK based checkout.
 *
 * For every combination of the switch, the bank accounts' countries and the customer's billing
 * country, sets up a cart through the Store API, loads the Checkout block in headless Chrome and
 * asserts on what is actually rendered - plus on the gateway list the server returns, on the
 * client side callback the plugin registers, and on the browser console staying clean.
 *
 * Requires tests/bin/setup-site.php, with the block page as the store's checkout page.
 *
 *   node tests/e2e/block-checkout.test.js
 */
const fs = require('fs');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { setSwitch, unsetSwitch, setAccountCountries } = require('../lib/wp.js');
const { SHAPES, expectedBacsOffered } = require('../lib/expected.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('block');
const COUNTRIES = [config.countries.a, config.countries.b, config.countries.unassigned];

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

/** Puts the product in the cart and the billing country on the customer, server side. */
async function setupCart(chrome, country) {
  const { email, phone, ...addr } = config.address;
  return chrome.eval(`
    ${PAGE_API}
    await api('/cart/items', 'DELETE');
    const add = await api('/cart/add-item', 'POST', { id: ${config.productId}, quantity: 1 });
    if (add.status >= 400) return { error: 'add-item: ' + JSON.stringify(add.body) };
    const addr = { ...${JSON.stringify(addr)}, country: ${JSON.stringify(country)} };
    const cu = await api('/cart/update-customer', 'POST', {
      billing_address: { ...addr, email: ${JSON.stringify(email)}, phone: ${JSON.stringify(phone)} },
      shipping_address: addr,
    });
    if (cu.status >= 400) return { error: 'update-customer: ' + JSON.stringify(cu.body) };
    const cart = (await api('/cart', 'GET')).body;
    return { serverPaymentMethods: cart.payment_methods, billingCountry: cart.billing_address.country };
  `);
}

async function readCheckout(chrome) {
  await chrome.waitFor(
    `document.querySelector('.wp-block-woocommerce-checkout-payment-block') && !document.querySelector('.wc-block-checkout.is-loading') && !document.querySelector('.wc-block-components-skeleton')`
  );
  await sleep(900);
  return chrome.eval(`
    const store = window.wp && wp.data && wp.data.select('wc/store/cart');
    const cartData = store ? store.getCartData() : null;
    const radios = [...document.querySelectorAll('input[name="radio-control-wc-payment-method-options"]')];
    const registry = window.wc && window.wc.wcBlocksRegistry;
    const settings = window.wc && window.wc.wcSettings;
    const bacs = registry && registry.getPaymentMethods ? registry.getPaymentMethods().bacs : null;

    // Ask the registered gateway directly, which is the client side half of the feature: the
    // page's own callback, with the plugin's extension callback wrapped around it.
    const ask = (country) => {
      if (!bacs) return null;
      try {
        return bacs.canMakePayment({ billingAddress: { country }, cartNeedsShipping: false, paymentRequirements: ['products'] });
      } catch (e) { return 'threw: ' + e.message; }
    };

    return {
      methods: radios.map(r => r.value).sort(),
      hydratedPaymentMethods: cartData ? cartData.paymentMethods : null,
      hydratedCountry: cartData && cartData.billingAddress ? cartData.billingAddress.country : null,
      scriptData: settings ? settings.getSetting('wccbba_data', null) : null,
      callbackSaysYes: {
        a: ask(${JSON.stringify(config.countries.a)}),
        b: ask(${JSON.stringify(config.countries.b)}),
        unassigned: ask(${JSON.stringify(config.countries.unassigned)}),
      },
    };
  `);
}

(async () => {
  const combos = [];
  for (const hide of [false, true]) {
    for (const shape of Object.keys(SHAPES)) {
      for (const country of COUNTRIES) {
        combos.push({ hide, shape, country });
      }
    }
  }
  // A store that never saved the settings tab: no switch option at all.
  for (const country of COUNTRIES) {
    combos.push({ hide: null, shape: 'all-assigned', country });
  }

  const chrome = await new Chrome({ profile: profilePath('block'), port: 9336 }).launch();
  const results = [];
  let pass = 0, fail = 0, n = 0;
  try {
    await chrome.goto(`${config.baseUrl}/?add-to-cart=${config.productId}`);

    for (const c of combos) {
      n++;
      // null means "option deleted", which must behave like the documented default (offer).
      if (c.hide === null) { unsetSwitch(); } else { setSwitch(c.hide); }
      setAccountCountries(SHAPES[c.shape]);
      const hideEffective = c.hide === true;

      chrome.clearErrors();
      await chrome.goto(CHECKOUT);
      const setup = await setupCart(chrome, c.country);
      if (setup.error) { console.log(`SETUP FAIL ${c.shape}/${c.country}: ${setup.error}`); fail++; continue; }
      await chrome.goto(CHECKOUT);
      const view = await readCheckout(chrome);

      const want = expectedBacsOffered(hideEffective, c.shape, c.country);
      const gotPage = view.methods.includes('bacs');
      const gotServer = (view.hydratedPaymentMethods || []).includes('bacs');

      // The client side callback only exists when there is something to hide: with the switch
      // off, or with an unassigned account, no script is enqueued at all.
      const wantScript = hideEffective && !Object.values(SHAPES[c.shape]).some((v) => !v);
      const gotScript = !!(view.scriptData && view.scriptData.active);
      const callbackOk = !wantScript || (
        view.callbackSaysYes.a === true &&
        view.callbackSaysYes.b === true &&
        view.callbackSaysYes.unassigned === false
      );

      const countryOk = view.hydratedCountry === c.country;
      const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));

      const ok = countryOk && gotPage === want && gotServer === want && gotScript === wantScript && callbackOk && errs.length === 0;
      ok ? pass++ : fail++;
      const tag = `switch=${c.hide === null ? 'unset' : c.hide ? 'hide' : 'offer'} ${c.shape} billed=${c.country}`;
      results.push({ tag, want, gotPage, gotServer, wantScript, gotScript, callback: view.callbackSaysYes, methods: view.methods, countryOk, errs, ok });
      if (!ok) {
        console.log(`FAIL ${tag}`);
        console.log(`     want bacs=${want} | page=${gotPage} server=${gotServer} methods=${JSON.stringify(view.methods)}`);
        console.log(`     script want=${wantScript} got=${gotScript} data=${JSON.stringify(view.scriptData)} callback=${JSON.stringify(view.callbackSaysYes)}`);
        console.log(`     countryOk=${countryOk} (page says ${view.hydratedCountry}) errs=${JSON.stringify(errs)}`);
      } else {
        console.log(`OK   ${tag} -> bacs ${gotPage ? 'offered' : 'hidden'}`);
      }
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-block.json'), JSON.stringify(results, null, 1));
  console.log(`\nBLOCK CHECKOUT: ${pass} passed, ${fail} failed, ${n} cases`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
