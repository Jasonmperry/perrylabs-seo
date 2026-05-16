<?php
/**
 * PLSEO_Schema_Graph — the central JSON-LD graph builder.
 *
 * Instead of emitting a dozen separate <script type="application/ld+json"> blocks,
 * we build ONE @graph with interlinked @id references. Modern crawlers (Google,
 * Bing, AI overviews) parse this more reliably and can resolve entity relationships.
 *
 * Contributors register a callback that returns one node or many. We dedupe by @id,
 * sort by priority, and emit a single <script> tag in wp_head.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Graph {

	private static ?self $instance = null;

	/**
	 * @var array<int,array{id:string,callback:callable,priority:int}>
	 */
	private array $contributors = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Wire built-in contributors.
		PLSEO_Schema_Types::register( $this );
		PLSEO_Schema_Content::register( $this );
		PLSEO_Schema_AEO::register( $this );

		// Render after wp_head:1 (meta tags) but before priority 20 (legacy plugins).
		add_action( 'wp_head', array( $this, 'output' ), 10 );
	}

	/**
	 * Register a contributor callback. Callbacks return either an array (single node)
	 * or an array of arrays (multiple nodes), or null/empty to contribute nothing.
	 *
	 * @param string                       $id        Stable contributor ID (not the @id, just bookkeeping).
	 * @param callable(\WP_Post|null):mixed $callback  Receives the queried post (or null).
	 * @param int                          $priority  Lower = earlier. Default 10.
	 */
	public function register_contributor( string $id, callable $callback, int $priority = 10 ): void {
		$this->contributors[] = array(
			'id'       => $id,
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	public function output(): void {
		if ( is_admin() || is_embed() || is_feed() ) {
			return;
		}

		$post = is_singular() ? get_queried_object() : null;
		$post = $post instanceof \WP_Post ? $post : null;

		// Honor per-post `none` schema override.
		if ( $post && plseo_get_post_meta( $post->ID, 'schema_type', '' ) === 'none' ) {
			return;
		}

		// Sort by priority, ascending.
		usort( $this->contributors, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );

		$nodes = array();
		foreach ( $this->contributors as $contrib ) {
			$result = call_user_func( $contrib['callback'], $post );
			if ( empty( $result ) ) {
				continue;
			}
			if ( isset( $result[0] ) && is_array( $result[0] ) ) {
				foreach ( $result as $node ) {
					if ( is_array( $node ) ) {
						$nodes[] = $node;
					}
				}
			} elseif ( is_array( $result ) ) {
				$nodes[] = $result;
			}
		}

		if ( empty( $nodes ) ) {
			return;
		}

		// Dedupe by @id (last writer wins, so contributors can override built-ins).
		$by_id = array();
		$loose = array();
		foreach ( $nodes as $node ) {
			if ( ! empty( $node['@id'] ) ) {
				$by_id[ (string) $node['@id'] ] = $node;
			} else {
				$loose[] = $node;
			}
		}
		$graph = array_merge( array_values( $by_id ), $loose );

		/**
		 * Final chance to mutate the @graph before output.
		 *
		 * @param array<int,array<string,mixed>> $graph Full graph node list.
		 * @param \WP_Post|null                  $post  Queried post or null.
		 */
		$graph = apply_filters( 'plseo_schema_graph', $graph, $post );

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo "<script type=\"application/ld+json\">\n";
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo "\n</script>\n";
	}

	/* ─────────────────── helpers exposed to contributors ─────────────────── */

	/** Canonical @id for the Organization node. */
	public static function org_id(): string {
		return trailingslashit( home_url( '/' ) ) . '#organization';
	}

	/** Canonical @id for the WebSite node. */
	public static function website_id(): string {
		return trailingslashit( home_url( '/' ) ) . '#website';
	}

	/** Canonical @id for a WebPage node (a given URL). */
	public static function webpage_id( string $url = '' ): string {
		$url = $url !== '' ? $url : self::current_url();
		return $url . '#webpage';
	}

	/** Canonical @id for an Article-style primary entity. */
	public static function primary_entity_id( string $url = '' ): string {
		$url = $url !== '' ? $url : self::current_url();
		return $url . '#primary';
	}

	/** Canonical @id for a Person (author) node. */
	public static function person_id( int $user_id ): string {
		return trailingslashit( home_url( '/' ) ) . '#person-' . $user_id;
	}

	public static function current_url(): string {
		if ( is_singular() ) {
			$p = get_queried_object();
			if ( $p instanceof \WP_Post ) {
				$override = (string) plseo_get_post_meta( $p->ID, 'canonical', '' );
				return $override !== '' ? $override : (string) get_permalink( $p );
			}
		}
		if ( is_front_page() ) {
			return (string) home_url( '/' );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_string( $link ) ? $link : (string) home_url( add_query_arg( null, null ) );
		}
		return (string) home_url( add_query_arg( null, null ) );
	}

	/**
	 * Look up an ImageObject node for a given attachment ID or URL.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function image_object( int $attachment_id = 0, string $fallback_url = '' ): ?array {
		$url = '';
		$w   = 0;
		$h   = 0;

		if ( $attachment_id > 0 ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( $src ) {
				$url = (string) $src[0];
				$w   = (int) $src[1];
				$h   = (int) $src[2];
			}
		}

		if ( '' === $url && '' !== $fallback_url ) {
			$url = $fallback_url;
		}

		if ( '' === $url ) {
			return null;
		}

		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => $url . '#image',
			'url'        => $url,
			'contentUrl' => $url,
		);
		if ( $w > 0 ) {
			$node['width']  = $w;
		}
		if ( $h > 0 ) {
			$node['height'] = $h;
		}
		return $node;
	}
}
