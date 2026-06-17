<?php
/**
 * Peptide-Pay — base class for all provider gateways.
 *
 * Every sub-gateway (Moonpay, Revolut, Stripe, Crypto, ...) extends this.
 * Subclasses only set: $provider_code, $provider_method_title,
 * $provider_default_title, $provider_default_description, and optionally
 * restrict supported currencies / country.
 *
 * @package Peptide_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WC_Gateway_Peptide_Pay_Base extends WC_Payment_Gateway {

	/** WP-Cron hook for the recurring status-reconciliation sweep (P0-4). */
	const RECONCILE_CRON_HOOK = 'peptide_pay_reconcile';

	/** Provider code sent to /api/v1/checkout/init (e.g. "moonpay", "revolut", "gateway", "crypto"). */
	protected $provider_code = 'gateway';

	/** Shown in WC → Settings → Payments list. Overridden per provider. */
	protected $provider_method_title = 'Peptide-Pay';

	/** Short description under the row title. Overridden per provider. */
	protected $provider_method_description = '';

	/** Default "Title" field (customer-facing). Overridden per provider. */
	protected $provider_default_title = 'Credit / Debit Card';

	/** Default "Description" field. Overridden per provider. */
	protected $provider_default_description = 'Pay by card. Secure checkout powered by Peptide-Pay.';

	/** Optional ISO currency restriction (e.g. "USD", "CAD", "INR"). Empty = all. */
	protected $provider_forced_currency = '';

	/** Default icon file (relative to plugin root). Override per provider. */
	protected $provider_icon_file = 'assets/icon.svg';

	// ─── Common merchant config (stored per-gateway, each gateway has its own) ───

	/** @var string */
	protected $api_key;
	/** @var string */
	protected $webhook_secret;
	/** @var string */
	protected $default_currency;
	/** @var bool */
	protected $debug_log;

	public function __construct() {
		// Parent ctor first — avoids uninitialized-property fatals on PHP 8.2+.
		if ( is_callable( array( 'WC_Payment_Gateway', '__construct' ) ) ) {
			parent::__construct();
		}

		$this->id                 = 'peptide_pay_' . $this->provider_code;
		$this->has_fields         = false;
		$this->method_title       = $this->provider_method_title;
		$this->method_description = $this->provider_method_description;
		$this->supports           = array( 'products', 'refunds' );

		// Default icon precedence: per-gateway "Icon URL" setting →
		// `peptide_pay_icon_<provider>` filter (dev override) → bundled
		// Peptide-Pay wordmark SVG. The wordmark is shipped from
		// /assets/peptide-pay-logo.svg so a fresh install never lands
		// with a missing-image broken icon next to the gateway label.
		$icon_from_settings = trim( (string) $this->get_option( 'icon_url', '' ) );
		if ( '' !== $icon_from_settings ) {
			$this->icon = esc_url( $icon_from_settings );
		} else {
			$dev_override = apply_filters( 'peptide_pay_icon_' . $this->provider_code, '' );
			$this->icon   = '' !== $dev_override ? $dev_override : ( PEPTIDE_PAY_URL . 'assets/peptide-pay-logo.svg' );
		}

		$this->init_form_fields();
		$this->init_settings();

		$this->title            = (string) $this->get_option( 'title', $this->provider_default_title );
		$this->description      = (string) $this->get_option( 'description', $this->provider_default_description );
		$this->api_key          = trim( (string) $this->get_option( 'api_key' ) );
		$this->webhook_secret   = trim( (string) $this->get_option( 'webhook_secret' ) );
		$this->default_currency = strtoupper( (string) $this->get_option( 'default_currency', 'EUR' ) );
		$this->debug_log        = 'yes' === $this->get_option( 'debug_log' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$api_keys_url = 'https://peptide-pay.com/app/api-keys';
		$webhook_url  = add_query_arg( 'wc-api', 'peptidepay_webhook_authed', home_url( '/' ) );
		$link_open    = '<a href="' . esc_url( $api_keys_url ) . '" target="_blank" rel="noopener noreferrer">';

		$fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'peptide-pay' ),
				'type'    => 'checkbox',
				/* translators: %s = provider method title, e.g. "Peptide-Pay — Revolut" */
				'label'   => sprintf( __( 'Enable %s at checkout', 'peptide-pay' ), $this->provider_method_title ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'peptide-pay' ),
				'type'        => 'text',
				'description' => __( 'Label shown to the customer at checkout. Free text — write anything: your brand name, an emoji, a hashtag.', 'peptide-pay' ),
				'default'     => $this->provider_default_title,
			),
			'description'     => array(
				'title'       => __( 'Description', 'peptide-pay' ),
				'type'        => 'textarea',
				'description' => __( 'Message shown below the title at checkout. Plain text or HTML — paste your own &lt;img&gt; tags, links, custom badges. Anything WordPress accepts.', 'peptide-pay' ),
				'default'     => $this->provider_default_description,
			),
			'icon_url'        => array(
				'title'       => __( 'Icon URL (optional)', 'peptide-pay' ),
				'type'        => 'text',
				'description' => __( 'Paste any image URL to replace the gateway icon shown in the checkout list. Upload via Media Library and copy the URL, or use any external HTTPS image. Leave empty for no icon.', 'peptide-pay' ),
				'placeholder' => 'https://example.com/my-logo.png',
				'default'     => '',
			),

			'api_key'         => array(
				'title'       => __( 'API Key', 'peptide-pay' ),
				'type'        => 'password',
				/* translators: %1$s = opening <a> tag, %2$s = closing </a>. Both wrap a deep-link to peptide-pay.com/app/api-keys. */
				'description' => sprintf(
					__( 'Required. Get yours at %1$speptide-pay.com/app/api-keys%2$s — click "Generate new key", copy the sk_live_ or sk_test_ value.', 'peptide-pay' ),
					$link_open,
					'</a>'
				),
				'placeholder' => 'sk_live_...',
				'default'     => '',
			),

			'webhook_url_info' => array(
				'title'       => __( 'Webhook URL', 'peptide-pay' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %1$s = opening <a>, %2$s = closing </a> for the Peptide-Pay dashboard link. */
					__( 'This is the URL the plugin already sends automatically on every checkout. You do NOT need to create or register a webhook anywhere — just keep your API key + secret filled in. Shown here read-only, only for your reference. (Manage your keys at %1$speptide-pay.com/app/api-keys%2$s.)', 'peptide-pay' ),
					$link_open,
					'</a>'
				),
				'default'     => $webhook_url,
				'custom_attributes' => array(
					'readonly' => 'readonly',
					'onclick'  => 'this.select();',
				),
			),

			'webhook_secret'  => array(
				'title'       => __( 'Webhook Secret', 'peptide-pay' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %1$s = opening <a>, %2$s = closing </a> for the Peptide-Pay dashboard link. */
					__( 'Required. Your webhook secret was shown once at signup. Lost it? Reveal it anytime at %1$speptide-pay.com/app/api-keys%2$s (no need to rotate). Paste it here. Used for HMAC-SHA256 verification.', 'peptide-pay' ),
					$link_open,
					'</a>'
				),
				'placeholder' => 'whsec_...',
				'default'     => '',
			),

			'default_currency' => array(
				'title'       => __( 'Default currency', 'peptide-pay' ),
				'type'        => 'select',
				'description' => __( 'Used only if WooCommerce store currency is missing.', 'peptide-pay' ),
				'default'     => 'EUR',
				'options'     => array(
					'EUR' => 'EUR',
					'USD' => 'USD',
					'GBP' => 'GBP',
					'CHF' => 'CHF',
					'CAD' => 'CAD',
					'INR' => 'INR',
				),
			),

			'custom_css'      => array(
				'title'       => __( 'Custom CSS (checkout)', 'peptide-pay' ),
				'type'        => 'textarea',
				'description' => __( 'Optional CSS injected only on the checkout page — restyle the gateway box, hide logos, change colors, anything. Useful selectors: <code>.payment_box.payment_method_peptide_pay_gateway</code> · <code>.peptide-pay-card-strip</code> · <code>.peptide-pay-card-strip img</code>.', 'peptide-pay' ),
				'default'     => '',
				'css'         => 'min-height:140px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;',
			),
			'debug_log'       => array(
				'title'       => __( 'Debug log', 'peptide-pay' ),
				'type'        => 'checkbox',
				'label'       => __( 'Save every API call to a WooCommerce log file (only enable when something is broken).', 'peptide-pay' ),
				'description' => __( 'When ON, every request the plugin sends to peptide-pay.com is recorded under <b>WooCommerce → Status → Logs</b>. Useful when a payment fails and you want to share what happened with support. Leave OFF in production — the log file grows fast on busy stores.', 'peptide-pay' ),
				'default'     => 'no',
			),
		);

		$this->form_fields = $fields;
	}

	public function process_admin_options() {
		if ( ! class_exists( 'WC_Admin_Settings' ) ) {
			return parent::process_admin_options();
		}

		$posted = $this->get_post_data();

		$enabled_key = $this->plugin_id . $this->id . '_enabled';
		$is_enabled  = ! empty( $posted[ $enabled_key ] );

		$api_key_key = $this->plugin_id . $this->id . '_api_key';
		$api_key     = isset( $posted[ $api_key_key ] ) ? trim( wp_unslash( $posted[ $api_key_key ] ) ) : '';

		$ws_key = $this->plugin_id . $this->id . '_webhook_secret';
		$ws     = isset( $posted[ $ws_key ] ) ? trim( wp_unslash( $posted[ $ws_key ] ) ) : '';

		if ( '' !== $api_key && ! preg_match( '/^sk_(live|test)_[A-Za-z0-9_\-]{16,}$/', $api_key ) ) {
			WC_Admin_Settings::add_error( __( 'Peptide-Pay: invalid API key. Must start with sk_live_ or sk_test_ followed by at least 16 characters.', 'peptide-pay' ) );
			return false;
		}

		if ( '' !== $ws && 0 !== strpos( $ws, 'whsec_' ) ) {
			WC_Admin_Settings::add_error( __( 'Peptide-Pay: invalid webhook secret. Must start with whsec_.', 'peptide-pay' ) );
			return false;
		}

		if ( $is_enabled && ( '' === $api_key || '' === $ws ) ) {
			WC_Admin_Settings::add_error( __( 'Peptide-Pay: API Key and Webhook Secret are required to enable this gateway. Get both at peptide-pay.com/app/api-keys.', 'peptide-pay' ) );
			return false;
		}

		if ( 0 === strpos( $api_key, 'sk_test_' ) ) {
			WC_Admin_Settings::add_error( __( 'Peptide-Pay: You pasted a TEST key (sk_test_). For live payments paste your sk_live_ key from peptide-pay.com/app/api-keys.', 'peptide-pay' ) );
		}

		return parent::process_admin_options();
	}

	/**
	 * Override WC's default get_icon() to inject an `onerror` handler on
	 * the gateway icon img. If the merchant's custom Icon URL 404s
	 * (S3 signed URL expired, deleted asset, typo), we swap to our
	 * bundled Peptide-Pay wordmark instead of letting the broken-image
	 * placeholder render next to the gateway label.
	 *
	 * `onerror=null` after the swap prevents an infinite loop if the
	 * fallback URL also fails (e.g. the plugin folder was renamed).
	 */
	public function get_icon() {
		$html = parent::get_icon();
		if ( '' === $html ) {
			return $html;
		}
		$fallback = esc_url( PEPTIDE_PAY_URL . 'assets/peptide-pay-logo.svg' );
		$html     = preg_replace(
			'/<img\b(?![^>]*\bonerror=)/i',
			'<img onerror="this.onerror=null;this.src=\'' . $fallback . '\';" ',
			$html
		);
		return $html;
	}

	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		// Hide the gateway from checkout if the merchant hasn't pasted both
		// secrets yet — Quick mode (wallet-only) was removed in 2.2.0 because
		// its session-id auth was customer-spoofable.
		if ( '' === $this->api_key || '' === $this->webhook_secret ) {
			return false;
		}

		// Country-locked rails — hide when store currency doesn't match.
		if ( '' !== $this->provider_forced_currency ) {
			$store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
			if ( strtoupper( $store_currency ) !== strtoupper( $this->provider_forced_currency ) ) {
				return false;
			}
		}

		// Smart masks individuals. If the Smart gateway is enabled, hide every
		// other Peptide-Pay sub-gateway on the customer's checkout page —
		// merchants who "enable all" shouldn't show 10+ duplicate card options.
		// Admin side (WC → Settings → Payments) is unaffected: we only filter
		// at the customer-facing checkout.
		if ( 'gateway' !== $this->provider_code && function_exists( 'is_admin' ) && ! is_admin() ) {
			$smart_settings = get_option( 'woocommerce_peptide_pay_gateway_settings' );
			if ( is_array( $smart_settings ) && isset( $smart_settings['enabled'] ) && 'yes' === $smart_settings['enabled'] ) {
				return false;
			}
		}

		// Local dev / staging bypass. Classic WC_Payment_Gateway::is_available()
		// hides gateways on non-HTTPS sites, which breaks the test loop on
		// sandbox installs (Playground, TasteWP preview, etc.). Previously this
		// skipped the parent's SSL gate whenever WP_DEBUG was on — far too
		// broad, since many live stores run with WP_DEBUG enabled, which would
		// expose the gateway over plain HTTP. Gate it instead on an explicit,
		// dedicated opt-in constant the merchant must set in wp-config.php
		// (define('PEPTIDE_PAY_ALLOW_INSECURE', true);). On a normal HTTPS
		// store this branch never runs and the parent SSL check still applies.
		if ( defined( 'PEPTIDE_PAY_ALLOW_INSECURE' ) && PEPTIDE_PAY_ALLOW_INSECURE ) {
			return true;
		}

		return parent::is_available();
	}

	/**
	 * Returns the provider code sent to the API. Smart gateway overrides this
	 * to support direct-to-provider mode (e.g. always go to Simplex).
	 */
	protected function get_effective_provider_code() {
		return $this->provider_code;
	}

	public function process_payment( $order_id ) {
		// Wrap the whole flow so a non-UTF-8 product name, an unexpected API
		// shape, or any other \Throwable returns a clean WC checkout failure
		// (re-enabled button + retry notice) instead of a fatal that blanks
		// the AJAX checkout response. The customer can always retry.
		try {
			return $this->do_process_payment( $order_id );
		} catch ( \Throwable $e ) {
			$this->log_error( 'process_payment exception: ' . $e->getMessage() );
			wc_add_notice( __( 'Something went wrong starting the payment. Please try again.', 'peptide-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Core payment flow, wrapped by process_payment()'s try/catch.
	 *
	 * @param int $order_id
	 * @return array
	 */
	protected function do_process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'peptide-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $this->api_key ) ) {
			wc_add_notice( __( 'Peptide-Pay is not configured: missing API key. Contact the shop admin.', 'peptide-pay' ), 'error' );
			$this->log_error( 'Missing api_key — payment cannot start for order ' . $order_id . '.' );
			return array( 'result' => 'failure' );
		}

		$webhook_url = add_query_arg( 'wc-api', 'peptidepay_webhook_authed', home_url( '/' ) );

		$currency = $order->get_currency() ? $order->get_currency() : $this->default_currency;
		if ( '' !== $this->provider_forced_currency ) {
			// Provider locked to a specific currency (e.g. UPI=INR, Interac=CAD).
			$currency = $this->provider_forced_currency;
		}

		// Build a compact product_name for the checkout page — "BPC-157 × 3,
		// Retatrutide 10mg × 1" — shown in the "Order summary" card next to
		// the amount. Truncated to 80 chars to fit one line on mobile.
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}
			$name = $item->get_name();
			$qty  = (int) $item->get_quantity();
			if ( '' === $name ) {
				continue;
			}
			$parts[] = $qty > 1 ? ( $name . ' × ' . $qty ) : $name;
		}
		$product_name = implode( ', ', $parts );
		if ( strlen( $product_name ) > 80 ) {
			$product_name = substr( $product_name, 0, 77 ) . '...';
		}

		$payload = array(
			'amount'       => (int) round( ( (float) $order->get_total() ) * 100 ),
			'currency'     => $currency,
			'provider'     => $this->get_effective_provider_code(),
			'email'        => $order->get_billing_email(),
			'order_id'     => (string) $order_id,
			'product_name' => $product_name,
			'webhook_url'  => esc_url_raw( $webhook_url ),
			'success_url'  => $this->get_return_url( $order ),
			'cancel_url'   => wc_get_cart_url(),
			'metadata'     => array(
				'wc_order_key' => $order->get_order_key(),
				'plugin_ver'   => PEPTIDE_PAY_VERSION,
				'gateway_id'   => $this->id,
			),
		);

		$headers = array(
			'Content-Type'      => 'application/json',
			'Accept'            => 'application/json',
			'User-Agent'        => 'peptide-pay-woocommerce/' . PEPTIDE_PAY_VERSION,
			'Idempotency-Key'   => 'wc_' . $order_id . '_' . substr( md5( (string) $order->get_date_created() ), 0, 8 ),
			// Tells the API this call originated from the WC plugin so the
			// merchant record gets `has_woocommerce_integration = true`
			// flipped on first checkout. Without this header, every WC
			// merchant sees a false "Webhook URL — REQUIRED" red banner
			// on the dashboard even though the plugin handles webhooks
			// itself. See src/app/api/v1/checkout/init/route.ts where the
			// header is read.
			'X-PeptidePay-Source' => 'woocommerce-plugin',
		);

		$headers['Authorization'] = 'Bearer ' . $this->api_key;

		// Assert the payload serialises before we send it. A non-UTF-8 product
		// name (`get_name()` above) can make wp_json_encode() return false,
		// which would otherwise post an empty body and surface as a confusing
		// server-side error. Fail fast with a clean retry message + a log line.
		$json = wp_json_encode( $payload );
		if ( false === $json ) {
			$this->log_error( 'checkout/init payload failed to JSON-encode for order ' . $order_id . ' (likely a non-UTF-8 product name).' );
			wc_add_notice( __( 'Could not prepare the payment. Please try again or contact the shop admin.', 'peptide-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$response = wp_remote_post(
			PEPTIDE_PAY_API_BASE . '/checkout/init',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $json,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'checkout/init wp_error: ' . $response->get_error_message() );
			wc_add_notice( __( 'Unable to reach payment provider. Please try again.', 'peptide-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['url'] ) || empty( $body['id'] ) ) {
			$msg = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : 'unknown error';
			$this->log_error( 'checkout/init http ' . $code . ' body=' . wp_json_encode( $body ) );
			wc_add_notice( sprintf( /* translators: %s: error message */ __( 'Payment init failed: %s', 'peptide-pay' ), esc_html( $msg ) ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Defensive: re-validate the redirect URL one more time before we
		// commit to it. The audit caught a case where the customer landed
		// on an empty /cart/ page silently — symptom of a redirect URL
		// that didn't survive sanitization. Belt-and-braces here so any
		// future API hiccup surfaces as a real error instead of a void.
		$redirect_url = esc_url_raw( (string) $body['url'] );
		if ( '' === $redirect_url || ! preg_match( '~^https?://~i', $redirect_url ) ) {
			$this->log_error( 'checkout/init returned 200 but redirect URL invalid: ' . wp_json_encode( $body ) );
			wc_add_notice( __( 'Payment provider returned an invalid checkout URL. Please try again or contact support.', 'peptide-pay' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'pending', __( 'Awaiting Peptide-Pay payment.', 'peptide-pay' ) );
		$order->update_meta_data( '_peptide_pay_session_id', sanitize_text_field( $body['id'] ) );
		$order->update_meta_data( '_peptide_pay_provider', $this->provider_code );
		$order->save();

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	// ─── Shared webhook handler (HMAC-verified, registered once by the bootstrap) ─

	public static function handle_webhook_authed() {
		// Collect EVERY distinct webhook_secret configured across the active
		// Peptide-Pay sub-gateways. We must not assume a single shared secret:
		// a merchant can configure different secrets on different rails, and
		// picking only the "first" one would reject a webhook signed with any
		// other rail's secret → the order silently stays pending (lost-order
		// class of bug). We try them all below and accept if ANY validates.
		$secrets  = array();
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		foreach ( $gateways as $gw ) {
			if ( ! isset( $gw->id ) || 0 !== strpos( (string) $gw->id, 'peptide_pay_' ) ) {
				continue;
			}
			$s = isset( $gw->webhook_secret ) ? trim( (string) $gw->webhook_secret ) : '';
			if ( '' !== $s && ! in_array( $s, $secrets, true ) ) {
				$secrets[] = $s;
			}
		}

		if ( empty( $secrets ) ) {
			status_header( 500 );
			exit( 'no secret configured' );
		}

		$raw = file_get_contents( 'php://input' );
		if ( empty( $raw ) ) {
			status_header( 400 );
			exit( 'empty body' );
		}

		// Reject oversized bodies before HMAC/JSON work. A legitimate event is a
		// few KB; capping at 64 KB bounds the cost of hashing/decoding an
		// attacker-supplied payload (DoS hardening) without affecting real events.
		if ( strlen( $raw ) > 65536 ) {
			status_header( 413 );
			exit( 'payload too large' );
		}

		// Server sends header "X-PeptidePay-Signature" (new format, v2.0.3+).
		// Older builds sent "PeptidePay-Signature" — still accept it for
		// transitional webhooks in flight. Value format (Stripe-style):
		//   t=<unix_seconds>,v1=<hex_sha256>
		$sig_header = '';
		if ( ! empty( $_SERVER['HTTP_X_PEPTIDEPAY_SIGNATURE'] ) ) {
			$sig_header = (string) wp_unslash( $_SERVER['HTTP_X_PEPTIDEPAY_SIGNATURE'] );
		} elseif ( ! empty( $_SERVER['HTTP_PEPTIDEPAY_SIGNATURE'] ) ) {
			$sig_header = (string) wp_unslash( $_SERVER['HTTP_PEPTIDEPAY_SIGNATURE'] );
		}
		if ( empty( $sig_header ) ) {
			status_header( 400 );
			exit( 'missing signature' );
		}

		$parts = array();
		foreach ( explode( ',', $sig_header ) as $seg ) {
			$seg = trim( $seg );
			if ( '' === $seg || strpos( $seg, '=' ) === false ) {
				continue;
			}
			$kv = explode( '=', $seg, 2 );
			if ( count( $kv ) !== 2 ) {
				continue;
			}
			$parts[ $kv[0] ] = $kv[1];
		}
		$ts  = isset( $parts['t'] ) ? (int) $parts['t'] : 0;
		$sig = isset( $parts['v1'] ) ? (string) $parts['v1'] : '';

		if ( ! $ts || ! $sig ) {
			status_header( 400 );
			exit( 'bad signature format' );
		}
		if ( abs( time() - $ts ) > 300 ) {
			status_header( 401 );
			exit( 'stale timestamp' );
		}
		$signed_payload = $ts . '.' . $raw;
		$verified       = false;
		foreach ( $secrets as $secret ) {
			$expected = hash_hmac( 'sha256', $signed_payload, $secret );
			if ( hash_equals( $expected, $sig ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			status_header( 401 );
			exit( 'signature mismatch' );
		}

		$event    = json_decode( $raw, true );
		if ( ! is_array( $event ) ) {
			status_header( 400 );
			exit( 'bad json' );
		}

		// Event type is `event` on the new server (was `event_type` on older
		// internal builds). Accept either key for backward compatibility, but
		// only act on the known event names. Unknown events get 202 so the
		// server stops retrying without flagging a failure.
		$event_name = '';
		if ( isset( $event['event'] ) && is_string( $event['event'] ) ) {
			$event_name = $event['event'];
		} elseif ( isset( $event['event_type'] ) && is_string( $event['event_type'] ) ) {
			$event_name = $event['event_type'];
		}
		$known_events = array( 'order.paid', 'order.refunded', 'order.failed' );
		if ( '' !== $event_name && ! in_array( $event_name, $known_events, true ) ) {
			status_header( 202 );
			exit( 'unsupported event' );
		}

		$order_id = isset( $event['order_id'] ) ? absint( $event['order_id'] ) : 0;
		$status   = isset( $event['status'] ) ? sanitize_text_field( $event['status'] ) : '';

		// Derive status from event name when the payload omits it (new server
		// always includes status, but belt-and-suspenders for future events).
		if ( '' === $status && '' !== $event_name ) {
			if ( 'order.paid' === $event_name ) {
				$status = 'paid';
			} elseif ( 'order.refunded' === $event_name ) {
				$status = 'refunded';
			} elseif ( 'order.failed' === $event_name ) {
				$status = 'failed';
			}
		}

		if ( ! $order_id ) {
			status_header( 400 );
			exit( 'missing order_id' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			status_header( 200 );
			exit( 'order gone' );
		}

		// ─── Bind the event to THIS order's stored session before fulfilling ───
		// HMAC proves the body came from someone holding the shared secret, but
		// it does NOT prove the event belongs to this order_id: a body signed
		// with the same secret for a different/replayed order could otherwise
		// fulfil the wrong WC order. Require the event's session id to match the
		// order's stored `_peptide_pay_session_id`, OR the event metadata's
		// `wc_order_key` to match this order's key. We enforce this only when the
		// event actually carries a binding field — older events without either
		// fall through and are trusted (we never reject a genuine payment over a
		// missing field), but a present-and-mismatched binding is hard-rejected.
		$event_session_id = isset( $event['session_id'] ) ? sanitize_text_field( (string) $event['session_id'] ) : '';
		$stored_session   = (string) $order->get_meta( '_peptide_pay_session_id' );
		$event_order_key  = ( isset( $event['metadata'] ) && is_array( $event['metadata'] ) && isset( $event['metadata']['wc_order_key'] ) )
			? sanitize_text_field( (string) $event['metadata']['wc_order_key'] )
			: '';

		$has_binding   = ( '' !== $event_session_id ) || ( '' !== $event_order_key );
		$binding_match = false;
		if ( '' !== $event_session_id && '' !== $stored_session && hash_equals( $stored_session, $event_session_id ) ) {
			$binding_match = true;
		}
		if ( '' !== $event_order_key && hash_equals( (string) $order->get_order_key(), $event_order_key ) ) {
			$binding_match = true;
		}

		if ( $has_binding && ! $binding_match ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: event session id, 2: order's stored session id */
					__( 'Peptide-Pay: webhook IGNORED — event session/order-key does not match this order (event session: %1$s, stored: %2$s). Possible cross-order/replayed event. Not fulfilled.', 'peptide-pay' ),
					$event_session_id ? $event_session_id : 'n/a',
					$stored_session ? $stored_session : 'n/a'
				)
			);
			status_header( 200 );
			exit( 'session/order binding mismatch — not fulfilled' );
		}

		if ( 'paid' === $status ) {
			// Shared, idempotent fulfilment path — also used by the status
			// reconciliation backstop (maybe_complete_from_status). Returns a
			// short result code; the webhook maps that to an HTTP status below.
			$result = self::fulfil_paid_event( $order, $event, 'authed' );
			if ( 'mismatch' === $result ) {
				status_header( 200 );
				exit( 'amount/currency mismatch — order held for review' );
			}
		} elseif ( 'refunded' === $status ) {
			self::reflect_refund_event( $order, $event );
		} elseif ( 'failed' === $status ) {
			// Guard against a late / out-of-order `order.failed` flipping an
			// order that's already paid, completed or refunded. Only an order
			// that never reached a settled state may be marked failed — so a
			// retry that succeeded (or a webhook arriving out of sequence)
			// can't silently fail a paid order.
			if ( ! $order->is_paid() && ! $order->has_status( array( 'processing', 'completed', 'refunded' ) ) ) {
				$order->update_status( 'failed', __( 'Peptide-Pay: payment failed.', 'peptide-pay' ) );
			} else {
				$order->add_order_note( __( 'Peptide-Pay: received a "failed" event for an already-settled order — ignored (kept current status).', 'peptide-pay' ) );
			}
		}

		status_header( 200 );
		exit( 'ok' );
	}

	/**
	 * Idempotent "mark this order paid" routine shared by the webhook and the
	 * status-reconciliation backstop (maybe_complete_from_status). Safe to call
	 * twice: the is_paid() guard short-circuits an already-paid order, so a
	 * webhook + a reconciliation pass that race never double-fulfil.
	 *
	 * Performs the same defensive amount + currency verification as before:
	 * only enforced when $event carries a numeric `amount` (older/sparse status
	 * payloads without it are trusted), an underpayment beyond 2% tolerance or a
	 * currency mismatch puts the order on-hold for manual review instead of
	 * completing it. Never marks paid on ambiguous data.
	 *
	 * @param WC_Order $order
	 * @param array    $event  Webhook event OR normalized status payload.
	 * @param string   $source 'authed' (webhook) or 'reconcile' (status poll) — note text only.
	 * @return string 'completed' | 'already' | 'mismatch'
	 */
	protected static function fulfil_paid_event( $order, $event, $source = 'authed' ) {
		if ( $order->is_paid() ) {
			return 'already';
		}

		$txid = isset( $event['txid'] ) ? sanitize_text_field( (string) $event['txid'] ) : '';

		// Defensive amount + currency verification before fulfilling. The
		// peptide-pay server is the source of truth and should only report
		// paid on a matched payment — but we double-check here so a server
		// bug, FX slippage, or a tampered amount can never make us ship an
		// underpaid order. Only enforced when the payload actually carries an
		// `amount`; payloads without it fall through and are trusted, so we
		// never reject a genuine payment over a missing field.
		$paid_minor     = ( isset( $event['amount'] ) && is_numeric( $event['amount'] ) ) ? (int) round( (float) $event['amount'] ) : null;
		$paid_ccy       = ( isset( $event['currency'] ) && is_string( $event['currency'] ) ) ? strtoupper( $event['currency'] ) : '';
		$order_ccy      = strtoupper( (string) $order->get_currency() );
		$expected_minor = (int) round( ( (float) $order->get_total() ) * 100 );

		if ( null !== $paid_minor ) {
			// 2% under-tolerance absorbs on-ramp rounding / FX; any
			// overpayment is fine. Currency must match the order's
			// (merchant-currency-end-to-end rule) when the payload reports one.
			$min_acceptable = (int) floor( $expected_minor * 0.98 );
			$currency_ok    = ( '' === $paid_ccy || $paid_ccy === $order_ccy );
			if ( ! $currency_ok || $paid_minor < $min_acceptable ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: paid minor units, 2: paid currency, 3: expected minor units, 4: order currency, 5: tx id */
						__( 'Peptide-Pay: payment does NOT match order — held for manual review. Paid %1$d %2$s, expected ~%3$d %4$s. TX: %5$s', 'peptide-pay' ),
						$paid_minor,
						$paid_ccy ? $paid_ccy : '?',
						$expected_minor,
						$order_ccy,
						$txid ? $txid : 'n/a'
					)
				);
				return 'mismatch';
			}
		}

		$order->payment_complete( $txid );
		$order->add_order_note(
			sprintf(
				/* translators: 1: source (authed webhook / reconciliation), 2: transaction id */
				__( 'Peptide-Pay payment received (%1$s). TX: %2$s', 'peptide-pay' ),
				'reconcile' === $source ? __( 'status reconciliation', 'peptide-pay' ) : __( 'authed', 'peptide-pay' ),
				$txid ? $txid : 'n/a'
			)
		);
		if ( null === $paid_minor ) {
			$order->add_order_note( __( 'Peptide-Pay: note — payload carried no amount field; paid total was not independently verified.', 'peptide-pay' ) );
		}
		return 'completed';
	}

	/**
	 * Reflect an `order.refunded` webhook into WooCommerce. Previously this was
	 * a bare order note, so a refund issued on the Peptide-Pay side never moved
	 * the WC order out of its paid status — leaving the merchant's books wrong.
	 *
	 * We create a WC refund for the reported amount (idempotent: if the order
	 * already has refunds totalling >= the reported amount we only add a note),
	 * which moves the order to `refunded` when fully refunded and restores
	 * stock / totals through WC's own pipeline. On any failure we fall back to
	 * a note so a malformed payload never throws inside the webhook handler.
	 *
	 * @param WC_Order $order
	 * @param array    $event
	 * @return void
	 */
	protected static function reflect_refund_event( $order, $event ) {
		// Amount is sent in minor units (same scale as checkout/init). Convert
		// back to the store's major-unit decimal for wc_create_refund(). When the
		// payload omits an amount, refund the full remaining total.
		$already   = (float) $order->get_total_refunded();
		$order_tot = (float) $order->get_total();
		$remaining = max( 0.0, $order_tot - $already );

		$amount = $remaining;
		if ( isset( $event['amount'] ) && is_numeric( $event['amount'] ) ) {
			$amount = round( ( (float) $event['amount'] ) / 100, wc_get_price_decimals() );
			// Never refund more than what's still refundable on the order.
			if ( $amount > $remaining ) {
				$amount = $remaining;
			}
		}

		$txid = isset( $event['txid'] ) ? sanitize_text_field( (string) $event['txid'] ) : '';

		if ( $amount <= 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: transaction id */
					__( 'Peptide-Pay: refund event received but order is already fully refunded. TX: %s', 'peptide-pay' ),
					$txid ? $txid : 'n/a'
				)
			);
			return;
		}

		if ( ! function_exists( 'wc_create_refund' ) ) {
			$order->add_order_note( __( 'Peptide-Pay: refund event received (could not auto-create WC refund — wc_create_refund unavailable). Please refund manually.', 'peptide-pay' ) );
			return;
		}

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => sprintf(
					/* translators: %s: transaction id */
					__( 'Peptide-Pay refund webhook. TX: %s', 'peptide-pay' ),
					$txid ? $txid : 'n/a'
				),
			)
		);

		if ( is_wp_error( $refund ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Peptide-Pay: refund event received but auto-refund failed (%s). Please refund manually.', 'peptide-pay' ),
					$refund->get_error_message()
				)
			);
		}
	}

	/**
	 * WC refund hook (merchant clicks "Refund" in wp-admin). On-ramp refunds
	 * are settled on the Peptide-Pay side, not via a card-processor API, so we
	 * record the WC-side refund (which this return value commits) and leave a
	 * note instructing the merchant to action the actual payout in the
	 * Peptide-Pay dashboard. Returning true lets WC create the refund line /
	 * adjust the order total without us pretending to have moved money.
	 *
	 * @param int        $order_id
	 * @param float|null $amount
	 * @param string     $reason
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'peptide_pay_refund', __( 'Order not found.', 'peptide-pay' ) );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount, 2: reason */
				__( 'Peptide-Pay: WC refund recorded for %1$s. Action the payout in the Peptide-Pay dashboard — on-ramp refunds are settled there, not automatically. Reason: %2$s', 'peptide-pay' ),
				wc_price( null === $amount ? $order->get_total() : (float) $amount ),
				'' !== $reason ? $reason : __( '(none given)', 'peptide-pay' )
			)
		);
		return true;
	}

	/**
	 * Reconciliation backstop (P0-4). Fetches authoritative status for the
	 * order's stored Peptide-Pay session from the API and, when the session is
	 * reported paid, routes through the SAME idempotent fulfilment path as the
	 * webhook (fulfil_paid_event). Safe to call repeatedly — an already-paid
	 * order short-circuits. Fails safe: never marks paid on an ambiguous or
	 * failed status response (network error, non-2xx, unparseable body, or any
	 * status other than an explicit paid/completed).
	 *
	 * Wired to:
	 *   (a) woocommerce_thankyou for unpaid peptide_pay_* orders, and
	 *   (b) a recurring cron (peptide_pay_reconcile) over pending/on-hold
	 *       Peptide-Pay orders younger than 24h.
	 *
	 * @param WC_Order $order
	 * @return bool True if the order ended up paid as a result of this call.
	 */
	public function maybe_complete_from_status( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( $order->is_paid() ) {
			return true; // Nothing to do — already fulfilled (webhook or prior poll).
		}

		$session_id = (string) $order->get_meta( '_peptide_pay_session_id' );
		if ( '' === $session_id ) {
			return false; // No session to reconcile against (order never reached checkout/init).
		}
		if ( '' === $this->api_key ) {
			return false; // Can't authenticate the status call.
		}

		// ── Status endpoint ──────────────────────────────────────────────
		// VERIFIED against the server (src/app/api/v1/sessions/[id]/route.ts):
		// the authoritative per-session status is GET {API_BASE}/sessions/{id}.
		// It accepts our Bearer API key (and 403s if the session doesn't belong
		// to this merchant), re-polls PayGate as a lost-IPN safety net, and
		// returns { id, status, amount, currency, paid_at, paid_provider, txid }.
		// `status` becomes 'paid' once settled.
		$status_url = PEPTIDE_PAY_API_BASE . '/sessions/' . rawurlencode( $session_id );

		$response = wp_remote_get(
			$status_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'              => 'application/json',
					'Authorization'       => 'Bearer ' . $this->api_key,
					'User-Agent'          => 'peptide-pay-woocommerce/' . PEPTIDE_PAY_VERSION,
					'X-PeptidePay-Source' => 'woocommerce-plugin',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'reconcile status wp_error for session ' . $session_id . ': ' . $response->get_error_message() );
			return false; // Fail safe — never mark paid on a network error.
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log( 'reconcile status http ' . $code . ' for session ' . $session_id );
			return false; // Fail safe — non-2xx is ambiguous.
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$this->log( 'reconcile status unparseable body for session ' . $session_id );
			return false; // Fail safe.
		}

		// Normalize the status field. Accept the same vocabulary the webhook
		// uses ("paid"); also accept "completed"/"succeeded" defensively in case
		// the status endpoint reports a session lifecycle term. Anything else
		// (pending, processing, failed, expired, unknown) is NOT a paid signal.
		$raw_status = '';
		if ( isset( $body['status'] ) && is_string( $body['status'] ) ) {
			$raw_status = strtolower( trim( $body['status'] ) );
		}
		$paid_terms = array( 'paid', 'completed', 'succeeded', 'success' );
		if ( ! in_array( $raw_status, $paid_terms, true ) ) {
			return false; // Fail safe — only fulfil on an explicit paid status.
		}

		// Bind the status response to this order's stored session (defence in
		// depth — the URL already keys on session_id, but verify any echoed id).
		if ( isset( $body['id'] ) && is_string( $body['id'] ) && '' !== $body['id'] ) {
			if ( ! hash_equals( $session_id, sanitize_text_field( $body['id'] ) ) ) {
				$this->log( 'reconcile status id mismatch for session ' . $session_id );
				return false;
			}
		}

		// Hand off to the shared idempotent fulfilment path. Pass through any
		// amount/currency the status response carries so the same defensive
		// verification runs (under-paid → on-hold, never silently completed).
		$result = self::fulfil_paid_event( $order, $body, 'reconcile' );
		return ( 'completed' === $result || 'already' === $result );
	}

	/**
	 * woocommerce_thankyou hook: when a customer lands on the return page for
	 * an unpaid Peptide-Pay order, proactively reconcile its status so a dropped
	 * webhook doesn't leave a paid order stuck on "pending".
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function thankyou_reconcile( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		if ( 0 !== strpos( (string) $order->get_payment_method(), 'peptide_pay_' ) ) {
			return;
		}
		$gateway = self::get_gateway_for_order( $order );
		if ( $gateway ) {
			$gateway->maybe_complete_from_status( $order );
		}
	}

	/**
	 * Recurring reconciliation (cron callback). Sweeps pending/on-hold
	 * Peptide-Pay orders younger than 24h and reconciles each against the API,
	 * so a permanently-dropped webhook still gets caught within an hour.
	 *
	 * @return void
	 */
	public static function cron_reconcile() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$orders = wc_get_orders(
			array(
				'limit'        => 50,
				'status'       => array( 'pending', 'on-hold' ),
				'date_created' => '>' . ( time() - DAY_IN_SECONDS ),
				'return'       => 'objects',
			)
		);
		if ( empty( $orders ) ) {
			return;
		}
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || $order->is_paid() ) {
				continue;
			}
			if ( 0 !== strpos( (string) $order->get_payment_method(), 'peptide_pay_' ) ) {
				continue;
			}
			if ( '' === (string) $order->get_meta( '_peptide_pay_session_id' ) ) {
				continue;
			}
			$gateway = self::get_gateway_for_order( $order );
			if ( $gateway ) {
				$gateway->maybe_complete_from_status( $order );
			}
		}
	}

	/**
	 * Resolve the live gateway instance whose credentials match an order's
	 * payment method, so reconciliation uses the right api_key. Falls back to
	 * any configured peptide_pay_* gateway with an api_key if the exact id is
	 * unavailable (e.g. a sub-gateway was disabled after the order was placed).
	 *
	 * @param WC_Order $order
	 * @return WC_Gateway_Peptide_Pay_Base|null
	 */
	protected static function get_gateway_for_order( $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways  = WC()->payment_gateways()->payment_gateways();
		$method_id = (string) $order->get_payment_method();

		if ( isset( $gateways[ $method_id ] ) && $gateways[ $method_id ] instanceof WC_Gateway_Peptide_Pay_Base ) {
			$gw = $gateways[ $method_id ];
			if ( '' !== trim( (string) $gw->api_key ) ) {
				return $gw;
			}
		}
		foreach ( $gateways as $gw ) {
			if ( $gw instanceof WC_Gateway_Peptide_Pay_Base && '' !== trim( (string) $gw->api_key ) ) {
				return $gw;
			}
		}
		return null;
	}

	/**
	 * Verbose request/response trace. Only written when the merchant has
	 * explicitly enabled "Debug log" — keeps the log file small in production
	 * but lets support capture full payloads when something is broken.
	 */
	protected function log( $message ) {
		if ( ! $this->debug_log ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( '[' . $this->provider_code . '] ' . $message, array( 'source' => 'peptide-pay' ) );
		}
	}

	/**
	 * Always-on error logging for customer-facing failures (wp_error,
	 * non-2xx, invalid redirect URL, thrown exceptions). Unlike log(), this
	 * writes regardless of the debug_log setting — a payment that fails for
	 * a customer must always leave a trace under WooCommerce → Status → Logs
	 * so the merchant/support can diagnose it without first reproducing the
	 * failure with debug mode toggled on. Routed at error() level so it
	 * surfaces even with WC's log level filtered to errors only.
	 */
	protected function log_error( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( '[' . $this->provider_code . '] ' . $message, array( 'source' => 'peptide-pay' ) );
		}
	}

	/**
	 * Wire the P0-4 reconciliation backstop. Registered here (rather than in
	 * the main plugin bootstrap) so the whole reconciliation feature lives in
	 * one file. Idempotent — guarded against double-registration.
	 *
	 *   (a) woocommerce_thankyou → re-check unpaid peptide_pay_* orders.
	 *   (b) hourly WP-Cron sweep over pending/on-hold orders younger than 24h.
	 */
	public static function init_reconciliation() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_reconcile' ), 5 );

		add_action( self::RECONCILE_CRON_HOOK, array( __CLASS__, 'cron_reconcile' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::RECONCILE_CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::RECONCILE_CRON_HOOK );
		}
	}
}

// Cron hook name for the recurring reconciliation sweep (P0-4). Defined as a
// class constant above for callers; this self-registration runs at require-time
// (the file is loaded inside peptide_pay_init on plugins_loaded, after WC is
// confirmed present), so the thankyou hook + hourly cron are always wired
// without modifying the main plugin bootstrap.
WC_Gateway_Peptide_Pay_Base::init_reconciliation();
