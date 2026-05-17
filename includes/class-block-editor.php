<?php
/**
 * PLSEO_Block_Editor — Gutenberg sidebar panel + REST-exposed post meta.
 *
 * Two responsibilities:
 *
 *   1. Register `_plseo_*` post meta with `show_in_rest = true` so the block
 *      editor can read/write the fields through the standard `entity-prop`
 *      hooks. The meta is still accessible the classic way via `get_post_meta`.
 *
 *   2. Enqueue assets/block-editor.js on every post-editor screen so the
 *      sidebar panel appears alongside the standard Document / Block tabs.
 *
 * Vanilla JS implementation — no build step required. WordPress ships the
 * `wp.plugins`, `wp.editPost`, `wp.components`, `wp.data`, and `wp.element`
 * globals; we lean on them directly.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Block_Editor {

	private static ?self $instance = null;

	private const META_FIELDS = array(
		'_plseo_title'         => 'string',
		'_plseo_description'   => 'string',
		'_plseo_canonical'     => 'string',
		'_plseo_social_image'  => 'string',
		'_plseo_schema_type'   => 'string',
		'_plseo_focus_keyword' => 'string',
		'_plseo_quick_answer'  => 'string',
		'_plseo_cornerstone'   => 'string',
		'_plseo_noindex'       => 'string',
		'_plseo_nofollow'      => 'string',
	);

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init',                          array( $this, 'register_meta_fields' ) );
		add_action( 'enqueue_block_editor_assets',   array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Expose our _plseo_* meta keys through the REST API for editor consumption.
	 *
	 * Only registered for public post types. `auth_callback` enforces the same
	 * `edit_post` capability check the meta box uses.
	 */
	public function register_meta_fields(): void {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			foreach ( self::META_FIELDS as $meta_key => $php_type ) {
				register_post_meta( $type, $meta_key, array(
					'type'              => $php_type,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => static function ( $v ) {
						return is_string( $v ) ? sanitize_textarea_field( $v ) : '';
					},
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				) );
			}
		}
	}

	/**
	 * Enqueue the sidebar JS + style bundle. WP's `wp-plugins`, `wp-edit-post`,
	 * `wp-components`, `wp-data`, `wp-element`, `wp-i18n` packages are declared
	 * as dependencies so they're auto-loaded by the editor.
	 */
	public function enqueue_editor_assets(): void {
		wp_enqueue_script(
			'plseo-block-editor',
			PL_SEO_PLUGIN_URL . 'assets/block-editor.js',
			array(
				'wp-plugins', 'wp-edit-post', 'wp-components',
				'wp-data', 'wp-element', 'wp-i18n', 'wp-compose',
			),
			PL_SEO_VERSION,
			true
		);

		wp_enqueue_style(
			'plseo-block-editor',
			PL_SEO_PLUGIN_URL . 'assets/block-editor.css',
			array( 'wp-edit-post' ),
			PL_SEO_VERSION
		);

		// Pass a small config object to the script (option keys, separator, etc.).
		wp_localize_script(
			'plseo-block-editor',
			'PLSEO_BLOCK',
			array(
				'separator'    => (string) PLSEO_Options::get( 'title_separator', '|' ),
				'siteName'     => (string) get_bloginfo( 'name' ),
				'schemaTypes'  => array(
					''               => 'Auto (post-type default)',
					'Article'        => 'Article',
					'NewsArticle'    => 'NewsArticle',
					'BlogPosting'    => 'BlogPosting',
					'WebPage'        => 'WebPage',
					'FAQPage'        => 'FAQPage',
					'HowTo'          => 'HowTo',
					'Event'          => 'Event',
					'VideoObject'    => 'VideoObject',
					'Product'        => 'Product',
					'Review'         => 'Review',
					'Recipe'         => 'Recipe',
					'JobPosting'     => 'JobPosting',
					'Course'         => 'Course',
					'SoftwareApplication' => 'SoftwareApplication',
					'Person'         => 'Person',
					'LocalBusiness'  => 'LocalBusiness',
					'none'           => 'None (disable schema)',
				),
				'titleBands'   => array( 'min' => 30, 'max' => 60 ),
				'descBands'    => array( 'min' => 120, 'max' => 160 ),
			)
		);
	}
}
