=== Peptide-Pay for WooCommerce ===
Contributors: peptidepay
Tags: payment gateway, peptides, high-risk, crypto, usdc, apple pay, google pay, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.6.5
License: MIT
License URI: https://opensource.org/licenses/MIT

14 payment rails in one plugin — cards, Apple Pay, Google Pay, Revolut, crypto (USDC), Binance Pay + 9 more. Built for WooCommerce stores Stripe and PayPal refuse: peptides, nutra, high-risk verticals. Instant USDC payouts.

== Description ==

Peptide-Pay ships **14 independent payment gateways** in a single plugin — Smart multi-provider checkout, Credit Card (Moonpay), Revolut Pay, Crypto.com Pay, Binance Pay, Banxa, Transak, Ramp Network, Sardine, Bitnovo, Simplex, AlchemyPay, Interac (CA), UPI (IN). Enable only the rails you want, disable the rest.

Built for merchants Stripe and PayPal refuse to serve: peptides, research chemicals, nootropics, CBD, nutraceuticals, and other high-risk verticals. Customers pay with a normal card / wallet form, you receive USDC on Polygon within seconds, directly to your own wallet.

**Two setup modes (per sub-gateway)**

* **Quick** — paste your Polygon USDC wallet address. No signup, no account, no API key. Ideal if you just want to start accepting cards today.
* **Advanced** — API key + HMAC-signed webhook secret. Gives you multi-tenant tracking, retry queue, and dashboard analytics at https://peptide-pay.com/app.

**Tested with**

* WooCommerce 7.0 up to 10.6
* PHP 7.4, 8.0, 8.1, 8.2, 8.3
* Woodmart, Astra, Flatsome, Storefront themes
* Elementor and block-based checkout
* WooCommerce HPOS (High-Performance Order Storage)

== Installation ==

1. Upload `peptide-pay-woocommerce.zip` through `Plugins → Add New → Upload Plugin`, or extract the folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to `WooCommerce → Settings → Payments`, click **Manage** next to Peptide-Pay, and enable the gateway.
4. **Quick mode:** paste your Polygon USDC wallet address. Save.
5. **Advanced mode:** select "Advanced" in Setup type, paste your API key (`sk_live_...`) and webhook secret (`whsec_...`) from https://peptide-pay.com/app/api-keys. Save.
6. Place a test order to confirm the redirect to the Peptide-Pay card form works.

== Frequently Asked Questions ==

= Is there a monthly fee? =
No. We only take a percentage on successful payments.

= What are the fees? =
**Merchant fee: flat 3% per successful transaction** (2% Peptide-Pay + 1% PayGate settlement layer). No subscription, no setup, no rolling reserve. On card payments, the card on-ramp adds ~4.5% pass-through, charged to the customer at checkout — so the customer pays ~7.5% all-in and you net ~92.5%. On crypto-direct (USDC in, USDC out), the on-ramp is skipped: you pay only the 3% and net ~97%.

= Will my store get banned like with Stripe? =
No. Peptide-Pay is built for high-risk verticals. We do not ban merchants for selling peptides, research chemicals, or similar restricted products, as long as your storefront complies with our terms (no prescription-only drugs, no controlled substances).

= Do I need to sign up for an account? =
No for Quick mode (just paste your wallet). Yes for Advanced mode (dashboard + webhook secret).

= Does it work with Woodmart / Elementor / block-based checkout? =
Yes. We redirect to a hosted card form, so we do not inject any checkout markup that could conflict with theme styles.

= How fast do I get paid? =
USDC lands in your Polygon wallet within ~30 seconds of each successful capture. No weekly rolling reserves, no 7-day hold.

= Do my customers need crypto? =
No. Customers pay with a normal card form. The crypto conversion happens on our side — they never see it.

= Is it HPOS compatible? =
Yes. The plugin declares HPOS and block-based checkout compatibility via `FeaturesUtil::declare_compatibility` and uses order-object methods only (no direct post-meta reads).

= What about refunds? =
On-chain USDC payments are final. For customer support, issue refunds manually to the buyer's wallet on request, and mark the order refunded in WooCommerce.

== Changelog ==

