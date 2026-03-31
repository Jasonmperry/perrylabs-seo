<?php
/**
 * Plugin Name: PerryLabs SEO + AEO
 * Plugin URI:  https://perrylabs.io
 * Description: Lightweight, no-bloat SEO & Answer Engine Optimization plugin. Meta tags, Open Graph, Twitter Cards, XML sitemap, JSON-LD structured data, redirects, IndexNow, llms.txt, and breadcrumbs — without the nag screens.
 * Version:     1.2.0
 * Author:      PerryLabs
 * Author URI:  https://perrylabs.io
 * Text Domain: perrylabs-seo
 * Domain Path: /languages
 * Requires PHP: 8.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ──────────────────────────────────────────────────────────────────────
 * Constants
 * ────────────────────────────────────────────────────────────────────── */

define( 'PL_SEO_VERSION', '1.2.0' );
define( 'PL_SEO_CODENAME', 'Beacon' );
define( 'PL_SEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PL_SEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PL_SEO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* ──────────────────────────────────────────────────────────────────────
 * Autoload includes
 * ────────────────────────────────────────────────────────────────────── */

require_once PL_SEO_PLUGIN_DIR . 'includes/class-settings.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-meta-box.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-meta-tags.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-sitemap.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-schema.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-breadcrumbs.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-redirects.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-robots-txt.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-indexnow.php';
require_once PL_SEO_PLUGIN_DIR . 'includes/class-llms-txt.php';

/* ──────────────────────────────────────────────────────────────────────
 * Initialization
 * ────────────────────────────────────────────────────────────────────── */

add_action( 'plugins_loaded', function () {
	// Redirects (admin + frontend).
	$redirects = new PerryLabs_SEO_Redirects();

	// Robots.txt viewer (admin-only rendering, no menu).
	$robots = new PerryLabs_SEO_Robots_Txt();

	// Admin-only components.
	if ( is_admin() ) {
		new PerryLabs_SEO_Settings( $redirects, $robots );
		new PerryLabs_SEO_Meta_Box();
	}

	// Frontend components.
	new PerryLabs_SEO_Meta_Tags();
	new PerryLabs_SEO_Sitemap();
	new PerryLabs_SEO_Schema();

	// IndexNow instant-indexing pings.
	new PerryLabs_SEO_IndexNow();

	// llms.txt / llms-full.txt endpoints.
	new PerryLabs_SEO_Llms_Txt();
} );

/* ──────────────────────────────────────────────────────────────────────
 * Activation — flush rewrite rules for sitemap endpoint
 * ────────────────────────────────────────────────────────────────────── */

register_activation_hook( __FILE__, function () {
	// Register rewrite rules before flushing.
	PerryLabs_SEO_Sitemap::register_rewrite_rules();
	PerryLabs_SEO_IndexNow::register_rewrite_rules();
	PerryLabs_SEO_Llms_Txt::register_rewrite_rules();
	flush_rewrite_rules();

	// Create redirect & 404 log tables.
	PerryLabs_SEO_Redirects::install_tables();
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/* ──────────────────────────────────────────────────────────────────────
 * Helper: Get plugin option with fallback
 * ────────────────────────────────────────────────────────────────────── */

/**
 * Retrieve an SEO + AEO option.
 *
 * @param string $key     Option key (without prefix).
 * @param mixed  $default Default value.
 * @return mixed
 */
function perrylabs_seo_get_option( string $key, mixed $default = '' ): mixed {
	$options = get_option( 'perrylabs_seo_options', array() );
	return $options[ $key ] ?? $default;
}

/* ──────────────────────────────────────────────────────────────────────
 * Template tag: Breadcrumbs
 * ────────────────────────────────────────────────────────────────────── */

/**
 * Output semantic breadcrumbs with JSON-LD.
 *
 * Usage: <?php perrylabs_seo_breadcrumbs(); ?>
 *
 * @param array $args Optional. Configuration arguments.
 */
function perrylabs_seo_breadcrumbs( array $args = array() ): void {
	PerryLabs_SEO_Breadcrumbs::render( $args );
}
