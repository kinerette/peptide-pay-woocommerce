<?php
/**
 * Plugin Name: Peptide-Pay for WooCommerce
 * Plugin URI: https://peptide-pay.com/download/woocommerce
 * Description: Accept Apple Pay, Google Pay, cards, Revolut + 11 more rails on your WooCommerce store — designed for peptides, nutra, and other high-risk merchants. 14 payment gateways in one plugin, kept in sync with the live PayGate provider list. Instant USDC payouts.
 * Version: 2.6.4
 * Author: Peptide-Pay
 * Author URI: https://peptide-pay.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: peptide-pay
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.6
 *
 * @package Peptide_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p>Peptide-Pay requires PHP 7.4 or higher.</p></div>';
	} );
	return;
}

define( 'PEPTIDE_PAY_VERSION', '2.6.4' );
define( 'PEPTIDE_PAY_FILE', __FILE__ );
define( 'PEPTIDE_PAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'PEPTIDE_PAY_URL', plugin_dir_url( __FILE__ ) );
define( 'PEPTIDE_PAY_API_BASE', 'https://www.peptide-pay.com/api/v1' );

// Transient + cron names for the live-provider sync. The 24h cadence
// matches what the homepage's /api/v1/providers cache lives behind on
// the server side, so we never ship a list staler than what the API
// would have served anyway.
define( 'PEPTIDE_PAY_LIVE_TRANSIENT', 'peptide_pay_live_provider_codes' );
define( 'PEPTIDE_PAY_LIVE_CRON_HOOK', 'peptide_pay_refresh_live_providers' );

// Default-off: WC → Settings → Payments shows only the "Smart"
// (recommended) row for fresh installs, hiding the 13 individual
// provider rails behind a toggle. Solves the "18-row wall" friction
// from the Pierre persona audit (2026-04-27): non-tech merchants
// either enabled all or froze, neither is the right outcome. Existing
// 2.0.x installs that already saved per-sub-gateway settings get the
// "show all" default flipped on automatically (see
// peptide_pay_default_show_individual_gateways below) so they don't
// silently lose what they configured.
define( 'PEPTIDE_PAY_SHOW_INDIVIDUAL_OPTION', 'peptide_pay_show_individual_gateways' );

/**
 * Declare HPOS (High-Performance Order Storage) + Cart/Checkout Blocks compat.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			PEPTIDE_PAY_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			PEPTIDE_PAY_FILE,
			true
		);
	}
} );

/**
 * Master class → provider_code map. Single source of truth for both the
 * gateway-registration filter and the WC Blocks registration loop.
 *
 * This MUST mirror includes/providers.php — when you add or remove a
 * sub-gateway class, also update this list. The dynamic live-provider
 * filter further narrows this set down to whatever the live API
 * currently returns, so a class staying here while PayGate retires the
 * upstream provider is non-fatal (the filter strips it).
 *
 * @return array<string,string> class name → provider_code
 */
function peptide_pay_class_to_code() {
	// Providers removed from this map because they are in PROVIDER_BLACKLIST
	// on the peptide-pay server side and will never appear in the live API
	// response. Keeping them here caused a dangerous fallback: when the live
	// API was unreachable the plugin registered ALL local classes, making dead
	// providers (binance, revolut, rampnetwork, sardine, alchemypay) visible
	// to customers at checkout.
	//
	// Removed (server-side PROVIDER_BLACKLIST — see paygate.ts):
	//   WC_Gateway_Peptide_Pay_Binance     — Binance Connect shut down Aug 2023
	//   WC_Gateway_Peptide_Pay_Revolut     — deep-link broken (zero balance)
	//   WC_Gateway_Peptide_Pay_RampNetwork — settles ETH mainnet, fund-loss risk
	//   WC_Gateway_Peptide_Pay_Sardine     — hardcodes fixed_network=ethereum
	//   WC_Gateway_Peptide_Pay_Alchemypay  — settles ETH mainnet via Trust Wallet
	return array(
		'WC_Gateway_Peptide_Pay_Smart'   => 'gateway',
		'WC_Gateway_Peptide_Pay_Moonpay' => 'moonpay',
		'WC_Gateway_Peptide_Pay_Cryptocom' => 'cryptocom',
		'WC_Gateway_Peptide_Pay_Banxa'   => 'banxa',
		'WC_Gateway_Peptide_Pay_Transak' => 'transak',
		'WC_Gateway_Peptide_Pay_Bitnovo' => 'bitnovo',
		'WC_Gateway_Peptide_Pay_Simplex' => 'simplex',
		'WC_Gateway_Peptide_Pay_Interac' => 'interac',
		'WC_Gateway_Peptide_Pay_Upi'     => 'upi',
	);
}

