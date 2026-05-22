<?php
/**
 * Peptide-Pay — WooCommerce Blocks (Cart/Checkout) support.
 *
 * Registers every Peptide-Pay sub-gateway as a payment method type so they
 * appear on block-based checkouts. Single class instanced N times (one per
 * sub-gateway) — no duplication.
 *
 * @package Peptide_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class Peptide_Pay_Blocks_Support extends AbstractPaymentMethodType {

	/** @var string WC_Payment_Gateway subclass name. */
	protected $gateway_class;

	/** @var WC_Gateway_Peptide_Pay_Base */
	protected $gateway;

	public function __construct( $gateway_class ) {
		$this->gateway_class = $gateway_class;
		$this->gateway       = new $gateway_class();
		// Name MUST match the gateway's ID so WC Blocks pairs them correctly.
		$this->name = $this->gateway->id;
	}

	public function initialize() {
		// Settings are already loaded by the gateway instance; nothing extra here.
	}

	public function is_active() {
		return 'yes' === $this->gateway->enabled;
	}

	public function get_payment_method_script_handles() {
		// A single shared JS file registers every gateway. WC Blocks calls this
		// method once per active payment method type, so we append each
		// gateway's id to a JS global (`window.peptidePayBlockIds`) inline —
		// the shared script then reads each gateway's data via the standard
		// getSetting('<id>_data') accessor and registers it. `wc-settings` is a
		// required dep so window.wc.wcSettings is guaranteed before the script.
		$handle = 'peptide-pay-blocks';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				PEPTIDE_PAY_URL . 'assets/js/blocks-support.js',
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
				PEPTIDE_PAY_VERSION,
				true
			);
		}
		wp_add_inline_script(
			$handle,
			'window.peptidePayBlockIds=(window.peptidePayBlockIds||[]).concat(' . wp_json_encode( $this->gateway->id ) . ');',
			'before'
		);
		return array( $handle );
	}

	public function get_payment_method_data() {
		$icon = '';
		if ( method_exists( $this->gateway, 'get_icon' ) ) {
			// Prefer the HTML icon if WC has built one (wraps <img>), otherwise raw URL.
			$icon = $this->gateway->icon ? (string) $this->gateway->icon : '';
		}
		return array(
			'id'          => $this->gateway->id,
			'title'       => $this->gateway->get_title(),
			'description' => $this->gateway->get_description(),
			'icon'        => $icon,
			'supports'    => array( 'products' ),
		);
	}
}
