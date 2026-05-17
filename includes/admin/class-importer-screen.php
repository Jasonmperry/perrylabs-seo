<?php
/**
 * PLSEO_Importer_Screen — admin UI for the Yoast / RankMath importer.
 *
 * Shows which source plugins have data, eligible counts, and a one-button
 * import per source. Always non-destructive by default — overwrite is opt-in.
 *
 * Lives at admin.php?page=plseo-import. Linked from Tools tab.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Importer_Screen {

	public static function boot(): void {
		add_action( 'admin_post_plseo_import_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}

		$detected = PLSEO_Importer::detect_sources();
		$status   = isset( $_GET['plseo_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['plseo_status'] ) ) : '';
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Import from Yoast / RankMath', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'One-shot migration of per-post SEO meta from a previous plugin. Non-destructive by default: existing PerryLabs SEO meta on a post is never overwritten unless you tick the overwrite box.', 'perrylabs-seo' ); ?></p>

			<?php if ( 'imported' === $status ) :
				$written = (int) ( $_GET['written']   ?? 0 );
				$skipped = (int) ( $_GET['skipped']   ?? 0 );
				$source  = sanitize_text_field( wp_unslash( (string) ( $_GET['source'] ?? '' ) ) );
			?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %1$s source name, %2$d written, %3$d skipped */
							esc_html__( 'Imported from %1$s: wrote meta for %2$d post(s), skipped %3$d (already had PerryLabs SEO meta).', 'perrylabs-seo' ),
							esc_html( ucfirst( $source ) ),
							$written,
							$skipped
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $detected ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No Yoast or RankMath meta detected in this database. Nothing to import.', 'perrylabs-seo' ); ?></p>
				</div>
			<?php else : ?>
				<div class="plseo-tool-cards">
					<?php foreach ( $detected as $source ) :
						$count = PLSEO_Importer::count_eligible( $source );
						$label = 'yoast' === $source ? 'Yoast SEO' : 'RankMath';
					?>
						<div class="plseo-card">
							<h3><?php echo esc_html( $label ); ?></h3>
							<p>
								<?php
								printf(
									/* translators: %d post count */
									esc_html__( '%d post(s) have %s meta.', 'perrylabs-seo' ),
									(int) $count,
									esc_html( $label )
								);
								?>
							</p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'plseo_import_run' ); ?>
								<input type="hidden" name="action" value="plseo_import_run" />
								<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
								<p>
									<label>
										<input type="checkbox" name="overwrite" value="1" />
										<?php esc_html_e( 'Overwrite existing PerryLabs SEO meta', 'perrylabs-seo' ); ?>
									</label>
								</p>
								<p>
									<label>
										<?php esc_html_e( 'Batch size:', 'perrylabs-seo' ); ?>
										<input type="number" name="batch" value="500" min="50" max="2000" style="width:80px" />
									</label>
								</p>
								<p>
									<button class="button button-primary"><?php esc_html_e( 'Import this batch', 'perrylabs-seo' ); ?></button>
								</p>
								<p class="description"><?php esc_html_e( 'Imports up to the batch limit. Re-run until written + skipped equals total eligible posts.', 'perrylabs-seo' ); ?></p>
							</form>
						</div>
					<?php endforeach; ?>
				</div>

				<h3 style="margin-top:30px"><?php esc_html_e( 'What gets mapped', 'perrylabs-seo' ); ?></h3>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'PerryLabs SEO', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Yoast', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'RankMath', 'perrylabs-seo' ); ?></th>
					</tr></thead>
					<tbody>
						<?php
						$map = array(
							'_plseo_title'         => array( '_yoast_wpseo_title',           'rank_math_title' ),
							'_plseo_description'   => array( '_yoast_wpseo_metadesc',        'rank_math_description' ),
							'_plseo_canonical'     => array( '_yoast_wpseo_canonical',       'rank_math_canonical_url' ),
							'_plseo_social_image'  => array( '_yoast_wpseo_opengraph-image', 'rank_math_facebook_image' ),
							'_plseo_focus_keyword' => array( '_yoast_wpseo_focuskw',         'rank_math_focus_keyword' ),
							'_plseo_cornerstone'   => array( '_yoast_wpseo_is_cornerstone',  'rank_math_pillar_content' ),
							'_plseo_noindex'       => array( '_yoast_wpseo_meta-robots-noindex',  'rank_math_robots (noindex)' ),
							'_plseo_nofollow'      => array( '_yoast_wpseo_meta-robots-nofollow', 'rank_math_robots (nofollow)' ),
						);
						foreach ( $map as $target => $sources ) {
							printf(
								'<tr><td><code>%1$s</code></td><td><code>%2$s</code></td><td><code>%3$s</code></td></tr>',
								esc_html( $target ),
								esc_html( $sources[0] ),
								esc_html( $sources[1] )
							);
						}
						?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}

	public static function handle_run(): void {
		check_admin_referer( 'plseo_import_run' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$source    = sanitize_key( (string) ( $_POST['source']    ?? '' ) );
		$overwrite = ! empty( $_POST['overwrite'] );
		$batch     = max( 50, min( 2000, (int) ( $_POST['batch'] ?? 500 ) ) );

		$r = PLSEO_Importer::run( $source, array(
			'overwrite' => $overwrite,
			'batch'     => $batch,
		) );

		wp_safe_redirect( add_query_arg( array(
			'page'         => 'plseo-import',
			'plseo_status' => 'imported',
			'source'       => $source,
			'written'      => (int) $r['written'],
			'skipped'      => (int) $r['skipped'],
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
