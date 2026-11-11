<?php

namespace Vatright\Orders;

use Vatright\Domain\Decision;
use Vatright\Domain\Scheme;
use Vatright\Domain\ValidationResult;
use Vatright\Domain\VatNumber;
use Vatright\Registry\Validator;
use Vatright\Settings;
use Throwable;
use WC_Order;

/**
 * Settles orders placed while the registry was down. A later answer never
 * changes the order's totals: the merchant is told and decides on a refund or
 * a corrected invoice.
 */
class Revalidation {

	const HOOK = 'vatright_revalidate';

	const GROUP = 'vatright';

	const PENDING_FLAG = 'validation_pending';

	/**
	 * Wait before attempt n (1-based), counted from the previous attempt, or
	 * from the order for the first one.
	 */
	const DELAYS = array( 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, 3 * DAY_IN_SECONDS );

	const VALID       = 'valid';
	const INVALID     = 'invalid';
	const UNAVAILABLE = 'unavailable';
	const NONE        = 'none';

	/** @var Validator */
	private $validator;

	/** @var array<int,string> */
	private $held_emails = array();

	public function __construct( Validator $validator ) {
		$this->validator = $validator;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'schedule_classic' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'schedule' ) );
		add_filter( 'woocommerce_email_classes', array( $this, 'add_email' ) );
		if ( class_exists( 'WC_Subscriptions' ) ) {
			add_filter( 'wcs_renewal_order_created', array( $this, 'schedule_renewal' ), 20 );
		}
	}

	/**
	 * @param array<string,object> $emails
	 * @return array<string,object>
	 */
	public function add_email( $emails ) {
		$emails[ RevalidatedEmail::KEY ] = new RevalidatedEmail();
		return $emails;
	}

	/**
	 * @param int|string $order_id
	 */
	public function schedule_classic( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$this->schedule( $order );
		}
	}

	/**
	 * Runs after the renewal's own decision is stored (priority 10).
	 *
	 * @param mixed $renewal
	 * @return mixed
	 */
	public function schedule_renewal( $renewal ) {
		if ( $renewal instanceof WC_Order ) {
			$this->schedule( $renewal );
		}
		return $renewal;
	}

	public function schedule( WC_Order $order ): void {
		$decision = DecisionStore::get( $order );
		if ( null === $decision || ! in_array( self::PENDING_FLAG, $decision->flags(), true ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, array( $order->get_id(), 1 ), self::GROUP ) ) {
			as_schedule_single_action( time() + self::DELAYS[0], self::HOOK, array( $order->get_id(), 1 ), self::GROUP );
		}
	}

	public function run( int $order_id, int $attempt ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$decision = DecisionStore::get( $order );
		if ( null === $decision || ! in_array( self::PENDING_FLAG, $decision->flags(), true ) ) {
			return;
		}

		if ( self::UNAVAILABLE !== $this->recheck( $order, 'revalidation' ) ) {
			return;
		}
		if ( isset( self::DELAYS[ $attempt ] ) ) {
			as_schedule_single_action( time() + self::DELAYS[ $attempt ], self::HOOK, array( $order_id, $attempt + 1 ), self::GROUP );
			return;
		}
		$order->add_order_note( __( 'The VAT number could not be checked after repeated attempts. Check it by hand from the EU VAT box.', 'vatright' ) );
	}

	/**
	 * Asks the registry again, bypassing the cache, and settles the order's
	 * record. Returns what the registry said.
	 */
	public function recheck( WC_Order $order, string $trigger, int $actor = 0, bool $hold_emails = false ): string {
		$decision = DecisionStore::get( $order );
		if ( null === $decision ) {
			return self::NONE;
		}
		$canonical = (string) ( $decision->to_array()['vat_number']['canonical'] ?? '' );
		$number    = '' === $canonical ? null : VatNumber::parse( $canonical, '' );
		if ( null === $number ) {
			return self::NONE;
		}

		try {
			$result = $this->validator->validate( $number, true, true );
		} catch ( Throwable $e ) {
			wc_get_logger()->error( 'VAT revalidation failed: ' . $e->getMessage(), array( 'source' => 'vatright' ) );
			return self::UNAVAILABLE;
		}

		$status = $result->status();
		if ( ValidationResult::VALID === $status ) {
			$this->settle_valid( $order, $decision, $result, $trigger, $actor, $hold_emails );
			return self::VALID;
		}
		if ( ValidationResult::INVALID === $status || ValidationResult::FORMAT_INVALID === $status ) {
			$this->settle_invalid( $order, $decision, $result, $trigger, $actor, $hold_emails );
			return self::INVALID;
		}

		$order->add_order_note( __( 'VAT number re-check: the registry is still unavailable.', 'vatright' ) );
		return self::UNAVAILABLE;
	}

	private function settle_valid( WC_Order $order, Decision $decision, ValidationResult $result, string $trigger, int $actor, bool $hold_email ): void {
		$refund_due = in_array( self::PENDING_FLAG, $decision->flags(), true ) && ! $decision->is_vat_exempt();
		DecisionStore::apply( $order, $this->patched( $decision, $result, $trigger, $refund_due ? 'validated_after_outage' : null ), $trigger, $actor );
		$order->save();

		if ( ! $refund_due ) {
			$order->add_order_note( __( 'VAT number re-check: the registry confirms the number is valid.', 'vatright' ) );
			return;
		}
		$order->add_order_note( __( 'VAT was charged because the registry was unavailable. The number is valid, so the customer may be due a refund of the VAT.', 'vatright' ) );
		$this->notify( $order, RevalidatedEmail::REFUND, $hold_email );
	}

	private function settle_invalid( WC_Order $order, Decision $decision, ValidationResult $result, string $trigger, int $actor, bool $hold_email ): void {
		$unfounded = $this->is_zero_rated( $decision );
		DecisionStore::apply( $order, $this->patched( $decision, $result, $trigger, $unfounded ? 'invalid_after_exemption' : null ), $trigger, $actor );
		$order->save();

		if ( ! $unfounded ) {
			$order->add_order_note( __( 'VAT number re-check: the registry reports the number as invalid. VAT stays charged.', 'vatright' ) );
			return;
		}
		$order->add_order_note( __( 'VAT was not charged on this order, but the registry reports the VAT number as invalid. VAT may be due.', 'vatright' ) );
		$this->notify( $order, RevalidatedEmail::INVALID, $hold_email );
	}

	private function is_zero_rated( Decision $decision ): bool {
		if ( $decision->is_vat_exempt() ) {
			return true;
		}
		foreach ( $decision->lines() as $line ) {
			if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The same tax outcome with the registry's answer recorded, as a new
	 * decision so the log shows the change.
	 */
	private function patched( Decision $decision, ValidationResult $result, string $trigger, ?string $flag ): Decision {
		$record               = $decision->to_array();
		$record['id']         = wp_generate_uuid4();
		$record['at']         = gmdate( 'Y-m-d\TH:i:s\Z' );
		$record['trigger']    = $trigger;
		$record['validation'] = $result->to_array();
		$record['flags']      = array_values( array_diff( $record['flags'], array( self::PENDING_FLAG ) ) );
		if ( null !== $flag && ! in_array( $flag, $record['flags'], true ) ) {
			$record['flags'][] = $flag;
		}
		return Decision::from_array( $record );
	}

	/**
	 * Sends the emails a batch of re-checks held back, once the whole batch
	 * has settled.
	 */
	public function send_held_emails(): void {
		$held              = $this->held_emails;
		$this->held_emails = array();
		foreach ( $held as $order_id => $kind ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$this->notify( $order, $kind, false );
			}
		}
	}

	private function notify( WC_Order $order, string $kind, bool $hold ): void {
		if ( ! Settings::get()->notify_admin_on_revalidation() ) {
			return;
		}
		if ( $hold ) {
			$this->held_emails[ $order->get_id() ] = $kind;
			return;
		}
		$email = WC()->mailer()->get_emails()[ RevalidatedEmail::KEY ] ?? null;
		if ( $email instanceof RevalidatedEmail ) {
			$email->trigger( $order->get_id(), $kind );
		}
	}
}
