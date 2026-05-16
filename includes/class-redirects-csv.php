<?php
/**
 * PLSEO_Redirects_CSV — CSV import/export for redirects.
 *
 * Format: source_url,target_url,status_code,match_type,notes
 *
 * The header row is auto-detected (case-insensitive match on the first field
 * = "source_url" OR "source") and skipped on import.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Redirects_CSV {

	/**
	 * @return array{added:int,skipped:int,errors:array<int,string>}
	 */
	public static function import_from_path( string $path ): array {
		$result = array( 'added' => 0, 'skipped' => 0, 'errors' => array() );
		if ( ! is_readable( $path ) ) {
			$result['errors'][] = sprintf( __( 'Cannot read uploaded file: %s', 'perrylabs-seo' ), $path );
			return $result;
		}
		$h = fopen( $path, 'rb' );
		if ( false === $h ) {
			$result['errors'][] = __( 'Could not open the CSV file.', 'perrylabs-seo' );
			return $result;
		}

		$line = 0;
		$mgr  = PLSEO_Redirects::instance();

		while ( false !== ( $row = fgetcsv( $h ) ) ) {
			$line++;
			if ( ! is_array( $row ) || count( $row ) < 2 ) {
				$result['skipped']++;
				continue;
			}

			$row = array_map( static fn( $v ) => is_string( $v ) ? trim( $v ) : '', $row );

			// Skip header row.
			if ( 1 === $line && in_array( strtolower( $row[0] ), array( 'source_url', 'source' ), true ) ) {
				$result['skipped']++;
				continue;
			}

			$source = (string) ( $row[0] ?? '' );
			$target = (string) ( $row[1] ?? '' );
			if ( '' === $source || '' === $target ) {
				$result['skipped']++;
				continue;
			}
			$status = isset( $row[2] ) && '' !== $row[2] ? (int) $row[2] : 301;
			$match  = isset( $row[3] ) && '' !== $row[3] ? (string) $row[3] : 'exact';
			$notes  = (string) ( $row[4] ?? '' );

			$id = $mgr->create( array(
				'source_url'  => $source,
				'target_url'  => $target,
				'status_code' => $status,
				'match_type'  => $match,
				'notes'       => $notes,
			) );
			if ( false === $id ) {
				$result['errors'][] = sprintf( __( 'Line %d: insert failed.', 'perrylabs-seo' ), $line );
				continue;
			}
			$result['added']++;
		}

		fclose( $h );
		return $result;
	}

	/**
	 * Stream a CSV download of all redirects to the browser.
	 */
	public static function stream_export(): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=' . get_bloginfo( 'charset' ) );
		header( 'Content-Disposition: attachment; filename="plseo-redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'wb' );
		fputcsv( $out, array( 'source_url', 'target_url', 'status_code', 'match_type', 'notes' ) );

		$mgr   = PLSEO_Redirects::instance();
		$page  = 0;
		$batch = 500;
		do {
			$rows = $mgr->all( $batch, $page * $batch, 'id', 'ASC' );
			foreach ( $rows as $r ) {
				fputcsv( $out, array(
					(string) $r->source_url,
					(string) $r->target_url,
					(int) $r->status_code,
					(string) $r->match_type,
					(string) ( $r->notes ?? '' ),
				) );
			}
			$page++;
		} while ( count( $rows ) === $batch );

		fclose( $out );
		exit;
	}
}
