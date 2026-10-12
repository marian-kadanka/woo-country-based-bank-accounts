# Tests

Tests for Country Based Bank Accounts for WooCommerce, covering both the classic and the block
based checkout. They drive a real development store: WP-CLI sets the plugin's options, headless
Chrome plays the customer, and the assertions are made against what is actually rendered and
emailed.

Nothing here ships with the plugin (see `.distignore`).

## Requirements

- Node 22+ (uses the built-in `WebSocket` and `fetch`, no npm packages at all)
- `google-chrome`
- `wp` (WP-CLI) able to reach the site
- a WooCommerce store reachable over HTTP, with the fixtures listed under *Configuration*

## Running

```sh
tests/bin/run-all.sh            # everything
tests/bin/run-all.sh js         # the browser scripts only - touches no site state at all
tests/bin/run-all.sh unit       # the plugin's rules, no browser
tests/bin/run-all.sh classic    # the classic checkout phases only
tests/bin/run-all.sh block      # the block checkout phases only
tests/bin/run-all.sh admin      # the payment settings screen, as an administrator
```

Individual files, once `bin/setup-site.php` has run:

```sh
node tests/unit/blocks-js.test.js                      # no site needed
node tests/unit/bacs-settings-js.test.js               # no site needed, needs Chrome
node tests/unit/bacs-settings-blocks-js.test.js        # no site needed, needs Chrome
wp eval-file <plugin>/tests/unit/plugin-logic.php      # from the WordPress root
wp eval-file <plugin>/tests/unit/key-migration.php     # from the WordPress root
wp eval-file <plugin>/tests/unit/email-status.php      # from the WordPress root
wp eval-file <plugin>/tests/unit/upgrade-from-2x.php   # from the WordPress root
node tests/e2e/classic-checkout.test.js                # needs the classic page as checkout
node tests/e2e/block-checkout.test.js                  # needs the block page as checkout
node tests/e2e/order-placement.test.js classic         # places real orders
node tests/e2e/order-placement.test.js block           # places real orders
```

`run-all.sh` takes care of pointing the store at the right checkout page for each phase.

## What is covered

The plugin has two rules, and they are independent. `lib/expected.js` writes them down once so
that every end-to-end test asserts against the same statement of them:

- **printing** - a customer is printed the bank accounts assigned to their billing country; when
  no account is assigned to it, all accounts are printed rather than none
- **checkout** - with the switch on, direct bank transfer is hidden from a customer whose billing
  country is assigned to no bank account at all

A third rule has no customer side at all: an account's countries stay with it when the bank
account details themselves are edited, which is what `unit/key-migration.php` and
`unit/bacs-settings-js.test.js` are about.

A bank account with no country is assigned to every country, so it is always printed and it also
keeps the gateway available whatever the switch says.

Both are exercised against two account shapes: `all-assigned` (A→SK, B→CZ, C→SK, so one country
deliberately has two accounts) and `one-unassigned` (A→SK, B→CZ, C→nothing).

**`unit/blocks-js.test.js`** loads `assets/js/blocks-checkout.js` against stubbed WooCommerce
Blocks globals: that it registers one `bacs` callback under the `wccbba` namespace, that it
registers nothing at all when there is nothing to hide, that it does not throw when the registry
or `wcSettings` is missing, and the whole callback matrix - an assigned country, an unassigned
one, no country yet, no billing address, no arguments, a truncated payload, and that country
codes are compared whole so `S` does not match `SK`.

**`unit/plugin-logic.php`** asserts the rules against the live plugin instance, which it takes
from the filter the plugin registered - the methods under test are instance methods and the
plugin keeps no reference anywhere. It covers how the switch resolves (`yes`, `no`, an unknown
value, and no stored value at all), how an account's countries read back (a list, an empty list,
a non-array value, no option), both printing and gateway rules as a matrix including the case
where the customer has given no country yet, the markup `WC_Gateway_BACS` really renders through
`woocommerce_thankyou_bacs` and `woocommerce_email_before_order_table`, and what the block
checkout integration hands the browser - including that no bank account detail is ever in it.

**`unit/key-migration.php`** covers the third thing the plugin does: keeping a bank account's
countries when the account itself is saved. They are stored under a hash of the account details,
so an edit changes the key they belong to, and the plugin moves them over. Every case goes
through the hook the Direct bank transfer settings screen fires, with the `$_POST` that screen
submits, and then asks the plugin which countries each stored account has. It covers an account
being edited, the accounts being reordered, reordered and edited at once, one being removed, one
being removed while another is edited, one being added, two accounts sharing their details, two
swapping their details, details that already have countries of their own, and the case
WooCommerce creates by handing an added row the position of a removed one - where the countries
must not follow a position into a bank account nobody assigned them to. Each pairing is covered
both ways: by the key the row carries, and by position alone for a screen without JavaScript.
It also asserts that the hooks sit either side of the gateway's own save, and that the notice
telling the administrator the accounts changed still appears.

