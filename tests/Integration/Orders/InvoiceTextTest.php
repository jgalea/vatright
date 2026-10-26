<?php

namespace Vatright\Tests\Integration\Orders;

use Vatright\Orders\DecisionStore;
use Vatright\Orders\InvoiceText;
use Vatright\Settings;
use WC_Order;

require_once __DIR__ . '/LifecycleTestCase.php';

class InvoiceTextTest extends LifecycleTestCase {

	const ART196 = 'Reverse charge: VAT to be accounted for by the recipient, Article 196 Council Directive 2006/112/EC';
	const ART138 = 'Exempt intra-Community supply of goods, Article 138 Council Directive 2006/112/EC';

	private function exempt_order( string $supply = 'digital', string $status = 'valid' ): WC_Order {
		$order = $this->order( 'DE', 'DE', '10115', array( $supply ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( $supply ), false, self::NUMBER, $status ), 'checkout' );
		$order->calculate_totals();
		$order->save();
		return $this->fresh( $order );
	}

	/**
	 * @return string[]
	 */
	private function values( WC_Order $order ): array {
		return array_map(
			static function ( array $row ): string {
				return $row['label'] . ': ' . $row['value'];
			},
			$order->get_order_item_totals()
		);
	}

	public function test_reverse_charge_services_row_has_the_article_196_text_verbatim() {
		$rows = $this->values( $this->exempt_order() );

		$this->assertContains( 'Customer VAT number: ' . self::NUMBER, $rows );
		$this->assertContains( 'VAT note: ' . self::ART196, $rows );
		$this->assertContains( 'VAT number consultation reference: WAPIAAAA', $rows );
	}

	public function test_intra_eu_goods_row_has_the_article_138_text_verbatim() {
		$this->assertContains( 'VAT note: ' . self::ART138, $this->values( $this->exempt_order( 'goods' ) ) );
	}

	public function test_no_rows_on_a_charged_order() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( 'DE', 'DE', '10115', array( 'digital' ), false, self::NUMBER, 'invalid' ), 'checkout' );
		$order->save();

		$labels = array_keys( $this->fresh( $order )->get_order_item_totals() );

		$this->assertSame( array(), preg_grep( '/^vatright_/', $labels ) );
	}

	public function test_no_rows_on_an_order_without_a_decision() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );

		$this->assertSame( array(), preg_grep( '/^vatright_/', array_keys( $order->get_order_item_totals() ) ) );
	}

	public function test_consultation_reference_only_for_a_valid_registry_answer() {
		$order = $this->order( 'DE', 'DE', '10115', array( 'digital' ) );
		$order->update_meta_data( DecisionStore::NUMBER_META, self::NUMBER );
		DecisionStore::apply( $order, $this->pending_decision( 'exempt_pending' ), 'checkout' );
		$order->save();

		$rows = $this->values( $this->fresh( $order ) );

		$this->assertContains( 'VAT note: ' . self::ART196, $rows, 'Exempt while pending still needs the wording' );
		$this->assertSame( array(), preg_grep( '/consultation/', $rows ) );
	}

	/**
	 * @dataProvider email_templates
	 */
	public function test_item_totals_rows_render_in_customer_email( string $template, bool $plain ) {
		$order = $this->exempt_order();
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'];

		$out = wc_get_template_html(
			$template,
			array(
				'order'         => $order,
				'sent_to_admin' => false,
				'plain_text'    => $plain,
				'email'         => $email,
			)
		);

		$this->assertStringContainsString( self::ART196, $out );
		$this->assertStringContainsString( self::NUMBER, $out );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function email_templates(): array {
		return array(
			'html'  => array( 'emails/email-order-details.php', false ),
			'plain' => array( 'emails/plain/email-order-details.php', true ),
		);
	}

	public function test_wording_filterable_and_translated() {
		$order = $this->exempt_order();

		add_filter(
			'gettext',
			static function ( $translated, $text, $domain ) {
				return 'vatright' === $domain && 'Customer VAT number' === $text ? 'Umsatzsteuer-ID des Kunden' : $translated;
			},
			10,
			3
		);
		add_filter(
			'vatright_legal_text',
			static function ( $text, $key, $filtered_order ) {
				return 'art196' === $key && $filtered_order instanceof WC_Order ? 'Steuerschuldnerschaft des Leistungsempfängers' : $text;
			},
			10,
			3
		);

		$rows = $this->values( $order );

		$this->assertContains( 'Umsatzsteuer-ID des Kunden: ' . self::NUMBER, $rows, 'Labels go through gettext' );
		$this->assertContains( 'VAT note: Steuerschuldnerschaft des Leistungsempfängers', $rows );
		$this->assertSame( self::ART138, InvoiceText::legal_text( 'art138' ), 'Other keys keep their text' );
	}

	public function test_the_wording_is_escaped_and_an_empty_filter_result_drops_the_row() {
		$order = $this->exempt_order();
		add_filter(
			'vatright_legal_text',
			static function () {
				return '<b>x</b>';
			}
		);
		$this->assertContains( 'VAT note: &lt;b&gt;x&lt;/b&gt;', $this->values( $order ) );

		remove_all_filters( 'vatright_legal_text' );
		add_filter( 'vatright_legal_text', '__return_empty_string' );
		$this->assertSame( array(), preg_grep( '/^VAT note/', $this->values( $order ) ) );
	}

	private function two_numbers(): void {
		update_option(
			Settings::OPTION,
			array(
				'store_vat_number'    => 'MT12345678',
				'store_vat_number_gb' => 'GB980780684',
			)
		);
		Settings::reset();
	}

	private function decided_order( string $country, string $postcode ): WC_Order {
		$order = $this->order( $country, $country, $postcode, array( 'digital' ) );
		DecisionStore::apply( $order, $this->decide( $country, $country, $postcode, array( 'digital' ) ), 'checkout' );
		$order->save();
		return $this->fresh( $order );
	}

	public function test_a_uk_customer_is_given_the_gb_store_number_and_everyone_else_the_eu_one() {
		$this->two_numbers();

		$this->assertContains( 'Our VAT number: GB980780684', $this->values( $this->decided_order( 'GB', 'SW1A 1AA' ) ) );
		$this->assertContains( 'Our VAT number: MT12345678', $this->values( $this->decided_order( 'DE', '10115' ) ) );
		$this->assertContains( 'Our VAT number: MT12345678', $this->values( $this->exempt_order() ) );
	}

	public function test_the_store_number_row_follows_the_billing_country_of_an_order_without_a_decision() {
		$this->two_numbers();

		$this->assertContains( 'Our VAT number: GB980780684', $this->values( $this->order( 'GB', 'GB', 'SW1A 1AA', array( 'digital' ) ) ) );
	}

	public function test_a_store_with_one_number_gets_no_store_number_row() {
		update_option( Settings::OPTION, array( 'store_vat_number' => 'MT12345678' ) );
		Settings::reset();

		$this->assertSame( array(), preg_grep( '/^Our VAT number/', $this->values( $this->decided_order( 'GB', 'SW1A 1AA' ) ) ) );
		$this->assertSame( array(), preg_grep( '/^Our VAT number/', $this->values( $this->decided_order( 'DE', '10115' ) ) ) );

		update_option( Settings::OPTION, array( 'store_vat_number_gb' => 'GB980780684' ) );
		Settings::reset();
		$this->assertSame( array(), preg_grep( '/^Our VAT number/', $this->values( $this->decided_order( 'GB', 'SW1A 1AA' ) ) ) );
	}
}