= 2.6.3 — UPDATE-FLOW FIX =
Companion fix to 2.6.2 for the auto-update path:
* The update-checker passed `id = peptide-pay-woocommerce/peptide-pay-woocommerce`
  to WP — but the actual main file is `peptide-pay.php`, not
  `peptide-pay-woocommerce.php`. After a one-click update, WP looked
  up the wrong path during the post-update activation and surfaced
  "Plugin file does not exist". Now `id` matches the real basename.
* Added a self-heal: after any plugin update, if `active_plugins`
  contains a stale Peptide-Pay path (older folder name, manual
  rename), it's reconciled to the canonical
  `peptide-pay-woocommerce/peptide-pay.php` automatically. Stops the
  "next admin pageload silently deactivates the plugin" hazard.

= 2.6.2 — INSTALL FIX =
**Fix the "Plugin file does not exist" activation error reported by
multiple Linux-hosted merchants in 2.6.0/2.6.1.** Root cause: the
release zip was built on Windows with PowerShell `Compress-Archive`,
which omits Unix file mode metadata. On extraction by WordPress on a
Linux host, files landed with mode 0000 (unreadable by the PHP user)
and WP reported them as "missing". 2.6.2 ships a Python-built zip
with proper 0644 file / 0755 directory permissions, explicit folder
entries, and forward-slash paths — the same shape WP.org's plugin
build pipeline emits.

If you got "Plugin file does not exist" on activation, just delete
the broken folder under wp-content/plugins/peptide-pay-woocommerce/
via SFTP / cPanel File Manager, then re-upload this 2.6.2 zip.

= 2.6.1 =
* Legal-safe wording: trust-signal line no longer calls MoonPay /
  Revolut / Banxa "licensed payment partners" — they're not under
  any commercial partnership with us. Replaced everywhere with
  "card processing via licensed on-ramps (MoonPay, Revolut, Banxa)".

= 2.6.0 =
**Conversion-focused release** — every change is aimed at making the
checkout block more trustworthy and the CTA clearer to the customer.
- **"Pay $XX →" button label** instead of the generic WooCommerce "Place
  order" when Peptide-Pay is the chosen gateway. Customer sees the
  exact amount on the CTA. Stripe / Lemon Squeezy / Shop Pay pattern.
- **Loading state** on click — the button instantly disables, swaps
  to a spinner + "Redirecting to secure checkout…", and a 30 s
  fallback re-enables it if the redirect never fires (no more dead
  spinners eating conversion).
- **Trust-signal row** — small lock icon + honest line: "Encrypted
  checkout — card processing via licensed on-ramps (MoonPay,
  Revolut, Banxa)." Toggle in settings (default ON).
- **Bundled Peptide-Pay wordmark** as the default gateway icon. Fresh
  installs never land with a missing-image broken-icon. If a custom
  Icon URL 404s, we fall back to the wordmark instead of the broken
  placeholder.
- **Glass preset** now uses a translucent gradient background so the
  frosted look stays visible on plain-white themes too (audit caught
  it being indistinguishable from Modern on Twenty Twenty-Five).
- Wider (160 px) max-width on the gateway icon so wordmark logos
  render at full readability without clipping.

= 2.5.3 =
* Fix: silent redirect-to-empty-cart on checkout if the API ever returns
  a malformed checkout URL. Now surfaces a real "Invalid checkout URL"
  notice and keeps the customer on the checkout instead of dumping them
  on /cart/ with a Pending order behind their back.
* Fix: hard cap on the gateway icon shown in the WC payment list
  (max-height 32 px, max-width 120 px, object-fit contain). A merchant
  who pasted a 600×600 image into the Icon URL field would otherwise
  blow the entire payment-method box apart — fixed.

= 2.5.2 =
* Logos at checkout are now rendered bare — no chip container, no
  border, no background. Brand marks sit directly on the gateway-box
  bg. Fixes the "circles inside circles" look.
* Added `!important` to every generated CSS property so WC themes
  shipping their own `.payment_box{background:…}` rule (most do)
  no longer override the merchant-picked preset. The pale-purple
  `#ebe9eb` default WC theme background is replaced cleanly by the
  Modern preset's white card now.
* Hid the small arrow tail WP draws above the `.payment_box` —
  inherited the theme bg and looked broken on any non-default colour.

= 2.5.1 =
* Drop "(2026 vibe)" from the Glass preset label — sober copy.

