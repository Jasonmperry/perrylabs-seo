<?php
/**
 * PLSEO_Bulk_Editor — edit SEO titles + descriptions for many posts at once.
 *
 * Most plugins make you click into each post to fix metadata; this is a
 * spreadsheet-style screen that lets you sweep an entire post type in minutes.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Bulk_Editor {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_post_plseo_bulk_save', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}

		$post_type = isset( $_GET['ptype'] ) ? sanitize_key( wp_unslash( (string) $_GET['ptype'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page = 30;
		$query    = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		$message = isset( $_GET['plseo_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['plseo_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Bulk SEO editor', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'Edit titles and descriptions across many posts in one screen. Empty cells fall back to the title template.', 'perrylabs-seo' ); ?></p>

			<?php if ( 'saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'perrylabs-seo' ); ?></p></div>
			<?php endif; ?>

			<form method="get" class="plseo-bulk-filter">
				<input type="hidden" name="page" value="plseo-bulk" />
				<label><?php esc_html_e( 'Post type', 'perrylabs-seo' ); ?>
					<select name="ptype">
						<?php foreach ( $types as $t ) : ?>
							<option value="<?php echo esc_attr( $t->name ); ?>" <?php selected( $post_type, $t->name ); ?>><?php echo esc_html( $t->labels->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button class="button"><?php esc_html_e( 'Filter', 'perrylabs-seo' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'plseo_bulk_save' ); ?>
				<input type="hidden" name="action" value="plseo_bulk_save" />
				<input type="hidden" name="ptype" value="<?php echo esc_attr( $post_type ); ?>" />
				<input type="hidden" name="paged" value="<?php echo (int) $paged; ?>" />

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:22%"><?php esc_html_e( 'Post', 'perrylabs-seo' ); ?></th>
							<th style="width:30%"><?php esc_html_e( 'SEO title', 'perrylabs-seo' ); ?></th>
							<th><?php esc_html_e( 'Meta description', 'perrylabs-seo' ); ?></th>
							<th style="width:90px"><?php esc_html_e( 'Noindex', 'perrylabs-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php while ( $query->have_posts() ) : $query->the_post(); $post = get_post(); if ( ! $post instanceof \WP_Post ) continue;
						$title = (string) plseo_get_post_meta( $post->ID, 'title', '' );
						$desc  = (string) plseo_get_post_meta( $post->ID, 'description', '' );
						$ni    = plseo_get_post_meta( $post->ID, 'noindex', '' ) === '1';
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( get_the_title() ); ?></strong><br>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $post ) ); ?>"><?php esc_html_e( 'Edit', 'perrylabs-seo' ); ?></a> ·
								<a href="<?php echo esc_url( (string) get_permalink( $post ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'perrylabs-seo' ); ?></a>
							</td>
							<td>
								<input type="text" name="rows[<?php echo (int) $post->ID; ?>][title]" value="<?php echo esc_attr( $title ); ?>" class="widefat">
							</td>
							<td>
								<textarea name="rows[<?php echo (int) $post->ID; ?>][description]" rows="2" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
							</td>
							<td style="text-align:center">
								<input type="checkbox" name="rows[<?php echo (int) $post->ID; ?>][noindex]" value="1" <?php checked( $ni ); ?>>
							</td>
						</tr>
					<?php endwhile; wp_reset_postdata(); ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save changes on this page', 'perrylabs-seo' ) ); ?>
			</form>

			<?php
			$total_pages = (int) $query->max_num_pages;
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

	public function handle_save(): void {
		check_admin_referer( 'plseo_bulk_save' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : array();
		foreach ( $rows as $post_id => $vals ) {
			$post_id = (int) $post_id;
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$title = isset( $vals['title'] ) ? sanitize_text_field( (string) $vals['title'] ) : '';
			$desc  = isset( $vals['description'] ) ? sanitize_textarea_field( (string) $vals['description'] ) : '';
			$ni    = ! empty( $vals['noindex'] );

			'' === $title ? delete_post_meta( $post_id, '_plseo_title' ) : update_post_meta( $post_id, '_plseo_title', $title );
			'' === $desc  ? delete_post_meta( $post_id, '_plseo_description' ) : update_post_meta( $post_id, '_plseo_description', $desc );
			$ni ? update_post_meta( $post_id, '_plseo_noindex', '1' ) : delete_post_meta( $post_id, '_plseo_noindex' );
		}

		$ptype = isset( $_POST['ptype'] ) ? sanitize_key( (string) $_POST['ptype'] ) : 'post';
		$paged = max( 1, (int) ( $_POST['paged'] ?? 1 ) );
		wp_safe_redirect( add_query_arg( array(
			'page'         => 'plseo-bulk',
			'ptype'        => $ptype,
			'paged'        => $paged,
			'plseo_status' => 'saved',
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
