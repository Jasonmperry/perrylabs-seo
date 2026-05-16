<?php
/**
 * PLSEO_Image_SEO — auto-alt fallback and on-upload slug optimization.
 *
 * Two interventions, both opt-in via options:
 *
 *   1. Auto-alt fallback (`image_auto_alt`):
 *      When an <img> is rendered without an alt attribute and the attachment
 *      itself has no alt text either, we synthesize one from the attachment
 *      title (or parent post title). Missing alts are the single most common
 *      a11y/SEO regression on long-running WP sites.
 *
 *   2. Upload slug optimization (`image_optimize_upload_slug`):
 *      At wp_handle_upload time, rewrite the filename to its sanitized title
 *      slug — "DSC_4523.jpg" becomes "founders-portrait.jpg" when the post
 *      title is "Founders Portrait". Original-filename metadata is preserved.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Image_SEO {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( (bool) PLSEO_Options::get( 'image_auto_alt', true ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'fallback_alt' ), 10, 2 );
		}
		if ( (bool) PLSEO_Options::get( 'image_optimize_upload_slug', false ) ) {
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'optimize_upload_slug' ) );
		}
	}

	/**
	 * Fill in alt from attachment title / post title when attribute is empty.
	 *
	 * @param array<string,string> $attr
	 * @param \WP_Post             $attachment
	 * @return array<string,string>
	 */
	public function fallback_alt( array $attr, \WP_Post $attachment ): array {
		if ( ! empty( $attr['alt'] ) ) {
			return $attr;
		}

		$alt = trim( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$alt = trim( (string) $attachment->post_title );
		}
		if ( '' === $alt && $attachment->post_parent ) {
			$parent = get_post( $attachment->post_parent );
			if ( $parent instanceof \WP_Post ) {
				$alt = trim( (string) $parent->post_title );
			}
		}
		if ( '' !== $alt ) {
			$attr['alt'] = sanitize_text_field( $alt );
		}
		return $attr;
	}

	/**
	 * Rewrite the filename of an uploaded image to a clean slug.
	 *
	 * Skips when the filename is already a clean slug or when the upload is
	 * not an image. Other extensions are left alone to avoid breaking
	 * document/audio/video naming conventions a team may depend on.
	 *
	 * @param array{name?:string,type?:string} $file
	 * @return array{name?:string,type?:string}
	 */
	public function optimize_upload_slug( array $file ): array {
		if ( empty( $file['name'] ) || empty( $file['type'] ) ) {
			return $file;
		}
		if ( ! str_starts_with( (string) $file['type'], 'image/' ) ) {
			return $file;
		}

		$info  = pathinfo( (string) $file['name'] );
		$base  = (string) ( $info['filename'] ?? '' );
		$ext   = (string) ( $info['extension'] ?? '' );
		if ( '' === $base || '' === $ext ) {
			return $file;
		}

		// Skip filenames already in slug form (lowercase ASCII, separators).
		if ( preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base ) ) {
			return $file;
		}

		$slug = sanitize_title( $base );
		if ( '' === $slug ) {
			return $file;
		}
		// Guard against bare numeric / very short slugs that lose meaning.
		if ( mb_strlen( $slug ) < 3 ) {
			return $file;
		}

		$file['name'] = $slug . '.' . strtolower( $ext );
		return $file;
	}
}
