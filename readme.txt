=== PerryLabs SEO + AEO ===
Contributors: perrylabs
Tags: seo, aeo, schema, sitemap, redirects, llms.txt, indexnow, ai crawlers, json-ld, e-e-a-t
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO + Answer Engine Optimization for WordPress. Unified JSON-LD @graph, per-type sitemaps, AI crawler matrix, llms.txt builder, FAQ/HowTo auto-detection. No external dependencies, no nag screens.

== Description ==

PerryLabs SEO + AEO ("Signal Boost") is built for the world where AI assistants and answer engines matter as much as classic search. Every other SEO plugin treats AEO as a bolt-on; this one treats it as first-class.

= Search engine optimization =

* **Unified JSON-LD @graph** — one connected graph (Organization, WebSite, WebPage, primary entity, Person, Breadcrumb) instead of disconnected blocks.
* **Title and meta templates** with tokens like %post_title%, %sep%, %site_name%, %current_year%.
* **Open Graph + Twitter Cards** with per-post overrides.
* **Canonical + hreflang** alternates (multi-language friendly).
* **Sitemap index** + per-type sub-sitemaps + image extensions. Disables WP core's wp-sitemap.xml.
* **Breadcrumbs** template tag + BreadcrumbList JSON-LD.
* **Redirects** — exact and PCRE regex matching, 301/302/307/308, hit counter, CSV import/export.
* **404 log** with one-click "promote to 301."
* **Robots.txt** customization with sitemap and llms.txt pointers.
* **Webmaster verification** (Google, Bing, Pinterest, Yandex, Baidu).
* **IndexNow** instant pings on publish/update.

= Answer engine optimization =

* **AI Crawler Matrix** — explicit per-bot Allow / Block / Block-training-only for 18+ known crawlers including GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, anthropic-ai, Claude-User, Claude-SearchBot, PerplexityBot, Google-Extended, Applebot-Extended, CCBot, Bytespider, Meta-ExternalAgent, DuckAssistBot.
* **AI bot visit log** — record every recognized bot UA (one row per bot/URL/day). The strongest free signal that you've been indexed by answer engines.
* **AEO dashboard** with 30-day rollup, top bots, top URLs.
* **llms.txt builder** — auto-generated markdown summary at /llms.txt with featured pages, plus full-content variant at /llms-full.txt. Cached, properly invalidated.
* **FAQ auto-detection** emits FAQPage schema from core/details blocks or question-style H2/H3 patterns.
* **HowTo auto-detection** emits HowTo schema from "Step N" headings or the first ordered list.
* **Quick-answer / TL;DR** promoted as the primary entity's abstract — AI overviews lift this verbatim.
* **Speakable schema** driven by a configurable CSS selector list.
* **E-E-A-T author schema** — Person node with bio, credentials, expertise, social sameAs.

= Editor experience =

* Per-post meta box with tabs (SEO / Social / Schema / AEO / Advanced / Analysis) + live content analysis.
* Bulk SEO editor for titles + descriptions across many posts.
* Front-end admin bar pill with the page's worst severity (pass / warn / fail) + finding list.
* Dashboard widget with 30-day AI crawler summary.
* Settings JSON export/import for portable config.

= Developer surface =

* REST API under /wp-json/plseo/v1/ (options, analyze, redirects, AI visits, IndexNow).
* WP-CLI commands: wp plseo redirects, wp plseo 404-log, wp plseo indexnow, wp plseo ai-visits, wp plseo cache, wp plseo settings.
* Schema graph contributor API — register custom JSON-LD nodes from any plugin or theme.
* Helper functions: plseo_get_option(), plseo_get_post_meta(), plseo_breadcrumbs(), plseo_register_schema_contributor(), plseo_log_ai_visit().

== Installation ==

1. Upload to /wp-content/plugins/perrylabs-seo/ (or install via WordPress plugin search).
2. Activate from Plugins → Installed Plugins.
3. Configure at "SEO + AEO" in the main admin menu.

If migrating from PerryLabs SEO v1.x, activation triggers a one-shot migration that copies options, post meta, and redirect rows into the v2 schema. v1 data is left in place in case you roll back.

