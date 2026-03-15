<?php
/**
 * PerryLabs SEO + AEO — Uninstall
 *
 * Removes all plugin data from the database on uninstall.
 * Deletes plugin options and all per-post SEO meta fields.
 *
 * @package PerryLabs_SEO
 */

// Abort if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* ──────────────────────────────────────────────────────────────────────
 * Delete plugin options
 * ────────────────────────────────────────────────────────────────────── */

delete_option( 'perrylabs_seo_options' );

/* ──────────────────────────────────────────────────────────────────────
 * Delete all per-post SEO meta fields
 * ────────────────────────────────────────────────────────────────────── */

global $wpdb;

$meta_keys = array(
	'_perrylabs_seo_title',
	'_perrylabs_seo_description',
	'_perrylabs_seo_canonical',
	'_perrylabs_seo_noindex',
	'_perrylabs_seo_nofollow',
	'_perrylabs_seo_social_image',
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => $key ),
		array( '%s' )
	);
}

/* ──────────────────────────────────────────────────────────────────────
 * Flush rewrite rules to clean up sitemap endpoints
 * ────────────────────────────────────────────────────────────────────── */

flush_rewrite_rules();
