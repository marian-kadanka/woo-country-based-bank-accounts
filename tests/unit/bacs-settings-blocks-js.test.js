/**
 * Unit tests for assets/js/bacs-settings-blocks.js, in a real browser.
 *
 * The block based payment settings screen lists the bank accounts as a React list keyed by an id
 * of its own, and saves them over the REST API without reloading the page. The script has to
 * hand every row the key of the account it was rendered from, keep that key on the row while the
 * list is dragged about and edited, and send the keys along with the save.
 *
 * The list here is rebuilt with the React WordPress itself ships, doing to it exactly what
 * WooCommerce's screen does: reordering by handing the list a reordered array, editing by
 * replacing an account and keeping its id, adding and removing. WooCommerce's own bundle is not
 * loaded - it is only reachable behind an administrator login - so what this pins down is the
 * behaviour the script depends on, not that screen's markup.
 *
 * Needs Chrome and a WordPress to read React from, but no site state and no fixture:
 *
 *   node tests/unit/bacs-settings-blocks-js.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome } = require('../lib/cdp.js');
const { profilePath } = require('../lib/artifacts.js');
const config = require('../config.js');

const SCRIPT = path.join(__dirname, '..', '..', 'assets', 'js', 'bacs-settings-blocks.js');
const src = fs.readFileSync(SCRIPT, 'utf8');

const vendor = path.join(config.wpPath, 'wp-includes', 'js', 'dist', 'vendor');
const react = fs.readFileSync(path.join(vendor, 'react.min.js'), 'utf8');
const reactDom = fs.readFileSync(path.join(vendor, 'react-dom.min.js'), 'utf8');

const KEYS = ['wccbba_keyAAA', 'wccbba_keyBBB', 'wccbba_keyCCC'];

/** The account list, as WooCommerce builds it: keyed by a client side id, with an "add" row. */
const APP = `
  const e = React.createElement;
  const rid = () => Math.random().toString(36).substring(2, 10);
  const paint = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
  let setItems;

  // The markup WooCommerce renders: the container and every account are divs, because the
  // accounts are the sortable items of a drag and drop list, and the row that adds an account
  // is the one li in there.
  function Row({ item, first }) {
    return e('div', { className: 'sortable-item bank-accounts__list-item' + (first ? ' first-item' : '') },
      e('div', { className: 'bank-accounts__list-item-inner' },
        e('div', { className: 'bank-accounts__list-item-before' }),
        e('div', { className: 'bank-accounts__list-item-text' },
          e('div', null, item.account_name),
          e('div', null, item.account_number || item.iban),
          e('div', null, item.bank_name || '')),
        e('div', { className: 'bank-accounts__list-item-after' })));
  }

  function List() {
    const [items, set] = React.useState(window.__seed.map((a) => ({ ...a, id: rid() })));
    setItems = set;
    window.__items = items;
    return e('div', { className: 'sortable-container bank-accounts__list' },
      e('div', { className: 'bank-accounts__list-header' }, 'Account Name'),
      items.map((item, i) => e(Row, { key: item.id, item, first: 0 === i })),
      e('li', { className: 'bank-accounts__list-item action' }, '+ Add account')
    );
  }

  window.renderList = async () => {
    ReactDOM.createRoot(document.getElementById('app')).render(e(List));
    await paint();
  };

  // What the drag handle does: the list is handed a reordered array
  window.reorder = async (from, to) => {
    const next = window.__items.slice();
    next.splice(to, 0, next.splice(from, 1)[0]);
    setItems(next);
    await paint();
  };
  // What the modal does on save: the account is replaced, its id is kept
  window.edit = async (at, iban) => {
    const next = window.__items.slice();
    next[at] = { ...next[at], iban, id: next[at].id };
    setItems(next);
    await paint();
  };
  window.add = async (iban) => {
    setItems([...window.__items, { id: rid(), account_name: 'New', iban }]);
    await paint();
  };
  window.remove = async (at) => {
    const next = window.__items.slice();
    next.splice(at, 1);
    setItems(next);
    await paint();
  };

  // The accounts as the screen would send them: whatever is listed, ids stripped
  window.payload = () => window.__items.map(({ id, ...rest }) => rest);
`;

