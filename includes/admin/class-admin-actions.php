<?php
/**
 * PLSEO_Admin_Actions — admin-post handlers.
 *
 * Lifted out of PLSEO_Admin so menu/render concerns stay in one place
 * and write-side concerns (nonce checks, capability gates, redirects,
 * CSV import/export, settings JSON, 404 promotion) stay in another.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Admin_Actions {

	public static function boot(): void {
		add_action( 'admin_post_plseo_redirect_create', array( __CLASS__, 'redirect_create' ) );
		add_action( 'admin_post_plseo_redirect_delete', array( __CLASS__, 'redirect_delete' ) );
		add_action( 'admin_post_plseo_redirect_import', array( __CLASS__, 'redirect_import' ) );
		add_action( 'admin_post_plseo_redirect_export', array( __CLASS__, 'redirect_export' ) );
		add_action( 'admin_post_plseo_404_resolve',     array( __CLASS__, 'log404_resolve' ) );
		add_action( 'admin_post_plseo_404_delete',      array( __CLASS__, 'log404_delete' ) );
		add_action( 'admin_post_plseo_404_promote',     array( __CLASS__, 'log404_promote' ) );
		add_action( 'admin_post_plseo_settings_export', array( __CLASS__, 'settings_export' ) );
		add_action( 'admin_post_plseo_settings_import', array( __CLASS__, 'settings_import' ) );
	}

	public static function redirect_create(): void {
		check_admin_referer( 'plseo_redirect_create' );
		self::require_cap();
		$id = PLSEO_Redirects::instance()->create( array(
			'source_url'  => (string) ( $_POST['source_url'] ?? '' ),
			'target_url'  => (string) ( $_POST['target_url'] ?? '' ),
			'status_code' => (int) ( $_POST['status_code'] ?? 301 ),
			'match_type'  => (string) ( $_POST['match_type'] ?? 'exact' ),
			'notes'       => (string) ( $_POST['notes'] ?? '' ),
		) );
		self::redirect_back( 'plseo-redirects', $id ? 'created' : 'failed' );
	}

	public static function redirect_delete(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_redirect_delete_' . $id );
		self::require_cap();
		PLSEO_Redirects::instance()->delete( $id );
		self::redirect_back( 'plseo-redirects', 'deleted' );
	}

	public static function redirect_import(): void {
		check_admin_referer( 'plseo_redirect_import' );
		self::require_cap();
		if ( empty( $_FILES['plseo_csv']['tmp_name'] ) ) {
			self::redirect_back( 'plseo-redirects', 'no-file' );
		}
		$result = PLSEO_Redirects_CSV::import_from_path( (string) $_FILES['plseo_csv']['tmp_name'] );
		self::redirect_back( 'plseo-redirects', 'imported', array(
			'added'   => (int) $result['added'],
			'skipped' => (int) $result['skipped'],
		) );
	}

	public static function redirect_export(): void {
		check_admin_referer( 'plseo_redirect_export' );
		self::require_cap();
		PLSEO_Redirects_CSV::stream_export();
	}

	public static function log404_resolve(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_resolve_' . $id );
		self::require_cap();
		PLSEO_404_Log::instance()->mark_resolved( $id );
		self::redirect_back( 'plseo-404-log', 'resolved' );
	}

	public static function log404_delete(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_delete_' . $id );
		self::require_cap();
		PLSEO_404_Log::instance()->delete( $id );
		self::redirect_back( 'plseo-404-log', 'deleted' );
	}

	public static function log404_promote(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_promote_' . $id );
		self::require_cap();
		$created = PLSEO_Redirects::instance()->create( array(
			'source_url'  => (string) ( $_POST['source_url'] ?? '' ),
			'target_url'  => (string) ( $_POST['target_url'] ?? '' ),
			'status_code' => 301,
			'match_type'  => 'exact',
			'notes'       => __( 'Promoted from 404 log', 'perrylabs-seo' ),
		) );
		if ( $created ) {
			PLSEO_404_Log::instance()->mark_resolved( $id );
		}
		self::redirect_back( 'plseo-404-log', $created ? 'promoted' : 'failed' );
	}

	public static function settings_export(): void {
		check_admin_referer( 'plseo_settings_export' );
		self::require_cap();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plseo-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( array(
			'plugin'      => 'perrylabs-seo',
			'version'     => PL_SEO_VERSION,
			'exported_at' => gmdate( 'c' ),
			'options'     => PLSEO_Options::all(),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function settings_import(): void {
		check_admin_referer( 'plseo_settings_import' );
		self::require_cap();
		if ( empty( $_FILES['plseo_import_file']['tmp_name'] ) ) {
			self::redirect_back( PLSEO_Admin::MENU_SLUG, 'no-file', array( 'tab' => 'tools' ) );
		}
		$json = file_get_contents( (string) $_FILES['plseo_import_file']['tmp_name'] );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
			self::redirect_back( PLSEO_Admin::MENU_SLUG, 'bad-file', array( 'tab' => 'tools' ) );
		}
		PLSEO_Options::update( $data['options'] );
		self::redirect_back( PLSEO_Admin::MENU_SLUG, 'imported', array( 'tab' => 'tools' ) );
	}

	/* ───────────────────────── shared ───────────────────────── */

	private static function require_cap(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
	}

	private static function redirect_back( string $page, string $status, array $extra = array() ): void {
		$url = add_query_arg(
			array_merge( array( 'page' => $page, 'plseo_status' => $status ), $extra ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