== Frequently Asked Questions ==

= Is this just another SEO plugin? =

No — it leads with AEO (Answer Engine Optimization). The unified @graph, AI Crawler Matrix, AI visit log, llms.txt builder, and FAQ/HowTo auto-detection are first-class features, not afterthoughts. The classic SEO output (meta tags, sitemap, redirects, robots.txt, IndexNow) is built on the same foundation.

= How is the AI Crawler Matrix different from a robots.txt blocklist? =

"Block all AI" is too coarse: it stops you appearing in AI answers entirely, which is increasingly where users discover content. The matrix lets you allow on-demand/search bots (PerplexityBot, ChatGPT-User, OAI-SearchBot) while blocking training-only bots (GPTBot, ClaudeBot, anthropic-ai, CCBot, Google-Extended). "Block training only" makes that choice in one click.

= What does the AI visit log actually prove? =

Not citation directly — Perplexity, ChatGPT, Copilot don't ping back when they quote you. But every visit *is* an answer engine reading your page. Watching the log trend up after publishing a new piece is the strongest free signal that your AEO work is paying off.

= Will the unified @graph break my existing schema? =

If another plugin emits its own JSON-LD, the two coexist (they live in separate <script> tags). The Schema Graph Contributor API lets you register additional nodes that flow into our single @graph, so you can move custom schema in incrementally.

= Does the bulk SEO editor work with custom post types? =

Yes — every public post type appears in the post-type filter at the top of the bulk editor.

= Will activating this disable WordPress core's wp-sitemap.xml? =

Yes — having two sitemaps that disagree is worse than either. We filter `wp_sitemaps_enabled` to false and serve our own at /sitemap.xml.

= Does it support WooCommerce, ACF, custom fields? =

The schema graph builder accepts custom contributors via plseo_register_schema_contributor() — Product, FAQ from ACF, etc. There's no auto-mapping built in for WooCommerce yet (planned for 2.1).

== Changelog ==

= 2.5.0 =
Editorial polish + migration path + WooCommerce + admin debugging tools.

* Post-list SEO column on every public post-type's edit.php — at-a-glance OK / Warn / Fix badge per row, with a ★ for cornerstone posts. Memoized per request; cornerstone-sortable.
* Yoast SEO → PerryLabs SEO + RankMath → PerryLabs SEO migration tool. Detects source-side meta in the DB, shows per-source counts, runs in batched non-destructive mode by default (existing PerryLabs SEO meta is never overwritten unless you tick the box). Admin UI at SEO + AEO → Import. Maps title, description, canonical, focus keyword, social image, cornerstone, noindex, nofollow for both sources.
* WooCommerce schema module (PLSEO_WooCommerce). Active only when WC is loaded. Emits a complete Product node with offers (price, currency, availability, sale-end), aggregateRating (only when reviews exist), and the 5 most-recent reviews, plus image gallery and brand. Adds 'product' to the sitemap automatically.
* Schema test screen. Pick any post by ID → render the @graph that would emit on the front-end, with a node-summary table, the full JSON-LD pretty-printed, and a one-click validator.schema.org link. Replaces the "view-source then squint" workflow.
* Robots.txt + endpoint preview screen. Shows the live filtered robots.txt and a copy-friendly list of every public AEO/SEO endpoint URL (sitemap variants, llms.txt, IndexNow key file, REST namespace).
* Hreflang management UI replaces the textarea with a row-based language|URL table, kept in sync with a hidden field so the existing save handler doesn't change.
* Expanded REST API: /audit, /search-log, /schema/{id}, /llms-txt — manage_options gated. Enables external dashboards and CI checks against the plugin's state.

= 2.4.0 =
Editor + onboarding polish round:

