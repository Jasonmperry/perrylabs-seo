<?php
/**
 * PLSEO_CLI — WP-CLI commands.
 *
 * Usage:
 *   wp plseo redirects list [--format=table|json|csv]
 *   wp plseo redirects add <source> <target> [--status=301] [--match=exact|regex] [--notes=...]
 *   wp plseo redirects delete <id>
 *   wp plseo redirects import <path-to-csv>
 *   wp plseo redirects export
 *   wp plseo 404-log show [--limit=50]
 *   wp plseo 404-log prune
 *   wp plseo indexnow submit <url>...
 *   wp plseo indexnow key
 *   wp plseo ai-visits report [--days=30]
 *   wp plseo cache flush
 *   wp plseo settings export
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_CLI {

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'plseo redirects', array( __CLASS__, 'cmd_redirects' ) );
		\WP_CLI::add_command( 'plseo 404-log',   array( __CLASS__, 'cmd_404_log' ) );
		\WP_CLI::add_command( 'plseo indexnow',  array( __CLASS__, 'cmd_indexnow' ) );
		\WP_CLI::add_command( 'plseo ai-visits', array( __CLASS__, 'cmd_ai_visits' ) );
		\WP_CLI::add_command( 'plseo cache',     array( __CLASS__, 'cmd_cache' ) );
		\WP_CLI::add_command( 'plseo settings',  array( __CLASS__, 'cmd_settings' ) );
	}

	public static function cmd_redirects( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		array_shift( $args );

		switch ( $sub ) {
			case 'list':
				$rows = PLSEO_Redirects::instance()->all( 500 );
				\WP_CLI\Utils\format_items(
					(string) ( $assoc['format'] ?? 'table' ),
					array_map( static fn( $r ) => (array) $r, $rows ),
					array( 'id', 'source_url', 'target_url', 'status_code', 'match_type', 'hits', 'last_hit' )
				);
				return;

			case 'add':
				if ( count( $args ) < 2 ) {
					\WP_CLI::error( 'Usage: wp plseo redirects add <source> <target>' );
				}
				$id = PLSEO_Redirects::instance()->create( array(
					'source_url'  => (string) $args[0],
					'target_url'  => (string) $args[1],
					'status_code' => (int) ( $assoc['status'] ?? 301 ),
					'match_type'  => (string) ( $assoc['match'] ?? 'exact' ),
					'notes'       => (string) ( $assoc['notes'] ?? '' ),
				) );
				$id ? \WP_CLI::success( "Created redirect #{$id}" ) : \WP_CLI::error( 'Failed to insert' );
				return;

			case 'delete':
				$id = (int) ( $args[0] ?? 0 );
				if ( $id < 1 ) {
					\WP_CLI::error( 'Usage: wp plseo redirects delete <id>' );
				}
				PLSEO_Redirects::instance()->delete( $id )
					? \WP_CLI::success( "Deleted redirect #{$id}" )
					: \WP_CLI::error( 'Not found' );
				return;

			case 'import':
				$path = (string) ( $args[0] ?? '' );
				if ( ! is_readable( $path ) ) {
					\WP_CLI::error( "Cannot read {$path}" );
				}
				$r = PLSEO_Redirects_CSV::import_from_path( $path );
				\WP_CLI::success( sprintf( 'Imported %d, skipped %d.', $r['added'], $r['skipped'] ) );
				foreach ( $r['errors'] as $err ) {
					\WP_CLI::warning( $err );
				}
				return;

			case 'export':
				$mgr   = PLSEO_Redirects::instance();
				$rows  = $mgr->all( 5000 );
				$out   = fopen( 'php://stdout', 'wb' );
				fputcsv( $out, array( 'source_url', 'target_url', 'status_code', 'match_type', 'notes' ) );
				foreach ( $rows as $r ) {
					fputcsv( $out, array(
						(string) $r->source_url,
						(string) $r->target_url,
						(int) $r->status_code,
						(string) $r->match_type,
						(string) ( $r->notes ?? '' ),
					) );
				}
				fclose( $out );
				return;

			default:
				\WP_CLI::error( 'Subcommands: list, add, delete, import, export' );
		}
	}

	public static function cmd_404_log( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		switch ( $sub ) {
			case 'show':
				$rows = PLSEO_404_Log::instance()->recent( (int) ( $assoc['limit'] ?? 50 ) );
				\WP_CLI\Utils\format_items(
					(string) ( $assoc['format'] ?? 'table' ),
					array_map( static fn( $r ) => (array) $r, $rows ),
					array( 'id', 'url', 'hits', 'last_hit' )
				);
				return;
			case 'prune':
				PLSEO_404_Log::instance()->prune();
				\WP_CLI::success( 'Pruned.' );
				return;
			default:
				\WP_CLI::error( 'Subcommands: show, prune' );
		}
	}

	public static function cmd_indexnow( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		array_shift( $args );

		if ( 'key' === $sub ) {
			\WP_CLI::log( PLSEO_IndexNow::instance()->key() );
			return;
		}
		if ( 'submit' === $sub ) {
			if ( empty( $args ) ) {
				\WP_CLI::error( 'Pass one or more URLs.' );
			}
			PLSEO_IndexNow::instance()->submit( $args );
			\WP_CLI::success( sprintf( 'Submitted %d URL(s).', count( $args ) ) );
			return;
		}
		\WP_CLI::error( 'Subcommands: key, submit' );
	}

	public static function cmd_ai_visits( array $args, array $assoc ): void {
		$days = (int) ( $assoc['days'] ?? 30 );
		$log  = PLSEO_AI_Visit_Log::instance();

		\WP_CLI::log( sprintf( 'AI visits — last %d days', $days ) );
		\WP_CLI::log( sprintf( 'Total hits: %d', $log->total_hits( $days ) ) );

		$by_bot = $log->summary_by_bot( $days );
		\WP_CLI\Utils\format_items(
			'table',
			array_map( static fn( $r ) => (array) $r, $by_bot ),
			array( 'bot_slug', 'hits', 'unique_urls', 'last_seen' )
		);
	}

	public static function cmd_cache( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		if ( 'flush' !== $sub ) {
			\WP_CLI::error( 'Subcommands: flush' );
		}
		PLSEO_Sitemap::instance()->bust_cache();
		PLSEO_LLMs_Txt::instance()->bust_cache();
		PLSEO_Options::flush_cache();
		\WP_CLI::success( 'Cleared sitemap, llms.txt, and options cache.' );
	}

	public static function cmd_settings( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		if ( 'export' !== $sub ) {
			\WP_CLI::error( 'Subcommands: export' );
		}
		\WP_CLI::log( wp_json_encode( PLSEO_Options::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
