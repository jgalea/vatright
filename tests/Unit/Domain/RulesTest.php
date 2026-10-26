<?php

namespace Vatright\Tests\Unit\Domain;

use Vatright\Domain\Decision;
use Vatright\Domain\DecisionInput;
use Vatright\Domain\Evidence;
use Vatright\Domain\Line;
use Vatright\Domain\Rule;
use Vatright\Domain\Rules;
use Vatright\Domain\Scheme;
use Vatright\Domain\Territory;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use PHPUnit\Framework\TestCase;

/**
 * Data provider labels cite the legal basis: "Dir." is Council Directive
 * 2006/112/EC, "Reg." is Council Implementing Regulation (EU) No 282/2011.
 */
class RulesTest extends TestCase {

	const EXEMPT_RULES = array( Rule::RC_GOODS_ART138, Rule::RC_SERVICES_ART196, Rule::DOMESTIC_B2B_ALLOWED, Rule::PENDING_EXEMPT, Rule::ROLE_EXEMPT );

	/**
	 * @param array<string,mixed> $o
	 */
	private function input( array $o = array() ): DecisionInput {
		$o = array_merge(
			array(
				'store'             => 'MT',
				'oss'               => false,
				'exceeded'          => false,
				'policy'            => 'charge',
				'allow_domestic'    => false,
				'billing_country'   => 'DE',
				'billing_postcode'  => '10115',
				'shipping_country'  => '',
				'shipping_postcode' => '',
				'pickup'            => false,
				'vat'               => null,
				'status'            => null,
				'ip'                => 'DE',
				'self_certified'    => null,
				'lines'             => array( new Line( 'd1', Line::DIGITAL, false ) ),
				'trigger'           => 'checkout',
				'exempt_roles'      => array(),
			),
			$o
		);

		$number     = null === $o['vat'] ? null : VatNumber::parse( $o['vat'], $o['billing_country'] );
		$validation = null === $o['status'] ? null : new ValidationResult( $o['status'], 'vies', '2026-10-17T11:59:58Z' );
		$evidence   = new Evidence(
			'' === $o['billing_country'] ? null : $o['billing_country'],
			'' === $o['shipping_country'] ? null : $o['shipping_country'],
			$o['ip'],
			$o['self_certified']
		);

		return new DecisionInput(
			$o['store'],
			$o['oss'],
			$o['exceeded'],
			array(
				'current_eur'  => $o['exceeded'] ? '12000.00' : '8123.40',
				'previous_eur' => '0.00',
				'exceeded'     => $o['exceeded'],
				'forced'       => false,
			),
			$o['policy'],
			$o['allow_domestic'],
			false,
			$o['billing_country'],
			$o['billing_postcode'],
			$o['shipping_country'],
			$o['shipping_postcode'],
			$o['pickup'],
			$number,
			$o['vat'],
			$validation,
			$evidence,
			$o['lines'],
			$o['trigger'],
			'2026-10-17T12:00:00Z',
			$o['exempt_roles']
		);
	}

	/**
	 * @param array<string,mixed> $o
	 */
	private function decide( array $o = array() ): Decision {
		$rules = new Rules(
			static function (): string {
				return '00000000-0000-4000-8000-000000000000';
			}
		);
		return $rules->decide( $this->input( $o ) );
	}

	private function goods( string $key = 'g1' ): Line {
		return new Line( $key, Line::GOODS, true );
	}

	private function digital( string $key = 'd1' ): Line {
		return new Line( $key, Line::DIGITAL, false );
	}

	private function service( string $key = 's1' ): Line {
		return new Line( $key, Line::SERVICE, false );
	}

	private function line_tax_country( Decision $d, string $key ): string {
		$line = $d->line( $key );
		$this->assertNotNull( $line, "line $key missing" );
		return $line->tax_country();
	}

	private function assertCharged( Decision $d ): void {
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertNotContains( $d->rule(), self::EXEMPT_RULES );
		$this->assertSame( array(), $d->legal_text() );
	}

