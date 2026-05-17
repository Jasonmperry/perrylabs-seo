<?php
/**
 * PLSEO_Importer — import per-post SEO meta from Yoast SEO or RankMath.
 *
 * Why it matters: every WordPress site moving to PerryLabs SEO today already
 * has years of meta keys stored by Yoast or RankMath. Migrating that data
 * over so the new plugin renders the same titles/descriptions on day one is
 * the difference between "drop-in upgrade" and "rebuild your SEO".
 *
 * Source key maps (verified against current Yoast / RankMath releases):
 *
 *   Yoast SEO
 *     _yoast_wpseo_title           → _plseo_title
 *     _yoast_wpseo_metadesc        → _plseo_description
 *     _yoast_wpseo_canonical       → _plseo_canonical
 *     _yoast_wpseo_opengraph-image → _plseo_social_image
 *     _yoast_wpseo_focuskw         → _plseo_focus_keyword
 *     _yoast_wpseo_meta-robots-noindex  ('1') → _plseo_noindex
 *     _yoast_wpseo_meta-robots-nofollow ('1') → _plseo_nofollow
 *     _yoast_wpseo_is_cornerstone  ('1')      → _plseo_cornerstone
 *
 *   RankMath
 *     rank_math_title              → _plseo_title
 *     rank_math_description        → _plseo_description
 *     rank_math_canonical_url      → _plseo_canonical
 *     rank_math_facebook_image     → _plseo_social_image
 *     rank_math_focus_keyword      → _plseo_focus_keyword (first comma-segment)
 *     rank_math_robots             (array containing 'noindex')  → _plseo_noindex
 *     rank_math_robots             (array containing 'nofollow') → _plseo_nofollow
 *     rank_math_pillar_content     ('on')                        → _plseo_cornerstone
 *
 * Yoast/RankMath title templates use %%title%% style tokens; we leave those
 * verbatim because the calling site can either set its own templates or
 * accept the literal text. Future enhancement: token translation.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Importer {

	/**
	 * Detect which third-party SEO plugins have meta in the DB.
	 *
	 * @return array<int,string> Slugs: 'yoast', 'rankmath'
	 */
	public static function detect_sources(): array {
		global $wpdb;
		$out = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_yoast = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_yoast_wpseo_title' LIMIT 1"
		);
		if ( $has_yoast > 0 ) {
			$out[] = 'yoast';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$has_rankmath = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='rank_math_title' LIMIT 1"
		);
		if ( $has_rankmath > 0 ) {
			$out[] = 'rankmath';
		}

		return $out;
	}

	/**
	 * Count posts that have at least one source-side meta key set.
	 */
	public static function count_eligible( string $source ): int {
		global $wpdb;
		$key = self::primary_key_for( $source );
		if ( '' === $key ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$key
		) );
	}

	private static function primary_key_for( string $source ): string {
		return match ( $source ) {
			'yoast'    => '_yoast_wpseo_title',
			'rankmath' => 'rank_math_title',
			default    => '',
		};
	}

	/**
	 * Run the migration for one source.
	 *
	 * @param string $source 'yoast' | 'rankmath'
	 * @param array{batch?:int,overwrite?:bool,dry_run?:bool} $opts
	 * @return array{processed:int,written:int,skipped:int,errors:array<int,string>}
	 */
	public static function run( string $source, array $opts = array() ): array {
		$batch     = max( 1, (int) ( $opts['batch']    ?? 500 ) );
		$overwrite = (bool) ( $opts['overwrite'] ?? false );
		$dry_run   = (bool) ( $opts['dry_run']   ?? false );

		$key = self::primary_key_for( $source );
		if ( '' === $key ) {
			return array( 'processed' => 0, 'written' => 0, 'skipped' => 0, 'errors' => array( 'Unknown source: ' . $source ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d",
			$key,
			$batch
		) );
		$post_ids = array_map( 'intval', $post_ids ?: array() );

		$processed = 0;
		$written   = 0;
		$skipped   = 0;
		$errors    = array();

		foreach ( $post_ids as $pid ) {
			if ( ! get_post( $pid ) ) {
				continue;
			}
			$processed++;

			$mapped = 'yoast' === $source
				? self::map_yoast( $pid )
				: self::map_rankmath( $pid );

			$row_written = false;
			foreach ( $mapped as $target_key => $value ) {
				if ( null === $value ) {
					continue;
				}
				if ( ! $overwrite ) {
					$existing = get_post_meta( $pid, $target_key, true );
					if ( '' !== (string) $existing ) {
						continue;
					}
				}
				if ( ! $dry_run ) {
					update_post_meta( $pid, $target_key, $value );
				}
				$row_written = true;
			}
			$row_written ? $written++ : $skipped++;
		}

		return array(
			'processed' => $processed,
			'written'   => $written,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	/**
	 * @return array<string,string|null>
	 */
	private static function map_yoast( int $post_id ): array {
		$noindex_raw  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex',  true );
		$nofollow_raw = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
		// Yoast stores: '' = default, '1' = noindex, '2' = index. We map only the explicit '1'.
		$noindex  = ( '1' === (string) $noindex_raw )  ? '1' : null;
		$nofollow = ( '1' === (string) $nofollow_raw ) ? '1' : null;

		return array(
			'_plseo_title'         => self::nullable( get_post_meta( $post_id, '_yoast_wpseo_title',           true ) ),
			'_plseo_description'   => self::nullable( get_post_meta( $post_id, '_yoast_wpseo_metadesc',        true ) ),
			'_plseo_canonical'     => self::nullable( get_post_meta( $post_id, '_yoast_wpseo_canonical',       true ) ),
			'_plseo_social_image'  => self::nullable( get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) ),
			'_plseo_focus_keyword' => self::nullable( get_post_meta( $post_id, '_yoast_wpseo_focuskw',         true ) ),
			'_plseo_cornerstone'   => ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true ) ) ? '1' : null,
			'_plseo_noindex'       => $noindex,
			'_plseo_nofollow'      => $nofollow,
		);
	}

	/**
	 * @return array<string,string|null>
	 */
	private static function map_rankmath( int $post_id ): array {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : array();
		$noindex  = in_array( 'noindex',  $robots, true ) ? '1' : null;
		$nofollow = in_array( 'nofollow', $robots, true ) ? '1' : null;

		// RankMath stores focus keywords as comma-separated; map directly.
		$focus = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );

		// Pillar content flag.
		$pillar = (string) get_post_meta( $post_id, 'rank_math_pillar_content', true );

		return array(
			'_plseo_title'         => self::nullable( get_post_meta( $post_id, 'rank_math_title',           true ) ),
			'_plseo_description'   => self::nullable( get_post_meta( $post_id, 'rank_math_description',     true ) ),
			'_plseo_canonical'     => self::nullable( get_post_meta( $post_id, 'rank_math_canonical_url',   true ) ),
			'_plseo_social_image'  => self::nullable( get_post_meta( $post_id, 'rank_math_facebook_image',  true ) ),
			'_plseo_focus_keyword' => '' !== $focus ? $focus : null,
			'_plseo_cornerstone'   => ( 'on' === $pillar ) ? '1' : null,
			'_plseo_noindex'       => $noindex,
			'_plseo_nofollow'      => $nofollow,
		);
	}

	private static function nullable( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return '' === $value ? null : $value;
	}
}
