<?php
/**
 * PLSEO_AEO_Dashboard_Screen — 30-day AI crawler activity dashboard.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_AEO_Dashboard_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$log    = PLSEO_AI_Visit_Log::instance();
		$by_bot = $log->summary_by_bot( 30 );
		$top    = $log->top_urls( 30, 25 );
		$total  = $log->total_hits( 30 );
		?>
		<div class="wrap plseo-wrap">
			<h1><?php esc_html_e( 'AEO dashboard', 'perrylabs-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'AI crawler activity over the last 30 days. Each hit is an answer engine looking at your content — the strongest free signal that your AEO work is being indexed.', 'perrylabs-seo' ); ?></p>

			<div class="plseo-stat-row">
				<div class="plseo-stat"><span class="plseo-stat-num"><?php echo number_format_i18n( $total ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Total AI visits / 30d', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat"><span class="plseo-stat-num"><?php echo number_format_i18n( count( $by_bot ) ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Distinct bots', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat"><span class="plseo-stat-num"><?php echo number_format_i18n( count( $top ) ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'URLs visited', 'perrylabs-seo' ); ?></span></div>
			</div>

			<h2><?php esc_html_e( 'By bot', 'perrylabs-seo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Bot', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Operator', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Unique URLs', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Last seen', 'perrylabs-seo' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $by_bot ) ) : ?>
						<tr><td colspan="5"><em><?php esc_html_e( 'No AI bot visits recorded yet. Logging is on by default — give it a few days.', 'perrylabs-seo' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $by_bot as $row ) :
						$bot = PLSEO_AI_Crawlers::CATALOG[ $row->bot_slug ] ?? array( 'label' => $row->bot_slug, 'operator' => '' );
					?>
						<tr>
							<td><strong><?php echo esc_html( (string) $bot['label'] ); ?></strong></td>
							<td><?php echo esc_html( (string) $bot['operator'] ); ?></td>
							<td><?php echo number_format_i18n( (int) $row->hits ); ?></td>
							<td><?php echo number_format_i18n( (int) $row->unique_urls ); ?></td>
							<td><?php echo esc_html( (string) $row->last_seen ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Most-visited URLs', 'perrylabs-seo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th style="width:65%"><?php esc_html_e( 'URL', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Bots', 'perrylabs-seo' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $top as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) $row->url ); ?>" target="_blank"><code><?php echo esc_html( (string) $row->url ); ?></code></a></td>
							<td><?php echo number_format_i18n( (int) $row->hits ); ?></td>
							<td><?php echo number_format_i18n( (int) $row->bots ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