= 2.5.0 =
* **Checkout style — point and click**, no CSS knowledge required.
  New "🎨 Checkout style" section in the gateway settings:
  - Style preset: Modern (default), Glass (frosted-glass),
    Minimal (no border, no background), Classic (boring WC default).
  - Border colour + Background colour pickers (WP core picker).
  - Corner radius slider (0–32 px).
  - Logo height slider (16–48 px).
  - Hover-lift animation toggle.
* Out of the box, every fresh install gets the **Modern** preset:
  white background, soft 1 px border, 16 px rounded corners, gentle
  shadow, hover lift. No more grey 2018-style WP `.payment_box`.
* Logos at checkout now render as chip-style badges (4 px padding,
  light translucent background, 6 px corners) for a uniform look
  across Visa, Apple Pay, Revolut, etc.
* Raw "Custom CSS" textarea moved below the new controls and labelled
  as a "⚡ Power user override" — the controls already cover 95% of
  the styling cases.

= 2.4.2 =
* Fix: Mastercard logo at checkout. Previous release shipped the wrong
  asset (`mastercard-color.svg` was a 44 KB SEPA-style placeholder by
  mistake). Replaced with the correct `mastercard.svg` — the proper
  red+yellow interlocking circles.
* Default Title changed from "Card or crypto — secure checkout" to
  the simpler **"Peptide-Pay"**. Merchants who already customised the
  Title field keep their value; only stores that never edited it pick
  up the new default.
* Help text rewritten in plain language for "One method only OR all
  methods at checkout" (was confusingly named "Show individual
  provider rows") and "Debug log" — now explains WHEN to enable each.

= 2.4.1 =
* Total checkout customisation freedom for merchants:
  - **Icon URL** field — paste any image URL to replace the gateway
    icon shown in the checkout list (no PHP required).
  - **Custom CSS** field — CSS injected only on the checkout page,
    target `.payment_box.payment_method_peptide_pay_gateway` /
    `.peptide-pay-card-strip` to restyle anything.
  - Title and Description help text now spells out that any text
    or HTML works — paste your own &lt;img&gt; tags, links, badges.
* Dev hooks: `peptide_pay_card_logos` (filter the array of badge
  files), `peptide_pay_card_logos_html` (filter the rendered row),
  `peptide_pay_payment_fields_after` (action hook to append HTML).
* Auto-update checker: cache TTL reduced from 12h to 1h, and admins
  can now force a check on demand by adding
  `?peptide_pay_force_check=1` to any admin URL.

= 2.4.0 =
* Payment-method logos at checkout now use the same Visa, Mastercard,
  Amex, Apple Pay, Google Pay and Revolut SVGs that ship on
  peptide-pay.com — clean brand artwork, served from the plugin's
  own assets/logos/ directory. The earlier hand-drawn placeholders
  are gone.

= 2.3.0 =
* Auto-update support. WP admin now surfaces the standard "new version
  available" notice on Plugins screen when a new release ships, with
  one-click update — no more manually re-downloading the zip. The
  checker polls peptide-pay.com every 12 hours.

= 2.2.0 =
* **Breaking change**: Quick mode (wallet-only, no signup) removed. Its
  webhook authentication relied on a session_id that was visible in the
  customer's URL bar — a malicious customer could spoof "order paid"
  without paying. The plugin now requires API Key + Webhook Secret on
  every gateway. Existing merchants on Quick mode: the gateway will be
  hidden from checkout until they paste both secrets — get them at
  https://peptide-pay.com/app/api-keys.
* New: each secret input now shows a clickable deep-link to the exact
  Peptide-Pay dashboard page where the value is found.
* New: Webhook URL is shown read-only in settings so merchants can copy
  it into their Peptide-Pay webhook config in one click.
* Internal: removed unsigned `?wc-api=peptide_pay` endpoint, the
  `is_demo_wallet()` helper and the `_peptide_pay_setup_type` order meta.

= 2.1.1 =
* Fix: Advanced-mode API key validation now accepts dashes (`-`) in the key
  body. The previous regex (`[A-Za-z0-9]{16,}`) silently rejected valid
  `sk_live_*` / `sk_test_*` keys generated by peptide-pay.com that contain
  `-` or `_` characters, bouncing settings back to Quick mode. HMAC webhook
  setup is now usable.