/**
 * Every class registered with the woocommerce_payment_gateways filter
 * (before the live-API filter narrows it). Order = display order in
 * WC → Settings → Payments.
 */
function peptide_pay_gateway_classes() {
	return array_keys( peptide_pay_class_to_code() );
}

/**
 * Fetch the list of live provider codes from the API. Cached for 24h
 * via WP transient + refreshed via daily cron. Returns null when the
 * API is unreachable, in which case the caller must fall back to "no
 * filter" (register everything we have locally) — matches the safety
 * model of WC's own gateway plugins, which never disable themselves
 * because of a network blip.
 *
 * Always lowercases the IDs so a case mismatch upstream can't desync
 * the local class map.
 *
 * @param bool $force_refresh Bypass the transient cache.
 * @return array<int,string>|null
 */
function peptide_pay_get_live_provider_codes( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_transient( PEPTIDE_PAY_LIVE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$resp = wp_remote_get(
		PEPTIDE_PAY_API_BASE . '/providers',
		array(
			'timeout'  => 5,
			'blocking' => true,
			'headers'  => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'peptide-pay-woocommerce/' . PEPTIDE_PAY_VERSION . ' (+https://peptide-pay.com)',
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return null;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return null;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $body ) || ! isset( $body['providers'] ) || ! is_array( $body['providers'] ) ) {
		return null;
	}

	$codes = array();
	foreach ( $body['providers'] as $p ) {
		if ( is_array( $p ) && isset( $p['id'] ) && is_string( $p['id'] ) ) {
			$codes[] = strtolower( $p['id'] );
		}
	}

	if ( empty( $codes ) ) {
		return null;
	}

	set_transient( PEPTIDE_PAY_LIVE_TRANSIENT, $codes, DAY_IN_SECONDS );
	return $codes;
}

/**
 * Cron callback: hard-refresh the live-provider cache once a day.
 */
function peptide_pay_refresh_live_providers_cron() {
	peptide_pay_get_live_provider_codes( true );
}
add_action( PEPTIDE_PAY_LIVE_CRON_HOOK, 'peptide_pay_refresh_live_providers_cron' );

// Register the daily cron once activated; cleaned up on deactivation.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( PEPTIDE_PAY_LIVE_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'daily', PEPTIDE_PAY_LIVE_CRON_HOOK );
	}
	// Warm the cache on activation so the first checkout doesn't pay the HTTP cost.
	peptide_pay_get_live_provider_codes( true );
} );
register_deactivation_hook( __FILE__, function () {
	$ts = wp_next_scheduled( PEPTIDE_PAY_LIVE_CRON_HOOK );
	if ( $ts ) {
		wp_unschedule_event( $ts, PEPTIDE_PAY_LIVE_CRON_HOOK );
	}
	delete_transient( PEPTIDE_PAY_LIVE_TRANSIENT );
} );

add_action( 'plugins_loaded', 'peptide_pay_init', 11 );