* First-activation setup wizard: two-step guided flow covering business identity (name, type, logo, social) and AEO posture (allow / block training only / block all AI). Admin-notice nudge until completed; skippable. Accessible at any time via wp-admin/admin.php?page=plseo-setup.
* Author E-E-A-T user-profile section: Users → Edit gets a "Author E-E-A-T (PerryLabs SEO)" block with three fields — sameAs URLs, credentials, expertise. Wires directly into the Person schema node the @graph already emits for post authors.
* Block patterns for AEO content: "FAQ section", "Step-by-step how-to", and "Quick answer / TL;DR" patterns registered under a "PerryLabs SEO" category in the inserter. Authors insert → edit in place → FAQPage / HowTo / Speakable schema auto-emits.
* SERP preview viewport toggle: Desktop / Mobile switch above the snippet preview tab. Mobile mode constrains the Google card to ~380px and clamps the title to 2 lines + description to 3 lines, approximating actual mobile-SERP truncation.

= 2.3.0 =
Polish pass + scale-out: the AI-fill CLI, Gutenberg sidebar, OG card generator, schema-rules visual UI, and the long-tail bug fixes from the v2.2 review.

* Gutenberg sidebar panel (PluginSidebar): SEO title, description, canonical, focus keyword(s), quick answer, schema-type override, social image, cornerstone, noindex, nofollow. Saves via core/editor entity-prop pipeline (no separate save button). Live character counters with good / warn / bad bands.
* Schema display rules: replaced raw JSON textarea with a row-based UI — post_type + taxonomy + term_slug selects per rule, comma-separated emit input with autocomplete from the supported-types datalist. Sanitizer round-trips to the same JSON format the engine reads.
* wp plseo ai-fill: Claude API (claude-haiku-4-5) command for bulk SEO meta generation. Skips posts that already have _plseo_title (resumable), supports --post-type / --limit / --overwrite / --dry-run / --sleep. API key from PLSEO_ANTHROPIC_API_KEY constant, ANTHROPIC_API_KEY env, or plseo_options[ai_fill_api_key].
* wp plseo links rebuild: backfills the internal-link graph for sites that activated v2 after content was already written (link graph normally only populates on save_post).
* wp plseo audit run / counts / bust: CLI access to the site-audit engine.
* OG image generator (PHP/GD): for posts with no featured image, no per-post override, and no default social image, renders a 1200×630 brand card with the post title. Cached per-(post-id, title-hash). Falls back to no-op when GD is unavailable.
* Link-graph LIKE-pattern bug fix: storage format changed from PHP-serialized array to comma-bracketed string (`,5,7,12,`). The old `%i:5;%` pattern matched serialized-array indices, double-counting inbound links for posts with 6+ outbound. New `%,5,%` only matches values.
* Audit silent 500-cap: now reports "Scanned X of Y eligible post(s)" with an inline notice when capped, instead of hiding the cap behind a per-batch number.
* Branded admin chrome on every screen: PerryLabs_Branding header + footer now wraps the settings page, Redirects, 404 log, AEO dashboard, Site audit, Search log, and Bulk image-alt screens.

= 2.2.0 =
"Best SEO things in the world" pass — closes the remaining feature gap against the paid players:

