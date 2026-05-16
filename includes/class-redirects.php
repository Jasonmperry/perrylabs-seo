<?php
/**
 * PLSEO_Redirects — manage and serve 301/302 redirects.
 *
 * Two match modes:
 *   - exact  : exact-path match (case-insensitive comparison on stored value).
 *   - regex  : PCRE pattern on the request path; `target_url` may use $1/$2 captures.
 *
 * Hit counter increments atomically. Last-hit timestamp recorded for cleanup.
 * Admin CRUD and CSV I/O live in PLSEO_Admin / PLSEO_Redirects_CSV — this module
 * is the data layer and request-time matcher.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Redirects {

	private static ?self $instance = null;

	public const TABLE = 'plseo_redirects';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Early — beats 404 detection and most plugin redirects.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 2 );
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_url varchar(2048) NOT NULL,
			target_url varchar(2048) NOT NULL,
			status_code smallint NOT NULL DEFAULT 301,
			match_type varchar(16) NOT NULL DEFAULT 'exact',
			hits int NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			notes text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_url (source_url(191)),
			KEY match_type (match_type)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ───────────────────────── matching ───────────────────────── */

	public function maybe_redirect(): void {
		if ( is_admin() ) {
			return;
		}
		$path = $this->current_path();
		if ( '' === $path ) {
			return;
		}

		// 1. Exact match (fast path — indexed).
		$exact = $this->find_exact( $path );
		if ( $exact ) {
			$this->perform( $exact, $exact->target_url );
		}

		// 2. Regex sweep. Limit to a reasonable batch so the page stays snappy.
		foreach ( $this->load_regex_rules() as $rule ) {
			$out = $this->apply_regex( $rule, $path );
			if ( null !== $out ) {
				$this->perform( $rule, $out );
			}
		}
	}

	private function current_path(): string {
		$raw = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $raw ) {
			return '';
		}
		$parsed = wp_parse_url( $raw, PHP_URL_PATH );
		$path   = is_string( $parsed ) ? $parsed : $raw;
		// Normalize trailing slash for matching; keep the original for regex.
		return '/' . ltrim( $path, '/' );
	}

	private function find_exact( string $path ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, source_url, target_url, status_code FROM {$table} WHERE match_type='exact' AND (source_url=%s OR source_url=%s) LIMIT 1",
			$path,
			rtrim( $path, '/' )
		) );
		return $row ?: null;
	}

	/** @return array<int,object> */
	private function load_regex_rules(): array {
		$cached = wp_cache_get( 'plseo_regex_rules', 'plseo' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = $wpdb->get_results( "SELECT id, source_url, target_url, status_code FROM {$table} WHERE match_type='regex' LIMIT 200" );
		$rows  = is_array( $rows ) ? $rows : array();
		wp_cache_set( 'plseo_regex_rules', $rows, 'plseo', MINUTE_IN_SECONDS );
		return $rows;
	}

	private function apply_regex( object $rule, string $path ): ?string {
		$pattern = (string) $rule->source_url;
		if ( '' === $pattern ) {
			return null;
		}
		// Author-supplied patterns. If they didn't include delimiters, wrap with '#…#i'.
		if ( ! in_array( $pattern[0], array( '#', '/', '~' ), true ) ) {
			$pattern = '#' . str_replace( '#', '\#', $pattern ) . '#i';
		}
		$count = 0;
		$out   = @preg_replace( $pattern, (string) $rule->target_url, $path, 1, $count ); // phpcs:ignore Generic.PHP.NoSilencedErrors
		if ( null === $out || $count < 1 || $out === $path ) {
			return null;
		}
		return (string) $out;
	}

	private function perform( object $rule, string $target ): void {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$status = in_array( (int) $rule->status_code, array( 301, 302, 307, 308 ), true ) ? (int) $rule->status_code : 301;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
			current_time( 'mysql' ),
			(int) $rule->id
		) );

		// If target starts with '/', resolve against home_url for sanity.
		if ( '' !== $target && '/' === $target[0] ) {
			$target = home_url( $target );
		}

		wp_safe_redirect( $target, $status, 'PerryLabs SEO' );
		exit;
	}

	/* ───────────────────────── CRUD (used by admin UI, REST, CLI) ───────────────────────── */

	/**
	 * @param array{source_url:string,target_url:string,status_code?:int,match_type?:string,notes?:string} $data
	 */
	public function create( array $data ): int|false {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'source_url'  => self::normalize_source( (string) $data['source_url'], (string) ( $data['match_type'] ?? 'exact' ) ),
				'target_url'  => (string) $data['target_url'],
				'status_code' => (int) ( $data['status_code'] ?? 301 ),
				'match_type'  => in_array( $data['match_type'] ?? 'exact', array( 'exact', 'regex' ), true ) ? (string) $data['match_type'] : 'exact',
				'created_at'  => current_time( 'mysql' ),
				'notes'       => (string) ( $data['notes'] ?? '' ),
				'hits'        => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);
		wp_cache_delete( 'plseo_regex_rules', 'plseo' );
		return $ok ? (int) $wpdb->insert_id : false;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$ok    = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		wp_cache_delete( 'plseo_regex_rules', 'plseo' );
		return (bool) $ok;
	}

	/**
	 * @return array<int,object>
	 */
	public function all( int $limit = 200, int $offset = 0, string $orderby = 'id', string $order = 'DESC' ): array {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$orderby = preg_match( '/^[a-z_]+$/', $orderby ) ? $orderby : 'id';
		$order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
		return is_array( $rows ) ? $rows : array();
	}

	public function count_all(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function normalize_source( string $source, string $type ): string {
		if ( 'regex' === $type ) {
			return $source;
		}
		// Strip protocol+host so the same redirect works in dev/staging/prod.
		$parsed = wp_parse_url( $source );
		if ( is_array( $parsed ) && ! empty( $parsed['path'] ) ) {
			$source = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
		}
		return '/' . ltrim( $source, '/' );
	}
}
