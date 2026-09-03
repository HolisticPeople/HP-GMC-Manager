# HP GMC Manager

Google Merchant Center management plugin for WordPress/WooCommerce with admin dashboard and MCP abilities.

## Features

- **Admin Dashboard**: Visual overview of GMC product statuses, issues, and exclusions
- **MCP Abilities**: AI-powered operations via Cursor integration
- **Shipping Management**: Account-level shipping settings control
- **GLA Complement Mode**: Works alongside Google Listings & Ads plugin
- **Dry Run Mode**: Safe testing on staging without affecting production GMC

## Requirements

- PHP 8.5+
- WordPress 6.0+
- WooCommerce 8.0+
- HP-Abilities plugin (for MCP integration)

## Installation

1. Clone or download to `wp-content/plugins/hp-gmc-manager`
2. Run `composer install` (optional, for Google API client)
3. Activate the plugin in WordPress
4. Configure in **GMC Manager > Settings**

## Configuration

### Google Cloud Setup

1. Create a Google Cloud project
2. Enable the Merchant API
3. Create a service account
4. Download the JSON key file
5. Add service account email to Merchant Center as Admin

### Plugin Settings

- **Merchant ID**: Your GMC account ID (e.g., 5298746911)
- **Service Account Path**: Absolute path to JSON key file
- **Mode**: Auto, Live, Dry Run, Mock, or Passthrough
- **Sync Frequency**: How often to sync product statuses

## MCP Tools

### Product Tools
| Tool | Description |
|------|-------------|
| `gmc-dashboard-summary` | Get overview stats |
| `gmc-list-issues` | List products with issues |
| `gmc-get-product-status` | Get status for specific SKU |
| `gmc-set-exclusion` | Exclude product from destinations |

### Account Tools
| Tool | Description |
|------|-------------|
| `gmc-get-shipping-settings` | Get shipping configuration |
| `gmc-list-shipping-services` | List all shipping services |
| `gmc-enable-country` | Add country to shipping |
| `gmc-disable-country` | Remove country from shipping |
| `gmc-get-account-status` | Overall account health |

### Audiences (Marketing)

Saved segments for Google Ads Customer Match. Tools are in category `hp-marketing` for use with the Marketing MCP server.

| Tool | Description |
|------|-------------|
| `gmc-audiences-segments-list` | List saved segments |
| `gmc-audiences-segment-get` | Get one segment by id |
| `gmc-audiences-segment-run` | Run segment, return count |
| `gmc-audiences-segment-save` | Create or update segment |
| `gmc-audiences-segment-duplicate` | Duplicate segment |
| `gmc-audiences-segment-export-csv` | Export segment as Google Customer Match CSV |

**Marketing MCP server (hp_marketing_stg / hp_marketing_prod)**  
Use the same bridge URL as GMC staging/production with query param `?scope=marketing` to receive only tools in category `hp-marketing` (Audiences + GA4 + Google Ads). This keeps Cursor under the tool limit when working on audience and campaign workflows. Document in `.cursor/rules` or mcp.json: use `hp_marketing_stg` for staging audience/campaign work.

## Operating Modes

| Mode | Description |
|------|-------------|
| **Auto** | Live on production, Dry Run on staging/local |
| **Live** | Real API calls to GMC |
| **Dry Run** | Logs actions, returns simulated success |
| **Mock** | Returns fake data for UI testing |
| **Passthrough** | Staging connects to real GMC (caution!) |

## Dashboard Tabs

1. **Overview**: Product status summary cards
2. **Issues**: List of disapproved/warning products
3. **Feeds**: Supplemental feeds
4. **Exclusions**: Manage destination exclusions
5. **Funnels**: Funnel feed and GMC settings
6. **Shipping**: Account-level shipping settings
7. **Audiences**: Saved segments, segment builder, CSV export for Google Ads Customer Match
8. **MCP Tools**: Enable/disable individual tools
9. **Dry Run Log**: View simulated actions (staging only)

## GLA Integration

