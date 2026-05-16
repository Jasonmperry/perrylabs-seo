<?php
/**
 * PLSEO_Schema_AEO — Answer Engine Optimization schema contributors.
 *
 * Auto-detects FAQ blocks, HowTo step lists, and speakable regions in post content,
 * then emits FAQPage / HowTo / SpeakableSpecification nodes appended to the @graph.
 *
 * This is the heart of AEO: AI overviews and voice assistants strongly prefer
 * structured Q&A and step-by-step content. We don't require authors to hand-write
 * the JSON-LD — we extract it from their content.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_AEO {

	public static function register( PLSEO_Schema_Graph $graph ): void {
		$graph->register_contributor( 'faq-autodetect',   array( __CLASS__, 'faq_page' ),   25 );
		$graph->register_contributor( 'howto-autodetect', array( __CLASS__, 'how_to' ),     27 );
		$graph->register_contributor( 'quick-answer',     array( __CLASS__, 'quick_answer' ), 30 );
	}

	/* ───────────────────────── FAQ ───────────────────────── */

	/**
	 * Build a FAQPage node from a post's content.
	 *
	 * Detection strategies (first match wins):
	 *   1. Per-post override: a serialized array of {q, a} in `_plseo_faq_items`.
	 *   2. Gutenberg `core/details` blocks (summary = Q, body = A) — the WP 6.5+ native FAQ pattern.
	 *   3. `<h2>`/`<h3>` heading-followed-by-paragraph pattern where the heading ends with "?".
	 *
	 * @return array<string,mixed>|null
	 */
	public static function faq_page( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! (bool) PLSEO_Options::get( 'aeo_faq_autodetect', true ) ) {
			// Still honor a manual override even when autodetect is off.
		}

		$items = self::extract_faq_items( $post );
		if ( empty( $items ) ) {
			return null;
		}

		$main = array();
		foreach ( $items as $pair ) {
			$main[] = array(
				'@type'          => 'Question',
				'name'           => (string) $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => (string) $pair['a'],
				),
			);
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => PLSEO_Schema_Graph::current_url() . '#faq',
			'mainEntity' => $main,
		);
	}

	/**
	 * @return array<int,array{q:string,a:string}>
	 */
	private static function extract_faq_items( \WP_Post $post ): array {
		// 1. Per-post override.
		$override = get_post_meta( $post->ID, '_plseo_faq_items', true );
		if ( is_array( $override ) && ! empty( $override ) ) {
			$out = array();
			foreach ( $override as $row ) {
				if ( ! empty( $row['q'] ) && ! empty( $row['a'] ) ) {
					$out[] = array(
						'q' => wp_strip_all_tags( (string) $row['q'] ),
						'a' => wp_strip_all_tags( (string) $row['a'] ),
					);
				}
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		if ( ! (bool) PLSEO_Options::get( 'aeo_faq_autodetect', true ) ) {
			return array();
		}

		$content = (string) $post->post_content;

		// 2. core/details blocks.
		$items = array();
		if ( has_block( 'core/details', $content ) ) {
			$blocks = parse_blocks( $content );
			self::walk_blocks_for_details( $blocks, $items );
		}
		if ( ! empty( $items ) ) {
			return $items;
		}

		// 3. Heading-question pattern. Render content first so shortcodes expand.
		$rendered = (string) apply_filters( 'the_content', $content );
		return self::extract_heading_question_pairs( $rendered );
	}

	/**
	 * Recursive walk of parsed blocks to find core/details (the native FAQ-ish block).
	 *
	 * @param array<int,array<string,mixed>> $blocks
	 * @param array<int,array{q:string,a:string}> $out
	 */
	private static function walk_blocks_for_details( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && 'core/details' === $block['blockName'] ) {
				$inner = (string) ( $block['innerHTML'] ?? '' );
				if ( preg_match( '#<summary[^>]*>(.*?)</summary>(.*)$#is', $inner, $m ) ) {
					$q = trim( wp_strip_all_tags( $m[1] ) );
					$a = trim( wp_strip_all_tags( $m[2] ) );
					if ( '' !== $q && '' !== $a ) {
						$out[] = array( 'q' => $q, 'a' => $a );
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_for_details( $block['innerBlocks'], $out );
			}
		}
	}

	/**
	 * @return array<int,array{q:string,a:string}>
	 */
	private static function extract_heading_question_pairs( string $html ): array {
		$out = array();
		// Match h2/h3 ending with "?" followed by content up to the next h2/h3.
		if ( preg_match_all(
			'#<h([23])[^>]*>(.*?\?)\s*</h\1>(.*?)(?=<h[23][^>]*>|$)#is',
			$html,
			$m,
			PREG_SET_ORDER
		) ) {
			foreach ( $m as $match ) {
				$q = trim( wp_strip_all_tags( $match[2] ) );
				$a = trim( wp_strip_all_tags( $match[3] ) );
				if ( '' !== $q && '' !== $a && mb_strlen( $a ) >= 20 ) {
					$out[] = array(
						'q' => $q,
						'a' => preg_replace( '/\s+/u', ' ', $a ) ?? $a,
					);
				}
			}
		}
		return $out;
	}

	/* ───────────────────────── HowTo ───────────────────────── */

	/**
	 * Build a HowTo node from a post's content.
	 *
	 * Detection strategies:
	 *   1. Per-post override array `_plseo_howto_steps`.
	 *   2. Heading pattern: H2/H3 starting with "Step N:" or "Step N -".
	 *   3. Ordered list (<ol>) immediately following the first H2/H3.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function how_to( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( ! (bool) PLSEO_Options::get( 'aeo_howto_autodetect', true ) ) {
			return null;
		}

		$steps = self::extract_howto_steps( $post );
		if ( count( $steps ) < 2 ) {
			return null;
		}

		$step_nodes = array();
		foreach ( $steps as $i => $step ) {
			$step_nodes[] = array(
				'@type' => 'HowToStep',
				'position' => $i + 1,
				'name'  => (string) $step['name'],
				'text'  => (string) $step['text'],
			);
		}

		return array(
			'@type'  => 'HowTo',
			'@id'    => PLSEO_Schema_Graph::current_url() . '#howto',
			'name'   => (string) get_the_title( $post ),
			'step'   => $step_nodes,
			'totalTime' => 'PT' . ( count( $step_nodes ) * 2 ) . 'M', // rough heuristic; users can override.
		);
	}

	/**
	 * @return array<int,array{name:string,text:string}>
	 */
	private static function extract_howto_steps( \WP_Post $post ): array {
		$override = get_post_meta( $post->ID, '_plseo_howto_steps', true );
		if ( is_array( $override ) && ! empty( $override ) ) {
			$out = array();
			foreach ( $override as $row ) {
				if ( ! empty( $row['name'] ) ) {
					$out[] = array(
						'name' => wp_strip_all_tags( (string) $row['name'] ),
						'text' => wp_strip_all_tags( (string) ( $row['text'] ?? $row['name'] ) ),
					);
				}
			}
			if ( ! empty( $out ) ) {
				return $out;
			}
		}

		$html  = (string) apply_filters( 'the_content', $post->post_content );
		$steps = array();

		// Pattern 1: H2/H3 starting with "Step N".
		if ( preg_match_all(
			'#<h([23])[^>]*>\s*(?:Step\s+\d+[:.\-\s]+)(.*?)</h\1>(.*?)(?=<h[23][^>]*>|$)#is',
			$html,
			$m,
			PREG_SET_ORDER
		) ) {
			foreach ( $m as $match ) {
				$name = trim( wp_strip_all_tags( $match[2] ) );
				$text = trim( wp_strip_all_tags( $match[3] ) );
				if ( '' !== $name ) {
					$steps[] = array(
						'name' => $name,
						'text' => '' !== $text ? preg_replace( '/\s+/u', ' ', $text ) ?? $text : $name,
					);
				}
			}
		}

		if ( ! empty( $steps ) ) {
			return $steps;
		}

		// Pattern 2: first <ol> in the content (single, top-level).
		if ( preg_match( '#<ol[^>]*>(.*?)</ol>#is', $html, $m ) ) {
			if ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', $m[1], $items, PREG_SET_ORDER ) ) {
				foreach ( $items as $i => $li ) {
					$text = trim( wp_strip_all_tags( $li[1] ) );
					if ( '' === $text ) {
						continue;
					}
					$steps[] = array(
						'name' => sprintf( 'Step %d', $i + 1 ),
						'text' => preg_replace( '/\s+/u', ' ', $text ) ?? $text,
					);
				}
			}
		}

		return $steps;
	}

	/* ───────────────────────── Quick Answer / TL;DR ───────────────────────── */

	/**
	 * Promote a "quick answer" — typically the first paragraph — as a `description`
	 * on the primary entity. AI overviews lift this text directly when summarizing
	 * a page, so making it the FIRST content readers see has compounding upside.
	 *
	 * This contributor returns nothing for the graph itself; it nudges the
	 * primary entity via the plseo_schema_graph filter (cleaner than a side-effect).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function quick_answer( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$field = (string) PLSEO_Options::get( 'aeo_quick_answer_field', 'first_paragraph' );
		if ( 'none' === $field ) {
			return null;
		}

		add_filter( 'plseo_schema_graph', static function ( array $graph ) use ( $post, $field ): array {
			$quick = self::resolve_quick_answer( $post, $field );
			if ( '' === $quick ) {
				return $graph;
			}

			// Prefer any primary entity (Article/Event/etc., including multi-type rules
			// that emit `#primary-{type}`). Fall back to the WebPage when there is none.
			$webpage_id = PLSEO_Schema_Graph::webpage_id();

			$attached = false;
			foreach ( $graph as &$node ) {
				if ( ! isset( $node['@id'] ) ) {
					continue;
				}
				if ( str_contains( (string) $node['@id'], '#primary' ) && empty( $node['abstract'] ) ) {
					$node['abstract'] = $quick;
					$attached = true;
					break;
				}
			}
			if ( ! $attached ) {
				foreach ( $graph as &$node ) {
					if ( isset( $node['@id'] ) && $node['@id'] === $webpage_id && empty( $node['abstract'] ) ) {
						$node['abstract'] = $quick;
						break;
					}
				}
			}
			return $graph;
		}, 99 );

		return null;
	}

	private static function resolve_quick_answer( \WP_Post $post, string $field ): string {
		$override = (string) plseo_get_post_meta( $post->ID, 'quick_answer', '' );
		if ( '' !== $override ) {
			return $override;
		}

		if ( 'excerpt' === $field && '' !== $post->post_excerpt ) {
			return (string) $post->post_excerpt;
		}

		$rendered = wp_strip_all_tags( (string) apply_filters( 'the_content', $post->post_content ) );
		$rendered = trim( preg_replace( '/\s+/u', ' ', $rendered ) ?? '' );
		if ( '' === $rendered ) {
			return '';
		}
		// First sentence cluster up to ~280 chars.
		$first = mb_substr( $rendered, 0, 280 );
		$cut   = mb_strrpos( $first, '. ' );
		if ( false !== $cut && $cut > 80 ) {
			$first = mb_substr( $first, 0, $cut + 1 );
		}
		return trim( $first );
	}
}
