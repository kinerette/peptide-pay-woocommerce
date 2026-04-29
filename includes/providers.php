<?php
/**
 * Peptide-Pay — provider gateway classes.
 *
 * One class per live PayGate provider. Source of truth = the live API at
 * https://www.peptide-pay.com/api/v1/providers (cached via transient in
 * the main plugin file). This file MUST stay aligned with that endpoint;
 * stale providers here cause "ghost" rows in WC → Settings → Payments
 * that send merchants to broken on-ramps. The runtime filter in
 * peptide_pay_add_gateways() also strips classes whose provider_code
 * isn't returned by the live API, so we degrade safely if PayGate
 * retires a provider between plugin updates.
 *
 * @package Peptide_Pay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Tier 1 — core card + wallet on-ramps (most reliable, try first) ───

class WC_Gateway_Peptide_Pay_Smart extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'gateway';
	protected $provider_method_title = 'Peptide-Pay — Smart (Recommended)';
	protected $provider_method_description = 'Hosted multi-provider checkout. Customer picks their preferred rail (card, Apple Pay, Revolut, crypto, ...) on a page tuned for maximum approval.';
	protected $provider_default_title = 'Peptide-Pay';
	protected $provider_default_description = 'Pay with Visa, Mastercard, Apple Pay, Google Pay, Revolut or crypto. Secure checkout.';

	/**
	 * Append the global "show individual provider gateways" toggle to
	 * the Smart gateway's settings form. Mirrored to a wp_option in
	 * process_admin_options so peptide_pay_add_gateways() can decide
	 * whether to register the 13 non-Smart sub-classes at all.
	 *
	 * Customers always see all providers inside the hosted checkout —
	 * this only affects the merchant-side WC → Settings → Payments
	 * list (the "wall of 14 rows" friction Pierre persona caught).
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		// Pull custom_css + debug_log out so we can re-append them at the
		// end, after the new style controls — order matters in the WC
		// settings UI (PHP preserves insertion order).
		$custom_css_field = isset( $this->form_fields['custom_css'] ) ? $this->form_fields['custom_css'] : null;
		$debug_log_field  = isset( $this->form_fields['debug_log'] )  ? $this->form_fields['debug_log']  : null;
		unset( $this->form_fields['custom_css'], $this->form_fields['debug_log'] );

		$default = function_exists( 'peptide_pay_default_show_individual_gateways' )
			&& peptide_pay_default_show_individual_gateways()
				? 'yes'
				: 'no';
		$this->form_fields['provider_mode'] = array(
			'title'       => __( 'Checkout mode', 'peptide-pay' ),
			'type'        => 'select',
			'description' => __( 'Smart (recommended): customer picks their preferred on-ramp on our hosted page. Direct: skip the selection page and send the customer straight to the chosen provider.', 'peptide-pay' ),
			'default'     => 'gateway',
			'options'     => array(
				'gateway'     => __( 'Smart — show all providers (recommended)', 'peptide-pay' ),
				'moonpay'     => __( 'Direct → Moonpay', 'peptide-pay' ),
				'revolut'     => __( 'Direct → Revolut Pay', 'peptide-pay' ),
				'simplex'     => __( 'Direct → Simplex', 'peptide-pay' ),
				'banxa'       => __( 'Direct → Banxa', 'peptide-pay' ),
				'transak'     => __( 'Direct → Transak', 'peptide-pay' ),
				'binance'     => __( 'Direct → Binance Pay', 'peptide-pay' ),
				'cryptocom'   => __( 'Direct → Crypto.com Pay', 'peptide-pay' ),
				'alchemypay'  => __( 'Direct → AlchemyPay', 'peptide-pay' ),
				'sardine'     => __( 'Direct → Sardine', 'peptide-pay' ),
				'rampnetwork' => __( 'Direct → Ramp Network', 'peptide-pay' ),
				'bitnovo'     => __( 'Direct → Bitnovo', 'peptide-pay' ),
				'interac'     => __( 'Direct → Interac (Canada)', 'peptide-pay' ),
				'upi'         => __( 'Direct → UPI (India)', 'peptide-pay' ),
			),
			'desc_tip'    => false,
		);
		$this->form_fields['show_individual_gateways'] = array(
			'title'       => __( 'One method only OR all methods at checkout', 'peptide-pay' ),
			'type'        => 'checkbox',
			'label'       => __( 'Show every individual provider (Moonpay, Revolut, Binance, etc.) as its own checkout option.', 'peptide-pay' ),
			'description' => __( 'OFF (recommended): customers see ONE option called "Peptide-Pay" at checkout, then pick their preferred rail (card / Apple Pay / Revolut / crypto) on our hosted page — best conversion. ON: each provider appears as its own row at checkout — useful only if you want to push one specific rail (e.g. only Moonpay) or hide everything except a few. Most merchants leave this OFF.', 'peptide-pay' ),
			'default'     => $default,
			'desc_tip'    => false,
		);
		$this->form_fields['show_payment_logos'] = array(
			'title'       => __( 'Show payment method logos at checkout', 'peptide-pay' ),
			'type'        => 'checkbox',
			'label'       => __( 'Display Visa / Mastercard / Apple Pay / Google Pay / Revolut / Crypto badges under the description.', 'peptide-pay' ),
			'description' => __( 'Disable if your theme already shows badges or if you want a text-only checkout row.', 'peptide-pay' ),
			'default'     => 'yes',
			'desc_tip'    => false,
		);
		$this->form_fields['show_trust_signals'] = array(
			'title'       => __( 'Show trust signals', 'peptide-pay' ),
			'type'        => 'checkbox',
			'label'       => __( 'Show "Encrypted checkout · Card processing via licensed on-ramps" line above the logos.', 'peptide-pay' ),
			'description' => __( 'Adds a small lock-icon line that reassures customers their card data is safe. Recommended ON — improves conversion on cold visitors. Honest framing: card processing happens at licensed regulated on-ramps (MoonPay, Revolut, Banxa); we never see card data.', 'peptide-pay' ),
			'default'     => 'yes',
			'desc_tip'    => false,
		);

		$this->form_fields['style_section'] = array(
			'title'       => __( '🎨 Checkout style', 'peptide-pay' ),
			'type'        => 'title',
			'description' => __( 'Tweak how the gateway box looks at checkout. Pick a preset, fine-tune the colours and corners — no CSS required. Power users can still paste raw CSS at the bottom.', 'peptide-pay' ),
		);
		$this->form_fields['style_preset'] = array(
			'title'       => __( 'Style preset', 'peptide-pay' ),
			'type'        => 'select',
			'description' => __( 'Pick a starting look. The fields below override individual values; leave them empty to inherit from the preset.', 'peptide-pay' ),
			'default'     => 'modern',
			'options'     => array(
				'modern'  => __( 'Modern (recommended) — soft shadow, rounded corners', 'peptide-pay' ),
				'glass'   => __( 'Glass — frosted-glass effect', 'peptide-pay' ),
				'minimal' => __( 'Minimal — no border, no background', 'peptide-pay' ),
				'classic' => __( 'Classic — WooCommerce default (boring but safe)', 'peptide-pay' ),
			),
			'desc_tip'    => false,
		);
		$this->form_fields['style_border_color'] = array(
			'title'       => __( 'Border colour', 'peptide-pay' ),
			'type'        => 'text',
			'class'       => 'peptide-pay-color-field',
			'description' => __( 'Hex (e.g. <code>#10b981</code>) or any CSS colour. Leave empty to use the preset default.', 'peptide-pay' ),
			'placeholder' => '#e5e7eb',
			'default'     => '',
		);
		$this->form_fields['style_bg_color'] = array(
			'title'       => __( 'Background colour', 'peptide-pay' ),
			'type'        => 'text',
			'class'       => 'peptide-pay-color-field',
			'description' => __( 'Hex or CSS colour. Try <code>rgba(255,255,255,0.7)</code> with the Glass preset for a translucent look.', 'peptide-pay' ),
			'placeholder' => '#ffffff',
			'default'     => '',
		);
		$this->form_fields['style_radius'] = array(
			'title'       => __( 'Corner radius (px)', 'peptide-pay' ),
			'type'        => 'number',
			'description' => __( '0 = sharp · 8 = soft · 16 = rounded · 32 = pill.', 'peptide-pay' ),
			'custom_attributes' => array(
				'min'  => '0',
				'max'  => '32',
				'step' => '1',
			),
			'default'     => '',
			'placeholder' => '16',
		);
		$this->form_fields['style_logo_size'] = array(
			'title'       => __( 'Logo height (px)', 'peptide-pay' ),
			'type'        => 'number',
			'description' => __( 'How big the Visa / Mastercard / Apple Pay badges appear under the description. 16 = tiny · 24 = default · 36 = bold · 48 = huge.', 'peptide-pay' ),
			'custom_attributes' => array(
				'min'  => '16',
				'max'  => '48',
				'step' => '2',
			),
			'default'     => '',
			'placeholder' => '24',
		);
		$this->form_fields['style_hover_lift'] = array(
			'title'       => __( 'Hover lift animation', 'peptide-pay' ),
			'type'        => 'checkbox',
			'label'       => __( 'Subtle 1 px lift + softer shadow when the customer mouses over the box.', 'peptide-pay' ),
			'default'     => 'yes',
		);
		$this->form_fields['custom_css_section'] = array(
			'title'       => __( '⚡ Power user override', 'peptide-pay' ),
			'type'        => 'title',
			'description' => __( 'Skip this if you have no idea what CSS is — the controls above already cover 95% of cases.', 'peptide-pay' ),
		);

		// Re-append the inherited fields at the end, in this order:
		// custom_css → debug_log. Defensive `if` in case the parent stops
		// shipping them in the future.
		if ( null !== $custom_css_field ) {
			$this->form_fields['custom_css'] = $custom_css_field;
		}
		if ( null !== $debug_log_field ) {
			$this->form_fields['debug_log'] = $debug_log_field;
		}
	}

	protected function get_effective_provider_code() {
		$mode = (string) $this->get_option( 'provider_mode', 'gateway' );
		return '' !== $mode ? $mode : 'gateway';
	}

	public function process_admin_options() {
		$result = parent::process_admin_options();
		$val    = ( 'yes' === $this->get_option( 'show_individual_gateways' ) ) ? 'yes' : 'no';
		// Mirror the per-Smart-gateway option to the global wp_option
		// read by peptide_pay_add_gateways(). Done with `update_option`
		// (not just `update_option_via_settings_api`) so a flag flip
		// takes effect on the *next* page load without a plugin reload.
		update_option( PEPTIDE_PAY_SHOW_INDIVIDUAL_OPTION, $val, false );
		return $result;
	}

	/**
	 * Render the description plus a row of accepted-method logos so the
	 * customer immediately sees the brand badges without the merchant
	 * having to wire up a custom theme. SVG files served from the plugin's
	 * own assets/ directory — same artwork as peptide-pay.com homepage.
	 *
	 * Customisation hooks (for agencies / theme devs):
	 *   - filter `peptide_pay_card_logos` (array file => label)
	 *     to replace the list of badges entirely.
	 *   - filter `peptide_pay_card_logos_html` (string)
	 *     to override the rendered HTML row.
	 *   - action `peptide_pay_payment_fields_after`
	 *     to append arbitrary HTML after our render.
	 */
	public function payment_fields() {
		parent::payment_fields();

		// Trust signal row — small lock + honest line about who actually
		// handles the card data (we don't, our PCI-DSS partners do).
		// Renders before the logos row so it lands closer to the eye.
		if ( 'yes' === $this->get_option( 'show_trust_signals', 'yes' ) ) {
			echo '<div class="peptide-pay-trust" style="display:flex;align-items:center;gap:8px;margin:10px 0 0;font-size:12.5px;color:#3f3f46;">';
			echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;color:#10b981;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
			echo '<span>' . esc_html__( 'Encrypted checkout — card processing via licensed on-ramps (MoonPay, Revolut, Banxa).', 'peptide-pay' ) . '</span>';
			echo '</div>';
		}

		if ( 'yes' === $this->get_option( 'show_payment_logos', 'yes' ) ) {
			$base = PEPTIDE_PAY_URL . 'assets/logos/';

			$logos = apply_filters(
				'peptide_pay_card_logos',
				array(
					'visa.svg'             => 'Visa',
					'mastercard.svg'       => 'Mastercard',
					'amex-color.svg'       => 'American Express',
					'apple-pay-color.svg'  => 'Apple Pay',
					'google-pay-color.svg' => 'Google Pay',
					'revolut.svg'          => 'Revolut',
				),
				$this
			);

			// Minimal fallback inline styles — only the layout that must hold
			// up if the merchant's theme CSS / our injected style block fail
			// to load. Visual styling (size, padding, badge background) lives
			// in peptide_pay_build_styles_css() so the merchant can tweak it
			// from the admin "Checkout style" section.
			$row_style = 'display:flex;flex-wrap:wrap;align-items:center;';

			$html  = '<div class="peptide-pay-card-strip" style="' . esc_attr( $row_style ) . '" aria-label="' . esc_attr__( 'Accepted payment methods', 'peptide-pay' ) . '">';
			foreach ( $logos as $file => $label ) {
				$src = ( false !== strpos( (string) $file, '://' ) )
					? (string) $file
					: $base . $file;
				$html .= sprintf(
					'<img src="%s" alt="%s" loading="lazy" />',
					esc_url( $src ),
					esc_attr( (string) $label )
				);
			}
			$html .= '</div>';

			echo apply_filters( 'peptide_pay_card_logos_html', $html, $logos, $this );
		}

		do_action( 'peptide_pay_payment_fields_after', $this );
	}
}

