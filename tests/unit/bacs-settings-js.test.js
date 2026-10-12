/**
 * Unit tests for assets/js/bacs-settings.js, in a real browser.
 *
 * The script's whole job is to make each row of the Direct bank transfer account table submit
 * the key of the bank account it was rendered from, under the position WooCommerce is about to
 * save that row as. That only means anything against a DOM that is dragged, added to and
 * removed from, so the table is rebuilt here along with WooCommerce's own "+ Add account"
 * handler - index reuse included - and the assertions are made on what the form submits.
 *
 * Needs Chrome, but no site and no fixture:
 *
 *   node tests/unit/bacs-settings-js.test.js
 */
const fs = require('fs');
const path = require('path');
const { Chrome } = require('../lib/cdp.js');
const { profilePath } = require('../lib/artifacts.js');

const SCRIPT = path.join(__dirname, '..', '..', 'assets', 'js', 'bacs-settings.js');
const src = fs.readFileSync(SCRIPT, 'utf8');

const KEYS = ['wccbba_keyAAA', 'wccbba_keyBBB', 'wccbba_keyCCC'];
const FIELDS = ['account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic'];

/**
 * The markup WC_Gateway_BACS::generate_account_details_html() prints, with $ibans as the rows.
 *
 * Only the parts the script looks for are reproduced: the td the table is wrapped in carries the
 * id, the rows carry the class, and each row's inputs are named by the row's position.
 */
function page(ibans) {
  const rows = ibans
    .map(
      (iban, i) =>
        `<tr class="account"><td class="sort"></td>` +
        FIELDS.map(
          (f) =>
            `<td><input type="text" name="bacs_${f}[${i}]" value="${f === 'iban' ? iban : f + '-' + i}" /></td>`
        ).join('') +
        `</tr>`
    )
    .join('');

  return (
    `<form id="mainform" method="post">` +
    `<table><tbody><tr><td class="forminp" id="bacs_accounts">` +
    `<div class="wc_input_table_wrapper"><table class="widefat wc_input_table sortable">` +
    `<thead><tr><th class="sort"></th></tr></thead>` +
    `<tbody class="accounts">${rows}</tbody>` +
    `</table></div></td></tr></tbody></table>` +
    `<p class="submit"><button type="submit" name="save">Save changes</button></p>` +
    `</form>`
  );
}

/** Helpers that stand in for WooCommerce's own table JS, plus the reader for the assertions. */
const HELPERS = `
  const tbody = () => document.querySelector('#bacs_accounts table tbody');
  const accountRows = () => Array.from(document.querySelectorAll('#bacs_accounts tbody tr.account'));

  // WooCommerce's "+ Add account" handler, index and all: it names the new row's inputs after
  // the number of rows there are, which is a position a removed row may still have had.
  window.wcAddRow = (iban) => {
    const size = accountRows().length;
    const cells = ${JSON.stringify(FIELDS)}
      .map((f) => '<td><input type="text" name="bacs_' + f + '[' + size + ']" /></td>')
      .join('');
    tbody().insertAdjacentHTML('beforeend', '<tr class="account"><td class="sort"></td>' + cells + '</tr>');
    const row = accountRows()[accountRows().length - 1];
    row.querySelector('input[name^="bacs_iban["]').value = iban;
  };

  // "Remove selected account(s)" takes the row out of the table altogether.
  window.wcRemoveRow = (at) => accountRows()[at].remove();

  // What the jQuery UI sortable does to a dragged row: the same element, somewhere else.
  window.wcMoveRow = (from, to) => {
    const rows = accountRows();
    const row = rows[from];
    to >= rows.length - 1 ? tbody().appendChild(row) : tbody().insertBefore(row, rows[to]);
  };

  window.wcEditIban = (at, iban) => {
    accountRows()[at].querySelector('input[name^="bacs_iban["]').value = iban;
  };

  // Submits the form and reports what the server would receive. Two rows can end up sharing a
  // position, and PHP keeps the last value submitted for a name, which is what the maps do.
  window.submitted = () => {
    const form = document.getElementById('mainform');
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

    const position = (input) => input.name.replace(/^[a-z_]+\\[|]$/g, '');
    const rows = accountRows().map((row) => {
      const field = row.querySelector('input.wccbba-account-key');
      return {
        position: position(row.querySelector('input[name^="bacs_account_name["]')),
        key: field ? field.value : null,
        iban: row.querySelector('input[name^="bacs_iban["]').value,
        inRow: field ? field.closest('tr.account') === row : null,
      };
    });

    const keys = {};
    const ibans = {};
    for (const [name, value] of new FormData(form).entries()) {
      const match = name.match(/^(wccbba_account_key|bacs_iban)\\[(\\d+)]$/);
      if (match) {
        (match[1] === 'bacs_iban' ? ibans : keys)[match[2]] = value;
      }
    }

    return { rows, keys, ibans };
  };
`;

