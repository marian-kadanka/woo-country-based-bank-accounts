/**
 * WP-CLI helpers. Every test drives the store through the same option updates an administrator
 * would make on the plugin's settings tab: the switch, and the countries of each bank account.
 */
const { execFileSync } = require('child_process');
const config = require('../config.js');

function wp(args, { quiet = false } = {}) {
  return execFileSync('wp', args, {
    cwd: config.wpPath,
    encoding: 'utf8',
    // Deleting an option that is already gone is a normal step here, and WP-CLI warns about it
    // on stderr without failing. Swallow that rather than let it look like a test problem.
    stdio: ['ignore', 'pipe', quiet ? 'ignore' : 'inherit'],
  }).trim();
}

/** Deletes an option, treating "it was not there" as success. */
function deleteOption(name) {
  try { wp(['option', 'delete', name], { quiet: true }); } catch (e) { /* already gone */ }
}

function wpEval(php) {
  return wp(['eval', php]);
}

function getOption(name) {
  try { return wp(['option', 'get', name, '--format=json'], { quiet: true }); } catch (e) { return null; }
}

/**
 * "Direct bank transfer behavior on checkout": hide it for unassigned countries, or offer it to everyone.
 *
 * Stored in the option the checkbox used before it became a radio, with the same values, so
 * these are the literal strings an administrator's save writes.
 *
 * @param {boolean} hide True for "yes" (hide), false for "no" (offer to everyone).
 */
function setSwitch(hide) {
  wp(['option', 'update', 'wccbba_country_disable_bacs', hide ? 'yes' : 'no']);
  return hide;
}

/** Removes the switch option entirely, which is how a store that never saved the tab looks. */
function unsetSwitch() {
  deleteOption('wccbba_country_disable_bacs');
}

/**
 * Assigns countries to the test bank accounts.
 *
 * `null` deletes the option, which is the state a bank account the administrator never gave a
 * country is in. An empty array is the other way to say the same thing - the plugin treats both
 * as "every country" - and unit/plugin-logic.php covers that one.
 *
 * @param {Object} map e.g. { a: ['SK'], b: ['CZ'], c: null }
 */
function setAccountCountries(map) {
  for (const [ref, countries] of Object.entries(map)) {
    const option = config.accountOption(ref);
    if (countries === null) {
      deleteOption(option);
    } else {
      wp(['option', 'update', option, JSON.stringify(countries), '--format=json']);
    }
  }
  return map;
}

module.exports = { wp, wpEval, getOption, deleteOption, setSwitch, unsetSwitch, setAccountCountries };
