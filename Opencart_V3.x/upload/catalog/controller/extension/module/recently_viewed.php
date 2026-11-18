<?php
class ControllerExtensionModuleRecentlyViewed extends Controller {
	/**
	 * Recently viewed products of the visitor from the cookie (product_id => date).
	 *
	 * The cookie is controlled by the visitor, it must never be unserialized blindly (PHP object injection). It is only
	 * unserialized when it has exactly the shape this module writes: integer keys and dates of 19 characters.
	 * Nothing else (no object, no other type) can pass this check, on any PHP version.
	 */
	private function getRecentlyViewedFromCookie() {
		$recently_viewed = array();

		if (isset($this->request->cookie['recently_viewed']) && !empty($this->request->cookie['recently_viewed'])) {
			$raw = base64_decode($this->request->cookie['recently_viewed'], true);

			if ($raw !== false && strlen($raw) < 8192 && preg_match('/^a:(\d+):\{(i:\d+;s:19:"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}";)*\}$/', $raw, $matches) && preg_match_all('/i:\d+;s:19:"/', $raw) === (int)$matches[1]) {
				$data = unserialize($raw);

				if (is_array($data)) {
					$recently_viewed = $data;
				}
			}
		}

		return $recently_viewed;
	}

	public function index($setting) {
		static $module = 0;
		
		$this->load->language('extension/module/recently_viewed');

		$data['heading_title'] = $this->language->get('heading_title');

		$data['text_tax'] = $this->language->get('text_tax');

		$data['button_cart'] = $this->language->get('button_cart');
		$data['button_wishlist'] = $this->language->get('button_wishlist');
		$data['button_compare'] = $this->language->get('button_compare');

		$this->load->model('catalog/product');

		$this->load->model('tool/image');
		
		$current_product_id = false;
		if(isset($this->request->get['product_id'])) {
			$current_product_id = (int)$this->request->get['product_id'];
		}

		// default limit is 4
		if (!$setting['limit']) {
			$setting['limit'] = 4;
		}

		// load language specific title if added by admin
		if(isset($setting['title'][$this->config->get('config_language_id')])) {
			$data['heading_title'] = $setting['title'][$this->config->get('config_language_id')];
		}

		$results  = array();
		$setting['products'] = array();
		if ($this->customer->isLogged()) {
			$this->load->model('extension/module/recently_viewed');
			
			/* if user is logged in then save all recently_viewed products to database if available in cookie and then clear the cookie */
			if(isset($this->request->cookie['recently_viewed']) && !empty($this->request->cookie['recently_viewed'])) {
				$recently_viewed = $this->getRecentlyViewedFromCookie();
				// sort by in recent viewed order
				uasort($recently_viewed, function($a, $b){ return strtotime($a) < strtotime($b); });
				foreach($recently_viewed as $k=>$v){
					$this->model_extension_module_recently_viewed->setRecentlyViewedProducts($this->customer->getId(), $k, $v);
				}
				unset($this->request->cookie['recently_viewed']);
				setcookie('recently_viewed', '', 0, '/', $this->request->server['HTTP_HOST']);
			}
			
			if($product_ids = $this->model_extension_module_recently_viewed->getRecentlyViewedProducts($this->customer->getId(), $setting['limit'], $current_product_id)){
				
				foreach($product_ids as $p){
					$results[] = $p['product_id'];
				}
			}
		} else if(isset($this->request->cookie['recently_viewed']) && !empty($this->request->cookie['recently_viewed'])) {
			$recently_viewed = $this->getRecentlyViewedFromCookie();
			// sort by in recent viewed order
			uasort($recently_viewed, function($a, $b){ return strtotime($a) < strtotime($b); });

			// if user is on product detail page then do not show current product in recently_viewed list
			if($current_product_id) {
				unset($recently_viewed[$current_product_id]);
			}
			
			$setting['products'] = array_keys($recently_viewed);
			
			if (!empty($setting['products'])) {
				$results = array_slice($setting['products'], 0, (int)$setting['limit']);
			}
		}
		
		
		$data['products'] = array();

		if ($results) {
			foreach ($results as $result) {
				
				$result = $this->model_catalog_product->getProduct($result);

				if (!$result) {
					continue;
				}

				if ($result['image']) {
					$image = $this->model_tool_image->resize($result['image'], $setting['width'], $setting['height']);
				} else {
					$image = $this->model_tool_image->resize('placeholder.png', $setting['width'], $setting['height']);
				}
				
				if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
					$price = $this->currency->format($this->tax->calculate($result['price'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				} else {
					$price = false;
				}

				if ((float)$result['special']) {
					$special = $this->currency->format($this->tax->calculate($result['special'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
				} else {
					$special = false;
				}

				if ($this->config->get('config_tax')) {
					$tax = $this->currency->format((float)$result['special'] ? $result['special'] : $result['price'], $this->session->data['currency']);
				} else {
					$tax = false;
				}

				if ($this->config->get('config_review_status')) {
					$rating = $result['rating'];
				} else {
					$rating = false;
				}

				$data['products'][] = array(
					'product_id'  => $result['product_id'],
					'thumb'       => $image,
					'name'        => $result['name'],
					'description' => utf8_substr(trim(strip_tags(html_entity_decode($result['description'], ENT_QUOTES, 'UTF-8'))), 0, $this->config->get('theme_' . $this->config->get('config_theme') . '_product_description_length')) . '..',
					'price'       => $price,
					'special'     => $special,
					'tax'         => $tax,
					'rating'      => $rating,
					'href'        => $this->url->link('product/product', 'product_id=' . $result['product_id'])
				);
			}

			return $this->load->view('extension/module/recently_viewed', $data);
		}
	}
}