The same file covers the other save path: the block based payment settings screen, which writes
the accounts straight to the option over the REST API. Those cases go through a real
`POST /wc-admin/options` request and assert what the `X-WCCBBA-Account-Keys` header does - an
edit, a reorder, an added row, no header at all, a key naming an account that is not there, and
a header that does not match the number of accounts - plus the `wccbba/v1/account-keys` route
the script reads the new keys back from, including that it refuses a visitor.

**`unit/bacs-settings-js.test.js`** loads `assets/js/bacs-settings.js` in headless Chrome against
a rebuilt bank account table, along with WooCommerce's own "+ Add account" handler, index reuse
included. It asserts that every row submits the key of the account it was rendered from, that a
dragged row takes it along, that a removed row submits nothing, that an added row submits an
empty key, and that where WooCommerce makes two rows share a position the added row has the last
word - which is what keeps the other account's countries off it. It also covers the script being
handed no data at all, where it must add no field and leave the save to pair the rows up by
position.

**`unit/upgrade-from-2x.php`** covers a store that was upgraded from 2.x with nobody touching
the settings. 2.x stored the switch as a checkbox and 3.0 shows it as two radio buttons, both
under `wccbba_country_disable_bacs` with the same `yes`/`no` values, and both versions key an
account's countries by `md5( serialize( $account ) )`, so the upgrade is meant to be a file swap.
The cases write the values the way 2.x wrote them and assert that countries saved under the 2.x
option name are still found, that a ticked box arrives as "do not offer it" selected and an
unticked one as "offer it to everyone", that a store which never saved the tab gets the default
with nothing written for it, that rendering the screen rewrites nothing, and that no activation
routine exists to run anything in the first place. What each setting then does at checkout is the
matrices' job, not this file's.

**`unit/email-status.php`** covers the status the bank accounts are emailed on.
`WC_Gateway_BACS::email_instructions()` prints them only for a customer email about a bacs order
whose status matches `woocommerce_bacs_email_instructions_order_status`, which defaults to
on-hold and which a store can move. The cases assert that on the default status the accounts for
the billing country are printed and that nothing is printed on any other, that moving the filter
to `processing` moves the printing with it and still prints the right accounts per country, and
that a filter deciding per order - it is handed the order - is honoured one order at a time.

**`unit/bacs-settings-blocks-js.test.js`** does the same for
`assets/js/bacs-settings-blocks.js`, the half that covers the block based payment settings
screen. That screen lists the accounts as a React list and saves them over the REST API without
reloading, so the list is rebuilt here with the React WordPress ships and put through what the
screen does to it: reordering by handing it a reordered array, editing by replacing an account
and keeping its id, adding and removing. The assertions are on the `X-WCCBBA-Account-Keys`
header the script attaches to the save, on it being left off requests that are none of its
business, and on the keys being read back and re-applied afterwards, since nothing reloads the
page. WooCommerce's own bundle is not loaded - it is only reachable behind an administrator
login - so what this pins down is the behaviour the script depends on, not that screen's markup.

**`e2e/classic-checkout.test.js`** and **`e2e/block-checkout.test.js`** run the same matrix on
both checkouts: every combination of the switch, the two account shapes and three billing
countries. The classic test picks the country in the form and waits for WooCommerce's own
`updated_checkout` to fire; the block test sets it through the Store API. Both assert that the
gateway is shown exactly when it should be, that the server's own gateway list agrees, and that
the browser console stays clean. The block test additionally asserts on the client side half:
that `wccbba_data` is present exactly when there is something to hide, and that the gateway's
registered `canMakePayment` - with the plugin's extension callback wrapped around it - answers
correctly for each country without a page reload. The block matrix also covers the switch option
being absent, which is how a store that never saved the settings tab looks.

**`e2e/order-placement.test.js`** places real orders through the checkout UI and verifies which
bank accounts the customer is given, on the order confirmation page and in the customer email,
asserting the exact set - the accounts that must not appear are checked too. It runs the
everyday case, the case where no account is assigned to the billing country (which is the one
that used to print nothing at all), the case where an unassigned account is the account for the
rest of the world, and the everyday case again with the switch on. Each case also checks the
order status, payment method and title, the gateway instructions, that the cart was emptied and
that the console stayed clean.

