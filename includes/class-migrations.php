<?php
/**
 * PLSEO_Migrations — schema/options versioning and v1 → v2 import.
 *
 * Runs on activation and on every plugins_loaded as a no-op fast path.
 * Each numbered migration runs at most once. The current schema number lives
 * in PLSEO_Options defaults under `_schema_version`.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Migrations {

	private const TARGET_VERSION = 3;
	private const VERSION_KEY    = 'plseo_db_version';

	/**
	 * Run any pending migrations. Idempotent.
	 */
	public static function run(): void {
		$installed = (int) get_option( self::VERSION_KEY, 0 );

		if ( $installed >= self::TARGET_VERSION ) {
			return;
		}

		// Tables must exist before migration writes anything. activate hook installs them,
		// but file-replace upgrades (uploading new files without deactivate→activate)
		// skip the hook. dbDelta is idempotent so this is safe to call every cold start.
		PLSEO_Redirects::install_table();
		PLSEO_404_Log::install_table();
		PLSEO_AI_Visit_Log::install_table();
		PLSEO_Search_Log::install_table();

		if ( $installed < 1 ) {
			self::fresh_install_or_v1_import();
		}

		if ( $installed < 2 ) {
			self::ensure_internal_keys();
		}

		if ( $installed < 3 ) {
			self::import_v1_apollo_constant();
		}

		update_option( self::VERSION_KEY, self::TARGET_VERSION );
	}

	/**
	 * v1 shipped a class-tracking.php that hard-coded an Apollo App ID with
	 * an optional PERRYLABS_APOLLO_APP_ID constant override. v2 exposes this
	 * as a regular option. If a site has the constant defined, import it.
	 * If not, leave the option empty so other sites don't accidentally inherit
	 * the original deployment's App ID.
	 */
	private static function import_v1_apollo_constant(): void {
		if ( ! defined( 'PERRYLABS_APOLLO_APP_ID' ) ) {
			return;
		}
		$id = (string) constant( 'PERRYLABS_APOLLO_APP_ID' );
		if ( '' === $id ) {
			return;
		}
		$opts = get_option( PLSEO_Options::OPTION_NAME, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		if ( empty( $opts['analytics_apollo_app_id'] ) ) {
			$opts['analytics_apollo_app_id'] = $id;
			update_option( PLSEO_Options::OPTION_NAME, $opts );
		}
	}

	/**
	 * On a fresh install: seed defaults.
	 * On a site that previously ran perrylabs-seo v1: copy v1 options across.
	 */
	private static function fresh_install_or_v1_import(): void {
		$v1 = get_option( 'perrylabs_seo_options', null );
		$defaults = PLSEO_Options::defaults();

		if ( ! is_array( $v1 ) ) {
			// Fresh install.
			$defaults['_first_install_at'] = time();
			update_option( PLSEO_Options::OPTION_NAME, $defaults );
			return;
		}

		// v1 → v2 key remapping. v1 keys that still exist verbatim are picked up by array_merge below.
		$mapped = array(
			'title_separator'        => $v1['title_separator']        ?? $defaults['title_separator'],
			'default_description'    => $v1['default_description']    ?? $defaults['default_description'],
			'google_verification'    => $v1['google_verification']    ?? '',
			'bing_verification'      => $v1['bing_verification']      ?? '',
			'pinterest_verification' => $v1['pinterest_verification'] ?? '',
			'yandex_verification'    => $v1['yandex_verification']    ?? '',
			'twitter_handle'         => $v1['twitter_handle']         ?? '',
			'facebook_url'           => $v1['facebook_url']           ?? '',
			'linkedin_url'           => $v1['linkedin_url']           ?? '',
			'default_social_image'   => $v1['default_social_image']   ?? '',
			'sitemap_post_types'     => $v1['sitemap_post_types']     ?? $defaults['sitemap_post_types'],
			'sitemap_taxonomies'     => $v1['sitemap_taxonomies']     ?? $defaults['sitemap_taxonomies'],
			'business_name'           => $v1['business_name']           ?? '',
			'business_type'           => $v1['business_type']           ?: $defaults['business_type'],
			'business_street_address' => $v1['business_street_address'] ?? '',
			'business_city'           => $v1['business_city']           ?? '',
			'business_state'          => $v1['business_state']          ?? '',
			'business_postal_code'    => $v1['business_postal_code']    ?? '',
			'business_country'        => $v1['business_country']        ?? '',
			'business_phone'          => $v1['business_phone']          ?? '',
			'business_email'          => $v1['business_email']          ?? '',
			'indexnow_enabled'        => ! empty( $v1['indexnow_enabled'] ),
			'llms_txt_enabled'        => isset( $v1['llms_txt_enabled'] ) ? (bool) $v1['llms_txt_enabled'] : $defaults['llms_txt_enabled'],
			'robots_txt_custom'       => $v1['robots_txt_custom']       ?? '',
			'noindex_archives'        => ! empty( $v1['noindex_archives'] ),
			'noindex_categories'      => ! empty( $v1['noindex_categories'] ),
			'noindex_tags'            => ! empty( $v1['noindex_tags'] ),
			'noindex_authors'         => isset( $v1['noindex_authors'] ) ? (bool) $v1['noindex_authors'] : $defaults['noindex_authors'],

			// v1's `robots_block_ai_crawlers` collapses into the AI Crawler matrix.
			// If the user had it on, default every known AI bot to "block".
			// PLSEO_AI_Crawlers reads this on first boot.
			'_v1_blocked_ai_crawlers' => ! empty( $v1['robots_block_ai_crawlers'] ),
		);

		$merged = array_merge( $defaults, $mapped );
		$merged['_first_install_at'] = $merged['_first_install_at'] ?: time();

		update_option( PLSEO_Options::OPTION_NAME, $merged );

		// Migrate post meta: v1 used `_perrylabs_seo_*`, v2 uses `_plseo_*`.
		self::migrate_v1_post_meta();

		// Preserve v1 redirect/404 tables by name? No — v2 uses its own (plseo_*). We migrate rows.
		self::migrate_v1_redirects();
	}

	/**
	 * Rename per-post meta keys from v1 → v2 form using a single SQL pass.
	 */
	private static function migrate_v1_post_meta(): void {
		global $wpdb;

		$map = array(
			'_perrylabs_seo_title'          => '_plseo_title',
			'_perrylabs_seo_description'    => '_plseo_description',
			'_perrylabs_seo_canonical'      => '_plseo_canonical',
			'_perrylabs_seo_noindex'        => '_plseo_noindex',
			'_perrylabs_seo_nofollow'       => '_plseo_nofollow',
			'_perrylabs_seo_social_image'   => '_plseo_social_image',
			'_perrylabs_seo_schema_type'    => '_plseo_schema_type',
			'_perrylabs_seo_alternate_urls' => '_plseo_hreflang',
		);

		foreach ( $map as $old => $new ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
				$new,
				$old
			) );
		}
	}

	/**
	 * Copy rows from v1 redirect tables into v2 tables, if both exist.
	 */
	private static function migrate_v1_redirects(): void {
		global $wpdb;

		$old_redirects = $wpdb->prefix . 'perrylabs_seo_redirects';
		$new_redirects = $wpdb->prefix . 'plseo_redirects';
		$old_log       = $wpdb->prefix . 'perrylabs_seo_404_log';
		$new_log       = $wpdb->prefix . 'plseo_404_log';

		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_redirects ) ); // phpcs:ignore WordPress.DB
		$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_redirects ) ); // phpcs:ignore WordPress.DB
		if ( $old_exists && $new_exists ) {
			$wpdb->query( "INSERT IGNORE INTO {$new_redirects} (source_url, target_url, status_code, hits, last_hit, created_at, notes, match_type) SELECT source_url, target_url, status_code, hits, last_hit, created_at, notes, 'exact' FROM {$old_redirects}" ); // phpcs:ignore WordPress.DB
		}

		$old_log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_log ) ); // phpcs:ignore WordPress.DB
		$new_log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_log ) ); // phpcs:ignore WordPress.DB
		if ( $old_log_exists && $new_log_exists ) {
			$wpdb->query( "INSERT IGNORE INTO {$new_log} (url, referrer, hits, last_hit, created_at) SELECT url, referrer, hits, last_hit, created_at FROM {$old_log}" ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Migration 2 — ensure internal keys (_first_install_at, _schema_version) exist
	 * for sites that did the v1 import before this key set was finalized.
	 */
	private static function ensure_internal_keys(): void {
		$opts = get_option( PLSEO_Options::OPTION_NAME, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		if ( empty( $opts['_first_install_at'] ) ) {
			$opts['_first_install_at'] = time();
		}
		$opts['_schema_version'] = 2;
		update_option( PLSEO_Options::OPTION_NAME, $opts );
		PLSEO_Options::flush_cache();
	}
}
