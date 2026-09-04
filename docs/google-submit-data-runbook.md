# Google Submit Data runbook

`GMC Manager > Google Submit Data` is intentionally read-only. Rendering it
does not contact Merchant Center, regenerate a feed, save a setting, or expose
customer/order data. Local generation and configuration are never presented as
Google receipt.

Trusted operators may import a bounded Store Quality observation through
`hp_gmc_import_store_quality_snapshot_v1()` in WP-CLI/eval. The only accepted
scope is the merchant-specific Store Quality view for merchant `5298746911`,
US, trailing 30 days, all stores. Keep up to 30 prior snapshots automatically.
Do not use this importer for an arbitrary Merchant Center response.

The fixed metric keys are `overall_quality`, `delivery`, `shipping_cost`,
`return_window`, `return_cost`, `promotions_rejection`, `ewallet`,
`high_resolution_images`, `images_per_offer`, and `store_rating`. Each has a
rating of `exceptional`, `great`, `good`, `fair`, or `incomplete`. `no data`
must be represented as an incomplete/null metric, never zero. Store only the
fixed metric fields, source scope, UTC observation timestamp, and at most ten
short safe errors.

Widget browser evidence uses the separate
`hp_gmc_import_store_widget_observation_v1()` importer. Allowed statuses are
`script_loaded`, `script_load_failed`, `start_attempted`, `start_failed`,
`widget_visible`, and `not_observed`. A script load and `merchantwidget.start`
attempt do not prove widget visibility or Google receipt; imports always record
`google_receipt: not_observed`.

The optional store widget requires its separate `hp_gmc_store_widget_enabled`
setting. It remains distinct from `hp_gmc_customer_reviews_enabled`, which
controls the order-confirmation survey opt-in. The widget is restricted to the
actual production HTTPS host and public home/shop/product/category/reviews
routes; checkout, cart, account, order and `key` routes cannot enqueue it.
