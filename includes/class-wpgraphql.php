<?php
/**
 * PLSEO_WPGraphQL — expose `_plseo_*` meta on WPGraphQL post-type nodes.
 *
 * Headless WordPress users querying through WPGraphQL get a `seo` field on
 * every public post-type containing the resolved title, description,
 * canonical, focus keyword, schema type, cornerstone flag, social image,
 * and quick answer. No build step required — registers via the standard
 * register_graphql_field API.
 *
 * No-op when WPGraphQL is not loaded.
 *
 * Example query:
 *
 *   query GetPost($slug: ID!) {
 *     post(id: $slug, idType: SLUG) {
 *       title
 *       seo {
 *         resolvedTitle
 *         description
 *         canonical
 *         focusKeyword
 *         quickAnswer
 *         cornerstone
 *         schemaType
 *         socialImage
 *       }
 *     }
 *   }
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_WPGraphQL {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( ! function_exists( 'register_graphql_object_type' ) ) {
			return;
		}
		add_action( 'graphql_register_types', array( $this, 'register' ) );
	}

	public function register(): void {
		// Object type.
		register_graphql_object_type( 'PerryLabsSEO', array(
			'description' => __( 'Per-post SEO + AEO metadata managed by PerryLabs SEO.', 'perrylabs-seo' ),
			'fields'      => array(
				'resolvedTitle'  => array( 'type' => 'String',  'description' => __( 'The resolved SEO title (per-post override → template).', 'perrylabs-seo' ) ),
				'description'    => array( 'type' => 'String',  'description' => __( 'Meta description.', 'perrylabs-seo' ) ),
				'canonical'      => array( 'type' => 'String',  'description' => __( 'Canonical URL.', 'perrylabs-seo' ) ),
				'focusKeyword'   => array( 'type' => 'String',  'description' => __( 'Comma-separated focus keywords.', 'perrylabs-seo' ) ),
				'quickAnswer'    => array( 'type' => 'String',  'description' => __( 'AEO quick-answer / TL;DR — lifted verbatim by AI overviews.', 'perrylabs-seo' ) ),
				'cornerstone'    => array( 'type' => 'Boolean', 'description' => __( 'Is this post flagged as cornerstone?', 'perrylabs-seo' ) ),
				'noindex'        => array( 'type' => 'Boolean', 'description' => __( 'Per-post noindex flag.', 'perrylabs-seo' ) ),
				'nofollow'       => array( 'type' => 'Boolean', 'description' => __( 'Per-post nofollow flag.', 'perrylabs-seo' ) ),
				'schemaType'     => array( 'type' => 'String',  'description' => __( 'Per-post schema-type override (empty = auto from rules).', 'perrylabs-seo' ) ),
				'socialImage'    => array( 'type' => 'String',  'description' => __( 'Per-post social image override.', 'perrylabs-seo' ) ),
				'hreflang'       => array( 'type' => 'String',  'description' => __( 'Hreflang alternates (one per line: lang|url).', 'perrylabs-seo' ) ),
				'severity'       => array( 'type' => 'String',  'description' => __( 'Worst severity from content analysis: pass | warn | fail | none.', 'perrylabs-seo' ) ),
			),
		) );

		// Mount the field on every public post-type's GraphQL type.
		$types = get_post_types( array( 'public' => true, 'show_in_graphql' => true ), 'objects' );
		foreach ( $types as $type ) {
			if ( empty( $type->graphql_single_name ) ) {
				continue;
			}
			register_graphql_field( $type->graphql_single_name, 'seo', array(
				'type'        => 'PerryLabsSEO',
				'description' => __( 'PerryLabs SEO + AEO metadata.', 'perrylabs-seo' ),
				'resolve'     => array( $this, 'resolve' ),
			) );
		}
	}

	/**
	 * Resolver — accepts whatever WPGraphQL passes for the source; pulls the
	 * underlying WP_Post and assembles the SEO payload.
	 *
	 * @param mixed $source WPGraphQL Post model (has ->ID).
	 * @return array<string,mixed>
	 */
	public function resolve( $source ): array {
		$post_id = is_object( $source ) && isset( $source->ID ) ? (int) $source->ID : 0;
		if ( ! $post_id ) {
			return array();
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$override = (string) plseo_get_post_meta( $post_id, 'title', '' );
		$resolved_title = '' !== $override
			? PLSEO_Template_Resolver::resolve( $override, PLSEO_Template_Resolver::context_for_post( $post ) )
			: PLSEO_Template_Resolver::resolve(
				(string) PLSEO_Options::get( 'page' === $post->post_type ? 'title_template_page' : 'title_template_post', '%post_title% %sep% %site_name%' ),
				PLSEO_Template_Resolver::context_for_post( $post )
			);

		$rows     = PLSEO_Content_Analysis::analyze( $post );
		$severity = PLSEO_Content_Analysis::overall_severity( $rows );

		return array(
			'resolvedTitle' => $resolved_title,
			'description'   => (string) plseo_get_post_meta( $post_id, 'description', '' ),
			'canonical'     => (string) plseo_get_post_meta( $post_id, 'canonical', '' ),
			'focusKeyword'  => (string) plseo_get_post_meta( $post_id, 'focus_keyword', '' ),
			'quickAnswer'   => (string) plseo_get_post_meta( $post_id, 'quick_answer', '' ),
			'cornerstone'   => '1' === (string) plseo_get_post_meta( $post_id, 'cornerstone', '' ),
			'noindex'       => '1' === (string) plseo_get_post_meta( $post_id, 'noindex', '' ),
			'nofollow'      => '1' === (string) plseo_get_post_meta( $post_id, 'nofollow', '' ),
			'schemaType'    => (string) plseo_get_post_meta( $post_id, 'schema_type', '' ),
			'socialImage'   => (string) plseo_get_post_meta( $post_id, 'social_image', '' ),
			'hreflang'      => (string) plseo_get_post_meta( $post_id, 'hreflang', '' ),
			'severity'      => $severity,
		);
	}
}
