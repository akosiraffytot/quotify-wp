=== Quotify ===
Contributors: rafaelmendoza
Tags: sitemap, page count, pricing, quote, estimate
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Counts pages from any website's XML sitemap and returns a tiered price with a checkout link.

== Description ==

Quotify gives your visitors a live tool: they type a website URL, hit the button, and instantly get a page count and a price — no page reload, no account needed.

Behind the scenes Quotify discovers the site's XML sitemap (robots.txt `Sitemap:` entries first, then common paths), streams the count with a performance-safe cap, matches it to your pricing tiers, and links to your checkout.

* **Configurable pricing** — Tools > Quotify: add as many tiers as you want (last tier open-ended), each with its own optional checkout URL, plus a global checkout URL template with `{page_count}`, `{total_price}` and `{site}` tokens ($ fixed, formatted for your locale).
* **Performance limits** — fetch timeout, response size cap, depth and sub-sitemap caps, per-site 1-hour cache and a 5,001-page early stop.
* **Place results anywhere** — choose which results show inline, or drop the count, price and "Get a quote" link into separate spots on the page with dedicated shortcodes.
* **Safe by default** — SSRF guard blocks private/loopback addresses, per-IP request throttling, nonce-protected requests.

== Usage ==

Add the form to any page or post with:

`[quotify]`

Customize the form (all attributes optional):

`[quotify button="Let's Go" quote_label="Start Now" show_pages="0" show_price="0" show_quote="0"]`

* `button` — text of the submit button (default: Estimate).
* `quote_label` — text of the checkout link (default: Get a Quote).
* `show_pages` / `show_price` / `show_quote` — set to `0` to hide that result inline. Error messages always show.
* `mode` — `input` (default) lets visitors type any website; `user` prefills the visitor's own WordPress profile website URL and makes it read-only (falls back to a normal input when logged out or no profile URL is set).

Place any result separately anywhere on the page — for example a "You have X pages" panel:

`[quotify_count]`
`[quotify_price]`
`[quotify_quote label="Buy Now"]`

Each accepts a `placeholder` attribute (e.g. `[quotify_count placeholder="--"]`). Results fill in live when the visitor runs an estimate; other spots on the page fill with the same values.

Sites with no discoverable sitemap, or with more than 5,000 pages, fall back to a clear message or a "Contact us" label instead of a price — letting you sell a custom quote.

== Installation ==

1. Upload the `quotify` folder to `/wp-content/plugins/`, or install the `quotify.zip` you download from GitHub.
2. Activate the plugin through the Plugins screen.
3. Go to **Tools > Quotify**, enter your pricing tiers and checkout URL template, and save.
4. Add the `[quotify]` shortcode (and the field shortcodes above) to the pages where you want the tool.

The plugin self-updates: each new release is offered in the Plugins screen and installs in one click.

== Frequently Asked Questions ==

= Why does my site's count include more pages than other tools report? =

Quotify counts every sitemap listed in the target's robots.txt — so on a multilingual site it totals all locale sitemaps, not just the default one. That is intentional.

= The price shows "Contact us for a custom quote." — why? =

Page counts above your last tier are matched to the open-ended bracket. When that bracket has no fixed price, Quotify shows the contact-us label and leaves the quote link off. Define a price on the last tier in Tools > Quotify to always return a checkout link.

= Do visitors need an account or login? =

No. The tool is fully public and runs entirely over AJAX, with security checks built in.

== Changelog ==

= 1.2.0 =
* New `mode` attribute for `[quotify]`: `user` prefills the visitor's WordPress profile website and makes it read-only (falls back to a normal input when logged out or no profile URL is set).

= 1.1.0 =
* Per-tier checkout URLs: give any pricing tier its own optional checkout link, falling back to the global template when blank.
* New `{site}` token in checkout URL templates — replaced with this website's URL (where the plugin is installed).

= 1.0.8 =
* Auto-clean expired transients from the options table on every count, keeping the database lean without deactivation or cron reliance.

= 1.0.7 =
* New `[quotify_count]`, `[quotify_price]` and `[quotify_quote]` shortcodes to place results anywhere on the page; filled live by the estimate.
* Shortcode attributes for the form: `button`, `quote_label`, and `show_pages`/`show_price`/`show_quote` to hide inline results.

= 1.0.6 =
* Visible animated spinner while an estimate runs.
* Admin note clarifying that counting totals every sitemap listed in robots.txt (e.g. all locales).

= 1.0.5 =
* Live estimate frontend: full-AJAX (no page reload), friendly inline errors.
* SSRF guard on submitted URLs (blocks private/loopback addresses).
* Per-IP request throttle (10 per minute).

= 1.0.4 =
* Fix: page-count fetch returning empty bodies on WordPress 7.1.
* Sitemap-index recursion with configurable depth and sub-sitemap caps.

= 1.0.3 =
* HTTP fetch layer: robots.txt discovery with fallback paths, per-site 1-hour cache, stampede lock, configurable performance limits.

= 1.0.2 =
* Streaming XML sitemap parser (XMLReader) with 5,001-page early stop.

= 1.0.1 =
* GitHub-hosted plugin updates via Plugin Update Checker; automatic zip release builds.