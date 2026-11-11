<?php

namespace Vatright\Tests\Support;

use WC_Order;

class SubscriptionStandIn extends WC_Order {

	public function get_type() {
		return 'shop_subscription';
	}
}
