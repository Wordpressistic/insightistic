<p align="center">
  <img src="assets/branding/insightistic-badge.png" alt="Insightistic" width="180" />
</p>

<h1 align="center">Insightistic</h1>

<p align="center">
  <strong>GA4, Google Search Console, PageSpeed, Cloudflare Traffic Insights, WooCommerce Intelligence, and AI Insights inside WordPress.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-5.6%2B-21759b?style=flat-square" alt="WordPress 5.6+"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square" alt="PHP 8.0+"></a>
  <a href="insightistic/insightistic.php"><img src="https://img.shields.io/badge/version-4.4.0-brightgreen?style=flat-square" alt="version 4.4.0"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%2B-blue?style=flat-square" alt="GPL v2+"></a>
</p>

<p align="center"><em>Published by <strong>WordPressistic - Your Digital Partner for Global Impact</strong></em></p>

---

## Overview

Insightistic brings your most important website growth signals into one clean WordPress admin dashboard. It is built for business owners, agencies, marketers, publishers, and WooCommerce stores that need clear analytics without jumping between multiple tools.

This is the first public WordPressistic release package for Insightistic, using the current plugin version from the codebase: **4.4.0**.

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
| Plugin version | 4.4.0 |
| WordPress requirement | 5.6 or newer |
| Tested up to | WordPress 6.8 |
| PHP requirement | 8.0 or newer |
| License | GPLv2 or later |
| Publisher | WordPressistic |
| Plugin URI | https://wordpressistic.com/insightistic |

---

## What's In This Repository

| Path | Description |
| --- | --- |
| [`insightistic/`](insightistic/) | The full WordPress plugin, version 4.4.0. Drop this folder into `wp-content/plugins/` or zip it for upload. |
| [`docs/Insightistic-v4.4-User-Guide.pdf`](docs/Insightistic-v4.4-User-Guide.pdf) | Branded public user guide for setup and day-to-day use. |
| [`assets/branding/`](assets/branding/) | WordPressistic and Insightistic brand badges used in the README and docs. |
| [`scripts/build_user_guide_pdf.py`](scripts/build_user_guide_pdf.py) | Regenerates the branded user-guide PDF. |
| [`scripts/build_brand_badges.py`](scripts/build_brand_badges.py) | Regenerates the brand badge PNGs. |

---

## Installation

1. Download the release ZIP: `insightistic-4.4.0.zip`.
2. In WordPress, go to **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP file.
4. Activate **Insightistic - GA4 Analytics & AI Insights**.
5. Go to **Insightistic > Settings** and connect the services you want to use.

Manual install:

1. Extract the release ZIP.
2. Upload the `insightistic` folder to `/wp-content/plugins/`.
3. Activate **Insightistic** from the WordPress **Plugins** screen.

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

Full setup details are available in the [Insightistic v4.4 User Guide](docs/Insightistic-v4.4-User-Guide.pdf).

---

## Public Release Package

The public release ZIP is intentionally clean. It includes only the installable plugin and public documentation:

- `insightistic/`
- `insightistic/readme.txt`
- `insightistic/docs/Insightistic-v4.4-User-Guide.pdf`
- `insightistic/LICENSE`

It does not include development tooling, tests, build scripts, repository metadata, internal workflow notes, source maps, `node_modules`, or assistant-specific files.

---

## Regenerating Brand Assets

```bash
pip install reportlab Pillow

# 1. Brand badges
python scripts/build_brand_badges.py

# 2. User-guide PDF
python scripts/build_user_guide_pdf.py \
    --wp-logo assets/branding/wordpressistic-badge.png \
    --plugin-logo assets/branding/insightistic-badge.png \
    --out docs/Insightistic-v4.4-User-Guide.pdf
```

---

## Documentation

- **User guide:** [`docs/Insightistic-v4.4-User-Guide.pdf`](docs/Insightistic-v4.4-User-Guide.pdf)
- **Plugin readme:** [`insightistic/readme.txt`](insightistic/readme.txt)
- **Changelog:** see the `== Changelog ==` section of [`insightistic/readme.txt`](insightistic/readme.txt)

---

## License

Released under the [GPL v2 or later](LICENSE).

---

<p align="center">
  <img src="assets/branding/wordpressistic-badge.png" alt="WordPressistic" width="120" />
</p>

<p align="center">
  <strong>
    <a href="https://wordpressistic.com">WordPressistic - Your Digital Partner for Global Impact</a>
  </strong>
</p>

<p align="center">
  <sub>Built and published by <a href="https://wordpressistic.com">wordpressistic.com</a>.</sub>
</p>