function peptide_pay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'peptide_pay_wc_missing_notice' );
		return;
	}

	try {
		load_plugin_textdomain( 'peptide-pay', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		require_once PEPTIDE_PAY_PATH . 'includes/class-gateway-base.php';
		require_once PEPTIDE_PAY_PATH . 'includes/providers.php';
		require_once PEPTIDE_PAY_PATH . 'includes/class-update-checker.php';

		// Self-hosted update checker — polls peptide-pay.com once per 12h and
		// surfaces the standard "new version available" notice in WP admin.
		WC_Peptide_Pay_Update_Checker::init();

		add_filter( 'woocommerce_payment_gateways', 'peptide_pay_add_gateways' );

		// HMAC-signed webhook endpoint (the only mode since 2.6.3 — Quick mode
		// removed because session-id auth was customer-spoofable).
		add_action( 'woocommerce_api_peptidepay_webhook_authed', array( 'WC_Gateway_Peptide_Pay_Base', 'handle_webhook_authed' ) );

		// Block checkout JS — register every sub-gateway for WC Blocks support.
		add_action( 'woocommerce_blocks_loaded', 'peptide_pay_register_blocks_support' );

		// Plugin row "Settings" link → jumps to the Smart gateway's settings.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'peptide_pay_action_links' );

		// Inject the computed checkout style (preset + colour controls) +
		// optional raw Custom CSS override on the checkout page only.
		add_action( 'wp_head', 'peptide_pay_inject_custom_checkout_css' );

		// Wire the WP core colour-picker JS to our colour input fields in
		// WC → Settings → Payments → Peptide-Pay → Manage.
		add_action( 'admin_enqueue_scripts', 'peptide_pay_enqueue_color_picker' );

		// Replace WC's generic "Place order" button with "Pay $XX →" so
		// the customer sees the exact amount on the CTA. Audit 2026-04-28
		// flagged that the generic label hurts conversion — Stripe and
		// Lemon Squeezy both put the amount on the button.
		add_filter( 'woocommerce_order_button_text', 'peptide_pay_filter_order_button_text' );

		// Loading state JS on the checkout page — disables the order
		// button and swaps the label to "Redirecting to secure
		// checkout…" the moment the customer clicks, with a 30-second
		// fallback that re-enables the button if nothing happens.
		add_action( 'wp_enqueue_scripts', 'peptide_pay_enqueue_checkout_js' );
	} catch ( \Throwable $e ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( 'Peptide-Pay init failed: ' . $e->getMessage() );
		}
		add_action( 'admin_notices', function() use ( $e ) {
			echo '<div class="notice notice-error"><p>Peptide-Pay init error: ' . esc_html( $e->getMessage() ) . '</p></div>';
		} );
	}
}

/**
 * Registered classes filtered against the live PayGate provider list
 * AND the merchant's "show individual gateways" preference. If the
 * API is unreachable we register every local class (degrade
 * permissively — better to show a checkout option that may fail than
 * hide the entire gateway because of a transient network issue).
 */
function peptide_pay_add_gateways( $gateways ) {
	$live_codes      = peptide_pay_get_live_provider_codes();
	$class_map       = peptide_pay_class_to_code();
	$show_individual = peptide_pay_show_individual_gateways();

	foreach ( $class_map as $cls => $code ) {
		if ( ! class_exists( $cls ) ) {
			continue;
		}
		if ( is_array( $live_codes ) && ! in_array( $code, $live_codes, true ) ) {
			// Live API doesn't expose this provider anymore — hide it from
			// merchants instead of letting them route customers into a
			// broken on-ramp. We keep the class on disk so an upstream
			// flap doesn't permanently delete merchant settings.
			continue;
		}
		// Compact view: only the Smart (gateway) row appears in WC →
		// Settings → Payments. Customers still get the full provider
		// menu inside the hosted checkout — this filter only affects
		// the merchant-side admin list, not the buyer's checkout.
		if ( ! $show_individual && 'gateway' !== $code ) {
			continue;
		}
		$gateways[] = $cls;
	}
	return $gateways;
}

/**
 * Whether to register every individual provider as its own row in
 * WC → Settings → Payments. Default false (compact); existing 2.0.x
 * installs that already saved per-gateway settings auto-default to
 * true so they don't silently lose configured rails.
 *
 * Override via the `peptide_pay_show_individual_gateways` wp_option
 * or the `peptide_pay/show_individual_gateways` filter (for code-as-
 * config setups).
 */
