<?php
/**
 * PLSEO_LLMs_Txt — serve /llms.txt and /llms-full.txt.
 *
 * llms.txt is a markdown summary AI tools can fetch to understand your site at a glance.
 * The proposed standard (llmstxt.org) calls for:
 *   1. An H1 with the site name.
 *   2. A blockquote summary.
 *   3. Sections of links to important pages.
 *
 * llms-full.txt is the full-fat version: every included post's content concatenated.
 * Cached for 10 minutes by default; busted when posts in the included types change.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_LLMs_Txt {

	private static ?self $instance = null;

	private const CACHE_KEY_SUMMARY = 'plseo_llms_txt_v1';
	private const CACHE_KEY_FULL    = 'plseo_llms_full_v1';
	private const CACHE_TTL         = 600; // 10 minutes.

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', static function ( array $vars ): array {
			$vars[] = 'plseo_llms';
			return $vars;
		} );
		add_action( 'template_redirect', array( $this, 'render' ), 1 );
		add_action( 'save_post', array( $this, 'bust_cache' ) );
		add_action( 'deleted_post', array( $this, 'bust_cache' ) );

		// Same canonical short-circuit as the sitemap module.
		add_filter( 'redirect_canonical', static function ( $redirect_url, $requested_url ) {
			if ( is_string( $requested_url ) && preg_match( '#/llms(?:-full)?\.txt/?$#i', (string) $requested_url ) ) {
				return false;
			}
			return $redirect_url;
		}, 10, 2 );
	}

	public static function register_rewrites(): void {
		// Trailing `/?$` tolerates themes that canonical-redirect to slash form.
		add_rewrite_rule( '^llms\.txt/?$',      'index.php?plseo_llms=summary', 'top' );
		add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?plseo_llms=full',    'top' );
	}

	public function render(): void {
		$which = (string) get_query_var( 'plseo_llms' );
		if ( '' === $which ) {
			return;
		}

		if ( ! (bool) PLSEO_Options::get( 'llms_txt_enabled', true ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );

		if ( 'summary' === $which ) {
			echo $this->summary(); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( 'full' === $which ) {
			if ( ! (bool) PLSEO_Options::get( 'llms_full_enabled', true ) ) {
				status_header( 404 );
				exit;
			}
			echo $this->full(); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		status_header( 404 );
		exit;
	}

	/* ───────────────────────── summary ───────────────────────── */

	private function summary(): string {
		$cached = get_transient( self::CACHE_KEY_SUMMARY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$out  = '# ' . (string) get_bloginfo( 'name' ) . "\n\n";
		$out .= '> ' . $this->intro() . "\n\n";

		// Featured pages: admin-curated, plus any cornerstone-marked posts.
		$featured = array_values( array_unique( array_filter( array_merge(
			array_map( 'intval', (array) PLSEO_Options::get( 'llms_txt_featured_ids', array() ) ),
			$this->cornerstone_post_ids()
		) ) ) );
		if ( ! empty( $featured ) ) {
			$out .= "## Featured\n\n";
			foreach ( $featured as $pid ) {
				$pid = (int) $pid;
				$p   = get_post( $pid );
				if ( ! $p instanceof \WP_Post || 'publish' !== $p->post_status ) {
					continue;
				}
				$out .= sprintf(
					"- [%s](%s): %s\n",
					$this->md_escape( (string) get_the_title( $p ) ),
					(string) get_permalink( $p ),
					$this->md_escape( $this->one_liner( $p ) )
				);
			}
			$out .= "\n";
		}

		// Per-post-type sections.
		$types = (array) PLSEO_Options::get( 'llms_txt_include_post_types', array( 'post', 'page' ) );
		foreach ( $types as $type ) {
			$obj = get_post_type_object( $type );
			if ( ! $obj ) {
				continue;
			}
			$posts = get_posts( array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_plseo_noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_plseo_noindex', 'value' => '1', 'compare' => '!=' ),
				),
			) );
			if ( empty( $posts ) ) {
				continue;
			}
			$out .= "## " . $obj->labels->name . "\n\n";
			foreach ( $posts as $p ) {
				if ( in_array( (int) $p->ID, array_map( 'intval', $featured ), true ) ) {
					continue;
				}
				$out .= sprintf(
					"- [%s](%s): %s\n",
					$this->md_escape( (string) get_the_title( $p ) ),
					(string) get_permalink( $p ),
					$this->md_escape( $this->one_liner( $p ) )
				);
			}
			$out .= "\n";
		}

		// Pointers.
		$out .= "## Resources\n\n";
		$out .= "- [Sitemap](" . home_url( '/sitemap.xml' ) . ")\n";
		if ( (bool) PLSEO_Options::get( 'llms_full_enabled', true ) ) {
			$out .= "- [Full content](" . home_url( '/llms-full.txt' ) . ")\n";
		}

		set_transient( self::CACHE_KEY_SUMMARY, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Cornerstone-marked post IDs across the configured post types.
	 *
	 * @return array<int,int>
	 */
	private function cornerstone_post_ids(): array {
		$types = (array) PLSEO_Options::get( 'llms_txt_include_post_types', array( 'post', 'page' ) );
		if ( empty( $types ) ) {
			return array();
		}
		$q = new \WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_plseo_cornerstone', 'value' => '1' ),
			),
		) );
		return array_map( 'intval', $q->posts );
	}

	private function intro(): string {
		$custom = trim( (string) PLSEO_Options::get( 'llms_txt_intro', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$tag = trim( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tag ) {
			return $tag;
		}
		return sprintf( '%s — content summary for AI assistants.', (string) get_bloginfo( 'name' ) );
	}

	private function one_liner( \WP_Post $p ): string {
		$override = (string) plseo_get_post_meta( $p->ID, 'description', '' );
		if ( '' !== $override ) {
			return $this->clip( $override, 160 );
		}
		$source = '' !== $p->post_excerpt ? $p->post_excerpt : wp_strip_all_tags( (string) apply_filters( 'the_content', $p->post_content ) );
		return $this->clip( trim( preg_replace( '/\s+/u', ' ', $source ) ?? '' ), 160 );
	}

	/* ───────────────────────── full ───────────────────────── */

	private function full(): string {
		$cached = get_transient( self::CACHE_KEY_FULL );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$out   = '# ' . (string) get_bloginfo( 'name' ) . " — Full content for AI assistants\n\n";
		$out  .= '> ' . $this->intro() . "\n\n";
		$out  .= "_Generated " . gmdate( 'Y-m-d H:i' ) . " UTC. " . esc_html__( 'Each post below is delimited by a horizontal rule.', 'perrylabs-seo' ) . "_\n\n";

		$types = (array) PLSEO_Options::get( 'llms_txt_include_post_types', array( 'post', 'page' ) );
		foreach ( $types as $type ) {
			$posts = get_posts( array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_plseo_noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_plseo_noindex', 'value' => '1', 'compare' => '!=' ),
				),
			) );
			foreach ( $posts as $p ) {
				$out .= "---\n\n";
				$out .= "# " . $this->md_escape( (string) get_the_title( $p ) ) . "\n\n";
				$out .= "_URL: " . (string) get_permalink( $p ) . "_\n";
				$out .= "_Updated: " . (string) get_the_modified_date( 'c', $p ) . "_\n\n";
				$out .= $this->post_to_markdown( $p );
				$out .= "\n\n";
			}
		}

		set_transient( self::CACHE_KEY_FULL, $out, self::CACHE_TTL );
		return $out;
	}

	/** Render a post to safe markdown-ish plain text (headings preserved). */
	private function post_to_markdown( \WP_Post $post ): string {
		$html = (string) apply_filters( 'the_content', $post->post_content );
		// Convert headings.
		$html = preg_replace_callback(
			'#<h([1-6])[^>]*>(.*?)</h\1>#is',
			static function ( array $m ): string {
				$level = (int) $m[1];
				return str_repeat( '#', $level ) . ' ' . trim( wp_strip_all_tags( $m[2] ) ) . "\n\n";
			},
			$html
		) ?? $html;
		// Convert links to "[text](url)".
		$html = preg_replace_callback(
			'#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static fn( array $m ): string => '[' . trim( wp_strip_all_tags( $m[2] ) ) . '](' . $m[1] . ')',
			$html
		) ?? $html;
		// Convert paragraphs and breaks to newlines.
		$html = preg_replace( '#</p>#i', "\n\n", $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html ) ?? $html;
		// Strip remaining tags.
		$text = wp_strip_all_tags( $html );
		// Collapse run-on whitespace but keep paragraph breaks.
		$text = preg_replace( "/[ \t]+/u", ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;
		return trim( $text );
	}

	private function md_escape( string $s ): string {
		// Strip newlines that would break list rows.
		return trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
	}

	private function clip( string $s, int $max ): string {
		return PLSEO_Str::clip( $s, $max );
	}

	public function bust_cache(): void {
		delete_transient( self::CACHE_KEY_SUMMARY );
		delete_transient( self::CACHE_KEY_FULL );
	}
}
