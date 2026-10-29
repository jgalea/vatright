<?php

namespace Vatright;

class Plugin {

	/** @var Checkout\CartSteering|null */
	private static $cart_steering = null;

	public static function cart_steering(): ?Checkout\CartSteering {
		return self::$cart_steering;
	}

	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_woocommerce_notice' ) );
			return;
		}

		Ledger\Schema::upgrade();

		$transport = new Registry\WpHttpTransport();
		$vies      = new Registry\Vies( $transport );
		$sync      = new Rates\RateSync( new Rates\Tedb( $transport ) );
		$screen    = new Rates\RatesScreen( $sync );
		$sync->register();
		$screen->register();

		$section = new Admin\SettingsSection(
			new Admin\SetupCheck( array( $vies, 'status' ) ),
			array( $screen, 'render' ),
			static function ( Domain\VatNumber $number ) use ( $vies ) {
				return $vies->supports( $number ) ? $vies->check( $number, null ) : null;
			},
			new Admin\RateMappingEditor( $sync )
		);
		$section->register();

		$settings  = Settings::get();
		$cache     = new Registry\ValidationCache( $settings );
		$validator = new Registry\Validator(
			new Registry\Router(
				$vies,
				new Registry\Hmrc( $transport, $settings->hmrc_client_id(), $settings->hmrc_client_secret() ),
				new Registry\SwissUid( $transport ),
				new Registry\Brreg( $transport )
			),
			$cache,
			$settings
		);

		( new Privacy\Privacy( $cache ) )->register();
		( new Privacy\Retention() )->register();

		$fx     = new Ledger\Fx( $transport );
		$ledger = new Ledger\LedgerWriter( $fx );
		$ledger->register();
		( new Ledger\Backfill( $ledger ) )->register();

		$oss = new Reports\OssReport( $fx );
		$esl = new Reports\EcSalesList();
		$ira = new Reports\IntrastatReport();
		( new Reports\ReportsScreen( $oss, $esl, $ira ) )->register();
		( new Reports\RestController( $oss, $esl, $ira ) )->register();

		$gatherer = new Checkout\InputGatherer( $validator, new Ledger\ThresholdTracker(), $fx );
		$steering = new Checkout\CartSteering( $gatherer, new Domain\Rules() );
		$steering->register();
		$field = new Checkout\VatField( $validator );
		$field->register();
		( new Checkout\StoreApiExtension( $field ) )->register();
		( new Account\RegistrationField( $validator ) )->register();
		( new Admin\ProductSupplyField() )->register();
		( new Admin\ProductIntrastatFields() )->register();
		( new Checkout\CheckoutDecider( $steering ) )->register();
		( new Orders\OrderSteering( $steering ) )->register();

		$revalidation = new Orders\Revalidation( $validator );
		$revalidation->register();
		( new Orders\AdminRecalc( $gatherer, new Domain\Rules(), $steering ) )->register();
		( new Orders\MetaBox( $revalidation ) )->register();
		( new Orders\InvoiceText() )->register();
		( new Orders\BillingAddress() )->register();
		( new Orders\OrdersColumn() )->register();
		( new Ledger\ThresholdNotifier( new Ledger\ThresholdTracker() ) )->register();
		( new Integrations\Subscriptions( $gatherer, new Domain\Rules() ) )->register();
		$importer = new Migration\Importer();
		$importer->register();
		( new Migration\ImportScreen( $importer ) )->register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'vatright import', new Migration\Cli( $importer ) );
		}
		self::$cart_steering = $steering;
	}

	public static function missing_woocommerce_notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'VAT Right requires WooCommerce to be active.', 'vatright' ) . '</p></div>';
	}
}
