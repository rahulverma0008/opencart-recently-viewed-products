<?php
require __DIR__ . '/../../_oc-test-harness/lib.php';

$db->exec("SET SESSION sql_mode = ''");

$ROUTE = 'extension/recently_viewed/module/recently_viewed';
function save_module(Client $a, string $t, array $s, int $id = 0): array { global $ROUTE; return $a->post('/admin/index.php?route=' . $ROUTE . '.save&user_token=' . $t . ($id ? '&module_id=' . $id : ''), $s); }
function mod(array $o = []): array { return $o + ['name' => 'Recently Viewed', 'title' => [1 => 'You looked at'], 'limit' => '4', 'width' => '100', 'height' => '100', 'status' => 1]; }
function product(int $id, string $name, int $status = 1): void {
	sql("DELETE FROM oc_product WHERE product_id = ?", [$id]); sql("DELETE FROM oc_product_description WHERE product_id = ?", [$id]); sql("DELETE FROM oc_product_to_store WHERE product_id = ?", [$id]);
	insert_row('oc_product', ['product_id' => $id, 'master_id' => 0, 'model' => 'RV' . $id, 'quantity' => 5, 'stock_status_id' => 7, 'shipping' => 0, 'price' => 10 + $id, 'tax_class_id' => 0, 'manufacturer_id' => 0, 'date_available' => '2020-01-01', 'status' => $status, 'minimum' => 1, 'subtract' => 0, 'date_added' => date('Y-m-d H:i:s'), 'date_modified' => date('Y-m-d H:i:s')]);
	insert_row('oc_product_description', ['product_id' => $id, 'language_id' => 1, 'name' => $name, 'description' => 'Description of ' . $name, 'tag' => '', 'meta_title' => $name, 'meta_description' => '', 'meta_keyword' => '']);
	insert_row('oc_product_to_store', ['product_id' => $id, 'store_id' => 0]);
}
function view(Client $c, int $id, array $headers = []): array { return $c->get('/index.php?route=product/product&product_id=' . $id . '&language=en-gb', $headers); }
/** names of the recently viewed module on a page, in page order (only inside the block of the module, the product page has the name of the current product too) */
function listed(string $html): array {
	$dom = new DOMDocument();
	@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
	$xpath = new DOMXPath($dom);
	$block = $xpath->query('//h3[normalize-space()="You looked at" or normalize-space()="Recently Viewed"]/following-sibling::div[contains(@class, "row")][1]');

	if (!$block->length) {
		return [];
	}

	preg_match_all('#RV [A-Za-z0-9]+#', $dom->saveHTML($block->item(0)), $names);

	return array_values(array_unique($names[0]));
}
function cookie_value(Client $c, string $name): ?string {
	foreach (file($c->jar, FILE_IGNORE_NEW_LINES) as $line) {
		$f = explode("\t", preg_replace('/^#HttpOnly_/', '', $line));
		if (count($f) >= 7 && $f[5] == $name) { return urldecode($f[6]); }
	}
	return null;
}

echo "Recently Viewed end to end test (OpenCart $OC_VERSION)\n";
[$admin, $token] = admin_login();

if (wanted('install')) {
	section('1. Install');
	install_package($admin, $token);
	check('history table exists', (bool)sql("SHOW TABLES LIKE 'oc_customer_product_viewed'"));
	check('event which remembers the viewed product is registered', (int)sql_value("SELECT COUNT(*) FROM oc_event WHERE code = 'recently_viewed' AND `trigger` = 'catalog/controller/product/product/before' AND status = 1") == 1);
	$src = '';
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($OC_DIR . '/extension/recently_viewed', FilesystemIterator::SKIP_DOTS)) as $f) { if (substr($f, -4) == '.php') { $src .= file_get_contents($f); } }
	preg_match_all('/unserialize\(\s*\$/', $src, $calls);
	check('source: unserialize() is only used without objects (allowed_classes => false)', count($calls[0]) >= 1 && count($calls[0]) == substr_count($src, "unserialize(\$decoded, ['allowed_classes' => false])"), 'calls ' . count($calls[0]));
}

