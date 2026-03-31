<?php
/**
 * PerryLabs SEO + AEO — JSON-LD Structured Data
 *
 * Outputs schema.org structured data as JSON-LD in the <head>.
 * Schema types are determined by a filterable type map that maps
 * post types to schema types. Field mappings are auto-detected
 * and can be overridden via filters or per-post-type options.
 *
 * Supported schema types:
 * - Organization (site-wide)
 * - WebSite with SearchAction (front page)
 * - Article, Event, Person, WebPage (singular, via type map)
 * - FAQPage (auto-detected from content structure)
 * - HowTo (parsed from Gutenberg blocks or heading/paragraph pairs)
 * - VideoObject (from post meta or embedded video URLs)
 * - LocalBusiness (from plugin settings)
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Schema {

	/**
	 * Default meta key patterns used for auto-detection.
	 * Maps schema property names to common meta key patterns.
	 */
	private const FIELD_PATTERNS = array(
		// Article fields.
		'author_id'       => array( '_contributor_id', '_author_id', '_article_author' ),
		'article_section' => array( '_article_cat', '_category', '_section' ),

		// Event fields.
		'startDate'        => array( '_event_start', '_start_date', '_event_date', '_start' ),
		'endDate'          => array( '_event_end', '_end_date', '_end' ),
		'location'         => array( '_event_location', '_location', '_venue' ),
		'registration_url' => array( '_event_registration_url', '_registration_url', '_ticket_url', '_rsvp_url' ),

		// Person fields.
		'first_name'       => array( '_first_name', '_given_name' ),
		'last_name'        => array( '_last_name', '_family_name', '_surname' ),
		'prefix'           => array( '_prefix', '_honorific_prefix', '_title' ),
		'suffix'           => array( '_suffix', '_honorific_suffix' ),
		'job_title'        => array( '_job_title', '_position', '_role' ),
		'company'          => array( '_company', '_organization', '_employer', '_works_for' ),
		'email'            => array( '_email', '_contact_email' ),
		'linkedin'         => array( '_linkedin', '_linkedin_url' ),
		'twitter'          => array( '_twitter', '_twitter_url', '_twitter_handle' ),
		'instagram'        => array( '_instagram', '_instagram_url' ),
		'facebook'         => array( '_facebook', '_facebook_url' ),
		'youtube'          => array( '_youtube', '_youtube_url' ),
		'website'          => array( '_website', '_url', '_personal_url' ),
		'bluesky'          => array( '_bluesky', '_bluesky_url' ),
		'threads'          => array( '_threads', '_threads_url' ),
		'tiktok'           => array( '_tiktok', '_tiktok_url' ),
	);

	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schema' ), 2 );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Schema type map
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Get the mapping of post types to schema types.
	 *
	 * Default mappings include standard WP post types.
	 * Themes/plugins can add custom post type mappings via the
	 * 'perrylabs_seo_schema_type_map' filter.
	 *
	 * @return array<string, string> Post type => schema type.
	 */
	private function get_schema_type_map(): array {
		$defaults = array(
			'post' => 'Article',
			'page' => 'WebPage',
		);

		return apply_filters( 'perrylabs_seo_schema_type_map', $defaults );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Field mapping
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Get the field map for a post type.
	 *
	 * Auto-detects meta key names by scanning registered meta keys for patterns
	 * that match schema properties. Results can be overridden via filter or
	 * stored per-post-type options.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, string> Schema property => meta key.
	 */
	private function get_field_map( string $post_type ): array {
		// Check for stored override in options first.
		$stored_maps = perrylabs_seo_get_option( 'schema_field_maps', array() );
		$map = $stored_maps[ $post_type ] ?? array();

		// Auto-detect unmapped fields by scanning registered meta keys.
		if ( empty( $map ) ) {
			$map = $this->auto_detect_field_map( $post_type );
		}

		/**
		 * Filter the schema field map for a specific post type.
		 *
		 * @param array  $map       Schema property => meta key mapping.
		 * @param string $post_type The post type slug.
		 */
		return apply_filters( 'perrylabs_seo_schema_field_map', $map, $post_type );
	}

	/**
	 * Auto-detect meta keys that match schema property patterns.
	 *
	 * Looks for meta keys registered for the post type that contain known
	 * substrings (e.g., '_event_start' matches 'startDate').
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string, string> Detected mappings.
	 */
	private function auto_detect_field_map( string $post_type ): array {
		global $wpdb;

		// Get distinct meta keys used by this post type (limited sample).
		$meta_keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT pm.meta_key
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND pm.meta_key LIKE %s
			 LIMIT 200",
			$post_type,
			'\_%'
		) );

		$map = array();

		foreach ( self::FIELD_PATTERNS as $property => $patterns ) {
			foreach ( $patterns as $pattern ) {
				foreach ( $meta_keys as $key ) {
					if ( str_contains( $key, $pattern ) ) {
						$map[ $property ] = $key;
						break 2; // Found a match for this property, move on.
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Get a mapped meta value for a schema property.
	 *
	 * @param int    $post_id  The post ID.
	 * @param array  $field_map The field map.
	 * @param string $property The schema property name.
	 * @return string The meta value, or empty string.
	 */
	private function get_mapped_value( int $post_id, array $field_map, string $property ): string {
		if ( ! isset( $field_map[ $property ] ) ) {
			return '';
		}
		return (string) get_post_meta( $post_id, $field_map[ $property ], true );
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
				// Per-post override takes priority.
				$override = get_post_meta( $post->ID, '_perrylabs_seo_schema_type', true );

				if ( 'none' === $override ) {
					// Explicitly disabled — skip.
				} else {
					$type_map    = $this->get_schema_type_map();
					$schema_type = $override ?: ( $type_map[ $post->post_type ] ?? 'WebPage' );

					$schema = match ( $schema_type ) {
						'Article'       => $this->get_article_schema( $post ),
						'Event'         => $this->get_event_schema( $post ),
						'Person'        => $this->get_person_schema( $post ),
						'FAQPage'       => $this->get_faq_schema( $post ),
						'HowTo'        => $this->get_howto_schema( $post ),
						'VideoObject'  => $this->get_video_schema( $post ),
						'LocalBusiness' => $this->get_local_business_schema( $post ),
						default         => $this->get_webpage_schema( $post ),
					};

					if ( $schema ) {
						$schemas[] = $schema;
					}
				}
			}
		}

		// Filter out nulls.
		$schemas = array_filter( $schemas );

		if ( empty( $schemas ) ) {
			return;
		}

		// Output each schema block.
		echo "<!-- SEO + AEO -->\n";
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
	 * Article schema
	 * ────────────────────────────────────────────────────────────── */

	private function get_article_schema( \WP_Post $post ): array {
		$field_map = $this->get_field_map( $post->post_type );

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

		// Author via mapped contributor CPT field.
		$contributor_id = (int) $this->get_mapped_value( $post->ID, $field_map, 'author_id' );
		if ( $contributor_id ) {
			$contributor = get_post( $contributor_id );
			if ( $contributor && $contributor->post_status === 'publish' ) {
				$contrib_field_map = $this->get_field_map( $contributor->post_type );
				$first_name  = $this->get_mapped_value( $contributor_id, $contrib_field_map, 'first_name' );
				$last_name   = $this->get_mapped_value( $contributor_id, $contrib_field_map, 'last_name' );
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

		// Article section (category) — try mapped taxonomy, fall back to standard category.
		$article_section_tax = $field_map['article_section'] ?? null;
		if ( $article_section_tax ) {
			// The mapped value is a taxonomy name stored as a meta key — extract taxonomy slug.
			$tax_slug = str_replace( '_', '', ltrim( $article_section_tax, '_' ) );
			$categories = get_the_terms( $post->ID, $article_section_tax );
			if ( ! $categories || is_wp_error( $categories ) ) {
				$categories = get_the_terms( $post->ID, 'category' );
			}
		} else {
			$categories = get_the_terms( $post->ID, 'category' );
		}
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

		// Speakable specification.
		$schema['speakable'] = array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => array( '.entry-title', '.entry-content' ),
		);

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Event schema
	 * ────────────────────────────────────────────────────────────── */

	private function get_event_schema( \WP_Post $post ): array {
		$field_map = $this->get_field_map( $post->post_type );

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
		$start_date = $this->get_mapped_value( $post->ID, $field_map, 'startDate' );
		if ( $start_date ) {
			$schema['startDate'] = $start_date;
		}

		// End date.
		$end_date = $this->get_mapped_value( $post->ID, $field_map, 'endDate' );
		if ( $end_date ) {
			$schema['endDate'] = $end_date;
		}

		// Location.
		$location = $this->get_mapped_value( $post->ID, $field_map, 'location' );
		if ( $location ) {
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
		$registration_url = $this->get_mapped_value( $post->ID, $field_map, 'registration_url' );
		if ( $registration_url ) {
			$schema['offers'] = array(
				'@type' => 'Offer',
				'url'   => $registration_url,
			);
		}

		// Event status.
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
	 * Person schema
	 * ────────────────────────────────────────────────────────────── */

	private function get_person_schema( \WP_Post $post ): array {
		$field_map = $this->get_field_map( $post->post_type );

		$first_name = $this->get_mapped_value( $post->ID, $field_map, 'first_name' );
		$last_name  = $this->get_mapped_value( $post->ID, $field_map, 'last_name' );
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
		$prefix = $this->get_mapped_value( $post->ID, $field_map, 'prefix' );
		if ( $prefix ) {
			$schema['honorificPrefix'] = $prefix;
		}

		// Suffix.
		$suffix = $this->get_mapped_value( $post->ID, $field_map, 'suffix' );
		if ( $suffix ) {
			$schema['honorificSuffix'] = $suffix;
		}

		// Job title.
		$job_title = $this->get_mapped_value( $post->ID, $field_map, 'job_title' );
		if ( $job_title ) {
			$schema['jobTitle'] = $job_title;
		}

		// Company / affiliation.
		$company = $this->get_mapped_value( $post->ID, $field_map, 'company' );
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
		$social_properties = array(
			'linkedin', 'twitter', 'instagram', 'facebook',
			'youtube', 'website', 'bluesky', 'threads', 'tiktok',
		);

		foreach ( $social_properties as $prop ) {
			$value = $this->get_mapped_value( $post->ID, $field_map, $prop );
			if ( $value ) {
				if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$same_as[] = $value;
				} elseif ( 'twitter' === $prop ) {
					$same_as[] = 'https://twitter.com/' . ltrim( $value, '@' );
				}
			}
		}

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		// Email.
		$email = $this->get_mapped_value( $post->ID, $field_map, 'email' );
		if ( $email && is_email( $email ) ) {
			$schema['email'] = $email;
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * FAQPage schema (auto-detected from content)
	 * ────────────────────────────────────────────────────────────── */

	private function get_faq_schema( \WP_Post $post ): ?array {
		$content = apply_filters( 'the_content', $post->post_content );
		$questions = array();

		// Strategy 1: Parse Gutenberg blocks for FAQ structures.
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $post->post_content );

			foreach ( $blocks as $block ) {
				// core/details blocks (WP 6.3+ accordion/FAQ).
				if ( 'core/details' === $block['blockName'] ) {
					$inner_html = $block['innerHTML'] ?? '';
					// Extract <summary> as question, remaining content as answer.
					if ( preg_match( '/<summary[^>]*>(.*?)<\/summary>/si', $inner_html, $summary_match ) ) {
						$q = wp_strip_all_tags( $summary_match[1] );
						// Remove the summary tag to get the answer portion.
						$answer_html = preg_replace( '/<summary[^>]*>.*?<\/summary>/si', '', $inner_html );
						$a = trim( wp_strip_all_tags( $answer_html ) );
						if ( $q && $a ) {
							$questions[] = array( 'q' => $q, 'a' => $a );
						}
					}
				}

				// yoast/faq-block for backwards compatibility with Yoast migrations.
				if ( 'yoast/faq-block' === $block['blockName'] ) {
					$faq_attrs = $block['attrs'] ?? array();
					$faq_items = $faq_attrs['questions'] ?? array();
					foreach ( $faq_items as $faq_item ) {
						$q = wp_strip_all_tags( $faq_item['jsonQuestion'] ?? '' );
						$a = wp_strip_all_tags( $faq_item['jsonAnswer'] ?? '' );
						if ( $q && $a ) {
							$questions[] = array( 'q' => $q, 'a' => $a );
						}
					}
				}
			}
		}

		// Strategy 2: Look for <dt>/<dd> pairs (definition lists).
		if ( empty( $questions ) && preg_match_all( '/<dt[^>]*>(.*?)<\/dt>\s*<dd[^>]*>(.*?)<\/dd>/si', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$q = wp_strip_all_tags( $match[1] );
				$a = wp_strip_all_tags( $match[2] );
				if ( $q && $a ) {
					$questions[] = array( 'q' => $q, 'a' => $a );
				}
			}
		}

		// Strategy 3: Look for heading + paragraph pairs (h2/h3 followed by p).
		if ( empty( $questions ) ) {
			if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>\s*(<p[^>]*>.*?<\/p>(?:\s*<p[^>]*>.*?<\/p>)*)/si', $content, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$q = wp_strip_all_tags( $match[1] );
					$a = wp_strip_all_tags( $match[2] );
					if ( $q && $a ) {
						$questions[] = array( 'q' => $q, 'a' => $a );
					}
				}
			}
		}

		// If no FAQ structure detected, fall back to WebPage.
		if ( empty( $questions ) ) {
			return $this->get_webpage_schema( $post );
		}

		$faq_entities = array();
		foreach ( $questions as $item ) {
			$faq_entities[] = array(
				'@type'          => 'Question',
				'name'           => $item['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['a'],
				),
			);
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'name'       => $post->post_title,
			'url'        => get_permalink( $post ),
			'mainEntity' => $faq_entities,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * LocalBusiness schema (from plugin settings)
	 * ────────────────────────────────────────────────────────────── */

	private function get_local_business_schema( \WP_Post $post ): ?array {
		$biz_name = perrylabs_seo_get_option( 'business_name', '' );

		// If no business info configured, fall back to WebPage.
		if ( ! $biz_name ) {
			return $this->get_webpage_schema( $post );
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => perrylabs_seo_get_option( 'business_type', 'LocalBusiness' ),
			'name'     => $biz_name,
			'url'      => home_url( '/' ),
		);

		// Address.
		$street  = perrylabs_seo_get_option( 'business_street_address', '' );
		$city    = perrylabs_seo_get_option( 'business_city', '' );
		$state   = perrylabs_seo_get_option( 'business_state', '' );
		$postal  = perrylabs_seo_get_option( 'business_postal_code', '' );
		$country = perrylabs_seo_get_option( 'business_country', '' );

		if ( $street || $city ) {
			$address = array( '@type' => 'PostalAddress' );
			if ( $street )  { $address['streetAddress']   = $street; }
			if ( $city )    { $address['addressLocality'] = $city; }
			if ( $state )   { $address['addressRegion']   = $state; }
			if ( $postal )  { $address['postalCode']      = $postal; }
			if ( $country ) { $address['addressCountry']  = $country; }
			$schema['address'] = $address;
		}

		// Contact point.
		$phone = perrylabs_seo_get_option( 'business_phone', '' );
		$email = perrylabs_seo_get_option( 'business_email', '' );

		if ( $phone || $email ) {
			$contact = array( '@type' => 'ContactPoint' );
			if ( $phone ) { $contact['telephone'] = $phone; }
			if ( $email ) { $contact['email']     = $email; }
			$contact['contactType'] = 'customer service';
			$schema['contactPoint'] = $contact;
		}

		// Logo.
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $logo_url ) {
				$schema['logo'] = $logo_url;
			}
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * HowTo schema (parsed from Gutenberg blocks or content)
	 * ────────────────────────────────────────────────────────────── */

	private function get_howto_schema( \WP_Post $post ): ?array {
		$steps = array();

		// Strategy 1: Parse Gutenberg blocks for ordered lists and heading/paragraph sequences.
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $post->post_content );

			// Look for ordered list blocks (core/list with ordered attribute).
			foreach ( $blocks as $block ) {
				if ( 'core/list' === $block['blockName'] && ! empty( $block['attrs']['ordered'] ) ) {
					$list_html = $block['innerHTML'] ?? '';
					if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/si', $list_html, $li_matches ) ) {
						foreach ( $li_matches[1] as $li_content ) {
							$text = trim( wp_strip_all_tags( $li_content ) );
							if ( $text ) {
								$steps[] = array( 'text' => $text );
							}
						}
					}
				}
			}

			// Look for heading + paragraph sequences as steps.
			if ( empty( $steps ) ) {
				$block_count = count( $blocks );
				for ( $i = 0; $i < $block_count; $i++ ) {
					if ( 'core/heading' === $blocks[ $i ]['blockName'] ) {
						$step_name = trim( wp_strip_all_tags( $blocks[ $i ]['innerHTML'] ?? '' ) );
						$step_text = '';

						// Collect following paragraph blocks as step text.
						for ( $j = $i + 1; $j < $block_count; $j++ ) {
							if ( 'core/paragraph' === $blocks[ $j ]['blockName'] ) {
								$para = trim( wp_strip_all_tags( $blocks[ $j ]['innerHTML'] ?? '' ) );
								if ( $para ) {
									$step_text .= ( $step_text ? ' ' : '' ) . $para;
								}
							} else {
								break;
							}
						}

						if ( $step_name && $step_text ) {
							$steps[] = array( 'name' => $step_name, 'text' => $step_text );
							$i = $j - 1; // Skip consumed paragraphs.
						}
					}
				}
			}
		}

		// If no structured steps found, fall back to WebPage.
		if ( empty( $steps ) ) {
			return $this->get_webpage_schema( $post );
		}

		// Build step array with positions.
		$how_to_steps = array();
		foreach ( $steps as $index => $step ) {
			$how_to_step = array(
				'@type'    => 'HowToStep',
				'position' => $index + 1,
				'text'     => $step['text'],
			);
			if ( ! empty( $step['name'] ) ) {
				$how_to_step['name'] = $step['name'];
			}
			$how_to_steps[] = $how_to_step;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'HowTo',
			'name'     => $post->post_title,
			'step'     => $how_to_steps,
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

		// Total time (from post meta if available).
		$total_time = get_post_meta( $post->ID, '_total_time', true );
		if ( ! $total_time ) {
			$total_time = get_post_meta( $post->ID, '_howto_total_time', true );
		}
		if ( $total_time ) {
			$schema['totalTime'] = $total_time;
		}

		return $schema;
	}

	/* ──────────────────────────────────────────────────────────────
	 * VideoObject schema (from post meta or embedded URLs)
	 * ────────────────────────────────────────────────────────────── */

	private function get_video_schema( \WP_Post $post ): ?array {
		$video_url = '';
		$embed_url = '';

		// Check post meta for video URLs.
		$meta_keys = array( '_video_url', 'video_url', '_embed_url', 'video' );
		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( $value && filter_var( $value, FILTER_VALIDATE_URL ) ) {
				$video_url = $value;
				break;
			}
		}

		// Scan post_content for YouTube and Vimeo URLs if no meta found.
		if ( ! $video_url ) {
			// YouTube: youtube.com/watch?v=ID or youtu.be/ID.
			if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/i', $post->post_content, $yt_match ) ) {
				$video_url = 'https://www.youtube.com/watch?v=' . $yt_match[1];
			}
			// Vimeo: vimeo.com/ID.
			elseif ( preg_match( '/vimeo\.com\/(\d+)/i', $post->post_content, $vm_match ) ) {
				$video_url = 'https://vimeo.com/' . $vm_match[1];
			}
		}

		// If no video found, fall back to WebPage.
		if ( ! $video_url ) {
			return $this->get_webpage_schema( $post );
		}

		// Build embed URL for YouTube.
		if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/i', $video_url, $yt_match ) ) {
			$embed_url = 'https://www.youtube.com/embed/' . $yt_match[1];
		}

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'VideoObject',
			'name'        => $post->post_title,
			'uploadDate'  => get_the_date( 'c', $post ),
		);

		// Description.
		$seo_desc = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
		$description = $seo_desc ?: wp_trim_words( strip_shortcodes( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ) ), 25, '...' );
		if ( $description ) {
			$schema['description'] = $description;
		}

		// Thumbnail.
		$image_url = $this->get_schema_image( $post->ID );
		if ( $image_url ) {
			$schema['thumbnailUrl'] = $image_url;
		}

		// Content URL and/or embed URL.
		if ( $embed_url ) {
			$schema['embedUrl']   = $embed_url;
			$schema['contentUrl'] = $video_url;
		} else {
			$schema['contentUrl'] = $video_url;
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
