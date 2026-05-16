<?php
/**
 * Uninstall — wipe all PerryLabs SEO data when the user deletes the plugin.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Settings + version markers.
delete_option( 'plseo_options' );
delete_option( 'plseo_db_version' );
delete_option( 'plseo_indexnow_key' );

// Custom tables.
$tables = array(
	$wpdb->prefix . 'plseo_redirects',
	$wpdb->prefix . 'plseo_404_log',
	$wpdb->prefix . 'plseo_ai_visits',
);
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

// Post meta we added (use direct query — there could be thousands).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB

// Transients (object cache + DB fallback).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_plseo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_plseo\\_%'" ); // phpcs:ignore WordPress.DB

// Multisite: also wipe legacy v1 options if they survived migration.
delete_option( 'perrylabs_seo_options' );

// Flush rewrites — the rewrite endpoints we registered are gone now.
delete_option( 'rewrite_rules' );
