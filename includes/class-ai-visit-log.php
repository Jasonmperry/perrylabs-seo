<?php
/**
 * PLSEO_AI_Visit_Log — record when an AI crawler hits the site.
 *
 * The signal is not "we got cited" (Perplexity/ChatGPT do not pass that back),
 * but it IS "an answer engine looked at us" — which is the strongest free signal
 * available today that your AEO work is producing visibility.
 *
 * To keep table size sane:
 *   - We dedupe by (bot_slug, day, url_hash) — one row per bot per URL per day.
 *   - Retention defaults to 90 days; configurable.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_AI_Visit_Log {

	private static ?self $instance = null;

	public const TABLE = 'plseo_ai_visits';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( $this, 'maybe_record' ), 1 );
		add_action( 'plseo_daily_maintenance', array( $this, 'prune' ) );
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			bot_slug varchar(64) NOT NULL,
			url_hash char(32) NOT NULL,
			url varchar(2048) NOT NULL,
			hit_count int NOT NULL DEFAULT 1,
			seen_date date NOT NULL,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bot_url_day (bot_slug, url_hash, seen_date),
			KEY bot_slug (bot_slug),
			KEY seen_date (seen_date)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function maybe_record(): void {
		if ( ! (bool) PLSEO_Options::get( 'aeo_log_ai_visits', true ) ) {
			return;
		}
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua ) {
			return;
		}

		$bot = PLSEO_AI_Crawlers::instance()->match_user_agent( $ua );
		if ( null === $bot ) {
			return;
		}

		$url = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$this->record( $bot, $url );
	}

	public function record( string $bot_slug, string $url ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );
		$hash  = md5( $url );

		// One write — upsert via INSERT ... ON DUPLICATE KEY UPDATE.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"INSERT INTO {$table} (bot_slug, url_hash, url, hit_count, seen_date, first_seen, last_seen)
			 VALUES (%s, %s, %s, 1, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)",
			$bot_slug,
			$hash,
			$url,
			$today,
			$now,
			$now
		) );
	}

	public function prune(): void {
		$days = (int) PLSEO_Options::get( 'aeo_ai_visit_retention_days', 90 );
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
	 * Totals per bot over the last N days.
	 *
	 * @return array<int,object>
	 */
	public function summary_by_bot( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT bot_slug, SUM(hit_count) AS hits, COUNT(DISTINCT url_hash) AS unique_urls, MAX(last_seen) AS last_seen
			 FROM {$table} WHERE seen_date >= %s GROUP BY bot_slug ORDER BY hits DESC",
			$since
		) );
	}

	/**
	 * Most-visited URLs.
	 *
	 * @return array<int,object>
	 */
	public function top_urls( int $days = 30, int $limit = 25 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT url, SUM(hit_count) AS hits, COUNT(DISTINCT bot_slug) AS bots
			 FROM {$table} WHERE seen_date >= %s
			 GROUP BY url ORDER BY hits DESC LIMIT %d",
			$since,
			$limit
		) );
	}

	public function total_hits( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(hit_count),0) FROM {$table} WHERE seen_date >= %s",
			$since
		) );
	}
}
