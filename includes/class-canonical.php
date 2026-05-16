<?php
/**
 * PLSEO_Canonical — domain-level canonical enforcement.
 *
 * Three opt-in interventions for serving every visitor on the canonical URL:
 *
 *   - force_https      : redirect http://… → https://…
 *   - force_www_mode   : 'add' (non-www → www), 'strip' (www → non-www), 'off'
 *   - trailing_slash   : 'add' (force trailing /), 'strip' (no trailing /), 'off'
 *
 * Runs at the template_redirect priority 1 so it beats most plugin redirects.
 * Each redirect is a 301. Skips wp-admin, REST, and asset paths.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Canonical {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) || $this->is_asset_request() ) {
			return;
		}
		$request = $this->current_url();
		if ( '' === $request ) {
			return;
		}

		$next = $request;
		$next = $this->maybe_force_https( $next );
		$next = $this->maybe_apply_www( $next );
		$next = $this->maybe_apply_slash( $next );

		if ( $next === $request ) {
			return;
		}

		wp_safe_redirect( $next, 301, 'PerryLabs SEO Canonical' );
		exit;
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $host || '' === $uri ) {
			return '';
		}
		return $scheme . '://' . $host . $uri;
	}

	private function is_asset_request(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return (bool) preg_match( '#\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map|xml|txt|json)(\?|$)#i', $uri );
	}

	private function maybe_force_https( string $url ): string {
		if ( ! (bool) PLSEO_Options::get( 'canon_force_https', false ) ) {
			return $url;
		}
		return preg_replace( '#^http://#i', 'https://', $url ) ?? $url;
	}

	private function maybe_apply_www( string $url ): string {
		$mode = (string) PLSEO_Options::get( 'canon_www_mode', 'off' );
		if ( 'off' === $mode ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$host = (string) $parts['host'];

		if ( 'add' === $mode && ! str_starts_with( $host, 'www.' ) ) {
			// Don't add www to a single-label host (localhost, fly.dev subdomains, etc.).
			if ( substr_count( $host, '.' ) >= 1 ) {
				$host = 'www.' . $host;
			}
		}
		if ( 'strip' === $mode && str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$parts['host'] = $host;
		return self::unparse_url( $parts );
	}

	private function maybe_apply_slash( string $url ): string {
		$mode = (string) PLSEO_Options::get( 'canon_trailing_slash', 'off' );
		if ( 'off' === $mode ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return $url;
		}
		$path = (string) $parts['path'];

		// Never modify the root or paths with file extensions.
		if ( '/' === $path || preg_match( '#\.[a-z0-9]{1,8}$#i', $path ) ) {
			return $url;
		}

		if ( 'add' === $mode && '/' !== substr( $path, -1 ) ) {
			$parts['path'] = $path . '/';
		}
		if ( 'strip' === $mode && '/' === substr( $path, -1 ) ) {
			$parts['path'] = rtrim( $path, '/' );
			if ( '' === $parts['path'] ) {
				$parts['path'] = '/';
			}
		}
		return self::unparse_url( $parts );
	}

	/**
	 * @param array<string,mixed> $p
	 */
	private static function unparse_url( array $p ): string {
		$scheme   = isset( $p['scheme'] )   ? $p['scheme'] . '://' : '';
		$host     = isset( $p['host'] )     ? (string) $p['host'] : '';
		$port     = isset( $p['port'] )     ? ':' . (int) $p['port'] : '';
		$user     = isset( $p['user'] )     ? (string) $p['user'] : '';
		$pass     = isset( $p['pass'] )     ? ':' . (string) $p['pass'] : '';
		$pass     = ( $user || $pass )       ? $pass . '@' : '';
		$path     = isset( $p['path'] )     ? (string) $p['path'] : '';
		$query    = isset( $p['query'] )    ? '?' . (string) $p['query'] : '';
		$fragment = isset( $p['fragment'] ) ? '#' . (string) $p['fragment'] : '';
		return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
	}
}
