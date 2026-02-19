# CLI scripts

## test-ads-create-list.php

Test the **Google Ads API “create user list”** call (Customer Match) from the command line. Use this to verify that adding the service account domain to **Allowed domains** in Google Ads fixed the 403 permission error, without going through the full plugin UI.

### Requirements

- PHP 8.0+
- `composer install` run in the plugin root (so `vendor/` exists)
- Service account JSON key path (same as in GMC Manager > Settings)
- Developer token and Google Ads customer ID (and manager ID if using an MCC) from GMC Manager > Settings

### Run

From the **plugin root** (directory containing `composer.json`):

```bash
cd /path/to/hp-gmc-manager
php scripts/test-ads-create-list.php \
  --credentials=/path/to/your-service-account.json \
  --developer-token=YOUR_DEVELOPER_TOKEN \
  --customer-id=6629157252 \
  --manager-id=6063247756
```

Omit `--manager-id` if you use a single Google Ads account (no manager).

- **Success:** script prints `OK (HTTP 200)` and a `resourceName`. Exit code 0.
- **403 / permission error:** script prints `HTTP 403` and the API response. Exit code 1.

After adding the service account domain to Allowed domains in Google Ads, run this script again; if it returns 200, the plugin’s “Upload to Google Ads” flow should work as well.
