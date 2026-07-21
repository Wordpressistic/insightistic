=== Insightistic - GA4 Analytics & AI Insights ===
Contributors: wordpressistic
Tags: google analytics, analytics, search console, pagespeed, ai insights
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 4.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

View GA4, Search Console, PageSpeed, Cloudflare, WooCommerce, and AI growth insights inside your WordPress dashboard.

== Description ==

Insightistic brings your most important website metrics directly into WordPress. It gives business owners, agencies, marketers, publishers, and WooCommerce stores a clean analytics dashboard without constant tab-switching.

Connect your Google Analytics 4 property and Google Search Console via a single service account (no OAuth pop-ups, no re-authentication). Add a PageSpeed API key for real-time Core Web Vitals scoring, and optionally activate AI insights powered by OpenAI, Google Gemini, OpenRouter, Groq, or Anthropic Claude.

= GA4 Overview =

* 8 stat cards  Sessions, Users, Pageviews, Bounce Rate, Avg. Session Duration, New vs Returning, Revenue, Transactions  all with period-over-period comparison
* Timeline chart: sessions and revenue over time
* Traffic source donut chart
* Top Countries, Top Pages, Top Channels  with share bars
* Top Blog Posts  automatically filters GA4 data for /blog/, /post/, /news/ paths
* Source/Medium attribution table with revenue %, revenue per session, and conversion rate

= Google Search Console =

* Clicks, Impressions, CTR, and Average Position at a glance
* Top 10 queries and top 10 pages
* Device breakdown (desktop / mobile / tablet)

= PageSpeed Insights =

* Animated score rings for mobile and desktop
* Core Web Vitals grid: LCP, INP, CLS, FCP, TBT, Speed Index  each with a pass/fail badge
* Test any URL on your site, not just the homepage

= Engagement Tracking =

* Optional lightweight frontend script (under 2 KB) that fires custom GA4 events
* Tracks: outbound link clicks, scroll depth (25 / 50 / 75 / 100 %), file downloads, and element clicks
* Zero PII collected  no cookies, no fingerprinting

= Cloudflare Traffic Insights =

* Zero configuration by default: connect your free Insightistic account (the same one used for AI Insights) and Traffic Insights turns on automatically — no Zone ID, no API token, nothing to paste
* Prefer not to connect an account? An Advanced section in Settings → Cloudflare lets you paste your own read-only Cloudflare API token instead
* Edge-level requests, cache hit rate, encrypted-traffic ratio, and threats blocked — data Google Analytics structurally cannot see (bots, ad-blockers, JS-disabled visitors, blocked scripts)
* Requests-over-time and status-code charts, Top Countries and TLS version breakdowns
* "Traffic Gap" callout: Cloudflare's edge pageviews vs GA4's client-side pageviews for the same period, with the likely causes for the delta
* Security section: firewall actions and top attacking countries from your zone's existing WAF events
* Optional "Get Traffic AI Insights" narrative (needs a free Insightistic account, same as every other AI Insights flow)
* Never required for anything else — if neither path is set up, the tab simply doesn't appear

= 404 & Broken Link Monitor =

* Free, on by default, purely server-side  no script, no dependency on the engagement tracker
* Records request path, distinct referring domains, hit count, and last-seen time for every 404 on your site
* View the top offenders on the Dashboard Overview tab; clear the log any time
* No IP address or user agent stored

= AI Insights (on demand) =

* Click "Get AI Insights" on the dashboard to analyse your GA4 and GSC data
* Choose your AI provider: OpenAI, Google Gemini, OpenRouter (free models supported), Groq, Anthropic Claude, or Insightistic Cloud AI (no key needed  runs on our self-hosted Ollama models and Hermes SEO agent, free with your account)
* AI never runs automatically  you stay in full control of API usage and cost

= Email Automations (free account required) =