/** A stand-in for @wordpress/api-fetch: middlewares, and the one request the script makes. */
const API_FETCH = `
  window.__middlewares = [];
  window.__sent = null;
  window.__retagged = [];

  const apiFetch = (options) => {
    if (String(options.path).indexOf('/wccbba/v1/account-keys') === 0) {
      window.__retagged.push(options.path);
      return window.__keysRoute
        ? Promise.resolve({ keys: window.__keysRoute, changed: !!window.__changed, notice: window.__notice })
        : Promise.reject(new Error('route unavailable'));
    }
    return Promise.resolve({});
  };
  apiFetch.use = (middleware) => window.__middlewares.push(middleware);

  window.__notices = [];
  window.wp = {
    apiFetch,
    data: {
      dispatch: (store) =>
        store === 'core/notices'
          ? { createNotice: (status, text, options) => window.__notices.push({ status, text, options }) }
          : {},
    },
  };

  // Runs a request through the middlewares the way api-fetch does, and records what comes out
  window.send = (options) => {
    const chain = window.__middlewares.reduceRight(
      (next, middleware) => (o) => middleware(o, next),
      (o) => { window.__sent = o; return Promise.resolve({ saved: true }); }
    );
    return chain(options);
  };

  window.saveRequest = () => ({
    path: '/wc-admin/options',
    method: 'POST',
    data: { woocommerce_bacs_accounts: window.payload() },
  });
`;

/**
 * Renders the list, loads the real script against it and runs $steps.
 *
 * @param {Chrome}     chrome
 * @param {Array}      seed  Accounts to list, in stored order.
 * @param {Array|null} keys  What the plugin hands over, null to hand over nothing.
 * @param {string}     steps What to do before the assertions are read.
 */
async function run(chrome, seed, keys, steps = '') {
  return chrome.eval(`
    document.body.innerHTML = '<div id="app"></div>';
    window.__seed = ${JSON.stringify(seed)};
    (0, eval)(${JSON.stringify(react)});
    (0, eval)(${JSON.stringify(reactDom)});
    (0, eval)(${JSON.stringify(APP)});
    (0, eval)(${JSON.stringify(API_FETCH)});
    ${keys === null ? 'delete window.wccbbaBacsSettings;' : `window.wccbbaBacsSettings = { keys: ${JSON.stringify(keys)} };`}
    window.__keysRoute = null;
    window.__changed = false;
    window.__notice = { text: 'Your bank accounts details have been updated.', label: 'here', url: 'https://example.test/wp-admin/admin.php?page=wc-settings&tab=wccbba' };

    await window.renderList();
    (0, eval)(${JSON.stringify(src)});
    await new Promise((r) => setTimeout(r, 50));

    ${steps}

    const rows = Array.from(document.querySelectorAll('.bank-accounts__list-item:not(.action)'));
    return {
      tags: rows.map((r) => r.getAttribute('data-wccbba-key')),
      ibans: rows.map((r) => r.querySelector('.bank-accounts__list-item-text').children[1].textContent),
      header: window.__sent && window.__sent.headers ? window.__sent.headers['X-WCCBBA-Account-Keys'] : null,
      sentAccounts: window.__sent && window.__sent.data && window.__sent.data.woocommerce_bacs_accounts
        ? window.__sent.data.woocommerce_bacs_accounts.map((a) => a.iban)
        : null,
      middlewares: window.__middlewares.length,
      retagged: window.__retagged.length,
      notices: window.__notices,
    };
  `);
}

const SEED = [
  { account_name: 'A', iban: 'AAA' },
  { account_name: 'B', iban: 'BBB' },
  { account_name: 'C', iban: 'CCC' },
];