Running it for both checkouts is the point rather than duplication: the classic confirmation page
comes from `WC_Shortcode_Checkout`, while the block one is the Order Confirmation block, which
re-fires `woocommerce_thankyou_bacs` from its "Additional information" inner block. Only placing
an order through each proves both paths still filter the accounts.

**`e2e/bacs-settings-screen.test.js`** is the only test that drives wp-admin. The block based
payment settings screen is behind the administrator login, so everything else about it is
asserted against a rebuilt table; this one opens the real screen, renames a bank account through
its modal and saves, then checks both halves of what the plugin does there: the countries
selected for that account follow it to the key its new details have, nothing is left under the
old key, and the "accounts have been updated" warning appears with its link to the plugin's
settings tab. It renames an account on the site and puts the accounts back afterwards.

It signs in with `bin/admin-cookie.php`, which mints the cookies `wp_set_auth_cookie()` would
set for the first administrator - a session token, not a password - prints them for the test and
writes nothing to disk. It refuses to run unless the site host is `localhost`, `127.0.0.1`,
`::1` or ends in `.localhost`, `.test` or `.local`, and the cookies expire after 15 minutes.
`wp user session destroy <user>` ends the session early.

## Nothing here is reachable over HTTP

The plugin lives in the web root, so this directory is protected several ways over:

- `.htaccess` denies everything (Apache). On nginx add:
  `location ~* /wp-content/plugins/woo-country-based-bank-accounts/tests/ { deny all; return 404; }`
- every PHP file here exits unless WordPress is already loaded, so requesting one directly does
  nothing even where `.htaccess` is ignored
- an `index.php` in each directory prevents directory listings
- **nothing sensitive is written here in the first place** - see below

`.gitattributes` and `.distignore` keep the whole directory out of GitHub source zips and of
release builds, so it normally never reaches a live site at all.

## Where runs write their files

Not into the plugin. Browser profiles contain session cookies, the site backup contains the
store's option values, and result files contain order ids and customer emails - none of that
belongs under a web root. Everything goes to `wccbba-tests` in the system temp directory
(`lib/artifacts.js`, and the same rule on the PHP side). Point `artifactsDir` in `config.json`
somewhere else if you prefer.

## Site state

The tests change store settings, so `bin/setup-site.php` first writes every value it is about to
touch to `site-backup.json` in that directory, and `bin/restore-site.php` puts them all back and
deletes the backup. An option that did not exist before a run is deleted again rather than
written, so a store that never saved the plugin's settings tab is left that way.

What it changes while running: the BACS gateway's bank accounts and settings, the plugin's own
options, the checkout page, and guest checkout (enabled so that placing orders does not create
user accounts). `run-all.sh` restores on exit even when a phase fails. If a run is interrupted,
restore by hand:

```sh
wp eval-file <plugin>/tests/bin/restore-site.php
```

**Orders are real.** `order-placement.test.js` creates one order per case and leaves them in
place, because that is the evidence that the flow worked. Delete them when you are done.

## Configuration

`config.json` holds everything site specific. To point the suite at another store:

| Key | What it is | How to find it |
| --- | --- | --- |
| `baseUrl`, `wpPath` | store URL and WordPress root | — |
| `productId` | one purchasable product that needs no shipping | `wp wc product list --user=1` |
| `countries` | two countries to assign accounts to, and one to assign to nothing | the store must sell to all three |
| `address` | the billing address used at checkout, without a country | the postcode has to be valid in all three countries |
| `accounts` | the test bank accounts, each with a unique `iban` used as the marker the assertions search for | — |
| `bacsSettings` | the gateway settings the run writes, including the instructions marker | — |

Checkout page ids and the accounts' option names are never configured. The plugin keys a bank
account's countries by `md5( serialize( $account ) )`, which can only be computed from the value
actually stored, so `bin/setup-site.php` writes the accounts, reads them back, computes the names
the way the plugin does and records them in `site-state.json`, which `config.js` merges in. The
same file records the two checkout pages, which are resolved in this order:

1. the page the store has configured (`woocommerce_checkout_page_id`), if its content matches the
   kind being looked for
2. a page a previous run created, marked with the `_wccbba_test_page` meta
3. the oldest published page whose content carries `[woocommerce_checkout]` or the
   `wp:woocommerce/checkout` block
4. failing all of that, a new page, which `bin/restore-site.php` deletes again afterwards

Not deriving these from settings would be a real bug: WooCommerce's "switch to block checkout"
swaps the two pages' slugs, which would silently point the suite at the wrong URLs.
