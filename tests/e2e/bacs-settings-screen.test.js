/**
 * The block based payment settings screen, driven as an administrator.
 *
 * Everything else about that screen is tested against a rebuilt table, because the screen itself
 * is behind the login. This is the real thing: log in with the cookies bin/admin-cookie.php
 * mints, open Direct bank transfer, rename a bank account through its modal, save, and check
 * both halves of what the plugin promises there -
 *
 *   - the countries selected for that account follow it to the key its new details have
 *   - the warning that the accounts changed is shown, since the screen never reloads
 *
 * Requires tests/bin/setup-site.php. Restores the bank accounts it renames.
 *
 *   node tests/e2e/bacs-settings-screen.test.js
 */
const { execFileSync } = require('child_process');
const { Chrome, sleep } = require('../lib/cdp.js');
const { profilePath } = require('../lib/artifacts.js');
const { wp, setAccountCountries, deleteOption } = require('../lib/wp.js');
const config = require('../config.js');

const SCREEN = `${config.baseUrl}/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Foffline%2Fbacs`;
const RENAMED = 'WCCBBA Renamed Account A';

/** The cookies an administrator's browser holds, minted for this run. */
function adminCookies() {
  const out = execFileSync(
    'wp',
    ['eval-file', 'wp-content/plugins/woo-country-based-bank-accounts/tests/bin/admin-cookie.php'],
    { cwd: config.wpPath, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }
  );
  const answer = JSON.parse(out.trim().split('\n').pop());
  if (answer.error) throw new Error(answer.error);
  return answer;
}

/** The option name holding each stored account's countries, in order, as the plugin computes it. */
function accountKeys() {
  return JSON.parse(
    wp(['eval', 'echo wp_json_encode( array_map( array( "WC_Country_Based_Bank_Accounts", "account_key" ), array_values( (array) get_option( "woocommerce_bacs_accounts" ) ) ) );'], { quiet: true })
  );
}

function storedAccounts() {
  return wp(['option', 'get', 'woocommerce_bacs_accounts', '--format=json'], { quiet: true });
}

function countriesOf(option) {
  try {
    return JSON.parse(wp(['option', 'get', option, '--format=json'], { quiet: true }));
  } catch (e) {
    return null;
  }
}

let pass = 0;
let fail = 0;
function check(name, got, expected) {
  const ok = JSON.stringify(got) === JSON.stringify(expected);
  ok ? pass++ : fail++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${ok ? '' : `  got=${JSON.stringify(got)} expected=${JSON.stringify(expected)}`}`);
}

(async () => {
  const before = storedAccounts();
  const session = adminCookies();
  console.log(`signed in as ${session.user} on ${session.host}`);

  setAccountCountries({ a: ['SK'], b: ['CZ'], c: null });
  const keysBefore = accountKeys();

  const chrome = await new Chrome({ port: 9339, profile: profilePath('admin-screen') }).launch();
  let newKey = null;

  try {
    for (const cookie of session.cookies) {
      await chrome.send('Network.setCookie', {
        url: config.baseUrl,
        name: cookie.name,
        value: cookie.value,
        domain: session.host,
        path: session.path,
        secure: cookie.secure,
        httpOnly: true,
      });
    }

    await chrome.goto(SCREEN);
    await chrome.waitFor(`document.querySelectorAll('.bank-accounts__list-item:not(.action)').length === 3`, 30000);

    // --- the screen loads for an administrator and the rows are tagged ------------------------
    const tags = await chrome.eval(`
      return [...document.querySelectorAll('.bank-accounts__list-item:not(.action)')].map((r) => r.getAttribute('data-wccbba-key'));
    `);
    check('every account row carries its key', tags, keysBefore);

    // --- rename the first account through its modal -------------------------------------------
    const opened = await chrome.eval(`
      const row = document.querySelector('.bank-accounts__list-item:not(.action)');
      const menu = row.querySelector('.bank-accounts__list-item-after button');
      if (!menu) return 'no menu button';
      menu.click();
      return 'clicked';
    `);
    check('the account menu opens', opened, 'clicked');

    // "View / edit", matched as plain text so no regex has to survive two layers of quoting
    const menuItem = `[...document.querySelectorAll('[role="menuitem"], .components-menu-item__button, button')]`
      + `.find((i) => i.textContent.replace(/\\s/g, '').toLowerCase().indexOf('view/edit') !== -1)`;

    await chrome.waitFor(menuItem, 10000);
    await chrome.eval(menuItem + '.click();');

    await chrome.waitFor(`document.querySelector('.bank-account-modal')`, 10000);

    const typed = await chrome.eval(`
      const label = [...document.querySelectorAll('.bank-account-modal label')].find((l) => /account name/i.test(l.textContent));
      if (!label) return 'no account name field';
      const input = document.getElementById(label.getAttribute('for')) || label.parentElement.querySelector('input');
      if (!input) return 'no input';
      // React tracks the value itself, so set it the way the browser would
      Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(input, ${JSON.stringify(RENAMED)});
      input.dispatchEvent(new Event('input', { bubbles: true }));
      return input.value;
    `);
    check('the account name is typed into the modal', typed, RENAMED);

    await chrome.eval(`document.querySelector('.bank-account-modal__save').click();`);
    await chrome.waitFor(`!document.querySelector('.bank-account-modal')`, 10000);
    await chrome.waitFor(`[...document.querySelectorAll('.bank-accounts__list-item:not(.action)')].some((r) => r.textContent.indexOf(${JSON.stringify(RENAMED)}) !== -1)`, 10000);

    // --- save the settings ---------------------------------------------------------------------
    const saved = await chrome.eval(`
      const button = [...document.querySelectorAll('button')].find((b) => /save changes/i.test(b.textContent));
      if (!button) return 'no save button';
      button.click();
      return 'clicked';
    `);
    check('the settings are saved', saved, 'clicked');

    // --- the warning the screen has no page to print ---------------------------------------------
    let warned = true;
    try {
      await chrome.waitFor(`document.body.innerText.indexOf('adjust Country Based Bank Accounts settings') !== -1`, 25000);
    } catch (e) {
      warned = false;
    }
    check('the accounts changed warning is shown', warned, true);

    const noticeLink = await chrome.eval(`
      const link = [...document.querySelectorAll('a')].find((a) => (a.href || '').indexOf('tab=wccbba') !== -1);
      return link ? 'present' : 'missing';
    `);
    check('it links to the plugin settings', noticeLink, 'present');

    // --- the countries followed the rename -------------------------------------------------------
    await sleep(1000);
    const keysAfter = accountKeys();
    newKey = keysAfter[0];

    check('the first account has a new key', newKey !== keysBefore[0], true);
    check('its countries moved with it', countriesOf(newKey), ['SK']);
    check('nothing is left under the old key', countriesOf(keysBefore[0]), null);
    check('the other accounts are untouched', countriesOf(keysBefore[1]), ['CZ']);
    check('the rename reached the database', storedAccounts().indexOf(RENAMED) !== -1, true);

    check('the console stayed clean', [chrome.pageErrors, chrome.consoleErrors], [[], []]);
  } finally {
    await chrome.close();

    // Put the bank accounts back the way bin/setup-site.php wrote them, so the phases that run
    // after this one still find the accounts they assert on.
    if (newKey) deleteOption(newKey);
    wp(['option', 'update', 'woocommerce_bacs_accounts', before, '--format=json'], { quiet: true });
  }

  console.log(`\nBACS SETTINGS SCREEN: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
