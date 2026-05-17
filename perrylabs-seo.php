<?php
/**
 * Plugin Name: PerryLabs SEO + AEO
 * Plugin URI:  https://perrylabs.io
 * Description: Search Engine Optimization and Answer Engine Optimization for WordPress. Unified @graph JSON-LD, per-type sitemaps, redirects with 404→redirect workflow, AI crawler matrix, llms.txt builder, FAQ/HowTo auto-detection, speakable schema, REST + WP-CLI surface. No external dependencies, no nag screens.
 * Version:     2.4.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      PerryLabs
 * Author URI:  https://perrylabs.io
 * Text Domain: perrylabs-seo
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ──────────────────────────────────────────────────────────────────────
 * Plugin constants
 * ────────────────────────────────────────────────────────────────────── */

define( 'PL_SEO_VERSION', '2.4.0' );
define( 'PL_SEO_CODENAME', 'Signal Boost' );
define( 'PL_SEO_PLUGIN_FILE', __FILE__ );
define( 'PL_SEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PL_SEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PL_SEO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ──────────────────────────────────────────────────────────────────────
 * Class loader — flat list, no glob magic, deterministic order
 * ────────────────────────────────────────────────────────────────────── */

$plseo_classes = array(
	// Shared branding.
	'includes/branding/class-perrylabs-branding.php',

	// Core infrastructure.
	'includes/class-options.php',
	'includes/class-migrations.php',
	'includes/helpers/class-str.php',
	'includes/helpers/class-template-resolver.php',
	'includes/helpers/class-field-renderer.php',

	// Frontend SEO output.
	'includes/class-meta-tags.php',
	'includes/class-breadcrumbs.php',

	// Schema graph.
	'includes/schema/class-schema-graph.php',
	'includes/schema/class-schema-rules.php',
	'includes/schema/class-schema-extended.php',
	'includes/schema/class-schema-types.php',
	'includes/schema/class-schema-content.php',
	'includes/schema/class-schema-aeo.php',

	// Sitemap.
	'includes/class-sitemap.php',

	// Redirects + 404.
	'includes/class-redirects.php',
	'includes/class-redirects-csv.php',
	'includes/class-404-log.php',

	// Robots, AI crawlers, AEO infrastructure.
	'includes/class-robots-txt.php',
	'includes/class-ai-crawlers.php',
	'includes/class-ai-visit-log.php',
	'includes/class-indexnow.php',
	'includes/class-llms-txt.php',

	// Content analysis.
	'includes/class-content-analysis.php',

	// Internal-link graph (inbound count + orphan detection).
	'includes/class-link-graph.php',

	// Image SEO (auto-alt + slug optimization on upload).
	'includes/class-image-seo.php',

	// Core Web Vitals helpers.
	'includes/class-perf.php',

	// Consent-aware analytics installer.
	'includes/class-analytics.php',

	// Canonical-domain enforcement (HTTPS, www, trailing slash).
	'includes/class-canonical.php',

	// Reading time + optional table of contents.
	'includes/class-reading-time.php',

	// On-site search log.
	'includes/class-search-log.php',

	// Site audit (Semrush-style aggregate health report).
	'includes/class-audit.php',

	// AI fill (Claude API client used by the wp plseo ai-fill command).
	'includes/class-ai-fill.php',

	// Gutenberg sidebar panel.
	'includes/class-block-editor.php',

	// Editor block patterns for AEO content (FAQ / HowTo / Quick answer).
	'includes/class-block-patterns.php',

	// Author E-E-A-T fields on the user profile screen.
	'includes/class-user-profile.php',

	// Open Graph image generator (PHP/GD).
	'includes/class-og-image.php',

	// Admin layer.
	'includes/admin/class-admin-actions.php',
	'includes/admin/class-redirects-screen.php',
	'includes/admin/class-log404-screen.php',
	'includes/admin/class-aeo-dashboard-screen.php',
	'includes/admin/class-audit-screen.php',
	'includes/admin/class-search-log-screen.php',
	'includes/admin/class-bulk-alt-editor.php',
	'includes/admin/class-setup-wizard.php',
	'includes/admin/class-admin.php',
	'includes/admin/class-tabs.php',
	'includes/admin/class-meta-box.php',
	'includes/admin/class-bulk-editor.php',
	'includes/admin/class-admin-bar.php',
	'includes/admin/class-dashboard-widget.php',

	// REST + CLI.
	'includes/class-rest-api.php',
	'includes/class-cli.php',
);

foreach ( $plseo_classes as $plseo_relative ) {
	require_once PL_SEO_PLUGIN_DIR . $plseo_relative;
}
unset( $plseo_classes, $plseo_relative );

/* ──────────────────────────────────────────────────────────────────────
 * Bootstrap
 * ────────────────────────────────────────────────────────────────────── */

// Keep the in-process options cache coherent when anything writes to plseo_options
// outside of our sanitize callback (REST, CLI, third-party plugin, etc.).
add_action( 'update_option_' . PLSEO_Options::OPTION_NAME, array( PLSEO_Options::class, 'flush_cache' ) );
add_action( 'add_option_'    . PLSEO_Options::OPTION_NAME, array( PLSEO_Options::class, 'flush_cache' ) );

add_action( 'plugins_loaded', function (): void {
	// Run pending migrations first — every other module depends on options shape.
	PLSEO_Migrations::run();

	// Frontend modules always boot (some emit only when ! is_admin() internally).
	PLSEO_Meta_Tags::instance()->boot();
	PLSEO_Schema_Graph::instance()->boot();
	PLSEO_Sitemap::instance()->boot();
	PLSEO_Redirects::instance()->boot();
	PLSEO_404_Log::instance()->boot();
	PLSEO_Robots_Txt::instance()->boot();
	PLSEO_AI_Crawlers::instance()->boot();
	PLSEO_AI_Visit_Log::instance()->boot();
	PLSEO_IndexNow::instance()->boot();
	PLSEO_LLMs_Txt::instance()->boot();
	PLSEO_REST_API::instance()->boot();
	PLSEO_Image_SEO::instance()->boot();
	PLSEO_Link_Graph::instance()->boot();
	PLSEO_Audit::instance()->boot();
	PLSEO_Perf::instance()->boot();
	PLSEO_Analytics::instance()->boot();
	PLSEO_Canonical::instance()->boot();
	PLSEO_Reading_Time::instance()->boot();
	PLSEO_Search_Log::instance()->boot();
	PLSEO_Block_Editor::instance()->boot();
	PLSEO_Block_Patterns::instance()->boot();
	PLSEO_User_Profile::instance()->boot();
	PLSEO_OG_Image::instance()->boot();

	// Admin-only modules.
	if ( is_admin() ) {
		PLSEO_Admin::instance()->boot();
		PLSEO_Meta_Box::instance()->boot();
		PLSEO_Bulk_Editor::instance()->boot();
		PLSEO_Dashboard_Widget::instance()->boot();
		PLSEO_Setup_Wizard::instance()->boot();
	}

	// Admin bar pill — front + admin, only for users who can edit posts.
	PLSEO_Admin_Bar::instance()->boot();

	// WP-CLI commands.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		PLSEO_CLI::register();
	}
} );