let pass = 0;
let fail = 0;
function check(name, got, expected) {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  ok ? pass++ : fail++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : `  got=${JSON.stringify(got)} expected=${JSON.stringify(expected)}`}`);
}

(async () => {
  const chrome = await new Chrome({ port: 9337, profile: profilePath('bacs-settings-blocks') }).launch();

  try {
    await chrome.goto('about:blank');

    // --- the rows are tagged once the list is there ------------------------------------------
    let result = await run(chrome, SEED, KEYS);
    check('every row is given its account key', result.tags, KEYS);
    check('the accounts are the rows, not the header or the add row', result.ibans, ['AAA', 'BBB', 'CCC']);
    check('one middleware is registered', result.middlewares, 1);

    // --- nothing done to the list ------------------------------------------------------------
    result = await run(chrome, SEED, KEYS, `await window.send(window.saveRequest());`);
    check('the save carries the keys in row order', result.header, KEYS.join(','));
    check('the accounts are sent unchanged', result.sentAccounts, ['AAA', 'BBB', 'CCC']);

    // --- dragged, edited, added, removed -----------------------------------------------------
    result = await run(chrome, SEED, KEYS, `await window.reorder(2, 0); await window.send(window.saveRequest());`);
    check('a dragged row takes its key with it', result.header, [KEYS[2], KEYS[0], KEYS[1]].join(','));
    check('dragged: the accounts are sent in the new order', result.sentAccounts, ['CCC', 'AAA', 'BBB']);

    result = await run(chrome, SEED, KEYS, `await window.edit(1, 'BBB-FIXED'); await window.send(window.saveRequest());`);
    check('an edited row keeps its key', result.header, KEYS.join(','));
    check('the edit is sent', result.sentAccounts, ['AAA', 'BBB-FIXED', 'CCC']);

    result = await run(chrome, SEED, KEYS, `await window.add('NEW'); await window.send(window.saveRequest());`);
    check('an added row has no key to send', result.header, KEYS.join(',') + ',');

    result = await run(chrome, SEED, KEYS, `await window.remove(1); await window.send(window.saveRequest());`);
    check('a removed row takes its key out of the save', result.header, [KEYS[0], KEYS[2]].join(','));

    // The case the whole thing exists for: an account is replaced by one that was added, in the
    // same save as an edit of another account.
    result = await run(
      chrome,
      SEED,
      KEYS,
      `await window.remove(1); await window.add('NEW'); await window.edit(0, 'AAA-FIXED'); await window.send(window.saveRequest());`
    );
    check('removed, added and edited at once', result.header, [KEYS[0], KEYS[2], ''].join(','));
    check('removed, added and edited: accounts sent', result.sentAccounts, ['AAA-FIXED', 'CCC', 'NEW']);

    // --- after a save --------------------------------------------------------------------------
    // The page is never reloaded, so the keys it was given no longer describe what is stored.
    result = await run(
      chrome,
      SEED,
      KEYS,
      `window.__keysRoute = ['wccbba_new1', 'wccbba_new2', 'wccbba_new3'];
       await window.send(window.saveRequest());
       await new Promise((r) => setTimeout(r, 50));`
    );
    check('the keys are read back after a save', result.retagged, 1);
    check('and the rows are tagged with them', result.tags, ['wccbba_new1', 'wccbba_new2', 'wccbba_new3']);

    // --- the warning the settings form prints, which this screen has no page to print into ---
    result = await run(
      chrome,
      SEED,
      KEYS,
      `window.__keysRoute = ['k1', 'k2', 'k3'];
       window.__changed = true;
       await window.send(window.saveRequest());
       await new Promise((r) => setTimeout(r, 50));`
    );
    check('a save that changed the accounts warns once', result.notices.length, 1);
    check('it is a warning', result.notices[0] && result.notices[0].status, 'warning');
    check('with the text the server sent', result.notices[0] && result.notices[0].text, 'Your bank accounts details have been updated.');
    check('and a link to the plugin settings', result.notices[0] && result.notices[0].options.actions, [
      { url: 'https://example.test/wp-admin/admin.php?page=wc-settings&tab=wccbba', label: 'here' },
    ]);
    check('dismissible', result.notices[0] && result.notices[0].options.isDismissible, true);

    result = await run(
      chrome,
      SEED,
      KEYS,
      `window.__keysRoute = ['k1', 'k2', 'k3'];
       await window.send(window.saveRequest());
       await new Promise((r) => setTimeout(r, 50));`
    );
    check('a save that changed nothing does not warn', result.notices, []);

    // A route that cannot answer must not strip the rows of what they have.
    result = await run(
      chrome,
      SEED,
      KEYS,
      `await window.send(window.saveRequest());
       await new Promise((r) => setTimeout(r, 50));`
    );
    check('a failed read back leaves the rows tagged', result.tags, KEYS);

    // --- requests that are none of its business ------------------------------------------------
    result = await run(chrome, SEED, KEYS, `await window.send({ path: '/wc/v3/payment_gateways/bacs', method: 'PUT', data: { settings: {} } });`);
    check('another request gets no header', result.header, null);

    result = await run(chrome, SEED, KEYS, `await window.send({ path: '/wc-admin/options', method: 'POST', data: { woocommerce_other_option: 1 } });`);
    check('another option gets no header', result.header, null);

    // The rows and the accounts being saved have to be the same list.
    result = await run(chrome, SEED, KEYS, `await window.send({ path: '/wc-admin/options', method: 'POST', data: { woocommerce_bacs_accounts: [{ iban: 'AAA' }] } });`);
    check('a save that is not this list gets no header', result.header, null);

    // --- nothing handed over --------------------------------------------------------------------
    result = await run(chrome, SEED, null);
    check('without keys nothing is tagged', result.tags, [null, null, null]);
    check('without keys no middleware is registered', result.middlewares, 0);

    result = await run(chrome, SEED, KEYS.slice(0, 2), `await window.send(window.saveRequest());`);
    check('keys that do not match the list are not used', result.tags, [null, null, null]);
    check('and no header is sent', result.header, null);

    check('the script threw nothing', [chrome.pageErrors, chrome.consoleErrors], [[], []]);
  } finally {
    await chrome.close();
  }

  console.log(`\nBACS SETTINGS BLOCKS JS: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