The Google Listings & Ads (GLA) plugin is **inactive** in production (since
before 2026-07); HP GMC Manager is the sole primary product source (pull feed
`/wp-json/hp-gmc/v1/product-feed`, GMC datafeed 407019991):

- Keeps GLA's offer-ID convention (`gla_{product_id}`, `_wc_gla_mc_offer_id`)
  so GMC item history is preserved
- GLA postmeta is load-bearing: feed inclusion still honors
  `_wc_gla_visibility = dont-sync-and-show` — do not purge GLA meta without
  migrating those flags to `_hp_gmc_excluded`

## Changelog

### 3.4.13

- Add dormant, default-off Google Customer Reviews store/product survey support.
- Consume the authenticated Checkout confirmation contract; require first-party
  sharing consent before emitting buyer data or loading Google. Unknown/staging
  hosts stay silent regardless of copied database settings.
- Reuse validated distinct purchased GTINs; no feed changes or historical backfill.
- Production activation remains gated on trusted delivery promises, disclosure,
  duplicate-integration review and exact release acceptance.

### 3.4.12

- Product autocomplete, including the audience SKU condition picker, now uses
  HP Core's literal-first Hebrew-keyboard recovery when the direct search is empty.

### 3.4.11 — Production Confirmation Helper

- Added a read-only `production-confirmation` operator command that emits the
  exact confirmation value for an operation and full manifest SHA-256.
- Tightened the authorization schema to require exactly 12 lowercase
  hexadecimal manifest-prefix characters. For manifest
  `91705c7459e9747f0e6009038bf5b310b1ada03ba030330292892e3b9d0f8a79`,
  the preflight confirmation is exactly
  `HP-GMC-PRODUCTION-PREFLIGHT-91705c7459e9`.
- Use the helper rather than manually truncating the checksum:
  `wp eval-file wp-content/plugins/hp-gmc-manager/includes/Operations/IdentifierMigrationOperator.php production-confirmation preflight 91705c7459e9747f0e6009038bf5b310b1ada03ba030330292892e3b9d0f8a79`.

### 3.4.10 — Guarded Production Identifier Candidate

- Added separately named `production-*` identifier operations without
  weakening the existing exact-host staging gate. Every production operation
  requires immutable manifest and authorization checksums plus exact production
  host/environment, merchant-account fingerprint, operation, feed-before hash
  and population, manifest/accepted counts, approval reference, bounded expiry,
  authorization UUID, and explicit confirmation bindings.
- Manifest and authorization bytes are each read once, then both checksummed
  and decoded from that same capture; mutating authorization UUIDs are consumed
  atomically and cannot be replayed.
- Production regeneration requires an immediate protected/canonical preflight
  for its explicitly authorized `applied` or `rolled_back` phase before any
  cache clear or feed generation.
- Kept production execution fail closed on any checksum, host, account, feed,
  row-count, authorization, canonical-before, or protected-field drift. Apply
  still automatically restores already-touched canonical fields on failure.
- Isolated dashboard/API product-count metadata to the merchant profile so
  agent and OpenAI feed generation can no longer overwrite merchant status.

### 3.4.9
- Publish the canonical 30-day MerchantReturnPolicy in Yoast's Organization graph, including customer-paid remorse returns, free defect returns, downloadable labels, full refunds, and no restocking fee.
- Reconcile the public Return & Refund Policy and Terms pages with that policy, retaining one-time content backups for rollback.
- Defer the one-time page reconciliation until WordPress `init`, after revision and post APIs are fully available to web and WP-CLI requests.

### 3.4.7 — Identifier Migration Operator Candidate

- Added the deploy-visible versioned
  `includes/Operations/IdentifierMigrationOperator.php` tool for
  immutable candidate exports and checksum-gated staging preflight, apply,
  rollback, and feed regeneration. Production is export-only; write operations
  require both WordPress environment type `staging` and the exact approved
  staging host, and `_sku`, `_global_unique_id`, and `sku_mfr`
  are fingerprinted, rechecked immediately before every row write, and verified
  unchanged. Failed preflight exits nonzero.
