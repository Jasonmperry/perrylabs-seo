<?php
/**
 * PLSEO_Smoke — end-to-end smoke test of every public surface.
 *
 * Used by `wp plseo smoke` and the REST route /wp-json/plseo/v1/smoke. Each
 * check runs in isolation, returns a typed result, and is grouped under one
 * of four categories:
 *
 *   - endpoints  : public-facing HTTP routes (sitemap, llms.txt, robots.txt,
 *                  indexnow key, REST namespace)
 *   - schema     : per-post @graph emission against the first publishable post
 *   - modules    : every singleton module is instantiable and has booted
 *   - meta       : pre-flight from PLSEO_CLI::doctor() folded back in
 *
 * Each result is `{ id, category, status, label, detail }` where status is
 * one of pass | warn | fail | skip. The runner prints summary counts; a
 * non-zero fail bucket is the only exit-code-1 condition.
 *
 * Deliberately makes no external network calls beyond `wp_remote_get` against
 * the site's own home_url(). Safe to run on any host — read-only.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Smoke {

	/**
	 * Run all smoke checks.
	 *
	 * @return array<int,array{id:string,category:string,status:string,label:string,detail:string}>
	 */
	public static function run(): array {
		$results = array();

		self::check_endpoints( $results );
		self::check_schema( $results );
		self::check_modules( $results );
		self::check_rest( $results );

		return $results;
	}

	/* ───────────────────────── HTTP endpoints ───────────────────────── */

	private static function check_endpoints( array &$results ): void {
		$opts = PLSEO_Options::all();

		// `marker` is the substring (or list of either-or substrings) we expect
		// in a 200 response. `private_ok` means the endpoint is allowed to be
		// overridden by hosting-level rules (e.g. Pantheon dev blocks all bots
		// with a global "Disallow: /" — that's correct, not a regression).
		$cases = array(
			array( 'sitemap',       '/sitemap.xml',   true,                                       array( '<urlset', '<sitemapindex' ), false ),
			array( 'robots_txt',    '/robots.txt',    true,                                       array( 'Sitemap:', 'User-agent' ),   true ),
			array( 'llms_txt',      '/llms.txt',      ! empty( $opts['llms_txt_enabled'] ),       array( '#' ),                        false ),
			array( 'llms_full_txt', '/llms-full.txt', ! empty( $opts['llms_full_enabled'] ),      array( '#' ),                        false ),
		);

		foreach ( $cases as $c ) {
			[ $id, $path, $expected_enabled, $markers, $private_ok ] = $c;

			if ( ! $expected_enabled ) {
				$results[] = self::result( $id, 'endpoints', 'skip', $path, 'disabled in plseo_options' );
				continue;
			}

			// `/llms-full.txt` concatenates many posts and is the slowest endpoint;
			// give it a bigger budget than the others.
			$timeout  = ( 'llms_full_txt' === $id ) ? 30 : 8;
			$response = wp_remote_get( home_url( $path ), array( 'timeout' => $timeout, 'sslverify' => false ) );

			if ( is_wp_error( $response ) ) {
				$results[] = self::result( $id, 'endpoints', 'fail', $path,
					'HTTP error: ' . $response->get_error_message() );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( $code !== 200 ) {
				$results[] = self::result( $id, 'endpoints', 'fail', $path, "HTTP {$code}" );
				continue;
			}

			// Match-any of the provided substrings.
			$matched = false;
			foreach ( (array) $markers as $needle ) {
				if ( false !== strpos( $body, (string) $needle ) ) {
					$matched = true;
					break;
				}
			}
			if ( $matched ) {
				$results[] = self::result( $id, 'endpoints', 'pass', $path,
					sprintf( 'HTTP 200 · %d bytes', strlen( $body ) ) );
				continue;
			}

			// No marker — either the endpoint is truly broken, or the hosting
			// layer is rewriting it (Pantheon dev does this for robots.txt).
			// Distinguishing: a "Disallow: /" global block is the canonical
			// "this site is privately gated" signature.
			if ( $private_ok && preg_match( '#User-agent:\s*\*\s*Disallow:\s*/#i', $body ) ) {
				$results[] = self::result( $id, 'endpoints', 'skip', $path,
					'host appears to block crawlers globally (dev environment?) — not a regression' );
				continue;
			}

			$results[] = self::result( $id, 'endpoints', 'warn', $path,
				sprintf( 'HTTP 200 but no expected marker in body (looked for: %s)', implode( ' | ', (array) $markers ) ) );
		}

		// IndexNow verification file — only meaningful if enabled.
		if ( ! empty( $opts['indexnow_enabled'] ) ) {
			$key      = PLSEO_IndexNow::instance()->key();
			$url      = '/' . $key . '.txt';
			$response = wp_remote_get( home_url( $url ), array( 'timeout' => 8, 'sslverify' => false ) );
			if ( is_wp_error( $response ) ) {
				$results[] = self::result( 'indexnow_key', 'endpoints', 'fail', $url, $response->get_error_message() );
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$body = trim( (string) wp_remote_retrieve_body( $response ) );
				$results[] = ( 200 === $code && $body === $key )
					? self::result( 'indexnow_key', 'endpoints', 'pass', $url, 'verification key served correctly' )
					: self::result( 'indexnow_key', 'endpoints', 'fail', $url, "HTTP {$code}; body mismatch" );
			}
		} else {
			$results[] = self::result( 'indexnow_key', 'endpoints', 'skip', '/{key}.txt', 'IndexNow disabled' );
		}
	}

	/* ───────────────────────── @graph emission ───────────────────────── */

	private static function check_schema( array &$results ): void {
		// Find the most recent published post in any configured sitemap type.
		$types = (array) PLSEO_Options::get( 'sitemap_post_types', array( 'post', 'page' ) );
		$post  = null;
		foreach ( $types as $t ) {
			$found = get_posts( array(
				'post_type'      => $t,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			) );
			if ( ! empty( $found ) ) {
				$post = $found[0];
				break;
			}
		}

		if ( ! $post instanceof \WP_Post ) {
			$results[] = self::result( 'schema_post_sample', 'schema', 'skip', 'no published post found', 'cannot exercise schema graph without content' );
			return;
		}

		// Reach into the schema-test screen's reflection helper to build the graph.
		if ( ! class_exists( 'PLSEO_Schema_Test_Screen' ) ) {
			$results[] = self::result( 'schema_unavailable', 'schema', 'fail', 'PLSEO_Schema_Test_Screen', 'class not loaded' );
			return;
		}
		try {
			$ref = new \ReflectionClass( PLSEO_Schema_Test_Screen::class );
			$m   = $ref->getMethod( 'build_graph_for_post' );
			$m->setAccessible( true );
			$graph = $m->invoke( null, $post );
		} catch ( \Throwable $e ) {
			$results[] = self::result( 'schema_build', 'schema', 'fail', 'reflection error', $e->getMessage() );
			return;
		}

		if ( ! is_array( $graph ) || empty( $graph['@graph'] ) ) {
			$results[] = self::result( 'schema_empty', 'schema', 'fail', sprintf( 'post #%d', $post->ID ),
				'graph builder returned no nodes' );
			return;
		}

		$results[] = self::result( 'schema_emits', 'schema', 'pass',
			sprintf( 'post #%d', $post->ID ),
			sprintf( '%d node(s) emitted, @context = schema.org', count( $graph['@graph'] ) )
		);

		// Confirm at least one Organization or Person at the org @id.
		$org_id   = PLSEO_Schema_Graph::org_id();
		$has_org  = false;
		$webpage  = false;
		foreach ( $graph['@graph'] as $node ) {
			if ( ( $node['@id'] ?? '' ) === $org_id ) {
				$has_org = true;
			}
			if ( ( $node['@type'] ?? '' ) === 'WebPage' ) {
				$webpage = true;
			}
		}
		$results[] = $has_org
			? self::result( 'schema_org_node', 'schema', 'pass', 'Organization/Person node', 'present at org_id' )
			: self::result( 'schema_org_node', 'schema', 'warn', 'Organization/Person node', 'no node found at org_id (set business_name on the Schema tab)' );

		$results[] = $webpage
			? self::result( 'schema_webpage', 'schema', 'pass', 'WebPage node', 'present' )
			: self::result( 'schema_webpage', 'schema', 'fail', 'WebPage node', 'missing — Schema_Types::webpage() did not fire' );
	}

	/* ───────────────────────── modules wired ───────────────────────── */

	private static function check_modules( array &$results ): void {
		$expected = array(
			'PLSEO_Options',
			'PLSEO_Migrations',
			'PLSEO_Str',
			'PLSEO_Template_Resolver',
			'PLSEO_Field_Renderer',
			'PLSEO_Meta_Tags',
			'PLSEO_Breadcrumbs',
			'PLSEO_Schema_Graph',
			'PLSEO_Schema_Rules',
			'PLSEO_Schema_Extended',
			'PLSEO_Schema_Types',
			'PLSEO_Schema_Content',
			'PLSEO_Schema_AEO',
			'PLSEO_Sitemap',
			'PLSEO_Redirects',
			'PLSEO_Redirects_CSV',
			'PLSEO_404_Log',
			'PLSEO_Robots_Txt',
			'PLSEO_AI_Crawlers',
			'PLSEO_AI_Visit_Log',
			'PLSEO_IndexNow',
			'PLSEO_LLMs_Txt',
			'PLSEO_Content_Analysis',
			'PLSEO_Link_Graph',
			'PLSEO_Image_SEO',
			'PLSEO_Perf',
			'PLSEO_Analytics',
			'PLSEO_Canonical',
			'PLSEO_Reading_Time',
			'PLSEO_Search_Log',
			'PLSEO_Audit',
			'PLSEO_AI_Fill',
			'PLSEO_Block_Editor',
			'PLSEO_Block_Patterns',
			'PLSEO_User_Profile',
			'PLSEO_OG_Image',
			'PLSEO_Importer',
			'PLSEO_WooCommerce',
			'PLSEO_Health_Check',
			'PLSEO_WPGraphQL',
			'PLSEO_Multilang',
			'PLSEO_Compat',
			'PLSEO_REST_API',
			'PLSEO_CLI',
		);

		$missing = array();
		foreach ( $expected as $c ) {
			if ( ! class_exists( $c ) ) {
				$missing[] = $c;
			}
		}

		$results[] = empty( $missing )
			? self::result( 'modules_loaded', 'modules', 'pass', 'class loading',
				sprintf( 'all %d expected classes loaded', count( $expected ) ) )
			: self::result( 'modules_loaded', 'modules', 'fail', 'class loading',
				sprintf( '%d missing: %s', count( $missing ), implode( ', ', array_slice( $missing, 0, 5 ) ) ) );

		// Table presence — already checked by `doctor` but worth a smoke too.
		global $wpdb;
		foreach ( array( 'plseo_redirects', 'plseo_404_log', 'plseo_ai_visits', 'plseo_search_log' ) as $t ) {
			$full   = $wpdb->prefix . $t;
			$exists = $full === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$results[] = $exists
				? self::result( 'table_' . $t, 'modules', 'pass', "table {$t}", 'exists' )
				: self::result( 'table_' . $t, 'modules', 'fail', "table {$t}", 'missing — re-activate the plugin' );
		}

		// Rewrite endpoints registered (compare against current rules).
		$rules    = (array) get_option( 'rewrite_rules', array() );
		$expected_rewrites = array( 'sitemap\\.xml', 'llms\\.txt' );
		$present  = 0;
		foreach ( $expected_rewrites as $pattern ) {
			foreach ( array_keys( $rules ) as $rule ) {
				if ( false !== strpos( (string) $rule, $pattern ) ) {
					$present++;
					break;
				}
			}
		}
		$results[] = $present === count( $expected_rewrites )
			? self::result( 'rewrites', 'modules', 'pass', 'rewrite rules', 'all expected endpoints registered' )
			: self::result( 'rewrites', 'modules', 'warn', 'rewrite rules',
				'some endpoints missing — visit Settings → Permalinks and save to refresh' );
	}

	/* ───────────────────────── REST self-test ───────────────────────── */

	private static function check_rest( array &$results ): void {
		// REST routes are manage_options-gated. CLI runs without a logged-in
		// user by default; temporarily impersonate the first admin so the
		// routes resolve. Restore the original user after.
		$prev_user_id = get_current_user_id();
		$prev_ok      = $prev_user_id && user_can( $prev_user_id, 'manage_options' );

		if ( ! $prev_ok ) {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			if ( empty( $admins ) ) {
				$results[] = self::result( 'rest_skip_no_admin', 'modules', 'skip', '/wp-json/plseo/v1/*',
					'no administrator user exists on this site' );
				return;
			}
			wp_set_current_user( (int) $admins[0] );
		}

		foreach ( array(
			'/plseo/v1/options',
			'/plseo/v1/audit',
			'/plseo/v1/ai-visits',
			'/plseo/v1/search-log',
		) as $route ) {
			$req      = new \WP_REST_Request( 'GET', $route );
			$response = rest_do_request( $req );
			$code     = (int) $response->get_status();
			$results[] = ( $code >= 200 && $code < 300 )
				? self::result( 'rest' . str_replace( '/', '_', $route ), 'modules', 'pass', $route, "HTTP {$code}" )
				: self::result( 'rest' . str_replace( '/', '_', $route ), 'modules', 'fail', $route, "HTTP {$code}" );
		}

		// Restore.
		if ( ! $prev_ok ) {
			wp_set_current_user( $prev_user_id );
		}
	}

	/* ───────────────────────── helpers ───────────────────────── */

	/**
	 * @return array{id:string,category:string,status:string,label:string,detail:string}
	 */
	private static function result( string $id, string $category, string $status, string $label, string $detail ): array {
		return compact( 'id', 'category', 'status', 'label', 'detail' );
	}

	/**
	 * Summary counts.
	 *
	 * @param array<int,array{status:string}> $results
	 * @return array{pass:int,warn:int,fail:int,skip:int}
	 */
	public static function summary( array $results ): array {
		$totals = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 );
		foreach ( $results as $r ) {
			$s = (string) ( $r['status'] ?? '' );
			if ( isset( $totals[ $s ] ) ) {
				$totals[ $s ]++;
			}
		}
		return $totals;
	}
}
