<?php
/**
 * PerryLabs SEO + AEO — llms.txt / llms-full.txt Endpoint
 *
 * Generates machine-readable content files following the llms.txt
 * specification, enabling LLMs and AI agents to discover and consume
 * site content in a structured plain-text format.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Llms_Txt {

	/** @var string Transient key for cached llms.txt output. */
	private const CACHE_KEY = 'perrylabs_seo_llms_txt';

	/** @var string Transient key for cached llms-full.txt output. */
	private const CACHE_KEY_FULL = 'perrylabs_seo_llms_full_txt';

	/** @var int Cache TTL in seconds (1 hour). */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/** @var int Maximum posts to include in llms-full.txt. */
	private const FULL_TXT_LIMIT = 100;

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
		add_action( 'save_post', array( $this, 'bust_cache' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Rewrite Rules
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Register rewrite rules for llms.txt and llms-full.txt.
	 *
	 * Called on `init` and available as a static method for the
	 * plugin activation hook.
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^llms\.txt$',
			'index.php?perrylabs_llms_txt=summary',
			'top'
		);

		add_rewrite_rule(
			'^llms-full\.txt$',
			'index.php?perrylabs_llms_txt=full',
			'top'
		);
	}

	/**
	 * Register custom query variables.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'perrylabs_llms_txt';
		return $vars;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Render
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Intercept the request and output the appropriate text file.
	 */
	public function render(): void {
		$mode = get_query_var( 'perrylabs_llms_txt' );

		if ( ! $mode ) {
			return;
		}

		// Check if the feature is enabled.
		if ( ! perrylabs_seo_get_option( 'llms_txt_enabled', true ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$is_full    = ( 'full' === $mode );
		$cache_key  = $is_full ? self::CACHE_KEY_FULL : self::CACHE_KEY;
		$output     = get_transient( $cache_key );

		if ( false === $output ) {
			$output = $is_full ? $this->build_full() : $this->build_summary();
			set_transient( $cache_key, $output, self::CACHE_TTL );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text output.
		exit;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Build Output: Summary (llms.txt)
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Build the llms.txt summary output.
	 *
	 * @return string
	 */
	private function build_summary(): string {
		$lines = $this->build_header();

		$grouped = $this->get_grouped_posts( -1 );

		foreach ( $grouped as $post_type => $posts ) {
			$label   = $this->get_post_type_label( $post_type );
			$lines[] = '';
			$lines[] = "## {$label}";

			foreach ( $posts as $post ) {
				$description = $this->get_description( $post );
				$url         = get_permalink( $post );
				$title       = get_the_title( $post );

				$line = "- [{$title}]({$url})";
				if ( $description ) {
					$line .= ": {$description}";
				}
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/* ──────────────────────────────────────────────────────────────
	 * Build Output: Full (llms-full.txt)
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Build the llms-full.txt full-content output.
	 *
	 * @return string
	 */
	private function build_full(): string {
		$lines = $this->build_header();

		$limit   = (int) perrylabs_seo_get_option( 'llms_txt_limit', self::FULL_TXT_LIMIT );
		$grouped = $this->get_grouped_posts( $limit ?: self::FULL_TXT_LIMIT );

		foreach ( $grouped as $post_type => $posts ) {
			$label   = $this->get_post_type_label( $post_type );
			$lines[] = '';
			$lines[] = "## {$label}";

			foreach ( $posts as $post ) {
				$url     = get_permalink( $post );
				$title   = get_the_title( $post );
				$content = $this->get_plain_text_content( $post );

				$lines[] = '';
				$lines[] = "### {$title}";
				$lines[] = "URL: {$url}";
				$lines[] = '';
				$lines[] = $content;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/* ──────────────────────────────────────────────────────────────
	 * Header
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Build the shared header lines.
	 *
	 * @return array
	 */
	private function build_header(): array {
		$site_name = get_bloginfo( 'name' );
		$tagline   = get_bloginfo( 'description' );

		$lines   = array();
		$lines[] = "# {$site_name}";

		if ( $tagline ) {
			$lines[] = '';
			$lines[] = "> {$tagline}";
		}

		return $lines;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Query Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Get published posts grouped by post type, excluding noindex items.
	 *
	 * @param int $limit Maximum number of posts (-1 for unlimited).
	 * @return array Associative array keyed by post type slug.
	 */
	private function get_grouped_posts( int $limit ): array {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		// Remove 'attachment' — not useful for llms.txt.
		unset( $post_types['attachment'] );

		if ( empty( $post_types ) ) {
			return array();
		}

		$args = array(
			'post_type'      => array_values( $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'relation' => 'OR',
					array(
						'key'     => '_perrylabs_seo_noindex',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_perrylabs_seo_noindex',
						'value'   => '1',
						'compare' => '!=',
					),
				),
			),
			'no_found_rows'  => true,
		);

		$query   = new WP_Query( $args );
		$grouped = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$grouped[ $post->post_type ][] = $post;
			}
		}

		// Sort groups so 'page' comes first, then by post type label.
		uksort( $grouped, function ( $a, $b ) {
			if ( 'page' === $a ) {
				return -1;
			}
			if ( 'page' === $b ) {
				return 1;
			}
			return strcmp(
				$this->get_post_type_label( $a ),
				$this->get_post_type_label( $b )
			);
		} );

		return $grouped;
	}

	/**
	 * Get a human-readable plural label for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function get_post_type_label( string $post_type ): string {
		$obj = get_post_type_object( $post_type );

		if ( $obj && ! empty( $obj->labels->name ) ) {
			return $obj->labels->name;
		}

		return ucfirst( $post_type );
	}

	/**
	 * Get the meta description or excerpt for a post.
	 *
	 * Prefers the PerryLabs SEO meta description, falls back to
	 * the post excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Single-line description.
	 */
	private function get_description( WP_Post $post ): string {
		// Try PerryLabs SEO meta description first.
		$meta = get_post_meta( $post->ID, '_perrylabs_seo_description', true );

		if ( $meta ) {
			return $this->single_line( $meta );
		}

		// Fall back to excerpt.
		if ( $post->post_excerpt ) {
			return $this->single_line( $post->post_excerpt );
		}

		return '';
	}

	/**
	 * Convert post content to plain text.
	 *
	 * Strips HTML, shortcodes, and decodes entities.
	 *
	 * @param WP_Post $post Post object.
	 * @return string Plain-text content.
	 */
	private function get_plain_text_content( WP_Post $post ): string {
		$content = $post->post_content;

		// Run shortcodes so their output is included.
		$content = do_shortcode( $content );

		// Strip HTML tags.
		$content = wp_strip_all_tags( $content );

		// Decode HTML entities.
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize whitespace: collapse multiple blank lines.
		$content = preg_replace( '/\n{3,}/', "\n\n", $content );

		return trim( $content );
	}

	/**
	 * Collapse a string to a single line.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private function single_line( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Cache Busting
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Delete both transients when any post is saved.
	 *
	 * @param int $post_id Post ID (unused but required by hook).
	 */
	public function bust_cache( int $post_id ): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::CACHE_KEY_FULL );
	}
}
