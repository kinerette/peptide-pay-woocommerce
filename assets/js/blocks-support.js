/**
 * Peptide-Pay — WooCommerce Blocks (Cart/Checkout) integration.
 *
 * For each Peptide-Pay sub-gateway, the PHP AbstractPaymentMethodType injects
 * its registration data under the standard `<id>_data` key (read via
 * wc.wcSettings.getSetting) and appends its id to `window.peptidePayBlockIds`
 * (inline, before this file). We register each one with the Blocks registry.
 *
 * NOTE (2026-05-22 fix): the previous version iterated
 * `wc.wcSettings.ALL_SETTINGS_DATA` — that property does NOT exist in the WC
 * settings API, so the loop never ran and registerPaymentMethod() was never
 * called → Peptide-Pay was invisible on Blocks-based checkouts. Now driven by
 * the explicit id list + the documented getSetting('<id>_data') accessor.
 */
(function () {
	if (
		!window.wp ||
		!window.wc ||
		!window.wc.wcBlocksRegistry ||
		!window.wc.wcSettings ||
		!window.wp.element
	) {
		return;
	}

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement, RawHTML } = window.wp.element;
	const decodeEntities =
		(window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };

	// De-duped list of gateway ids injected by the PHP side (one inline line
	// per registered sub-gateway).
	const ids = (window.peptidePayBlockIds || []).filter(function (v, i, a) {
		return v && a.indexOf(v) === i;
	});

	ids.forEach(function (id) {
		const data = getSetting(id + '_data', null);
		if (!data || !data.id) {
			return;
		}

		const Label = function () {
			return createElement(
				'span',
				{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
				data.icon
					? createElement('img', { src: data.icon, alt: '', style: { maxHeight: '24px' } })
					: null,
				createElement('span', null, decodeEntities(data.title || ''))
			);
		};

		const Content = function () {
			return createElement(RawHTML, null, decodeEntities(data.description || ''));
		};

		registerPaymentMethod({
			name: data.id,
			label: createElement(Label),
			ariaLabel: decodeEntities(data.title || data.id),
			content: createElement(Content),
			edit: createElement(Content),
			// Honour the server-computed availability (currency lock, Smart
			// masking, secrets set). Defaults to true when the flag is absent
			// so older payloads keep rendering.
			canMakePayment: function () { return data.available !== false; },
			supports: { features: (data.supports && data.supports.length) ? data.supports : ['products'] },
		});
	});
})();