* Send branded analytics digests to your team on a daily, weekly, or monthly schedule
* Includes KPI cards, period-over-period changes, top channels, top pages, top content, and recommended next actions
* Schedule by site timezone, choose weekly day and send time, and send a manual test digest before launch
* Sending needs a free Insightistic account (no card, free forever) — everything else in the plugin works without one

= Addon Intelligence =

* Five free addons, every one unconditionally free to use: Email Automations, SEO Opportunity Finder, Anomaly Alerts, Content Performance Lab, and WooCommerce Intelligence for revenue, order, and product decisions
* Addon reports use cached GA4/GSC data to avoid unnecessary API usage
* System Status page checks credentials, cron, mail readiness, minified assets, tracker size, and WooCommerce availability
* Export/import non-secret plugin settings for faster migration and support

= Security =

* Service account authentication  keys never expire, no OAuth callbacks
* All API keys and credentials are encrypted with AES-256-CBC before being stored in your database, using your WordPress auth salt as the encryption key
* Nonce-protected AJAX handlers; all inputs sanitised and escaped

= Performance =

* All analytics data is fetched via admin-only AJAX  zero impact on frontend page load
* 15-minute transient caching minimises Google API quota usage
* Minified JS and CSS served in production (source files served when SCRIPT_DEBUG is on)
* Chart.js bundled locally  no external CDN requests

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Insightistic  Settings** and open the **GA4** tab
4. Paste your Google Service Account JSON key (or use the "Import from JSON" button)
5. Enter your GA4 Property ID (numeric, e.g. 123456789)
6. Click **Save Settings**, then open the dashboard

**GA4 Service Account setup:**

