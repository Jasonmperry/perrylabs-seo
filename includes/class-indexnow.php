<?php
/**
 * PerryLabs SEO + AEO — IndexNow Integration
 *
 * Pings the IndexNow API when posts are published, updated, or trashed,
 * so search engines discover content changes in near-real-time.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_IndexNow {

	/** @var string Transient key for the ping log. */
	private const LOG_TRANSIENT = 'perrylabs_seo_indexnow_log';

	/** @var int Maximum log entries to keep. */
	private const LOG_MAX = 10;

	/** @var string IndexNow API endpoint. */
	private const API_ENDPOINT = 'https://api.indexnow.org/indexnow';

	public function __construct() {
		add_action( 'transition_post_status', array( $this, 'handle_post_transition' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'serve_key_file' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Rewrite rules
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Register the rewrite rule for the API key verification file.
	 *
	 * Call this from the plugin activation hook, then flush_rewrite_rules().
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.txt$',
			'index.php?perrylabs_indexnow_key=$matches[1]',
			'top'
		);

		add_filter( 'query_vars', function ( array $vars ): array {
			$vars[] = 'perrylabs_indexnow_key';
			return $vars;
		} );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Key file endpoint
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Intercept requests for the key verification file and serve plain text.
	 */
	public function serve_key_file(): void {
		$requested_key = get_query_var( 'perrylabs_indexnow_key', '' );

		if ( '' === $requested_key ) {
			return;
		}

		$api_key = self::get_api_key();

		if ( $requested_key !== $api_key ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $api_key );
		exit;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Post status transitions
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Handle post status transitions and ping IndexNow when appropriate.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function handle_post_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! perrylabs_seo_get_option( 'indexnow_enabled', false ) ) {
			return;
		}

		// Only act on meaningful transitions.
		$dominated_transitions = array(
			'publish_publish', // Updated.
			'draft_publish',   // Newly published.
			'pending_publish', // Approved & published.
			'future_publish',  // Scheduled → live.
			'publish_trash',   // Trashed.
		);

		$transition = $old_status . '_' . $new_status;

		if ( ! in_array( $transition, $dominated_transitions, true ) ) {
			return;
		}

		// Only ping for public post types.
		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object || ! $post_type_object->public ) {
			return;
		}

		$url = get_permalink( $post );

		if ( ! $url ) {
			return;
		}

		$this->ping( $url );
	}

	/* ──────────────────────────────────────────────────────────────
	 * IndexNow API ping
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Send a non-blocking ping to the IndexNow API.
	 *
	 * @param string $url The URL to submit.
	 */
	private function ping( string $url ): void {
		$api_key  = self::get_api_key();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$key_url  = home_url( $api_key . '.txt' );

		$body = wp_json_encode( array(
			'host'        => $host,
			'key'         => $api_key,
			'keyLocation' => $key_url,
			'urlList'     => array( $url ),
		) );

		$response = wp_remote_post( self::API_ENDPOINT, array(
			'blocking' => false,
			'timeout'  => 5,
			'headers'  => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
			'body'     => $body,
		) );

		$status = is_wp_error( $response ) ? $response->get_error_message() : 'submitted';

		self::log_ping( $url, $status );
	}

	/* ──────────────────────────────────────────────────────────────
	 * API key management
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Retrieve the IndexNow API key, generating one if it does not exist.
	 *
	 * @return string UUID-formatted API key.
	 */
	public static function get_api_key(): string {
		$key = perrylabs_seo_get_option( 'indexnow_api_key', '' );

		if ( '' !== $key ) {
			return $key;
		}

		// Auto-generate a UUID v4.
		$key = wp_generate_uuid4();

		$options                     = get_option( 'perrylabs_seo_options', array() );
		$options['indexnow_api_key'] = $key;
		update_option( 'perrylabs_seo_options', $options );

		return $key;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Ping log
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Log an IndexNow ping, keeping only the most recent entries.
	 *
	 * @param string $url    The URL that was pinged.
	 * @param string $status Response status or error message.
	 */
	private static function log_ping( string $url, string $status ): void {
		$log = get_transient( self::LOG_TRANSIENT );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, array(
			'url'    => $url,
			'status' => $status,
			'time'   => current_time( 'mysql' ),
		) );

		$log = array_slice( $log, 0, self::LOG_MAX );

		set_transient( self::LOG_TRANSIENT, $log, DAY_IN_SECONDS );
	}
}