- Added `scripts/compare-identifier-feeds.py` for row-keyed production,
  staging-before, and staging-after comparisons that separate population drift,
  identifier changes, and every non-identifier cell change. A staging-after
  comparison exits nonzero if IDs, headers, GTIN, or any non-identifier cell
  changes.

### 3.4.6

- Removed the unsafe SKU-to-MPN projection from the canonical pull feed. `mpn`
  now emits only from a product-level reviewed manufacturer source:
  `_hp_gmc_mpn`, `_hp_gmc_mpn_verified=yes`, and an approved
  `_hp_gmc_mpn_source` (`manufacturer`, `manufacturer_catalog`,
  `authorized_distributor`, or `product_label`). Variations never inherit a
  parent MPN.
- Removed the unused flat `mapProductToGmc()` / `pushProduct()` path instead of
  presenting its obsolete `accounts/{id}/products` POST as Merchant API v1.
  HP GMC Manager's canonical product publication remains the pull feed at
  `/wp-json/hp-gmc/v1/product-feed`.
- `identifier_exists=no` now requires an explicit
  `_hp_gmc_identifier_exists=no` product review and is suppressed whenever a
  GTIN, reviewed MPN, or source-backed brand is present. Missing local
  identifiers remain unresolved instead of being mislabeled as nonexistent.
- Brand no longer falls back to `HolisticPeople` when no product manufacturer
  source exists. Variations may inherit a source-backed parent brand, while
  their GTIN and MPN remain variation-specific.
- SKU remains the store's internal SKU/offer-join evidence and is never
  relabeled as a manufacturer identifier. GTIN projection retains the native
  Woo/legacy source order and now rejects unsupported separators, restricted
  GS1 prefixes, and invalid check digits. Offer IDs, feed column order, and
  non-identifier behavior remain unchanged.

### 3.4.5

- Uses HP-Funnels' offer-level GMC contract when available, preserving the
  legacy starter item while exporting additional ExpressShop packages with
  stable IDs, bundle status, and component imagery for accurate promotion
  targeting. Older HP-Funnels versions retain the one-row fallback.

### 3.4.4

- Fixed `additional_image_link` coverage for legacy products whose valid extra
  images are attached to the product but were never added to the WooCommerce
  gallery field. The feed keeps curated galleries authoritative; only an empty
  gallery activates the fallback, which excludes the featured image, rejects
  unsupported formats and images below 500x500, de-duplicates attachment IDs,
  de-duplicates URLs, and retains Google's 10-image cap.

### 3.4.3

- Added `?profile=openai`, a separate fail-closed OpenAI commerce feed.
  Products are omitted unless `_hp_openai_feed_eligible=yes` has been set
  after a per-item policy and landing-page review. The profile also inherits
  the agent title/description claim guards; the canonical merchant and agent
  profiles are unchanged.

### 3.4.2

- Agent-profile products with high-risk claim language in the title now fail
  closed unless a claim-safe `_hp_agent_feed_title` has been explicitly
  reviewed and stored. Canonical merchant-feed titles remain unchanged.

### 3.4.1

- Added `?profile=agent` to the public Google-compatible product feed. This
  agent-discovery profile prefers explicitly reviewed
  `_hp_agent_feed_description` copy and replaces bounded high-risk medical
  claim language with neutral product copy.
- Required descriptions now fall back to the product name instead of emitting
  a blank field.
- `identifier_exists=no` now emits only when both GTIN and SKU-backed MPN are
  absent, removing contradictory identifier declarations.

### 3.4.0

- **UCP checkout-compliance feed attributes.** The primary feed now appends the
  transaction-layer columns required for Copilot Checkout / Google UCP checkout:
  `native_commerce(checkout_eligibility)`,
  `consumer_notice(notice_type:notice_message)`, and `merchant_item_id`.
- **Checkout eligibility is born OFF.** The global
  `hp_gmc_ucp_checkout_eligibility` toggle defaults to `disabled`, so the new
  eligibility column stays blank until store-level native checkout settings are
  configured. When enabled, products can be failed closed per item with
  `_hp_gmc_checkout_ineligible=yes`.
