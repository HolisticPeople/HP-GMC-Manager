# Google store quality destination v1

HP-GMC owns `hp_gmc_get_store_quality_link_v1(): ?array`. It returns `url` and translated `label` strings for the verified HolisticPeople Google store page, or null when the HTTPS site/merchant identity is unsupported. It performs no network requests or writes and is independent of the optional floating widget setting.

HP-Zen footer, HP-Checkout trust section and the GMC Reviews-page section own their markup. Consumers omit the link if the function is absent, fails or returns no valid strings. Links use target `_blank`, rel `noopener noreferrer`, an external-link indicator and screen-reader new-tab text. This is a store information link, not a promised review form or awarded badge.

Authorized September 14 presentation release. GCR, feeds, survey consent and submitted Merchant Center settings are unchanged. Disable only the existing floating-widget option after production links are verified.
