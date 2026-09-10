<h1 align="center">Insightistic</h1>

<p align="center">
  <strong>GA4, Google Search Console, PageSpeed, Cloudflare Traffic Insights, WooCommerce Intelligence, and AI Insights inside WordPress.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-5.6%2B-21759b?style=flat-square" alt="WordPress 5.6+"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square" alt="PHP 8.0+"></a>
  <img src="https://img.shields.io/badge/version-4.4.2-brightgreen?style=flat-square" alt="version 4.4.2">
  <img src="https://img.shields.io/badge/License-GPLv2%2B-blue?style=flat-square" alt="GPL v2+">
</p>

<p align="center"><em>Published by <strong>WordPressistic — Your Digital Partner for Global Impact</strong></em></p>

---

## Overview

Insightistic brings your most important website growth signals into one clean WordPress admin dashboard. It is built for business owners, agencies, marketers, publishers, and WooCommerce stores that need clear analytics without jumping between multiple tools.

| Feature | What it gives you |
| --- | --- |
| **GA4 Overview** | Sessions, users, pageviews, bounce rate, average session duration, new vs returning, revenue, transactions, timeline charts, traffic sources, countries, pages, channels, posts, and source/medium attribution. |
| **Google Search Console** | Clicks, impressions, CTR, average position, top queries, top pages, and device breakdown. |
| **PageSpeed Insights** | Mobile and desktop score rings, Core Web Vitals, lab metrics, and URL testing from WordPress. |
| **Speed Test** | Full Lighthouse audit for mobile and desktop, top opportunities, diagnostics, and AI Agent Readiness scoring. |
| **Cloudflare Traffic Insights** | Hosted Cloudflare traffic data through a connected Insightistic account, with optional advanced BYO Cloudflare credentials. |
| **404 Monitor** | Server-side broken-link and 404 monitoring without adding a front-end tracking dependency. |
| **AI Insights** | On-demand growth analysis using Insightistic Cloud AI or supported bring-your-own-key providers. |
| **Engagement Tracking** | Lightweight optional tracker for outbound clicks, scroll depth, downloads, form submits, site search, video plays, content copy, and selected element clicks. |
| **Free Add-ons** | Email Automations, SEO Opportunity Finder, Anomaly Alerts, Content Performance Lab, and WooCommerce Intelligence. |
| **System Status** | Checks credentials, cron, mail readiness, minified assets, tracker size, and WooCommerce availability. |
| **Security** | Encrypted credential storage, nonce-protected admin actions, capability checks, sanitized input, and escaped output. |

---

## Release Status

| Item | Value |
| --- | --- |
| Plugin version | 4.4.2 |
| WordPress requirement | 5.6 or newer |
| Tested up to | WordPress 6.8 |
| PHP requirement | 8.0 or newer |
| License | GPLv2 or later |
| Publisher | WordPressistic |
| Plugin URI | https://wordpressistic.com/insightistic |

---

## What's In This Repository

The plugin source lives directly at the repository root (standard WordPress plugin layout):

| Path | Description |
| --- | --- |
| [`insightistic.php`](insightistic.php) | Main plugin file (header, constants, activation, migrations). |
| [`readme.txt`](readme.txt) | WordPress.org-style readme with the changelog. |
| [`uninstall.php`](uninstall.php) | Uninstall cleanup. |
| [`LICENSE`](LICENSE) | GPL-2.0-or-later license text. |
| [`includes/`](includes/) | Plugin classes (GA4, GSC, PageSpeed, Cloudflare, AI, encryption, admin, etc.). |
| [`templates/`](templates/) | Admin page templates (dashboard, settings, addons, license, speed test, system status). |
| [`assets/`](assets/) | CSS, first-party JS (source + minified), and the bundled Chart.js 4.4.4 vendor file. |
| [`languages/`](languages/) | Translation template (`insightistic.pot`). |
| [`tests/`](tests/) | Standalone PHP test suites (GA4 regression fixtures, encryption, WordPress stubs). |
| [`scripts/`](scripts/) | Release/build tooling (asset builder, POT generator, release validator, ZIP builder/validator). |
| [`composer.json`](composer.json) / [`phpcs.xml.dist`](phpcs.xml.dist) | PHP tooling: PHP_CodeSniffer, WordPress Coding Standards, PHPCompatibilityWP. |
| [`package.json`](package.json) | Node tooling: terser + clean-css asset builds. |
| [`.github/workflows/`](.github/workflows/) | CI (lint/standards/tests/build) and tag-driven release workflows. |

---

## Installation

From a release (recommended):

1. Download the release ZIP: `insightistic.4.4.2.zip`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file — it extracts to a single `insightistic/` directory.
4. Activate **Insightistic - GA4 Analytics & AI Insights**.
5. Go to **Insightistic > Settings** and connect the services you want to use.