- **Per-product notices are opt-in meta only.** `_hp_gmc_notice_type` and
  `_hp_gmc_notice_message` emit one sanitized consumer notice only when both are
  present and the notice type is whitelisted.
- **Merchant checkout mapping is explicit.** `merchant_item_id` always emits the
  numeric WooCommerce product ID used by the checkout API as `product_id`.

### 3.3.2

- **Corrected the `google_product_category` default comment** in
  `ProductDataFeed::getGoogleProductCategory()`. The comment claimed `469 =
  "Health & Beauty > Health Care > Vitamins & Supplements"`, which is wrong:
  **469 is the top-level "Health & Beauty" node**, and the actual supplement leaf
  is **525 ("… > Fitness & Nutrition > Vitamins & Supplements")**. Comment-only —
  the default return value stays `469` (a deliberately broad catch-all for
  products with no per-product mapping). The full catalog was mapped to
  per-product categories on 2026-07-08 (written to the ACF field the feed reads,
  which out-ranks this default). Regression guard added so the wrong comment and
  a drifted default can't return.

### 3.3.1

- **Removed `product_highlights`** (added in 3.3.0). It extracted `<li>` bullets
  from product descriptions directly into the feed, which surfaced un-reviewed
  disease/claim language (e.g. "can cure...", "flu virus", "infection") on
  products outside the claims-remediation scope — a GMC healthcare-misleading-
  claims risk. Caught on staging before any production promotion. The other
  3.3.0 additions (additional_image_link, sale_price + price/sale_price fix,
  sale_price_effective_date, shipping dimensions) are retained. Regression
  guard added: the feed header must not contain product_highlights and the
  builder must not exist, so it can't return without a compliance guard + a
  full-catalog claims audit.

### 3.3.0

- **UCP / agentic-commerce feed enhancement.** The primary product feed now
  emits a richer, agent-relevant attribute set, each column populated ONLY when
  the product actually carries the data (blank cell = "attribute not provided",
  matching the `identifier_exists` doctrine — never fabricated):
  - `additional_image_link` — up to 10 gallery image URLs (comma-separated),
    excluding the primary image. Coverage: ~355 products.
  - `sale_price` + corrected `price` — when a product is on sale, `price` now
    holds the **regular** price and `sale_price` holds the sale amount (GMC
    requires this pairing). Previously an on-sale product emitted the sale
    amount in `price` with no `sale_price` at all. Coverage: ~22 products.
  - `sale_price_effective_date` — ISO-8601 `start/end` range, emitted only when
    BOTH sale dates are set (0 products today; code is ready).
  - `product_highlights` — short bullet features, derived only from genuine
    `<li>` bullet lists in the short/long description (paragraphs are not
    reshaped into fake bullets). Coverage: ~4 products.
  - `shipping_length` / `shipping_width` / `shipping_height` — physical product
    dimensions with a GMC-valid unit (in/cm). Coverage: ~536 products.
- **Not added (no source data):** `item_group_id` and variant attributes
  (`size`, `material`, `pattern`) — the catalog contains **zero variable
  products** (all 559 are simple), so these would be 100%-empty columns. Add
  them in lockstep if variable products are ever introduced.
- Availability remains WC `is_in_stock()` (backorder business model, unchanged).
- Regression assertions for every new column (presence + omit-when-empty
  behavior + the price/sale_price pairing) added to CoreStructureTest.

### 3.2.1

- **Fix: supplemental feed endpoints served JSON, not TSV.** `SupplementalFeedEndpoint::serveFeed()` returned the feed text through `WP_REST_Response`, which JSON-encodes it into one quoted string — GMC's daily fetch of both linked supplemental datasources (hp-exclusions, hp-supplemental-overrides) has never parsed a single row, silently disabling every override and exclusion since the feeds were linked (verified via Content API `products.get`: no effective overrides, empty `excludedDestinations`). Now serves raw text via header()+echo+exit, exactly like the primary feed endpoint. Regression assertions added to CoreStructureTest.

