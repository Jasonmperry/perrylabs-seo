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
		\WP_CLI::add_command( 'plseo links',     array( __CLASS__, 'cmd_links' ) );
		\WP_CLI::add_command( 'plseo audit',     array( __CLASS__, 'cmd_audit' ) );
		\WP_CLI::add_command( 'plseo ai-fill',   array( __CLASS__, 'cmd_ai_fill' ) );
		\WP_CLI::add_command( 'plseo doctor',    array( __CLASS__, 'cmd_doctor' ) );
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

	/**
	 * Internal-link graph operations.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : rebuild | inbound
	 *
	 * [--post-type=<types>]
	 * : Comma-separated post types. Defaults to all public post types.
	 *
	 * [--batch=<n>]
	 * : Posts per query batch (default 100).
	 *
	 * [--dry-run]
	 * : Show what would be written without persisting.
	 *
	 * ## EXAMPLES
	 *
	 *   wp plseo links rebuild
	 *   wp plseo links rebuild --post-type=post,page
	 *   wp plseo links inbound 42
	 */
	public static function cmd_links( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		array_shift( $args );

		switch ( $sub ) {
			case 'rebuild':
				self::links_rebuild( $assoc );
				return;
			case 'inbound':
				$id = (int) ( $args[0] ?? 0 );
				if ( $id < 1 ) {
					\WP_CLI::error( 'Usage: wp plseo links inbound <post-id>' );
				}
				$n = PLSEO_Link_Graph::instance()->inbound_count( $id );
				\WP_CLI::log( sprintf( 'post %d has %d inbound internal link(s)', $id, $n ) );
				return;
			default:
				\WP_CLI::error( 'Subcommands: rebuild, inbound' );
		}
	}

	private static function links_rebuild( array $assoc ): void {
		$types_raw = (string) ( $assoc['post-type'] ?? '' );
		if ( '' === $types_raw ) {
			$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
			$types = array_values( array_filter( $types, static fn( $t ) => 'attachment' !== $t ) );
		} else {
			$types = array_map( 'sanitize_key', explode( ',', $types_raw ) );
		}
		$batch   = max( 1, (int) ( $assoc['batch'] ?? 100 ) );
		$dry_run = isset( $assoc['dry-run'] );

		$page    = 1;
		$written = 0;
		$cleared = 0;
		$graph   = PLSEO_Link_Graph::instance();

		\WP_CLI::log( sprintf( 'Rebuilding link graph for: %s', implode( ', ', $types ) ) );
		if ( $dry_run ) {
			\WP_CLI::log( '(dry run — no writes)' );
		}

		do {
			$posts = get_posts( array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $batch,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			) );

			foreach ( $posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				if ( $dry_run ) {
					$written++;
				} else {
					$before = (string) get_post_meta( $post->ID, '_plseo_internal_links', true );
					$graph->rebuild_for_post( (int) $post->ID, $post );
					$after = (string) get_post_meta( $post->ID, '_plseo_internal_links', true );
					if ( '' === $after && '' !== $before ) {
						$cleared++;
					} elseif ( '' !== $after ) {
						$written++;
					}
				}
			}
			\WP_CLI::log( sprintf( '  page %d: %d post(s) processed', $page, count( $posts ) ) );
			$page++;
		} while ( count( $posts ) === $batch );

		\WP_CLI::success( sprintf( 'Wrote %d, cleared %d.', $written, $cleared ) );
	}

	/**
	 * Site-audit operations.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : run | counts | bust
	 *
	 * ## EXAMPLES
	 *
	 *   wp plseo audit run
	 *   wp plseo audit counts
	 *   wp plseo audit bust
	 */
	public static function cmd_audit( array $args, array $assoc ): void {
		$sub = $args[0] ?? '';
		switch ( $sub ) {
			case 'run':
				$r = PLSEO_Audit::instance()->run();
				\WP_CLI::log( sprintf(
					'Scanned %d of %d eligible post(s).',
					(int) $r['scanned'],
					(int) ( $r['total_eligible'] ?? $r['scanned'] )
				) );
				foreach ( $r['counts'] as $check => $n ) {
					if ( $n > 0 ) {
						\WP_CLI::log( sprintf( '  %-26s %d', $check, $n ) );
					}
				}
				\WP_CLI::success( 'Done.' );
				return;
			case 'counts':
				$r = PLSEO_Audit::instance()->results();
				\WP_CLI\Utils\format_items(
					(string) ( $assoc['format'] ?? 'table' ),
					array_map(
						static fn( $check, $n ) => array( 'check' => $check, 'count' => $n ),
						array_keys( $r['counts'] ),
						array_values( $r['counts'] )
					),
					array( 'check', 'count' )
				);
				return;
			case 'bust':
				PLSEO_Audit::instance()->bust_cache();
				\WP_CLI::success( 'Audit cache cleared.' );
				return;
			default:
				\WP_CLI::error( 'Subcommands: run, counts, bust' );
		}
	}

	/**
	 * AI-generate SEO meta for posts that don't have any yet.
	 *
	 * Calls Claude (claude-haiku-4-5) to draft title/description/focus_keyword/
	 * quick_answer for each post and writes them to `_plseo_*` meta.
	 *
	 * Requires an API key — see PLSEO_AI_Fill::api_key() for resolution order.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<types>]
	 * : Comma-separated post types. Defaults to all public types except attachment.
	 *
	 * [--limit=<n>]
	 * : Maximum posts to process in this run (default 50). Useful for resuming
	 *   across multiple invocations on large sites.
	 *
	 * [--overwrite]
	 * : Re-generate meta even for posts that already have _plseo_title.
	 *   Default: skip those.
	 *
	 * [--dry-run]
	 * : Show what would be written without calling the API or persisting.
	 *
	 * [--sleep=<ms>]
	 * : Delay between API calls in milliseconds (default 0). Use to stay
	 *   under your account's rate limit on large runs.
	 *
	 * ## EXAMPLES
	 *
	 *   # Fill the first 50 posts on a fresh install
	 *   wp plseo ai-fill
	 *
	 *   # Just news, with a half-second pause between calls
	 *   wp plseo ai-fill --post-type=biobuzz_news --limit=200 --sleep=500
	 *
	 *   # Re-generate everything
	 *   wp plseo ai-fill --overwrite --limit=1000
	 */
	public static function cmd_ai_fill( array $args, array $assoc ): void {
		$key = PLSEO_AI_Fill::api_key();
		if ( '' === $key ) {
			\WP_CLI::error( 'No Claude API key. Define PLSEO_ANTHROPIC_API_KEY in wp-config.php or set ANTHROPIC_API_KEY env var.' );
		}

		$types_raw = (string) ( $assoc['post-type'] ?? '' );
		if ( '' === $types_raw ) {
			$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
			$types = array_values( array_filter( $types, static fn( $t ) => 'attachment' !== $t ) );
		} else {
			$types = array_map( 'sanitize_key', explode( ',', $types_raw ) );
		}

		$limit     = max( 1, (int) ( $assoc['limit'] ?? 50 ) );
		$overwrite = isset( $assoc['overwrite'] );
		$dry_run   = isset( $assoc['dry-run'] );
		$sleep_ms  = max( 0, (int) ( $assoc['sleep'] ?? 0 ) );

		$query_args = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( ! $overwrite ) {
			$query_args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_plseo_title', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_plseo_title', 'value' => '',           'compare' => '=' ),
			);
		}

		$posts = get_posts( $query_args );
		if ( empty( $posts ) ) {
			\WP_CLI::success( 'Nothing to fill — all targeted posts already have _plseo_title.' );
			return;
		}

		\WP_CLI::log( sprintf(
			'Filling %d post(s) [%s] — overwrite=%s, dry-run=%s',
			count( $posts ),
			implode( ',', $types ),
			$overwrite ? 'yes' : 'no',
			$dry_run ? 'yes' : 'no'
		) );

		$ok       = 0;
		$failed   = 0;
		$progress = method_exists( '\WP_CLI\Utils', 'make_progress_bar' )
			? \WP_CLI\Utils\make_progress_bar( 'Generating', count( $posts ) )
			: null;

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				if ( $progress ) { $progress->tick(); }
				continue;
			}
			if ( $dry_run ) {
				\WP_CLI::log( sprintf( '  [dry] would generate for #%d %s', $post->ID, mb_substr( $post->post_title, 0, 60 ) ) );
				if ( $progress ) { $progress->tick(); }
				continue;
			}

			$meta = PLSEO_AI_Fill::generate_for_post( $post );
			if ( is_wp_error( $meta ) ) {
				$failed++;
				\WP_CLI::warning( sprintf( 'post %d: %s', $post->ID, $meta->get_error_message() ) );
			} else {
				PLSEO_AI_Fill::persist( (int) $post->ID, $meta );
				$ok++;
			}
			if ( $progress ) { $progress->tick(); }
			if ( $sleep_ms > 0 ) {
				usleep( $sleep_ms * 1000 );
			}
		}
		if ( $progress ) { $progress->finish(); }

		\WP_CLI::success( sprintf( 'Filled %d post(s); %d failed.', $ok, $failed ) );
	}

	/**
	 * Pre-flight health check — verifies the install is shipped correctly.
	 *
	 * Walks PHP version, required extensions, WP version, multisite state,
	 * conflicting plugins, plugin-level settings (PLSEO_Health_Check), and
	 * custom-table presence. Useful before promoting from dev to live.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Exit with non-zero status code if any check produces a warning
	 *   (default: only errors fail the run).
	 *
	 * ## EXAMPLES
	 *
	 *   wp plseo doctor
	 *   wp plseo doctor --strict
	 */
	public static function cmd_doctor( array $args, array $assoc ): void {
		$strict = isset( $assoc['strict'] );
		$rows   = self::doctor_checks();

		$errors   = 0;
		$warnings = 0;
		foreach ( $rows as $r ) {
			[ $status, $label, $detail ] = $r;
			$icon = match ( $status ) {
				'ok'    => "\033[32m✓\033[0m",
				'warn'  => "\033[33m⚠\033[0m",
				'error' => "\033[31m✗\033[0m",
				default => '·',
			};
			\WP_CLI::log( sprintf( '  %s %-44s %s', $icon, $label, $detail ) );
			if ( 'error' === $status ) {
				$errors++;
			}
			if ( 'warn' === $status ) {
				$warnings++;
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Summary: %d ok, %d warn, %d error', count( $rows ) - $errors - $warnings, $warnings, $errors ) );

		if ( $errors > 0 ) {
			\WP_CLI::error( 'Doctor reported errors. Fix before promoting to live.' );
		}
		if ( $strict && $warnings > 0 ) {
			\WP_CLI::error( '--strict: warnings present.' );
		}
		\WP_CLI::success( 'Doctor done.' );
	}

	/**
	 * Build the list of checks for the doctor command.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private static function doctor_checks(): array {
		global $wp_version, $wpdb;
		$out = array();

		// PHP version.
		$out[] = version_compare( PHP_VERSION, '8.0', '>=' )
			? array( 'ok',    'PHP version',           PHP_VERSION )
			: array( 'error', 'PHP version',           PHP_VERSION . ' (requires 8.0+)' );

		// WordPress version.
		$out[] = version_compare( (string) $wp_version, '6.0', '>=' )
			? array( 'ok',   'WordPress version', (string) $wp_version )
			: array( 'warn', 'WordPress version', $wp_version . ' (recommended 6.0+)' );

		// Required PHP extensions.
		foreach ( array( 'mbstring', 'json', 'pcre', 'curl' ) as $ext ) {
			$out[] = extension_loaded( $ext )
				? array( 'ok',    "ext-{$ext}", 'loaded' )
				: array( 'error', "ext-{$ext}", 'NOT loaded' );
		}

		// GD (OG image generator). Soft requirement.
		$out[] = function_exists( 'imagecreatetruecolor' )
			? array( 'ok',   'ext-gd',    'loaded (OG image generator available)' )
			: array( 'warn', 'ext-gd',    'not loaded — OG image generator will be a no-op' );

		// Multisite awareness.
		$out[] = array( 'ok', 'Multisite', is_multisite() ? 'yes' : 'no' );

		// Custom tables present.
		foreach ( array( 'plseo_redirects', 'plseo_404_log', 'plseo_ai_visits', 'plseo_search_log' ) as $t ) {
			$full   = $wpdb->prefix . $t;
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$out[]  = $exists === $full
				? array( 'ok',    "table {$t}", 'exists' )
				: array( 'error', "table {$t}", 'missing — re-activate the plugin' );
		}

		// Conflicting SEO plugins.
		foreach ( array(
			'Yoast SEO'        => array( 'wordpress-seo/wp-seo.php',                 'WPSEO_VERSION' ),
			'RankMath SEO'     => array( 'seo-by-rank-math/rank-math.php',           'RANK_MATH_VERSION' ),
			'All in One SEO'   => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'AIOSEO_VERSION' ),
		) as $label => $pair ) {
			$active = ( function_exists( 'is_plugin_active' ) && is_plugin_active( $pair[0] ) ) || defined( $pair[1] );
			$out[]  = $active
				? array( 'warn', "Conflict: {$label}", 'active alongside PerryLabs SEO — duplicate output likely' )
				: array( 'ok',   "Conflict: {$label}", 'not active' );
		}

		// Plugin-level health-check findings.
		$health = class_exists( 'PLSEO_Health_Check' ) ? PLSEO_Health_Check::instance()->run() : array();
		foreach ( $health as $h ) {
			$out[] = array(
				'warn' === $h['severity'] ? 'warn' : 'ok',
				'Health: ' . $h['label'],
				$h['detail']
			);
		}

		// Pretty URLs (needed for the rewrite-based endpoints).
		$out[] = '' !== (string) get_option( 'permalink_structure', '' )
			? array( 'ok',    'Permalinks', 'pretty (rewrite-based endpoints work)' )
			: array( 'error', 'Permalinks', 'plain permalinks active — /sitemap.xml, /llms.txt, and IndexNow key all return 404' );

		// WP-Cron — required for retention pruning + scheduled audits.
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' );
		$out[]         = ! $cron_disabled
			? array( 'ok',   'WP-Cron', 'enabled (daily prune of 404 / AI visits / search log runs automatically)' )
			: array( 'warn', 'WP-Cron', 'disabled — schedule the daily plseo_daily_maintenance hook from system cron or tables will grow unbounded' );

		return $out;
	}
}
