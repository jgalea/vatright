<?php

namespace Vatright\Tests\Integration\Privacy;

require_once __DIR__ . '/RetentionTest.php';

class RetentionPostsStorageTest extends RetentionTest {

	public function set_up() {
		parent::set_up();
		add_filter(
			'pre_option_woocommerce_custom_orders_table_enabled',
			static function () {
				return 'no';
			},
			20
		);
	}
}
