<?php
/**
 * Uninstall — wipe every artifact this plugin can create.
 *
 * Goal: a clean install → uninstall round trip leaves zero residue.
 * Anything created by the plugin (options, custom tables, post meta, user
 * meta, transients, scheduled events, the OG image cache directory) gets
 * removed here.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Top-level options + version markers + setup flag.
delete_option( 'plseo_options' );
delete_option( 'plseo_db_version' );
delete_option( 'plseo_indexnow_key' );
delete_option( 'plseo_setup_completed' );

// Legacy v1 options if they survived migration.
delete_option( 'perrylabs_seo_options' );

// Every custom table the plugin creates.
$tables = array(
	$wpdb->prefix . 'plseo_redirects',
	$wpdb->prefix . 'plseo_404_log',
	$wpdb->prefix . 'plseo_ai_visits',
	$wpdb->prefix . 'plseo_search_log',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// Post meta — could be thousands of rows; direct DELETE is fastest.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB

// User meta — author E-E-A-T fields + admin-notice dismissals.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'plseo\\_%'" ); // phpcs:ignore WordPress.DB

// Transients (object cache + DB fallback).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_plseo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB

// Scheduled cron event for daily maintenance.
wp_clear_scheduled_hook( 'plseo_daily_maintenance' );

// OG image cache directory under uploads.
if ( function_exists( 'wp_upload_dir' ) ) {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['basedir'] ) ) {
		$dir = trailingslashit( $upload['basedir'] ) . 'plseo-og';
		if ( is_dir( $dir ) ) {
			$files = glob( $dir . '/*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( is_file( $f ) ) {
						@unlink( $f ); // phpcs:ignore Generic.PHP.NoSilencedErrors,WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
			@rmdir( $dir ); // phpcs:ignore Generic.PHP.NoSilencedErrors,WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

// Multisite: repeat for every site in the network.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		delete_option( 'plseo_options' );
		delete_option( 'plseo_db_version' );
		delete_option( 'plseo_indexnow_key' );
		delete_option( 'plseo_setup_completed' );
		delete_option( 'perrylabs_seo_options' );

		foreach ( array( 'plseo_redirects', 'plseo_404_log', 'plseo_ai_visits', 'plseo_search_log' ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_plseo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB
		wp_clear_scheduled_hook( 'plseo_daily_maintenance' );

		restore_current_blog();
	}
}

// Force a rewrite-rules flush so /sitemap.xml etc no longer match.
delete_option( 'rewrite_rules' );
