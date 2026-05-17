<?php
/**
 * PLSEO_Sitemap — sitemap index + per-type sub-sitemaps + image extensions.
 *
 * URL layout:
 *   /sitemap.xml                     → index referencing all sub-sitemaps
 *   /sitemap-{post_type}.xml         → URLs of one post type (paginated to 500/page)
 *   /sitemap-{post_type}-{page}.xml  → subsequent pages
 *   /sitemap-tax-{taxonomy}.xml      → URLs of one taxonomy
 *   /sitemap-author.xml              → author archives (when not noindexed)
 *
 * Disables WordPress core's wp_sitemaps so the two don't compete.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Sitemap {

	private static ?self $instance = null;

	private const PER_PAGE = 500;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'filter_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );

		// Suppress WP core sitemaps to avoid conflict.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// Bust the index when content changes.
		add_action( 'save_post', array( $this, 'bust_cache' ) );
		add_action( 'deleted_post', array( $this, 'bust_cache' ) );
	}

	public static function register_rewrites(): void {
		add_rewrite_rule( '^sitemap\.xml$',            'index.php?plseo_sitemap=index',  'top' );
		add_rewrite_rule( '^sitemap-news\.xml$',       'index.php?plseo_sitemap=news',   'top' );
		add_rewrite_rule( '^sitemap-videos\.xml$',     'index.php?plseo_sitemap=videos', 'top' );
		add_rewrite_rule( '^sitemap-tax-([^/]+)\.xml$', 'index.php?plseo_sitemap=tax&plseo_sitemap_obj=$matches[1]', 'top' );
		add_rewrite_rule( '^sitemap-author\.xml$',     'index.php?plseo_sitemap=author', 'top' );
		add_rewrite_rule( '^sitemap-([a-z0-9_\-]+)-(\d+)\.xml$', 'index.php?plseo_sitemap=type&plseo_sitemap_obj=$matches[1]&plseo_sitemap_page=$matches[2]', 'top' );
		add_rewrite_rule( '^sitemap-([a-z0-9_\-]+)\.xml$', 'index.php?plseo_sitemap=type&plseo_sitemap_obj=$matches[1]&plseo_sitemap_page=1', 'top' );
	}

	public function filter_query_vars( array $vars ): array {
		$vars[] = 'plseo_sitemap';
		$vars[] = 'plseo_sitemap_obj';
		$vars[] = 'plseo_sitemap_page';
		return $vars;
	}

	public function maybe_render(): void {
		$which = (string) get_query_var( 'plseo_sitemap' );
		if ( '' === $which ) {
			return;
		}
		if ( ! (bool) PLSEO_Options::get( 'sitemap_enabled', true ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );

		switch ( $which ) {
			case 'index':
				echo $this->render_index(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'type':
				echo $this->render_type( (string) get_query_var( 'plseo_sitemap_obj' ), max( 1, (int) get_query_var( 'plseo_sitemap_page' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'tax':
				echo $this->render_taxonomy( (string) get_query_var( 'plseo_sitemap_obj' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'author':
				echo $this->render_authors(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'news':
				echo $this->render_news(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'videos':
				echo $this->render_videos(); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				status_header( 404 );
		}
		exit;
	}

	/* ───────────────────────── INDEX ───────────────────────── */

	private function render_index(): string {
		$entries     = array();
		$post_types  = $this->configured_post_types();
		$taxonomies  = $this->configured_taxonomies();

		foreach ( $post_types as $type ) {
			$count = $this->count_published( $type );
			if ( $count < 1 ) {
				continue;
			}
			$pages = (int) ceil( $count / self::PER_PAGE );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$slug      = 1 === $page ? $type : "{$type}-{$page}";
				$entries[] = array(
					'loc'     => home_url( "/sitemap-{$slug}.xml" ),
					'lastmod' => $this->latest_modified( $type ),
				);
			}
		}

		foreach ( $taxonomies as $tax ) {
			if ( $this->count_taxonomy_terms( $tax ) > 0 ) {
				$entries[] = array(
					'loc'     => home_url( "/sitemap-tax-{$tax}.xml" ),
					'lastmod' => current_time( 'c', true ),
				);
			}
		}

		if ( ! (bool) PLSEO_Options::get( 'noindex_authors', true ) ) {
			$entries[] = array(
				'loc'     => home_url( '/sitemap-author.xml' ),
				'lastmod' => current_time( 'c', true ),
			);
		}

		if ( (bool) PLSEO_Options::get( 'sitemap_news_enabled', false ) ) {
			$entries[] = array(
				'loc'     => home_url( '/sitemap-news.xml' ),
				'lastmod' => current_time( 'c', true ),
			);
		}

		if ( (bool) PLSEO_Options::get( 'sitemap_video_enabled', false ) ) {
			$entries[] = array(
				'loc'     => home_url( '/sitemap-videos.xml' ),
				'lastmod' => current_time( 'c', true ),
			);
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $entries as $e ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( $e['loc'] ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_html( $e['lastmod'] ) . "</lastmod>\n";
			$xml .= "\t</sitemap>\n";
		}
		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/* ───────────────────────── POST-TYPE PAGE ───────────────────────── */

	private function render_type( string $type, int $page ): string {
		if ( ! in_array( $type, $this->configured_post_types(), true ) ) {
			status_header( 404 );
			return '';
		}
		$exclude = (array) PLSEO_Options::get( 'sitemap_exclude_ids', array() );

		$args = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'post__not_in'   => array_map( 'intval', $exclude ),
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => '_plseo_noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_plseo_noindex', 'value' => '1', 'compare' => '!=' ),
				),
				array(
					'relation' => 'OR',
					array( 'key' => '_plseo_exclude_sitemap', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_plseo_exclude_sitemap', 'value' => '1', 'compare' => '!=' ),
				),
			),
		);
		$q = new \WP_Query( $args );

		$include_images = (bool) PLSEO_Options::get( 'sitemap_include_images', true );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $include_images ) {
			$xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		$xml .= '>' . "\n";

		while ( $q->have_posts() ) {
			$q->the_post();
			$post = get_post();
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) get_permalink( $post ) ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_html( (string) get_the_modified_date( 'c', $post ) ) . "</lastmod>\n";
			$xml .= "\t\t<changefreq>" . esc_html( $this->guess_changefreq( $post ) ) . "</changefreq>\n";
			$xml .= "\t\t<priority>" . esc_html( $this->guess_priority( $post ) ) . "</priority>\n";

			if ( $include_images ) {
				foreach ( $this->collect_post_images( $post ) as $img ) {
					$xml .= "\t\t<image:image>\n";
					$xml .= "\t\t\t<image:loc>" . esc_url( $img ) . "</image:loc>\n";
					$xml .= "\t\t</image:image>\n";
				}
			}

			$xml .= "\t</url>\n";
		}
		wp_reset_postdata();
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/* ───────────────────────── TAXONOMY ───────────────────────── */

	private function render_taxonomy( string $tax ): string {
		if ( ! in_array( $tax, $this->configured_taxonomies(), true ) ) {
			status_header( 404 );
			return '';
		}
		$terms = get_terms( array(
			'taxonomy'   => $tax,
			'hide_empty' => true,
		) );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$xml .= "\t<url>\n";
				$xml .= "\t\t<loc>" . esc_url( (string) $link ) . "</loc>\n";
				$xml .= "\t\t<changefreq>weekly</changefreq>\n";
				$xml .= "\t</url>\n";
			}
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/* ───────────────────────── AUTHOR ───────────────────────── */

	private function render_authors(): string {
		$users = get_users( array(
			'has_published_posts' => true,
			'fields'              => array( 'ID' ),
		) );

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $users as $u ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) get_author_posts_url( (int) $u->ID ) ) . "</loc>\n";
			$xml .= "\t\t<changefreq>weekly</changefreq>\n";
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/* ───────────────────────── NEWS (Google News) ───────────────────────── */

	/**
	 * Google News sitemap. Per Google's spec, only includes articles published
	 * within the last 48 hours. Empty when nothing fresh exists — that's correct
	 * and won't trigger an error in Search Console.
	 */
	private function render_news(): string {
		if ( ! (bool) PLSEO_Options::get( 'sitemap_news_enabled', false ) ) {
			status_header( 404 );
			return '';
		}
		$types       = (array) PLSEO_Options::get( 'sitemap_news_post_types', array( 'post' ) );
		$publication = trim( (string) PLSEO_Options::get( 'sitemap_news_publication', '' ) );
		if ( '' === $publication ) {
			$publication = (string) get_bloginfo( 'name' );
		}
		$language = (string) get_locale();
		$language = $language !== '' ? substr( str_replace( '_', '-', $language ), 0, 2 ) : 'en';

		$q = new \WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 1000, // Google's documented cap.
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array( array( 'after' => '48 hours ago' ) ),
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_plseo_noindex', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_plseo_noindex', 'value' => '1', 'compare' => '!=' ),
			),
		) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $q->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) get_permalink( $post ) ) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n";
			$xml .= "\t\t\t\t<news:name>" . esc_html( $publication ) . "</news:name>\n";
			$xml .= "\t\t\t\t<news:language>" . esc_html( $language ) . "</news:language>\n";
			$xml .= "\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . esc_html( (string) get_the_date( 'c', $post ) ) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . esc_html( (string) get_the_title( $post ) ) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n";
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	/* ───────────────────────── VIDEOS ───────────────────────── */

	/**
	 * Video sitemap. Includes any post in a configured post type that contains
	 * a recognized video URL (YouTube/Vimeo/native <video>) or has a per-post
	 * `_plseo_video_url` override. Schema metadata mirrors PLSEO_Schema_Content.
	 */
	private function render_videos(): string {
		if ( ! (bool) PLSEO_Options::get( 'sitemap_video_enabled', false ) ) {
			status_header( 404 );
			return '';
		}
		$types = $this->configured_post_types();

		$q = new \WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_plseo_noindex', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_plseo_noindex', 'value' => '1', 'compare' => '!=' ),
			),
		) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
		$xml .= '        xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		foreach ( $q->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$video_url = $this->detect_video_url( $post );
			if ( '' === $video_url ) {
				continue;
			}
			$thumb = $this->detect_video_thumb( $post, $video_url );
			$desc  = trim( (string) $post->post_excerpt );
			if ( '' === $desc ) {
				$desc = wp_strip_all_tags( (string) get_the_title( $post ) );
			}

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) get_permalink( $post ) ) . "</loc>\n";
			$xml .= "\t\t<video:video>\n";
			if ( '' !== $thumb ) {
				$xml .= "\t\t\t<video:thumbnail_loc>" . esc_url( $thumb ) . "</video:thumbnail_loc>\n";
			}
			$xml .= "\t\t\t<video:title>" . esc_html( (string) get_the_title( $post ) ) . "</video:title>\n";
			$xml .= "\t\t\t<video:description>" . esc_html( mb_substr( $desc, 0, 2000 ) ) . "</video:description>\n";
			$xml .= "\t\t\t<video:content_loc>" . esc_url( $video_url ) . "</video:content_loc>\n";
			$xml .= "\t\t\t<video:publication_date>" . esc_html( (string) get_the_date( 'c', $post ) ) . "</video:publication_date>\n";
			$xml .= "\t\t</video:video>\n";
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	private function detect_video_url( \WP_Post $post ): string {
		$override = (string) get_post_meta( $post->ID, '_plseo_video_url', true );
		if ( '' !== $override ) {
			return $override;
		}
		$content = (string) $post->post_content;
		if ( preg_match( '#https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w\-]+|youtu\.be/[\w\-]+|vimeo\.com/\d+)#i', $content, $m ) ) {
			return $m[0];
		}
		if ( preg_match( '/<video[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function detect_video_thumb( \WP_Post $post, string $video_url ): string {
		if ( preg_match( '#youtu(?:\.be/|be\.com/watch\?v=)([\w\-]+)#i', $video_url, $m ) ) {
			return sprintf( 'https://img.youtube.com/vi/%s/hqdefault.jpg', $m[1] );
		}
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'full' );
			if ( $src ) {
				return (string) $src[0];
			}
		}
		return '';
	}

	/* ───────────────────────── helpers ───────────────────────── */

	/** @return array<int,string> */
	private function configured_post_types(): array {
		$set = (array) PLSEO_Options::get( 'sitemap_post_types', array( 'post', 'page' ) );
		/**
		 * Filter the post types included in sitemap output.
		 *
		 * @param array<int,string> $set
		 */
		return array_values( array_filter( (array) apply_filters( 'plseo_sitemap_post_types', $set ) ) );
	}

	/** @return array<int,string> */
	private function configured_taxonomies(): array {
		$set = (array) PLSEO_Options::get( 'sitemap_taxonomies', array( 'category', 'post_tag' ) );
		/**
		 * Filter the taxonomies included in sitemap output.
		 *
		 * @param array<int,string> $set
		 */
		return array_values( array_filter( (array) apply_filters( 'plseo_sitemap_taxonomies', $set ) ) );
	}

	private function count_published( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private function count_taxonomy_terms( string $tax ): int {
		$n = wp_count_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
		return is_wp_error( $n ) ? 0 : (int) $n;
	}

	private function latest_modified( string $post_type ): string {
		$cache_key = 'plseo_last_mod_' . md5( $post_type );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$q = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		if ( empty( $q->posts ) ) {
			$result = current_time( 'c', true );
		} else {
			$result = (string) get_the_modified_date( 'c', (int) $q->posts[0] );
		}
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	private function guess_changefreq( \WP_Post $post ): string {
		$age_days = ( time() - get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS;
		if ( $age_days < 7 )  return 'daily';
		if ( $age_days < 60 ) return 'weekly';
		if ( $age_days < 365 ) return 'monthly';
		return 'yearly';
	}

	private function guess_priority( \WP_Post $post ): string {
		// Cornerstone-marked posts always take the top slot.
		if ( '1' === (string) get_post_meta( $post->ID, '_plseo_cornerstone', true ) ) {
			return '1.0';
		}
		if ( 'page' === $post->post_type && get_option( 'page_on_front' ) === (string) $post->ID ) {
			return '1.0';
		}
		if ( 'page' === $post->post_type ) {
			return '0.8';
		}
		$age_days = ( time() - get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS;
		if ( $age_days < 30 )  return '0.9';
		if ( $age_days < 365 ) return '0.6';
		return '0.4';
	}

	/**
	 * @return array<int,string>
	 */
	private function collect_post_images( \WP_Post $post ): array {
		$urls    = array();
		$thumb   = get_the_post_thumbnail_url( $post, 'full' );
		if ( is_string( $thumb ) && '' !== $thumb ) {
			$urls[] = $thumb;
		}
		// Pull <img src> from rendered content; cap at 5 to keep XML compact.
		$rendered = (string) apply_filters( 'the_content', $post->post_content );
		if ( preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\']#i', $rendered, $m ) ) {
			foreach ( array_slice( $m[1], 0, 5 ) as $u ) {
				if ( ! in_array( $u, $urls, true ) ) {
					$urls[] = $u;
				}
			}
		}
		return $urls;
	}

	public function bust_cache(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_plseo\\_last\\_mod\\_%' OR option_name LIKE '\\_transient\\_timeout\\_plseo\\_last\\_mod\\_%'" ); // phpcs:ignore WordPress.DB
	}
}
