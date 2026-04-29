<?php
/**
 * Fires when the plugin is deleted via the WordPress Plugins screen.
 * Cleans up every per-sub-gateway settings option. Order data stays in WooCommerce.
 *
 * @package Peptide_Pay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Aligned with the live PayGate provider set served by /api/v1/providers
// (see includes/providers.php).
$gateway_ids = array(
	'peptide_pay_gateway',
	'peptide_pay_moonpay',
	'peptide_pay_revolut',
	'peptide_pay_cryptocom',
	'peptide_pay_binance',
	'peptide_pay_banxa',
	'peptide_pay_transak',
	'peptide_pay_rampnetwork',
	'peptide_pay_sardine',
	'peptide_pay_bitnovo',
	'peptide_pay_simplex',
	'peptide_pay_alchemypay',
	'peptide_pay_interac',
	'peptide_pay_upi',
);

// Legacy IDs from <2.1.0 that referenced provider rails PayGate has since
// retired or never billed under the same code. Kept here so a merchant
// upgrading from 2.0.x (which had stripe/utorg/topper/transfi/paypal/
// robinhood) still gets a clean uninstall.
$legacy_gateway_ids = array(
	'peptide_pay_stripe',
	'peptide_pay_utorg',
	'peptide_pay_topper',
	'peptide_pay_transfi',
	'peptide_pay_paypal',
	'peptide_pay_robinhood',
);

foreach ( array_merge( $gateway_ids, $legacy_gateway_ids ) as $id ) {
	delete_option( 'woocommerce_' . $id . '_settings' );
}

// Legacy single-gateway option from v1.x.
delete_option( 'woocommerce_peptide_pay_settings' );
delete_option( 'peptide_pay_webhook_secret' );
delete_option( 'peptide_pay_version' );

// 2.1.0+ live-provider transient cache.
delete_transient( 'peptide_pay_live_provider_codes' );
