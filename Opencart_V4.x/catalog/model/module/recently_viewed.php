<?php
namespace Opencart\Catalog\Model\Extension\RecentlyViewed\Module;

class RecentlyViewed extends \Opencart\System\Engine\Model {

	/** viewed products kept for every customer */
	private const KEEP = 50;

	/**
	 * Product ids a customer has viewed, the most recent first
	 *
	 * @return array<int, int>
	 */
	public function getRecentlyViewedProducts(int $customer_id, int $limit, int $exclude_product_id = 0): array {
		$query = $this->db->query("SELECT `product_id` FROM `" . DB_PREFIX . "customer_product_viewed` WHERE `customer_id` = '" . (int)$customer_id . "' AND `product_id` <> '" . (int)$exclude_product_id . "' ORDER BY `viewed_on` DESC, `id` DESC LIMIT " . max(1, $limit));

		return array_map('intval', array_column($query->rows, 'product_id'));
	}

	/**
	 * @param int $viewed_on unix time of the view, 0 for now
	 */
	public function setRecentlyViewedProduct(int $customer_id, int $product_id, int $viewed_on = 0): void {
		$time = $viewed_on > 0 ? "FROM_UNIXTIME('" . (int)$viewed_on . "')" : 'NOW()';

		// viewed again: the old row goes, the new one is the newest (also by id, views in the same second keep their order)
		$this->db->query("DELETE FROM `" . DB_PREFIX . "customer_product_viewed` WHERE `customer_id` = '" . (int)$customer_id . "' AND `product_id` = '" . (int)$product_id . "'");

		$this->db->query("INSERT INTO `" . DB_PREFIX . "customer_product_viewed` SET `customer_id` = '" . (int)$customer_id . "', `product_id` = '" . (int)$product_id . "', `viewed_on` = " . $time);

		// the history of a customer does not grow forever
		$old = $this->db->query("SELECT `id` FROM `" . DB_PREFIX . "customer_product_viewed` WHERE `customer_id` = '" . (int)$customer_id . "' ORDER BY `viewed_on` DESC, `id` DESC LIMIT " . self::KEEP . ", 1000");

		if ($old->num_rows) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "customer_product_viewed` WHERE `id` IN (" . implode(',', array_map('intval', array_column($old->rows, 'id'))) . ")");
		}
	}
}
