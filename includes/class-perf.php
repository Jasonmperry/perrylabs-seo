<?php
/**
 * PLSEO_Perf — Core Web Vitals helpers.
 *
 * Four interventions, each opt-in via an option:
 *
 *   1. resource_hints  — `dns-prefetch` and `preconnect` for known external
 *      hosts referenced in the social / business config (Twitter, Facebook,
 *      Google Fonts, etc.) so the browser warms TLS before render.
 *
 *   2. lazy_load_images — adds `loading="lazy"` and `decoding="async"` to
 *      `<img>` tags that lack the attribute. WordPress core does this for
 *      content images but not for theme markup or older posts.
 *
 *   3. fetchpriority_lcp — for the first `<img>` in the_content of a singular
 *      view (typically the LCP element), sets `fetchpriority="high"` and
 *      removes `loading="lazy"`. Lifts LCP without theme changes.
 *
 *   4. image_dimensions — when an `<img>` lacks width/height, fill them from
 *      the WP attachment metadata. Prevents CLS.
 *
 * Each intervention is small but the cumulative impact on Core Web Vitals is
 * real. None require external API calls; all logic is local string/regex work
 * over post content.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Perf {

	private static ?self $instance = null;

	private bool $lcp_consumed = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( (bool) PLSEO_Options::get( 'perf_resource_hints', true ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		}

		// the_content runs for singular and the loop. We tag attributes there.
		$apply_lazy   = (bool) PLSEO_Options::get( 'perf_lazy_load',     true );
		$apply_lcp    = (bool) PLSEO_Options::get( 'perf_fetchpriority', true );
		$apply_dims   = (bool) PLSEO_Options::get( 'perf_image_dimensions', true );

		if ( $apply_lazy || $apply_lcp || $apply_dims ) {
			add_filter( 'the_content', array( $this, 'process_content' ), 99 );
			add_action( 'loop_start',  array( $this, 'reset_lcp_marker' ) );
		}
	}

	/* ───────────────────────── resource hints ───────────────────────── */

	/**
	 * @param array<int,string|array<string,mixed>> $hints
	 */
	public function resource_hints( array $hints, string $relation_type ): array {
		$hosts = array();

		if ( 'dns-prefetch' === $relation_type ) {
			foreach ( $this->external_hosts() as $host ) {
				$hosts[] = '//' . $host;
			}
		}
		if ( 'preconnect' === $relation_type ) {
			// Preconnect for the heaviest ones only (TLS handshakes are expensive).
			$priority = array( 'www.googletagmanager.com', 'www.google-analytics.com', 'fonts.gstatic.com' );
			foreach ( $priority as $host ) {
				if ( in_array( $host, $this->external_hosts(), true ) ) {
					$hosts[] = array( 'href' => 'https://' . $host, 'crossorigin' );
				}
			}
		}

		return array_merge( $hints, $hosts );
	}

	/**
	 * Hosts we know we'll likely talk to. Derived from configured social URLs
	 * + analytics IDs (the analytics module registers more dynamically).
	 *
	 * @return array<int,string>
	 */
	private function external_hosts(): array {
		$hosts = array();
		foreach ( array( 'facebook_url', 'linkedin_url', 'instagram_url', 'youtube_url', 'mastodon_url' ) as $key ) {
			$url  = (string) PLSEO_Options::get( $key, '' );
			$host = $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}

		/**
		 * Filter the list of external hosts to hint at.
		 *
		 * @param array<int,string> $hosts
		 */
		return array_values( array_unique( (array) apply_filters( 'plseo_perf_external_hosts', $hosts ) ) );
	}

	/* ───────────────────────── image processing ───────────────────────── */

	public function reset_lcp_marker(): void {
		$this->lcp_consumed = false;
	}

	public function process_content( string $html ): string {
		if ( is_admin() || is_feed() || empty( $html ) ) {
			return $html;
		}

		$lcp_active = is_singular() && ! $this->lcp_consumed;

		return preg_replace_callback(
			'#<img\b([^>]*)/?>#i',
			function ( array $m ) use ( $lcp_active ): string {
				$attrs = $this->parse_attrs( $m[1] );

				if ( (bool) PLSEO_Options::get( 'perf_image_dimensions', true ) ) {
					$attrs = $this->ensure_dimensions( $attrs );
				}

				$is_lcp = false;
				if ( $lcp_active && ! $this->lcp_consumed ) {
					$is_lcp = true;
					$this->lcp_consumed = true;
				}

				if ( $is_lcp && (bool) PLSEO_Options::get( 'perf_fetchpriority', true ) ) {
					$attrs['fetchpriority'] = 'high';
					unset( $attrs['loading'] );
				} elseif ( (bool) PLSEO_Options::get( 'perf_lazy_load', true ) ) {
					if ( ! isset( $attrs['loading'] ) ) {
						$attrs['loading']  = 'lazy';
					}
					if ( ! isset( $attrs['decoding'] ) ) {
						$attrs['decoding'] = 'async';
					}
				}

				return '<img ' . $this->serialize_attrs( $attrs ) . ' />';
			},
			$html
		) ?? $html;
	}

	/**
	 * @param array<string,string> $attrs
	 * @return array<string,string>
	 */
	private function ensure_dimensions( array $attrs ): array {
		if ( ! empty( $attrs['width'] ) && ! empty( $attrs['height'] ) ) {
			return $attrs;
		}
		if ( empty( $attrs['src'] ) ) {
			return $attrs;
		}
		$attachment_id = attachment_url_to_postid( $attrs['src'] );
		if ( $attachment_id < 1 ) {
			return $attrs;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return $attrs;
		}
		$attrs['width']  = (string) $meta['width'];
		$attrs['height'] = (string) $meta['height'];
		return $attrs;
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_attrs( string $raw ): array {
		$out = array();
		if ( preg_match_all( '/([a-zA-Z\-_:]+)\s*=\s*(["\'])(.*?)\2/s', $raw, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$out[ strtolower( $pair[1] ) ] = $pair[3];
			}
		}
		// Boolean attributes (no value) — rare on <img>, but keep them around.
		if ( preg_match_all( '/\b([a-zA-Z\-_:]+)(?=\s|$)(?!\s*=)/', $raw, $bm ) ) {
			foreach ( $bm[1] as $name ) {
				$name = strtolower( $name );
				if ( ! isset( $out[ $name ] ) ) {
					$out[ $name ] = '';
				}
			}
		}
		return $out;
	}

	/**
	 * @param array<string,string> $attrs
	 */
	private function serialize_attrs( array $attrs ): string {
		$parts = array();
		foreach ( $attrs as $name => $value ) {
			if ( '' === $value ) {
				$parts[] = esc_html( $name );
			} else {
				$parts[] = sprintf( '%s="%s"', esc_html( $name ), esc_attr( $value ) );
			}
		}
		return implode( ' ', $parts );
	}
}
