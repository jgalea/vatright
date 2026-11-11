<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,Generic.Files.OneObjectStructurePerFile,Generic.CodeAnalysis.UnusedFunctionParameter

if ( ! class_exists( 'WC_Order_Item', false ) ) {
	class WC_Order_Item {
		public $id;
		public $meta  = array();
		public $taxes = array( 'total' => array() );
		public $total = 0;

		public function get_id() {
			return $this->id;
		}

		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function get_taxes() {
			return $this->taxes;
		}

		public function get_total() {
			return $this->total;
		}
	}

	class WC_Order_Item_Product extends WC_Order_Item {}

	class WC_Order_Item_Shipping extends WC_Order_Item {}

	class WC_Order_Item_Fee extends WC_Order_Item {}

	abstract class WC_Abstract_Order {
		public $items = array();

		public function get_items() {
			return $this->items;
		}

		public function get_item( $id ) {
			return $this->items[ $id ] ?? false;
		}

		public $order_meta = array();

		public function get_id() {
			return 7;
		}

		public function get_meta( $key ) {
			return $this->order_meta[ $key ] ?? '';
		}

		public function meta_exists( $key ) {
			return isset( $this->order_meta[ $key ] );
		}

		public function get_taxes() {
			return array();
		}
	}

	class WC_Order extends WC_Abstract_Order {}

	class WC_Order_Refund extends WC_Abstract_Order {}
}