1. Go to [Google Cloud Console](https://console.cloud.google.com/)  IAM & Admin  Service Accounts
2. Create a new service account and download its JSON key file
3. In the plugin Settings  GA4 tab, click **Import from JSON** and paste the key file contents
4. In your GA4 property, go to Admin  Property Access Management and add the service account email with the **Viewer** role
5. Enter your GA4 Property ID and click Save

**Search Console (optional):**
Use the same service account email  add it as a Full User in Google Search Console  Settings  Users and permissions.

**PageSpeed (optional):**
Create an API key in Google Cloud Console with the PageSpeed Insights API enabled and enter it in Settings  PageSpeed.

== Frequently Asked Questions ==

= Does this slow down my site? =

No. All data is fetched in the WordPress admin via AJAX. The optional engagement tracking script is under 2 KB and is only loaded when you enable it.

= Why use a service account instead of OAuth? =

Service account tokens never expire and require no browser callback. OAuth tokens typically expire every 7 days and need periodic re-authorisation, which can silently break dashboards on shared or managed hosting.

= Are my API keys stored securely? =

Yes. Every credential is encrypted with AES-256-CBC using your site's unique WordPress auth salt before being written to the database. Keys are never stored in plaintext.

= Does the AI analysis run automatically? =

No. AI analysis is always triggered manually by clicking "Get AI Insights". This keeps you in full control of API usage and cost.

= Which AI providers are supported? =

OpenAI (GPT-4o Mini and above), Google Gemini (1.5 Flash and above), Anthropic Claude (Haiku and above), Groq (fast, low-cost Llama models), OpenRouter with free and paid models including Mistral, Llama, Gemma, Qwen, DeepSeek, and Phi, and Insightistic Cloud AI  our own self-hosted Ollama models plus a Hermes SEO skill agent, free with a connected account and no key to manage.

= What does the engagement tracking script collect? =

When enabled, the script fires custom events to your GA4 property for: outbound link clicks, scroll depth milestones (25 / 50 / 75 / 100 %), file downloads (.pdf, .zip, etc.), and designated element clicks. No personally identifiable information is collected, no cookies are set, and no data is sent anywhere other than your own GA4 property.

= Is GA4 the only analytics platform supported? =

Yes. Insightistic is purpose-built for GA4. Universal Analytics was shut down by Google in July 2023.

= What date range does Search Console cover? =

Search Console data has a 23 day processing delay and covers up to 16 months of history. The plugin requests the last 90 days by default.

= Will this work on multisite? =

The plugin can be network-activated but each site requires its own credentials configured separately via its own Insightistic Settings page.

== Screenshots ==

1. Overview dashboard  8 stat cards, timeline chart, traffic source donut, top countries, pages, and channels
2. Search Console tab  clicks, impressions, CTR, average position, top queries, top pages, and device breakdown
3. PageSpeed tab  animated score rings for mobile and desktop, plus Core Web Vitals grid with pass/fail badges
4. AI Insights panel  overall score, key findings, and prioritised recommendations
5. Settings page  tabbed interface covering GA4, Search Console, PageSpeed, Engagement, AI, and Docs
6. Addons page  five free modules: Email, SEO, Anomaly, Content Lab, and WooCommerce Intelligence

== Changelog ==

= 4.4.0 =
* Release: first public WordPressistic release package for Insightistic, keeping the current plugin version at 4.4.0 and preparing the plugin for clean public distribution.
* Change: Cloudflare Traffic Insights no longer requires configuring anything separately. Connect your free Insightistic account (the same one used for AI Insights and email automation) and it turns on automatically — no Zone ID, no API token. The direct Zone ID/API token setup from 4.2.0-4.3.0 is now an opt-in "Advanced" section in Settings → Cloudflare for anyone who'd rather not connect an account; it still works exactly as before.
* UI: the Traffic Insights dashboard tab is now fully hidden (not shown with a "Setup" badge) whenever neither path is available, instead of nagging users to configure it — this feature is never required for anything else in the plugin.
* Dev: documents the new `/api/plugin/cloudflare-traffic` SaaS contract in docs/APP-CONNECT-WORKFLOW.md — both data paths (hosted and Advanced/BYO) normalize to the same payload shape, so the dashboard tab, Traffic Gap callout, security monitor, and AI narrative are unchanged regardless of which one is active.

= 4.3.0 =
* New: Cloudflare Traffic Insights is now a full dashboard tab, free for everyone, no Insightistic account required. Builds on the 4.2.0 credentials foundation with:
  * A GraphQL data layer over Cloudflare's free-plan `httpRequests1dGroups` + `firewallEventsAdaptiveGroups` datasets (retry/backoff, 15-minute cache, same reliability pattern as GA4/GSC/Woo).
  * A "Traffic Insights" tab: edge requests, cache hit rate, threats blocked, and encrypted-traffic stat cards; a requests-over-time + status-code chart pair; Top Countries and TLS Version Mix tables.
  * A "Traffic Gap" callout comparing Cloudflare's edge-detected pageviews against GA4's client-side pageviews for the same period — the delta is bots/crawlers, ad-blockers, JS-disabled visitors, or a blocked GA4 script, i.e. real traffic Google Analytics alone never shows you.
  * A Security section: firewall actions (block/challenge/log/etc.) and top attacking countries, built from the same firewall-event data.
  * A "Get Traffic AI Insights" button, gated by the same free-account requirement as every other AI Insights flow — no new gate introduced.
* Note: the GraphQL query was written against Cloudflare's publicly documented schema but has not been exercised against a live zone in the environment this was built in — see docs/APP-CONNECT-WORKFLOW.md §9 for the verification checklist. A field-name mismatch degrades gracefully to an error message rather than a fatal.

= 4.2.0 =
* New: Insightistic Cloud AI  a sixth AI provider alongside OpenAI, Gemini, OpenRouter, Groq and Anthropic Claude. No API key to paste: it runs on our self-hosted infrastructure (Ollama models for general analysis, plus a Hermes SEO skill agent for organic-growth-tuned insights) and is proxied through your already-connected free Insightistic account. Free to use with a fair-use limit per period; select the engine (Balanced / SEO Specialist) in Settings → AI Insights.
* This is an additional engine inside the existing AI Insights flow, so it uses the same account gate already documented for AI Insights and email automation  no new gate, no change to what's free.
* New: 404 & Broken Link Monitor  a free, on-by-default, purely server-side check (no script) that records which URLs on your site 404, how often, and which external domains are referring visitors to them. View and clear the log from the Dashboard Overview tab; toggle it off in Settings → Engagement.
* UI: the AI Insights panel's score now renders as an animated ring gauge (reusing the same component as the Speed Test page) instead of a plain number, and shows which engine produced the result when using Insightistic Cloud AI.
* New: Cloudflare Traffic Insights  Phase 0. A new Settings → Cloudflare tab stores a Zone ID and a read-only API token (encrypted, same as every other credential) and verifies the connection. Free for everyone, no account required. This lays the foundation for a deep, non-Google traffic dashboard (bot traffic, cache ratio, threats blocked, edge-level country data) landing in a follow-up release  see docs/APP-CONNECT-WORKFLOW.md for the full phased plan.

= 4.1.0 =
* Change: Insightistic is now fully free. WooCommerce Intelligence, SEO Opportunity Finder, Anomaly Alerts, and Content Lab no longer require a license or account — every module works immediately. A free Insightistic account (no card, free forever) is only needed for two flows: AI Insights and Email Automation delivery.
* Fix: the Addons page no longer shows "[object Object]" when a locked add-on fails to enable — the account-required card now renders properly, both after a failed toggle and pre-rendered on page load.
* Fix: the Settings page "How to Set Up" instructions no longer collapse into a single narrow column next to an empty box — each step's grid was receiving one more item than its column definition accounted for.
* UI: Addons page redesigned — real icon glyphs instead of plain text, dynamic "Ready to use" / "Needs free account" status instead of a hardcoded Available filter, and copy rewritten across the plugin to say "Create a free account" instead of "Upgrade" or "Start a trial."
* New: connecting your free account now also syncs WooCommerce orders, products, customers, and site health to your Insightistic dashboard directly — no separate connector plugin needed. Daily automatic sync plus a "Sync now" button and last-synced timestamp on the License page. The standalone insightistic-connector plugin is still available for sites that don't run Insightistic itself (e.g. an agency dashboarding a client's store).

