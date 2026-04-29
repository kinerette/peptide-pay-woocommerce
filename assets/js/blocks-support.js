/**
 * Peptide-Pay — WooCommerce Blocks checkout integration.
 *
 * Reads each sub-gateway's registration data from wc.wcSettings
 * (keyed by gateway ID), and registers a payment method with the
 * WC Blocks registry. Single file, handles all 19 gateways.
 */
(function () {
	if (!window.wp || !window.wc) return;

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement, RawHTML } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	// Every sub-gateway ID follows `peptide_pay_<provider>` and each ships
	// its data under `<id>_data` in wc.wcSettings.
	const allSettings = (window.wc.wcSettings && window.wc.wcSettings.ALL_SETTINGS_DATA) || {};
	Object.keys(allSettings).forEach((key) => {
		if (!key.startsWith('peptide_pay_') || !key.endsWith('_data')) return;

		const data = getSetting(key);
		if (!data || !data.id) return;

		const Label = () => createElement(
			'span',
			{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
			data.icon ? createElement('img', { src: data.icon, alt: '', style: { maxHeight: '24px' } }) : null,
			createElement('span', null, decodeEntities(data.title || ''))
		);

		const Content = () => createElement(
			RawHTML,
			null,
			decodeEntities(data.description || '')
		);

		registerPaymentMethod({
			name: data.id,
			label: createElement(Label),
			ariaLabel: decodeEntities(data.title || data.id),
			content: createElement(Content),
			edit: createElement(Content),
			canMakePayment: () => true,
			supports: { features: data.supports || ['products'] },
		});
	});
})();
