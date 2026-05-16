<?php
/**
 * PLSEO_Str — shared string and locale utilities.
 *
 * Pulled out of the various schema/meta classes to remove the four near-identical
 * `clip()` and `language()` helpers that had drifted apart over the rewrite.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Str {

	/**
	 * Clip a string to at most $max characters, with an ellipsis and word-aware
	 * boundary backoff. Multi-byte safe. Strips trailing punctuation before the
	 * ellipsis so output reads cleanly.
	 */
	public static function clip( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$clipped = mb_substr( $text, 0, $max - 1 );
		$cut     = mb_strrpos( $clipped, ' ' );
		if ( false !== $cut && $cut > $max - 30 ) {
			$clipped = mb_substr( $clipped, 0, $cut );
		}
		return rtrim( $clipped, ' ,.;:-' ) . '…';
	}

	/**
	 * Collapse whitespace and strip tags, no clipping. Useful for "first paragraph"
	 * extraction and content-analysis word counts.
	 */
	public static function normalize( string $text ): string {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) ?? '' );
	}

	/**
	 * Render a post's content to plain text (after `the_content` filter).
	 */
	public static function post_plain_text( \WP_Post $post ): string {
		return self::normalize( (string) apply_filters( 'the_content', $post->post_content ) );
	}

	/**
	 * Resolved site language as a BCP-47 string. Honors the `site_language`
	 * option override; otherwise derives from the active WP locale.
	 */
	public static function site_language(): string {
		$opt = trim( (string) PLSEO_Options::get( 'site_language', '' ) );
		if ( '' !== $opt ) {
			return $opt;
		}
		$locale = (string) get_locale();
		return '' !== $locale ? str_replace( '_', '-', $locale ) : 'en';
	}
}
