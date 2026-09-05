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
routes plus the clean `/hp-checkout/` entry. Cart, account, order-pay,
order-received, every Woo endpoint and private/query-bearing order routes
cannot enqueue it. Actual widget visibility and clearance from checkout
controls must be verified on production after separately authorized activation;
staging remains outward silent even if production options are copied to it.

An authorized read-only observer may import one fresh bounded observation after
the Merchant Center UI is checked. Preserve the last good snapshot on an
observer/import failure, record only its short error and timestamp, and notify
only when freshness becomes stale, an import fails, or a metric/status changes.
No observer may enable settings, call customer/order routes, submit surveys, or
claim Google receipt from a script load.

## Extended public-business observations (3.4.15)

`hp_gmc_import_submission_observation_v1(array $observation)` is a server-only
operator import. It has no REST endpoint, form, automatic feed request or save
button. `GoogleSubmissionObservation::definitions()` is the closed column and
source contract for `shipping`, `countries`, `returns`, `payments`, `feeds`,
`yotpo`, `seo`, `loyalty`, `delivery_proposals`, and `analytics`.

Envelope: `version: 1`, one `section`, its exact allowlisted `source`, observed
`environment` (`production` or `staging`), `state`, UTC `observed_at`, and `rows`.
Rows are keyed by stable lowercase public identifiers, not customer/order IDs.
Only the declared columns are accepted. Missing columns normalize to null.
Observation state is one of `proposed`, `staged`, `submitted`, `accepted`,
`visible`, `google_awarded`, `not_observed`; use only the state the source proves.
Shipping policy marked Complete is submitted configuration, not delivery proof.
Keep observation time distinct from last feed sent or Google processing time.

Example (illustrative schema only, never import as an observation):

```json
{"version":1,"section":"payments","source":"https://merchants.google.com/mc/settings/paymentsignal/edit?a=5298746911","environment":"production","state":"submitted","observed_at":"2026-09-05T12:00:00Z","rows":{"apple_pay":{"provider":"Apple Pay","status":"pending_review","submitted_at":null,"accepted_at":null}}}
```

Imports reject unknown fields, malformed/future timestamps, foreign source
URLs, negative counters, inverted ranges, unbounded content and obvious private
field values. They never replace an observation with an older one. At most
250 public rows and 30 prior observations per section are retained, non-autoloaded.
Do not import raw browser/API dumps, customer details, order/tracking IDs, keys,
credentials, or private URLs. A named source is not evidence without an actual
read by the observer. Do not manufacture feed timestamps from initial UI messages.

`hp_gmc_record_google_observation_failure_v1($code, $section = 'quality')`
records a failure for that source while preserving its last good data. Existing
quality, reviews, widget and submitted-settings import functions remain valid.
Successful imports clear only that source's failure. Quality/reviews/widget,
payments/feeds/Yotpo become stale after 36 hours; weekly country/shipping/returns,
SEO/loyalty/analytics/proposal observations after eight days.

## Observation cycle

1. Read the authenticated source; confirm merchant, country, window and environment.
2. Save only bounded public-business values locally with actual observation time.
3. Validate/import only to the specifically authorized destination. Importing a
   production observation into staging labels its observed environment production;
   it does not change the production store or Merchant Center.
4. Re-observe later. Identical values with a new observation time refresh the
   check; they do not count as progress. Report substantive changed rows, failure
   recovery and freshness separately. Test failures using isolated fixtures,
   never invent a production failure or customer review to exercise a monitor.
5. After activation: daily US checks and weekly international coverage. Notify
   only meaningful changes, failed observations, stale sources or required action.
   An unavailable login is `authentication_required`, never a successful check.

The local HP ShipStation Rates configuration affects review invitation timing.
It does not upload Merchant Center shipping services. Proposals need explicit
merchant acceptance before they become shipping settings or promises.
