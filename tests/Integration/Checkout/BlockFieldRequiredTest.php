<?php

namespace Vatright\Tests\Integration\Checkout;

use Vatright\Checkout\VatField;

require_once __DIR__ . '/CheckoutTestCase.php';

class BlockFieldRequiredTest extends CheckoutTestCase {

	/**
	 * @param bool|array<string,mixed> $required
	 * @return string[]
	 */
	private function countries_of( $required ): array {
		$this->assertIsArray( $required );
		return $required['properties']['customer']['properties']['billing_address']['properties']['country']['enum'];
	}

	public function test_the_block_field_stays_optional_while_business_only_is_off(): void {
		$this->settings( array( 'b2b_only' => 'off' ) );

		$this->assertFalse( VatField::block_required() );
	}

	public function test_the_block_field_is_required_wherever_it_is_shown_when_business_only_is_everywhere(): void {
		$this->settings( array( 'b2b_only' => 'everywhere' ) );

		$countries = $this->countries_of( VatField::block_required() );

		$this->assertContains( 'DE', $countries );
		$this->assertContains( 'GB', $countries );
		$this->assertNotContains( 'US', $countries );
	}

	public function test_the_block_field_is_required_only_in_eu_countries_in_eu_mode(): void {
		$this->settings( array( 'b2b_only' => 'eu' ) );

		$countries = $this->countries_of( VatField::block_required() );

		$this->assertContains( 'DE', $countries );
		$this->assertNotContains( 'GB', $countries );
		$this->assertNotContains( 'CH', $countries );
	}

	public function test_the_block_field_is_required_only_in_the_chosen_countries(): void {
		$this->settings(
			array(
				'b2b_only'           => 'countries',
				'b2b_only_countries' => array( 'de', 'FR' ),
			)
		);

		$this->assertSame( array( 'DE', 'FR' ), $this->countries_of( VatField::block_required() ) );
	}
}