	/**
	 * @dataProvider provide_valid_b2b_services
	 */
	public function test_valid_b2b_services_reverse_charge( Line $line ): void {
		$d = $this->decide(
			array(
				'vat'    => 'DE123456789',
				'status' => ValidationResult::VALID,
				'lines'  => array( $line ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Rule::RC_SERVICES_ART196, $d->rule() );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $d->scheme() );
		$this->assertSame( 'DE', $d->tax_country() );
		$this->assertSame( array( 'art196' ), $d->legal_text() );
		$this->assertNull( $d->blocking_error() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_valid_b2b_services(): array {
		return array(
			'Dir. Art. 44 + 196, Reg. Art. 18(1)(a): valid DE number, e-book' => array( new Line( 'x', Line::DIGITAL, false ) ),
			'Dir. Art. 44 + 196, Reg. Art. 18(1)(a): valid DE number, consulting' => array( new Line( 'x', Line::SERVICE, false ) ),
		);
	}

	/**
	 * @dataProvider provide_b2b_services_near_misses
	 * @param array<string,mixed> $o
	 */
	public function test_b2b_services_near_miss_charges( array $o ): void {
		$this->assertCharged( $this->decide( $o ) );
	}

	/**
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function provide_b2b_services_near_misses(): array {
		return array(
			'Reg. Art. 18(1)(a): no registry confirmation, charge policy' => array(
				array(
					'vat'    => 'DE123456789',
					'status' => ValidationResult::UNAVAILABLE,
				),
			),
			'Reg. Art. 18(1)(a): number given but never checked' => array( array( 'vat' => 'DE123456789' ) ),
			'Reg. Art. 18(1)(a): registry says invalid, renewal' => array(
				array(
					'vat'     => 'DE123456789',
					'status'  => ValidationResult::INVALID,
					'trigger' => 'renewal',
				),
			),
			'Reg. Art. 18(2): no number, customer is non-taxable' => array( array( 'exceeded' => true ) ),
			'Reg. Art. 18(1): valid number from another country than billing' => array(
				array(
					'vat'             => 'DE123456789',
					'status'          => ValidationResult::VALID,
					'billing_country' => 'FR',
					'ip'              => 'FR',
				),
			),
		);
	}

	/**
	 * @dataProvider provide_art138
	 */
	public function test_valid_b2b_goods_dispatched_cross_border_art138( string $label ): void {
		$d = $this->decide(
			array(
				'vat'               => 'DE123456789',
				'status'            => ValidationResult::VALID,
				'shipping_country'  => 'DE',
				'shipping_postcode' => '10115',
				'lines'             => array( $this->goods() ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt(), $label );
		$this->assertSame( Rule::RC_GOODS_ART138, $d->rule() );
		$this->assertSame( Scheme::INTRA_EU_GOODS, $d->scheme() );
		$this->assertSame( 'DE', $d->tax_country() );
		$this->assertSame( array( 'art138' ), $d->legal_text() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_art138(): array {
		return array( 'Dir. Art. 138(1)(a)+(b): MT to DE, DE-identified customer' => array( 'goods MT to DE' ) );
	}

	/**
	 * @dataProvider provide_art138_near_misses
	 * @param array<string,mixed> $o
	 */
	public function test_art138_near_miss_charges( array $o, string $rule ): void {
		$d = $this->decide( array_merge( array( 'lines' => array( $this->goods() ) ), $o ) );
		$this->assertCharged( $d );
		$this->assertSame( $rule, $d->rule() );
	}

	/**
	 * @return array<string,array{array<string,mixed>, string}>
	 */
	public function provide_art138_near_misses(): array {
		return array(
			'Dir. Art. 138(1)(b): customer identified in the dispatch state (MT number, goods to DE)' => array(
				array(
					'vat'               => 'MT12345678',
					'status'            => ValidationResult::VALID,
					'billing_country'   => 'MT',
					'billing_postcode'  => 'VLT1000',
					'shipping_country'  => 'DE',
					'shipping_postcode' => '10115',
				),
				Rule::DOMESTIC_B2B_GUARD,
			),
			'Dir. Art. 138(1): goods never leave MT (DE number, shipped to MT)' => array(
				array(
					'vat'               => 'DE123456789',
					'status'            => ValidationResult::VALID,
					'shipping_country'  => 'MT',
					'shipping_postcode' => 'VLT1000',
				),
				Rule::DOMESTIC_B2B_GUARD,
			),
			'Dir. Art. 138(1)(b): VIES unavailable, charge policy' => array(
				array(
					'vat'               => 'DE123456789',
					'status'            => ValidationResult::UNAVAILABLE,
					'shipping_country'  => 'DE',
					'shipping_postcode' => '10115',
				),
				Rule::PENDING_CHARGED,
			),
		);
	}

	/**
	 * @dataProvider provide_same_country
	 */
	public function test_valid_number_same_country_charges( Line $line ): void {
		$d = $this->decide(
			array(
				'vat'              => 'MT12345678',
				'status'           => ValidationResult::VALID,
				'billing_country'  => 'MT',
				'billing_postcode' => 'VLT1000',
				'shipping_country' => Line::GOODS === $line->supply_type() ? 'MT' : '',
				'ip'               => 'MT',
				'lines'            => array( $line ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::DOMESTIC_B2B_GUARD, $d->rule() );
		$this->assertSame( Scheme::DOMESTIC, $d->scheme() );
		$this->assertSame( 'MT', $d->tax_country() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_same_country(): array {
		return array(
			'Dir. Art. 196 needs a supplier not established in the customer state: e-book' => array( new Line( 'x', Line::DIGITAL, false ) ),
			'Dir. Art. 196 needs a supplier not established in the customer state: consulting' => array( new Line( 'x', Line::SERVICE, false ) ),
			'Dir. Art. 138(1) needs dispatch out of MT: goods kept in MT' => array( new Line( 'x', Line::GOODS, true ) ),
		);
	}

	/**
	 * @dataProvider provide_domestic_allowed
	 */
	public function test_domestic_exemption_allowed_by_setting( string $status, bool $exempt ): void {
		$d = $this->decide(
			array(
				'vat'              => 'MT12345678',
				'status'           => $status,
				'billing_country'  => 'MT',
				'billing_postcode' => 'VLT1000',
				'ip'               => 'MT',
				'allow_domestic'   => true,
				'trigger'          => 'renewal',
			)
		);
		$this->assertSame( $exempt, $d->is_vat_exempt() );
		if ( $exempt ) {
			$this->assertSame( Rule::DOMESTIC_B2B_ALLOWED, $d->rule() );
			$this->assertSame( 'MT', $d->tax_country() );
		} else {
			$this->assertCharged( $d );
		}
	}

	/**
	 * @return array<string,array{string, bool}>
	 */
	public function provide_domestic_allowed(): array {
		return array(
			'Dir. Art. 199/199a domestic reverse charge, merchant opted in, valid number' => array( ValidationResult::VALID, true ),
			'Dir. Art. 199/199a near miss: invalid number still charges' => array( ValidationResult::INVALID, false ),
			'Dir. Art. 199/199a near miss: VIES unavailable, charge policy' => array( ValidationResult::UNAVAILABLE, false ),
		);
	}

	/**
	 * @dataProvider provide_prefix_mismatch
	 */
	public function test_prefix_mismatch_charges_and_flags( string $billing, string $vat ): void {
		$d = $this->decide(
			array(
				'vat'             => $vat,
				'status'          => ValidationResult::VALID,
				'billing_country' => $billing,
				'ip'              => $billing,
				'exceeded'        => true,
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::PREFIX_MISMATCH_CHARGED, $d->rule() );
		$this->assertSame( array( 'prefix_mismatch' ), $d->flags() );
		$this->assertSame( Territory::vat_country( $billing ), $d->tax_country() );
	}

	/**
	 * @return array<string,array{string, string}>
	 */
	public function provide_prefix_mismatch(): array {
		return array(
			'Reg. Art. 18(1) "unless he has information to the contrary": DE number, FR billing' => array( 'FR', 'DE123456789' ),
			'Reg. Art. 18(1) information to the contrary: FR number, Monaco billing is fine, DE number is not' => array( 'MC', 'DE123456789' ),
		);
	}

	public function test_prefix_matching_through_monaco_is_not_a_mismatch(): void {
		$d = $this->decide(
			array(
				'vat'              => 'FR40303265045',
				'status'           => ValidationResult::VALID,
				'billing_country'  => 'MC',
				'billing_postcode' => '98000',
				'ip'               => 'MC',
			)
		);
		$this->assertSame( Rule::RC_SERVICES_ART196, $d->rule() );
		$this->assertSame( 'FR', $d->tax_country() );
	}

	/**
	 * @dataProvider provide_xi_goods
	 */
	public function test_xi_number_goods_to_northern_ireland_exempt( string $label ): void {
		$d = $this->decide(
			array(
				'vat'               => 'XI123456789',
				'status'            => ValidationResult::VALID,
				'billing_country'   => 'GB',
				'billing_postcode'  => 'BT1 1AA',
				'shipping_country'  => 'GB',
				'shipping_postcode' => 'BT1 1AA',
				'ip'                => 'GB',
				'lines'             => array( $this->goods() ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt(), $label );
		$this->assertSame( Rule::RC_GOODS_ART138, $d->rule() );
		$this->assertSame( 'GB', $d->tax_country() );
		$this->assertSame( array( 'art138' ), $d->legal_text() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_xi_goods(): array {
		return array( 'Dir. Art. 138(1) applied to Northern Ireland goods by Windsor Framework Art. 8 and Annex 3' => array( 'XI goods to Belfast' ) );
	}

	/**
	 * @dataProvider provide_xi_goods_near_misses
	 * @param array<string,mixed> $o
	 */
	public function test_xi_goods_near_miss_is_not_exempt( array $o, string $rule ): void {
		$d = $this->decide(
			array_merge(
				array(
					'billing_country'  => 'GB',
					'billing_postcode' => 'BT1 1AA',
					'shipping_country' => 'GB',
					'ip'               => 'GB',
					'status'           => ValidationResult::VALID,
					'lines'            => array( $this->goods() ),
				),
				$o
			)
		);
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertSame( $rule, $d->rule() );
	}

	/**
	 * @return array<string,array{array<string,mixed>, string}>
	 */
	public function provide_xi_goods_near_misses(): array {
		return array(
			'Dir. Art. 146(1)(a): XI number, goods to London leave the EU VAT area' => array(
				array(
					'vat'               => 'XI123456789',
					'shipping_postcode' => 'SW1A 1AA',
				),
				Rule::NON_EU_NUMBER_RECORDED,
			),
			'Dir. Art. 138(1)(b): a GB number does not identify the buyer for EU goods rules' => array(
				array(
					'vat'               => 'GB123456789',
					'shipping_postcode' => 'BT1 1AA',
				),
				Rule::B2C_ORIGIN_BELOW_THRESHOLD,
			),
			'Reg. Art. 18(1)(a): XI number unconfirmed, charge policy' => array(
				array(
					'vat'               => 'XI123456789',
					'shipping_postcode' => 'BT1 1AA',
					'status'            => ValidationResult::UNAVAILABLE,
				),
				Rule::PENDING_CHARGED,
			),
		);
	}

	/**
	 * @dataProvider provide_xi_services
	 */
	public function test_xi_number_services_treated_as_uk_export( Line $line ): void {
		$d = $this->decide(
			array(
				'vat'              => 'XI123456789',
				'status'           => ValidationResult::VALID,
				'billing_country'  => 'GB',
				'billing_postcode' => 'BT1 1AA',
				'ip'               => 'GB',
				'lines'            => array( $line ),
			)
		);
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertSame( Rule::NON_EU_NUMBER_RECORDED, $d->rule() );
		$this->assertSame( Scheme::EXPORT, $d->scheme() );
		$this->assertSame( 'GB', $d->tax_country() );
		$this->assertSame( array(), $d->legal_text() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_xi_services(): array {
		return array(
			'Dir. Art. 58: e-book to a UK customer is supplied in the UK' => array( new Line( 'x', Line::DIGITAL, false ) ),
			'Dir. Art. 44: consulting to a validated UK business is supplied in the UK' => array( new Line( 'x', Line::SERVICE, false ) ),
		);
	}

	/**
	 * @dataProvider provide_local_pickup
	 */
	public function test_local_pickup_revokes_goods_exemption( string $label ): void {
		$d = $this->decide(
			array(
				'vat'               => 'DE123456789',
				'status'            => ValidationResult::VALID,
				'shipping_country'  => 'DE',
				'shipping_postcode' => '10115',
				'pickup'            => true,
				'lines'             => array( $this->goods(), $this->digital() ),
			)
		);
		$this->assertFalse( $d->is_vat_exempt(), $label );
		$this->assertSame( Rule::LOCAL_PICKUP_DOMESTIC, $d->rule() );
		$this->assertSame( Scheme::MIXED, $d->scheme() );
		$this->assertSame( 'MT', $this->line_tax_country( $d, 'g1' ) );
		$goods = $d->line( 'g1' );
		$this->assertNotNull( $goods );
		$this->assertSame( Scheme::DOMESTIC, $goods->scheme() );
		$digital = $d->line( 'd1' );
		$this->assertNotNull( $digital );
		$this->assertSame( Scheme::REVERSE_CHARGE_SERVICES, $digital->scheme() );
		$this->assertSame( array( 'art196' ), $d->legal_text() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_local_pickup(): array {
		return array( 'Dir. Art. 31: goods not dispatched are supplied where they are (MT)' => array( 'pickup' ) );
	}

	/**
	 * @dataProvider provide_invalid_blocking
	 */
	public function test_invalid_number_blocks_checkout( string $status, string $trigger ): void {
		$d = $this->decide(
			array(
				'vat'     => 'DE123456789',
				'status'  => $status,
				'trigger' => $trigger,
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::INVALID_NUMBER, $d->rule() );
		$this->assertSame( 'invalid_vat_number', $d->blocking_error() );
	}

	/**
	 * @return array<string,array{string, string}>
	 */
	public function provide_invalid_blocking(): array {
		return array(
			'Reg. Art. 18(1)(a): registry invalid at checkout' => array( ValidationResult::INVALID, 'checkout' ),
			'Reg. Art. 18(1)(a): registry invalid in cart' => array( ValidationResult::INVALID, 'cart' ),
			'Reg. Art. 18(1)(a): format invalid at checkout' => array( ValidationResult::FORMAT_INVALID, 'checkout' ),
		);
	}

	/**
	 * @dataProvider provide_invalid_after_checkout
	 */
	public function test_invalid_number_on_renewal_charges( string $trigger ): void {
		$d = $this->decide(
			array(
				'vat'     => 'DE123456789',
				'status'  => ValidationResult::INVALID,
				'trigger' => $trigger,
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::INVALID_NUMBER, $d->rule() );
		$this->assertNull( $d->blocking_error() );
		$this->assertSame( array(), $d->flags() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_invalid_after_checkout(): array {
		return array(
			'Reg. Art. 18(1)(a): renewal'      => array( 'renewal' ),
			'Reg. Art. 18(1)(a): revalidation' => array( 'revalidation' ),
			'Reg. Art. 18(1)(a): admin'        => array( 'admin' ),
		);
	}

	/**
	 * @dataProvider provide_unconfirmed
	 */
	public function test_unavailable_charge_policy_charges_and_flags( ?string $status, string $policy ): void {
		$d = $this->decide(
			array(
				'vat'    => 'DE123456789',
				'status' => $status,
				'policy' => $policy,
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::PENDING_CHARGED, $d->rule() );
		$this->assertSame( array( 'validation_pending' ), $d->flags() );
		$this->assertNull( $d->blocking_error() );
	}

	/**
	 * @return array<string,array{?string, string}>
	 */
	public function provide_unconfirmed(): array {
		return array(
			'Reg. Art. 18(1)(a): VIES unavailable'       => array( ValidationResult::UNAVAILABLE, 'charge' ),
			'Reg. Art. 18(1)(a): unchecked'              => array( ValidationResult::UNCHECKED, 'charge' ),
			'Reg. Art. 18(1)(a): no result at all'       => array( null, 'charge' ),
			'Reg. Art. 18(1)(a): unknown policy charges' => array( ValidationResult::UNAVAILABLE, 'something-else' ),
		);
	}

	/**
	 * @dataProvider provide_exempt_pending
	 */
	public function test_unavailable_exempt_pending_exempts_and_flags( Line $line, string $rule, string $legal ): void {
		$d = $this->decide(
			array(
				'vat'               => 'DE123456789',
				'status'            => ValidationResult::UNAVAILABLE,
				'policy'            => 'exempt_pending',
				'shipping_country'  => 'DE',
				'shipping_postcode' => '10115',
				'lines'             => array( $line ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( $rule, $d->rule() );
		$this->assertSame( array( 'validation_pending' ), $d->flags() );
		$this->assertSame( array( $legal ), $d->legal_text() );
	}

	/**
	 * @return array<string,array{Line, string, string}>
	 */
	public function provide_exempt_pending(): array {
		return array(
			'Dir. Art. 196, merchant accepts Reg. Art. 18(1)(a) risk: e-book' => array( new Line( 'x', Line::DIGITAL, false ), Rule::PENDING_EXEMPT, 'art196' ),
			'Dir. Art. 138, merchant accepts Reg. Art. 18(1)(a) risk: goods' => array( new Line( 'x', Line::GOODS, true ), Rule::PENDING_EXEMPT, 'art138' ),
		);
	}

	/**
	 * @dataProvider provide_never_checked
	 */
	public function test_exempt_pending_charges_a_number_that_was_never_checked( ?string $status ): void {
		$d = $this->decide(
			array(
				'vat'    => 'DE123456789',
				'status' => $status,
				'policy' => 'exempt_pending',
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::PENDING_CHARGED, $d->rule() );
		$this->assertSame( array( 'validation_pending' ), $d->flags() );
	}

	/**
	 * @return array<string,array{?string}>
	 */
	public function provide_never_checked(): array {
		return array(
			'unchecked'        => array( ValidationResult::UNCHECKED ),
			'no result at all' => array( null ),
		);
	}

	public function test_exempt_pending_never_exempts_a_domestic_number(): void {
		$d = $this->decide(
			array(
				'vat'              => 'MT12345678',
				'status'           => ValidationResult::UNAVAILABLE,
				'policy'           => 'exempt_pending',
				'billing_country'  => 'MT',
				'billing_postcode' => 'VLT1000',
				'ip'               => 'MT',
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::DOMESTIC_B2B_GUARD, $d->rule() );
	}

	/**
	 * @dataProvider provide_reject
	 */
	public function test_unavailable_reject_policy_blocks_checkout_only( string $trigger, string $rule, ?string $error ): void {
		$d = $this->decide(
			array(
				'vat'     => 'DE123456789',
				'status'  => ValidationResult::UNAVAILABLE,
				'policy'  => 'reject',
				'trigger' => $trigger,
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( $rule, $d->rule() );
		$this->assertSame( $error, $d->blocking_error() );
		$this->assertSame( array( 'validation_pending' ), $d->flags() );
	}

	/**
	 * @return array<string,array{string, string, ?string}>
	 */
	public function provide_reject(): array {
		return array(
			'Reg. Art. 18(1)(a) reject policy: checkout blocks' => array( 'checkout', Rule::PENDING_REJECTED, 'registry_unavailable' ),
			'Reg. Art. 18(1)(a) reject policy: cart only charges' => array( 'cart', Rule::PENDING_CHARGED, null ),
			'Reg. Art. 18(1)(a) reject policy: renewal only charges' => array( 'renewal', Rule::PENDING_CHARGED, null ),
		);
	}

	/**
	 * @dataProvider provide_below_threshold
	 */
	public function test_b2c_below_threshold_uses_origin( Line $line, string $shipping ): void {
		$d = $this->decide(
			array(
				'shipping_country'  => $shipping,
				'shipping_postcode' => '' === $shipping ? '' : '10115',
				'lines'             => array( $line ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_ORIGIN_BELOW_THRESHOLD, $d->rule() );
		$this->assertSame( Scheme::ORIGIN, $d->scheme() );
		$this->assertSame( 'MT', $d->tax_country() );
	}

	/**
	 * @return array<string,array{Line, string}>
	 */
	public function provide_below_threshold(): array {
		return array(
			'Dir. Art. 59c(1) disapplies Art. 58: e-book to DE under EUR 10,000'       => array( new Line( 'x', Line::DIGITAL, false ), '' ),
			'Dir. Art. 59c(1) disapplies Art. 33(a): goods to DE under EUR 10,000' => array( new Line( 'x', Line::GOODS, true ), 'DE' ),
		);
	}

	/**
	 * @dataProvider provide_above_threshold
	 */
	public function test_b2c_above_threshold_uses_destination( Line $line, string $shipping ): void {
		$d = $this->decide(
			array(
				'exceeded'          => true,
				'shipping_country'  => $shipping,
				'shipping_postcode' => '' === $shipping ? '' : '10115',
				'lines'             => array( $line ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_DESTINATION, $d->rule() );
		$this->assertSame( Scheme::OSS_DESTINATION, $d->scheme() );
		$this->assertSame( 'DE', $d->tax_country() );
	}

	/**
	 * @return array<string,array{Line, string}>
	 */
	public function provide_above_threshold(): array {
		return array(
			'Dir. Art. 58 + 59c(2): e-book to DE over EUR 10,000'   => array( new Line( 'x', Line::DIGITAL, false ), '' ),
			'Dir. Art. 33(a) + 59c(2): goods to DE over EUR 10,000' => array( new Line( 'x', Line::GOODS, true ), 'DE' ),
		);
	}

	/**
	 * @dataProvider provide_oss_opt_in
	 */
	public function test_oss_registered_forces_destination( string $label ): void {
		$d = $this->decide( array( 'oss' => true ) );
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_DESTINATION, $d->rule(), $label );
		$this->assertSame( 'DE', $d->tax_country() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_oss_opt_in(): array {
		return array( 'Dir. Art. 59c(3): supplier opted for destination below the threshold' => array( 'opted in' ) );
	}

	public function test_b2c_domestic_customer_pays_store_vat(): void {
		$d = $this->decide(
			array(
				'billing_country'   => 'MT',
				'billing_postcode'  => 'VLT1000',
				'shipping_country'  => 'MT',
				'shipping_postcode' => 'VLT1000',
				'ip'                => 'MT',
				'exceeded'          => true,
				'lines'             => array( $this->goods(), $this->digital(), $this->service() ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::DOMESTIC, $d->rule(), 'Dir. Art. 2(1)(a) + 31/32/45: all supplies in MT' );
		$this->assertSame( Scheme::DOMESTIC, $d->scheme() );
		$this->assertSame( 'MT', $d->tax_country() );
	}

	/**
	 * @dataProvider provide_general_service
	 */
	public function test_b2c_general_service_always_origin( string $billing, string $postcode, bool $exceeded ): void {
		$d = $this->decide(
			array(
				'billing_country'  => $billing,
				'billing_postcode' => $postcode,
				'ip'               => $billing,
				'exceeded'         => $exceeded,
				'lines'            => array( $this->service() ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_SERVICE_ORIGIN, $d->rule() );
		$this->assertSame( Scheme::ORIGIN, $d->scheme() );
		$this->assertSame( 'MT', $d->tax_country() );
	}

	/**
	 * @return array<string,array{string, string, bool}>
	 */
	public function provide_general_service(): array {
		return array(
			'Dir. Art. 45: DE consumer, threshold exceeded'                          => array( 'DE', '10115', true ),
			'Dir. Art. 45: Canary consumer is still supplied in MT'                  => array( 'ES', '35001', false ),
			'Dir. Art. 45 (Art. 59 exceptions not modelled, so charged): US consumer' => array( 'US', '10001', false ),
		);
	}

	/**
	 * @dataProvider provide_canary
	 */
	public function test_canary_islands_b2c_is_outside_territory( Line $line ): void {
		$d = $this->decide(
			array(
				'billing_country'   => 'ES',
				'billing_postcode'  => '35001',
				'shipping_country'  => Line::GOODS === $line->supply_type() ? 'ES' : '',
				'shipping_postcode' => Line::GOODS === $line->supply_type() ? '38001' : '',
				'ip'                => 'ES',
				'lines'             => array( $line ),
			)
		);
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertSame( Rule::OUTSIDE_TERRITORY, $d->rule() );
		$this->assertSame( Scheme::OUTSIDE_TERRITORY, $d->scheme() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_canary(): array {
		return array(
			'Dir. Art. 6(1)(b): goods to Tenerife'         => array( new Line( 'x', Line::GOODS, true ) ),
			'Dir. Art. 6(1)(b) + 58: e-book to Las Palmas' => array( new Line( 'x', Line::DIGITAL, false ) ),
		);
	}

	/**
	 * @dataProvider provide_canary_near_misses
	 */
	public function test_canary_near_miss_charges( string $postcode ): void {
		$d = $this->decide(
			array(
				'billing_country'   => 'ES',
				'billing_postcode'  => $postcode,
				'shipping_country'  => 'ES',
				'shipping_postcode' => $postcode,
				'ip'                => 'ES',
				'exceeded'          => true,
				'lines'             => array( $this->goods() ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_DESTINATION, $d->rule() );
		$this->assertSame( 'ES', $d->tax_country() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_canary_near_misses(): array {
		return array(
			'Dir. Art. 5 + 33(a): Madrid is in the EU VAT area' => array( '28001' ),
			'Dir. Art. 6(1)(b) not provable from a partial postcode, so charged' => array( '35' ),
		);
	}

	/**
	 * @dataProvider provide_monaco
	 */
	public function test_monaco_taxed_as_france( Line $line ): void {
		$d = $this->decide(
			array(
				'billing_country'   => 'MC',
				'billing_postcode'  => '98000',
				'shipping_country'  => Line::GOODS === $line->supply_type() ? 'MC' : '',
				'shipping_postcode' => Line::GOODS === $line->supply_type() ? '98000' : '',
				'ip'                => 'MC',
				'exceeded'          => true,
				'lines'             => array( $line ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_DESTINATION, $d->rule() );
		$this->assertSame( 'FR', $d->tax_country() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_monaco(): array {
		return array(
			'Dir. Art. 7(1)+(2): goods to Monaco are French transactions'   => array( new Line( 'x', Line::GOODS, true ) ),
			'Dir. Art. 7(1)+(2): e-book to Monaco is a French transaction' => array( new Line( 'x', Line::DIGITAL, false ) ),
		);
	}

	/**
	 * @dataProvider provide_us
	 */
	public function test_us_customer_is_export( Line $line ): void {
		$d = $this->decide(
			array(
				'billing_country'   => 'US',
				'billing_postcode'  => '10001',
				'shipping_country'  => Line::GOODS === $line->supply_type() ? 'US' : '',
				'shipping_postcode' => Line::GOODS === $line->supply_type() ? '10001' : '',
				'ip'                => 'US',
				'lines'             => array( $line ),
			)
		);
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertSame( Rule::EXPORT_NON_EU, $d->rule() );
		$this->assertSame( Scheme::EXPORT, $d->scheme() );
		$this->assertSame( 'US', $d->tax_country() );
		$this->assertSame( array(), $d->flags() );
	}

	/**
	 * @return array<string,array{Line}>
	 */
	public function provide_us(): array {
		return array(
			'Dir. Art. 146(1)(a): goods shipped to the US' => array( new Line( 'x', Line::GOODS, true ) ),
			'Dir. Art. 58: e-book to a US consumer'        => array( new Line( 'x', Line::DIGITAL, false ) ),
		);
	}

	/**
	 * @dataProvider provide_swiss
	 */
	public function test_swiss_number_recorded_export( ?string $vat, string $rule ): void {
		$d = $this->decide(
			array(
				'vat'               => $vat,
				'status'            => null === $vat ? null : ValidationResult::UNCHECKED,
				'billing_country'   => 'CH',
				'billing_postcode'  => '8001',
				'shipping_country'  => 'CH',
				'shipping_postcode' => '8001',
				'ip'                => 'CH',
				'lines'             => array( $this->goods() ),
			)
		);
		$this->assertFalse( $d->is_vat_exempt() );
		$this->assertSame( $rule, $d->rule() );
		$this->assertSame( Scheme::EXPORT, $d->scheme() );
		$this->assertSame( array(), $d->flags() );
		$record = $d->to_array();
		if ( null !== $vat ) {
			$this->assertSame( 'CHE123456789', $record['vat_number']['canonical'] );
		}
	}

	/**
	 * @return array<string,array{?string, string}>
	 */
	public function provide_swiss(): array {
		return array(
			'Dir. Art. 146(1)(a): CHE number recorded on an export' => array( 'CHE-123.456.789 MWST', Rule::NON_EU_NUMBER_RECORDED ),
			'Dir. Art. 146(1)(a): no number, plain export' => array( null, Rule::EXPORT_NON_EU ),
		);
	}

	public function test_unvalidated_non_eu_business_buying_a_general_service_is_charged(): void {
		$d = $this->decide(
			array(
				'vat'              => 'CHE-123.456.789 MWST',
				'status'           => ValidationResult::UNCHECKED,
				'billing_country'  => 'CH',
				'billing_postcode' => '8001',
				'ip'               => 'CH',
				'lines'            => array( $this->service() ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( Rule::B2C_SERVICE_ORIGIN, $d->rule(), 'Dir. Art. 44 needs proof of business status, else Art. 45' );
		$this->assertSame( 'MT', $d->tax_country() );
	}

	/**
	 * @dataProvider provide_mixed
	 */
	public function test_mixed_cart_lines_get_own_place_of_supply( string $label ): void {
		$d = $this->decide(
			array(
				'exceeded'          => true,
				'shipping_country'  => 'FR',
				'shipping_postcode' => '75001',
				'lines'             => array( $this->goods(), $this->digital(), $this->service() ),
			)
		);
		$this->assertCharged( $d );
		$this->assertSame( 'FR', $this->line_tax_country( $d, 'g1' ), $label );
		$this->assertSame( 'DE', $this->line_tax_country( $d, 'd1' ) );
		$this->assertSame( 'MT', $this->line_tax_country( $d, 's1' ) );
		$this->assertNull( $d->tax_country() );
		$this->assertSame( Scheme::MIXED, $d->scheme() );
		$this->assertSame( Rule::B2C_DESTINATION, $d->rule() );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_mixed(): array {
		return array( 'Dir. Art. 33(a) goods, Art. 58 + Reg. Art. 24b(d) e-book, Art. 45 consulting' => array( 'mixed' ) );
	}

	/**
	 * @dataProvider provide_digital_evidence
	 */
	public function test_digital_uses_evidence_not_shipping( string $billing, string $shipping, string $ip, string $expected, bool $conflict ): void {
		$d = $this->decide(
			array(
				'exceeded'          => true,
				'billing_country'   => $billing,
				'billing_postcode'  => '12345',
				'shipping_country'  => $shipping,
				'shipping_postcode' => '12345',
				'ip'                => $ip,
			)
		);
		$this->assertSame( $expected, $d->tax_country() );
		$this->assertSame( $conflict, $d->to_array()['evidence']['conflict'] );
	}

	/**
	 * @return array<string,array{string, string, string, string, bool}>
	 */
	public function provide_digital_evidence(): array {
		return array(
			'Reg. Art. 24b(d) + 24f(a)(b): billing and IP agree on DE, shipping FR ignored' => array( 'DE', 'FR', 'DE', 'DE', true ),
			'Reg. Art. 24b(d) + 24f: two pieces say FR over a DE IP'                         => array( 'FR', 'FR', 'DE', 'FR', true ),
			'Reg. Art. 24b(d): no two agreeing pieces, billing kept and conflict raised'      => array( 'DE', '', 'AT', 'DE', true ),
		);
	}

	public function test_single_piece_of_evidence_is_flagged(): void {
		$d = $this->decide( array( 'ip' => null ) );
		$this->assertSame( array( 'single_evidence' ), $d->flags(), 'Reg. Art. 24b(d) asks for two items of evidence' );
		$this->assertCharged( $d );
	}

	public function test_evidence_flags_skip_reverse_charged_digital_lines(): void {
		$d = $this->decide(
			array(
				'vat'    => 'DE123456789',
				'status' => ValidationResult::VALID,
				'ip'     => null,
			)
		);
		$this->assertSame( array(), $d->flags(), 'Reg. Art. 24b applies to non-taxable customers only' );
	}

	/**
	 * @dataProvider provide_self_certified
	 */
	public function test_self_certified_country_wins( string $label ): void {
		$d = $this->decide(
			array(
				'exceeded'       => true,
				'ip'             => 'FR',
				'self_certified' => 'AT',
			)
		);
		$this->assertSame( 'AT', $d->tax_country(), $label );
		$this->assertSame( array( 'evidence_conflict_self_certified' ), $d->flags() );
		$this->assertSame( 'AT', $d->to_array()['evidence']['resolved'] );
		$this->assertCharged( $d );
	}

	/**
	 * @return array<string,array{string}>
	 */
	public function provide_self_certified(): array {
		return array( 'Reg. Art. 24f(f) "other commercially relevant information" (plugin policy, not a named item)' => array( 'self-cert' ) );
	}

	public function test_decision_is_deterministic_given_same_input(): void {
		$o = array(
			'vat'               => 'DE123456789',
			'status'            => ValidationResult::VALID,
			'shipping_country'  => 'FR',
			'shipping_postcode' => '75001',
			'lines'             => array( $this->goods(), $this->digital(), $this->service() ),
		);
		$this->assertSame( $this->decide( $o )->to_array(), $this->decide( $o )->to_array() );
	}

	public function test_record_carries_inputs_and_injected_id(): void {
		$d      = $this->decide(
			array(
				'vat'    => 'de 123 456 789',
				'status' => ValidationResult::VALID,
			)
		);
		$record = $d->to_array();
		$this->assertSame( '00000000-0000-4000-8000-000000000000', $record['id'] );
		$this->assertSame( '2026-10-17T12:00:00Z', $record['at'] );
		$this->assertSame( 'checkout', $record['trigger'] );
		$this->assertSame(
			array(
				'raw'       => 'de 123 456 789',
				'canonical' => 'DE123456789',
				'prefix'    => 'DE',
				'country'   => 'DE',
			),
			$record['vat_number']
		);
		$this->assertSame( 'valid', $record['validation']['status'] );
		$this->assertSame( array( 'billing', 'shipping', 'ip', 'self_certified', 'resolved', 'conflict', 'pieces' ), array_keys( $record['evidence'] ) );
		$this->assertSame( 'charge', $record['policy']['outage'] );
		$this->assertSame( 'MT', $record['store']['country'] );
		$this->assertEquals( $d, Decision::from_array( $record ) );
	}

	public function test_exempt_role_exempts_a_consumer_with_its_own_rule(): void {
		$d = $this->decide(
			array(
				'billing_country'  => 'FR',
				'billing_postcode' => '75001',
				'lines'            => array( $this->goods(), $this->digital() ),
				'exempt_roles'     => array( 'wholesale_customer' ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Rule::ROLE_EXEMPT, $d->rule() );
		$this->assertSame( Scheme::ROLE_EXEMPT, $d->scheme() );
		$this->assertSame( array(), $d->legal_text() );
		$this->assertNull( $d->blocking_error() );
		$this->assertSame( array( 'wholesale_customer' ), $d->to_array()['customer']['exempt_roles'] );
		$this->assertEquals( $d, Decision::from_array( $d->to_array() ) );
	}

	public function test_without_an_exempt_role_nothing_changes_and_the_record_has_no_roles(): void {
		$d = $this->decide( array( 'billing_country' => 'FR' ) );
		$this->assertCharged( $d );
		$this->assertArrayNotHasKey( 'exempt_roles', $d->to_array()['customer'] );
	}

	public function test_a_confirmed_vat_number_keeps_its_reverse_charge_over_the_role(): void {
		$d = $this->decide(
			array(
				'vat'          => 'DE123456789',
				'status'       => ValidationResult::VALID,
				'exempt_roles' => array( 'wholesale_customer' ),
			)
		);
		$this->assertSame( Rule::RC_SERVICES_ART196, $d->rule() );
		$this->assertSame( array( 'art196' ), $d->legal_text() );
	}

	public function test_an_invalid_number_does_not_block_a_role_exempt_customer(): void {
		$d = $this->decide(
			array(
				'vat'          => 'DE123456789',
				'status'       => ValidationResult::INVALID,
				'exempt_roles' => array( 'wholesale_customer' ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Rule::ROLE_EXEMPT, $d->rule() );
		$this->assertNull( $d->blocking_error() );
		$this->assertSame( array(), $d->flags() );
	}

	public function test_role_exemption_leaves_exports_as_they_are(): void {
		$d = $this->decide(
			array(
				'billing_country' => 'US',
				'lines'           => array( $this->goods() ),
				'exempt_roles'    => array( 'wholesale_customer' ),
			)
		);
		$this->assertSame( Rule::EXPORT_NON_EU, $d->rule() );
		$this->assertSame( Scheme::EXPORT, $d->scheme() );
	}

	public function test_role_exemption_covers_every_taxable_line_of_a_mixed_cart(): void {
		$d = $this->decide(
			array(
				'oss'               => true,
				'billing_country'   => 'DE',
				'shipping_country'  => 'DE',
				'shipping_postcode' => '10115',
				'lines'             => array( $this->goods(), $this->service() ),
				'exempt_roles'      => array( 'reseller' ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Scheme::ROLE_EXEMPT, $d->scheme() );
		$this->assertSame( 'DE', $this->line_tax_country( $d, 'g1' ) );
		$this->assertSame( 'MT', $this->line_tax_country( $d, 's1' ) );
	}

	public function test_a_domestic_sale_to_a_role_exempt_customer_is_exempt(): void {
		$d = $this->decide(
			array(
				'billing_country' => 'MT',
				'lines'           => array( $this->goods() ),
				'exempt_roles'    => array( 'reseller' ),
			)
		);
		$this->assertTrue( $d->is_vat_exempt() );
		$this->assertSame( Rule::ROLE_EXEMPT, $d->rule() );
	}

	public function test_default_id_is_a_uuid4(): void {
		$id = ( new Rules() )->decide( $this->input() )->id();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
	}

	public function test_empty_cart_is_not_exempt(): void {
		$d = $this->decide(
			array(
				'vat'    => 'DE123456789',
				'status' => ValidationResult::VALID,
				'lines'  => array(),
			)
		);
		$this->assertCharged( $d );
	}

	/**
	 * Principle 1 as a property: across random inputs, the customer is only
	 * exempted by a reverse-charge or opted-in rule, and only when the number
	 * was confirmed or the merchant chose to exempt while the registry was out.
	 */
	public function test_only_listed_rules_can_exempt(): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- a seeded mt_rand keeps failures reproducible.
		mt_srand( 20261017 );
		$pick = static function ( array $options ) {
			return $options[ mt_rand( 0, count( $options ) - 1 ) ];
		};

		$countries = array( 'MT', 'DE', 'FR', 'ES', 'GR', 'MC', 'GB', 'CH', 'NO', 'US', '' );
		$postcodes = array( '', '10115', '35001', '38', '97100', '98000', 'BT1 1AA', 'SW1A 1AA', '27498' );
		$numbers   = array( null, 'DE123456789', 'MT12345678', 'FR40303265045', 'EL123456789', 'XI123456789', 'GB123456789', 'CHE123456789', 'NO123456789MVA', 'garbage' );
		$statuses  = array( null, ValidationResult::VALID, ValidationResult::VALID, ValidationResult::VALID, ValidationResult::INVALID, ValidationResult::UNAVAILABLE, ValidationResult::UNCHECKED, ValidationResult::FORMAT_INVALID );
		$types     = array( Line::GOODS, Line::DIGITAL, Line::SERVICE );

		$number_country = array(
			'DE' => 'DE',
			'MT' => 'MT',
			'FR' => 'FR',
			'EL' => 'GR',
			'XI' => 'GB',
			'GB' => 'GB',
			'CH' => 'CH',
			'NO' => 'NO',
			'ga' => 'US',
		);

		$exempted = 0;
		for ( $i = 0; $i < 500; $i++ ) {
			$lines = array();
			$count = mt_rand( 1, 3 );
			for ( $l = 0; $l < $count; $l++ ) {
				$type    = $pick( $types );
				$lines[] = new Line( "l$l", $type, Line::GOODS === $type );
			}
			$status  = $pick( $statuses );
			$vat     = $pick( $numbers );
			$billing = $pick( $countries );
			if ( null !== $vat && mt_rand( 0, 1 ) ) {
				$billing = $number_country[ substr( $vat, 0, 2 ) ];
			}
			$d = $this->decide(
				array(
					'store'             => $pick( array( 'MT', 'DE' ) ),
					'oss'               => (bool) mt_rand( 0, 1 ),
					'exceeded'          => (bool) mt_rand( 0, 1 ),
					'policy'            => $pick( array( 'charge', 'exempt_pending', 'reject' ) ),
					'allow_domestic'    => (bool) mt_rand( 0, 1 ),
					'billing_country'   => $billing,
					'billing_postcode'  => $pick( $postcodes ),
					'shipping_country'  => $pick( $countries ),
					'shipping_postcode' => $pick( $postcodes ),
					'pickup'            => 0 === mt_rand( 0, 3 ),
					'vat'               => $vat,
					'status'            => $status,
					'ip'                => $pick( array( null, 'DE', 'FR', 'MT', 'US' ) ),
					'self_certified'    => $pick( array( null, null, 'AT', 'DE' ) ),
					'lines'             => $lines,
					'trigger'           => $pick( array( 'cart', 'checkout', 'admin', 'renewal', 'revalidation', 'backfill' ) ),
				)
			);

			if ( ! $d->is_vat_exempt() ) {
				continue;
			}
			++$exempted;
			$this->assertContains( $d->rule(), self::EXEMPT_RULES, "input #$i" );
			$this->assertNull( $d->blocking_error(), "input #$i" );
			$this->assertNotContains( $status, array( ValidationResult::INVALID, ValidationResult::FORMAT_INVALID ), "input #$i" );
			if ( ValidationResult::VALID !== $status ) {
				$this->assertSame( Rule::PENDING_EXEMPT, $d->rule(), "input #$i" );
				$this->assertSame( 'exempt_pending', $d->to_array()['policy']['outage'], "input #$i" );
			}
		}
		// phpcs:enable
		$this->assertGreaterThan( 25, $exempted, 'the generator must reach exempt decisions or the property proves nothing' );
	}

	/**
	 * @dataProvider provide_location_confirmation
	 * @param array<string,mixed> $o
	 */
	public function test_location_confirmation_is_needed_only_where_evidence_conflicts( array $o, bool $needed, string $label ): void {
		$this->assertSame( $needed, $this->decide( $o )->needs_location_confirmation(), $label );
	}

	/**
	 * @return array<string,array{array<string,mixed>,bool,string}>
	 */
	public function provide_location_confirmation(): array {
		$b2b = array(
			'vat'    => 'DE114103379',
			'status' => ValidationResult::VALID,
		);
		return array(
			'digital, billing and IP disagree'    => array( array( 'ip' => 'FR' ), true, 'Reg. Art. 24f: digital B2C needs two matching pieces' ),
			'digital, IP agrees'                  => array( array( 'ip' => 'DE' ), false, 'no conflict' ),
			'digital, no IP source'               => array( array( 'ip' => null ), false, 'nothing to disagree with' ),
			'digital, already self-certified'     => array(
				array(
					'ip'             => 'FR',
					'self_certified' => 'DE',
				),
				false,
				'confirmation given',
			),
			'goods only'                          => array(
				array(
					'ip'    => 'FR',
					'lines' => array( $this->goods() ),
				),
				false,
				'goods use the delivery address',
			),
			'service only'                        => array(
				array(
					'ip'    => 'FR',
					'lines' => array( $this->service() ),
				),
				false,
				'services follow the customer, not evidence',
			),
			'digital with goods'                  => array(
				array(
					'ip'    => 'FR',
					'lines' => array( $this->goods(), $this->digital() ),
				),
				true,
				'the digital line needs it',
			),
			'valid B2B digital'                   => array( array( 'ip' => 'FR' ) + $b2b, false, 'exempt business' ),
			'B2B number the registry turned down' => array(
				array(
					'ip'     => 'FR',
					'vat'    => 'DE123475223',
					'status' => ValidationResult::INVALID,
				),
				false,
				'the invalid number blocks on its own',
			),
		);
	}
}
