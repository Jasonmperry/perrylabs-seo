<?php
/**
 * PLSEO_Schema_Extended — additional schema.org types.
 *
 * Adds Product, Review, Recipe, JobPosting, Course, SoftwareApplication, Book,
 * and ClaimReview to the rules-engine vocabulary. None of these dictate post
 * meta keys arbitrarily — each pulls its data from a well-named `_plseo_*` meta
 * field that the user fills via the meta box's "Schema" tab.
 *
 * The dispatcher (PLSEO_Schema_Content::build_entity) calls into here when the
 * rules engine emits one of these types. Anything we don't know how to build
 * falls back to the basic Article-shaped node.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Extended {

	/**
	 * Types this module knows how to build.
	 *
	 * @return array<int,string>
	 */
	public static function supported_types(): array {
		return array( 'Product', 'Review', 'Recipe', 'JobPosting', 'Course', 'SoftwareApplication', 'Book', 'ClaimReview', 'QAPage' );
	}

	public static function handles( string $type ): bool {
		return in_array( $type, self::supported_types(), true );
	}

	/**
	 * Build one extended-type node. Returns null if the type is unrecognized
	 * so the dispatcher can fall back.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function build( \WP_Post $post, string $type, array $base ): ?array {
		return match ( $type ) {
			'Product'             => self::product( $post, $base ),
			'Review'              => self::review( $post, $base ),
			'Recipe'              => self::recipe( $post, $base ),
			'JobPosting'          => self::job_posting( $post, $base ),
			'Course'              => self::course( $post, $base ),
			'SoftwareApplication' => self::software( $post, $base ),
			'Book'                => self::book( $post, $base ),
			'ClaimReview'         => self::claim_review( $post, $base ),
			'QAPage'              => self::qa_page( $post, $base ),
			default               => null,
		};
	}

	/* ───────────────────────── per-type builders ───────────────────────── */

	private static function product( \WP_Post $post, array $base ): array {
		$node = array_merge( $base, array(
			'@type'    => 'Product',
			'sku'      => (string) plseo_get_post_meta( $post->ID, 'sku', '' ),
			'brand'    => self::brand_ref(),
		) );
		$node = self::strip_empty( $node, array( 'sku', 'brand' ) );

		$price    = trim( (string) plseo_get_post_meta( $post->ID, 'price', '' ) );
		$currency = trim( (string) plseo_get_post_meta( $post->ID, 'currency', 'USD' ) );
		if ( '' !== $price ) {
			$node['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
				'availability'  => 'https://schema.org/' . ( plseo_get_post_meta( $post->ID, 'in_stock', '1' ) ? 'InStock' : 'OutOfStock' ),
				'url'           => $base['url'] ?? '',
			);
		}

		$rating = (float) plseo_get_post_meta( $post->ID, 'rating', 0 );
		$count  = (int) plseo_get_post_meta( $post->ID, 'rating_count', 0 );
		if ( $rating > 0 && $count > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $rating,
				'reviewCount' => $count,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}
		return $node;
	}

	private static function review( \WP_Post $post, array $base ): array {
		$item_name = (string) plseo_get_post_meta( $post->ID, 'review_item_name', $base['name'] ?? '' );
		$item_url  = (string) plseo_get_post_meta( $post->ID, 'review_item_url', '' );
		$rating    = (float) plseo_get_post_meta( $post->ID, 'rating', 0 );
		$node = array_merge( $base, array(
			'@type'        => 'Review',
			'reviewBody'   => $base['description'] ?? '',
			'itemReviewed' => array_filter( array(
				'@type' => 'Thing',
				'name'  => $item_name,
				'url'   => $item_url ?: null,
			) ),
		) );
		if ( $rating > 0 ) {
			$node['reviewRating'] = array(
				'@type'       => 'Rating',
				'ratingValue' => $rating,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}
		return $node;
	}

	private static function recipe( \WP_Post $post, array $base ): array {
		$ingredients = self::lines( (string) plseo_get_post_meta( $post->ID, 'ingredients', '' ) );
		$instructions = self::lines( (string) plseo_get_post_meta( $post->ID, 'instructions', '' ) );
		$prep        = trim( (string) plseo_get_post_meta( $post->ID, 'prep_time', '' ) );
		$cook        = trim( (string) plseo_get_post_meta( $post->ID, 'cook_time', '' ) );
		$total       = trim( (string) plseo_get_post_meta( $post->ID, 'total_time', '' ) );
		$yield       = trim( (string) plseo_get_post_meta( $post->ID, 'recipe_yield', '' ) );

		$node = array_merge( $base, array(
			'@type'              => 'Recipe',
			'recipeIngredient'   => $ingredients,
			'recipeInstructions' => array_map(
				static fn( $s ) => array( '@type' => 'HowToStep', 'text' => (string) $s ),
				$instructions
			),
		) );
		if ( '' !== $prep )  { $node['prepTime']    = $prep; }   // ISO 8601 (PT15M)
		if ( '' !== $cook )  { $node['cookTime']    = $cook; }
		if ( '' !== $total ) { $node['totalTime']   = $total; }
		if ( '' !== $yield ) { $node['recipeYield'] = $yield; }
		return self::strip_empty( $node, array( 'recipeIngredient', 'recipeInstructions' ) );
	}

	private static function job_posting( \WP_Post $post, array $base ): array {
		$node = array_merge( $base, array(
			'@type'           => 'JobPosting',
			'title'           => $base['name'] ?? '',
			'employmentType'  => (string) plseo_get_post_meta( $post->ID, 'employment_type', 'FULL_TIME' ),
			'datePosted'      => $base['datePublished'] ?? '',
			'validThrough'    => (string) plseo_get_post_meta( $post->ID, 'valid_through', '' ),
			'hiringOrganization' => array( '@id' => PLSEO_Schema_Graph::org_id() ),
		) );

		$city    = (string) plseo_get_post_meta( $post->ID, 'job_city', '' );
		$region  = (string) plseo_get_post_meta( $post->ID, 'job_region', '' );
		$country = (string) plseo_get_post_meta( $post->ID, 'job_country', '' );
		if ( '' !== $city || '' !== $region || '' !== $country ) {
			$node['jobLocation'] = array(
				'@type' => 'Place',
				'address' => array_filter( array(
					'@type'            => 'PostalAddress',
					'addressLocality'  => $city ?: null,
					'addressRegion'    => $region ?: null,
					'addressCountry'   => $country ?: null,
				) ),
			);
		}

		$salary_min = (float) plseo_get_post_meta( $post->ID, 'salary_min', 0 );
		$salary_max = (float) plseo_get_post_meta( $post->ID, 'salary_max', 0 );
		$currency   = (string) plseo_get_post_meta( $post->ID, 'salary_currency', 'USD' );
		if ( $salary_min > 0 || $salary_max > 0 ) {
			$node['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $currency,
				'value'    => array_filter( array(
					'@type'    => 'QuantitativeValue',
					'minValue' => $salary_min ?: null,
					'maxValue' => $salary_max ?: null,
					'unitText' => (string) plseo_get_post_meta( $post->ID, 'salary_unit', 'YEAR' ),
				) ),
			);
		}
		return self::strip_empty( $node, array( 'validThrough' ) );
	}

	private static function course( \WP_Post $post, array $base ): array {
		return array_merge( $base, array(
			'@type'    => 'Course',
			'provider' => array( '@id' => PLSEO_Schema_Graph::org_id() ),
		) );
	}

	private static function software( \WP_Post $post, array $base ): array {
		$node = array_merge( $base, array(
			'@type'            => 'SoftwareApplication',
			'applicationCategory' => (string) plseo_get_post_meta( $post->ID, 'app_category', 'BusinessApplication' ),
			'operatingSystem'  => (string) plseo_get_post_meta( $post->ID, 'os', 'Web' ),
			'softwareVersion'  => (string) plseo_get_post_meta( $post->ID, 'version', '' ),
		) );
		$price    = trim( (string) plseo_get_post_meta( $post->ID, 'price', '' ) );
		$currency = trim( (string) plseo_get_post_meta( $post->ID, 'currency', 'USD' ) );
		if ( '' !== $price ) {
			$node['offers'] = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => $currency,
			);
		}
		return self::strip_empty( $node, array( 'softwareVersion' ) );
	}

	private static function book( \WP_Post $post, array $base ): array {
		return array_merge( $base, array(
			'@type'  => 'Book',
			'isbn'   => (string) plseo_get_post_meta( $post->ID, 'isbn', '' ),
			'numberOfPages' => (int) plseo_get_post_meta( $post->ID, 'pages', 0 ) ?: null,
			'bookFormat' => (string) plseo_get_post_meta( $post->ID, 'book_format', 'EBook' ),
		) );
	}

	private static function claim_review( \WP_Post $post, array $base ): array {
		$claim  = (string) plseo_get_post_meta( $post->ID, 'claim_reviewed', '' );
		$rating = (string) plseo_get_post_meta( $post->ID, 'rating_text', 'Unverified' );
		$best   = (int) plseo_get_post_meta( $post->ID, 'rating_best', 5 );
		$worst  = (int) plseo_get_post_meta( $post->ID, 'rating_worst', 1 );
		$value  = (int) plseo_get_post_meta( $post->ID, 'rating_value', 3 );
		return array_merge( $base, array(
			'@type'          => 'ClaimReview',
			'claimReviewed'  => $claim,
			'reviewRating'   => array(
				'@type'           => 'Rating',
				'ratingValue'     => $value,
				'bestRating'      => $best,
				'worstRating'     => $worst,
				'alternateName'   => $rating,
			),
			'itemReviewed'   => array(
				'@type'    => 'Claim',
				'datePublished' => $base['datePublished'] ?? '',
				'appearance' => array(
					'@type' => 'CreativeWork',
					'url'   => (string) plseo_get_post_meta( $post->ID, 'claim_source_url', '' ),
				),
			),
		) );
	}

	private static function qa_page( \WP_Post $post, array $base ): array {
		// QAPage expects a single accepted answer plus a main question.
		$question = (string) plseo_get_post_meta( $post->ID, 'question', $base['name'] ?? '' );
		$answer   = (string) plseo_get_post_meta( $post->ID, 'accepted_answer', '' );
		if ( '' === $answer ) {
			$answer = (string) ( $base['description'] ?? '' );
		}
		return array_merge( $base, array(
			'@type'      => 'QAPage',
			'mainEntity' => array(
				'@type' => 'Question',
				'name'  => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			),
		) );
	}

	/* ───────────────────────── shared utilities ───────────────────────── */

	/** @return array<int,string> */
	private static function lines( string $text ): array {
		return array_values( array_filter( array_map(
			static fn( $s ) => trim( (string) $s ),
			preg_split( '/\r?\n/', $text ) ?: array()
		) ) );
	}

	private static function brand_ref(): array {
		return array( '@id' => PLSEO_Schema_Graph::org_id() );
	}

	/**
	 * Drop null/empty values from a node's optional fields but keep required ones.
	 *
	 * @param array<string,mixed> $node
	 * @param array<int,string>   $optional_keys
	 * @return array<string,mixed>
	 */
	private static function strip_empty( array $node, array $optional_keys ): array {
		foreach ( $optional_keys as $k ) {
			if ( isset( $node[ $k ] ) && ( null === $node[ $k ] || '' === $node[ $k ] || ( is_array( $node[ $k ] ) && empty( $node[ $k ] ) ) ) ) {
				unset( $node[ $k ] );
			}
		}
		return $node;
	}
}
