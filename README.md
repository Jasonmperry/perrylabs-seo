# PerryLabs SEO + AEO

Search Engine Optimization **and** Answer Engine Optimization for WordPress. Unified JSON-LD `@graph`, per-type + news + video sitemaps, redirects with a 404→redirect workflow, AI crawler matrix, llms.txt builder, FAQ/HowTo auto-detection, speakable schema, site audit, schema display rules, live SERP/Twitter/Facebook preview, internal-link graph with orphan detection, image SEO, multi-focus-keyword analysis, Flesch-Kincaid readability, REST + WP-CLI surface. Zero external dependencies. No nag screens. GPL-2.0-or-later.

Internal codename: **Signal Boost**.

## Why another SEO plugin

The big incumbents (Yoast, RankMath, AIOSEO) cover SEO well but ship with upsell wizards, account walls, and 50+ files of branding. None of them is built for AEO first — they bolt llms.txt and AI-bot blocking on top of legacy code. This plugin starts the other way around: **what does an LLM need from your site, and how do you give it that without paying a SaaS** — then layers the classic SEO output on top.

## What's in the box

### Search engine optimization

- **Unified JSON-LD `@graph`** — one connected graph (Organization → WebSite → WebPage → primary entity → Person → Breadcrumb) instead of disconnected blocks. Crawlers and AI overviews resolve entity references reliably.
- **Title and meta templates** with tokens (`%post_title%`, `%sep%`, `%site_name%`, `%current_year%`, etc.).
- **Open Graph + Twitter Cards** with per-post overrides for title, description, image.
- **Canonical + hreflang** alternates (multi-language).
- **Sitemap index + per-type sub-sitemaps** with image extensions. Disables WP core's wp-sitemap.xml to avoid conflict.
- **Breadcrumbs** template tag `plseo_breadcrumbs()` and JSON-LD `BreadcrumbList`.
- **Redirects** — exact and PCRE regex matching, 301/302/307/308, hit counter, CSV import/export.
- **404 log** with one-click "promote to 301" workflow.
- **Robots.txt** customization with automatic sitemap and llms.txt pointers.
- **Webmaster verification** for Google, Bing, Pinterest, Yandex, Baidu.
- **IndexNow** instant pings to Bing and Yandex on publish/update.

### Answer engine optimization (the gap most plugins skip)

- **AI Crawler Matrix** — explicit per-bot Allow / Block / Block-training-only for 18+ known crawlers (GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, anthropic-ai, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Bytespider, Meta-ExternalAgent, DuckAssistBot, YouBot, Amazonbot, cohere-ai). Drives robots.txt automatically.
- **AI bot visit log** — every recognized bot UA gets recorded (one row per bot/URL/day) so you can see *who is reading you*, when, and which pages they prefer. The closest free signal to "you got cited."
- **AEO dashboard** with 30-day rollup, top bots, top URLs.
- **llms.txt builder** — auto-generated markdown summary at `/llms.txt` with featured pages, per-post-type sections, and a full-content variant at `/llms-full.txt`. Cached with proper invalidation on post save.
- **FAQ auto-detection** — emits `FAQPage` schema from `core/details` blocks or `?`-ending H2/H3 patterns.
- **HowTo auto-detection** — emits `HowTo` schema from `Step N:` headings or the first `<ol>`.
- **Quick-answer / TL;DR** — promoted as the primary entity's `abstract`; this is what AI overviews lift verbatim.
- **Speakable schema** — driven by a configurable list of CSS selectors.
- **E-E-A-T author schema** — `Person` node with bio, credentials, expertise, social `sameAs` from user meta.

### Site audit (v2.1)

Semrush-style site-wide health report grouped by severity:

- **Errors** — missing title, missing description, duplicate titles, duplicate descriptions, broken internal links, multiple H1, missing org logo.
- **Warnings** — thin content, title/description length out of band, orphan posts, images missing alt, low readability, no cornerstone posts marked.
- **Notices** — missing featured image, no focus keyword, no outbound internal links.

One-click "Fix" jumps straight to the post editor.

### Editor experience

- Per-post **meta box with tabs** (SEO / **Preview** / Social / Schema / AEO / Advanced / Analysis).
- **Live SERP / X / Facebook snippet preview** that updates as you type (v2.1).
- **Live content analysis** including Flesch-Kincaid readability and per-keyword coverage (v2.1).
- **Multiple focus keywords** — comma-separated, each scored independently (v2.1).
- **Cornerstone content marker** — flagged posts get sitemap priority 1.0 and auto-appear in `/llms.txt` featured (v2.1).
- **Bulk SEO editor** screen — title + description for many posts at once.
- **Front-end admin bar pill** with the current page's worst severity (pass / warn / fail) and the full finding list.
- **Dashboard widget** with 30-day AI crawler summary.
- **Settings JSON export/import** for moving config between sites.

### Image SEO (v2.1)

- **Auto-alt fallback** — `<img>` tags without alt text are filled from the attachment / parent title at render time.
- **Upload slug optimization** — `DSC_4523.jpg` becomes `founders-portrait.jpg` based on attachment title at upload time.

### Schema display rules (v2.1)

