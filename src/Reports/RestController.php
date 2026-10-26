<?php

namespace Vatright\Reports;

use Vatright\Ledger\LedgerWriter;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class RestController {

	const NAMESPACE_V1 = 'vatright/v1';
	const LOG_META     = '_vatright_log';

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	/** @var IntrastatReport */
	private $intrastat;

	public function __construct( OssReport $oss, EcSalesList $esl, IntrastatReport $intrastat ) {
		$this->oss       = $oss;
		$this->esl       = $esl;
		$this->intrastat = $intrastat;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$period = array(
			'year'    => array(
				'type'    => 'integer',
				'minimum' => 2000,
				'maximum' => 2100,
			),
			'quarter' => array(
				'type' => 'integer',
				'enum' => array( 1, 2, 3, 4 ),
			),
			'month'   => array(
				'type' => 'integer',
				'enum' => array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 ),
			),
			'from'    => array(
				'type'    => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			),
			'to'      => array(
				'type'    => 'string',
				'pattern' => '^\d{4}-\d{2}-\d{2}$',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/oss',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'oss' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => array_merge(
					$period,
					array(
						'currency' => array(
							'type'    => 'string',
							'default' => 'EUR',
							'pattern' => '^[A-Za-z]{3}$',
						),
					)
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/ec-sales-list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ec_sales_list' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => $period,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/reports/intrastat',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'intrastat' ),
				'permission_callback' => array( $this, 'can_view_reports' ),
				'args'                => array(
					'year'  => array_merge( $period['year'], array( 'required' => true ) ),
					'month' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
						'maximum'  => 12,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/orders/(?P<id>\d+)/vat',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'order_vat' ),
				'permission_callback' => array( $this, 'can_read_order' ),
				'args'                => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
	}

	public function can_view_reports(): bool {
		return current_user_can( ReportsScreen::CAPABILITY );
	}

	public function can_read_order( WP_REST_Request $request ): bool {
		return wc_rest_check_post_permissions( 'shop_order', 'read', (int) $request['id'] );
	}

	/**
	 * Either from and to, or year and month, or year and quarter, in that order of precedence.
	 *
	 * @return ReportPeriod|WP_Error
	 */
	private function period( WP_REST_Request $request ) {
		$period = ReportPeriod::from_args( $request->get_params() );
		if ( null === $period ) {
			return new WP_Error(
				'vatright_bad_period',
				__( 'Give a report period: from and to dates, or year with month, or year with quarter.', 'vatright' ),
				array( 'status' => 400 )
			);
		}
		return $period;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function oss( WP_REST_Request $request ) {
		$period = $this->period( $request );
		if ( $period instanceof WP_Error ) {
			return $period;
		}
		try {
			return rest_ensure_response( $this->oss->build_for( $period, (string) $request['currency'] ) );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'vatright_rate_unavailable', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function ec_sales_list( WP_REST_Request $request ) {
		$period = $this->period( $request );
		if ( $period instanceof WP_Error ) {
			return $period;
		}
		try {
			$rows = $this->esl->build_for( $period );
		} catch ( IncompleteReport $e ) {
			return new WP_Error(
				'vatright_incomplete_report',
				$e->getMessage(),
				array(
					'status'    => 502,
					'order_ids' => $e->order_ids(),
				)
			);
		}
		return rest_ensure_response(
			array(
				'year'    => $period->year(),
				'quarter' => $period->quarter_number(),
				'period'  => $period->label(),
				'kind'    => $period->kind(),
				'from'    => $period->start(),
				'to'      => $period->end(),
				'rows'    => $rows,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function intrastat( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( $this->intrastat->build( (int) $request['year'], (int) $request['month'] ) );
		} catch ( IncompleteReport $e ) {
			return new WP_Error(
				'vatright_incomplete_report',
				$e->getMessage(),
				array(
					'status'    => 502,
					'order_ids' => $e->order_ids(),
				)
			);
		}
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function order_vat( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order instanceof \WC_Order ) {
			return new WP_Error( 'vatright_no_order', __( 'Order not found.', 'vatright' ), array( 'status' => 404 ) );
		}

		$entries = $order->get_meta( self::LOG_META, false );
		usort(
			$entries,
			static function ( $a, $b ): int {
				return $a->id <=> $b->id;
			}
		);
		$log = array();
		foreach ( $entries as $entry ) {
			$log[] = json_decode( (string) $entry->value, true );
		}

		return rest_ensure_response(
			array(
				'order_id' => $order->get_id(),
				'decision' => json_decode( (string) $order->get_meta( LedgerWriter::DECISION_META ), true ),
				'log'      => $log,
			)
		);
	}
}