/**
 * Lays the table out again, loads the real script against it and runs $steps.
 *
 * @param {Chrome}        chrome
 * @param {Array<string>} ibans  One row per entry, as the table is rendered.
 * @param {Array|null}    keys   What the plugin hands over, null to hand over nothing at all.
 * @param {string}        steps  What to do to the table before submitting it.
 */
async function run(chrome, ibans, keys, steps = '') {
  return chrome.eval(`
    document.body.innerHTML = ${JSON.stringify(page(ibans))};
    ${HELPERS}
    // Nothing to post to, and a dispatched submit event does not navigate anyway
    document.getElementById('mainform').addEventListener('submit', (e) => e.preventDefault());

    ${keys === null ? 'delete window.wccbbaBacsSettings;' : `window.wccbbaBacsSettings = { keys: ${JSON.stringify(keys)} };`}
    eval(${JSON.stringify(src)});

    ${steps}

    return window.submitted();
  `);
}

let pass = 0;
let fail = 0;
function check(name, got, expected) {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  ok ? pass++ : fail++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : `  got=${JSON.stringify(got)} expected=${JSON.stringify(expected)}`}`);
}

/** The pairs the server gets to work with: position => key, in the order the rows are in. */
function pairs(result) {
  return result.rows.map((row) => [row.position, row.key]);
}

(async () => {
  const chrome = await new Chrome({ port: 9336, profile: profilePath('bacs-settings') }).launch();

  try {
    await chrome.goto('about:blank');

    // --- nothing done to the table ---------------------------------------------------------
    let result = await run(chrome, ['A', 'B', 'C'], KEYS);
    check('every row submits its own key', pairs(result), [['0', KEYS[0]], ['1', KEYS[1]], ['2', KEYS[2]]]);
    check('the key field sits in the row it belongs to', result.rows.map((r) => r.inRow), [true, true, true]);

    // The key says which account a row holds, not what has been typed into it.
    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcEditIban(1, 'B-FIXED');`);
    check('an edited row still submits its key', pairs(result), [['0', KEYS[0]], ['1', KEYS[1]], ['2', KEYS[2]]]);
    check('the edit is submitted as well', result.ibans, { 0: 'A', 1: 'B-FIXED', 2: 'C' });

    // --- dragged around --------------------------------------------------------------------
    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcMoveRow(2, 0);`);
    check('a dragged row takes its key with it', pairs(result), [['2', KEYS[2]], ['0', KEYS[0]], ['1', KEYS[1]]]);
    check('dragged: the order submitted is the new one', result.rows.map((r) => r.iban), ['C', 'A', 'B']);

    // --- removed ---------------------------------------------------------------------------
    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcRemoveRow(1);`);
    check('a removed row submits nothing', pairs(result), [['0', KEYS[0]], ['2', KEYS[2]]]);

    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcRemoveRow(0); wcRemoveRow(0); wcRemoveRow(0);`);
    check('an emptied table submits no keys', result.keys, {});

    // --- added -----------------------------------------------------------------------------
    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcAddRow('NEW');`);
    check('an added row submits an empty key', pairs(result), [['0', KEYS[0]], ['1', KEYS[1]], ['2', KEYS[2]], ['3', '']]);

    // --- added onto the position of a removed one ------------------------------------------
    // WooCommerce names the added row after the number of rows, so here it collides with the
    // account that was left at position 2. Both rows submit under that position and the last
    // one wins - which has to be the added row's empty key, not the key of the account whose
    // position it took, or that account's countries would land on a bank account of their own.
    result = await run(chrome, ['A', 'B', 'C'], KEYS, `wcRemoveRow(1); wcAddRow('NEW');`);
    check('the reused position is submitted twice', pairs(result), [['0', KEYS[0]], ['2', KEYS[2]], ['2', '']]);
    check('the added row has the last word on the key', result.keys, { 0: KEYS[0], 2: '' });
    check('and on the account details too', result.ibans, { 0: 'A', 2: 'NEW' });

    // --- nothing handed over ----------------------------------------------------------------
    // No accounts, or an older plugin version mid-upgrade: the script must add no fields at
    // all, so that the save falls back to pairing the rows up by position.
    result = await run(chrome, ['A', 'B', 'C'], null);
    check('without data no key is submitted', result.keys, {});
    check('without data no field is added', result.rows.map((r) => r.key), [null, null, null]);

    // A row the plugin has no key for is a row nothing can be moved onto, not a row that may
    // claim somebody else's key.
    result = await run(chrome, ['A', 'B', 'C'], KEYS.slice(0, 2));
    check('a row with no key of its own submits an empty one', pairs(result), [['0', KEYS[0]], ['1', KEYS[1]], ['2', '']]);

    check('the script threw nothing', [chrome.pageErrors, chrome.consoleErrors], [[], []]);
  } finally {
    await chrome.close();
  }

  console.log(`\nBACS SETTINGS JS: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