* UX: Smart gateway default checkout label changed from "Credit / Debit
  Card & more" to "Card or crypto — secure checkout" — clearer for the
  customer, still neutral.
* UX: Setup-type help text now spells out the practical difference —
  Quick = redirect-only, manual order confirmation; Advanced = automatic
  order confirmation via webhooks.

= 2.1.0 =
* Provider list now syncs with the live PayGate `/api/v1/providers`
  endpoint via a 24h-cached transient and a daily WP cron — retired
  providers no longer show up as "ghost" rows in WC → Settings →
  Payments. Merchants who saw stripe / utorg / topper / transfi /
  paypal / robinhood rows that silently failed in 2.0.x will see them
  disappear after activation.
* Added Crypto.com Pay and AlchemyPay as first-class sub-gateways
  (already supported server-side, just unsurfaced locally).
* Removed 6 sub-gateway classes whose upstream provider had been
  retired by PayGate (stripe, utorg, topper, transfi, paypal,
  robinhood). Per-gateway settings are wiped on plugin uninstall via
  the existing legacy-cleanup branch in uninstall.php.
* "Demo wallet" guard: pasting the homepage `/` Try-demo wallet is
  now rejected with an admin-side error, before WC saves the option.
  Mirrors the new server-side denylist on /api/v1/checkout/init.
* Pricing copy aligned with the rest of the site: flat 3% merchant
  fee on every transaction; ~4.5% card on-ramp pass-through is paid
  by the customer (not the merchant). Replaces the older "~8% all-in"
  / "1.5% for crypto" wording.
* Doc URLs now point at /app/api-keys instead of the historical
  /dashboard slug (which 404'd on the live site).

= 2.0.3 =
* Webhook signature verification updated to match server-side format: header
  renamed to `X-PeptidePay-Signature` (previously `PeptidePay-Signature`),
  value is Stripe-style `t=<unix_ts>,v1=<hex_hmac_sha256>`, HMAC input is
  `<ts>.<raw_body>`. Old header name still accepted transitionally.
* Event type field corrected: `event: "order.paid"` (was `event_type`).
  Unknown events now return 202 so the server stops retrying without
  flagging a delivery failure. Legacy `event_type` key still accepted.

= 2.0.2 =
* UX: plugin now sends the order's product names + quantities to the
  Peptide-Pay checkout so customers see "BPC-157 × 3, Retatrutide × 1"
  above the amount (conversion uplift — no more confusion about what
  they're paying for). Truncated to 80 chars to fit mobile.

= 2.0.1 =
* UX: removed default "Peptide-Pay" icon from every checkout row — was shown
  identically on all 18 gateways, looked amateur. Merchants can set their
  own via the `peptide_pay_icon_<provider>` filter.
* UX: Smart (Recommended) gateway now masks individual sub-gateways at
  the customer-facing checkout when active. Merchants who "enable all"
  no longer display 10+ redundant card options to buyers.
* DX: is_available() bypasses the SSL check on WP_DEBUG installs so
  wp-sandbox / Playground / local staging testing works over HTTP.

= 2.0.0 =
* Major: refactored to 19 independent sub-gateways (matches PayGate's native architecture).
  New gateways: Smart (hosted multi-provider), Moonpay, Revolut, Stripe (Apple/Google Pay),
  Banxa, Transak, Ramp Network, Utorg, Sardine, Topper, Bitnovo,
  Simplex, Transfi, Binance Pay, PayPal, Robinhood, Interac (CA), UPI (IN).
* Each sub-gateway has independent enable/disable, title, description, wallet, and icon.
* Country-locked rails auto-hide when store currency doesn't match (UPI=INR, Interac=CAD, etc.).
* WC Blocks checkout: single JS bundle registers all sub-gateways.

= 1.0.0 =
* Initial release: single gateway, Quick + Advanced setup modes.
* HPOS + block-based checkout compatible.

== Screenshots ==

1. Payment method card shown on the WooCommerce checkout.
2. Plugin settings screen — Quick mode (just paste a wallet).
3. Plugin settings screen — Advanced mode (API key + webhook secret).
4. Hosted Peptide-Pay checkout (card form, Apple Pay, Google Pay).
5. Completed order with Peptide-Pay TX hash in order notes.
