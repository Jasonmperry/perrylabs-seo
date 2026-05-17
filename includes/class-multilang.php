<?php
/**
 * PLSEO_Multilang — auto-hreflang from Polylang or WPML translation maps.
 *
 * The per-post `_plseo_hreflang` field lets authors specify alternates by
 * hand. When Polylang or WPML is active, the translations already exist in
 * the database — we shouldn't make authors maintain them twice. This module
 * hooks the existing hreflang resolver and merges in auto-detected
 * translations from whichever plugin is present.
 *
 * Manual overrides always win — anything the author entered in the meta-box
 * hreflang table is preserved as-is. Auto-detected entries are added on top
 * for languages that aren't already represented.
 *
 * Detection logic:
 *   - Polylang  : `function_exists('pll_get_post_translations')`
 *   - WPML      : `defined('ICL_LANGUAGE_CODE')` AND apply_filters('wpml_active_languages',...) returns array
 *
 * No-op when neither is loaded.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Multilang {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( ! $this->is_polylang() && ! $this->is_wpml() ) {
			return;
		}
		add_filter( 'plseo_hreflang_alternates', array( $this, 'merge_translations' ), 10, 2 );
	}

	private function is_polylang(): bool {
		return function_exists( 'pll_get_post_translations' );
	}

	private function is_wpml(): bool {
		return defined( 'ICL_LANGUAGE_CODE' ) && function_exists( 'apply_filters' )
			&& is_array( apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) );
	}

	/**
	 * Filter callback. Takes the manual `_plseo_hreflang` parse + adds detected
	 * translations for languages the author hasn't already listed.
	 *
	 * @param array<int,array{lang:string,url:string}> $alternates
	 * @param \WP_Post                                  $post
	 * @return array<int,array{lang:string,url:string}>
	 */
	public function merge_translations( array $alternates, \WP_Post $post ): array {
		$existing_langs = array_map(
			static fn( $a ) => strtolower( (string) $a['lang'] ),
			$alternates
		);

		$detected = $this->is_polylang()
			? $this->detect_polylang( $post )
			: $this->detect_wpml( $post );

		foreach ( $detected as $row ) {
			if ( in_array( strtolower( $row['lang'] ), $existing_langs, true ) ) {
				continue;
			}
			$alternates[] = $row;
		}
		return $alternates;
	}

	/**
	 * @return array<int,array{lang:string,url:string}>
	 */
	private function detect_polylang( \WP_Post $post ): array {
		$translations = pll_get_post_translations( $post->ID );
		if ( ! is_array( $translations ) || empty( $translations ) ) {
			return array();
		}
		$out = array();
		foreach ( $translations as $lang_slug => $target_id ) {
			$target_id = (int) $target_id;
			if ( $target_id < 1 || $target_id === (int) $post->ID ) {
				continue;
			}
			$url = (string) get_permalink( $target_id );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'lang' => self::normalize_lang( (string) $lang_slug ),
				'url'  => $url,
			);
		}
		return $out;
	}

	/**
	 * @return array<int,array{lang:string,url:string}>
	 */
	private function detect_wpml( \WP_Post $post ): array {
		$out  = array();
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $langs ) ) {
			return $out;
		}
		foreach ( $langs as $code => $info ) {
			$target_id = apply_filters( 'wpml_object_id', (int) $post->ID, $post->post_type, false, (string) $code );
			if ( ! $target_id || (int) $target_id === (int) $post->ID ) {
				continue;
			}
			$url = (string) get_permalink( (int) $target_id );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'lang' => self::normalize_lang( (string) $code ),
				'url'  => $url,
			);
		}
		return $out;
	}

	/**
	 * Polylang/WPML codes are often 2-letter ('en'); BCP-47 conventionally
	 * wants region too. We leave 2-letter codes alone (valid hreflang) and
	 * only normalize underscores → dashes.
	 */
	private static function normalize_lang( string $code ): string {
		return str_replace( '_', '-', $code );
	}
}