### 3.2.0
- **Primary feed `identifier_exists` column**: rows WITHOUT a GTIN now emit
  `identifier_exists=no` (interim doctrine, user decision 2026-07-04, until
  each product's barcode is resolved); rows with a GTIN leave it blank
  (defaults to yes). Rationale: ~200 catalog products are genuinely
  barcode-less house/practitioner brands (Tachyonized, Dr Gabriel Cousens
  Scalar line, Natures Frequencies, Mother Earth Minerals) — declaring
  identifier_exists=no is the honest signal and stops "limited performance"
  warnings for them.

### 3.1.0
- **Merchant API v1beta → v1 migration**: Google discontinued v1beta on
  2026-02-28; all dataSource/product/account calls (`listDataSources`,
  `fetchDataSource`, `getProductStatuses`, the hourly `hp_gmc_sync_status`
  status sync) were failing with HTTP 409. Base URLs now target v1.
  NOTE: Merchant API v1 additionally requires a one-time developer
  registration of the service account's GCP project with the merchant account
  (`accounts/{id}/developerRegistration:registerGcp`) — calls return 401
  `GCP_NOT_REGISTERED` until that is done.
- **Primary feed GTIN**: the `gtin` column now reads WooCommerce's native
  GTIN/UPC/EAN/ISBN field (`_global_unique_id`, WC 9.2+ product Inventory tab)
  first, then legacy meta keys. Values are normalized (separators stripped)
  and must be 8/12/13/14 digits, otherwise emitted empty (fail closed — a
  wrong GTIN causes disapproval; a missing one falls back to brand+mpn).
- Added `tests/` regression ledger (standalone PHP assertion scripts).

## Development

```bash
# Clone repository
git clone https://github.com/holisticpeople/hp-gmc-manager.git

# Install dependencies
composer install

# Deploy to staging (via GitHub Actions)
git push origin dev
```

### Audience run server logs (v1.29.9+)

When a segment run fails (e.g. critical error after "Processing 0 of 200"), the plugin logs to the PHP error log so you can see how far it got. Each line is prefixed with `hp_gmc_audience` and includes `version`, `message`, `memory_mb`, and `peak_mb`.

**On Kinsta staging:** SSH in and run:
```bash
# Recent audience run entries (filter by plugin prefix)
grep hp_gmc_audience /path/to/wp-content/debug.log | tail -100
```
If `WP_DEBUG_LOG` is not set, check the host’s PHP error log path (e.g. in Kinsta dashboard or `php.ini`).

**Messages you’ll see:** `run_segment_start` → `run_segment_calling_internal` → `run_definition_internal_start` → `progress_transient_set` → `engine_run_before` → `batch_progress` (batch 1, 2, 3, then every 10). If it crashes before any batch, you’ll see up to `engine_run_before`; if it’s a PHP fatal (e.g. OOM), the last line in the log is the last step that completed.

**Optional:** Set `HP_GMC_DEBUG_LOG` in `wp-config.php` to a writable path (e.g. `WP_CONTENT_DIR . '/uploads/hp-gmc-debug.log'`) to also write these lines to a dedicated file.

## File Structure

```
hp-gmc-manager/
├── hp-gmc-manager.php          # Main plugin file
├── composer.json               # Composer dependencies
├── includes/
│   ├── Plugin.php              # Core plugin class
│   ├── Admin/
│   │   ├── Dashboard.php       # Dashboard page
│   │   └── SettingsPage.php    # Settings page
│   ├── Services/
│   │   ├── MerchantApiClient.php   # API wrapper
│   │   ├── ProductSync.php         # Product sync service
│   │   ├── IssueMonitor.php        # Status caching
│   │   └── ShippingSettings.php    # Shipping management
│   └── Abilities/
│       ├── ProductAbilities.php    # Product MCP tools
│       └── AccountAbilities.php    # Account MCP tools
└── assets/
    ├── css/dashboard.css
    └── js/dashboard.js
```

## License

GPL v2 or later

## Author

Holistic People - https://holisticpeople.com