Manual install:

1. Extract the release ZIP.
2. Upload the `insightistic` folder to `/wp-content/plugins/`.
3. Activate **Insightistic** from the WordPress **Plugins** screen.

From source (development):

```bash
composer install
npm install
npm run build:assets
```

---

## Requirements

- WordPress **5.6+**.
- PHP **8.0+**.
- A Google Cloud project and service account for GA4 and Search Console.
- A PageSpeed Insights API key for PageSpeed reports.
- Optional: a free Insightistic account for Insightistic Cloud AI, email automation delivery, hosted Cloudflare Traffic Insights, and WooCommerce/site-health sync.
- Optional: supported AI provider keys for bring-your-own-key AI analysis.

---

## Setup Summary

GA4:

1. Create a Google Cloud service account.
2. Download the service account JSON key.
3. Add the service account email to your GA4 property with Viewer access.
4. Add the GA4 Property ID and JSON key in **Insightistic > Settings > GA4**.

Search Console:

1. Add the same service account email to Google Search Console.
2. Paste the Search Console property URL in **Insightistic > Settings > Search Console**.

PageSpeed:

1. Enable the PageSpeed Insights API in Google Cloud.
2. Create an API key.
3. Add the key in **Insightistic > Settings > PageSpeed**.

AI Insights:

1. Connect a free Insightistic account for Insightistic Cloud AI, or add a supported provider API key.
2. Load analytics data first.
3. Click **Get AI Insights** from the dashboard.

---

## Development

### Commands

```bash
composer install          # install PHP dev tooling (phpcs, WPCS, PHPCompatibilityWP)
npm install               # install asset build tooling (terser, clean-css)

npm run build:assets      # rebuild admin.min.css / admin.min.js / tracking.min.js from source
npm run check:assets      # fail if committed minified files are stale
npm run js:check          # node --check across first-party JS
composer test             # PHP test suites (GA4 regression, encryption, ...)
composer make-pot         # regenerate languages/insightistic.pot
composer release-check    # full pre-release gate (versions, lint, tests, assets, ZIP, ZIP validation)
```

### Asset architecture

| Source (canonical) | Built artifact | Notes |
| --- | --- | --- |
| `assets/css/admin.css` | `assets/css/admin.min.css` | Rebuilt with clean-css (level 2). |
| `assets/js/admin.js` | `assets/js/admin.min.js` (+ `.map`, dev only — the map is **not** shipped) | Rebuilt with terser. |
| `assets/js/tracking.js` | `assets/js/tracking.min.js` | Rebuilt with terser; hard size budget < 3 KiB enforced by the build. |
| `assets/js/vendor/chart.umd.min.js` | — | Bundled Chart.js **4.4.4** dependency. Never rebuilt or version-bumped with the plugin. |

Minified files are served in production; readable sources are served when `SCRIPT_DEBUG` is on. A release fails CI if the committed minified files differ from a fresh build.

### Release process

1. Update the version contract — `insightistic.php` header + `INSIGHTISTIC_VERSION`, `readme.txt` stable tag + changelog, `package.json`, and regenerate the POT. `php scripts/validate-release.php` must pass.
2. Run `composer release-check` (versions, PHP lint, tests, assets, POT, ZIP build + ZIP content validation).
3. Commit, then tag `insightistic-X.Y.Z` and push the tag. The **Release** GitHub Actions workflow builds the ZIP **from the tagged commit**, validates it, and attaches it (plus its SHA-256 checksum) to the GitHub release. Manually built ZIPs are never published.

The tag workflow refuses to release when the tag version does not exactly match the plugin's internal version.

---

## Testing

- `tests/test-ga4-named-ranges.php` — regression coverage for the GA4 named `dateRange` response format (the 4.4.1 fix): overview KPIs, channels, pages, and countries, with period-over-period changes and no current/previous row duplication.
- `tests/test-ga4-legacy.php` — the legacy `totals[]` response format must keep working.
- `tests/test-ga4-edge-cases.php` — zero previous period, empty results, missing metrics, malformed payloads, API errors, current-only/previous-only responses, division-by-zero, numeric strings, extra dimensions.
- `tests/test-encryption.php` — credential encryption round-trips, tamper detection, and legacy (< 3.3.0) format decryption/migration.

All suites run standalone (WordPress APIs are stubbed; HTTP is served from fixtures) — no network access or secrets required.

---

## License

Released under the [GPL v2 or later](LICENSE).

---

<p align="center">
  <strong>
    <a href="https://wordpressistic.com">WordPressistic — Your Digital Partner for Global Impact</a>
  </strong>
</p>

<p align="center">
  <sub>Built and published by <a href="https://wordpressistic.com">wordpressistic.com</a>.</sub>
</p>