function peptide_pay_show_individual_gateways() {
	$opt = get_option( PEPTIDE_PAY_SHOW_INDIVIDUAL_OPTION, null );
	if ( null === $opt ) {
		// Auto-detect legacy installs: any per-sub-gateway settings option
		// existing means the merchant set them up under 2.0.x. Stay
		// compatible — show every row.
		$opt = peptide_pay_default_show_individual_gateways() ? 'yes' : 'no';
		update_option( PEPTIDE_PAY_SHOW_INDIVIDUAL_OPTION, $opt, false );
	}
	$show = ( 'yes' === $opt );
	return apply_filters( 'peptide_pay/show_individual_gateways', $show );
}

function peptide_pay_default_show_individual_gateways() {
	// Probe a handful of historical sub-gateway option keys. If any one
	// already exists, the merchant configured them on 2.0.x — default to
	// "show all" so the upgrade isn't surprising. Otherwise compact.
	$probe = array(
		'woocommerce_peptide_pay_moonpay_settings',
		'woocommerce_peptide_pay_revolut_settings',
		'woocommerce_peptide_pay_banxa_settings',
		'woocommerce_peptide_pay_transak_settings',
		'woocommerce_peptide_pay_binance_settings',
		'woocommerce_peptide_pay_simplex_settings',
	);
	foreach ( $probe as $key ) {
		if ( false !== get_option( $key, false ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Replace the generic "Place order" CTA with "Pay $XX →" when a
 * Peptide-Pay gateway is the chosen payment method. Reads the cart
 * total + currency at render time. Only fires on checkout — bails
 * elsewhere so we don't pollute "Place order" buttons in other
 * contexts (e.g. order-pay endpoint).
 */
function peptide_pay_filter_order_button_text( $label ) {
	if ( ! function_exists( 'WC' ) || ! WC() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return $label;
	}
	$cart = WC()->cart;
	if ( ! $cart ) {
		return $label;
	}
	$chosen = WC()->session ? WC()->session->get( 'chosen_payment_method' ) : '';
	if ( empty( $chosen ) || 0 !== strpos( (string) $chosen, 'peptide_pay_' ) ) {
		return $label;
	}
	$total = (float) $cart->get_total( 'edit' );
	if ( $total <= 0 ) {
		return $label;
	}
	$formatted = function_exists( 'wp_strip_all_tags' )
		? wp_strip_all_tags( wc_price( $total ) )
		: (string) $total;
	/* translators: %s: formatted order total, e.g. "$25.00" */
	return sprintf( __( 'Pay %s →', 'peptide-pay' ), $formatted );
}

/**
 * Enqueue our tiny checkout-loading-state JS on the WC checkout page.
 */
function peptide_pay_enqueue_checkout_js() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	wp_enqueue_script(
		'peptide-pay-checkout',
		PEPTIDE_PAY_URL . 'assets/js/checkout.js',
		array( 'jquery' ),
		PEPTIDE_PAY_VERSION,
		true
	);
}

function peptide_pay_action_links( $links ) {
	$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=peptide_pay_gateway' );
	$action_link  = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'peptide-pay' ) . '</a>';
	array_unshift( $links, $action_link );
	return $links;
}

/**
 * Build a CSS string for the merchant-configured "Checkout style" UI
 * (preset + colour pickers + radius/logo size + hover lift). Reads the
 * Smart gateway settings since the style controls live there. Falls
 * back to the "modern" preset if the merchant hasn't customised
 * anything — every fresh install gets a 2026-grade look out of the box.
 */
function peptide_pay_build_styles_css( $s ) {
	$preset = isset( $s['style_preset'] ) ? $s['style_preset'] : 'modern';
	$presets = array(
		'modern'  => array( 'border' => '#e5e7eb', 'bg' => '#ffffff', 'radius' => 16, 'logo' => 24, 'glass' => false, 'lift_default' => 'yes' ),
		// Glass: gradient bg instead of solid translucent so the frosted
		// effect is still readable when the merchant's theme background
		// is plain white (audit caught Glass = invisible on white).
		'glass'   => array( 'border' => 'rgba(255,255,255,0.35)', 'bg' => 'linear-gradient(135deg, rgba(255,255,255,0.85), rgba(248,250,255,0.7))', 'radius' => 20, 'logo' => 26, 'glass' => true,  'lift_default' => 'yes' ),
		'minimal' => array( 'border' => 'transparent', 'bg' => 'transparent', 'radius' => 0, 'logo' => 22, 'glass' => false, 'lift_default' => 'no'  ),
		'classic' => array( 'border' => '#cccccc', 'bg' => '#f9f9f9', 'radius' => 4, 'logo' => 22, 'glass' => false, 'lift_default' => 'no'  ),
	);
	$base = isset( $presets[ $preset ] ) ? $presets[ $preset ] : $presets['modern'];

	$border = ! empty( $s['style_border_color'] ) ? trim( (string) $s['style_border_color'] ) : $base['border'];
	$bg     = ! empty( $s['style_bg_color'] )     ? trim( (string) $s['style_bg_color'] )     : $base['bg'];
	$radius = ( isset( $s['style_radius'] ) && '' !== $s['style_radius'] )       ? max( 0, min( 32, (int) $s['style_radius'] ) ) : $base['radius'];
	$logo   = ( isset( $s['style_logo_size'] ) && '' !== $s['style_logo_size'] ) ? max( 16, min( 48, (int) $s['style_logo_size'] ) ) : $base['logo'];
	$lift   = isset( $s['style_hover_lift'] ) ? ( 'yes' === $s['style_hover_lift'] ) : ( 'yes' === $base['lift_default'] );

	// Sanitise colour values — reject anything that looks like a CSS
	// expression injection attempt. Allow hex, rgb(), rgba(), hsl(),
	// hsla(), simple keywords (white, transparent), and CSS variables.
	$colour_re = '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|var\([^)]*\)|[a-z]+)$/i';
	if ( ! preg_match( $colour_re, $border ) ) { $border = $base['border']; }
	if ( ! preg_match( $colour_re, $bg ) )     { $bg     = $base['bg']; }

	// !important on every property because most WC themes ship their own
	// `.payment_box{background:#ebe9eb}` rule with !important — the merchant
	// just sees the theme default if we don't out-specificity it.
	$css  = ".payment_box.payment_method_peptide_pay_gateway{";
	$css .= "background:{$bg} !important;border:1px solid {$border} !important;border-radius:{$radius}px !important;";
	$css .= "padding:16px 18px !important;box-shadow:0 1px 2px rgba(0,0,0,0.04),0 4px 16px rgba(0,0,0,0.04) !important;";
	$css .= "transition:transform .2s ease,box-shadow .2s ease !important;}";

	// Kill the little arrow tail WC injects above the .payment_box — it
	// inherits the theme background and looks broken when we change the bg.
	$css .= ".payment_box.payment_method_peptide_pay_gateway::before,";
	$css .= ".payment_box.payment_method_peptide_pay_gateway::after{display:none !important;}";

	if ( ! empty( $base['glass'] ) ) {
		$css .= ".payment_box.payment_method_peptide_pay_gateway{backdrop-filter:blur(20px) saturate(140%) !important;-webkit-backdrop-filter:blur(20px) saturate(140%) !important;}";
	}

	if ( $lift ) {
		$css .= ".payment_box.payment_method_peptide_pay_gateway:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,0.06),0 12px 24px rgba(0,0,0,0.08) !important;}";
	}

	// Logos shown bare — no chip container, no border, no background. Just
	// the brand mark on the gateway-box bg. Merchant feedback: chip-in-chip
	// looked like circles inside circles.
	$css .= ".peptide-pay-card-strip{gap:10px !important;margin-top:12px !important;align-items:center !important;}";
	$css .= ".peptide-pay-card-strip img{height:{$logo}px !important;width:auto !important;padding:0 !important;background:transparent !important;border:0 !important;border-radius:0 !important;box-shadow:none !important;}";

	// Hard cap on the gateway icon shown next to the radio label — a
	// merchant who pasted a 600×600 PNG into the Icon URL field would
	// otherwise blow the checkout box apart (audit 2026-04-28 caught this:
	// "the apple was 400px tall in the Peptide-Pay box"). 32 px max keeps
	// us in line with Stripe / Mollie / Shop Pay icon sizing.
	$css .= "li.wc_payment_method.payment_method_peptide_pay_gateway label img,";
	$css .= "li.wc_payment_method[class*=\"payment_method_peptide_pay_\"] label img,";
	$css .= "li.wc_payment_method.payment_method_peptide_pay_gateway > label > img,";
	$css .= "li.wc_payment_method[class*=\"payment_method_peptide_pay_\"] > label > img{";
	$css .= "max-height:24px !important;max-width:160px !important;width:auto !important;height:auto !important;object-fit:contain !important;vertical-align:middle !important;display:inline-block !important;margin-right:8px !important;}";

	return $css;
}

/**
 * Inject the computed checkout-style CSS + the merchant's raw "Custom
 * CSS" override into the checkout page <head>. Reads the Smart gateway
 * settings (where the style UI lives) but applies to every Peptide-Pay
 * sub-gateway via the `.payment_method_peptide_pay_gateway` selector.
 */
function peptide_pay_inject_custom_checkout_css() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return;
	}

	// Only emit CSS if at least one Peptide-Pay gateway is enabled.
	$has_active = false;
	foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
		if ( ! isset( $gw->id ) || 0 !== strpos( (string) $gw->id, 'peptide_pay_' ) ) {
			continue;
		}
		if ( method_exists( $gw, 'is_available' ) && $gw->is_available() ) {
			$has_active = true;
			break;
		}
	}
	if ( ! $has_active ) {
		return;
	}

	$smart_settings = get_option( 'woocommerce_peptide_pay_gateway_settings', array() );
	$smart_settings = is_array( $smart_settings ) ? $smart_settings : array();
	$css            = peptide_pay_build_styles_css( $smart_settings );

	$custom = trim( (string) ( isset( $smart_settings['custom_css'] ) ? $smart_settings['custom_css'] : '' ) );
	if ( '' !== $custom ) {
		// Prevent </style> breakout — trusted merchant input but cheap insurance.
		$custom = str_replace( array( '</style', '</STYLE' ), '<\/style', $custom );
		$css   .= "\n/* merchant override */\n" . $custom;
	}

	echo "\n<style id=\"peptide-pay-checkout-css\">\n" . $css . "\n</style>\n";
}

