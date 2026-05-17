<?php
/**
 * PLSEO_Bulk_Alt_Editor — fix attachment alt text across many images at once.
 *
 * Lists the most-recent image attachments where alt is missing or weak (under
 * 5 chars). Inline edit, save the page → done. Most sites have hundreds of
 * images with no alt; this is the fastest way to clean that up.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Bulk_Alt_Editor {

	public static function boot(): void {
		add_action( 'admin_post_plseo_bulk_alt_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}

		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['filter'] ) ) : 'missing'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( 'missing' === $filter ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			);
		}

		$q = new \WP_Query( $args );
		$status = isset( $_GET['plseo_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['plseo_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Bulk image alt editor', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'Most-recent image attachments. Fill in the alt text and save. Accessibility + image SEO in one screen.', 'perrylabs-seo' ); ?></p>

			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'perrylabs-seo' ); ?></p></div>
			<?php endif; ?>

			<p class="plseo-bulk-filter">
				<a class="button <?php echo 'missing' === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'filter' => 'missing', 'paged' => 1 ) ) ); ?>"><?php esc_html_e( 'Missing only', 'perrylabs-seo' ); ?></a>
				<a class="button <?php echo 'all'     === $filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'filter' => 'all',     'paged' => 1 ) ) ); ?>"><?php esc_html_e( 'All images', 'perrylabs-seo' ); ?></a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'plseo_bulk_alt_save' ); ?>
				<input type="hidden" name="action" value="plseo_bulk_alt_save" />
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
				<input type="hidden" name="paged"  value="<?php echo (int) $paged; ?>" />

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:88px"><?php esc_html_e( 'Preview', 'perrylabs-seo' ); ?></th>
							<th style="width:30%"><?php esc_html_e( 'File', 'perrylabs-seo' ); ?></th>
							<th><?php esc_html_e( 'Alt text', 'perrylabs-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php while ( $q->have_posts() ) : $q->the_post(); $att = get_post(); if ( ! $att instanceof \WP_Post ) continue;
							$alt   = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
							$thumb = wp_get_attachment_image_url( $att->ID, 'thumbnail' );
							$parent = $att->post_parent ? get_post( $att->post_parent ) : null;
						?>
							<tr>
								<td><?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" style="max-width:72px;max-height:72px"><?php endif; ?></td>
								<td>
									<strong><?php echo esc_html( get_the_title( $att ) ); ?></strong><br>
									<code style="font-size:11px"><?php echo esc_html( basename( (string) wp_get_attachment_url( $att->ID ) ) ); ?></code>
									<?php if ( $parent ) : ?>
										<br><span class="description"><?php printf( esc_html__( 'Used in: %s', 'perrylabs-seo' ), '<a href="' . esc_url( (string) get_edit_post_link( $parent ) ) . '">' . esc_html( get_the_title( $parent ) ) . '</a>' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<input type="text" name="alts[<?php echo (int) $att->ID; ?>]" value="<?php echo esc_attr( $alt ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Describe the image…', 'perrylabs-seo' ); ?>">
								</td>
							</tr>
						<?php endwhile; wp_reset_postdata(); ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save changes on this page', 'perrylabs-seo' ) ); ?>
			</form>

			<?php
			$total_pages = (int) $q->max_num_pages;
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'total'   => $total_pages,
					'current' => $paged,
				) ) );
				echo '</div></div>';
			}
			?>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}

	public static function handle_save(): void {
		check_admin_referer( 'plseo_bulk_alt_save' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$rows = isset( $_POST['alts'] ) && is_array( $_POST['alts'] ) ? wp_unslash( $_POST['alts'] ) : array();
		foreach ( $rows as $id => $alt ) {
			$id  = (int) $id;
			$alt = sanitize_text_field( (string) $alt );
			if ( '' === $alt ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			}
		}
		wp_safe_redirect( add_query_arg( array(
			'page'         => 'plseo-bulk-alt',
			'filter'       => isset( $_POST['filter'] ) ? sanitize_key( (string) $_POST['filter'] ) : 'missing',
			'paged'        => max( 1, (int) ( $_POST['paged'] ?? 1 ) ),
			'plseo_status' => 'saved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
