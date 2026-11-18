# Recently Viewed Products

Shows customers the products they looked at before, in any layout (product page, category page ...).

**For OpenCart 4.0.0.0 - 4.1.0.4** (one package). For OpenCart 3.x / 2.x use the other folders.

## How To Install
1) In your OpenCart admin panel go to Extensions > Installer
2) Upload `recently_viewed.ocmod.zip` and click the green install button
3) Go to Extensions > Extensions, choose Modules from the extension type
4) Click the Install button of Recently Viewed, then the edit button: create a module (title, number of products, image size)
5) Design > Layouts: add the module to the layouts where it should be shown (e.g. Product, position content bottom)

* Logged in customers: the history is kept in the database (table `customer_product_viewed`), they see it in any browser.
* Guests: the history is kept in a cookie (the last 20 products, until the browser is closed). It moves into the account at login.
* The product page does not list the product itself.

The viewed product is remembered by an OpenCart event (catalog/controller/product/product/before): no OCMOD, no file of OpenCart is changed.

## Features
1. Easy to install and setup
2. Works for logged in customers and for guests
3. The database keeps the history of logged in customers, so it follows them to any browser
4. Cookies keep the history of guests
5. Product cards of your theme (OpenCart product/thumb), Bootstrap 5

## IMPORTANT: security fix
The OpenCart 2.x and 3.x versions read the cookie with `unserialize(base64_decode($cookie))`. A cookie is controlled by the visitor, this is a
PHP object injection vulnerability. **Update the stores which still run the older versions.** The OpenCart 4 version stores JSON, validates every
value, and reads the old cookie format only as plain data (`unserialize` with `allowed_classes => false`), so visitors keep their history.

## What is new in 2.0.0 (compared with the OpenCart 3 version)
* Security: no unserialize() of the cookie (see above); the cookie is HttpOnly and SameSite, limited to 20 products and 4 KB.
* Installing the module again no longer drops the history table (it deleted the history of all customers). The table is kept on uninstall too.
* Views are ordered correctly: the old sort callback returned true/false instead of a number, which PHP 8 sorts unpredictably.
* A product which was deleted or disabled is skipped (it caused PHP errors) and does not use a place of the limit.
* The history of a customer is trimmed to the last 50 products; the limit is validated (1 - 50) and cast in the SQL.
* Only products which exist are remembered.
* Module settings are validated and saved with the OpenCart 4 AJAX form.

Upgrading a store from OpenCart 3: the history table has the same structure and is used as it is. Module instances (name, title, size) have to be created
again and added to the layouts (OpenCart 4 uses another module code).

## Testing
`_oc-test-harness/run.sh opencart-recently-viewed-products/Opencart_V4.x --oc <version> --fresh` (from the parent folder) installs the zip in a real OpenCart
and checks the admin, guests (cookie order, limit, cap, disabled products, hostile and old cookies), logged in customers (merge, ordering, trimming),
and that uninstall / install keep the history.
