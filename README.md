# Kodepos Indonesia for WooCommerce (SQLite Version)

Cascading Indonesian address dropdowns — **Provinsi → Kota/Kabupaten → Kecamatan → Kelurahan/Desa → Kode Pos** — for the WooCommerce block checkout and admin screens, powered by a ~84,000-row postal code database bundled directly inside the plugin.

No API keys, no external service, no per-site data import — everything is queried locally from a read-only SQLite file that ships with the plugin.

## Why this exists

WooCommerce's default Indonesian address form only offers a flat Province/State list and a free-text City field, with no district (Kecamatan), sub-district (Kelurahan/Desa) or postal code lookup. This plugin adds the missing cascade using an official Indonesian postal code dataset, entirely offline.

## Features

- **Cascading selects** on the WooCommerce block checkout, the admin order edit screen, the admin user profile address fields, and the WooCommerce → Settings → General store address form.
- **Auto-filled postal code** once a Kelurahan/Desa is selected.
- **Native province codes preserved** — the Province/State field stays WooCommerce's built-in Indonesia state list (`AC`, `JK`, `KB`, …) rather than being replaced, so existing tax rates, shipping zones and saved addresses that reference those codes keep working. Cities are looked up from that native code.
- **District / sub-district persisted** via WooCommerce's Additional Checkout Fields API (`kodepos-indonesia/district`, `kodepos-indonesia/sub-district`), so they show up on orders, order emails and My Account automatically.
- **Graceful degradation** — if the bundled database can't be read (e.g. `pdo_sqlite` missing), the plugin falls back to WooCommerce's default fields. Checkout is never blocked, and an admin notice under **WooCommerce → Kodepos Indonesia** explains why.
- **HPOS and Cart/Checkout Blocks compatible.**

## How it works

```
data/kodepos.sqlite  (read-only, ~84k rows)
        │
        ▼
Kodepos_DB            PDO/SQLite wrapper, query_only mode
        │
        ▼
Kodepos_Rest_Proxy     REST API under kodepos-indonesia/v1 (public, read-only)
        │
        ▼
assets/js/cascade-core.js   fetches provinces/cities/districts/sub-districts/postcodes
        │
        ├── checkout-blocks.js   wires the cascade into the WooCommerce block checkout
        └── admin-address.js    wires the cascade into order/profile/settings screens
```

The SQLite file itself is never served to the browser — a `.htaccess` deny rule and an empty `index.php` guard the `data/` directory, and the browser only ever talks to the REST proxy.

### REST API

All routes are namespaced under `kodepos-indonesia/v1` and are public/read-only:

| Route | Required query param | Returns |
|---|---|---|
| `GET /provinces` | — | All provinces |
| `GET /cities` | `province_code` | Cities in a province (dataset province code) |
| `GET /cities-by-state` | `wp_state_code` | Cities for a native WooCommerce/WP Indonesia state code |
| `GET /districts` | `city_code` | Districts (Kecamatan) in a city |
| `GET /sub-districts` | `district_code` | Sub-districts (Kelurahan/Desa) in a district |
| `GET /postal-codes` | `sub_district_code` | Postal codes for a sub-district |

Responses are `{ "items": [...] }` and cached with `Cache-Control: public, max-age=300`. If the bundled database is unavailable, routes return a `503` with error code `kodepos_not_configured`.

## Requirements

- WordPress 6.5+
- PHP 7.4+
- WooCommerce 8.0+ (declares compatibility with HPOS and Cart/Checkout Blocks)
- PHP `pdo_sqlite` extension (ships with virtually all PHP builds)

## Installation

1. Copy this plugin into `wp-content/plugins/`.
2. Activate it from **Plugins** (WooCommerce must already be active).
3. That's it — checkout and admin address forms pick up the cascade automatically for Indonesian addresses. No configuration is needed.

Check **WooCommerce → Kodepos Indonesia** at any time for a diagnostics page showing whether the `pdo_sqlite` extension is enabled, whether the bundled database loaded, its data version and row count.

## Project structure

```
kodepos-indonesia.php               Plugin bootstrap, HPOS/Blocks compatibility
includes/
  class-kodepos-db.php              Read-only PDO/SQLite data access layer
  class-kodepos-rest-proxy.php      Public REST API exposing the DB to the browser
  class-kodepos-checkout.php        Block checkout integration + additional fields
  class-kodepos-admin.php           Order / profile / store settings integration
  class-kodepos-settings.php        WooCommerce → Kodepos Indonesia diagnostics page
assets/
  js/cascade-core.js                Shared cascade fetch/render logic
  js/checkout-blocks.js             Block checkout wiring
  js/admin-address.js               Admin screens wiring
  css/kodepos.css
data/
  kodepos.sqlite                    Bundled, read-only postal code database
build/
  csv-to-sqlite.php                 CLI tool to regenerate data/kodepos.sqlite from a CSV
uninstall.php                       Cleans up legacy options/transients on uninstall
```

## Regenerating the bundled database

The SQLite file is built once from a semicolon-delimited CSV and committed to the repo — it is **not** generated on the user's server. To rebuild it from an updated dataset:

```
php build/csv-to-sqlite.php [source.csv] [target.sqlite]
```

Defaults to `kodepos-indonesia.csv` in the repo root and `data/kodepos.sqlite`. The CSV must have the header:

```
province_code;province;city_code;city;district_code;district;sub_district_code;sub_district;postal_code;wp_state_code
```

Rows with a blank `province_code` are skipped (they can't be resolved back to a province and would produce duplicate/blank entries in province-keyed lookups). The script writes `data_version` (build date) and `row_count` into a `meta` table, then `VACUUM`s the file.

## Data storage

District and sub-district selections are stored as WooCommerce Additional Checkout Fields meta:

- `_wc_billing/kodepos-indonesia/district`, `_wc_billing/kodepos-indonesia/sub-district`
- `_wc_shipping/kodepos-indonesia/district`, `_wc_shipping/kodepos-indonesia/sub-district`

These are the same keys used by orders, order emails, My Account, the admin order editor, admin user profiles and the store address settings, so a value entered anywhere is visible everywhere.

## FAQ

**What happens if the bundled database can't be read?**
The plugin falls back to WooCommerce's default fields — checkout is never blocked. An admin notice under **WooCommerce → Kodepos Indonesia** explains why (usually a missing `pdo_sqlite` PHP extension).

**Why does the classic (PHP) WooCommerce settings screen render instead of the React one?**
WooCommerce's React "settings-ui" feature saves store address settings from its own internal state, which never sees the cascading selects, so district/sub-district selections would silently fail to save. The plugin disables that feature so the classic renderer — which the cascade fully supports — is used instead.

## License

GPLv2 or later.
