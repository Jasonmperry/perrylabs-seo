<?php
/**
 * PLSEO_Schema_Content — content-type schema contributors.
 *
 * Article, Person (author E-E-A-T), Event, LocalBusiness (post-level override),
 * VideoObject. These look at the queried post and only emit when appropriate.
 *
 * Type selection respects:
 *   1. Per-post _plseo_schema_type meta (manual override).
 *   2. The schema_type_map option (post type → schema type).
 *   3. Sensible defaults (post → Article, page → WebPage).
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Content {

	public static function register( PLSEO_Schema_Graph $graph ): void {
		$graph->register_contributor( 'primary-entity', array( __CLASS__, 'primary_entities' ), 15 );
		$graph->register_contributor( 'author-person',  array( __CLASS__, 'author_person' ),    18 );
		$graph->register_contributor( 'video-object',   array( __CLASS__, 'video_object' ),     20 );
	}

	/**
	 * Resolve all schema @types for a given post via the rules engine.
	 *
	 * @return array<int,string>
	 */
	public static function resolve_types( \WP_Post $post ): array {
		return PLSEO_Schema_Rules::emit_for( $post );
	}

	/**
	 * Build one or more primary-entity nodes for the queried post.
	 *
	 * Returns an array of nodes (the graph builder accepts a 0-indexed list).
	 * WebPage is dropped because Schema_Types::webpage() handles it. FAQPage /
	 * HowTo are dropped because Schema_AEO emits them with their own @ids.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function primary_entities( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$types = self::resolve_types( $post );
		if ( in_array( 'none', $types, true ) ) {
			return null;
		}

		$nodes = array();
		foreach ( $types as $type ) {
			if ( in_array( $type, array( 'WebPage', 'FAQPage', 'HowTo' ), true ) ) {
				// Emitted elsewhere — skip to avoid duplicate @ids.
				continue;
			}
			$node = self::build_entity( $post, $type );
			if ( $node ) {
				$nodes[] = $node;
			}
		}
		return empty( $nodes ) ? null : $nodes;
	}

	/**
	 * Construct one entity node of the requested type.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function build_entity( \WP_Post $post, string $type ): ?array {
		$url  = PLSEO_Schema_Graph::current_url();
		// Multiple types per post need distinct @ids so the graph dedupes correctly.
		$entity_id = $url . '#primary-' . sanitize_key( $type );
		$node = array(
			'@type'         => $type,
			'@id'           => $entity_id,
			'mainEntityOfPage' => array( '@id' => PLSEO_Schema_Graph::webpage_id( $url ) ),
			'url'           => $url,
			'name'          => (string) get_the_title( $post ),
			'headline'      => self::clip( wp_strip_all_tags( (string) get_the_title( $post ) ), 110 ),
			'datePublished' => (string) get_the_date( 'c', $post ),
			'dateModified'  => (string) get_the_modified_date( 'c', $post ),
			'inLanguage'    => self::language(),
			'publisher'     => array( '@id' => PLSEO_Schema_Graph::org_id() ),
		);

		// Description: per-post → excerpt → first paragraph.
		$desc = (string) plseo_get_post_meta( $post->ID, 'description', '' );
		if ( '' === $desc ) {
			$desc = (string) $post->post_excerpt;
		}
		if ( '' === $desc ) {
			$content = wp_strip_all_tags( (string) apply_filters( 'the_content', $post->post_content ) );
			$desc    = self::clip( trim( preg_replace( '/\s+/u', ' ', $content ) ?? '' ), 280 );
		}
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		// Author reference (Person node emitted by author_person contributor).
		if ( $post->post_author ) {
			$node['author'] = array( '@id' => PLSEO_Schema_Graph::person_id( (int) $post->post_author ) );
		}

		// Image.
		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			$img = PLSEO_Schema_Graph::image_object( $thumb_id );
			if ( $img ) {
				$node['image'] = array( '@id' => $img['@id'] );
			}
		}

		// Article-family extras.
		if ( in_array( $type, array( 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle' ), true ) ) {
			$node['wordCount']      = str_word_count( wp_strip_all_tags( $post->post_content ) );
			$node['articleSection'] = self::primary_section( $post );
			$keywords               = self::keywords( $post );
			if ( ! empty( $keywords ) ) {
				$node['keywords'] = implode( ', ', $keywords );
			}
		}

		// Event-specific.
		if ( 'Event' === $type ) {
			$start = (string) plseo_get_post_meta( $post->ID, 'event_start', '' );
			$end   = (string) plseo_get_post_meta( $post->ID, 'event_end', '' );
			$loc   = (string) plseo_get_post_meta( $post->ID, 'event_location', '' );
			if ( '' !== $start ) {
				$node['startDate'] = $start;
			}
			if ( '' !== $end ) {
				$node['endDate'] = $end;
			}
			if ( '' !== $loc ) {
				$node['location'] = array(
					'@type'   => 'Place',
					'name'    => $loc,
				);
			}
		}

		return $node;
	}

	/**
	 * Emit a Person node for the queried post's author. Used by primary_entity via @id ref.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function author_person( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post || ! $post->post_author ) {
			return null;
		}
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) {
			return null;
		}

		$node = array(
			'@type' => 'Person',
			'@id'   => PLSEO_Schema_Graph::person_id( (int) $user->ID ),
			'name'  => (string) $user->display_name,
			'url'   => (string) get_author_posts_url( (int) $user->ID ),
			'worksFor' => array( '@id' => PLSEO_Schema_Graph::org_id() ),
		);

		if ( ! empty( $user->user_url ) ) {
			$node['url'] = (string) $user->user_url;
		}

		$bio = trim( (string) get_user_meta( $user->ID, 'description', true ) );
		if ( '' !== $bio ) {
			$node['description'] = $bio;
		}

		// Per-user E-E-A-T fields stored in user meta with plseo_ prefix.
		$same_as = (string) get_user_meta( $user->ID, 'plseo_same_as', true );
		if ( '' !== $same_as ) {
			$urls = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $same_as ) ?: array() ) ) );
			if ( ! empty( $urls ) ) {
				$node['sameAs'] = $urls;
			}
		}
		$credentials = (string) get_user_meta( $user->ID, 'plseo_credentials', true );
		if ( '' !== $credentials ) {
			$node['hasCredential'] = array(
				'@type'           => 'EducationalOccupationalCredential',
				'credentialCategory' => $credentials,
			);
		}
		$expertise = (string) get_user_meta( $user->ID, 'plseo_expertise', true );
		if ( '' !== $expertise ) {
			$node['knowsAbout'] = array_values( array_filter( array_map( 'trim', explode( ',', $expertise ) ) ) );
		}

		// Avatar.
		$avatar = get_avatar_url( $user->ID, array( 'size' => 256 ) );
		if ( $avatar ) {
			$node['image'] = array(
				'@type'      => 'ImageObject',
				'url'        => (string) $avatar,
				'contentUrl' => (string) $avatar,
			);
		}

		return $node;
	}

	/**
	 * Detect a video in the post (first <video> tag, YouTube/Vimeo embed, or featured video meta)
	 * and emit a VideoObject node.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function video_object( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$url      = '';
		$thumb    = '';
		$duration = '';
		$content  = (string) $post->post_content;

		// Per-post override.
		$override = (string) plseo_get_post_meta( $post->ID, 'video_url', '' );
		if ( '' !== $override ) {
			$url = $override;
		}

		// Try to find a YouTube/Vimeo URL.
		if ( '' === $url && preg_match( '#https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w\-]+|youtu\.be/[\w\-]+|vimeo\.com/\d+)#i', $content, $m ) ) {
			$url = $m[0];
		}
		// Native <video src="">.
		if ( '' === $url && preg_match( '/<video[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$url = $m[1];
		}

		if ( '' === $url ) {
			return null;
		}

		// Best-effort thumbnail.
		if ( preg_match( '#youtu(?:\.be/|be\.com/watch\?v=)([\w\-]+)#i', $url, $m ) ) {
			$thumb = sprintf( 'https://img.youtube.com/vi/%s/hqdefault.jpg', $m[1] );
		}
		if ( '' === $thumb ) {
			$thumb_id = (int) get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$src = wp_get_attachment_image_src( $thumb_id, 'full' );
				if ( $src ) {
					$thumb = (string) $src[0];
				}
			}
		}

		$node = array(
			'@type'        => 'VideoObject',
			'@id'          => PLSEO_Schema_Graph::current_url() . '#video',
			'name'         => (string) get_the_title( $post ),
			'description'  => self::clip( wp_strip_all_tags( (string) $post->post_excerpt ?: (string) get_the_title( $post ) ), 220 ),
			'uploadDate'   => (string) get_the_date( 'c', $post ),
			'contentUrl'   => $url,
		);
		if ( '' !== $thumb ) {
			$node['thumbnailUrl'] = $thumb;
		}
		$override_dur = (string) plseo_get_post_meta( $post->ID, 'video_duration', '' );
		if ( '' !== $override_dur ) {
			$node['duration'] = $override_dur; // ISO 8601, e.g. PT2M30S
		}

		return $node;
	}

	/* ───────────────────────── utilities ───────────────────────── */

	private static function primary_section( \WP_Post $post ): string {
		$cats = get_the_category( $post->ID );
		if ( is_array( $cats ) && ! empty( $cats ) ) {
			return (string) $cats[0]->name;
		}
		return (string) ucfirst( $post->post_type );
	}

	/**
	 * @return array<int,string>
	 */
	private static function keywords( \WP_Post $post ): array {
		$tags = get_the_tags( $post->ID );
		if ( ! is_array( $tags ) ) {
			return array();
		}
		return array_values( array_filter( array_map( static fn( $t ) => (string) $t->name, $tags ) ) );
	}

	private static function clip( string $text, int $max ): string {
		return PLSEO_Str::clip( $text, $max );
	}

	private static function language(): string {
		return PLSEO_Str::site_language();
	}
}
