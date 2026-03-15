<?php
/**
 * PerryLabs SEO + AEO — Meta Tag Output
 *
 * Outputs meta title, description, canonical, robots, Open Graph, and
 * Twitter Card tags via wp_head. Handles singular, archive, and
 * taxonomy contexts.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Meta_Tags {

	public function __construct() {
		// Filter the document title (WordPress 4.1+).
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 15 );
		add_filter( 'document_title_separator', array( $this, 'filter_title_separator' ) );

		// Output meta tags in <head>.
		add_action( 'wp_head', array( $this, 'output_meta_tags' ), 1 );

		// Remove default canonical to avoid duplicates.
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Document title
	 * ────────────────────────────────────────────────────────────── */

	public function filter_document_title( string $title ): string {
		if ( is_singular() ) {
			$post      = get_queried_object();
			$seo_title = $post ? get_post_meta( $post->ID, '_perrylabs_seo_title', true ) : '';

			if ( $seo_title ) {
				$separator = perrylabs_seo_get_option( 'title_separator', '|' );
				$site_name = get_bloginfo( 'name' );

				return $this->resolve_template_tags( $seo_title, $post->post_title, $site_name, $separator );
			}
		}

		return $title;
	}

	public function filter_title_separator( string $sep ): string {
		return perrylabs_seo_get_option( 'title_separator', '|' );
	}

	/**
	 * Replace template tags in title string.
	 */
	private function resolve_template_tags( string $template, string $post_title, string $site_name, string $separator ): string {
		if ( strpos( $template, '%' ) !== false ) {
			return str_replace(
				array( '%title%', '%sitename%', '%sep%' ),
				array( $post_title, $site_name, $separator ),
				$template
			);
		}

		// No template tags — append site name.
		return $template . ' ' . $separator . ' ' . $site_name;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Meta tag output
	 * ────────────────────────────────────────────────────────────── */

	public function output_meta_tags(): void {
		echo "\n<!-- PerryLabs SEO + AEO -->\n";

		$this->output_description();
		$this->output_canonical();
		$this->output_robots();
		$this->output_open_graph();
		$this->output_twitter_card();

		echo "<!-- /PerryLabs SEO + AEO -->\n\n";
	}

	/* ──────────────────────────────────────────────────────────────
	 * Meta description
	 * ────────────────────────────────────────────────────────────── */

	private function output_description(): void {
		$description = $this->get_description();
		if ( $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}

	private function get_description(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return '';
			}

			$desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
			if ( $desc ) {
				return $desc;
			}

			// Fallback: excerpt or trimmed content.
			$fallback = $post->post_excerpt ?: $post->post_content;
			return wp_trim_words( strip_shortcodes( wp_strip_all_tags( $fallback ) ), 25, '...' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && $term->description ) {
				return wp_trim_words( $term->description, 25, '...' );
			}
		}

		if ( is_home() || is_front_page() ) {
			return perrylabs_seo_get_option( 'default_description', get_bloginfo( 'description' ) );
		}

		return perrylabs_seo_get_option( 'default_description', get_bloginfo( 'description' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Canonical URL
	 * ────────────────────────────────────────────────────────────── */

	private function output_canonical(): void {
		$canonical = $this->get_canonical();
		if ( $canonical ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $canonical ) );
		}
	}

	private function get_canonical(): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return '';
			}

			$override = get_post_meta( $post->ID, '_perrylabs_seo_canonical', true );
			if ( $override ) {
				return $override;
			}

			return (string) wp_get_canonical_url( $post );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term ? get_term_link( $term ) : '';
		}

		if ( is_home() && ! is_front_page() ) {
			return (string) get_permalink( get_option( 'page_for_posts' ) );
		}

		if ( is_front_page() ) {
			return home_url( '/' );
		}

		if ( is_post_type_archive() ) {
			return (string) get_post_type_archive_link( get_queried_object()->name ?? '' );
		}

		return '';
	}

	/* ──────────────────────────────────────────────────────────────
	 * Robots meta
	 * ────────────────────────────────────────────────────────────── */

	private function output_robots(): void {
		$directives = array();

		// Per-post overrides.
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				if ( get_post_meta( $post->ID, '_perrylabs_seo_noindex', true ) ) {
					$directives[] = 'noindex';
				}
				if ( get_post_meta( $post->ID, '_perrylabs_seo_nofollow', true ) ) {
					$directives[] = 'nofollow';
				}
			}
		}

		// Global archive defaults.
		if ( is_date() && perrylabs_seo_get_option( 'noindex_archives', false ) ) {
			$directives[] = 'noindex';
		}
		if ( is_category() && perrylabs_seo_get_option( 'noindex_categories', false ) ) {
			$directives[] = 'noindex';
		}
		if ( is_tag() && perrylabs_seo_get_option( 'noindex_tags', false ) ) {
			$directives[] = 'noindex';
		}
		if ( is_author() && perrylabs_seo_get_option( 'noindex_authors', true ) ) {
			$directives[] = 'noindex';
		}

		// Paginated pages.
		if ( is_paged() ) {
			$directives[] = 'noindex';
		}

		// Search results should never be indexed.
		if ( is_search() ) {
			$directives[] = 'noindex';
		}

		$directives = array_unique( $directives );

		if ( ! empty( $directives ) ) {
			printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( implode( ', ', $directives ) ) );
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Open Graph
	 * ────────────────────────────────────────────────────────────── */

	private function output_open_graph(): void {
		$tags = array();

		$tags['og:site_name'] = get_bloginfo( 'name' );
		$tags['og:locale']    = get_locale();

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return;
			}

			$separator = perrylabs_seo_get_option( 'title_separator', '|' );
			$site_name = get_bloginfo( 'name' );

			$seo_title = get_post_meta( $post->ID, '_perrylabs_seo_title', true );
			if ( $seo_title ) {
				$tags['og:title'] = $this->resolve_template_tags( $seo_title, $post->post_title, $site_name, $separator );
			} else {
				$tags['og:title'] = $post->post_title;
			}

			$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
			$tags['og:description'] = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );

			$tags['og:url'] = $this->get_canonical() ?: get_permalink( $post );

			// Type: article for posts, website for pages.
			$article_types = array( 'post', 'biobuzz_news', 'biobuzz_event' );
			$tags['og:type'] = in_array( $post->post_type, $article_types, true ) ? 'article' : 'website';

			// Image: social image override > featured image > default.
			$tags['og:image'] = $this->get_social_image( $post->ID );

		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				$tags['og:title']       = $term->name;
				$tags['og:description'] = $term->description ? wp_trim_words( $term->description, 25, '...' ) : '';
				$tags['og:url']         = get_term_link( $term );
				$tags['og:type']        = 'website';
				$tags['og:image']       = perrylabs_seo_get_option( 'default_social_image', '' );
			}

		} else {
			// Home / archives.
			$tags['og:title']       = get_bloginfo( 'name' );
			$tags['og:description'] = $this->get_description();
			$tags['og:url']         = home_url( '/' );
			$tags['og:type']        = 'website';
			$tags['og:image']       = perrylabs_seo_get_option( 'default_social_image', '' );
		}

		foreach ( $tags as $property => $content ) {
			if ( $content ) {
				printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Twitter Card
	 * ────────────────────────────────────────────────────────────── */

	private function output_twitter_card(): void {
		$tags = array();
		$tags['twitter:card'] = 'summary_large_image';

		$twitter_handle = perrylabs_seo_get_option( 'twitter_handle', '' );
		if ( $twitter_handle ) {
			$tags['twitter:site'] = $twitter_handle;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return;
			}

			$separator = perrylabs_seo_get_option( 'title_separator', '|' );
			$site_name = get_bloginfo( 'name' );

			$seo_title = get_post_meta( $post->ID, '_perrylabs_seo_title', true );
			if ( $seo_title ) {
				$tags['twitter:title'] = $this->resolve_template_tags( $seo_title, $post->post_title, $site_name, $separator );
			} else {
				$tags['twitter:title'] = $post->post_title;
			}

			$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
			$tags['twitter:description'] = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );

			$tags['twitter:image'] = $this->get_social_image( $post->ID );

		} else {
			$tags['twitter:title']       = get_bloginfo( 'name' );
			$tags['twitter:description'] = $this->get_description();
			$tags['twitter:image']       = perrylabs_seo_get_option( 'default_social_image', '' );
		}

		foreach ( $tags as $name => $content ) {
			if ( $content ) {
				printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
			}
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Get the social sharing image for a post.
	 *
	 * Priority: social image override > featured image > default.
	 */
	private function get_social_image( int $post_id ): string {
		// 1. Per-post social image override.
		$social_image = get_post_meta( $post_id, '_perrylabs_seo_social_image', true );
		if ( $social_image ) {
			return $social_image;
		}

		// 2. Featured image.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$image = wp_get_attachment_image_url( $thumbnail_id, 'large' );
			if ( $image ) {
				return $image;
			}
		}

		// 3. Default from settings.
		return perrylabs_seo_get_option( 'default_social_image', '' );
	}
}
