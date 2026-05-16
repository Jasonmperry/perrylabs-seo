<?php
/**
 * PLSEO_Dashboard_Widget — AEO summary widget on wp-admin/index.php.
 *
 * 30-day AI bot visit count, top 3 bots, top 3 URLs, link to full dashboard.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Dashboard_Widget {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'plseo_aeo_summary', __( 'AI crawler activity (PerryLabs SEO + AEO)', 'perrylabs-seo' ), array( $this, 'render' ) );
	}

	public function render(): void {
		$log    = PLSEO_AI_Visit_Log::instance();
		$total  = $log->total_hits( 30 );
		$by_bot = array_slice( $log->summary_by_bot( 30 ), 0, 3 );
		$top    = array_slice( $log->top_urls( 30, 3 ), 0, 3 );

		echo '<p><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong> ' . esc_html__( 'AI crawler hits in the last 30 days.', 'perrylabs-seo' ) . '</p>';

		if ( ! empty( $by_bot ) ) {
			echo '<h4>' . esc_html__( 'Top bots', 'perrylabs-seo' ) . '</h4><ul>';
			foreach ( $by_bot as $row ) {
				$bot = PLSEO_AI_Crawlers::CATALOG[ $row->bot_slug ] ?? array( 'label' => $row->bot_slug );
				printf( '<li><strong>%s</strong> — %s</li>', esc_html( (string) $bot['label'] ), esc_html( number_format_i18n( (int) $row->hits ) . ' ' . __( 'hits', 'perrylabs-seo' ) ) );
			}
			echo '</ul>';
		}

		if ( ! empty( $top ) ) {
			echo '<h4>' . esc_html__( 'Most-visited URLs', 'perrylabs-seo' ) . '</h4><ul>';
			foreach ( $top as $row ) {
				printf( '<li><a href="%s" target="_blank">%s</a> — %s</li>', esc_url( (string) $row->url ), esc_html( (string) $row->url ), esc_html( number_format_i18n( (int) $row->hits ) ) );
			}
			echo '</ul>';
		}

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=plseo-aeo-dashboard' ) ) . '">' . esc_html__( 'Open AEO dashboard →', 'perrylabs-seo' ) . '</a></p>';
	}
}
