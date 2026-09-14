# Google quality presentation preview

HP-GMC 3.4.20 adds `hp_gmc_get_store_quality_presentation_v1(): ?array`.
It returns `url`, `label`, `description`, `preview: true`, `interactive: true`
only on supported public routes with `hp_google_quality_preview=1`, HTTPS,
matching known request/home host, configured merchant 5298746911, and a signed-in
administrator or shop manager capability. Private order/account routes,
unapproved query data and protected/unpublished pages are denied.

HP-Zen and HP-Checkout consume the optional provider, retaining the old link
when absent/null/malformed. Controls share one delegated, non-submit loader.
The layout can be reviewed on staging; its hard environment gate forbids Google
requests. Production preview loads the official script only after staff click.
Preview responses are private/no-store and set DONOTCACHEPAGE. Verify the served
cache headers and anonymous denial; PHP headers alone cannot prove CDN behavior.

Google owns the launcher, panel and branding. A second click on its native
launcher is expected. Neither script load nor start proves visibility, rating,
receipt or award. The bounded wrapper observation only confirms a non-hidden,
nonzero-size iframe intersecting the viewport, never its cross-origin contents. Human inspection must
confirm the genuine panel. Errors offer an external fallback without implying
ineligibility. No browser receipt writer, admin editor, scheduler, survey,
feed or analytics event is added.

## Release gates

First merge/deploy and verify staging with the three owners coordinated. Sole
Publish Manager prepares an isolated production preview candidate, retaining the
accepted Return Policy and current public footer. Do not broadly promote other
dev changes. Verify authenticated preview and anonymous baseline on phone,
tablet, desktop and wide layouts, repeated activation, blocked/slow scripts,
fallback, keyboard use and populated custom checkout. Confirm cache privacy,
GCR unchanged, and native panel visible on production. Obtain human visual
acceptance before a separate public-activation change. Current public floating
widget setting stays disabled. No settings migration is part of this version.

Review widget loading, native visibility, review availability, provider
syndication receipts and Google award as separate observations in the existing
Google Submit Data imports. Preserve last-good observations on access failure.
