<?php

namespace Vatright\Reports;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The stretch of sales a report covers, in the store's local days. A quarter
 * keeps the label the ledger stores; a month or a custom range is found by
 * the date of the sale each row belongs to.
 */
final class ReportPeriod {

	const QUARTER = 'quarter';
	const MONTH   = 'month';
	const RANGE   = 'range';

	/** @var string */
	private $kind;

	/** @var string */
	private $start;

	/** @var string */
	private $end;

	/** @var int|null */
	private $quarter;

	private function __construct( string $kind, string $start, string $end, ?int $quarter = null ) {
		$this->kind    = $kind;
		$this->start   = $start;
		$this->end     = $end;
		$this->quarter = $quarter;
	}

	public static function quarter( int $year, int $quarter ): self {
		$first = sprintf( '%04d-%02d-01', $year, ( $quarter - 1 ) * 3 + 1 );
		$last  = ( new DateTimeImmutable( $first ) )->modify( '+2 months' )->modify( 'last day of this month' )->format( 'Y-m-d' );
		return new self( self::QUARTER, $first, $last, $quarter );
	}

	public static function month( int $year, int $month ): self {
		$first = sprintf( '%04d-%02d-01', $year, $month );
		$last  = ( new DateTimeImmutable( $first ) )->modify( 'last day of this month' )->format( 'Y-m-d' );
		return new self( self::MONTH, $first, $last );
	}

	/**
	 * @param string $from First day, Y-m-d.
	 * @param string $to   Last day, Y-m-d, on or after $from.
	 */
	public static function range( string $from, string $to ): ?self {
		if ( ! self::is_date( $from ) || ! self::is_date( $to ) || $from > $to ) {
			return null;
		}
		return new self( self::RANGE, $from, $to );
	}

	public static function from_label( string $label ): ?self {
		if ( 1 === preg_match( '/^(\d{4})-Q([1-4])$/', $label, $m ) ) {
			return self::quarter( (int) $m[1], (int) $m[2] );
		}
		if ( 1 === preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $label, $m ) ) {
			return self::month( (int) $m[1], (int) $m[2] );
		}
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $label, $m ) ) {
			return self::range( $m[1], $m[2] );
		}
		return null;
	}

	/**
	 * A date range wins over a month, which wins over a quarter.
	 *
	 * @param array<string,mixed> $args Request arguments: from and to, or year and month, or year and quarter.
	 */
	public static function from_args( array $args ): ?self {
		$text = static function ( string $key ) use ( $args ): string {
			return isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ? trim( (string) $args[ $key ] ) : '';
		};

		if ( '' !== $text( 'from' ) || '' !== $text( 'to' ) ) {
			return self::range( $text( 'from' ), $text( 'to' ) );
		}
		$year = (int) $text( 'year' );
		if ( $year < 2000 || $year > 2100 ) {
			return null;
		}
		if ( '' !== $text( 'month' ) ) {
			$month = (int) $text( 'month' );
			return $month >= 1 && $month <= 12 ? self::month( $year, $month ) : null;
		}
		$quarter = (int) $text( 'quarter' );
		return $quarter >= 1 && $quarter <= 4 ? self::quarter( $year, $quarter ) : null;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function quarter_number(): ?int {
		return $this->quarter;
	}

	public function year(): int {
		return (int) substr( $this->start, 0, 4 );
	}

	public function start(): string {
		return $this->start;
	}

	public function end(): string {
		return $this->end;
	}

	public function label(): string {
		switch ( $this->kind ) {
			case self::QUARTER:
				return $this->year() . '-Q' . $this->quarter;
			case self::MONTH:
				return substr( $this->start, 0, 7 );
			default:
				return $this->start . '_' . $this->end;
		}
	}

	public function slug(): string {
		switch ( $this->kind ) {
			case self::QUARTER:
				return strtolower( $this->label() );
			case self::MONTH:
				return $this->label();
			default:
				return $this->start . '-to-' . $this->end;
		}
	}

	/**
	 * The first moment of the period and the first moment after it, as UTC
	 * datetimes the ledger's supply_at column compares against.
	 *
	 * @return array{0:string,1:string}
	 */
	public function utc_bounds( DateTimeZone $local ): array {
		$utc   = new DateTimeZone( 'UTC' );
		$from  = new DateTimeImmutable( $this->start . ' 00:00:00', $local );
		$until = ( new DateTimeImmutable( $this->end . ' 00:00:00', $local ) )->modify( '+1 day' );
		return array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $until->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * The ledger rows that belong to sales made in this period: every entry
	 * of an order whose sale falls in it, so a refund books against the sale
	 * it changes whenever the refund itself was made.
	 *
	 * @return array{0:string,1:array<int,string>} SQL with %s placeholders, and their values.
	 */
	public function sale_condition( string $table, DateTimeZone $local ): array {
		if ( self::QUARTER === $this->kind ) {
			return array( 'relates_to_period = %s', array( $this->label() ) );
		}
		list( $from, $until ) = $this->utc_bounds( $local );
		return array(
			"order_id IN (SELECT order_id FROM {$table} WHERE entry_type = 'sale' AND supply_at >= %s AND supply_at < %s)",
			array( $from, $until ),
		);
	}

	private static function is_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}
}
