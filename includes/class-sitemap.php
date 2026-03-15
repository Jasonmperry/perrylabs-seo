<?php
/**
 * PerryLabs SEO + AEO — XML Sitemap
 *
 * Auto-generates a sitemap index at /sitemap.xml with sub-sitemaps
 * for each configured post type and taxonomy. Uses rewrite rules
 * pointing to a virtual endpoint — no actual files on disk.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Sitemap {

	/** @var int Maximum URLs per sub-sitemap. */
	private const URLS_PER_SITEMAP = 1000;

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render_sitemap' ) );

		// Disable the built-in WordPress sitemap (WP 5.5+).
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Rewrite rules
	 * ────────────────────────────────────────────────────────────── */

	public static function register_rewrite_rules(): void {
		// Main sitemap index.
		add_rewrite_rule(
			'^sitemap\.xml$',
			'index.php?perrylabs_sitemap=index',
			'top'
		);

		// Sub-sitemaps: post type.
		add_rewrite_rule(
			'^sitemap-([a-z0-9_-]+)-?(\d*)\.xml$',
			'index.php?perrylabs_sitemap=posts&perrylabs_sitemap_type=$matches[1]&perrylabs_sitemap_page=$matches[2]',
			'top'
		);

		// Sub-sitemaps: taxonomy.
		add_rewrite_rule(
			'^sitemap-tax-([a-z0-9_-]+)\.xml$',
			'index.php?perrylabs_sitemap=taxonomy&perrylabs_sitemap_tax=$matches[1]',
			'top'
		);
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'perrylabs_sitemap';
		$vars[] = 'perrylabs_sitemap_type';
		$vars[] = 'perrylabs_sitemap_tax';
		$vars[] = 'perrylabs_sitemap_page';
		return $vars;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Render sitemap
	 * ────────────────────────────────────────────────────────────── */

	public function render_sitemap(): void {
		$sitemap = get_query_var( 'perrylabs_sitemap' );
		if ( ! $sitemap ) {
			return;
		}

		// Set XML headers.
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		switch ( $sitemap ) {
			case 'index':
				echo $this->generate_index();
				break;

			case 'posts':
				$post_type = get_query_var( 'perrylabs_sitemap_type', 'post' );
				$page      = max( 1, (int) get_query_var( 'perrylabs_sitemap_page', 1 ) );
				echo $this->generate_post_type_sitemap( $post_type, $page );
				break;

			case 'taxonomy':
				$taxonomy = get_query_var( 'perrylabs_sitemap_tax', '' );
				echo $this->generate_taxonomy_sitemap( $taxonomy );
				break;

			default:
				status_header( 404 );
				echo '<?xml version="1.0" encoding="UTF-8"?><error>Not found</error>';
				break;
		}

		exit;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Sitemap Index
	 * ────────────────────────────────────────────────────────────── */

	private function generate_index(): string {
		$output  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$output .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		// Post type sub-sitemaps.
		$post_types = $this->get_sitemap_post_types();
		foreach ( $post_types as $post_type ) {
			$count = $this->count_posts( $post_type );
			$pages = max( 1, (int) ceil( $count / self::URLS_PER_SITEMAP ) );

			for ( $page = 1; $page <= $pages; $page++ ) {
				$suffix = $pages > 1 ? '-' . $page : '';
				$output .= '<sitemap>' . "\n";
				$output .= '  <loc>' . esc_url( home_url( '/sitemap-' . $post_type . $suffix . '.xml' ) ) . '</loc>' . "\n";
				$output .= '  <lastmod>' . $this->get_last_modified_date( $post_type ) . '</lastmod>' . "\n";
				$output .= '</sitemap>' . "\n";
			}
		}

		// Taxonomy sub-sitemaps.
		$taxonomies = $this->get_sitemap_taxonomies();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 1,
			) );

			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$output .= '<sitemap>' . "\n";
				$output .= '  <loc>' . esc_url( home_url( '/sitemap-tax-' . $taxonomy . '.xml' ) ) . '</loc>' . "\n";
				$output .= '</sitemap>' . "\n";
			}
		}

		$output .= '</sitemapindex>';

		return $output;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Post type sitemap
	 * ────────────────────────────────────────────────────────────── */

	private function generate_post_type_sitemap( string $post_type, int $page = 1 ): string {
		$output  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$output .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$output .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

		// Front page.
		if ( $post_type === 'page' && $page === 1 ) {
			$output .= '<url>' . "\n";
			$output .= '  <loc>' . esc_url( home_url( '/' ) ) . '</loc>' . "\n";
			$output .= '  <changefreq>daily</changefreq>' . "\n";
			$output .= '  <priority>1.0</priority>' . "\n";
			$output .= '</url>' . "\n";
		}

		$offset = ( $page - 1 ) * self::URLS_PER_SITEMAP;

		$posts = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => self::URLS_PER_SITEMAP,
			'offset'         => $offset,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => array(
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
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			$modified  = get_the_modified_date( 'c', $post );

			// Priority based on post type.
			$priority = match ( $post->post_type ) {
				'page'                => '0.8',
				'biobuzz_news'        => '0.7',
				'biobuzz_event'       => '0.7',
				'biobuzz_contributor' => '0.5',
				default               => '0.6',
			};

			$output .= '<url>' . "\n";
			$output .= '  <loc>' . esc_url( $permalink ) . '</loc>' . "\n";
			$output .= '  <lastmod>' . esc_html( $modified ) . '</lastmod>' . "\n";
			$output .= '  <priority>' . $priority . '</priority>' . "\n";

			// Include featured image.
			$image_url = get_the_post_thumbnail_url( $post->ID, 'large' );
			if ( $image_url ) {
				$output .= '  <image:image>' . "\n";
				$output .= '    <image:loc>' . esc_url( $image_url ) . '</image:loc>' . "\n";
				$image_title = get_post_meta( get_post_thumbnail_id( $post->ID ), '_wp_attachment_image_alt', true ) ?: $post->post_title;
				$output .= '    <image:title>' . esc_html( $image_title ) . '</image:title>' . "\n";
				$output .= '  </image:image>' . "\n";
			}

			$output .= '</url>' . "\n";
		}

		$output .= '</urlset>';

		return $output;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Taxonomy sitemap
	 * ────────────────────────────────────────────────────────────── */

	private function generate_taxonomy_sitemap( string $taxonomy ): string {
		$output  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$output .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => self::URLS_PER_SITEMAP,
		) );

		if ( is_wp_error( $terms ) ) {
			$output .= '</urlset>';
			return $output;
		}

		foreach ( $terms as $term ) {
			$term_link = get_term_link( $term );
			if ( is_wp_error( $term_link ) ) {
				continue;
			}

			$output .= '<url>' . "\n";
			$output .= '  <loc>' . esc_url( $term_link ) . '</loc>' . "\n";
			$output .= '</url>' . "\n";
		}

		$output .= '</urlset>';

		return $output;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────── */

	private function get_sitemap_post_types(): array {
		$configured = perrylabs_seo_get_option( 'sitemap_post_types', array( 'post', 'page', 'biobuzz_news', 'biobuzz_event', 'biobuzz_contributor' ) );
		return array_filter( $configured, function ( $pt ) {
			return post_type_exists( $pt );
		} );
	}

	private function get_sitemap_taxonomies(): array {
		$configured = perrylabs_seo_get_option( 'sitemap_taxonomies', array( 'category', 'post_tag', 'biobuzz_region', 'biobuzz_article_cat', 'biobuzz_event_cat' ) );
		return array_filter( $configured, function ( $tax ) {
			return taxonomy_exists( $tax );
		} );
	}

	private function count_posts( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return (int) ( $counts->publish ?? 0 );
	}

	private function get_last_modified_date( string $post_type ): string {
		global $wpdb;

		$date = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_modified_gmt DESC LIMIT 1",
			$post_type
		) );

		if ( $date ) {
			return gmdate( 'c', strtotime( $date ) );
		}

		return gmdate( 'c' );
	}
}
