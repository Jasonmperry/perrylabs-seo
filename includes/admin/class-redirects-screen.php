<?php
/**
 * PLSEO_Redirects_Screen — admin page that manages redirects.
 *
 * Pulled out of class-admin.php so the menu router stays focused.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Redirects_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$rows = PLSEO_Redirects::instance()->all( 200 );
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Redirects', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'Match exact paths or PCRE patterns. Targets starting with "/" resolve against your home URL.', 'perrylabs-seo' ); ?></p>

			<h2 class="title"><?php esc_html_e( 'Add redirect', 'perrylabs-seo' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plseo-redirect-add">
				<?php wp_nonce_field( 'plseo_redirect_create' ); ?>
				<input type="hidden" name="action" value="plseo_redirect_create" />
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="plseo-rd-src"><?php esc_html_e( 'Source', 'perrylabs-seo' ); ?></label></th>
						<td><input type="text" id="plseo-rd-src" name="source_url" required class="regular-text code" placeholder="/old-path/"></td>
					</tr>
					<tr>
						<th><label for="plseo-rd-tgt"><?php esc_html_e( 'Target', 'perrylabs-seo' ); ?></label></th>
						<td><input type="text" id="plseo-rd-tgt" name="target_url" required class="regular-text code" placeholder="/new-path/ or https://example.com/x"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Match type', 'perrylabs-seo' ); ?></th>
						<td>
							<select name="match_type">
								<option value="exact"><?php esc_html_e( 'Exact', 'perrylabs-seo' ); ?></option>
								<option value="wildcard"><?php esc_html_e( 'Wildcard (/old/* → /new/$1)', 'perrylabs-seo' ); ?></option>
								<option value="regex"><?php esc_html_e( 'Regex (PCRE)', 'perrylabs-seo' ); ?></option>
							</select>
							<select name="status_code">
								<option value="301">301 — <?php esc_html_e( 'Permanent', 'perrylabs-seo' ); ?></option>
								<option value="302">302 — <?php esc_html_e( 'Temporary', 'perrylabs-seo' ); ?></option>
								<option value="307">307 — <?php esc_html_e( 'Temporary, preserve method', 'perrylabs-seo' ); ?></option>
								<option value="308">308 — <?php esc_html_e( 'Permanent, preserve method', 'perrylabs-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="plseo-rd-notes"><?php esc_html_e( 'Notes', 'perrylabs-seo' ); ?></label></th>
						<td><input type="text" id="plseo-rd-notes" name="notes" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'perrylabs-seo' ) ); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Active redirects', 'perrylabs-seo' ); ?></h2>
			<table class="wp-list-table widefat fixed striped plseo-list">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Target', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Type', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Last hit', 'perrylabs-seo' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><em><?php esc_html_e( 'No redirects yet.', 'perrylabs-seo' ); ?></em></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( (string) $r->source_url ); ?></code></td>
							<td><code><?php echo esc_html( (string) $r->target_url ); ?></code></td>
							<td><?php echo (int) $r->status_code; ?></td>
							<td><?php echo esc_html( (string) $r->match_type ); ?></td>
							<td><?php echo (int) $r->hits; ?></td>
							<td><?php echo $r->last_hit ? esc_html( (string) $r->last_hit ) : '—'; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'perrylabs-seo' ) ); ?>');">
									<?php wp_nonce_field( 'plseo_redirect_delete_' . (int) $r->id ); ?>
									<input type="hidden" name="action" value="plseo_redirect_delete" />
									<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
									<button class="button-link delete"><?php esc_html_e( 'Delete', 'perrylabs-seo' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="title"><?php esc_html_e( 'CSV import / export', 'perrylabs-seo' ); ?></h2>
			<div class="plseo-tool-cards">
				<div class="plseo-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'plseo_redirect_import' ); ?>
						<input type="hidden" name="action" value="plseo_redirect_import" />
						<p><?php esc_html_e( 'CSV columns: source_url, target_url, status_code, match_type, notes. Header row optional.', 'perrylabs-seo' ); ?></p>
						<input type="file" name="plseo_csv" accept=".csv,text/csv" required />
						<button class="button"><?php esc_html_e( 'Import CSV', 'perrylabs-seo' ); ?></button>
					</form>
				</div>
				<div class="plseo-card">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'plseo_redirect_export' ); ?>
						<input type="hidden" name="action" value="plseo_redirect_export" />
						<p><?php esc_html_e( 'Download all redirects as a CSV file.', 'perrylabs-seo' ); ?></p>
						<button class="button button-primary"><?php esc_html_e( 'Export CSV', 'perrylabs-seo' ); ?></button>
					</form>
				</div>
			</div>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}
}
