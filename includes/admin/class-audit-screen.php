<?php
/**
 * PLSEO_Audit_Screen — admin page that renders site audit results.
 *
 * Separate from class-admin.php so the rendering logic doesn't bloat the menu router.
 * Layout mimics Semrush's overview: severity tiles → grouped findings list,
 * with each row linking back to the post editor where the issue can be fixed.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Audit_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}

		// Refresh via GET parameter (nonce'd link).
		if ( isset( $_GET['plseo_refresh'] ) && check_admin_referer( 'plseo_audit_refresh' ) ) {
			PLSEO_Audit::instance()->bust_cache();
			wp_safe_redirect( remove_query_arg( array( 'plseo_refresh', '_wpnonce' ) ) );
			exit;
		}

		$results = PLSEO_Audit::instance()->results();
		$catalog = PLSEO_Audit::check_catalog();
		$totals  = self::totals_by_severity( $results['counts'], $catalog );

		$refresh_url = wp_nonce_url(
			add_query_arg( 'plseo_refresh', '1' ),
			'plseo_audit_refresh'
		);
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Site audit', 'perrylabs-seo' ) ); ?>
			<p>
				<a href="<?php echo esc_url( $refresh_url ); ?>" class="button"><?php esc_html_e( 'Re-run now', 'perrylabs-seo' ); ?></a>
			</p>
			<p class="description">
				<?php
				$scanned   = (int) ( $results['scanned'] ?? 0 );
				$total     = (int) ( $results['total_eligible'] ?? $scanned );
				$capped    = ! empty( $results['capped'] );
				$relative  = human_time_diff( (int) $results['generated_at'] ) . ' ' . __( 'ago', 'perrylabs-seo' );
				if ( $capped ) {
					printf(
						/* translators: %1$s scanned count, %2$s total eligible, %3$s relative time */
						esc_html__( 'Scanned %1$s of %2$s eligible post(s) — newest first. Last run %3$s.', 'perrylabs-seo' ),
						esc_html( number_format_i18n( $scanned ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( $relative )
					);
				} else {
					printf(
						/* translators: %1$s scanned count, %2$s relative time */
						esc_html__( 'Scanned all %1$s post(s). Last run %2$s.', 'perrylabs-seo' ),
						esc_html( number_format_i18n( $scanned ) ),
						esc_html( $relative )
					);
				}
				?>
			</p>
			<?php if ( $capped ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %d batch cap */
							esc_html__( 'Audit examines up to %d posts per run, ordered by most-recently-modified. Older posts cycle in as newer ones drop out — content opportunities in your archive aren\'t missed permanently, but the freshest content is always covered first.', 'perrylabs-seo' ),
							500
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="plseo-stat-row">
				<div class="plseo-stat plseo-stat--error"><span class="plseo-stat-num"><?php echo number_format_i18n( $totals['error'] ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Errors', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat plseo-stat--warn"><span class="plseo-stat-num"><?php echo number_format_i18n( $totals['warning'] ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Warnings', 'perrylabs-seo' ); ?></span></div>
				<div class="plseo-stat plseo-stat--notice"><span class="plseo-stat-num"><?php echo number_format_i18n( $totals['notice'] ); ?></span><span class="plseo-stat-label"><?php esc_html_e( 'Notices', 'perrylabs-seo' ); ?></span></div>
			</div>

			<?php
			foreach ( array( 'error' => __( 'Errors', 'perrylabs-seo' ), 'warning' => __( 'Warnings', 'perrylabs-seo' ), 'notice' => __( 'Notices', 'perrylabs-seo' ) ) as $sev => $sev_label ) {
				self::render_severity_group( $sev, $sev_label, $results['findings'], $catalog );
			}
			?>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,int>                                    $counts
	 * @param array<string,array{severity:string,label:string,detail:string}> $catalog
	 * @return array{error:int,warning:int,notice:int}
	 */
	private static function totals_by_severity( array $counts, array $catalog ): array {
		$t = array( 'error' => 0, 'warning' => 0, 'notice' => 0 );
		foreach ( $counts as $check_id => $n ) {
			$sev = $catalog[ $check_id ]['severity'] ?? 'notice';
			$t[ $sev ] = ( $t[ $sev ] ?? 0 ) + (int) $n;
		}
		return $t;
	}

	/**
	 * @param array<string,array<int,array{post_id:int,detail:string}>> $findings
	 * @param array<string,array{severity:string,label:string,detail:string}> $catalog
	 */
	private static function render_severity_group( string $severity, string $sev_label, array $findings, array $catalog ): void {
		// Filter checks belonging to this severity.
		$checks = array_filter(
			$catalog,
			static fn( $info ) => ( $info['severity'] ?? '' ) === $severity
		);

		echo '<h2 class="plseo-audit-group plseo-audit-group--' . esc_attr( $severity ) . '">' . esc_html( $sev_label ) . '</h2>';
		$total_in_group = 0;
		foreach ( $checks as $check_id => $info ) {
			$rows = $findings[ $check_id ] ?? array();
			if ( empty( $rows ) ) {
				continue;
			}
			$total_in_group += count( $rows );
			?>
			<details class="plseo-audit-check" open>
				<summary>
					<span class="plseo-pill plseo-pill--<?php echo esc_attr( $severity === 'error' ? 'fail' : ( $severity === 'warning' ? 'warn' : 'pass' ) ); ?>">
						<?php echo esc_html( (string) count( $rows ) ); ?>
					</span>
					<strong><?php echo esc_html( $info['label'] ); ?></strong>
					<span class="plseo-audit-help"><?php echo esc_html( $info['detail'] ); ?></span>
				</summary>
				<table class="wp-list-table widefat striped plseo-audit-table">
					<thead><tr><th><?php esc_html_e( 'Post', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Detail', 'perrylabs-seo' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) :
						$pid = (int) ( $r['post_id'] ?? 0 );
						$detail = (string) ( $r['detail'] ?? '' );
						if ( $pid > 0 ) {
							$post = get_post( $pid );
							$title = $post ? get_the_title( $post ) : sprintf( '#%d', $pid );
							$edit  = $post ? get_edit_post_link( $post, '' ) : '';
							$view  = $post ? get_permalink( $post ) : '';
						} else {
							$title = __( 'Site-wide', 'perrylabs-seo' );
							$edit  = admin_url( 'admin.php?page=plseo&tab=schema' );
							$view  = '';
						}
					?>
						<tr>
							<td><strong><?php echo esc_html( (string) $title ); ?></strong></td>
							<td><?php echo esc_html( $detail ); ?></td>
							<td>
								<?php if ( $edit ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Fix', 'perrylabs-seo' ); ?></a>
								<?php endif; ?>
								<?php if ( $view ) : ?>
									<a class="button-link" href="<?php echo esc_url( $view ); ?>" target="_blank"><?php esc_html_e( 'View', 'perrylabs-seo' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
			<?php
		}
		if ( 0 === $total_in_group ) {
			echo '<p class="plseo-audit-clean">' . esc_html__( 'No issues in this group.', 'perrylabs-seo' ) . '</p>';
		}
	}
}