/* ──────────────────────────────────────────────────────────────────────
 * Activation / deactivation
 * ────────────────────────────────────────────────────────────────────── */

register_activation_hook( __FILE__, function (): void {
	// Tables first (some migrations write to them).
	PLSEO_Redirects::install_table();
	PLSEO_404_Log::install_table();
	PLSEO_AI_Visit_Log::install_table();
	PLSEO_Search_Log::install_table();

	// Migrate v1 → v2 if needed; seed defaults otherwise.
	PLSEO_Migrations::run();

	// Register rewrite endpoints before flushing.
	PLSEO_Sitemap::register_rewrites();
	PLSEO_IndexNow::register_rewrites();
	PLSEO_LLMs_Txt::register_rewrites();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
	flush_rewrite_rules();
} );

/* ──────────────────────────────────────────────────────────────────────
 * Plugin row action links
 * ────────────────────────────────────────────────────────────────────── */

add_filter( 'plugin_action_links_' . PL_SEO_PLUGIN_BASENAME, function ( array $links ): array {
	$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=plseo' ) ) . '">' . esc_html__( 'Settings', 'perrylabs-seo' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
} );

/* ──────────────────────────────────────────────────────────────────────
 * Public helper functions — the stable API for themes and other plugins
 * ────────────────────────────────────────────────────────────────────── */

/**
 * Get a single plugin option.
 *
 * @param string $key     Option key (no prefix).
 * @param mixed  $default Fallback when key is unset.
 * @return mixed
 */
function plseo_get_option( string $key, mixed $default = '' ): mixed {
	return PLSEO_Options::get( $key, $default );
}

/**
 * Get a single post-level SEO meta value.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key without the _plseo_ prefix.
 * @param mixed  $default Fallback when unset or empty string.
 * @return mixed
 */
function plseo_get_post_meta( int $post_id, string $key, mixed $default = '' ): mixed {
	$value = get_post_meta( $post_id, '_plseo_' . $key, true );
	return ( '' === $value || null === $value ) ? $default : $value;
}

/**
 * Render breadcrumbs as HTML (the JSON-LD copy is emitted automatically in <head>).
 *
 * Drop into a theme template: `<?php plseo_breadcrumbs(); ?>`
 *
 * @param array<string,mixed> $args Optional rendering overrides.
 */
function plseo_breadcrumbs( array $args = array() ): void {
	PLSEO_Breadcrumbs::instance()->render( $args );
}

/**
 * Register a schema-graph contributor at runtime.
 *
 * Themes/plugins can add their own nodes to the graph by passing
 * a callable that returns either an array (single node) or array<int,array> (multiple).
 *
 * @param string                          $id       Unique contributor ID.
 * @param callable(\WP_Post|null):mixed   $callback Callback receiving the queried post (or null).
 * @param int                             $priority Lower = earlier in the graph. Default 10.
 */
function plseo_register_schema_contributor( string $id, callable $callback, int $priority = 10 ): void {
	PLSEO_Schema_Graph::instance()->register_contributor( $id, $callback, $priority );
}

/**
 * Programmatically log an AI crawler visit. Mostly used by the AI Visit Log module,
 * but exposed so other plugins (analytics, security) can contribute observations.
 *
 * @param string $bot_slug  Slug from PLSEO_AI_Crawlers::CATALOG.
 * @param string $request_uri Path that was requested.
 */
function plseo_log_ai_visit( string $bot_slug, string $request_uri ): void {
	PLSEO_AI_Visit_Log::instance()->record( $bot_slug, $request_uri );
}
