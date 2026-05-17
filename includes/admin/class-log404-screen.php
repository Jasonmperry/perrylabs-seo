<?php
/**
 * PLSEO_Log404_Screen — admin page showing recent 404s with one-click 301 promotion.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Log404_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$rows = PLSEO_404_Log::instance()->recent( 100 );
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( '404 log', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'URLs visitors are trying to reach that no longer exist. Promote any of them into a redirect with one click.', 'perrylabs-seo' ); ?></p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:38%"><?php esc_html_e( 'URL', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Last hit', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'perrylabs-seo' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><em><?php esc_html_e( 'No unresolved 404s. Nice.', 'perrylabs-seo' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $r->url ); ?></code></td>
							<td><?php echo (int) $r->hits; ?></td>
							<td><?php echo esc_html( (string) $r->last_hit ); ?></td>
							<td><?php echo $r->referrer ? '<code>' . esc_html( (string) $r->referrer ) . '</code>' : '—'; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plseo-promote-form">
									<?php wp_nonce_field( 'plseo_404_promote_' . (int) $r->id ); ?>
									<input type="hidden" name="action" value="plseo_404_promote" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<input type="hidden" name="source_url" value="<?php echo esc_attr( (string) $r->url ); ?>" />
									<input type="text" name="target_url" placeholder="<?php esc_attr_e( 'Redirect to…', 'perrylabs-seo' ); ?>" class="regular-text" required />
									<button class="button button-primary"><?php esc_html_e( 'Create 301', 'perrylabs-seo' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'plseo_404_resolve_' . (int) $r->id ); ?>
									<input type="hidden" name="action" value="plseo_404_resolve" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="button-link"><?php esc_html_e( 'Mark resolved', 'perrylabs-seo' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}
}