class WC_Gateway_Peptide_Pay_Moonpay extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'moonpay';
	protected $provider_method_title = 'Peptide-Pay — Credit Card (Moonpay)';
	protected $provider_method_description = 'Accept Visa / Mastercard via Moonpay. Best global card coverage.';
	protected $provider_default_title = 'Credit / Debit Card';
	protected $provider_default_description = 'Pay by Visa or Mastercard. Powered by Moonpay.';
}

class WC_Gateway_Peptide_Pay_Revolut extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'revolut';
	protected $provider_method_title = 'Peptide-Pay — Revolut Pay';
	protected $provider_method_description = 'One-click checkout for Revolut users (huge in UK + EU).';
	protected $provider_default_title = 'Revolut Pay';
	protected $provider_default_description = 'Pay in one click with your Revolut account.';
}

class WC_Gateway_Peptide_Pay_Cryptocom extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'cryptocom';
	protected $provider_method_title = 'Peptide-Pay — Crypto.com Pay';
	protected $provider_method_description = 'Pay from Crypto.com app balance. Strong card + crypto coverage globally.';
	protected $provider_default_title = 'Crypto.com Pay';
	protected $provider_default_description = 'Pay with your Crypto.com account.';
}

class WC_Gateway_Peptide_Pay_Binance extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'binance';
	protected $provider_method_title = 'Peptide-Pay — Binance Pay';
	protected $provider_method_description = 'Pay from Binance account balance (global).';
	protected $provider_default_title = 'Binance Pay';
	protected $provider_default_description = 'Pay with your Binance account balance.';
}