if (wanted('admin')) {
	section('2. Admin: module settings');
	$p = $admin->get('/admin/index.php?route=' . $ROUTE . '&user_token=' . $token);
	check('new module page renders', $p['status'] == 200 && strpos($p['body'], 'id="form-recently-viewed"') !== false, short($p['body']));

	$r = save_module($admin, $token, mod(['name' => 'ab']));
	check('module name must have 3-64 characters', isset($r['json']['error']['name']), short($r['body']));
	foreach (['0', '51', 'abc', ''] as $v) { $r = save_module($admin, $token, mod(['limit' => $v])); check("limit '$v' is refused", isset($r['json']['error']['limit']), short($r['body'])); }
	$r = save_module($admin, $token, mod(['width' => '', 'height' => 'x']));
	check('width and height are required numbers', isset($r['json']['error']['width'], $r['json']['error']['height']), short($r['body']));
	$r = save_module($admin, $token, mod(['title' => [1 => 'ab']]));
	check('a too short title is refused, an empty one is fine', isset($r['json']['error']['title_1']) && empty(save_module($admin, $token, mod(['title' => [1 => '']]))['json']['error']), short($r['body']));
	sql("DELETE FROM oc_module WHERE code LIKE 'recently_viewed.%'");

	$r = save_module($admin, $token, mod());
	$module_id = (int)($r['json']['module_id'] ?? 0);
	check('a new module is created and its id returned', !empty($r['json']['success']) && $module_id > 0 && sql_value("SELECT code FROM oc_module WHERE module_id = ?", [$module_id]) === 'recently_viewed.recently_viewed', short($r['body']));
	$stored = json_decode((string)sql_value("SELECT setting FROM oc_module WHERE module_id = ?", [$module_id]), true);
	check('module settings are stored (same keys as the OpenCart 3 version)', ($stored['limit'] ?? 0) === 4 && $stored['title'][1] === 'You looked at' && $stored['width'] === 100, short($stored));
	$r = save_module($admin, $token, mod(['name' => 'Renamed module', 'limit' => '2']), $module_id);
	check('saving with the module id changes it, no second module', !empty($r['json']['success']) && (int)sql_value("SELECT COUNT(*) FROM oc_module WHERE code = 'recently_viewed.recently_viewed'") == 1 && sql_value("SELECT name FROM oc_module WHERE module_id = ?", [$module_id]) === 'Renamed module');
	$p = $admin->get('/admin/index.php?route=' . $ROUTE . '&user_token=' . $token . '&module_id=' . $module_id);
	check('edit page shows the saved values', strpos($p['body'], 'value="Renamed module"') !== false && strpos($p['body'], 'value="You looked at"') !== false);

	sql("DELETE FROM oc_user WHERE username = 'viewer'");
	sql("INSERT INTO oc_user_group SET name = 'Viewer', permission = ?", [json_encode(['access' => ['extension/recently_viewed/module/recently_viewed'], 'modify' => []])]);
	insert_row('oc_user', ['user_group_id' => (int)sql_value("SELECT MAX(user_group_id) FROM oc_user_group"), 'username' => 'viewer', 'password' => password_hash('viewer123', PASSWORD_DEFAULT), 'firstname' => 'V', 'lastname' => 'O', 'email' => 'v@example.com', 'image' => '', 'ip' => '', 'status' => 1, 'date_added' => date('Y-m-d H:i:s')]);
	[$viewer, $vt] = admin_login('viewer', 'viewer123');
	$r = save_module($viewer, $vt, mod(['name' => 'Hacked name']), $module_id);
	check('saving without modify permission is refused', !empty($r['json']['error']['warning']) && sql_value("SELECT name FROM oc_module WHERE module_id = ?", [$module_id]) === 'Renamed module', short($r['body']));
	save_module($admin, $token, mod(), $module_id); // limit 4 again
}

if (wanted('guest') || wanted('customer')) {
	// products and the module in the layout of the product page
	foreach ([['Alpha', 121], ['Beta', 122], ['Gamma', 123], ['Delta', 124], ['Epsilon', 125], ['Zeta', 126]] as [$n, $id]) { product($id, 'RV ' . $n); }
	product(130, 'RV Hidden', 0);
	$module_id = (int)sql_value("SELECT MIN(module_id) FROM oc_module WHERE code = 'recently_viewed.recently_viewed'");
	if (!$module_id) { $module_id = (int)(save_module($admin, $token, mod())['json']['module_id'] ?? 0); }
	save_module($admin, $token, mod(), $module_id);
	$layout = (int)sql_value("SELECT layout_id FROM oc_layout_route WHERE route = 'product/product' LIMIT 1") ?: 2;
	sql("DELETE FROM oc_layout_module WHERE code LIKE 'recently_viewed.%'");
	insert_row('oc_layout_module', ['layout_id' => $layout, 'code' => 'recently_viewed.recently_viewed.' . $module_id, 'position' => 'content_bottom', 'sort_order' => 1]);
	foreach (glob($OC_DIR . '/system/storage/cache/*') as $f) { @unlink($f); }
}

