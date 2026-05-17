<?php
/**
 * PLSEO_Link_Graph — internal-link bookkeeping.
 *
 * Every published post is scanned for its outbound internal links on save.
 * We store the resolved target IDs as a serialized array in `_plseo_internal_links`
 * post meta, and compute inbound counts (and orphan detection) on demand.
 *
 * Why post meta rather than a dedicated table:
 *   - We don't need joins; reverse lookups are cheap with a meta_query.
 *   - Storage cost is small (~tens of bytes per post).
 *   - WP's object cache layer covers the hot path.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Link_Graph {

	private static ?self $instance = null;

	private const META_OUT = '_plseo_internal_links';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'save_post', array( $this, 'rebuild_for_post' ), 20, 2 );
	}

	/**
	 * Scan a post's content for internal links and persist the resolved post IDs.
	 *
	 * Storage format: comma-bracketed string like ",5,7,12," (not a PHP-serialized
	 * array). Bracketing both ends lets inbound_count() use the LIKE pattern
	 * `%,N,%` to count references unambiguously — a serialized array would have
	 * its sequential indices (i:0;, i:1;, …) collide with value-side IDs when
	 * the count grows past the matched ID number.
	 */
	public function rebuild_for_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			delete_post_meta( $post_id, self::META_OUT );
			return;
		}
		$targets = $this->extract_internal_targets( (string) $post->post_content, $post_id );
		if ( empty( $targets ) ) {
			delete_post_meta( $post_id, self::META_OUT );
			return;
		}
		update_post_meta( $post_id, self::META_OUT, self::serialize_targets( $targets ) );
	}

	/**
	 * @param array<int,int> $ids
	 */
	public static function serialize_targets( array $ids ): string {
		$clean = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) );
		return ',' . implode( ',', $clean ) . ',';
	}

	/**
	 * @return array<int,int>
	 */
	public static function parse_targets( string $serialized ): array {
		$serialized = trim( $serialized, ',' );
		if ( '' === $serialized ) {
			return array();
		}
		return array_map( 'intval', explode( ',', $serialized ) );
	}

	/**
	 * Resolve in-content <a href> values that point at this site to post IDs.
	 *
	 * @return array<int,int>
	 */
	private function extract_internal_targets( string $content, int $self_id ): array {
		if ( '' === $content ) {
			return array();
		}
		if ( ! preg_match_all( '#<a[^>]+href=["\']([^"\']+)["\']#i', $content, $m ) ) {
			return array();
		}
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$ids   = array();

		foreach ( array_unique( $m[1] ) as $href ) {
			$href_host = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( '' !== $href_host && $href_host !== $host ) {
				continue;
			}
			$id = url_to_postid( $href );
			if ( $id > 0 && $id !== $self_id ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Inbound link count for one post. O(meta_query); cached per request via static memo.
	 *
	 * @var array<int,int>
	 */
	private array $inbound_cache = array();

	public function inbound_count( int $post_id ): int {
		if ( array_key_exists( $post_id, $this->inbound_cache ) ) {
			return $this->inbound_cache[ $post_id ];
		}
		global $wpdb;
		// Match against the comma-bracketed string format. `%,5,%` matches the
		// stored value `,5,7,12,` but NOT a post that happens to have 5 outbound
		// links (which would be `,1,2,3,4,5,` — also matches, but correctly).
		// Crucially, it does NOT match against a serialized-array index like
		// `i:5;` which was the v1 bug.
		$needle = '%,' . $post_id . ',%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
			self::META_OUT,
			$needle
		) );
		$this->inbound_cache[ $post_id ] = $n;
		return $n;
	}

	/**
	 * IDs of published posts with zero inbound internal links and no parent.
	 *
	 * @param array<int,string> $post_types
	 * @param int               $limit
	 * @return array<int,int>
	 */
	public function orphans( array $post_types, int $limit = 100 ): array {
		$q = new \WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$out = array();
		foreach ( $q->posts as $pid ) {
			$pid = (int) $pid;
			if ( $this->inbound_count( $pid ) === 0 ) {
				$out[] = $pid;
			}
		}
		return $out;
	}
}
