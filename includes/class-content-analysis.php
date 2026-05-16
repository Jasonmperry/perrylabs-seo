<?php
/**
 * PLSEO_Content_Analysis — local, deterministic content checks.
 *
 * No remote API calls. Every check returns a stable shape:
 *   array{id:string, severity:'pass'|'warn'|'fail', label:string, detail:string}
 *
 * Severity gradient:
 *   pass  — looks good
 *   warn  — could be better, but not broken
 *   fail  — actively hurting SEO or AEO
 *
 * Modules consume this two ways:
 *   - The post meta box renders the list and a small score badge.
 *   - PLSEO_Admin_Bar shows the post's worst severity on the front-end pill.
 *   - PLSEO_REST_API exposes it at /wp-json/plseo/v1/analyze/{id}.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Content_Analysis {

	private const TITLE_MIN = 30;
	private const TITLE_MAX = 60;
	private const DESC_MIN  = 120;
	private const DESC_MAX  = 160;

	/**
	 * Run the full check set against a post.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string}>
	 */
	public static function analyze( \WP_Post $post ): array {
		$out = array();

		$rendered = (string) apply_filters( 'the_content', $post->post_content );
		$plain    = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $rendered ) ) ?? '' );

		// Title.
		$title_resolved = self::effective_title( $post );
		$len = mb_strlen( $title_resolved );
		if ( 0 === $len ) {
			$out[] = self::row( 'title_missing', 'fail', __( 'SEO title is missing', 'perrylabs-seo' ), __( 'Set a custom title or rely on the template — currently resolves to an empty string.', 'perrylabs-seo' ) );
		} elseif ( $len < self::TITLE_MIN ) {
			$out[] = self::row( 'title_short', 'warn', __( 'Title is short', 'perrylabs-seo' ), sprintf( __( '%1$d chars (target %2$d–%3$d).', 'perrylabs-seo' ), $len, self::TITLE_MIN, self::TITLE_MAX ) );
		} elseif ( $len > self::TITLE_MAX ) {
			$out[] = self::row( 'title_long', 'warn', __( 'Title may be truncated in search results', 'perrylabs-seo' ), sprintf( __( '%1$d chars (target %2$d–%3$d).', 'perrylabs-seo' ), $len, self::TITLE_MIN, self::TITLE_MAX ) );
		} else {
			$out[] = self::row( 'title_ok', 'pass', __( 'Title length looks good', 'perrylabs-seo' ), sprintf( __( '%d chars.', 'perrylabs-seo' ), $len ) );
		}

		// Description.
		$desc = self::effective_description( $post );
		$dlen = mb_strlen( $desc );
		if ( 0 === $dlen ) {
			$out[] = self::row( 'desc_missing', 'fail', __( 'Meta description is missing', 'perrylabs-seo' ), __( 'Crawlers and AI engines may use the first paragraph as a fallback — which usually under-sells the page.', 'perrylabs-seo' ) );
		} elseif ( $dlen < self::DESC_MIN ) {
			$out[] = self::row( 'desc_short', 'warn', __( 'Description is short', 'perrylabs-seo' ), sprintf( __( '%1$d chars (target %2$d–%3$d).', 'perrylabs-seo' ), $dlen, self::DESC_MIN, self::DESC_MAX ) );
		} elseif ( $dlen > self::DESC_MAX ) {
			$out[] = self::row( 'desc_long', 'warn', __( 'Description may be truncated in search results', 'perrylabs-seo' ), sprintf( __( '%1$d chars (target %2$d–%3$d).', 'perrylabs-seo' ), $dlen, self::DESC_MIN, self::DESC_MAX ) );
		} else {
			$out[] = self::row( 'desc_ok', 'pass', __( 'Description length looks good', 'perrylabs-seo' ), sprintf( __( '%d chars.', 'perrylabs-seo' ), $dlen ) );
		}

		// H1 count.
		$h1_count = preg_match_all( '#<h1\b#i', $rendered ) ?: 0;
		if ( 0 === $h1_count ) {
			// If the theme renders the title outside the_content, there's still a single H1 — flag as warn, not fail.
			$out[] = self::row( 'h1_implicit', 'warn', __( 'No explicit H1 in content', 'perrylabs-seo' ), __( 'Most themes render the post title as the H1 — verify the rendered page has exactly one.', 'perrylabs-seo' ) );
		} elseif ( $h1_count > 1 ) {
			$out[] = self::row( 'h1_multiple', 'fail', __( 'Multiple H1 elements detected', 'perrylabs-seo' ), sprintf( __( 'Found %d H1 tags. Use exactly one per page.', 'perrylabs-seo' ), $h1_count ) );
		} else {
			$out[] = self::row( 'h1_ok', 'pass', __( 'One H1 in content', 'perrylabs-seo' ), '' );
		}

		// Image alt coverage.
		$img_total  = preg_match_all( '#<img\b[^>]*>#i', $rendered, $imgs ) ?: 0;
		$img_no_alt = 0;
		if ( $img_total > 0 ) {
			foreach ( $imgs[0] as $tag ) {
				if ( ! preg_match( '#\salt\s*=\s*["\'][^"\']+["\']#i', $tag ) ) {
					$img_no_alt++;
				}
			}
		}
		if ( 0 === $img_total ) {
			$out[] = self::row( 'images_none', 'warn', __( 'No images in content', 'perrylabs-seo' ), __( 'Posts with at least one relevant image tend to outperform text-only.', 'perrylabs-seo' ) );
		} elseif ( $img_no_alt > 0 ) {
			$out[] = self::row( 'images_alt_missing', 'fail', __( 'Some images are missing alt text', 'perrylabs-seo' ), sprintf( __( '%1$d of %2$d images.', 'perrylabs-seo' ), $img_no_alt, $img_total ) );
		} else {
			$out[] = self::row( 'images_alt_ok', 'pass', __( 'All images have alt text', 'perrylabs-seo' ), '' );
		}

		// Word count.
		$word_count = str_word_count( $plain );
		if ( $word_count < 300 ) {
			$out[] = self::row( 'words_thin', 'warn', __( 'Content is thin', 'perrylabs-seo' ), sprintf( __( '%d words. Cornerstone pages typically need 600+.', 'perrylabs-seo' ), $word_count ) );
		} else {
			$out[] = self::row( 'words_ok', 'pass', __( 'Word count is reasonable', 'perrylabs-seo' ), sprintf( __( '%d words.', 'perrylabs-seo' ), $word_count ) );
		}

		// Outbound internal links from this post.
		$internal = self::count_internal_links( $rendered );
		if ( 0 === $internal ) {
			$out[] = self::row( 'internal_links_none', 'warn', __( 'No internal links', 'perrylabs-seo' ), __( 'Linking to related posts helps crawlers and answer engines understand topical clusters.', 'perrylabs-seo' ) );
		} else {
			$out[] = self::row( 'internal_links_ok', 'pass', sprintf( __( '%d internal link(s) out', 'perrylabs-seo' ), $internal ), '' );
		}

		// Inbound internal links to this post (orphan detection).
		$inbound = PLSEO_Link_Graph::instance()->inbound_count( (int) $post->ID );
		if ( 0 === $inbound && 'publish' === $post->post_status ) {
			$out[] = self::row( 'inbound_none', 'warn', __( 'Orphan post — no other posts link to this one', 'perrylabs-seo' ), __( 'Add a link from a related post so this page is discoverable from elsewhere on the site.', 'perrylabs-seo' ) );
		} elseif ( $inbound > 0 ) {
			$out[] = self::row( 'inbound_ok', 'pass', sprintf( __( '%d inbound internal link(s)', 'perrylabs-seo' ), $inbound ), '' );
		}

		// AEO: FAQ-readiness signal.
		$has_question_h = (bool) preg_match( '#<h[23][^>]*>[^<]*\?\s*</h[23]>#i', $rendered );
		if ( $has_question_h ) {
			$out[] = self::row( 'aeo_faq_signal', 'pass', __( 'Question-style heading detected — FAQ schema may auto-emit', 'perrylabs-seo' ), '' );
		}

		// AEO: HowTo-readiness signal.
		if ( (bool) preg_match( '#<h[23][^>]*>\s*Step\s+\d+#i', $rendered ) || (bool) preg_match( '#<ol[\s>]#i', $rendered ) ) {
			$out[] = self::row( 'aeo_howto_signal', 'pass', __( 'Step-style content detected — HowTo schema may auto-emit', 'perrylabs-seo' ), '' );
		}

		// Readability (Flesch-Kincaid Reading Ease).
		$readability = self::readability( $plain );
		if ( $readability['words'] >= 50 ) {
			if ( $readability['score'] >= 60 ) {
				$out[] = self::row( 'readability_ok', 'pass', sprintf( __( 'Readability: %1$s (FK Reading Ease %2$.0f)', 'perrylabs-seo' ), $readability['band'], $readability['score'] ), sprintf( __( 'Avg %1$.1f words/sentence, %2$.1f syllables/word.', 'perrylabs-seo' ), $readability['avg_sentence_len'], $readability['avg_syllables_per_word'] ) );
			} elseif ( $readability['score'] >= 30 ) {
				$out[] = self::row( 'readability_dense', 'warn', sprintf( __( 'Readability: %1$s (FK Reading Ease %2$.0f)', 'perrylabs-seo' ), $readability['band'], $readability['score'] ), __( 'Shorter sentences and simpler words would lift the score.', 'perrylabs-seo' ) );
			} else {
				$out[] = self::row( 'readability_hard', 'fail', sprintf( __( 'Readability: %1$s (FK Reading Ease %2$.0f)', 'perrylabs-seo' ), $readability['band'], $readability['score'] ), __( 'College-level prose. Consider breaking long sentences and replacing rare words.', 'perrylabs-seo' ) );
			}
		}

		// Focus keyword coverage (supports comma-separated list).
		$kw_raw = (string) plseo_get_post_meta( $post->ID, 'focus_keyword', '' );
		if ( '' !== $kw_raw ) {
			foreach ( self::split_keywords( $kw_raw ) as $kw ) {
				$out[] = self::keyword_coverage( $kw, $post, $title_resolved, $plain, $rendered );
			}
		}

		return $out;
	}

	/**
	 * Flesch-Kincaid Reading Ease.
	 *
	 * @return array{score:float,band:string,words:int,sentences:int,avg_sentence_len:float,avg_syllables_per_word:float}
	 */
	public static function readability( string $plain ): array {
		$words_arr = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$words     = count( $words_arr );
		$sentences = max( 1, (int) preg_match_all( '/[.!?]+(?=\s|$)/u', $plain ) );

		$syllables = 0;
		foreach ( $words_arr as $w ) {
			$syllables += self::count_syllables( $w );
		}

		if ( $words < 1 ) {
			return array( 'score' => 0.0, 'band' => 'n/a', 'words' => 0, 'sentences' => $sentences, 'avg_sentence_len' => 0.0, 'avg_syllables_per_word' => 0.0 );
		}

		$asl   = $words / $sentences;
		$asw   = $syllables / $words;
		$score = 206.835 - ( 1.015 * $asl ) - ( 84.6 * $asw );
		$score = max( 0.0, min( 100.0, $score ) );

		$band = match ( true ) {
			$score >= 90 => 'very easy',
			$score >= 80 => 'easy',
			$score >= 70 => 'fairly easy',
			$score >= 60 => 'standard',
			$score >= 50 => 'fairly difficult',
			$score >= 30 => 'difficult',
			default      => 'very difficult',
		};

		return array(
			'score'                  => $score,
			'band'                   => $band,
			'words'                  => $words,
			'sentences'              => $sentences,
			'avg_sentence_len'       => $asl,
			'avg_syllables_per_word' => $asw,
		);
	}

	/**
	 * Approximate syllable count — vowel-group heuristic. Good enough for English-ish prose.
	 */
	private static function count_syllables( string $word ): int {
		$w = strtolower( preg_replace( '/[^a-z]/i', '', $word ) ?? '' );
		if ( '' === $w ) {
			return 0;
		}
		if ( strlen( $w ) <= 3 ) {
			return 1;
		}
		// Silent trailing 'e' (but not 'le').
		$w = preg_replace( '/(?:[^aeiouy])e$/i', '$0e', $w ) ?? $w;
		$w = preg_replace( '/e$/i', '', $w ) ?? $w;
		preg_match_all( '/[aeiouy]+/i', $w, $m );
		$count = isset( $m[0] ) ? count( $m[0] ) : 0;
		return max( 1, $count );
	}

	/** @return array<int,string> */
	private static function split_keywords( string $raw ): array {
		$parts = array_map( 'trim', explode( ',', $raw ) );
		return array_values( array_filter( $parts, static fn( $p ) => '' !== $p ) );
	}

	/**
	 * Coverage report for one focus keyword.
	 *
	 * @return array{id:string,severity:string,label:string,detail:string}
	 */
	private static function keyword_coverage( string $kw, \WP_Post $post, string $title, string $plain, string $rendered ): array {
		$kw_lc      = mb_strtolower( $kw );
		$in_title   = false !== mb_stripos( $title, $kw );
		$in_url     = false !== stripos( (string) get_post_field( 'post_name', $post ), sanitize_title( $kw ) );
		$in_first   = false !== mb_stripos( mb_substr( $plain, 0, 200 ), $kw_lc );
		$in_h1h2    = (bool) preg_match( '#<h[12][^>]*>[^<]*' . preg_quote( $kw, '#' ) . '[^<]*</h[12]>#i', $rendered );
		$density    = self::keyword_density( $plain, $kw );

		$hits = (int) $in_title + (int) $in_url + (int) $in_first + (int) $in_h1h2;
		$severity = $hits >= 3 ? 'pass' : ( $hits >= 2 ? 'warn' : 'fail' );

		$detail = sprintf(
			'%s · %s · %s · %s · %s',
			$in_title ? '✓ title'         : '✗ title',
			$in_url   ? '✓ URL'           : '✗ URL',
			$in_first ? '✓ first 200 chars' : '✗ first 200 chars',
			$in_h1h2  ? '✓ H1/H2'         : '✗ H1/H2',
			sprintf( __( 'density %.2f%%', 'perrylabs-seo' ), $density * 100 )
		);

		return self::row(
			'keyword_' . sanitize_key( $kw ),
			$severity,
			sprintf( __( 'Focus keyword: "%s"', 'perrylabs-seo' ), $kw ),
			$detail
		);
	}

	private static function keyword_density( string $plain, string $kw ): float {
		$words = max( 1, str_word_count( $plain ) );
		$count = mb_substr_count( mb_strtolower( $plain ), mb_strtolower( $kw ) );
		return $count / $words;
	}

	/**
	 * Aggregate severity: highest-severity finding wins.
	 */
	public static function overall_severity( array $rows ): string {
		$worst = 'pass';
		foreach ( $rows as $r ) {
			if ( 'fail' === ( $r['severity'] ?? '' ) ) {
				return 'fail';
			}
			if ( 'warn' === ( $r['severity'] ?? '' ) ) {
				$worst = 'warn';
			}
		}
		return $worst;
	}

	private static function row( string $id, string $severity, string $label, string $detail ): array {
		return array( 'id' => $id, 'severity' => $severity, 'label' => $label, 'detail' => $detail );
	}

	private static function effective_title( \WP_Post $post ): string {
		$override = (string) plseo_get_post_meta( $post->ID, 'title', '' );
		if ( '' !== $override ) {
			return PLSEO_Template_Resolver::resolve( $override, PLSEO_Template_Resolver::context_for_post( $post ) );
		}
		$tmpl = (string) PLSEO_Options::get( 'page' === $post->post_type ? 'title_template_page' : 'title_template_post', '%post_title% %sep% %site_name%' );
		return PLSEO_Template_Resolver::resolve( $tmpl, PLSEO_Template_Resolver::context_for_post( $post ) );
	}

	private static function effective_description( \WP_Post $post ): string {
		$override = (string) plseo_get_post_meta( $post->ID, 'description', '' );
		if ( '' !== $override ) {
			return $override;
		}
		return (string) $post->post_excerpt;
	}

	private static function count_internal_links( string $html ): int {
		if ( ! preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\']#i', $html, $m ) ) {
			return 0;
		}
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$n    = 0;
		foreach ( $m[1] as $href ) {
			$h = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( '' === $h || $h === $host ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Suggest internal links to related posts based on a focus keyword
	 * (no remote calls, simple LIKE match on post_title and post_content).
	 *
	 * @return array<int,array{ID:int,title:string,permalink:string}>
	 */
	public static function suggest_internal_links( int $post_id, string $keyword, int $limit = 5 ): array {
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return array();
		}
		$query = new \WP_Query( array(
			'post_status'    => 'publish',
			'posts_per_page' => $limit + 1,
			'post__not_in'   => array( $post_id ),
			's'              => $keyword,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $query->posts as $p ) {
			if ( $p instanceof \WP_Post ) {
				$out[] = array(
					'ID'        => (int) $p->ID,
					'title'     => (string) get_the_title( $p ),
					'permalink' => (string) get_permalink( $p ),
				);
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