= 4.0.0 =
* New: Insightistic account connection. Paste one license key (Insightistic → License) to link this site to the Insightistic SaaS dashboard — automatic site registration, secure HMAC-signed communication, and cloud analytics. Basic analytics remain free forever.
* New: central feature gate. WooCommerce Intelligence Pro, SEO Opportunity Finder, Anomaly Alerts, Content Lab and Email Automations are now unlocked by your Insightistic plan (7-day free trial on signup). Sites upgrading from 3.x that already used these add-ons keep them for a 14-day grace period.
* New: Speed Test page — full Lighthouse audit for mobile and desktop with all four category scores, Core Web Vitals + lab metrics (including TTFB), top opportunities with estimated savings, diagnostics, and a new AI Agent Readiness score that grades how well your page can be read and cited by AI assistants and answer engines.
* New: step-by-step setup guides with official links embedded in the GA4, Search Console, PageSpeed and Engagement settings tabs.
* New: engagement tracker now also records form submits, site searches, video plays and content-copy events (all proxied server-side; still under 3KB).
* Reliability: license checks run once daily via WP-Cron with a 72-hour offline grace window — if the Insightistic API is unreachable the plugin keeps working from cached entitlements and never blocks wp-admin.
* UI: premium animated license and speed-test screens (respecting prefers-reduced-motion), animated score gauges and opportunity bars.

