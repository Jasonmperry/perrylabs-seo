<?php
/**
 * PLSEO_Admin_Bar — front-end status pill in the WP admin bar.
 *
 * For logged-in editors viewing a singular page, shows the post's
 * worst content-analysis severity at a glance. Clicking jumps to the
 * editor and scrolls to the analysis panel.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Admin_Bar {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_bar_menu', array( $this, 'register' ), 90 );
		add_action( 'wp_head',        array( $this, 'inline_styles' ) );
		add_action( 'admin_head',     array( $this, 'inline_styles' ) );
	}

	public function register( \WP_Admin_Bar $bar ): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$analysis = PLSEO_Content_Analysis::analyze( $post );
		$overall  = PLSEO_Content_Analysis::overall_severity( $analysis );
		$label    = array(
			'pass' => __( 'SEO: OK', 'perrylabs-seo' ),
			'warn' => __( 'SEO: warnings', 'perrylabs-seo' ),
			'fail' => __( 'SEO: issues', 'perrylabs-seo' ),
		)[ $overall ] ?? __( 'SEO', 'perrylabs-seo' );

		$bar->add_node( array(
			'id'    => 'plseo-status',
			'title' => sprintf(
				'<span class="ab-icon dashicons dashicons-chart-line"></span><span class="ab-label plseo-ab-pill plseo-ab-pill--%s">%s</span>',
				esc_attr( $overall ),
				esc_html( $label )
			),
			'href'  => esc_url( get_edit_post_link( $post, '' ) . '#plseo-meta-box' ),
		) );

		// Sub-nodes: one per finding (truncated to 6).
		$i = 0;
		foreach ( $analysis as $row ) {
			$bar->add_node( array(
				'id'     => 'plseo-finding-' . $i,
				'parent' => 'plseo-status',
				'title'  => sprintf(
					'<span class="plseo-ab-dot plseo-ab-dot--%s"></span> %s',
					esc_attr( $row['severity'] ),
					esc_html( $row['label'] )
				),
				'href'   => esc_url( get_edit_post_link( $post, '' ) . '#plseo-meta-box' ),
			) );
			if ( ++$i >= 6 ) {
				break;
			}
		}
	}

	public function inline_styles(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		echo '<style>
		#wpadminbar .plseo-ab-pill{padding:2px 8px;border-radius:9px;font-size:11px;color:#fff!important;margin-left:4px}
		#wpadminbar .plseo-ab-pill--pass{background:#1e8a3c}
		#wpadminbar .plseo-ab-pill--warn{background:#b58400}
		#wpadminbar .plseo-ab-pill--fail{background:#b62917}
		#wpadminbar .plseo-ab-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:middle}
		#wpadminbar .plseo-ab-dot--pass{background:#1e8a3c}
		#wpadminbar .plseo-ab-dot--warn{background:#b58400}
		#wpadminbar .plseo-ab-dot--fail{background:#b62917}
		</style>';
	}
}