* Core Web Vitals helpers: dns-prefetch + preconnect for external hosts, lazy-load + decoding="async" auto-attribute, fetchpriority="high" on the first <img> of singular content (LCP boost), auto-fill width/height from attachment metadata (CLS fix).
* Cookie-aware analytics installer: GA4, GTM, Plausible, Fathom, Microsoft Clarity. If PerryLabs Cookie Notice is active, each tag registers via plcn_register_script() so it's consent-gated automatically. Otherwise injects directly.
* Canonical-domain enforcement: force HTTPS, force www / strip www, force trailing slash / strip trailing slash. Single 301 before render.
* Wildcard redirects: /old/* → /new/$1 syntax, on top of the existing exact + regex modes.
* 9 new schema.org types via the rules engine: Product (with Offer + AggregateRating), Review, Recipe, JobPosting, Course, SoftwareApplication, Book, ClaimReview, QAPage.
* Reading time computed at 230 wpm and exposed as timeRequired on the primary @graph entity. Optional auto table of contents inserted before the first H2 when a post has 3+ H2 headings.
* On-site search log: captures every is_search() query (dedupe by query/day), surfaces top queries AND a zero-result list (content opportunity goldmine). Bot UAs filtered out. Configurable retention.
* Bulk image alt editor: dedicated screen for fixing missing alt across many attachments at once. Defaults to "missing only" filter.
* Three more webmaster verifications: Apple News, Cloudflare, Norton Safe Web.
* Mastodon profile verification via <link rel="me"> from the configured Mastodon URL.
* Two new settings tabs: Analytics + Performance. Canonical-domain rules added to Advanced. Verifications expanded on General.

= 2.1.0 =
Feature pass to close gaps against Semrush + RankMath Pro:

* Site audit screen — Semrush-style aggregate health report with errors / warnings / notices, "Fix" links into post editor.
* Schema display rules — first-match engine, per-post-type + per-taxonomy term, emit multiple schema types per post.
* Live SERP / X / Facebook snippet preview in the meta box that updates as you type.
* News sitemap (/sitemap-news.xml) — Google-News-spec, posts from the last 48h.
* Video sitemap (/sitemap-videos.xml) — auto-detects YouTube, Vimeo, native <video>.
* Cornerstone content marker — bumps sitemap priority to 1.0 and auto-includes in /llms.txt featured.
* Multiple focus keywords — comma-separated, each scored independently against title, URL, headings, density.
* Flesch-Kincaid readability score in the per-post analyzer.
* Internal-link graph — outbound links cached per post on save; inbound count and orphan detection in the analyzer and audit.
* Image SEO module — auto-alt fallback at render time, optional upload-slug optimization (DSC_4523.jpg → founders-portrait.jpg).
* Refactor: PLSEO_Str helper consolidates 5 duplicated clip()/language()/normalize() implementations. class-admin.php split into 5 focused files (menu router + actions + 3 screen renderers). No file in the codebase exceeds ~550 LOC.

= 2.0.0 =
Major rewrite. Codename "Signal Boost." Everything new:

* Unified JSON-LD @graph builder + contributor API.
* AI Crawler Matrix with 18+ bots and three-state policy (allow / block / block training only).
* AI visit log + AEO dashboard + dashboard widget.
* llms.txt builder with featured pages and full-content variant.
* FAQ / HowTo / Speakable / Quick-answer auto-detection.
* E-E-A-T Person schema with credentials, expertise, sameAs from user meta.
* Sitemap index + per-type sub-sitemaps + image sitemap extensions.
* Bulk SEO editor screen.
* Front-end admin bar status pill with content analysis findings.
* 404 → 301 one-click promotion workflow.
* CSV import/export and regex redirects.
* REST API (/wp-json/plseo/v1/) and WP-CLI commands.
* Settings JSON export/import.
* Hreflang multi-language support.
* Migration from v1.x — options, post meta, redirect tables.
* Smaller, more maintainable code: every class under ~700 LOC, no monolithic settings class.

= 1.2.0 (legacy) =
Last v1 release. Preserved on the `v1-archive` git branch.

== Upgrade Notice ==

= 2.5.0 =
Post-list SEO column, Yoast / RankMath migration tool, WooCommerce Product schema, schema-test debug screen, robots.txt preview, expanded REST API. Drop-in.

= 2.4.0 =
Adds first-activation setup wizard, author E-E-A-T profile fields, AEO-ready block patterns (FAQ / HowTo / Quick answer), SERP preview viewport toggle.

= 2.3.0 =
Adds Gutenberg sidebar, visual schema-rules UI, wp plseo ai-fill / links rebuild / audit CLI commands, OG image generator. Fixes link-graph LIKE false-positives. Drop-in upgrade.

= 2.2.0 =
Adds Core Web Vitals helpers, consent-aware analytics installer, canonical-domain enforcement, wildcard redirects, 9 more schema types, reading time + auto TOC, on-site search log, bulk image alt editor.

= 2.1.0 =
Adds site audit, schema display rules, live SERP preview, news + video sitemaps, multi-keyword analysis, cornerstone marker, image SEO, internal-link graph. No data migration needed.

= 2.0.0 =
Major rewrite. Activation migrates v1 data automatically. AEO features are new — visit the AEO tab after upgrading.
