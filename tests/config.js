/**
 * Site specific values for the tests, shared with the PHP helpers in tests/bin.
 * See tests/README.md for what each value means and how to find it on your own site.
 *
 * Checkout page ids and the option keys of the test bank accounts are deliberately NOT in
 * config.json. Page ids differ between sites and slugs change (WooCommerce's "switch to block
 * checkout" renames both pages), and the plugin keys a bank account's countries by
 * md5( serialize( $account ) ), which can only be computed from the value actually stored.
 * bin/setup-site.php resolves both and writes them to site-state.json, which is merged in here.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const config = require('./config.json');

// Where a run keeps its files. lib/artifacts.js and the PHP helpers use the same rule.
const artifactsBase = config.artifactsDir || path.join(os.tmpdir(), 'wccbba-tests');

let state = {};
try {
  state = JSON.parse(fs.readFileSync(path.join(artifactsBase, 'site-state.json'), 'utf8'));
} catch (e) {
  // setup-site.php has not run yet; the accessors below explain that when a test asks.
}

const SETUP_HINT =
  'Run bin/setup-site.php first:\n' +
  '  wp eval-file wp-content/plugins/woo-country-based-bank-accounts/tests/bin/setup-site.php';

/**
 * Absolute url of the discovered checkout page.
 *
 * @param {'classic'|'block'} kind Which checkout to drive.
 */
function checkoutUrl(kind) {
  const p = kind === 'block' ? state.blockCheckoutPath : state.classicCheckoutPath;
  if (!p) {
    throw new Error(`No ${kind} checkout page recorded. ${SETUP_HINT}`);
  }
  return config.baseUrl + p;
}

/**
 * The option name holding the countries of one test bank account.
 *
 * Throws rather than returning undefined: a test that wrote countries to the option
 * "wccbba_undefined" would pass while asserting nothing.
 *
 * @param {'a'|'b'|'c'} ref Which test account, as named in config.json.
 */
function accountOption(ref) {
  const name = (state.accountOptions || {})[ref];
  if (!name) {
    throw new Error(`No option name recorded for test account "${ref}". ${SETUP_HINT}`);
  }
  return name;
}

/** The IBAN marker of one test account, which is what the printing assertions look for. */
function marker(ref) {
  const account = config.accounts.find((a) => a.ref === ref);
  if (!account) {
    throw new Error(`No test account "${ref}" in config.json`);
  }
  return account.iban;
}

/** Every account's marker, so a test can assert on the ones that must NOT appear too. */
function allMarkers() {
  return config.accounts.map((a) => a.iban);
}

module.exports = Object.assign(
  { artifactsBase, checkoutUrl, accountOption, marker, allMarkers },
  config,
  state
);