if (wanted('guest')) {
	section('3. Guests (cookie)');
	$g = new_shopper();
	foreach ([121, 122, 123] as $id) { view($g, $id); }
	$page = view($g, 124);
	check('product page shows the previously viewed products, newest first, not the current one', listed($page['body']) == ['RV Gamma', 'RV Beta', 'RV Alpha'], short(listed($page['body'])));
	check('module heading is the title set by the admin', strpos($page['body'], 'You looked at') !== false);
	$cookie = json_decode((string)cookie_value($g, 'recently_viewed'), true);
	check('cookie is JSON with the viewed product ids (oldest first)', is_array($cookie) && array_keys($cookie) == [121, 122, 123, 124], short($cookie));
	check('cookie is HttpOnly', (bool)preg_grep('/^#HttpOnly_.*recently_viewed/', file($g->jar, FILE_IGNORE_NEW_LINES)));

	view($g, 121);
	$page = view($g, 126);
	check('a product viewed again moves to the front (and is listed once)', listed($page['body']) == ['RV Alpha', 'RV Delta', 'RV Gamma', 'RV Beta'], short(listed($page['body'])));

	save_module($admin, $token, mod(['limit' => '2']), $module_id);
	$page = view($g, 125);
	check('limit of the module is respected', count(listed($page['body'])) == 2, short(listed($page['body'])));
	save_module($admin, $token, mod(), $module_id);

	$before = cookie_value($g, 'recently_viewed');
	view($g, 130); view($g, 999999); $c1 = $g->get('/index.php?route=product/product&language=en-gb');
	check('disabled / unknown products are not remembered, no server error', cookie_value($g, 'recently_viewed') === $before && $c1['status'] < 500, 'status ' . $c1['status']);

	sql("UPDATE oc_product SET status = 0 WHERE product_id = 121");
	$page = view($g, 126);
	check('a product which was disabled meanwhile is skipped and does not use a place of the limit', !in_array('RV Alpha', listed($page['body'])) && count(listed($page['body'])) == 4, short(listed($page['body'])));
	sql("UPDATE oc_product SET status = 1 WHERE product_id = 121");

	// the cookie is capped
	$many = new_shopper();
	for ($i = 0; $i < 25; $i++) { product(200 + $i, 'RV Bulk' . $i); view($many, 200 + $i); }
	$cookie = json_decode((string)cookie_value($many, 'recently_viewed'), true);
	check('cookie keeps the last 20 products', is_array($cookie) && count($cookie) == 20 && array_key_last($cookie) == 224 && array_key_first($cookie) == 205, short(array_keys($cookie ?: [])));

	// hostile cookies
	section('3b. Guests: hostile / old cookies');
	$legacy = base64_encode(serialize([121 => '2020-01-01 10:00:00', 122 => '2020-01-01 11:00:00']));
	$h = new_shopper();
	$page = view($h, 126, ['Cookie: recently_viewed=' . urlencode($legacy)]);
	check('cookie of the OpenCart 3 version (serialized) is still understood: migration', listed($page['body']) == ['RV Beta', 'RV Alpha'], short(listed($page['body'])));
	$after = json_decode((string)cookie_value($h, 'recently_viewed'), true);
	check('...and rewritten as JSON', is_array($after) && array_keys($after) == [121, 122, 126], short($after));

	@mkdir($OC_DIR . '/system/storage/logs', 0777, true);
	$marker = $OC_DIR . '/system/storage/logs/object-injection-marker.txt';
	@unlink($marker);
	$object = base64_encode('O:8:"stdClass":1:{s:1:"a";s:5:"pwned";}' );
	$gadget = base64_encode('O:29:"Opencart\System\Library\Log":1:{s:8:"filename";s:30:"object-injection-marker.txt";}');
	foreach (['object' => $object, 'log object' => $gadget, 'garbage' => 'not a cookie !!', 'json scalar' => '123', 'sql in keys' => json_encode(['1 OR 1=1' => 5, '-3' => 5, '0' => 5, 'abc' => 5, '121' => 'x']), 'huge' => str_repeat('A', 9000), 'nested' => json_encode([121 => [1, 2], 122 => time()])] as $name => $value) {
		$x = new_shopper();
		$page = view($x, 126, ['Cookie: recently_viewed=' . urlencode($value)]);
		check("hostile cookie ($name): page works, nothing bogus listed", $page['status'] == 200 && !array_diff(listed($page['body']), ['RV Beta']), short(listed($page['body'])) . ' status ' . $page['status']);
	}
	check('hostile cookie: no object was created (nothing written by an unserialized Log object)', !is_file($marker));
}

