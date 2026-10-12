/**
 * Availability matrix for the CLASSIC (shortcode) checkout.
 *
 * Drives the page the way a customer does: picks a billing country in the form, lets
 * WooCommerce's own update_order_review AJAX refresh the page, then reads the payment methods
 * that are offered. The classic checkout has no client side callback, so this is purely the
 * woocommerce_available_payment_gateways filter being exercised end to end.
 *
 * Requires tests/bin/setup-site.php, with the classic page as the store's checkout page.
 *
 *   node tests/e2e/classic-checkout.test.js
 */
const fs = require('fs');
const { Chrome, sleep } = require('../lib/cdp.js');
const { artifactPath, profilePath } = require('../lib/artifacts.js');
const { setSwitch, setAccountCountries } = require('../lib/wp.js');
const { SHAPES, expectedBacsOffered } = require('../lib/expected.js');
const config = require('../config.js');

const CHECKOUT = config.checkoutUrl('classic');
const COUNTRIES = [config.countries.a, config.countries.b, config.countries.unassigned];

/** Fills the billing fields and waits for WooCommerce to refresh the order review. */
async function setBillingCountry(chrome, country) {
  const a = config.address;
  return chrome.eval(`
    const set = (id, value) => {
      const el = document.getElementById(id);
      if (!el) return false;
      el.value = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    };

    const country = document.getElementById('billing_country');
    if (!country) return { error: 'no billing_country field' };
    if (!([...country.options].some(o => o.value === ${JSON.stringify(country)}))) {
      return { error: 'country ${country} not sold to' };
    }

    window.__updated = false;
    jQuery(document.body).on('updated_checkout', () => { window.__updated = true; });

    set('billing_first_name', ${JSON.stringify(a.first_name)});
    set('billing_last_name', ${JSON.stringify(a.last_name)});
    set('billing_address_1', ${JSON.stringify(a.address_1)});
    set('billing_city', ${JSON.stringify(a.city)});
    set('billing_postcode', ${JSON.stringify(a.postcode)});
    set('billing_email', ${JSON.stringify(a.email)});
    set('billing_phone', ${JSON.stringify(a.phone)});

    // Select2 replaces the native select, so WooCommerce listens for jQuery's change event.
    // trigger() runs the handlers whether or not the value actually changed, so a refresh is
    // always on its way from here and the caller always has something to wait for.
    jQuery(country).val(${JSON.stringify(country)}).trigger('change');
    return { ok: true };
  `);
}

const READ = `
  const inputs = [...document.querySelectorAll('input[name="payment_method"]')];
  return {
    methods: inputs.map(i => i.value).sort(),
    bacsBox: !!document.querySelector('.payment_method_bacs'),
    country: document.getElementById('billing_country') ? document.getElementById('billing_country').value : null,
  };
`;

(async () => {
  const combos = [];
  for (const hide of [false, true]) {
    for (const shape of Object.keys(SHAPES)) {
      for (const country of COUNTRIES) {
        combos.push({ hide, shape, country });
      }
    }
  }

  const chrome = await new Chrome({ profile: profilePath('classic'), port: 9337 }).launch();
  const results = [];
  let pass = 0, fail = 0, n = 0;
  try {
    for (const c of combos) {
      n++;
      setSwitch(c.hide);
      setAccountCountries(SHAPES[c.shape]);

      chrome.clearErrors();
      await chrome.send('Network.clearBrowserCookies'); // fresh session => empty cart, no stale country
      await chrome.goto(`${config.baseUrl}/?add-to-cart=${config.productId}`);
      await chrome.goto(CHECKOUT);
      await chrome.waitFor(`document.querySelector('form.checkout') && typeof window.jQuery === 'function'`);
      await sleep(700);

      const filled = await setBillingCountry(chrome, c.country);
      if (filled.error) { console.log(`SETUP FAIL ${c.shape}/${c.country}: ${filled.error}`); fail++; continue; }
      await chrome.waitFor('window.__updated === true', 25000);
      await sleep(600);

      const view = await chrome.eval(READ);
      const want = expectedBacsOffered(c.hide, c.shape, c.country);
      const got = view.methods.includes('bacs');
      const countryOk = view.country === c.country;
      const errs = [...chrome.consoleErrors, ...chrome.pageErrors].filter((e) => !/favicon|404|net::ERR|Failed to load resource/i.test(e));

      const ok = countryOk && got === want && view.bacsBox === want && errs.length === 0;
      ok ? pass++ : fail++;
      const tag = `switch=${c.hide ? 'hide' : 'offer'} ${c.shape} billed=${c.country}`;
      results.push({ tag, want, got, bacsBox: view.bacsBox, methods: view.methods, countryOk, errs, ok });
      if (!ok) {
        console.log(`FAIL ${tag}`);
        console.log(`     want bacs=${want} got=${got} box=${view.bacsBox} methods=${JSON.stringify(view.methods)}`);
        console.log(`     countryOk=${countryOk} (form says ${view.country}) errs=${JSON.stringify(errs)}`);
      } else {
        console.log(`OK   ${tag} -> bacs ${got ? 'offered' : 'hidden'}`);
      }
    }
  } finally { await chrome.close(); }
  fs.writeFileSync(artifactPath('results-classic.json'), JSON.stringify(results, null, 1));
  console.log(`\nCLASSIC CHECKOUT: ${pass} passed, ${fail} failed, ${n} cases`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('RUNNER ERROR:', e.stack); process.exit(2); });