= 3.3.0 =
* Security: the GA4 Measurement Protocol api_secret is no longer exposed in front-end page source. Engagement events now post to a server-side collector (admin-ajax) that forwards them to Google with the secret kept on the server. The collector enforces a same-origin check, a per-IP rate limit, and an event allowlist.
* Security: credentials now use authenticated encryption (AES-256-CBC + HMAC-SHA256 with HKDF-derived keys). Stored secrets are no longer keyed directly on the WordPress salts, so rotating the salts no longer makes them undecryptable. Define INSIGHTISTIC_ENCRYPTION_KEY in wp-config.php for an external key. Existing values are read transparently and re-encrypted once on upgrade.
* Reliability: GA4 API calls now retry transient rate-limit/server errors with exponential backoff, handle Google quota errors gracefully (logged silently, friendly "temporarily unavailable" message), and a single failed report no longer aborts the whole dashboard.
* AI: added a cost guardrail - an in-flight lock plus a short result cache prevent a double-click or rapid re-run from triggering duplicate paid provider calls. Commerce analysis no longer mutates a global option to pass its skill profile.
* UX: restored the traffic-source icons (now Dashicons, encoding-proof) and made the attribution table revenue currency-aware (uses the WooCommerce store currency when available, filterable otherwise).
* Housekeeping: admin-only options no longer autoload on the front end; uninstall now removes AI history, key-rotation timestamps, and the encryption secret. Bumped minimum PHP to 8.0 and "Tested up to" to 6.8.
* Dev: added Composer (PHPCS / WordPress Coding Standards), an npm asset build pipeline, a standalone encryption test harness, and a GitHub Actions CI workflow.

= 3.2.2 =
* Fix: "every OpenRouter free model fails" - now correctly identified as the OpenRouter privacy gate.
* OpenRouter requires accounts to explicitly opt in to "free model providers may train on my prompts" at openrouter.ai/settings/privacy. Without that opt-in, every :free model rejects requests with "no allowed providers" / "no endpoints found" / "data policy" errors regardless of which slug is chosen. The new error handler detects this exact pattern and points the user at the privacy URL.
* Added an upfront info notice on the OpenRouter settings tab so users see the privacy-opt-in requirement BEFORE they hit the error (links to openrouter.ai/settings/privacy and openrouter.ai/models?max_price=0).
* New wrong-model-type detector: if a user pastes a re-ranker (rerank), embedding (embed), TTS, or STT model slug into the custom model field, the error now says "This is not a chat-completion model" and suggests a working instruct/chat slug instead. Catches mistakes like pasting nvidia/llama-nemotron-rerank-vl-1b-v2:free or a -embed-3-large slug.

= 3.2.1 =
* Fix: OpenRouter endpoint errors when using free models
* The old free-tier model dropdown listed slugs that OpenRouter has since deprecated (mistralai/mistral-7b-instruct:free, google/gemma-3-12b-it:free, meta-llama/llama-3.1-8b-instruct:free, qwen/qwen-2.5-7b-instruct:free, microsoft/phi-3-mini-128k-instruct:free, deepseek/deepseek-r1:free, and the default meta-llama/llama-3.3-8b-instruct:free which never existed as a free variant). Calls to these slugs returned 404 / "model not found" / "no endpoints found".
* Replaced the dropdown with current free models verified on OpenRouter: meta-llama/llama-3.3-70b-instruct:free (new default), deepseek/deepseek-chat-v3-0324:free, deepseek/deepseek-r1-0528:free, google/gemma-2-9b-it:free, mistralai/mistral-small-3.2-24b-instruct:free, mistralai/mistral-small-3.1-24b-instruct:free, qwen/qwen3-14b:free, qwen/qwen3-8b:free, meta-llama/llama-3.2-3b-instruct:free, nvidia/nemotron-nano-9b-v2:free.
* Migrated the default for fresh installs (insightistic_openrouter_model) to meta-llama/llama-3.3-70b-instruct:free.
* Added a translate_openrouter_error() layer that converts generic OpenRouter API errors into actionable messages: model-not-found errors now say which slug failed and point at openrouter.ai/models?max_price=0; 401 errors say "verify the key"; 429 errors say "rate-limited"; insufficient-credit errors say "switch to a :free model or top up". The dashboard error UI shows the actionable text instead of the opaque upstream message.
* Added an inline hint under the OpenRouter model dropdown linking to the current free-model catalogue.

