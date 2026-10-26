<?php

namespace Vatright\Tests\Unit\Domain;

use Vatright\Domain\DecisionInput;
use Vatright\Domain\Evidence;
use Vatright\Domain\Line;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use PHPUnit\Framework\TestCase;

class DecisionInputTest extends TestCase {

	public function test_exposes_every_constructor_argument(): void {
		$number     = VatNumber::parse( 'DE123456789', 'DE' );
		$validation = new ValidationResult( ValidationResult::VALID, 'vies', '2026-10-17T11:59:58Z' );
		$evidence   = new Evidence( 'DE', 'DE', null, null );
		$lines      = array( new Line( 'item-1', Line::GOODS, true ) );
		$threshold  = array(
			'current_eur'  => '8123.40',
			'previous_eur' => '0.00',
			'exceeded'     => false,
			'forced'       => false,
		);

		$input = new DecisionInput( 'MT', false, true, $threshold, 'reject', true, true, 'DE', '10115', 'AT', '1010', true, $number, 'de 123 456 789', $validation, $evidence, $lines, 'cart', '2026-10-17T12:00:00Z', array( 'reseller' ) );

		$this->assertSame( 'MT', $input->store_country() );
		$this->assertFalse( $input->oss_registered() );
		$this->assertTrue( $input->threshold_exceeded() );
		$this->assertSame( $threshold, $input->threshold() );
		$this->assertSame( 'reject', $input->outage_policy() );
		$this->assertTrue( $input->allow_domestic_exemption() );
		$this->assertTrue( $input->fixed_prices() );
		$this->assertSame( 'DE', $input->billing_country() );
		$this->assertSame( '10115', $input->billing_postcode() );
		$this->assertSame( 'AT', $input->shipping_country() );
		$this->assertSame( '1010', $input->shipping_postcode() );
		$this->assertTrue( $input->local_pickup() );
		$this->assertSame( $number, $input->vat_number() );
		$this->assertSame( 'de 123 456 789', $input->vat_raw() );
		$this->assertSame( $validation, $input->validation() );
		$this->assertSame( $evidence, $input->evidence() );
		$this->assertSame( $lines, $input->lines() );
		$this->assertSame( 'cart', $input->trigger() );
		$this->assertSame( '2026-10-17T12:00:00Z', $input->now_utc() );
		$this->assertSame( array( 'reseller' ), $input->exempt_roles() );
	}

	public function test_optional_parts_can_be_absent(): void {
		$input = new DecisionInput( 'MT', false, false, array(), 'charge', false, false, 'DE', '', '', '', false, null, null, null, new Evidence( 'DE', null, null, null ), array(), 'checkout', '2026-10-17T12:00:00Z' );

		$this->assertNull( $input->vat_number() );
		$this->assertNull( $input->vat_raw() );
		$this->assertNull( $input->validation() );
		$this->assertSame( '', $input->shipping_country() );
		$this->assertSame( array(), $input->exempt_roles() );
	}

	public function test_line_exposes_its_fields(): void {
		$line = new Line( 'item-1', Line::DIGITAL, false );
		$this->assertSame( 'item-1', $line->key() );
		$this->assertSame( 'digital', $line->supply_type() );
		$this->assertFalse( $line->needs_shipping() );
		$this->assertSame( array( 'goods', 'digital', 'service' ), array( Line::GOODS, Line::DIGITAL, Line::SERVICE ) );
	}
}
