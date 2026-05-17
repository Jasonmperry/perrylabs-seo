<?php
/**
 * PLSEO_Meta_Tags — title, description, canonical, OG, Twitter, hreflang, robots meta.
 *
 * Hooks into wp_head at priority 1 to print our tags before any third-party noise.
 * Per-post overrides (`_plseo_*` meta) always beat templated defaults.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Meta_Tags {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 15 );
		add_filter( 'document_title_separator', array( $this, 'filter_title_separator' ), 15 );
		add_action( 'wp_head', array( $this, 'output_head_tags' ), 1 );

		// Strip core's default robots meta when we're handling robots ourselves.
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );

		// Disable WP's emoji scripts when configured.
		if ( (bool) PLSEO_Options::get( 'remove_emoji_scripts', false ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
		}
	}

	/* ───────────────────────── title ───────────────────────── */

	public function filter_title_separator( string $sep ): string {
		return (string) PLSEO_Options::get( 'title_separator', $sep );
	}

	public function filter_document_title( string $title ): string {
		$resolved = $this->resolve_title();
		return '' !== $resolved ? $resolved : $title;
	}

	private function resolve_title(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return '';
			}
			$override = (string) plseo_get_post_meta( $post->ID, 'title', '' );
			if ( '' !== $override ) {
				return PLSEO_Template_Resolver::resolve(
					$override,
					PLSEO_Template_Resolver::context_for_post( $post )
				);
			}

			$tmpl_key = 'page' === $post->post_type ? 'title_template_page' : 'title_template_post';
			$tmpl     = (string) PLSEO_Options::get( $tmpl_key, '%post_title% %sep% %site_name%' );
			return PLSEO_Template_Resolver::resolve( $tmpl, PLSEO_Template_Resolver::context_for_post( $post ) );
		}

		if ( is_front_page() || is_home() ) {
			$tmpl = (string) PLSEO_Options::get( 'title_template_home', '%site_name% %sep% %site_tagline%' );
			return PLSEO_Template_Resolver::resolve( $tmpl );
		}

		if ( is_archive() || is_search() ) {
			$tmpl    = (string) PLSEO_Options::get( 'title_template_archive', '%archive_title% %sep% %site_name%' );
			$context = PLSEO_Template_Resolver::context_for_archive();
			if ( is_search() ) {
				$context['archive_title'] = sprintf( __( 'Search results for "%s"', 'perrylabs-seo' ), get_search_query() );
			}
			return PLSEO_Template_Resolver::resolve( $tmpl, $context );
		}

		return '';
	}

	/* ───────────────────────── description ───────────────────────── */

	private function resolve_description(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof \WP_Post ) {
				return '';
			}
			$override = (string) plseo_get_post_meta( $post->ID, 'description', '' );
			if ( '' !== $override ) {
				return $override;
			}
			$excerpt = (string) $post->post_excerpt;
			if ( '' !== $excerpt ) {
				return $this->clip( $excerpt );
			}
			return $this->clip( $this->first_paragraph( $post ) );
		}

		if ( is_front_page() || is_home() ) {
			$default = (string) PLSEO_Options::get( 'default_description', '' );
			return $default !== '' ? $default : (string) get_bloginfo( 'description' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && '' !== $term->description ) {
				return $this->clip( wp_strip_all_tags( $term->description ) );
			}
		}

		if ( is_author() ) {
			$author = get_queried_object();
			if ( $author instanceof \WP_User ) {
				$bio = get_user_meta( $author->ID, 'description', true );
				if ( $bio ) {
					return $this->clip( wp_strip_all_tags( (string) $bio ) );
				}
			}
		}

		return (string) PLSEO_Options::get( 'default_description', (string) get_bloginfo( 'description' ) );
	}

	private function first_paragraph( \WP_Post $post ): string {
		return PLSEO_Str::post_plain_text( $post );
	}

	private function clip( string $text, int $max = 160 ): string {
		return PLSEO_Str::clip( $text, $max );
	}

	/* ───────────────────────── robots ───────────────────────── */

	public function filter_robots( array $robots ): array {
		$noindex = $this->should_noindex();
		if ( $noindex ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'] );
			return $robots;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && plseo_get_post_meta( $post->ID, 'nofollow', '' ) === '1' ) {
				$robots['nofollow'] = true;
			}
		}

		return $robots;
	}

	private function should_noindex(): bool {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post && plseo_get_post_meta( $post->ID, 'noindex', '' ) === '1' ) {
				return true;
			}
			return false;
		}
		if ( is_search() ) {
			return (bool) PLSEO_Options::get( 'noindex_search', true );
		}
		if ( is_404() ) {
			return (bool) PLSEO_Options::get( 'noindex_404', true );
		}
		if ( is_author() ) {
			return (bool) PLSEO_Options::get( 'noindex_authors', true );
		}
		if ( is_category() ) {
			return (bool) PLSEO_Options::get( 'noindex_categories', false );
		}
		if ( is_tag() || is_tax() ) {
			return (bool) PLSEO_Options::get( 'noindex_tags', true );
		}
		if ( is_date() || is_year() || is_month() || is_day() ) {
			return (bool) PLSEO_Options::get( 'noindex_archives', false );
		}
		return false;
	}

	/* ───────────────────────── head output ───────────────────────── */

	public function output_head_tags(): void {
		// Description.
		$desc = $this->resolve_description();
		if ( '' !== $desc ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $desc ) );
		}

		// Canonical.
		$canonical = $this->resolve_canonical();
		if ( '' !== $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
		}

		// Hreflang alternates.
		$this->output_hreflang();

		// Webmaster verification.
		$this->output_verification();

		// Open Graph + Twitter.
		$this->output_open_graph( $desc, $canonical );
		$this->output_twitter_card( $desc );
	}

	private function resolve_canonical(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$override = (string) plseo_get_post_meta( $post->ID, 'canonical', '' );
				if ( '' !== $override ) {
					return $override;
				}
				return (string) get_permalink( $post );
			}
		}
		if ( is_front_page() ) {
			return (string) home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_string( $link ) ? $link : '';
		}
		return '';
	}

	private function output_hreflang(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Start with the per-post manual list (textarea / table UI).
		$alternates = array();
		$raw = (string) plseo_get_post_meta( $post->ID, 'hreflang', '' );
		if ( '' !== $raw ) {
			foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line || ! str_contains( $line, '|' ) ) {
					continue;
				}
				[ $lang, $url ] = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( '' === $lang || '' === $url ) {
					continue;
				}
				$alternates[] = array( 'lang' => $lang, 'url' => $url );
			}
		}

		/**
		 * Filter hreflang alternates. PLSEO_Multilang hooks here to add
		 * Polylang / WPML translations.
		 *
		 * @param array<int,array{lang:string,url:string}> $alternates
		 * @param \WP_Post                                  $post
		 */
		$alternates = (array) apply_filters( 'plseo_hreflang_alternates', $alternates, $post );

		if ( empty( $alternates ) ) {
			return;
		}

		// Add x-default when at least 2 languages are present and one looks like
		// English (a reasonable convention; users can disable via the filter).
		$has_xdefault = false;
		foreach ( $alternates as $a ) {
			if ( 'x-default' === strtolower( (string) $a['lang'] ) ) {
				$has_xdefault = true;
				break;
			}
		}
		if ( ! $has_xdefault && count( $alternates ) >= 2 ) {
			foreach ( $alternates as $a ) {
				if ( str_starts_with( strtolower( (string) $a['lang'] ), 'en' ) ) {
					$alternates[] = array( 'lang' => 'x-default', 'url' => $a['url'] );
					break;
				}
			}
		}

		foreach ( $alternates as $a ) {
			printf( "<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
				esc_attr( (string) $a['lang'] ),
				esc_url( (string) $a['url'] )
			);
		}
	}

	private function output_verification(): void {
		$map = array(
			'google_verification'      => 'google-site-verification',
			'bing_verification'        => 'msvalidate.01',
			'pinterest_verification'   => 'p:domain_verify',
			'yandex_verification'      => 'yandex-verification',
			'baidu_verification'       => 'baidu-site-verification',
			'apple_news_verification'  => 'apple-news-publisher',
			'cloudflare_verification'  => 'cf-2fa-verify',
			'norton_verification'      => 'norton-safeweb-site-verification',
		);
		foreach ( $map as $option_key => $meta_name ) {
			$val = trim( (string) PLSEO_Options::get( $option_key, '' ) );
			if ( '' !== $val ) {
				printf( "<meta name=\"%s\" content=\"%s\" />\n", esc_attr( $meta_name ), esc_attr( $val ) );
			}
		}

		// Mastodon rel-me (a <link>, not a <meta>) — establishes verified link
		// from Mastodon profile back to the site.
		$mastodon = trim( (string) PLSEO_Options::get( 'mastodon_url', '' ) );
		if ( '' !== $mastodon ) {
			printf( "<link rel=\"me\" href=\"%s\" />\n", esc_url( $mastodon ) );
		}
	}

	private function output_open_graph( string $description, string $canonical ): void {
		$title = $this->resolve_title();
		if ( '' === $title ) {
			$title = (string) wp_get_document_title();
		}
		$site_name = (string) get_bloginfo( 'name' );
		$type      = is_singular() && ! is_front_page() ? 'article' : 'website';
		$locale    = trim( (string) PLSEO_Options::get( 'og_locale', '' ) );
		if ( '' === $locale ) {
			$locale = str_replace( '-', '_', (string) get_locale() );
		}

		printf( "<meta property=\"og:type\" content=\"%s\" />\n", esc_attr( $type ) );
		printf( "<meta property=\"og:site_name\" content=\"%s\" />\n", esc_attr( $site_name ) );
		printf( "<meta property=\"og:locale\" content=\"%s\" />\n", esc_attr( $locale ) );
		if ( '' !== $title ) {
			printf( "<meta property=\"og:title\" content=\"%s\" />\n", esc_attr( $title ) );
		}
		if ( '' !== $description ) {
			printf( "<meta property=\"og:description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		if ( '' !== $canonical ) {
			printf( "<meta property=\"og:url\" content=\"%s\" />\n", esc_url( $canonical ) );
		}

		$image = $this->resolve_social_image();
		if ( '' !== $image ) {
			printf( "<meta property=\"og:image\" content=\"%s\" />\n", esc_url( $image ) );
		}

		if ( 'article' === $type && is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				printf(
					"<meta property=\"article:published_time\" content=\"%s\" />\n",
					esc_attr( (string) get_the_date( 'c', $post ) )
				);
				printf(
					"<meta property=\"article:modified_time\" content=\"%s\" />\n",
					esc_attr( (string) get_the_modified_date( 'c', $post ) )
				);
				$author = $post->post_author ? get_userdata( (int) $post->post_author ) : false;
				if ( $author ) {
					printf( "<meta property=\"article:author\" content=\"%s\" />\n", esc_attr( $author->display_name ) );
				}
			}
		}
	}

	private function output_twitter_card( string $description ): void {
		$card   = (string) PLSEO_Options::get( 'twitter_card_type', 'summary_large_image' );
		$handle = trim( (string) PLSEO_Options::get( 'twitter_handle', '' ) );
		$title  = $this->resolve_title();

		printf( "<meta name=\"twitter:card\" content=\"%s\" />\n", esc_attr( $card ) );
		if ( '' !== $handle ) {
			$handle = '@' . ltrim( $handle, '@' );
			printf( "<meta name=\"twitter:site\" content=\"%s\" />\n", esc_attr( $handle ) );
		}
		if ( '' !== $title ) {
			printf( "<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr( $title ) );
		}
		if ( '' !== $description ) {
			printf( "<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		$image = $this->resolve_social_image();
		if ( '' !== $image ) {
			printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url( $image ) );
		}
	}

	private function resolve_social_image(): string {
		$post = null;
		if ( is_singular() ) {
			$post = get_queried_object();
		}

		if ( $post instanceof \WP_Post ) {
			$override = (string) plseo_get_post_meta( $post->ID, 'social_image', '' );
			if ( '' !== $override ) {
				return $override;
			}
			$thumb = get_the_post_thumbnail_url( $post, 'full' );
			if ( is_string( $thumb ) && '' !== $thumb ) {
				return $thumb;
			}
		}

		$default = (string) PLSEO_Options::get( 'default_social_image', '' );

		/**
		 * Fallback opportunity: the OG image generator hooks here to render
		 * a per-post card when no image is available anywhere else.
		 *
		 * @param string        $default Default social image URL (may be empty).
		 * @param \WP_Post|null $post    Queried post when singular.
		 */
		return (string) apply_filters( 'plseo_resolve_social_image', $default, $post );
	}
}