= 3.2.0 =
* New: WooCommerce Intelligence Pro - advanced commerce add-on
* Adds a dedicated "Commerce" tab to the Insightistic dashboard (only shown when the WooCommerce Intelligence Pro add-on is enabled and WooCommerce is active)
* 8 KPI cards: Gross Revenue, Net Revenue, Orders, Average Order Value, Refund Rate, New Customers, Repeat Rate, Units Sold - all with period-over-period comparison
* Revenue & Orders timeline (Chart.js dual-axis line chart)
* Order Status donut (processing / completed / on-hold / refunded / etc.)
* Tables: Top Products (revenue + units + SKU links to edit screen), Top Categories, Top Customers (LTV in period), Recent Orders (with status badge + edit links), Top Countries, Payment Methods, Coupons, Refund reasons, Low Stock
* Currency-aware: all amounts use the store currency symbol from get_woocommerce_currency_symbol()
* Cached via the same 15-minute transient layer used by GA4 / GSC; "Updated X min ago - Force refresh" badge supported
* New AJAX endpoint insightistic_get_woo_data with the same nonce + force=1 contract as the other data layers
* AI Insights for Commerce: dedicated "Get Commerce AI Insights" button that calls Insightistic_AI::analyze_commerce() with a commerce-tuned system prompt and a structured payload (top products, categories, AOV, refund rate, previous period); reuses the existing provider/model selection (OpenAI, Gemini, OpenRouter, Anthropic, Groq)
* New AJAX endpoint insightistic_woo_ai_analyze
* Added "commerce_expert" AI skill profile that biases the system prompt toward AOV, repeat-purchase, product-mix risk, and promotion strategy
* Lazy-loaded: Commerce tab fetches data on first switch (same UX as Search Console and PageSpeed in 3.1.x)
* Currency, low-stock thresholds, and category names are read from the live WC objects so multi-currency / multi-vendor stores show the right numbers

= 3.1.1 =
* Hotfix: stripped UTF-8 BOM from every PHP file. On servers without output_buffering, the BOM bytes leaked before the JSON response and corrupted every AJAX call, producing the generic "Something went wrong. Please try again." error on the Overview, Search Console, and PageSpeed panels.
* Diagnostics: AJAX error messages now include the HTTP status and a snippet of the actual server response, so configuration errors, expired nonces, and PHP fatals are visible instead of being hidden behind a generic fallback.
* Hardened: nonce expiry (admin-ajax.php "-1" body or HTTP 401/403) now renders an explicit "Your session expired - hard refresh the page" message that points to the real fix.
* Hardened: dashboard, GSC, and PageSpeed AJAX calls now declare `dataType: 'json'`. jQuery error callback fires on non-JSON responses so the diagnostic kicks in instead of the success branch silently treating a string as `{success:false}`.
* Hardened: settings template function `insightistic_format_rotated()` wrapped in `function_exists()` to remove a latent redeclare risk if the settings page is rendered twice in the same request.
* Cleanup: removed the duplicate `i18n.adminEmail` localized key (already available at the top level).

= 3.1.0 =
* UX: Dashboard now shows skeleton placeholders during initial load instead of an infinite spinner
* UX: Errors during GA4 / Search Console / PageSpeed loads render a Retry button + a direct link to Settings
* UX: "Updated X min ago - Force refresh" badge added to every dashboard panel; force refresh bypasses the 15-minute cache
* UX: Search Console and PageSpeed tabs auto-load on first switch when configured (was: required a manual Load Data click)
* UX: Stat cards no longer disappear when a metric is missing - they show an em-dash placeholder so the grid no longer reflows
* UX: Settings save now preserves the active tab through the page reload (was: always reset to GA4)
* UX: Nonce expiry is now surfaced with a clear error message instead of a silent no-op
* AI: AI Insights panel shows provider, model, and approximate per-run cost up front
* AI: Added Copy, Re-run, and Save snapshot actions; the last successful insight is preserved on error
* AI: Per-provider Change key / Clear key / Test Provider controls on the AI settings tab; "Rotated X days ago" badge per key
* Email: New Preview Digest button on the Addons page (renders the live digest in a modal before sending)
* Email: Send Test Digest now warns if the form is dirty so users do not accidentally send to the previously-saved address
* Addons: Toggles now disable themselves while the AJAX call is in flight; failures show an inline error inside the card
* System Status: Each failing check now links straight to its fix; added a Recompute button
* A11y: Bumped low-contrast label colors to AA, added :focus-visible rings on tabs and stat cards, role="status" + screen-reader-text on skeletons
* Build: admin.min.js + admin.min.css regenerated against the new sources

