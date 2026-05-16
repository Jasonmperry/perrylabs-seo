<?php
/**
 * PLSEO_Reading_Time — derive read-time + optional auto table of contents.
 *
 * Computes reading time at 230 wpm (the industry-default median) and exposes:
 *
 *   - the integer minute count via plseo_reading_time( $post ),
 *   - a `timeRequired` field on the primary @graph entity (ISO 8601, e.g. PT4M),
 *   - an opt-in TOC injected before the first H2 of any post that has 3+ H2s.
 *
 * The TOC is a semantic <nav> with linked entries; it does not depend on JS.
 * Existing in-content `<h2 id="…">` anchors are preserved; missing IDs are
 * synthesized from the heading text.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Reading_Time {

	private static ?self $instance = null;

	private const WORDS_PER_MINUTE = 230;
	private const MIN_HEADINGS_FOR_TOC = 3;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Always contribute timeRequired to the primary schema entity.
		add_filter( 'plseo_schema_graph', array( $this, 'enrich_with_read_time' ), 50, 2 );

		if ( (bool) PLSEO_Options::get( 'auto_toc_enabled', false ) ) {
			add_filter( 'the_content', array( $this, 'maybe_inject_toc' ), 12 );
		}
	}

	/* ───────────────────────── public helpers ───────────────────────── */

	public function minutes( \WP_Post $post ): int {
		$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
		return max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );
	}

	public function iso_duration( \WP_Post $post ): string {
		return 'PT' . $this->minutes( $post ) . 'M';
	}

	/* ───────────────────────── schema enrichment ───────────────────────── */

	/**
	 * @param array<int,array<string,mixed>> $graph
	 * @return array<int,array<string,mixed>>
	 */
	public function enrich_with_read_time( array $graph, ?\WP_Post $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $graph;
		}
		$iso = $this->iso_duration( $post );
		foreach ( $graph as &$node ) {
			if ( isset( $node['@id'] ) && str_contains( (string) $node['@id'], '#primary' ) && empty( $node['timeRequired'] ) ) {
				$node['timeRequired'] = $iso;
			}
		}
		return $graph;
	}

	/* ───────────────────────── TOC injection ───────────────────────── */

	public function maybe_inject_toc( string $html ): string {
		if ( is_admin() || ! is_singular() ) {
			return $html;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $html;
		}
		// Find h2 headings.
		if ( ! preg_match_all( '#<h2(\s[^>]*)?>(.*?)</h2>#is', $html, $m, PREG_SET_ORDER ) ) {
			return $html;
		}
		if ( count( $m ) < self::MIN_HEADINGS_FOR_TOC ) {
			return $html;
		}

		$items   = array();
		$new_html = $html;
		foreach ( $m as $match ) {
			$attrs = (string) ( $match[1] ?? '' );
			$text  = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' === $text ) {
				continue;
			}
			$id = '';
			if ( preg_match( '/\bid\s*=\s*["\']([^"\']+)["\']/i', $attrs, $idm ) ) {
				$id = $idm[1];
			}
			if ( '' === $id ) {
				$id = self::slug( $text );
				// Inject the id into the heading attributes in $new_html (first match only).
				$replacement = '<h2' . $attrs . ' id="' . esc_attr( $id ) . '">' . $match[2] . '</h2>';
				$pos         = strpos( $new_html, $match[0] );
				if ( false !== $pos ) {
					$new_html = substr_replace( $new_html, $replacement, $pos, strlen( $match[0] ) );
				}
			}
			$items[] = array( 'id' => $id, 'text' => $text );
		}

		if ( empty( $items ) ) {
			return $html;
		}

		$toc  = '<nav class="plseo-toc" aria-label="' . esc_attr__( 'Table of contents', 'perrylabs-seo' ) . '">';
		$toc .= '<p class="plseo-toc__title">' . esc_html__( 'On this page', 'perrylabs-seo' ) . '</p><ol>';
		foreach ( $items as $i ) {
			$toc .= sprintf( '<li><a href="#%s">%s</a></li>', esc_attr( $i['id'] ), esc_html( $i['text'] ) );
		}
		$toc .= '</ol></nav>';

		// Insert before the first H2 occurrence.
		$pos = strpos( $new_html, '<h2' );
		if ( false === $pos ) {
			return $new_html;
		}
		return substr_replace( $new_html, $toc, $pos, 0 );
	}

	private static function slug( string $text ): string {
		$slug = sanitize_title( $text );
		return $slug !== '' ? $slug : 'section-' . substr( md5( $text ), 0, 6 );
	}
}