// ─── Tier 2 — European & global on-ramps ───────────────────────────────

class WC_Gateway_Peptide_Pay_Banxa extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'banxa';
	protected $provider_method_title = 'Peptide-Pay — Banxa';
	protected $provider_method_description = 'Regulated on-ramp, strong in APAC + EU.';
	protected $provider_default_title = 'Banxa';
	protected $provider_default_description = 'Pay by card via Banxa (regulated on-ramp).';
}

class WC_Gateway_Peptide_Pay_Transak extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'transak';
	protected $provider_method_title = 'Peptide-Pay — Transak';
	protected $provider_method_description = 'Cards, SEPA, UPI via Transak (100+ countries).';
	protected $provider_default_title = 'Transak';
	protected $provider_default_description = 'Pay by card or bank transfer via Transak.';
}

class WC_Gateway_Peptide_Pay_RampNetwork extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'rampnetwork';
	protected $provider_method_title = 'Peptide-Pay — Ramp Network';
	protected $provider_method_description = 'Card + bank transfer on-ramp. USD only.';
	protected $provider_default_title = 'Ramp Network';
	protected $provider_default_description = 'Pay with card via Ramp Network.';
	protected $provider_forced_currency = 'USD';
}

class WC_Gateway_Peptide_Pay_Sardine extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'sardine';
	protected $provider_method_title = 'Peptide-Pay — Sardine';
	protected $provider_method_description = 'Fraud-optimised card on-ramp, lower decline rate.';
	protected $provider_default_title = 'Sardine';
	protected $provider_default_description = 'Pay by card via Sardine.';
}