= 3.0.0 =
* Final testing release for multiple WordPress site types
* Added: Branded customer user guide for GA4, Search Console, PageSpeed, AI Analysis, features, and add-ons
* Improved: Release positioning for the full free addon experience
* Improved: Version metadata for final launch testing

= 2.2.0 =
* Added: SEO Opportunity Finder free addon
* Added: Anomaly Alerts free addon
* Added: Content Performance Lab free addon
* Added: WooCommerce Intelligence Pro paid addon shell with live WooCommerce order/product reporting when WooCommerce is active
* Added: System Status page with release-readiness checks
* Added: Non-secret settings export/import
* Improved: Addons page now ships the launch lineup of 4 free addons and 1 paid addon
* Improved: Addon reports use cached dashboard data to protect API quotas

= 2.1.0 =
* Added: Free Email Automations addon with branded growth digest emails
* Added: Daily, weekly, and monthly email scheduling with site-timezone send time controls
* Added: Manual test digest sending from the Addons page
* Improved: Email digests now use the same full GA4 dashboard payload as the admin dashboard instead of only reading a basic cached snapshot
* Improved: Addons page now positions Email Automations as a high-value free module
* Improved: Production asset loading now prefers current minified files when available and falls back safely to source files when minified assets are stale

= 2.0.0 =
* Added: Google Search Console integration (clicks, impressions, CTR, position, queries, pages, device breakdown)
* Added: PageSpeed Insights tab with animated SVG score rings and Core Web Vitals grid (LCP, INP, CLS, FCP, TBT, Speed Index)
* Added: Optional lightweight engagement tracking script (under 2 KB) for outbound links, scroll depth, file downloads, element clicks
* Added: 4 new GA4 stat cards  Pageviews, Avg. Session Duration, Bounce Rate, New vs Returning
* Added: Traffic Channel Report card (Organic, Direct, Social, Referral, Email, Paid Search)
* Added: Top Posts card  filters GA4 for blog/post/news URL paths
* Added: Addons showcase page with category filter tabs
* Added: Tabbed dashboard (Overview / Search Console / PageSpeed)
* Added: 6-tab Settings page (GA4, Search Console, PageSpeed, Engagement, AI Insights, Docs)
* Added: Shared JWT authentication helper  one service account powers both GA4 and Search Console
* Improved: Responsive layout across all screen sizes
* Improved: Top Pages card now shows bounce rate and average time on page
* Security: All API credentials encrypted with AES-256-CBC  no plaintext storage
* Security: PageSpeed API key now encrypted at rest (migrates any legacy plaintext value on first save)
* Performance: Chart.js bundled locally  no external CDN dependency
* Performance: Minified JS and CSS served in production with SCRIPT_DEBUG fallback to source files

= 1.1.0 =
* Added: AI Insights panel  OpenAI, Gemini, OpenRouter (free models), Anthropic Claude
* Added: Source/Medium attribution table with revenue attribution
* Added: Traffic donut chart by source
* Added: 15-minute transient caching to minimise API quota usage
* Added: AES-256 encryption for all stored credentials

= 1.0.0 =
* Initial release  GA4 sessions, users, revenue, Top Countries, Top Pages, time-series chart

== Upgrade Notice ==

= 2.0.0 =
Major feature release. Adds Search Console, PageSpeed, Engagement Tracking, and 6 new dashboard cards. No breaking changes  existing GA4 credentials and settings are preserved automatically.
