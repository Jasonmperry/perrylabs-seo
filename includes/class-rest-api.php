<?php
/**
 * PLSEO_REST_API — namespaced REST endpoints for headless and external tools.
 *
 * Namespace: plseo/v1
 *
 * Endpoints:
 *   GET    /options                      → full options dict (manage_options)
 *   GET    /analyze/(?P<id>\d+)          → content analysis for a post (edit_posts)
 *   GET    /redirects                    → list redirects (manage_options)
 *   POST   /redirects                    → create one (manage_options)
 *   DELETE /redirects/(?P<id>\d+)        → delete one (manage_options)
 *   GET    /ai-visits                    → AI bot summary + top URLs (manage_options)
 *   POST   /indexnow/submit              → manually submit URLs (manage_options)
 *
 * Every endpoint enforces a capability check; nothing is public.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_REST_API {

	private static ?self $instance = null;

	public const NS = 'plseo/v1';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( ! (bool) PLSEO_Options::get( 'rest_api_enabled', true ) ) {
			return;
		}

		register_rest_route( self::NS, '/options', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => static fn() => new \WP_REST_Response( PLSEO_Options::all(), 200 ),
		) );

		register_rest_route( self::NS, '/analyze/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => static fn( $req ) => current_user_can( 'edit_post', (int) $req['id'] ),
			'callback'            => array( $this, 'analyze' ),
			'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );

		register_rest_route( self::NS, '/redirects', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => array( $this, 'list_redirects' ),
				'args'                => array(
					'per_page' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => array( $this, 'create_redirect' ),
				'args'                => array(
					'source_url'  => array( 'type' => 'string',  'required' => true ),
					'target_url'  => array( 'type' => 'string',  'required' => true ),
					'status_code' => array( 'type' => 'integer', 'default' => 301 ),
					'match_type'  => array( 'type' => 'string',  'default' => 'exact', 'enum' => array( 'exact', 'regex' ) ),
					'notes'       => array( 'type' => 'string',  'default' => '' ),
				),
			),
		) );

		register_rest_route( self::NS, '/redirects/(?P<id>\d+)', array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => array( $this, 'delete_redirect' ),
			'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		) );

		register_rest_route( self::NS, '/ai-visits', array(
			'methods'             => \WP_REST_Server::READABLE,
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => array( $this, 'ai_visits' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 365 ),
			),
		) );

		register_rest_route( self::NS, '/indexnow/submit', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'callback'            => array( $this, 'indexnow_submit' ),
			'args'                => array(
				'urls' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ),
			),
		) );
	}

	public function analyze( \WP_REST_Request $req ): \WP_REST_Response {
		$post = get_post( (int) $req['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$rows = PLSEO_Content_Analysis::analyze( $post );
		return new \WP_REST_Response( array(
			'overall'  => PLSEO_Content_Analysis::overall_severity( $rows ),
			'findings' => $rows,
		), 200 );
	}

	public function list_redirects( \WP_REST_Request $req ): \WP_REST_Response {
		$per = (int) $req['per_page'];
		$pg  = (int) $req['page'];
		$mgr = PLSEO_Redirects::instance();
		return new \WP_REST_Response( array(
			'total' => $mgr->count_all(),
			'rows'  => $mgr->all( $per, ( $pg - 1 ) * $per ),
		), 200 );
	}

	public function create_redirect( \WP_REST_Request $req ): \WP_REST_Response {
		$id = PLSEO_Redirects::instance()->create( array(
			'source_url'  => (string) $req['source_url'],
			'target_url'  => (string) $req['target_url'],
			'status_code' => (int) $req['status_code'],
			'match_type'  => (string) $req['match_type'],
			'notes'       => (string) $req['notes'],
		) );
		return $id
			? new \WP_REST_Response( array( 'id' => $id ), 201 )
			: new \WP_REST_Response( array( 'error' => 'insert_failed' ), 500 );
	}

	public function delete_redirect( \WP_REST_Request $req ): \WP_REST_Response {
		return PLSEO_Redirects::instance()->delete( (int) $req['id'] )
			? new \WP_REST_Response( null, 204 )
			: new \WP_REST_Response( array( 'error' => 'delete_failed' ), 500 );
	}

	public function ai_visits( \WP_REST_Request $req ): \WP_REST_Response {
		$days = (int) $req['days'];
		$log  = PLSEO_AI_Visit_Log::instance();
		return new \WP_REST_Response( array(
			'days'    => $days,
			'total'   => $log->total_hits( $days ),
			'by_bot'  => $log->summary_by_bot( $days ),
			'top'     => $log->top_urls( $days, 25 ),
		), 200 );
	}

	public function indexnow_submit( \WP_REST_Request $req ): \WP_REST_Response {
		$urls = array_map( 'esc_url_raw', (array) $req['urls'] );
		PLSEO_IndexNow::instance()->submit( $urls );
		return new \WP_REST_Response( array( 'submitted' => count( $urls ) ), 202 );
	}
}
