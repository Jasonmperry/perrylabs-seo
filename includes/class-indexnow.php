<?php
/**
 * PLSEO_IndexNow — ping Bing/Yandex when a post is published or updated.
 *
 * Generates a 32-char hex key on first activation, serves it at /{key}.txt
 * for verification, and POSTs the changed URL to the engines listed in
 * `indexnow_engines`. Best-effort: failures are logged but never block the
 * publish flow.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_IndexNow {

	private static ?self $instance = null;

	private const ENDPOINTS = array(
		'bing'   => 'https://www.bing.com/indexnow',
		'yandex' => 'https://yandex.com/indexnow',
	);

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', static function ( array $vars ): array {
			$vars[] = 'plseo_indexnow_key';
			return $vars;
		} );
		add_action( 'template_redirect', array( $this, 'serve_key_file' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
	}

	public static function register_rewrites(): void {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?plseo_indexnow_key=$matches[1]', 'top' );
	}

	public function key(): string {
		$saved = (string) get_option( 'plseo_indexnow_key', '' );
		if ( '' === $saved ) {
			$saved = bin2hex( random_bytes( 16 ) );
			update_option( 'plseo_indexnow_key', $saved );
		}
		return $saved;
	}

	public function serve_key_file(): void {
		$requested = (string) get_query_var( 'plseo_indexnow_key' );
		if ( '' === $requested ) {
			return;
		}
		if ( ! hash_equals( $this->key(), $requested ) ) {
			return; // Let WordPress 404.
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->key() );
		exit;
	}

	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! (bool) PLSEO_Options::get( 'indexnow_enabled', false ) ) {
			return;
		}
		// Only fire when a publicly-visible piece of content changes.
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$included = (array) PLSEO_Options::get( 'sitemap_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $included, true ) ) {
			return;
		}
		// Honor per-post noindex — if the page is noindex, don't tip off engines.
		if ( plseo_get_post_meta( $post->ID, 'noindex', '' ) === '1' ) {
			return;
		}

		$url = (string) get_permalink( $post );
		if ( '' === $url ) {
			return;
		}

		$this->submit( array( $url ) );
	}

	/**
	 * @param array<int,string> $urls
	 */
	public function submit( array $urls ): void {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
		if ( empty( $urls ) ) {
			return;
		}
		$engines = (array) PLSEO_Options::get( 'indexnow_engines', array( 'bing', 'yandex' ) );
		$host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$key     = $this->key();

		$body = wp_json_encode( array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => $urls,
		) );

		foreach ( $engines as $engine ) {
			if ( ! isset( self::ENDPOINTS[ $engine ] ) ) {
				continue;
			}
			wp_remote_post( self::ENDPOINTS[ $engine ], array(
				'timeout'    => 5,
				'blocking'   => false,
				'headers'    => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'       => $body,
				'user-agent' => 'PerryLabs SEO/' . PL_SEO_VERSION . ' (+' . home_url( '/' ) . ')',
			) );
		}
	}
}
