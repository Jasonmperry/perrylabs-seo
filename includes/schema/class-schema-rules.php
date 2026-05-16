<?php
/**
 * PLSEO_Schema_Rules — rule-based schema dispatch.
 *
 * Rules live in `plseo_options['schema_rules']` as an ordered list of
 * `{ when: { post_type?:string, taxonomy?:string, term_slug?:string },
 *    emit: array<int,string>  }`.
 *
 * Evaluation:
 *   - Walk rules in order.
 *   - First rule whose conditions match wins (returns its `emit` list).
 *   - If no rule matches, fall back to the legacy `schema_type_map` (post_type → string).
 *
 * The emit list can name any schema type Schema_Content / Schema_AEO already
 * knows how to build (Article, NewsArticle, BlogPosting, FAQPage, HowTo, Event,
 * VideoObject, Person, LocalBusiness, WebPage) plus the special token `none`,
 * which suppresses primary-entity output for the post.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Rules {

	/**
	 * Resolve which schema types should emit for a given post.
	 *
	 * @return array<int,string>
	 */
	public static function emit_for( \WP_Post $post ): array {
		// Per-post override always wins.
		$override = (string) plseo_get_post_meta( $post->ID, 'schema_type', '' );
		if ( '' !== $override ) {
			return 'none' === $override ? array( 'none' ) : array( $override );
		}

		$rules = (array) PLSEO_Options::get( 'schema_rules', array() );
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['emit'] ) ) {
				continue;
			}
			if ( self::matches( $post, (array) ( $rule['when'] ?? array() ) ) ) {
				return array_values( array_filter( array_map( 'strval', (array) $rule['emit'] ) ) );
			}
		}

		// Legacy fallback: simple post_type → string map.
		$map = (array) PLSEO_Options::get( 'schema_type_map', array() );
		if ( isset( $map[ $post->post_type ] ) && '' !== $map[ $post->post_type ] ) {
			return array( (string) $map[ $post->post_type ] );
		}

		return array( 'page' === $post->post_type ? 'WebPage' : 'Article' );
	}

	/**
	 * @param array{post_type?:string,taxonomy?:string,term_slug?:string} $when
	 */
	private static function matches( \WP_Post $post, array $when ): bool {
		if ( empty( $when ) ) {
			// Empty match block = unconditional rule. Matches everything.
			return true;
		}
		if ( ! empty( $when['post_type'] ) && (string) $when['post_type'] !== $post->post_type ) {
			return false;
		}
		if ( ! empty( $when['taxonomy'] ) && ! empty( $when['term_slug'] ) ) {
			$has = has_term( (string) $when['term_slug'], (string) $when['taxonomy'], $post );
			if ( ! $has ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sanitize a submitted rules array.
	 *
	 * @param mixed $raw
	 * @return array<int,array{when:array<string,string>,emit:array<int,string>}>
	 */
	public static function sanitize( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$when_in = (array) ( $row['when'] ?? array() );
			$when    = array();
			foreach ( array( 'post_type', 'taxonomy', 'term_slug' ) as $k ) {
				if ( ! empty( $when_in[ $k ] ) ) {
					$when[ $k ] = sanitize_text_field( (string) $when_in[ $k ] );
				}
			}
			$emit = array_values( array_filter( array_map(
				static fn( $v ) => sanitize_text_field( (string) $v ),
				(array) ( $row['emit'] ?? array() )
			) ) );
			if ( empty( $emit ) ) {
				continue;
			}
			$out[] = array( 'when' => $when, 'emit' => $emit );
		}
		return $out;
	}
}
