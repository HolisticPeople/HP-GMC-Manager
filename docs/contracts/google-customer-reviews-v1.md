# Google Customer Reviews contract v1

Central contract: `HP-Codex-Skills/skills/hp-roadmap/references/roadmaps/hp-google-customer-reviews-contract-2026-09-04.md`.

HP-GMC consumes `hp_checkout_get_review_confirmation_context_v1()` and exposes
`hp_gmc_get_customer_reviews_optin_v1()` plus the no-argument presentation
renderer `hp_gmc_render_customer_reviews_optin_v1()`. HP-GMC also registers the
same no-argument renderer on WooCommerce's native `woocommerce_thankyou` hook;
the hook's order ID is deliberately ignored. HP-Zen may retain its presentation
mount during transition: GMC's static provider guard emits one fragment only.
Neither path receives buyer payload from Zen or trusts a hook order ID.
Checkout’s `hp_checkout_render_review_confirmation_auth_fields_v1()` owns any
blank email/native nonce fields needed for a late guest consent POST; no hidden
customer email or private Woo verification logic is copied into GMC. Missing or
failed helpers suppress the whole form.

The integration is default off. Unknown hosts and staging are outward-silent in
server and browser gates. A valid production context first renders a local
nonce-bound consent form with no buyer fields. Only its authenticated POST can
render the strict Google payload. Missing delivery truth/provider/configuration
fails softly. Valid distinct GTINs come only from `ProductIdentifiers`; no valid
GTIN omits `products` while retaining store-survey eligibility.

Production activation requires accepted delivery semantics, disclosure,
duplicate-integration inspection, staging acceptance, and an exact separately
authorized release. This contract never regenerates feeds or backfills orders.
