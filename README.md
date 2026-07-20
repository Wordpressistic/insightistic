<p align="center">
  <img src="assets/branding/insightistic-badge.png" alt="Insightistic" width="180" />
</p>

<h1 align="center">Insightistic</h1>

<p align="center">
  <strong>GA4 · Google Search Console · PageSpeed · AI Insights — all inside WordPress.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-5.6%2B-21759b?style=flat-square" alt="WordPress 5.6+"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square" alt="PHP 8.0+"></a>
  <a href="insightistic/insightistic.php"><img src="https://img.shields.io/badge/version-4.1.0-brightgreen?style=flat-square" alt="version 4.1.0"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%2B-blue?style=flat-square" alt="GPL v2+"></a>
</p>

<p align="center"><em>Published by <strong>WordPressistic — AI Business Automation Solutions</strong></em></p>

---

## Overview

Insightistic brings your most important Google traffic signals — **GA4**, **Search Console**, **PageSpeed**, and **on-demand AI Analysis** — into one clean WordPress admin dashboard. No OAuth pop-ups, no tab-switching, no sampled data.

| Feature | What it gives you |
| --- | --- |
| **GA4 Overview** | Sessions, users, pageviews, bounce rate, avg. session duration, new vs returning, revenue, transactions — all with period-over-period comparison, timeline + source donut, top countries / pages / channels / posts, source/medium attribution. |
| **Google Search Console** | Clicks, impressions, CTR, average position, top 10 queries, top 10 pages, device breakdown. |
| **PageSpeed Insights** | Animated mobile + desktop score rings, full Core Web Vitals grid (LCP, INP, CLS, FCP, TBT, Speed Index), test any URL on your site. |
| **Speed Test** | Full Lighthouse audit (mobile + desktop) — all four category scores, Core Web Vitals + lab metrics (incl. TTFB), top opportunities with estimated savings, diagnostics, and an AI Agent Readiness score. |
| **AI Insights (on demand)** | OpenAI, Google Gemini, OpenRouter (free models supported), Groq, Anthropic Claude. Always manual — never background. |
| **Engagement Tracking** | < 3 KB script for outbound clicks, scroll depth, file downloads, form submits, site search, video plays, content-copy, and element clicks. Zero PII, no cookies. |
| **Free add-ons** | Every add-on works immediately, no license required: Email Automations, SEO Opportunity Finder, Anomaly Alerts, Content Performance Lab, and WooCommerce Intelligence (revenue, orders, products, customers). |
| **Free Insightistic account** | Only needed for two flows: AI Insights and Email Automation delivery. Connecting an account also syncs WooCommerce orders/products/customers/site health to the Insightistic dashboard (daily auto-sync + manual "Sync now"). |
| **System Status** | Checks credentials, cron, mail readiness, minified assets, tracker size, and WooCommerce availability before launch. |
| **Security** | Service Account JSON keys, all credentials AES-256-CBC + HMAC-SHA256 (HKDF-derived) encrypted at rest. Nonce-protected AJAX, sanitised + escaped inputs. |

---

## What's in this repository

| Path | Description |
| --- | --- |
| [`insightistic/`](insightistic/) | The full WordPress plugin (v4.1.0). Drop this folder into `wp-content/plugins/` or zip it for upload. |
| [`docs/Insightistic-v4.1-User-Guide.pdf`](docs/Insightistic-v4.1-User-Guide.pdf) | Branded customer user guide — GA4, Search Console, PageSpeed, Speed Test, AI, features, add-ons, account/license. |
| [`assets/branding/`](assets/branding/) | Brand badges used in the docs and README (WordPressistic + Insightistic). |
| [`scripts/build_user_guide_pdf.py`](scripts/build_user_guide_pdf.py) | Regenerates the user-guide PDF with the latest branding. |
| [`scripts/build_brand_badges.py`](scripts/build_brand_badges.py) | Regenerates the brand badge PNGs. |

---

## Installation

1. Download or clone this repository.
2. Copy the [`insightistic/`](insightistic/) folder into `wp-content/plugins/` on your WordPress site, or zip it and upload via **Plugins → Add New → Upload Plugin**.
3. Activate **Insightistic** from the **Plugins** menu.
4. Go to **Insightistic → Settings → GA4** and paste your Google Service Account JSON key.
5. Enter your GA4 Property ID, click **Save Settings**, and open the dashboard.

Full step-by-step setup for GA4, Search Console, PageSpeed, and each supported AI provider is in the [User Guide PDF](docs/Insightistic-v4.1-User-Guide.pdf).

---

## Requirements

- WordPress **5.6+** (tested up to 6.8)
- PHP **8.0+**
- A Google Cloud project + Service Account (for GA4 / Search Console)
- A PageSpeed Insights API key *(optional)*
- An AI provider key — OpenAI / Gemini / OpenRouter / Groq / Anthropic *(optional, only needed for AI Insights)*
- A free Insightistic account *(optional, only needed for AI Insights and Email Automation delivery)*

---

## Regenerating brand assets

```bash
pip install reportlab Pillow

# 1. Brand badges (PNG)
python3 scripts/build_brand_badges.py

# 2. User-guide PDF
python3 scripts/build_user_guide_pdf.py \
    --wp-logo     assets/branding/wordpressistic-badge.png \
    --plugin-logo assets/branding/insightistic-badge.png \
    --out         docs/Insightistic-v4.1-User-Guide.pdf
```

---

## Documentation

- **User guide:** [`docs/Insightistic-v4.1-User-Guide.pdf`](docs/Insightistic-v4.1-User-Guide.pdf)
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
    <a href="https://wordpressistic.com">WordPressistic — AI Business Automation Solutions</a>
  </strong>
</p>

<p align="center">
  <sub>Built and published by <a href="https://wordpressistic.com">wordpressistic.com</a>.</sub>
</p>
