<?php

namespace Vatright\Orders;

use Vatright\Domain\Decision;
use WC_Meta_Data;
use WC_Order;

final class DecisionStore {

	const META = '_vatright_decision';

	const LOG_META = '_vatright_log';

	const NUMBER_META = '_vatright_number';

	const REQUIRED_KEYS = array( 'id', 'at', 'trigger', 'store', 'customer', 'vat_number', 'validation', 'evidence', 'policy', 'lines', 'scheme', 'rule', 'vat_exempt', 'flags', 'legal_text', 'blocking_error' );

	/**
	 * A stored record from another schema version, a partial import or a
	 * hand edit reads as no decision rather than breaking the screen that
	 * shows it.
	 */
	public static function get( WC_Order $order ): ?Decision {
		$data = json_decode( (string) $order->get_meta( self::META ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		try {
			if ( array() !== array_diff( self::REQUIRED_KEYS, array_keys( $data ) ) ) {
				throw new \UnexpectedValueException( 'missing keys' );
			}
			return Decision::from_array( $data );
		} catch ( \Throwable $e ) {
			wc_get_logger()->warning( 'Ignored an unreadable VAT decision on order ' . $order->get_id() . ': ' . $e->getMessage(), array( 'source' => 'vatright' ) );
			return null;
		}
	}

	/**
	 * Writes through the order CRUD API; the caller saves the order. Applying
	 * the decision that is already stored adds no log entry.
	 */
	public static function apply( WC_Order $order, Decision $decision, string $trigger, int $actor = 0 ): void {
		$previous = self::get( $order );
		$record   = $decision->to_array();

		$order->update_meta_data( self::META, (string) wp_json_encode( $record ) );
		$order->update_meta_data( 'is_vat_exempt', $decision->is_vat_exempt() ? 'yes' : 'no' );
		if ( '' !== (string) ( $record['vat_number']['canonical'] ?? '' ) ) {
			$order->update_meta_data( self::NUMBER_META, $record['vat_number']['canonical'] );
		}
		CompatKeys::write( $order, $decision );

		if ( null !== $previous && $previous->id() === $decision->id() ) {
			return;
		}
		$order->add_meta_data(
			self::LOG_META,
			(string) wp_json_encode(
				array(
					'at'            => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'trigger'       => $trigger,
					'decision_id'   => $decision->id(),
					'previous_id'   => null === $previous ? null : $previous->id(),
					'scheme'        => $decision->scheme(),
					'rule'          => $decision->rule(),
					'flags'         => $decision->flags(),
					'actor_user_id' => $actor,
				)
			)
		);
	}

	/**
	 * Saved rows come back in storage order, so they are sorted by meta id;
	 * rows added since the last save have no id yet and go last.
	 *
	 * @return array<int,array<string,mixed>> Oldest first.
	 */
	public static function log( WC_Order $order ): array {
		$rows = array();
		foreach ( (array) $order->get_meta( self::LOG_META, false ) as $position => $meta ) {
			if ( ! $meta instanceof WC_Meta_Data ) {
				continue;
			}
			$data  = $meta->get_data();
			$entry = json_decode( (string) $data['value'], true );
			if ( is_array( $entry ) ) {
				$rows[] = array( empty( $data['id'] ) ? PHP_INT_MAX : (int) $data['id'], (int) $position, $entry );
			}
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a[0], $a[1] ) <=> array( $b[0], $b[1] );
			}
		);
		return array_column( $rows, 2 );
	}
}