if (wanted('customer')) {
	section('4. Logged in customers (database)');
	sql("DELETE FROM oc_customer_product_viewed");
	$email = 'rv.customer.' . time() . '@example.com';
	insert_row('oc_customer', ['customer_group_id' => 1, 'store_id' => 0, 'language_id' => 1, 'firstname' => 'Rita', 'lastname' => 'Viewer', 'email' => $email, 'telephone' => '1', 'password' => password_hash('secret123', PASSWORD_DEFAULT), 'custom_field' => '', 'newsletter' => 0, 'ip' => '127.0.0.1', 'status' => 1, 'safe' => 0, 'token' => '', 'code' => '', 'date_added' => date('Y-m-d H:i:s')]);
	$customer_id = (int)sql_value('SELECT customer_id FROM oc_customer WHERE email = ?', [$email]);

	$c = new_shopper();
	view($c, 121); view($c, 122); // as a guest first
	$page = $c->get('/index.php?route=account/login&language=en-gb');
	preg_match('/login_token=([a-zA-Z0-9]+)/', $page['body'], $m);
	$login = $c->post('/index.php?route=account/login.login&language=en-gb&login_token=' . ($m[1] ?? ''), ['email' => $email, 'password' => 'secret123']);
	check('setup: customer is logged in', !empty($login['json']['redirect']), short($login['body']));

	view($c, 123);
	$page = view($c, 124);
	check('guest history is merged into the account, newest first, current product not listed', listed($page['body']) == ['RV Gamma', 'RV Beta', 'RV Alpha'], short(listed($page['body'])));
	check('the cookie is deleted after the merge', cookie_value($c, 'recently_viewed') === null || cookie_value($c, 'recently_viewed') === '');
	check('rows are in the database', (int)sql_value('SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = ?', [$customer_id]) == 4);

	view($c, 121); view($c, 121);
	check('viewing a product again moves it, no duplicate rows', (int)sql_value('SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = ? AND product_id = 121', [$customer_id]) == 1);
	$page = view($c, 126);
	check('...and it is the first one in the list', listed($page['body'])[0] === 'RV Alpha', short(listed($page['body'])));

	$other = new_shopper();
	check('another visitor does not see the history of the customer', !listed(view($other, 126)['body']) || listed(view($other, 126)['body']) == []);

	// history is capped per customer
	for ($i = 0; $i < 60; $i++) { sql("INSERT INTO oc_customer_product_viewed SET customer_id = ?, product_id = ?, viewed_on = ?", [$customer_id, 5000 + $i, gmdate('Y-m-d H:i:s', time() - 60 * (100 + $i))]); } // dates in UTC like the database session of OpenCart
	view($c, 125);
	check('history of a customer is trimmed to the last 50', (int)sql_value('SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = ?', [$customer_id]) == 50);
	check('...and the newest ones are the ones which stay', (int)sql_value('SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = ? AND product_id = 125', [$customer_id]) == 1);

	sql("UPDATE oc_product SET status = 0 WHERE product_id = 122");
	$page = view($c, 126);
	check('disabled product is skipped for customers too', !in_array('RV Beta', listed($page['body'])) && $page['status'] == 200);
	sql("UPDATE oc_product SET status = 1 WHERE product_id = 122");
}

if (wanted('uninstall')) {
	section('5. Uninstall keeps the history');
	sql("DELETE FROM oc_customer_product_viewed"); insert_row('oc_customer_product_viewed', ['customer_id' => 77, 'product_id' => 121, 'viewed_on' => date('Y-m-d H:i:s')]);
	$u = $admin->get('/admin/index.php?route=extension/module.uninstall&user_token=' . $token . '&extension=' . $CODE . '&code=' . $NAME);
	check('uninstall succeeds', !empty($u['json']['success']), short($u['body']));
	check('uninstall removes the event', (int)sql_value("SELECT COUNT(*) FROM oc_event WHERE code = 'recently_viewed'") == 0);
	check('uninstall keeps the history table and its rows', (int)sql_value("SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = 77") == 1);
	$p = view(new_shopper(), 121);
	check('product pages still work after uninstall', $p['status'] == 200);
	$in = $admin->get('/admin/index.php?route=extension/module.install&user_token=' . $token . '&extension=' . $CODE . '&code=' . $NAME);
	check('installing again succeeds', !empty($in['json']['success']), short($in['body']));
	check('...without dropping the history (the OpenCart 3 version dropped it)', (int)sql_value("SELECT COUNT(*) FROM oc_customer_product_viewed WHERE customer_id = 77") == 1);
	check('...and the event is back', (int)sql_value("SELECT COUNT(*) FROM oc_event WHERE code = 'recently_viewed' AND status = 1") == 1);
}

finish();
