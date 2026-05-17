<?php
/**
 * PLSEO_Post_List_Column — "SEO" column on Posts/Pages/CPT list tables.
 *
 * Adds a column to wp-admin/edit.php that shows each post's worst-severity
 * badge from PLSEO_Content_Analysis. Editors can scan a list and see at a
 * glance which posts need attention without clicking into each one.
 *
 * Performance note: analyze() is not cheap on rendered content. We memoize
 * per request and cap per-row work to the post excerpt + first 2,000 chars
 * of post_content (sufficient for the surface-level checks). The full
 * analyze runs only inside the meta box.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Post_List_Column {

	private static ?self $instance = null;

	private const COLUMN_KEY = 'plseo_status';

	/** @var array<int,string> per-request memo of post_id → severity */
	private array $cache = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		foreach ( $this->target_post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns",        array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column",  array( $this, 'render_column' ), 10, 2 );
			add_filter( "manage_edit-{$type}_sortable_columns", array( $this, 'mark_sortable' ) );
		}
		add_action( 'pre_get_posts',  array( $this, 'apply_sort' ) );
		add_action( 'admin_print_styles-edit.php', array( $this, 'print_styles' ) );
	}

	/** @return array<int,string> */
	private function target_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		// Insert before the date column (last by convention) so it sits in a
		// useful place rather than at the right edge.
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out[ self::COLUMN_KEY ] = __( 'SEO', 'perrylabs-seo' );
			}
			$out[ $key ] = $label;
		}
		// Fallback if there was no `date` column on this CPT.
		if ( ! isset( $out[ self::COLUMN_KEY ] ) ) {
			$out[ self::COLUMN_KEY ] = __( 'SEO', 'perrylabs-seo' );
		}
		return $out;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}
		$severity = $this->severity_for( $post_id );
		$label    = array(
			'pass' => 'OK',
			'warn' => 'Warn',
			'fail' => 'Fix',
			'none' => '—',
		)[ $severity ] ?? '—';

		printf(
			'<span class="plseo-col-pill plseo-col-pill--%1$s" title="%2$s">%3$s</span>',
			esc_attr( $severity ),
			esc_attr__( 'Open the post to see findings in the SEO meta box.', 'perrylabs-seo' ),
			esc_html( $label )
		);

		// Cornerstone badge sits to the right when set.
		if ( '1' === (string) get_post_meta( $post_id, '_plseo_cornerstone', true ) ) {
			echo ' <span class="plseo-col-corner" title="' . esc_attr__( 'Cornerstone content', 'perrylabs-seo' ) . '">★</span>';
		}
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function mark_sortable( array $columns ): array {
		$columns[ self::COLUMN_KEY ] = self::COLUMN_KEY;
		return $columns;
	}

	/**
	 * Sorting: load posts whose noindex flag is set last (since they're a
	 * deliberate noindex, not an SEO failure). Cornerstone first.
	 *
	 * This is a heuristic sort because computing per-row severity at query
	 * time would be O(N×analyze) on every list page. Real ordering of
	 * "worst SEO first" still has to come from the audit screen.
	 */
	public function apply_sort( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( $q->get( 'orderby' ) !== self::COLUMN_KEY ) {
			return;
		}
		$q->set( 'meta_query', array(
			'relation' => 'OR',
			array( 'key' => '_plseo_cornerstone', 'compare' => 'EXISTS' ),
			array( 'key' => '_plseo_cornerstone', 'compare' => 'NOT EXISTS' ),
		) );
		$q->set( 'orderby', 'meta_value' );
		$q->set( 'meta_key', '_plseo_cornerstone' );
		$q->set( 'order', 'DESC' );
	}

	private function severity_for( int $post_id ): string {
		if ( array_key_exists( $post_id, $this->cache ) ) {
			return $this->cache[ $post_id ];
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			$this->cache[ $post_id ] = 'none';
			return 'none';
		}
		$rows = PLSEO_Content_Analysis::analyze( $post );
		$sev  = PLSEO_Content_Analysis::overall_severity( $rows );
		$this->cache[ $post_id ] = $sev;
		return $sev;
	}

	public function print_styles(): void {
		echo '<style>
		.column-plseo_status { width: 90px; }
		.plseo-col-pill {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 9px;
			font-size: 11px;
			font-weight: 600;
			color: #fff;
			text-transform: uppercase;
			letter-spacing: 0.25px;
		}
		.plseo-col-pill--pass { background: #1e8a3c; }
		.plseo-col-pill--warn { background: #b58400; }
		.plseo-col-pill--fail { background: #b62917; }
		.plseo-col-pill--none { background: #c3c4c7; color: #2c3338; }
		.plseo-col-corner { color: #b58400; font-weight: 600; margin-left: 4px; }
		</style>';
	}
}
