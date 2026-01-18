# HP GMC Manager

Google Merchant Center management plugin for WordPress/WooCommerce with admin dashboard and MCP abilities.

## Features

- **Admin Dashboard**: Visual overview of GMC product statuses, issues, and exclusions
- **MCP Abilities**: AI-powered operations via Cursor integration
- **Shipping Management**: Account-level shipping settings control
- **GLA Complement Mode**: Works alongside Google Listings & Ads plugin
- **Dry Run Mode**: Safe testing on staging without affecting production GMC

## Requirements

- PHP 8.0+
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
3. **Exclusions**: Manage destination exclusions
4. **Shipping**: Account-level shipping settings
5. **MCP Tools**: Enable/disable individual tools
6. **Dry Run Log**: View simulated actions (staging only)

## GLA Integration

This plugin complements the Google Listings & Ads (GLA) plugin:

- GLA handles product sync (feed-based)
- HP GMC Manager adds monitoring, shipping settings, and MCP tools
- Reads GLA's product ID mapping (`gla_XXXXX`)
- Does not conflict with GLA's sync operations

## Development

```bash
# Clone repository
git clone https://github.com/holisticpeople/hp-gmc-manager.git

# Install dependencies
composer install

# Deploy to staging (via GitHub Actions)
git push origin dev
```

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
