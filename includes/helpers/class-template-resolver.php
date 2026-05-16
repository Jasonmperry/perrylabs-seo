<?php
/**
 * PLSEO_Template_Resolver — expand variables in title/description templates.
 *
 * Templates use %tokens%, e.g. "%post_title% %sep% %site_name%".
 * Unknown tokens are stripped (not echoed verbatim) so a bad template
 * never leaks `%foo%` to the rendered page.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Template_Resolver {

	/**
	 * Resolve a template string against a context array.
	 *
	 * @param string              $template
	 * @param array<string,mixed> $context
	 * @return string
	 */
	public static function resolve( string $template, array $context = array() ): string {
		if ( '' === $template ) {
			return '';
		}

		$defaults = self::default_context();
		$context  = array_merge( $defaults, $context );

		$out = preg_replace_callback(
			'/%([a-z0-9_]+)%/i',
			static function ( array $m ) use ( $context ): string {
				$key = strtolower( $m[1] );
				return isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
			},
			$template
		);

		if ( ! is_string( $out ) ) {
			return '';
		}

		// Collapse whitespace runs introduced by stripped tokens.
		$out = preg_replace( '/\s+/', ' ', $out ) ?? $out;

		// Strip orphan separators left at edges, e.g. "Title | " or " | Site".
		$sep = preg_quote( (string) ( $context['sep'] ?? '|' ), '/' );
		$out = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $out ) ?? $out;

		return trim( $out );
	}

	/**
	 * Context tokens that always have a value regardless of caller.
	 *
	 * @return array<string,string>
	 */
	private static function default_context(): array {
		return array(
			'sep'          => (string) PLSEO_Options::get( 'title_separator', '|' ),
			'site_name'    => (string) get_bloginfo( 'name' ),
			'site_tagline' => (string) get_bloginfo( 'description' ),
			'site_url'     => (string) home_url( '/' ),
			'current_year' => (string) gmdate( 'Y' ),
			'current_date' => (string) gmdate( 'F j, Y' ),
		);
	}

	/**
	 * Build a context dict from a WP_Post.
	 *
	 * @return array<string,string>
	 */
	public static function context_for_post( \WP_Post $post ): array {
		$author = $post->post_author ? get_userdata( (int) $post->post_author ) : false;
		return array(
			'post_title'   => (string) $post->post_title,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_date'    => (string) get_the_date( '', $post ),
			'post_modified'=> (string) get_the_modified_date( '', $post ),
			'author'       => $author ? (string) $author->display_name : '',
		);
	}

	/**
	 * Build a context dict for an archive request.
	 *
	 * @return array<string,string>
	 */
	public static function context_for_archive(): array {
		return array(
			'archive_title' => (string) wp_strip_all_tags( get_the_archive_title() ),
		);
	}

	/**
	 * Documented list of available tokens — drives the admin help UI.
	 *
	 * @return array<string,string>
	 */
	public static function token_documentation(): array {
		return array(
			'%post_title%'    => __( 'The post or page title.', 'perrylabs-seo' ),
			'%post_excerpt%'  => __( 'The post excerpt, if set.', 'perrylabs-seo' ),
			'%post_date%'     => __( 'The post publication date.', 'perrylabs-seo' ),
			'%post_modified%' => __( 'The post last-modified date.', 'perrylabs-seo' ),
			'%author%'        => __( 'The post author\'s display name.', 'perrylabs-seo' ),
			'%archive_title%' => __( 'The archive title (category, tag, author, date).', 'perrylabs-seo' ),
			'%site_name%'     => __( 'The site name (Settings → General → Site Title).', 'perrylabs-seo' ),
			'%site_tagline%'  => __( 'The site tagline.', 'perrylabs-seo' ),
			'%site_url%'      => __( 'The home URL.', 'perrylabs-seo' ),
			'%sep%'           => __( 'The configured title separator character.', 'perrylabs-seo' ),
			'%current_year%'  => __( 'Current 4-digit year (useful in titles for evergreen pages).', 'perrylabs-seo' ),
			'%current_date%'  => __( 'Current date in long format.', 'perrylabs-seo' ),
		);
	}
}
