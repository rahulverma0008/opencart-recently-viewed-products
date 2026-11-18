<?php
namespace Opencart\Admin\Controller\Extension\RecentlyViewed\Module;

class RecentlyViewed extends \Opencart\System\Engine\Controller {

	private const EVENT_CODE = 'recently_viewed';

	/**
	 * Separator between the class and the method in a route: OpenCart 4.0.2.0 changed it from | to .
	 */
	private function separator(): string {
		return version_compare(VERSION, '4.0.2.0', '>=') ? '.' : '|';
	}

	private function getAction(): string {
		return 'extension/recently_viewed/module/recently_viewed' . $this->separator() . 'track';
	}

	/**
	 * The history of the customers. The table is created if it is missing and NEVER dropped: the OpenCart 3 version dropped it
	 * when the module was installed again, and with it the history of all customers. The structure is the one of the OpenCart 3 version.
	 */
	private function createTable(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "customer_product_viewed` (
			`id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`customer_id` int COMMENT 'customer_id from `" . DB_PREFIX . "customer`',
			`product_id` int COMMENT 'product_id from `" . DB_PREFIX . "product`',
			`viewed_on` datetime
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		// the history is read by customer
		if (!$this->db->query("SHOW INDEX FROM `" . DB_PREFIX . "customer_product_viewed` WHERE `Key_name` = 'customer_viewed'")->num_rows) {
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "customer_product_viewed` ADD INDEX `customer_viewed` (`customer_id`, `viewed_on`)");
		}
	}

	/**
	 * Register the event which remembers the viewed product. Plain SQL because the addEvent() method of the
	 * setting/event model differs between OpenCart versions. Safe to call more than once.
	 */
	private function addEvent(): void {
		$this->deleteEvent();

		$this->db->query("INSERT INTO `" . DB_PREFIX . "event` SET `code` = '" . self::EVENT_CODE . "', `description` = 'Recently Viewed: remember the viewed product', `trigger` = 'catalog/controller/product/product/before', `action` = '" . $this->db->escape($this->getAction()) . "', `status` = '1', `sort_order` = '0'");
	}

	private function deleteEvent(): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "'");
	}

	public function install(): void {
		$this->createTable();
		$this->addEvent();
	}

	public function uninstall(): void {
		// the history stays, see createTable()
		$this->deleteEvent();
	}

	public function index(): void {
		$this->load->language('extension/recently_viewed/module/recently_viewed');

		$this->document->setTitle($this->language->get('heading_title'));

		// the event is missing when the files have been replaced without reinstalling the extension
		if (!$this->db->query("SELECT `event_id` FROM `" . DB_PREFIX . "event` WHERE `code` = '" . self::EVENT_CODE . "' AND `action` = '" . $this->db->escape($this->getAction()) . "'")->num_rows) {
			$this->addEvent();
		}

		$module_id = (int)($this->request->get['module_id'] ?? 0);

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/recently_viewed/module/recently_viewed', 'user_token=' . $this->session->data['user_token'] . ($module_id ? '&module_id=' . $module_id : ''))
		];

		$data['save'] = $this->url->link('extension/recently_viewed/module/recently_viewed' . $this->separator() . 'save', 'user_token=' . $this->session->data['user_token'] . ($module_id ? '&module_id=' . $module_id : ''));
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$module_info = [];

		if ($module_id) {
			$this->load->model('setting/module');

			$module_info = $this->model_setting_module->getModule($module_id);
		}

		$data['module_id'] = $module_id;
		$data['name'] = $module_info['name'] ?? '';
		$data['title'] = is_array($module_info['title'] ?? null) ? $module_info['title'] : [];
		$data['limit'] = $module_info['limit'] ?? 4;
		$data['width'] = $module_info['width'] ?? 200;
		$data['height'] = $module_info['height'] ?? 200;
		$data['status'] = (int)($module_info['status'] ?? 1);

		$this->load->model('localisation/language');

		$data['languages'] = $this->model_localisation_language->getLanguages();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/recently_viewed/module/recently_viewed', $data));
	}

	public function save(): void {
		$this->load->language('extension/recently_viewed/module/recently_viewed');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/recently_viewed/module/recently_viewed')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;

		$name = trim((string)($post['name'] ?? ''));

		if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 64) {
			$json['error']['name'] = $this->language->get('error_name');
		}

		$this->load->model('localisation/language');

		$title = [];

		foreach ($this->model_localisation_language->getLanguages() as $language) {
			$value = trim((string)($post['title'][$language['language_id']] ?? ''));

			// a title is optional, the language file has a default
			if ($value !== '' && (mb_strlen($value, 'UTF-8') < 3 || mb_strlen($value, 'UTF-8') > 64)) {
				$json['error']['title_' . $language['language_id']] = $this->language->get('error_title');
			}

			$title[$language['language_id']] = $value;
		}

		$limit = trim((string)($post['limit'] ?? ''));

		if (!ctype_digit($limit) || (int)$limit < 1 || (int)$limit > 50) {
			$json['error']['limit'] = $this->language->get('error_limit');
		}

		foreach (['width', 'height'] as $key) {
			$value = trim((string)($post[$key] ?? ''));

			if (!ctype_digit($value) || (int)$value < 1 || (int)$value > 2000) {
				$json['error'][$key] = $this->language->get('error_' . $key);
			}
		}

		if (isset($json['error']) && !isset($json['error']['warning'])) {
			$json['error']['warning'] = $this->language->get('error_warning');
		}

		if (!$json) {
			$settings = [
				'name'   => $name,
				'title'  => $title,
				'limit'  => (int)$limit,
				'width'  => (int)$post['width'],
				'height' => (int)$post['height'],
				'status' => (int)!empty($post['status'])
			];

			$this->load->model('setting/module');

			$module_id = (int)($this->request->get['module_id'] ?? 0);

			if (!$module_id) {
				// OpenCart 4.0.0.0 returns nothing from addModule(), the id is the last insert id then
				$module_id = (int)$this->model_setting_module->addModule('recently_viewed.recently_viewed', $settings) ?: (int)$this->db->getLastId();
			} else {
				$this->model_setting_module->editModule($module_id, $settings);
			}

			$json['success'] = $this->language->get('text_success');
			$json['module_id'] = $module_id;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
