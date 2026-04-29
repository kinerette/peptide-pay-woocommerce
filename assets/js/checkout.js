/**
 * Peptide-Pay — checkout loading state
 *
 * Hooks the WooCommerce classic-checkout submit and gives the customer
 * immediate visual feedback when they click "Pay $XX →". Without this,
 * there's a 1-3s gap between the click and the redirect to peptide-pay.com
 * during which nothing visible happens — the audit caught customers
 * thinking the page froze and abandoning.
 *
 * Behaviour:
 *  - On submit, if Peptide-Pay is the chosen method:
 *      • Disable the order button.
 *      • Swap its label to "Redirecting to secure checkout…" + spinner.
 *  - 30s fallback: if nothing happened (timeout, network error swallowed
 *    by WC's AJAX layer), re-enable the button and surface a notice so
 *    the customer can retry instead of staring at a permanent spinner.
 */
( function ( $ ) {
	'use strict';

	if ( typeof $ === 'undefined' || ! $.fn ) {
		return;
	}

	var ORIGINAL_LABEL = null;
	var FALLBACK_TIMER = null;
	var SPINNER_HTML = '<span class="peptide-pay-spinner" aria-hidden="true" style="display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;margin-right:8px;vertical-align:middle;animation:peptide-pay-spin 0.7s linear infinite;"></span>';

	function isPeptidePayChosen() {
		var $checked = $( 'input[name="payment_method"]:checked' );
		if ( ! $checked.length ) {
			return false;
		}
		var val = String( $checked.val() || '' );
		return val.indexOf( 'peptide_pay_' ) === 0;
	}

	function setLoading( $button, label ) {
		if ( ! $button.length ) {
			return;
		}
		if ( null === ORIGINAL_LABEL ) {
			ORIGINAL_LABEL = $button.html();
		}
		$button.prop( 'disabled', true ).attr( 'aria-busy', 'true' );
		$button.html( SPINNER_HTML + label );
	}

	function clearLoading( $button ) {
		if ( ! $button.length ) {
			return;
		}
		$button.prop( 'disabled', false ).removeAttr( 'aria-busy' );
		if ( null !== ORIGINAL_LABEL ) {
			$button.html( ORIGINAL_LABEL );
			ORIGINAL_LABEL = null;
		}
		if ( FALLBACK_TIMER ) {
			clearTimeout( FALLBACK_TIMER );
			FALLBACK_TIMER = null;
		}
	}

	// Inject our spin keyframe once.
	$( function () {
		if ( ! document.getElementById( 'peptide-pay-spin-style' ) ) {
			var style = document.createElement( 'style' );
			style.id = 'peptide-pay-spin-style';
			style.textContent = '@keyframes peptide-pay-spin{to{transform:rotate(360deg);}}';
			document.head.appendChild( style );
		}
	} );

	// WooCommerce fires `checkout_place_order` right before submit. If a
	// site-side validator returns false, we don't want to start the
	// loader — return value of `true` from the trigger handler signals
	// "ok to submit", false aborts.
	$( document ).on( 'checkout_place_order_peptide_pay_gateway checkout_place_order', function () {
		if ( ! isPeptidePayChosen() ) {
			return true;
		}
		var $button = $( '#place_order' );
		setLoading( $button, 'Redirecting to secure checkout…' );

		// 30s fallback — re-enable the button if WC's AJAX layer
		// silently failed (timeout, network drop, server 5xx). Without
		// this the customer sits forever on a spinning button.
		FALLBACK_TIMER = setTimeout( function () {
			clearLoading( $button );
			if ( typeof $.fn.notice !== 'undefined' || typeof window.alert !== 'undefined' ) {
				if ( window.console && window.console.warn ) {
					console.warn( 'Peptide-Pay: no redirect within 30s — re-enabling button.' );
				}
			}
		}, 30000 );

		return true;
	} );

	// If WC fires a checkout error (validation failed, ajax 4xx/5xx),
	// re-enable our button immediately.
	$( document.body ).on( 'checkout_error', function () {
		clearLoading( $( '#place_order' ) );
	} );

	// Same for the updated_checkout event — a price/cart refresh during
	// a click should restore the button if our loader is up.
	$( document.body ).on( 'updated_checkout', function () {
		var $button = $( '#place_order' );
		if ( null !== ORIGINAL_LABEL && ! $button.attr( 'aria-busy' ) ) {
			clearLoading( $button );
		}
	} );
} )( window.jQuery );
