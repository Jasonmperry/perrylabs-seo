<?php
/**
 * PerryLabs SEO + AEO — JSON-LD Structured Data
 *
 * Outputs schema.org structured data as JSON-LD in the <head>:
 * - Organization (site-wide)
 * - Article (biobuzz_news posts)
 * - Event (biobuzz_event posts)
 * - Person (biobuzz_contributor posts)
 * - WebPage (generic pages)
 * - WebSite with SearchAction (front page)
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Schema {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ), 2 );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Main output
	 * ────────────────────────────────────────────────────────────── */

	public function output_schema(): void {
		$schemas = array();

		// Site-wide Organization schema.
		$schemas[] = $this->get_organization_schema();

		// Front page: WebSite with SearchAction.
		if ( is_front_page() ) {
			$schemas[] = $this->get_website_schema();
		}

		// Singular content schemas.
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$schema = match ( $post->post_type ) {
					'biobuzz_news'        => $this->get_article_schema( $post ),
					'biobuzz_event'       => $this->get_event_schema( $post ),
					'biobuzz_contributor' => $this->get_person_schema( $post ),
					default               => $this->get_webpage_schema( $post ),
				};

				if ( $schema ) {
					$schemas[] = $schema;
				}
			}
		}

		// Filter out nulls.
		$schemas = array_filter( $schemas );

		if ( empty( $schemas ) ) {
			return;
		}

		// Output each schema block.
		foreach ( $schemas as $schema ) {
			echo '<script type="application/ld+json">' . "\n";
			echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			echo "\n</script>\n";
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Organization schema (site-wide)
	 * ────────────────────────────────────────────────────────────── */

	private function get_organization_schema(): array {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);

		// Logo.
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $logo_url ) {
				$schema['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				);
			}
		}

		// Social profiles.
		$same_as = array();

		$twitter = perrylabs_seo_get_option( 'twitter_handle', '' );
		if ( $twitter ) {
			$handle = ltrim( $twitter, '@' );
			$same_as[] = 'https://twitter.com/' . $handle;
		}

		$facebook = perrylabs_seo_get_option( 'facebook_url', '' );
		if ( $facebook ) {
			$same_as[] = $facebook;
		}

		$linkedin = perrylabs_seo_get_option( 'linkedin_url', '' );
		if ( $linkedin ) {
			$same_as[] = $linkedin;
		}

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * WebSite schema (front page)
	 * ────────────────────────────────────────────────────────────── */

	private function get_website_schema(): array {
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => get_bloginfo( 'name' ),
			'url'             => home_url( '/' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'        => 'EntryPoint',
					'urlTemplate'  => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Article schema (biobuzz_news)
	 * ────────────────────────────────────────────────────────────── */

	private function get_article_schema( \WP_Post $post ): array {
		$schema = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => $post->post_title,
			'url'              => get_permalink( $post ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
		);

		// Description.
		$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
		$description = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );
		if ( $description ) {
			$schema['description'] = $description;
		}

		// Image.
		$image_url = $this->get_schema_image( $post->ID );
		if ( $image_url ) {
			$schema['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image_url,
			);
		}

		// Author via contributor CPT.
		$contributor_id = (int) get_post_meta( $post->ID, '_biobuzz_contributor_id', true );
		if ( $contributor_id ) {
			$contributor = get_post( $contributor_id );
			if ( $contributor && $contributor->post_status === 'publish' ) {
				$first_name = get_post_meta( $contributor_id, '_biobuzz_contributor_first_name', true );
				$last_name  = get_post_meta( $contributor_id, '_biobuzz_contributor_last_name', true );
				$author_name = trim( $first_name . ' ' . $last_name ) ?: $contributor->post_title;

				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => $author_name,
					'url'   => get_permalink( $contributor ),
				);
			}
		}

		if ( ! isset( $schema['author'] ) ) {
			// Fallback to WP author.
			$author = get_userdata( $post->post_author );
			if ( $author ) {
				$schema['author'] = array(
					'@type' => 'Person',
					'name'  => $author->display_name,
				);
			}
		}

		// Publisher (Organization).
		$schema['publisher'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $logo_url ) {
				$schema['publisher']['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				);
			}
		}

		// Article section (category).
		$categories = get_the_terms( $post->ID, 'biobuzz_article_cat' );
		if ( $categories && ! is_wp_error( $categories ) ) {
			$schema['articleSection'] = $categories[0]->name;
		}

		// Keywords (tags).
		$tags = get_the_tags( $post->ID );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$schema['keywords'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
		}

		// Word count.
		$word_count = str_word_count( strip_shortcodes( wp_strip_all_tags( $post->post_content ) ) );
		if ( $word_count > 0 ) {
			$schema['wordCount'] = $word_count;
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Event schema (biobuzz_event)
	 * ────────────────────────────────────────────────────────────── */

	private function get_event_schema( \WP_Post $post ): array {
		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => $post->post_title,
			'url'      => get_permalink( $post ),
		);

		// Description.
		$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
		$description = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );
		if ( $description ) {
			$schema['description'] = $description;
		}

		// Image.
		$image_url = $this->get_schema_image( $post->ID );
		if ( $image_url ) {
			$schema['image'] = $image_url;
		}

		// Start date.
		$start_date = get_post_meta( $post->ID, '_biobuzz_event_start', true );
		if ( $start_date ) {
			$schema['startDate'] = $start_date;
		}

		// End date.
		$end_date = get_post_meta( $post->ID, '_biobuzz_event_end', true );
		if ( $end_date ) {
			$schema['endDate'] = $end_date;
		}

		// Location.
		$location = get_post_meta( $post->ID, '_biobuzz_event_location', true );
		if ( $location ) {
			// Detect if it's a virtual event (URL-like location).
			if ( filter_var( $location, FILTER_VALIDATE_URL ) ) {
				$schema['location'] = array(
					'@type' => 'VirtualLocation',
					'url'   => $location,
				);
				$schema['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
			} else {
				$schema['location'] = array(
					'@type'   => 'Place',
					'name'    => $location,
					'address' => array(
						'@type' => 'PostalAddress',
						'name'  => $location,
					),
				);
				$schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
			}
		}

		// Registration URL.
		$registration_url = get_post_meta( $post->ID, '_biobuzz_event_registration_url', true );
		if ( $registration_url ) {
			$schema['offers'] = array(
				'@type' => 'Offer',
				'url'   => $registration_url,
			);
		}

		// Event status (default: scheduled).
		$schema['eventStatus'] = 'https://schema.org/EventScheduled';

		// Organizer.
		$schema['organizer'] = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Person schema (biobuzz_contributor)
	 * ────────────────────────────────────────────────────────────── */

	private function get_person_schema( \WP_Post $post ): array {
		$first_name = get_post_meta( $post->ID, '_biobuzz_contributor_first_name', true );
		$last_name  = get_post_meta( $post->ID, '_biobuzz_contributor_last_name', true );
		$full_name  = trim( $first_name . ' ' . $last_name ) ?: $post->post_title;

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'name'     => $full_name,
			'url'      => get_permalink( $post ),
		);

		if ( $first_name ) {
			$schema['givenName'] = $first_name;
		}
		if ( $last_name ) {
			$schema['familyName'] = $last_name;
		}

		// Prefix (honorific).
		$prefix = get_post_meta( $post->ID, '_biobuzz_contributor_prefix', true );
		if ( $prefix ) {
			$schema['honorificPrefix'] = $prefix;
		}

		// Suffix.
		$suffix = get_post_meta( $post->ID, '_biobuzz_contributor_suffix', true );
		if ( $suffix ) {
			$schema['honorificSuffix'] = $suffix;
		}

		// Job title.
		$job_title = get_post_meta( $post->ID, '_biobuzz_contributor_job_title', true );
		if ( $job_title ) {
			$schema['jobTitle'] = $job_title;
		}

		// Company / affiliation.
		$company = get_post_meta( $post->ID, '_biobuzz_contributor_company', true );
		if ( $company ) {
			$schema['worksFor'] = array(
				'@type' => 'Organization',
				'name'  => $company,
			);
		}

		// Image.
		$image_url = $this->get_schema_image( $post->ID );
		if ( $image_url ) {
			$schema['image'] = $image_url;
		}

		// Description.
		$description = $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 25, '...' );
		if ( $description ) {
			$schema['description'] = $description;
		}

		// Social links.
		$same_as = array();
		$social_keys = array(
			'_biobuzz_contributor_linkedin',
			'_biobuzz_contributor_twitter',
			'_biobuzz_contributor_instagram',
			'_biobuzz_contributor_facebook',
			'_biobuzz_contributor_youtube',
			'_biobuzz_contributor_website',
			'_biobuzz_contributor_bluesky',
			'_biobuzz_contributor_threads',
			'_biobuzz_contributor_tiktok',
		);

		foreach ( $social_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( $value ) {
				// If it looks like a URL, use it directly; otherwise build Twitter URL.
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$same_as[] = $value;
				} elseif ( $key === '_biobuzz_contributor_twitter' ) {
					$same_as[] = 'https://twitter.com/' . ltrim( $value, '@' );
				}
			}
		}

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		// Email.
		$email = get_post_meta( $post->ID, '_biobuzz_contributor_email', true );
		if ( $email && is_email( $email ) ) {
			$schema['email'] = $email;
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * WebPage schema (generic posts/pages)
	 * ────────────────────────────────────────────────────────────── */

	private function get_webpage_schema( \WP_Post $post ): array {
		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'WebPage',
			'name'          => $post->post_title,
			'url'           => get_permalink( $post ),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
		);

		$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
		$description = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );
		if ( $description ) {
			$schema['description'] = $description;
		}

		$schema['isPartOf'] = array(
			'@type' => 'WebSite',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Get the best available image for schema markup.
	 */
	private function get_schema_image( int $post_id ): string {
		// Social image override.
		$social_image = get_post_meta( $post_id, '_perrylabs_seo_social_image', true );
		if ( $social_image ) {
			return $social_image;
		}

		// Featured image.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$image = wp_get_attachment_image_url( $thumbnail_id, 'large' );
			if ( $image ) {
				return $image;
			}
		}

		// Default social image.
		return perrylabs_seo_get_option( 'default_social_image', '' );
	}
}
