
-----------------------

# Country Based Bank Accounts for WooCommerce

Assign Direct bank transfer accounts to billing countries. Only matching accounts are printed, and the BACS gateway can be hidden when none match.

-----------------------

### Description

If you sell abroad and hold bank accounts in more than one country, WooCommerce prints every one of them on every order. Customers have to work out which account is meant for them, and some pay into the wrong one or pay for an international transfer they did not need.

This extension assigns the bank accounts of the built in Direct bank transfer (BACS) gateway to the countries they are meant for. A customer billed to Slovakia then gets only your Slovak account, a customer billed to Czechia only your Czech one, both on the order confirmation page and in the order email. Where no account is meant for the billing country, you can either have all of them printed so the customer can still pay, or hide the BACS gateway from that customer at checkout.

### How to set it up

1. Set your bank accounts up as usual in WooCommerce > Settings > Payments > Direct bank transfer.
2. Open WooCommerce > Settings > Country Based Bank Accounts, where every one of those accounts is listed.
3. Select the countries each account is meant for. Leave an account's country list empty to have it printed for every country, which makes it your account for the rest of the world.
4. Under Direct bank transfer behavior on checkout, decide what happens when a billing country appears in none of those lists: either the BACS gateway is hidden from that customer, or it stays on offer and all of your accounts are printed so they can still pay.

### Good to know

* No customer is ever left without an account to pay into. When a billing country appears in none of the lists, all accounts are printed rather than none.
* A bank account whose country list is left empty counts for every country: it is always printed, and it keeps the BACS gateway available to everyone. The settings page warns you when hiding is switched on and therefore has no effect.
* Editing a bank account keeps the countries you selected for it, on the classic settings form as well as on the block based payment settings screen. They used to be lost, because a bank account has no id of its own and WooCommerce had already replaced it by the time the plugin heard about the save.
* Hiding the BACS gateway works on the classic as well as the block based checkout. On the block based one the gateway disappears about a second after the billing address reaches the server, and the browser hides it even when that update never arrives, so a customer on a flaky connection is not offered an account meant for somebody else.
* Only country codes are sent to the customer's browser, never your bank account details.

### Compatibility

This WooCommerce addon is compatible with:
* WooCommerce 3.4 and newer
* both the classic and the block based Cart and Checkout

### Installation

1. Go to Plugins > Add New > Search
2. Type Country Based Bank Accounts for WooCommerce in the search box and hit Enter
3. Click on the button Install and then activate the plugin

= Manual Installation =

The manual installation method involves downloading our plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

### Changelog
Please see `readme.txt` for changelog
