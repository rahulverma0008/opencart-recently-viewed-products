<?php
namespace Opencart\Catalog\Controller\Extension\RecentlyViewed\Module;

class RecentlyViewed extends \Opencart\System\Engine\Controller {

	private const COOKIE = 'recently_viewed';

	/** products kept in the cookie of a guest */
	private const COOKIE_LIMIT = 20;

	/**
	 * product id => unix time of the view, from the cookie of the visitor. Never trusts the cookie.
	 *
	 * The cookie is JSON. The OpenCart 3 versions stored base64(serialize(array of id => date)), such a cookie is still read
	 * (migration), but only as plain data: unserialize() never creates objects ('allowed_classes' => false).
	 *
	 * @return array<int, int> oldest view first (the order of the JSON object is the order of the views)
	 */
	private function readCookie(): array {
		// OpenCart HTML encodes all request values (a " in the cookie arrives as &quot;), JSON needs the original
		$raw = html_entity_decode((string)($this->request->cookie[self::COOKIE] ?? ''), ENT_QUOTES, 'UTF-8');

		if ($raw === '' || strlen($raw) > 4096) {
			return [];
		}

		$data = json_decode($raw, true);

		if (!is_array($data)) {
			$decoded = base64_decode($raw, true);

			// malformed input is normal here (anybody can send any cookie), it must not write notices into the error log
			set_error_handler(fn(): bool => true);

			try {
				$data = $decoded === false ? false : unserialize($decoded, ['allowed_classes' => false]);
			} finally {
				restore_error_handler();
			}

			if (!is_array($data)) {
				return [];
			}

			// the old format has dates as values, oldest first
			foreach ($data as $product_id => $viewed_on) {
				$data[$product_id] = is_string($viewed_on) ? (int)strtotime($viewed_on) : 0;
			}

			asort($data);
		}

		$views = [];

		foreach ($data as $product_id => $viewed_on) {
			if ((is_int($product_id) || ctype_digit((string)$product_id)) && (int)$product_id > 0 && is_numeric($viewed_on)) {
				$views[(int)$product_id] = (int)$viewed_on;
			}
		}

		return array_slice($views, -self::COOKIE_LIMIT, null, true);
	}

	/**
	 * @param array<int, int> $views
	 */
	private function writeCookie(array $views): void {
		setcookie(self::COOKIE, $views ? json_encode($views) : '', [
			// a session cookie, like before
			'expires'  => $views ? 0 : time() - 3600,
			'path'     => '/',
			'secure'   => !empty($this->request->server['HTTPS']),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}

	/**
	 * The history of the guest becomes the history of the customer (after the login). It has to happen before the next view is
	 * recorded, otherwise the old views of the guest would look newer than the view of this page.
	 */
	private function mergeCookie(): void {
		if (empty($this->request->cookie[self::COOKIE])) {
			return;
		}

		$this->load->model('extension/recently_viewed/module/recently_viewed');

		foreach ($this->readCookie() as $product_id => $viewed_on) {
			$this->model_extension_recently_viewed_module_recently_viewed->setRecentlyViewedProduct($this->customer->getId(), $product_id, $viewed_on);
		}

		unset($this->request->cookie[self::COOKIE]);

		$this->writeCookie([]);
	}

	/**
	 * Event catalog/controller/product/product/before: remember the product the visitor is looking at.
	 * The event is registered when the module is installed and removed when it is uninstalled.
	 *
	 * @param string       $route
	 * @param array<mixed> $args
	 *
	 * @return void
	 */
	public function track(&$route, &$args): void {
		$product_id = (int)($this->request->get['product_id'] ?? 0);

		if ($product_id < 1) {
			return;
		}

		// only products which exist (and are visible in this store)
		$this->load->model('catalog/product');

		if (!$this->model_catalog_product->getProduct($product_id)) {
			return;
		}

		if ($this->customer->isLogged()) {
			$this->mergeCookie();

			$this->load->model('extension/recently_viewed/module/recently_viewed');

			$this->model_extension_recently_viewed_module_recently_viewed->setRecentlyViewedProduct($this->customer->getId(), $product_id);
		} else {
			$views = $this->readCookie();

			// viewed again: moves to the end (the newest)
			unset($views[$product_id]);

			$views[$product_id] = time();

			$this->writeCookie(array_slice($views, -self::COOKIE_LIMIT, null, true));
		}
	}

	/**
	 * The module in a layout
	 *
	 * @param array<string, mixed> $setting
	 */
	public function index(array $setting): string {
		$this->load->language('extension/recently_viewed/module/recently_viewed');

		$this->load->model('catalog/product');
		$this->load->model('tool/image');

		$limit = (int)($setting['limit'] ?? 0);

		if ($limit < 1) {
			$limit = 4;
		}

		$limit = min($limit, 50);

		$width = (int)($setting['width'] ?? 0) ?: 200;
		$height = (int)($setting['height'] ?? 0) ?: 200;

		$data['heading_title'] = $this->language->get('heading_title');

		// title of the current language, set by the admin
		$title = trim((string)($setting['title'][$this->config->get('config_language_id')] ?? ''));

		if ($title !== '') {
			$data['heading_title'] = $title;
		}

		// the product page itself is not "recently viewed"
		$current = (int)($this->request->get['product_id'] ?? 0);

		if ($this->customer->isLogged()) {
			$this->load->model('extension/recently_viewed/module/recently_viewed');

			$this->mergeCookie();

			$product_ids = $this->model_extension_recently_viewed_module_recently_viewed->getRecentlyViewedProducts($this->customer->getId(), $limit + 5, $current);
		} else {
			$views = $this->readCookie();

			unset($views[$current]);

			$product_ids = array_reverse(array_keys($views));
		}

		$data['products'] = [];

		foreach ($product_ids as $product_id) {
			// deleted / disabled products are skipped, they do not use a place of the limit
			$product = $this->model_catalog_product->getProduct($product_id);

			if (!$product) {
				continue;
			}

			if ($product['image']) {
				$image = $this->model_tool_image->resize(html_entity_decode($product['image'], ENT_QUOTES, 'UTF-8'), $width, $height);
			} else {
				$image = $this->model_tool_image->resize('placeholder.png', $width, $height);
			}

			if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
				$price = $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
			} else {
				$price = false;
			}

			if ((float)$product['special']) {
				$special = $this->currency->format($this->tax->calculate($product['special'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
			} else {
				$special = false;
			}

			if ($this->config->get('config_tax')) {
				$tax = $this->currency->format((float)$product['special'] ? $product['special'] : $product['price'], $this->session->data['currency']);
			} else {
				$tax = false;
			}

			$product_data = [
				'product_id'  => $product['product_id'],
				'thumb'       => $image,
				'name'        => $product['name'],
				'description' => mb_substr(trim(strip_tags(html_entity_decode($product['description'], ENT_QUOTES, 'UTF-8'))), 0, (int)$this->config->get('config_product_description_length') ?: 100, 'UTF-8') . '..',
				'price'       => $price,
				'special'     => $special,
				'tax'         => $tax,
				'minimum'     => $product['minimum'] > 0 ? $product['minimum'] : 1,
				'rating'      => $product['rating'],
				'href'        => $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id'])
			];

			$data['products'][] = $this->load->controller('product/thumb', $product_data);

			if (count($data['products']) >= $limit) {
				break;
			}
		}

		if (!$data['products']) {
			return '';
		}

		return $this->load->view('extension/recently_viewed/module/recently_viewed', $data);
	}
}