class WC_Gateway_Peptide_Pay_Bitnovo extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'bitnovo';
	protected $provider_method_title = 'Peptide-Pay — Bitnovo';
	protected $provider_method_description = 'Card on-ramp — strong in Southern Europe. USD only.';
	protected $provider_default_title = 'Bitnovo';
	protected $provider_default_description = 'Pay by card via Bitnovo.';
	protected $provider_forced_currency = 'USD';
}

class WC_Gateway_Peptide_Pay_Simplex extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'simplex';
	protected $provider_method_title = 'Peptide-Pay — Simplex';
	protected $provider_method_description = 'Licensed EU e-money institution, card on-ramp.';
	protected $provider_default_title = 'Simplex';
	protected $provider_default_description = 'Pay by card via Simplex.';
}

class WC_Gateway_Peptide_Pay_Alchemypay extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'alchemypay';
	protected $provider_method_title = 'Peptide-Pay — AlchemyPay';
	protected $provider_method_description = 'Asia-focused on-ramp — Visa / Mastercard / Apple Pay / Google Pay.';
	protected $provider_default_title = 'AlchemyPay';
	protected $provider_default_description = 'Pay by card or wallet via AlchemyPay.';
}

// ─── Tier 3 — country-locked rails ─────────────────────────────────────

class WC_Gateway_Peptide_Pay_Interac extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'interac';
	protected $provider_method_title = 'Peptide-Pay — Interac (Canada)';
	protected $provider_method_description = 'Canadian Interac e-Transfer. CAD only.';
	protected $provider_default_title = 'Interac e-Transfer';
	protected $provider_default_description = 'Pay with Interac e-Transfer (Canada only).';
	protected $provider_forced_currency = 'CAD';
}

class WC_Gateway_Peptide_Pay_Upi extends WC_Gateway_Peptide_Pay_Base {
	protected $provider_code = 'upi';
	protected $provider_method_title = 'Peptide-Pay — UPI (India)';
	protected $provider_method_description = 'UPI bank-transfer, India only. INR only.';
	protected $provider_default_title = 'UPI';
	protected $provider_default_description = 'Pay with UPI (India only).';
	protected $provider_forced_currency = 'INR';
}
