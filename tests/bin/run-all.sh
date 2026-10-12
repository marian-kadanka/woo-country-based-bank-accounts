#!/usr/bin/env bash
#
# Runs the whole suite and always puts the site back, even if a phase fails.
#
#   tests/bin/run-all.sh           # everything
#   tests/bin/run-all.sh js        # the browser scripts only - touches no site state at all
#   tests/bin/run-all.sh unit      # the plugin's rules, no browser
#   tests/bin/run-all.sh classic   # the classic checkout phases only
#   tests/bin/run-all.sh block     # the block checkout phases only
#   tests/bin/run-all.sh admin     # the payment settings screen, as an administrator
#
# Each checkout has to be the store's own checkout page while it is being driven - the classic
# one for is_checkout() to hold on it, the block one because the Order Confirmation block is
# only used for orders placed through it - so the phases swap woocommerce_checkout_page_id
# between the two pages bin/setup-site.php resolved.
#
set -uo pipefail

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_REL="wp-content/plugins/woo-country-based-bank-accounts"
WP_PATH="$(node -p "require('$TESTS_DIR/config.json').wpPath")"

only="${1:-all}"
failed=0
run() { echo; echo "=== $1 ==="; shift; "$@" || failed=1; }
wp_() { wp --path="$WP_PATH" "$@"; }

case "$only" in
  js|unit|classic|block|admin|all) ;;
  *) echo "Unknown argument \"$only\". Use js, unit, classic, block, admin, or nothing."; exit 1 ;;
esac

# The script tests need neither a site nor a fixture: one stubs its own globals, the other
# rebuilds the bank account table in the browser.
if [ "$only" = "js" ] || [ "$only" = "all" ] || [ "$only" = "unit" ]; then
  run "unit: blocks checkout.js" node "$TESTS_DIR/unit/blocks-js.test.js"
  run "unit: bacs settings.js" node "$TESTS_DIR/unit/bacs-settings-js.test.js"
  run "unit: bacs settings blocks.js" node "$TESTS_DIR/unit/bacs-settings-blocks-js.test.js"
fi
if [ "$only" = "js" ]; then
  echo; [ $failed -eq 0 ] && echo "ALL PASSED" || echo "FAILED"
  exit $failed
fi

# Restore whenever a backup is outstanding: at the end, but also if a phase aborts or the run is
# interrupted. Guarding on the file keeps the trap harmless once the run has already restored.
BACKUP_FILE="$(node -p "require('$TESTS_DIR/config.js').artifactsBase")/site-backup.json"
restore() {
  [ -f "$BACKUP_FILE" ] || return 0
  echo; echo "=== restoring site ==="
  wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/restore-site.php"
}
trap restore EXIT

wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/bin/setup-site.php" || { echo "setup failed"; exit 1; }

CLASSIC_PAGE="$(node -p "require('$TESTS_DIR/config.js').classicCheckoutPageId")"
BLOCK_PAGE="$(node -p "require('$TESTS_DIR/config.js').blockCheckoutPageId")"

# The plugin's rules, against the test bank accounts setup just wrote.
if [ "$only" = "unit" ] || [ "$only" = "all" ]; then
  run "unit: plugin rules" wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/plugin-logic.php"
  run "unit: country options follow an edited account" wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/key-migration.php"
  run "unit: which status the accounts are emailed on" wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/email-status.php"
  run "unit: a store upgraded from 2.x" wp_ eval-file "$WP_PATH/$PLUGIN_REL/tests/unit/upgrade-from-2x.php"
fi

if [ "$only" = "classic" ] || [ "$only" = "all" ]; then
  wp_ option update woocommerce_checkout_page_id "$CLASSIC_PAGE" >/dev/null
  run "e2e: classic checkout matrix" node "$TESTS_DIR/e2e/classic-checkout.test.js"
  run "e2e: classic order placement" node "$TESTS_DIR/e2e/order-placement.test.js" classic
fi

if [ "$only" = "block" ] || [ "$only" = "all" ]; then
  wp_ option update woocommerce_checkout_page_id "$BLOCK_PAGE" >/dev/null
  run "e2e: block checkout matrix" node "$TESTS_DIR/e2e/block-checkout.test.js"
  run "e2e: block order placement" node "$TESTS_DIR/e2e/order-placement.test.js" block
fi

# Signs in with the cookies bin/admin-cookie.php mints, so it needs no checkout page of its own.
if [ "$only" = "admin" ] || [ "$only" = "all" ]; then
  run "e2e: bank accounts settings screen" node "$TESTS_DIR/e2e/bacs-settings-screen.test.js"
fi

restore

echo
[ $failed -eq 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"
exit $failed
