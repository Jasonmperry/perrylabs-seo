<?php
/**
 * PLSEO_Search_Log — record what visitors search for on your site.
 *
 * Captures `is_search()` queries (one row per query per day) and exposes
 * top-queries, zero-result-queries, and the time-series via getters.
 *
 * Privacy: we store only the query string + total result count + day. No IPs,
 * no user IDs. Bot UAs are filtered out so noise doesn't drown the signal.
 *
 * The "what people search for that returns 0 results" view is the gold —
 * each row is a content opportunity.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Search_Log {

	private static ?self $instance = null;

	public const TABLE = 'plseo_search_log';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_record' ), 40 );
		add_action( 'plseo_daily_maintenance', array( $this, 'prune' ) );
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			query_hash char(32) NOT NULL,
			query varchar(255) NOT NULL,
			result_count int NOT NULL DEFAULT 0,
			hit_count int NOT NULL DEFAULT 1,
			seen_date date NOT NULL,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY query_day (query_hash, seen_date),
			KEY seen_date (seen_date),
			KEY result_count (result_count)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function maybe_record(): void {
		if ( ! is_search() ) {
			return;
		}
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// Filter common bot UAs to avoid skewing the data.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( preg_match( '#(?:bot|spider|crawler|preview|monitor|curl|wget|http\b)#i', $ua ) ) {
			return;
		}

		$query = trim( get_search_query( false ) );
		if ( '' === $query || mb_strlen( $query ) > 255 ) {
			return;
		}

		global $wp_query;
		$results = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;

		$this->record( $query, $results );
	}

	public function record( string $query, int $result_count ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );
		$hash  = md5( mb_strtolower( $query ) );

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT INTO {$table} (query_hash, query, result_count, hit_count, seen_date, first_seen, last_seen)
			 VALUES (%s, %s, %d, 1, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen), result_count = VALUES(result_count)",
			$hash,
			$query,
			$result_count,
			$today,
			$now,
			$now
		) );
	}

	public function prune(): void {
		$days = (int) PLSEO_Options::get( 'search_log_retention_days', 90 );
		if ( $days < 1 ) {
			return;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"DELETE FROM {$wpdb->prefix}" . self::TABLE . ' WHERE seen_date < %s',
			$cutoff
		) );
	}

	/**
	 * @return array<int,object>
	 */
	public function top_queries( int $days = 30, int $limit = 25 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT query, SUM(hit_count) AS hits, MAX(last_seen) AS last_seen, AVG(result_count) AS avg_results
			 FROM {$wpdb->prefix}" . self::TABLE . ' WHERE seen_date >= %s GROUP BY query_hash ORDER BY hits DESC LIMIT %d',
			$since,
			$limit
		) );
	}

	/**
	 * Zero-result queries — content opportunities. People are searching and
	 * coming up empty.
	 *
	 * @return array<int,object>
	 */
	public function zero_result_queries( int $days = 30, int $limit = 25 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT query, SUM(hit_count) AS hits, MAX(last_seen) AS last_seen
			 FROM {$wpdb->prefix}" . self::TABLE . ' WHERE seen_date >= %s AND result_count = 0 GROUP BY query_hash ORDER BY hits DESC LIMIT %d',
			$since,
			$limit
		) );
	}

	public function total_searches( int $days = 30 ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(hit_count),0) FROM {$wpdb->prefix}" . self::TABLE . ' WHERE seen_date >= %s',
			$since
		) );
	}
}