/**
 * Enqueue the WordPress core colour picker on the WC settings tab so
 * our Style preset's colour fields render as proper pickers instead of
 * plain text inputs.
 */
function peptide_pay_enqueue_color_picker( $hook ) {
	if ( ! is_string( $hook ) || false === strpos( $hook, 'woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || 'wc-settings' !== $_GET['page'] ) {
		return;
	}
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
	$inline = "jQuery(function($){"
		. "$('input.peptide-pay-color-field').each(function(){"
		. "var el=$(this);if(el.next('.wp-picker-container').length)return;"
		. "el.wpColorPicker({});});"
		. "});";
	wp_add_inline_script( 'wp-color-picker', $inline );
}

function peptide_pay_wc_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error is-dismissible"><p>';
	echo esc_html__( 'Peptide-Pay requires WooCommerce to be installed and active.', 'peptide-pay' );
	echo '</p></div>';
}

/**
 * Register WC Blocks checkout support for every Peptide-Pay sub-gateway,
 * filtered by the same live-provider list as the classic WC Payments
 * filter. Without this filter, a block-checkout merchant would see
 * retired providers that no longer route on the server.
 */
function peptide_pay_register_blocks_support() {
	if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once PEPTIDE_PAY_PATH . 'includes/class-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $payment_method_registry ) {
			$live_codes      = peptide_pay_get_live_provider_codes();
			$class_map       = peptide_pay_class_to_code();
			$show_individual = peptide_pay_show_individual_gateways();
			foreach ( $class_map as $cls => $code ) {
				if ( ! class_exists( $cls ) ) {
					continue;
				}
				if ( is_array( $live_codes ) && ! in_array( $code, $live_codes, true ) ) {
					continue;
				}
				if ( ! $show_individual && 'gateway' !== $code ) {
					continue;
				}
				$payment_method_registry->register( new Peptide_Pay_Blocks_Support( $cls ) );
			}
		}
	);
}
