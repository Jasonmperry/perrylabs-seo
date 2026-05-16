<?php
/**
 * PLSEO_404_Log — capture 404 hits to surface redirect opportunities.
 *
 * Dedupes by URL with an incrementing hit counter. Retention controlled by
 * the `redirects_log_retention_days` option (default 60). The "create redirect
 * from this 404" workflow lives in PLSEO_Admin — this module is the data layer.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_404_Log {

	private static ?self $instance = null;

	public const TABLE = 'plseo_404_log';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_capture' ), 50 );
		add_action( 'plseo_daily_maintenance', array( $this, 'prune' ) );
		if ( ! wp_next_scheduled( 'plseo_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plseo_daily_maintenance' );
		}
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			referrer varchar(2048) DEFAULT NULL,
			hits int NOT NULL DEFAULT 1,
			last_hit datetime NOT NULL,
			created_at datetime NOT NULL,
			resolved tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY url (url(191)),
			KEY resolved (resolved)
		) {$collate};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function maybe_capture(): void {
		if ( ! is_404() ) {
			return;
		}
		if ( ! (bool) PLSEO_Options::get( 'redirects_log_404s', true ) ) {
			return;
		}
		if ( is_admin() || is_robots() || is_feed() ) {
			return;
		}

		$url = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $url ) {
			return;
		}
		// Skip noisy bots probing common files.
		if ( preg_match( '#(\.php|\.aspx|/wp-admin/|/xmlrpc\.php)#i', $url ) ) {
			return;
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT id FROM {$table} WHERE url = %s LIMIT 1",
			$url
		) );

		if ( $existing ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
				$now,
				(int) $existing
			) );
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'url'        => $url,
				'referrer'   => $referrer !== '' ? $referrer : null,
				'hits'       => 1,
				'last_hit'   => $now,
				'created_at' => $now,
				'resolved'   => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d' )
		);
	}

	public function prune(): void {
		$days = (int) PLSEO_Options::get( 'redirects_log_retention_days', 60 );
		if ( $days < 1 ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// Keep resolved rows around; drop unresolved older than cutoff.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"DELETE FROM {$table} WHERE resolved = 0 AND last_hit < %s",
			$cutoff
		) );
	}

	/**
	 * @return array<int,object>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE resolved = 0 ORDER BY hits DESC, last_hit DESC LIMIT %d",
			$limit
		) );
		return is_array( $rows ) ? $rows : array();
	}

	public function mark_resolved( int $id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'resolved' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}

	public function count_unresolved(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE . ' WHERE resolved = 0' );
	}
}
