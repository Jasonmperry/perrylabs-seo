<?php
/**
 * PLSEO_Schema_Types — site-wide schema contributors.
 *
 * Emits the Organization, WebSite (with SearchAction), and WebPage nodes —
 * the backbone every page in the graph references via @id.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Types {

	public static function register( PLSEO_Schema_Graph $graph ): void {
		$graph->register_contributor( 'organization', array( __CLASS__, 'organization' ), 5 );
		$graph->register_contributor( 'website',      array( __CLASS__, 'website' ),      6 );
		$graph->register_contributor( 'webpage',      array( __CLASS__, 'webpage' ),      7 );
	}

	/**
	 * Organization (or LocalBusiness subtype) — the publisher.
	 *
	 * @return array<string,mixed>
	 */
	public static function organization( ?\WP_Post $post ): array {
		unset( $post );

		$type        = (string) PLSEO_Options::get( 'business_type', 'Organization' );
		$name        = (string) PLSEO_Options::get( 'business_name', '' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		$logo_url    = (string) PLSEO_Options::get( 'business_logo', '' );
		$legal_name  = (string) PLSEO_Options::get( 'business_legal_name', '' );
		$founding    = (string) PLSEO_Options::get( 'business_founding_date', '' );

		$node = array(
			'@type' => $type,
			'@id'   => PLSEO_Schema_Graph::org_id(),
			'name'  => $name,
			'url'   => trailingslashit( home_url( '/' ) ),
		);
		if ( '' !== $legal_name ) {
			$node['legalName'] = $legal_name;
		}
		if ( '' !== $founding ) {
			$node['foundingDate'] = $founding;
		}

		if ( '' !== $logo_url ) {
			$node['logo'] = array(
				'@type'      => 'ImageObject',
				'@id'        => $logo_url . '#logo',
				'url'        => $logo_url,
				'contentUrl' => $logo_url,
			);
			$node['image'] = array( '@id' => $logo_url . '#logo' );
		}

		// Address.
		$addr = self::collect_address();
		if ( ! empty( $addr ) ) {
			$node['address'] = $addr;
		}

		// Contact points.
		$contact = array();
		$phone   = trim( (string) PLSEO_Options::get( 'business_phone', '' ) );
		$email   = trim( (string) PLSEO_Options::get( 'business_email', '' ) );
		if ( '' !== $phone || '' !== $email ) {
			$contact = array(
				'@type'       => 'ContactPoint',
				'contactType' => 'customer service',
			);
			if ( '' !== $phone ) {
				$contact['telephone'] = $phone;
			}
			if ( '' !== $email ) {
				$contact['email'] = $email;
			}
			$node['contactPoint'] = array( $contact );
		}

		// Social profile sameAs.
		$same_as = array_values( array_filter( array_map( 'trim', array(
			(string) PLSEO_Options::get( 'facebook_url', '' ),
			(string) PLSEO_Options::get( 'linkedin_url', '' ),
			(string) PLSEO_Options::get( 'instagram_url', '' ),
			(string) PLSEO_Options::get( 'youtube_url', '' ),
			(string) PLSEO_Options::get( 'github_url', '' ),
			(string) PLSEO_Options::get( 'mastodon_url', '' ),
			self::twitter_url(),
		) ) ) );
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = $same_as;
		}

		// LocalBusiness-specific fields.
		if ( str_contains( $type, 'LocalBusiness' ) || 'Restaurant' === $type || 'Store' === $type ) {
			$range = trim( (string) PLSEO_Options::get( 'business_price_range', '' ) );
			if ( '' !== $range ) {
				$node['priceRange'] = $range;
			}
			$hours = trim( (string) PLSEO_Options::get( 'business_hours', '' ) );
			if ( '' !== $hours ) {
				// Free-text input; users may paste a single line or multiple.
				$node['openingHours'] = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $hours ) ?: array() ) ) );
			}
		}

		return $node;
	}

	private static function twitter_url(): string {
		$h = trim( (string) PLSEO_Options::get( 'twitter_handle', '' ) );
		if ( '' === $h ) {
			return '';
		}
		return 'https://twitter.com/' . ltrim( $h, '@' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function collect_address(): array {
		$street  = trim( (string) PLSEO_Options::get( 'business_street_address', '' ) );
		$city    = trim( (string) PLSEO_Options::get( 'business_city', '' ) );
		$region  = trim( (string) PLSEO_Options::get( 'business_state', '' ) );
		$postal  = trim( (string) PLSEO_Options::get( 'business_postal_code', '' ) );
		$country = trim( (string) PLSEO_Options::get( 'business_country', '' ) );

		if ( '' === $street && '' === $city && '' === $region && '' === $postal && '' === $country ) {
			return array();
		}

		$out = array( '@type' => 'PostalAddress' );
		if ( '' !== $street )  { $out['streetAddress']   = $street; }
		if ( '' !== $city )    { $out['addressLocality'] = $city; }
		if ( '' !== $region )  { $out['addressRegion']   = $region; }
		if ( '' !== $postal )  { $out['postalCode']      = $postal; }
		if ( '' !== $country ) { $out['addressCountry']  = $country; }
		return $out;
	}

	/**
	 * WebSite node with SearchAction (sitelinks search box).
	 *
	 * @return array<string,mixed>
	 */
	public static function website( ?\WP_Post $post ): array {
		unset( $post );
		return array(
			'@type'           => 'WebSite',
			'@id'             => PLSEO_Schema_Graph::website_id(),
			'url'             => trailingslashit( home_url( '/' ) ),
			'name'            => (string) get_bloginfo( 'name' ),
			'description'     => (string) get_bloginfo( 'description' ),
			'inLanguage'      => self::language(),
			'publisher'       => array( '@id' => PLSEO_Schema_Graph::org_id() ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => trailingslashit( home_url( '/' ) ) . '?s={search_term_string}',
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
	}

	/**
	 * WebPage node — every page in the graph anchors here.
	 *
	 * @return array<string,mixed>
	 */
	public static function webpage( ?\WP_Post $post ): array {
		$url   = PLSEO_Schema_Graph::current_url();
		$title = (string) wp_get_document_title();

		$node = array(
			'@type'      => 'WebPage',
			'@id'        => PLSEO_Schema_Graph::webpage_id( $url ),
			'url'        => $url,
			'name'       => $title,
			'isPartOf'   => array( '@id' => PLSEO_Schema_Graph::website_id() ),
			'inLanguage' => self::language(),
		);

		if ( $post instanceof \WP_Post ) {
			$node['datePublished'] = (string) get_the_date( 'c', $post );
			$node['dateModified']  = (string) get_the_modified_date( 'c', $post );
			$thumb_id              = (int) get_post_thumbnail_id( $post );
			if ( $thumb_id > 0 ) {
				$img = PLSEO_Schema_Graph::image_object( $thumb_id );
				if ( $img ) {
					$node['primaryImageOfPage'] = array( '@id' => $img['@id'] );
				}
			}
		}

		// AEO speakable, if configured.
		$speakable = (array) PLSEO_Options::get( 'aeo_speakable_selectors', array() );
		if ( ! empty( $speakable ) ) {
			$node['speakable'] = array(
				'@type'    => 'SpeakableSpecification',
				'cssSelector' => array_values( array_filter( array_map( 'strval', $speakable ) ) ),
			);
		}

		return $node;
	}

	private static function language(): string {
		return PLSEO_Str::site_language();
	}
}
