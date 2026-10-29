<?php

namespace Vatright\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use Vatright\Domain\ValidationResult;
use Vatright\Migration\LegacyData;
use Vatright\Orders\BillingAddress;

class LegacyDataTest extends TestCase {

	/**
	 * @param array<string,mixed> $values
	 */
	private static function reader( array $values ): callable {
		return static function ( string $key ) use ( $values ) {
			return $values[ $key ] ?? '';
		};
	}

	/**
	 * The keys each plugin's own source reads and writes; a change here has
	 * to be checked against that source again.
	 */
	public function test_each_source_reads_the_keys_its_plugin_writes(): void {
		$this->assertSame( array( '_billing_vat_number', '_vat_number' ), LegacyData::SOURCES['eu-vat-number']['order_number'] );
		$this->assertSame( array( 'vat_number', 'billing_vat_number' ), LegacyData::SOURCES['eu-vat-number']['user_number'] );
		$this->assertSame( array( '_billing_eu_vat_number' ), LegacyData::SOURCES['wpfactory']['order_number'] );
		$this->assertSame( array( 'billing_eu_vat_number', '_wc_other/alg_eu_vat/billing_eu_vat_number' ), LegacyData::SOURCES['wpfactory']['user_number'] );
		$this->assertSame( array( 'vat_number' ), LegacyData::SOURCES['aelia']['order_number'] );
		$this->assertSame( array( 'vat_number' ), LegacyData::SOURCES['aelia']['user_number'] );
		$this->assertSame( array( 'VAT Number' ), LegacyData::SOURCES['simba']['order_number'] );
		$this->assertSame( array(), LegacyData::SOURCES['simba']['user_number'] );
		$this->assertSame( array( 'eu-vat-number', 'wpfactory', 'aelia', 'simba' ), array_keys( LegacyData::SOURCES ), 'Order of precedence' );
	}

	/**
	 * @dataProvider verdicts
	 * @param array<string,mixed> $meta
	 */
	public function test_verdict_maps_each_plugins_validation_record( string $source, array $meta, string $expected ): void {
		$this->assertSame( $expected, LegacyData::verdict( $source, self::reader( $meta ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public function verdicts(): array {
		return array(
			'eu-vat-number valid'           => array(
				'eu-vat-number',
				array(
					'_vat_number_is_validated' => 'true',
					'_vat_number_is_valid'     => 'true',
				),
				ValidationResult::VALID,
			),
			'eu-vat-number invalid'         => array(
				'eu-vat-number',
				array(
					'_vat_number_is_validated' => 'true',
					'_vat_number_is_valid'     => 'false',
				),
				ValidationResult::INVALID,
			),
			'eu-vat-number not checked'     => array(
				'eu-vat-number',
				array(
					'_vat_number_is_validated' => 'false',
					'_vat_number_is_valid'     => 'false',
				),
				ValidationResult::UNCHECKED,
			),
			'eu-vat-number nothing'         => array( 'eu-vat-number', array(), ValidationResult::UNCHECKED ),
			'wpfactory details'             => array( 'wpfactory', array( 'billing_eu_vat_number_details' => array( 'business_name' => array( 'data' => 'Acme' ) ) ), ValidationResult::VALID ),
			'wpfactory no details'          => array( 'wpfactory', array(), ValidationResult::UNCHECKED ),
			'aelia valid'                   => array( 'aelia', array( '_vat_number_validated' => 'valid' ), ValidationResult::VALID ),
			'aelia not valid'               => array( 'aelia', array( '_vat_number_validated' => 'not-valid' ), ValidationResult::INVALID ),
			'aelia could not be validated'  => array( 'aelia', array( '_vat_number_validated' => 'could-not-be-validated' ), ValidationResult::UNCHECKED ),
			'simba validated'               => array(
				'simba',
				array(
					'Valid VAT Number'     => 'true',
					'VAT number validated' => 'true',
				),
				ValidationResult::VALID,
			),
			'simba invalid'                 => array( 'simba', array( 'Valid VAT Number' => 'false' ), ValidationResult::INVALID ),
			'simba valid but never checked' => array( 'simba', array( 'Valid VAT Number' => 'true' ), ValidationResult::UNCHECKED ),
			'unknown source'                => array( 'nope', array( '_vat_number_is_valid' => 'true' ), ValidationResult::UNCHECKED ),
		);
	}

	/**
	 * @dataProvider store_options
	 * @param array<string,mixed>        $options
	 * @param array{eu:string,gb:string} $expected
	 */
	public function test_store_numbers_read_each_plugins_settings( string $source, array $options, array $expected ): void {
		$this->assertSame( $expected, LegacyData::store_numbers( $source, self::reader( $options ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array{eu:string,gb:string}}>
	 */
	public function store_options(): array {
		return array(
			'simba both regions'       => array(
				'simba',
				array(
					'woocommerce_eu_vat_store_id' => array(
						'eu' => 'MT12345678',
						'uk' => 'GB123456789',
					),
				),
				array(
					'eu' => 'MT12345678',
					'gb' => 'GB123456789',
				),
			),
			'simba pre-1.17.7 string'  => array(
				'simba',
				array( 'woocommerce_eu_vat_store_id' => ' GB123456789 ' ),
				array(
					'eu' => '',
					'gb' => 'GB123456789',
				),
			),
			'aelia requester'          => array(
				'aelia',
				array(
					'wc_aelia_eu_vat_assistant' => array(
						'vies_requester_vat_country' => 'GR',
						'vies_requester_vat_number'  => '094259216',
					),
				),
				array(
					'eu' => 'EL094259216',
					'gb' => '',
				),
			),
			'wpfactory requester'      => array(
				'wpfactory',
				array(
					'alg_wc_eu_vat_requester_country_code' => 'MT',
					'alg_wc_eu_vat_requester_vat_number'   => 'MT 1234-5678',
				),
				array(
					'eu' => 'MT12345678',
					'gb' => '',
				),
			),
			'eu-vat-number has none'   => array(
				'eu-vat-number',
				array( 'woocommerce_eu_vat_store_id' => 'MT12345678' ),
				array(
					'eu' => '',
					'gb' => '',
				),
			),
			'aelia without the option' => array(
				'aelia',
				array(),
				array(
					'eu' => '',
					'gb' => '',
				),
			),
		);
	}

	public function test_the_address_line_is_added_once_and_never_beside_another_plugins(): void {
		$address = new BillingAddress();
		$formats = $address->add_token(
			array(
				'default' => "{name}\n{country}",
				'DE'      => "{company}\n{name}\n{vat_id}",
				'AT'      => "{name}\n{vat_number}",
				'BE'      => "{name}\n{billing_eu_vat_number}",
			)
		);

		$this->assertSame( "{name}\n{country}\n" . BillingAddress::TOKEN, $formats['default'] );
		$this->assertSame( "{company}\n{name}\n{vat_id}", $formats['DE'], 'WooCommerce EU VAT Number already prints it' );
		$this->assertSame( "{name}\n{vat_number}", $formats['AT'], 'Aelia already prints it' );
		$this->assertSame( "{name}\n{billing_eu_vat_number}", $formats['BE'], 'WPFactory already prints it' );
		$this->assertSame( $formats, $address->add_token( $formats ), 'Running the filter twice adds nothing' );
	}
}
