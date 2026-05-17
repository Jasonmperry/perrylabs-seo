<?php
/**
 * PLSEO_Audit — site-wide SEO/AEO health checks.
 *
 * Runs a batch over published posts in configured types and aggregates issues
 * grouped by severity, in the spirit of Semrush's Site Audit (errors / warnings
 * / notices). No external API calls — everything is computed locally from
 * post content, post meta, and the link graph.
 *
 * Issues surfaced (≈20 checks across post + site scopes):
 *
 *   Errors:
 *     - missing_title          → post has no resolved SEO title
 *     - missing_description    → post has no description and no excerpt
 *     - duplicate_title        → two or more posts share an identical resolved title
 *     - duplicate_description  → two or more posts share an identical description
 *     - broken_internal_link   → outbound internal link resolves to a non-existent post
 *     - multiple_h1            → post body contains >1 H1 element
 *     - org_logo_missing       → Organization schema is incomplete
 *
 *   Warnings:
 *     - thin_content           → post is below 300 words
 *     - title_length           → outside the 30–60 char band
 *     - description_length     → outside the 120–160 char band
 *     - orphan_post            → no inbound internal links
 *     - missing_alt            → at least one <img> lacks alt text
 *     - low_readability        → Flesch-Kincaid score under 30
 *     - cornerstone_unmarked   → no posts are flagged cornerstone (AEO miss)
 *
 *   Notices:
 *     - missing_featured_image
 *     - missing_focus_keyword
 *     - no_internal_links_out
 *
 * Results live in a transient with a configurable TTL (default 1 hour) so
 * reload is fast. CLI / REST consumers can request a refresh.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Audit {

	private static ?self $instance = null;

	private const TRANSIENT = 'plseo_audit_results_v1';
	private const TTL       = HOUR_IN_SECONDS;
	private const BATCH     = 500; // Posts examined per run — pagination shown in UI.

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'plseo_daily_maintenance', array( $this, 'run' ) );
	}

	/**
	 * Read cached results, or compute a fresh set when missing.
	 *
	 * @return array{generated_at:int,counts:array<string,int>,findings:array<string,array<int,array{post_id:int,detail:string}>>,scanned:int}
	 */
	public function results(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		return $this->run();
	}

	/**
	 * Force a fresh audit. Returns the same shape as results().
	 *
	 * Caps at BATCH posts per run, sorted by most-recently-modified so a large
	 * site's freshest content is always covered. `total_eligible` is the full
	 * publish-count across configured types; `scanned` is what we actually
	 * examined. Surfacing both prevents the "silent cap" surprise where users
	 * thought their 5000-post site had been fully scanned at 500.
	 *
	 * @return array{generated_at:int,counts:array<string,int>,findings:array<string,array<int,array{post_id:int,detail:string}>>,scanned:int,total_eligible:int,capped:bool}
	 */
	public function run(): array {
		$types = (array) PLSEO_Options::get( 'sitemap_post_types', array( 'post', 'page' ) );

		// Real total across all configured post types (not capped).
		$total_eligible = 0;
		foreach ( $types as $t ) {
			$counts          = wp_count_posts( $t );
			$total_eligible += $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		$posts = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => self::BATCH,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$findings   = $this->blank_findings_bucket();
		$title_seen = array();
		$desc_seen  = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$this->audit_post( $post, $findings, $title_seen, $desc_seen );
		}

		$this->finalize_duplicates( $title_seen, $desc_seen, $findings );
		$this->audit_site( $findings );

		$counts = array_map( 'count', $findings );
		$out    = array(
			'generated_at'   => time(),
			'counts'         => $counts,
			'findings'       => $findings,
			'scanned'        => count( $posts ),
			'total_eligible' => $total_eligible,
			'capped'         => $total_eligible > count( $posts ),
		);

		set_transient( self::TRANSIENT, $out, self::TTL );
		return $out;
	}

	public function bust_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @return array<string,array<int,array{post_id:int,detail:string}>>
	 */
	private function blank_findings_bucket(): array {
		return array(
			// Errors
			'missing_title'         => array(),
			'missing_description'   => array(),
			'duplicate_title'       => array(),
			'duplicate_description' => array(),
			'broken_internal_link'  => array(),
			'multiple_h1'           => array(),
			'org_logo_missing'      => array(),
			// Warnings
			'thin_content'          => array(),
			'title_length'          => array(),
			'description_length'    => array(),
			'orphan_post'           => array(),
			'missing_alt'           => array(),
			'low_readability'       => array(),
			'cornerstone_unmarked'  => array(),
			// Notices
			'missing_featured_image'=> array(),
			'missing_focus_keyword' => array(),
			'no_internal_links_out' => array(),
		);
	}

	/**
	 * Per-post checks. `$findings`, `$title_seen`, and `$desc_seen` are mutated.
	 *
	 * @param array<string,array<int,array{post_id:int,detail:string}>> $findings
	 * @param array<string,array<int,int>>                              $title_seen
	 * @param array<string,array<int,int>>                              $desc_seen
	 */
	private function audit_post( \WP_Post $post, array &$findings, array &$title_seen, array &$desc_seen ): void {
		$rows = PLSEO_Content_Analysis::analyze( $post );

		// Map the existing per-post analysis rows into audit buckets.
		foreach ( $rows as $row ) {
			switch ( $row['id'] ) {
				case 'title_missing':
					$findings['missing_title'][] = $this->find_row( $post );
					break;
				case 'desc_missing':
					$findings['missing_description'][] = $this->find_row( $post );
					break;
				case 'title_short':
				case 'title_long':
					$findings['title_length'][] = $this->find_row( $post, $row['detail'] );
					break;
				case 'desc_short':
				case 'desc_long':
					$findings['description_length'][] = $this->find_row( $post, $row['detail'] );
					break;
				case 'words_thin':
					$findings['thin_content'][] = $this->find_row( $post, $row['detail'] );
					break;
				case 'h1_multiple':
					$findings['multiple_h1'][] = $this->find_row( $post, $row['detail'] );
					break;
				case 'images_alt_missing':
					$findings['missing_alt'][] = $this->find_row( $post, $row['detail'] );
					break;
				case 'inbound_none':
					$findings['orphan_post'][] = $this->find_row( $post );
					break;
				case 'internal_links_none':
					$findings['no_internal_links_out'][] = $this->find_row( $post );
					break;
				case 'readability_hard':
					$findings['low_readability'][] = $this->find_row( $post, $row['detail'] );
					break;
			}
		}

		// Broken outbound internal links (resolved IDs that no longer exist).
		$out_raw = (string) get_post_meta( $post->ID, '_plseo_internal_links', true );
		$out_ids = '' !== $out_raw ? PLSEO_Link_Graph::parse_targets( $out_raw ) : array();
		foreach ( $out_ids as $tid ) {
			if ( $tid > 0 && 'publish' !== get_post_status( $tid ) ) {
				$findings['broken_internal_link'][] = $this->find_row( $post, sprintf( __( 'Link → post #%d (no longer published)', 'perrylabs-seo' ), $tid ) );
			}
		}

		// Notice-level checks not covered by the per-post analyzer:
		if ( ! has_post_thumbnail( $post ) ) {
			$findings['missing_featured_image'][] = $this->find_row( $post );
		}
		if ( '' === (string) plseo_get_post_meta( $post->ID, 'focus_keyword', '' ) ) {
			$findings['missing_focus_keyword'][] = $this->find_row( $post );
		}

		// Track resolved titles + descriptions for site-wide duplicate detection.
		$title = $this->effective_title( $post );
		if ( '' !== $title ) {
			$key                  = mb_strtolower( trim( $title ) );
			$title_seen[ $key ][] = (int) $post->ID;
		}
		$desc = $this->effective_description( $post );
		if ( '' !== $desc ) {
			$key                  = mb_strtolower( trim( $desc ) );
			$desc_seen[ $key ][] = (int) $post->ID;
		}
	}

	/**
	 * @param array<string,array<int,int>>                              $title_seen
	 * @param array<string,array<int,int>>                              $desc_seen
	 * @param array<string,array<int,array{post_id:int,detail:string}>> $findings
	 */
	private function finalize_duplicates( array $title_seen, array $desc_seen, array &$findings ): void {
		foreach ( $title_seen as $title => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			foreach ( $ids as $pid ) {
				$findings['duplicate_title'][] = array(
					'post_id' => $pid,
					'detail'  => sprintf( __( 'Shared with %d other post(s)', 'perrylabs-seo' ), count( $ids ) - 1 ),
				);
			}
		}
		foreach ( $desc_seen as $desc => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			foreach ( $ids as $pid ) {
				$findings['duplicate_description'][] = array(
					'post_id' => $pid,
					'detail'  => sprintf( __( 'Shared with %d other post(s)', 'perrylabs-seo' ), count( $ids ) - 1 ),
				);
			}
		}
	}

	/**
	 * @param array<string,array<int,array{post_id:int,detail:string}>> $findings
	 */
	private function audit_site( array &$findings ): void {
		// Organization completeness.
		$logo = trim( (string) PLSEO_Options::get( 'business_logo', '' ) );
		if ( '' === $logo ) {
			$findings['org_logo_missing'][] = array(
				'post_id' => 0,
				'detail'  => __( 'Set a logo on the Schema tab — Google rich results require it.', 'perrylabs-seo' ),
			);
		}

		// Cornerstone marking.
		$q = new \WP_Query( array(
			'post_type'      => (array) PLSEO_Options::get( 'sitemap_post_types', array( 'post', 'page' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_plseo_cornerstone', 'value' => '1' ) ),
		) );
		if ( empty( $q->posts ) ) {
			$findings['cornerstone_unmarked'][] = array(
				'post_id' => 0,
				'detail'  => __( 'No posts flagged as cornerstone. AI assistants weight curated pillar pages more heavily — mark a handful on the meta box.', 'perrylabs-seo' ),
			);
		}
	}

	/**
	 * @return array{post_id:int,detail:string}
	 */
	private function find_row( \WP_Post $post, string $detail = '' ): array {
		return array(
			'post_id' => (int) $post->ID,
			'detail'  => $detail,
		);
	}

	private function effective_title( \WP_Post $post ): string {
		$override = (string) plseo_get_post_meta( $post->ID, 'title', '' );
		if ( '' !== $override ) {
			return PLSEO_Template_Resolver::resolve( $override, PLSEO_Template_Resolver::context_for_post( $post ) );
		}
		$tmpl = (string) PLSEO_Options::get( 'page' === $post->post_type ? 'title_template_page' : 'title_template_post', '%post_title% %sep% %site_name%' );
		return PLSEO_Template_Resolver::resolve( $tmpl, PLSEO_Template_Resolver::context_for_post( $post ) );
	}

	private function effective_description( \WP_Post $post ): string {
		$override = (string) plseo_get_post_meta( $post->ID, 'description', '' );
		if ( '' !== $override ) {
			return $override;
		}
		return (string) $post->post_excerpt;
	}

	/**
	 * Bucket labels for the admin UI.
	 *
	 * @return array<string,array{severity:string,label:string,detail:string}>
	 */
	public static function check_catalog(): array {
		return array(
			'missing_title'         => array( 'severity' => 'error',   'label' => __( 'Missing SEO title', 'perrylabs-seo' ),         'detail' => __( 'Resolved to empty string.', 'perrylabs-seo' ) ),
			'missing_description'   => array( 'severity' => 'error',   'label' => __( 'Missing meta description', 'perrylabs-seo' ), 'detail' => __( 'No description and no excerpt.', 'perrylabs-seo' ) ),
			'duplicate_title'       => array( 'severity' => 'error',   'label' => __( 'Duplicate titles', 'perrylabs-seo' ),         'detail' => __( 'Two or more posts share the same resolved title.', 'perrylabs-seo' ) ),
			'duplicate_description' => array( 'severity' => 'error',   'label' => __( 'Duplicate descriptions', 'perrylabs-seo' ),   'detail' => __( 'Two or more posts share the same description.', 'perrylabs-seo' ) ),
			'broken_internal_link'  => array( 'severity' => 'error',   'label' => __( 'Broken internal links', 'perrylabs-seo' ),    'detail' => __( 'A linked post is no longer published.', 'perrylabs-seo' ) ),
			'multiple_h1'           => array( 'severity' => 'error',   'label' => __( 'Multiple H1 in content', 'perrylabs-seo' ),   'detail' => __( 'Exactly one H1 per page is the standard.', 'perrylabs-seo' ) ),
			'org_logo_missing'      => array( 'severity' => 'error',   'label' => __( 'Organization logo missing', 'perrylabs-seo' ), 'detail' => __( 'Set a logo on the Schema tab.', 'perrylabs-seo' ) ),

			'thin_content'          => array( 'severity' => 'warning', 'label' => __( 'Thin content', 'perrylabs-seo' ),            'detail' => __( 'Under 300 words.', 'perrylabs-seo' ) ),
			'title_length'          => array( 'severity' => 'warning', 'label' => __( 'Title length out of band', 'perrylabs-seo' ), 'detail' => __( 'Target 30–60 characters.', 'perrylabs-seo' ) ),
			'description_length'    => array( 'severity' => 'warning', 'label' => __( 'Description length out of band', 'perrylabs-seo' ), 'detail' => __( 'Target 120–160 characters.', 'perrylabs-seo' ) ),
			'orphan_post'           => array( 'severity' => 'warning', 'label' => __( 'Orphan posts', 'perrylabs-seo' ),            'detail' => __( 'No inbound internal links.', 'perrylabs-seo' ) ),
			'missing_alt'           => array( 'severity' => 'warning', 'label' => __( 'Images missing alt text', 'perrylabs-seo' ),  'detail' => __( 'a11y + AI image search rely on alt.', 'perrylabs-seo' ) ),
			'low_readability'       => array( 'severity' => 'warning', 'label' => __( 'Hard-to-read content', 'perrylabs-seo' ),     'detail' => __( 'Flesch-Kincaid Reading Ease under 30.', 'perrylabs-seo' ) ),
			'cornerstone_unmarked'  => array( 'severity' => 'warning', 'label' => __( 'No cornerstone posts marked', 'perrylabs-seo' ), 'detail' => __( 'Flag your pillar pages so AEO output highlights them.', 'perrylabs-seo' ) ),

			'missing_featured_image'=> array( 'severity' => 'notice',  'label' => __( 'Missing featured image', 'perrylabs-seo' ),   'detail' => __( 'Social previews and the schema graph use it.', 'perrylabs-seo' ) ),
			'missing_focus_keyword' => array( 'severity' => 'notice',  'label' => __( 'No focus keyword', 'perrylabs-seo' ),         'detail' => __( 'Helps the per-post analyzer score coverage.', 'perrylabs-seo' ) ),
			'no_internal_links_out' => array( 'severity' => 'notice',  'label' => __( 'No outbound internal links', 'perrylabs-seo' ), 'detail' => __( 'Link to related posts to strengthen topical clusters.', 'perrylabs-seo' ) ),
		);
	}
}
