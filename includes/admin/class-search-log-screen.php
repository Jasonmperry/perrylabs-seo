<?php
/**
 * PLSEO_Search_Log_Screen — admin page for the on-site search query log.
 *
 * Two tables side-by-side: top queries by hits, and zero-result queries
 * (the content-opportunity list).
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Search_Log_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$log    = PLSEO_Search_Log::instance();
		$top    = $log->top_queries( 30, 25 );
		$zero   = $log->zero_result_queries( 30, 25 );
		$total  = $log->total_searches( 30 );
		?>
		<div class="wrap plseo-wrap">
			<h1><?php esc_html_e( 'Site search log', 'perrylabs-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'What visitors search for on your own site. The zero-result list is gold — each row is a content gap.', 'perrylabs-seo' ); ?></p>

			<div class="plseo-stat-row">
				<div class="plseo-stat"><span class="plseo-stat-num"><?php echo number_format_i18n( $total ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Searches / 30d', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat"><span class="plseo-stat-num"><?php echo number_format_i18n( count( $top ) ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Distinct queries', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat plseo-stat--warn"><span class="plseo-stat-num"><?php echo number_format_i18n( count( $zero ) ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Zero-result queries', 'perrylabs-seo' ); ?></span></div>
			</div>

			<h2><?php esc_html_e( 'Top queries', 'perrylabs-seo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Query', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Avg results', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Last seen', 'perrylabs-seo' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $top ) ) : ?>
						<tr><td colspan="4"><em><?php esc_html_e( 'No searches logged yet. Logging is enabled by default — give it a few days.', 'perrylabs-seo' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $top as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) $row->query ); ?></strong></td>
							<td><?php echo number_format_i18n( (int) $row->hits ); ?></td>
							<td><?php echo number_format_i18n( (float) $row->avg_results, 1 ); ?></td>
							<td><?php echo esc_html( (string) $row->last_seen ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Zero-result queries (content opportunities)', 'perrylabs-seo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'Query', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Last seen', 'perrylabs-seo' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $zero ) ) : ?>
						<tr><td colspan="3"><em><?php esc_html_e( 'Nice — every search has returned at least one result.', 'perrylabs-seo' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $zero as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) $row->query ); ?></strong></td>
							<td><?php echo number_format_i18n( (int) $row->hits ); ?></td>
							<td><?php echo esc_html( (string) $row->last_seen ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