RankMath-Pro-style rule engine: emit one or more schema types per post based on post type and taxonomy term. Rules are evaluated in order; the first match wins. Per-post overrides on the meta box still trump everything. Falls back to the legacy `schema_type_map` if no rules match.

### Internal-link graph (v2.1)

On every post save, outbound internal links are extracted to `_plseo_internal_links` post meta. The content analyzer surfaces inbound count (orphan detection) and the site audit groups orphans + broken internal links across the site.

### Developer surface

- **REST API** under `/wp-json/plseo/v1/` (options read, analyze post, redirects CRUD, AI visit summary, IndexNow submit).
- **WP-CLI** commands: `wp plseo redirects {list,add,delete,import,export}`, `wp plseo 404-log {show,prune}`, `wp plseo indexnow {key,submit}`, `wp plseo ai-visits report`, `wp plseo cache flush`, `wp plseo settings export`.
- **Schema graph contributor API** — register custom JSON-LD nodes from any plugin or theme.
- **Helper functions**: `plseo_get_option()`, `plseo_get_post_meta()`, `plseo_breadcrumbs()`, `plseo_register_schema_contributor()`, `plseo_log_ai_visit()`.
- **Filters and actions** documented inline in each module; all hooks prefixed `plseo_`.

## Requirements

- WordPress 6.0+
- PHP 8.1+ (uses enums-friendly syntax, readonly considerations, `match`, nullsafe, etc.)

## Installation

### As a Git submodule (recommended for managed sites)

```bash
cd your-wp-project/
git submodule add https://github.com/Jasonmperry/perrylabs-seo.git wp-content/plugins/perrylabs-seo
git commit -m "Add PerryLabs SEO + AEO as submodule"
```

### Manual

1. Clone or download into `wp-content/plugins/perrylabs-seo/`.
2. Activate from the WordPress admin.
3. Configure at **SEO + AEO** in the admin menu.

## Migrating from v1

If the site previously ran `perrylabs-seo` v1.x, activation triggers a one-shot migration:

- `perrylabs_seo_options` → `plseo_options` (with key mapping)
- `_perrylabs_seo_*` post meta → `_plseo_*` (single SQL rename)
- `wp_perrylabs_seo_redirects` rows → `wp_plseo_redirects`
- `wp_perrylabs_seo_404_log` rows → `wp_plseo_404_log`
- v1's "block AI crawlers" toggle → AI Crawler Matrix with every known bot set to **block**.

The v1 tables and option rows are left in place (in case you ever roll back); `uninstall.php` only wipes v2 data.

## Developer examples

### Conditionally render breadcrumbs in a theme template

```php
<?php if ( function_exists( 'plseo_breadcrumbs' ) ) { plseo_breadcrumbs(); } ?>
```

### Add a custom node to the schema @graph

```php
add_action( 'plugins_loaded', function (): void {
    plseo_register_schema_contributor( 'my_book_schema', function ( $post ) {
        if ( ! $post || 'book' !== $post->post_type ) {
            return null;
        }
        return array(
            '@type'  => 'Book',
            '@id'    => get_permalink( $post ) . '#book',
            'name'   => get_the_title( $post ),
            'author' => array( '@id' => trailingslashit( home_url() ) . '#person-' . $post->post_author ),
        );
    } );
} );
```

### Filter the AI bot catalogue at runtime

```php
add_filter( 'plseo_schema_graph', function ( array $graph, $post ): array {
    // Inspect / mutate the final graph before output.
    return $graph;
}, 10, 2 );
```

### Call the REST API from a JS dashboard

```bash
curl -u editor:apppass https://example.com/wp-json/plseo/v1/ai-visits?days=30
```

## Public endpoints

| Endpoint | What it serves |
|----------|----------------|
| `/sitemap.xml` | Sitemap index |
| `/sitemap-{type}.xml` | One post type per file (paginated 500/page) |
| `/sitemap-tax-{taxonomy}.xml` | All terms in one taxonomy |
| `/llms.txt` | Markdown summary for AI assistants |
| `/llms-full.txt` | Full content concatenation for AI assistants |
| `/robots.txt` | Sitemap + AI crawler rules + custom directives |
| `/{32-hex-key}.txt` | IndexNow verification file |

## Standards inherited from the Cookie Monster plugin

The architectural conventions are shared with **PerryLabs Cookie Notice**:

- `PLSEO_*` class prefix, `PL_SEO_*` constants, `plseo_*()` helper functions, `_plseo_*` post meta keys.
- Single `plseo_options` row for all settings; per-key sanitizers registered through `PLSEO_Options::register_sanitizer()`.
- Singleton `::instance()` for stateful modules; plain instantiation elsewhere.
- Custom tables installed in the activation hook (`{prefix}plseo_redirects`, `{prefix}plseo_404_log`, `{prefix}plseo_ai_visits`).
- `uninstall.php` wipes options, transients, post meta, and tables on plugin delete.
- README.md (developer-facing) plus WP.org-style `readme.txt`.
- Two-word internal codename.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) or the [GNU site](https://www.gnu.org/licenses/gpl-2.0.html) for the full text.
