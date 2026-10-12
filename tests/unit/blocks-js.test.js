/**
 * Unit tests for assets/js/blocks-checkout.js.
 *
 * Loads the real script against stubbed WooCommerce Blocks globals and checks what it registers
 * and how its canMakePayment callback answers. Needs no running site:
 *
 *   node tests/unit/blocks-js.test.js
 */
const fs = require('fs');
const path = require('path');

const SCRIPT = path.join(__dirname, '..', '..', 'assets', 'js', 'blocks-checkout.js');
const src = fs.readFileSync(SCRIPT, 'utf8');

/**
 * Runs the script with the globals it expects, and reports what it registered.
 *
 * @param {Object|null} data   What getSetting('wccbba_data') returns, null to leave it unset.
 * @param {Object}      broken Globals to withhold: { registry: true } or { settings: true }.
 */
function load(data, broken = {}) {
  const calls = [];
  const win = {
    wc: {
      wcBlocksRegistry: broken.registry ? undefined : {
        registerPaymentMethodExtensionCallbacks: (namespace, callbacks) => calls.push({ namespace, callbacks }),
      },
      wcSettings: broken.settings ? undefined : {
        getSetting: (name, fallback) => (name === 'wccbba_data' && data !== null ? data : fallback),
      },
    },
  };
  new Function('window', src)(win);
  return calls;
}

let pass = 0, fail = 0;
function check(name, got, expected) {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  ok ? pass++ : fail++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : `  got=${JSON.stringify(got)} expected=${JSON.stringify(expected)}`}`);
}

const ACTIVE = { active: true, countries: ['SK', 'CZ'] };

// --- registration -----------------------------------------------------------------------------
const calls = load(ACTIVE);
check('registers once', calls.length, 1);
check('registers under the plugin namespace', calls[0] && calls[0].namespace, 'wccbba');
check('registers a callback for bacs only', calls[0] && Object.keys(calls[0].callbacks), ['bacs']);

// Nothing to hide means nothing to register: no callback wrapping the gateway for no reason.
check('inactive data registers nothing', load({ active: false }).length, 0);
check('missing data registers nothing', load(null).length, 0);
check('empty data registers nothing', load({}).length, 0);

// The script is enqueued by WooCommerce with wc-blocks-registry and wc-settings as dependencies,
// but a page that somehow lacks them must not throw - that would break the whole checkout.
check('survives without the registry', load(ACTIVE, { registry: true }).length, 0);
check('survives without wcSettings', load(ACTIVE, { settings: true }).length, 0);

// --- the callback -----------------------------------------------------------------------------
function canMakePayment(data, args) {
  const registered = load(data);
  if (!registered.length) throw new Error('nothing was registered, so there is no callback to call');
  return registered[0].callbacks.bacs(args);
}

check('an assigned country keeps it', canMakePayment(ACTIVE, { billingAddress: { country: 'SK' } }), true);
check('the other assigned country keeps it', canMakePayment(ACTIVE, { billingAddress: { country: 'CZ' } }), true);
check('an unassigned country hides it', canMakePayment(ACTIVE, { billingAddress: { country: 'DE' } }), false);

// Before the customer has chosen a country the gateway is left alone, the same as on the server.
check('no country yet keeps it', canMakePayment(ACTIVE, { billingAddress: { country: '' } }), true);
check('no billing address at all keeps it', canMakePayment(ACTIVE, {}), true);
check('no arguments at all keeps it', canMakePayment(ACTIVE, undefined), true);

// A truncated payload must not hide the gateway from everybody.
check('no country list keeps it', canMakePayment({ active: true }, { billingAddress: { country: 'DE' } }), false);

// Country codes are compared whole: "S" must not match "SK".
check('a partial code does not match', canMakePayment(ACTIVE, { billingAddress: { country: 'S' } }), false);

console.log(`\nBLOCKS JS: ${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
