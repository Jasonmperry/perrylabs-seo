<?php
/**
 * PerryLabs SEO + AEO — Redirect Manager
 *
 * 301/302 redirect management, 404 logging, CSV import/export.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Redirects {

	/** @var string Redirects table name (without prefix). */
	private const TABLE_REDIRECTS = 'plseo_redirects';

	/** @var string 404 log table name (without prefix). */
	private const TABLE_404_LOG = 'plseo_404_log';

	/** @var string Admin page slug. */
	private const PAGE_SLUG = 'perrylabs-seo-redirects';

	/** @var string Nonce action for redirect forms. */
	private const NONCE_ACTION = 'plseo_redirects_nonce';

	public function __construct() {
		// Frontend: intercept requests early.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'log_404' ), 2 );

		// Admin menu.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
			add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Database Installation
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Create or update the custom database tables.
	 * Called on plugin activation.
	 */
	public static function install_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$redirects_table = $wpdb->prefix . self::TABLE_REDIRECTS;
		$log_table       = $wpdb->prefix . self::TABLE_404_LOG;

		$sql_redirects = "CREATE TABLE {$redirects_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_url varchar(2048) NOT NULL,
			target_url varchar(2048) NOT NULL,
			status_code int NOT NULL DEFAULT 301,
			hits int NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			notes text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source_url (source_url(191))
		) {$charset_collate};";

		$sql_404_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			referrer varchar(2048) DEFAULT NULL,
			hits int NOT NULL DEFAULT 1,
			last_hit datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY url (url(191))
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_redirects );
		dbDelta( $sql_404_log );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Frontend: Redirect Matching
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Check if the current request matches a stored redirect.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() ) {
			return;
		}

		global $wpdb;

		$request_path = $this->get_request_path();
		if ( empty( $request_path ) ) {
			return;
		}

		$table = $wpdb->prefix . self::TABLE_REDIRECTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$redirect = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, target_url, status_code FROM {$table} WHERE source_url = %s LIMIT 1",
				$request_path
			)
		);

		if ( ! $redirect ) {
			return;
		}

		// Atomically increment hit counter.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d",
				current_time( 'mysql' ),
				$redirect->id
			)
		);

		$status_code = in_array( (int) $redirect->status_code, array( 301, 302 ), true )
			? (int) $redirect->status_code
			: 301;

		wp_redirect( esc_url_raw( $redirect->target_url ), $status_code );
		exit;
	}

	/**
	 * Log 404 errors.
	 */
	public function log_404(): void {
		if ( ! is_404() ) {
			return;
		}

		global $wpdb;

		$request_path = $this->get_request_path();
		if ( empty( $request_path ) ) {
			return;
		}

		$table    = $wpdb->prefix . self::TABLE_404_LOG;
		$referrer = wp_get_referer() ?: ( $_SERVER['HTTP_REFERER'] ?? null );
		$now      = current_time( 'mysql' );

		// Check for existing entry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE url = %s LIMIT 1",
				$request_path
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET hits = hits + 1, last_hit = %s, referrer = %s WHERE id = %d",
					$now,
					$referrer ? sanitize_url( $referrer ) : null,
					$existing
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'url'        => $request_path,
					'referrer'   => $referrer ? sanitize_url( $referrer ) : null,
					'hits'       => 1,
					'last_hit'   => $now,
					'created_at' => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Get the current request path, normalized with leading slash and no trailing query string.
	 */
	private function get_request_path(): string {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( empty( $path ) ) {
			return '';
		}

		// Normalize: ensure leading slash, remove trailing slash (except for root).
		$path = '/' . ltrim( $path, '/' );
		if ( $path !== '/' ) {
			$path = rtrim( $path, '/' ) . '/';
		}

		return $path;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Admin Menu
	 * ────────────────────────────────────────────────────────────── */

	public function add_admin_page(): void {
		add_options_page(
			__( 'Redirects — PerryLabs SEO', 'perrylabs-seo' ),
			__( 'SEO Redirects', 'perrylabs-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Admin Actions (Add, Edit, Delete, Import, Export, Delete 404)
	 * ────────────────────────────────────────────────────────────── */

	public function handle_admin_actions(): void {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::PAGE_SLUG ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Export CSV.
		if ( isset( $_GET['plseo_action'] ) && $_GET['plseo_action'] === 'export_csv' ) {
			check_admin_referer( 'plseo_export_csv' );
			$this->export_csv();
		}

		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}

		// Add redirect.
		if ( isset( $_POST['plseo_add_redirect'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_add_redirect();
			return;
		}

		// Edit redirect.
		if ( isset( $_POST['plseo_edit_redirect'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_edit_redirect();
			return;
		}

		// Delete redirect.
		if ( isset( $_POST['plseo_delete_redirect'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_delete_redirect();
			return;
		}

		// Import CSV.
		if ( isset( $_POST['plseo_import_csv'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_import_csv();
			return;
		}

		// Delete 404 log entry.
		if ( isset( $_POST['plseo_delete_404'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_delete_404();
			return;
		}

		// Create redirect from 404.
		if ( isset( $_POST['plseo_redirect_from_404'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$this->handle_redirect_from_404();
			return;
		}
	}

	private function handle_add_redirect(): void {
		global $wpdb;

		$source = $this->sanitize_path( $_POST['source_url'] ?? '' );
		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		$code   = in_array( (int) ( $_POST['status_code'] ?? 301 ), array( 301, 302 ), true )
			? (int) $_POST['status_code']
			: 301;
		$notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		if ( empty( $source ) || empty( $target ) ) {
			$this->admin_redirect( 'error', __( 'Source and target URLs are required.', 'perrylabs-seo' ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_REDIRECTS,
			array(
				'source_url'  => $source,
				'target_url'  => $target,
				'status_code' => $code,
				'notes'       => $notes ?: null,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		$this->admin_redirect( 'success', __( 'Redirect added.', 'perrylabs-seo' ) );
	}

	private function handle_edit_redirect(): void {
		global $wpdb;

		$id     = absint( $_POST['redirect_id'] ?? 0 );
		$source = $this->sanitize_path( $_POST['source_url'] ?? '' );
		$target = esc_url_raw( wp_unslash( $_POST['target_url'] ?? '' ) );
		$code   = in_array( (int) ( $_POST['status_code'] ?? 301 ), array( 301, 302 ), true )
			? (int) $_POST['status_code']
			: 301;
		$notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		if ( ! $id || empty( $source ) || empty( $target ) ) {
			$this->admin_redirect( 'error', __( 'Invalid redirect data.', 'perrylabs-seo' ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->prefix . self::TABLE_REDIRECTS,
			array(
				'source_url'  => $source,
				'target_url'  => $target,
				'status_code' => $code,
				'notes'       => $notes ?: null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		$this->admin_redirect( 'success', __( 'Redirect updated.', 'perrylabs-seo' ) );
	}

	private function handle_delete_redirect(): void {
		global $wpdb;

		$id = absint( $_POST['redirect_id'] ?? 0 );
		if ( ! $id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . self::TABLE_REDIRECTS,
			array( 'id' => $id ),
			array( '%d' )
		);

		$this->admin_redirect( 'success', __( 'Redirect deleted.', 'perrylabs-seo' ) );
	}

	private function handle_delete_404(): void {
		global $wpdb;

		$id = absint( $_POST['log_id'] ?? 0 );
		if ( ! $id ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . self::TABLE_404_LOG,
			array( 'id' => $id ),
			array( '%d' )
		);

		$this->admin_redirect( 'success', __( '404 log entry deleted.', 'perrylabs-seo' ), 'log' );
	}

	private function handle_redirect_from_404(): void {
		global $wpdb;

		$log_id = absint( $_POST['log_id'] ?? 0 );
		if ( ! $log_id ) {
			return;
		}

		$log_table = $wpdb->prefix . self::TABLE_404_LOG;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entry = $wpdb->get_row(
			$wpdb->prepare( "SELECT url FROM {$log_table} WHERE id = %d", $log_id )
		);

		if ( ! $entry ) {
			$this->admin_redirect( 'error', __( '404 entry not found.', 'perrylabs-seo' ), 'log' );
			return;
		}

		// Redirect to the add form with the source pre-filled.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'prefill_source' => rawurlencode( $entry->url ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	private function handle_import_csv(): void {
		global $wpdb;

		if ( empty( $_FILES['csv_file']['tmp_name'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
			$this->admin_redirect( 'error', __( 'No file uploaded or upload error.', 'perrylabs-seo' ) );
			return;
		}

		$file = fopen( $_FILES['csv_file']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $file ) {
			$this->admin_redirect( 'error', __( 'Could not read the uploaded file.', 'perrylabs-seo' ) );
			return;
		}

		$table   = $wpdb->prefix . self::TABLE_REDIRECTS;
		$now     = current_time( 'mysql' );
		$count   = 0;
		$row_num = 0;

		while ( ( $row = fgetcsv( $file ) ) !== false ) {
			$row_num++;

			// Skip header row.
			if ( $row_num === 1 && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'source_url' ) {
				continue;
			}

			$source = $this->sanitize_path( $row[0] ?? '' );
			$target = esc_url_raw( trim( $row[1] ?? '' ) );
			$code   = isset( $row[2] ) && in_array( (int) $row[2], array( 301, 302 ), true )
				? (int) $row[2]
				: 301;
			$notes  = sanitize_textarea_field( trim( $row[3] ?? '' ) );

			if ( empty( $source ) || empty( $target ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'source_url'  => $source,
					'target_url'  => $target,
					'status_code' => $code,
					'notes'       => $notes ?: null,
					'created_at'  => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s' )
			);

			$count++;
		}

		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->admin_redirect(
			'success',
			/* translators: %d: number of redirects imported */
			sprintf( __( '%d redirects imported.', 'perrylabs-seo' ), $count )
		);
	}

	private function export_csv(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_REDIRECTS;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$redirects = $wpdb->get_results(
			"SELECT source_url, target_url, status_code, hits, last_hit, created_at, notes FROM {$table} ORDER BY id ASC",
			ARRAY_A
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=plseo-redirects-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $output, array( 'source_url', 'target_url', 'status_code', 'hits', 'last_hit', 'created_at', 'notes' ) );

		foreach ( $redirects as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Admin Page Rendering
	 * ────────────────────────────────────────────────────────────── */

	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'log' ? 'log' : 'redirects';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Redirects — PerryLabs SEO', 'perrylabs-seo' ); ?></h1>

			<?php $this->render_notices(); ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'redirects', $this->admin_page_url() ) ); ?>"
				   class="nav-tab <?php echo $active_tab === 'redirects' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Redirects', 'perrylabs-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'log', $this->admin_page_url() ) ); ?>"
				   class="nav-tab <?php echo $active_tab === 'log' ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( '404 Log', 'perrylabs-seo' ); ?>
				</a>
			</h2>

			<?php
			if ( $active_tab === 'log' ) {
				$this->render_404_log_tab();
			} else {
				$this->render_redirects_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_notices(): void {
		if ( ! isset( $_GET['plseo_msg'] ) ) {
			return;
		}

		$type    = isset( $_GET['plseo_status'] ) && $_GET['plseo_status'] === 'error' ? 'error' : 'success';
		$message = sanitize_text_field( wp_unslash( $_GET['plseo_msg'] ) );
		$class   = $type === 'error' ? 'notice-error' : 'notice-success';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private function render_redirects_tab(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_REDIRECTS;

		// Check if we are editing.
		$editing    = null;
		$editing_id = absint( $_GET['edit'] ?? 0 );
		if ( $editing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$editing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $editing_id )
			);
		}

		// Pre-fill source from 404 log.
		$prefill_source = isset( $_GET['prefill_source'] ) ? sanitize_text_field( rawurldecode( $_GET['prefill_source'] ) ) : '';

		// Pagination.
		$per_page     = 50;
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset       = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$redirects = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
		?>

		<!-- Add / Edit Form -->
		<h3><?php echo $editing ? esc_html__( 'Edit Redirect', 'perrylabs-seo' ) : esc_html__( 'Add Redirect', 'perrylabs-seo' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $this->admin_page_url() ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>

			<?php if ( $editing ) : ?>
				<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $editing->id ); ?>" />
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="source_url"><?php esc_html_e( 'Source URL', 'perrylabs-seo' ); ?></label>
					</th>
					<td>
						<input type="text" name="source_url" id="source_url" class="regular-text"
						       value="<?php echo esc_attr( $editing ? $editing->source_url : $prefill_source ); ?>"
						       placeholder="/old-page/" required />
						<p class="description"><?php esc_html_e( 'The old URL path, e.g. /old-page/', 'perrylabs-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="target_url"><?php esc_html_e( 'Target URL', 'perrylabs-seo' ); ?></label>
					</th>
					<td>
						<input type="text" name="target_url" id="target_url" class="regular-text"
						       value="<?php echo esc_attr( $editing ? $editing->target_url : '' ); ?>"
						       placeholder="https://example.com/new-page/" required />
						<p class="description"><?php esc_html_e( 'Where to redirect to. Can be a full URL or a relative path.', 'perrylabs-seo' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="status_code"><?php esc_html_e( 'Status Code', 'perrylabs-seo' ); ?></label>
					</th>
					<td>
						<select name="status_code" id="status_code">
							<option value="301" <?php selected( $editing ? $editing->status_code : 301, 301 ); ?>>
								301 — <?php esc_html_e( 'Permanent', 'perrylabs-seo' ); ?>
							</option>
							<option value="302" <?php selected( $editing ? $editing->status_code : 301, 302 ); ?>>
								302 — <?php esc_html_e( 'Temporary', 'perrylabs-seo' ); ?>
							</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="notes"><?php esc_html_e( 'Notes', 'perrylabs-seo' ); ?></label>
					</th>
					<td>
						<textarea name="notes" id="notes" rows="2" class="large-text"><?php
							echo esc_textarea( $editing ? $editing->notes : '' );
						?></textarea>
					</td>
				</tr>
			</table>

			<?php if ( $editing ) : ?>
				<?php submit_button( __( 'Update Redirect', 'perrylabs-seo' ), 'primary', 'plseo_edit_redirect' ); ?>
				<a href="<?php echo esc_url( $this->admin_page_url() ); ?>"><?php esc_html_e( 'Cancel', 'perrylabs-seo' ); ?></a>
			<?php else : ?>
				<?php submit_button( __( 'Add Redirect', 'perrylabs-seo' ), 'primary', 'plseo_add_redirect' ); ?>
			<?php endif; ?>
		</form>

		<hr />

		<!-- Import / Export -->
		<h3><?php esc_html_e( 'Import / Export', 'perrylabs-seo' ); ?></h3>
		<div style="display: flex; gap: 20px; align-items: flex-start; margin-bottom: 20px;">
			<form method="post" action="<?php echo esc_url( $this->admin_page_url() ); ?>" enctype="multipart/form-data" style="display: flex; gap: 8px; align-items: center;">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="file" name="csv_file" accept=".csv" required />
				<button type="submit" name="plseo_import_csv" class="button"><?php esc_html_e( 'Import CSV', 'perrylabs-seo' ); ?></button>
			</form>

			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'plseo_action', 'export_csv', $this->admin_page_url() ), 'plseo_export_csv' ) ); ?>"
			   class="button"><?php esc_html_e( 'Export CSV', 'perrylabs-seo' ); ?></a>
		</div>
		<p class="description"><?php esc_html_e( 'CSV format: source_url, target_url, status_code, notes. Header row is optional.', 'perrylabs-seo' ); ?></p>

		<hr />

		<!-- Redirects Table -->
		<h3><?php esc_html_e( 'Existing Redirects', 'perrylabs-seo' ); ?></h3>

		<?php if ( empty( $redirects ) ) : ?>
			<p><?php esc_html_e( 'No redirects found.', 'perrylabs-seo' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Source URL', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Target URL', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Code', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Last Hit', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'perrylabs-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $redirects as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->source_url ); ?></code></td>
							<td><?php echo esc_html( $r->target_url ); ?></td>
							<td><?php echo esc_html( $r->status_code ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $r->hits ) ); ?></td>
							<td><?php echo $r->last_hit ? esc_html( $r->last_hit ) : '&mdash;'; ?></td>
							<td><?php echo $r->notes ? esc_html( wp_trim_words( $r->notes, 10 ) ) : '&mdash;'; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'edit', $r->id, $this->admin_page_url() ) ); ?>"
								   class="button button-small"><?php esc_html_e( 'Edit', 'perrylabs-seo' ); ?></a>
								<form method="post" action="<?php echo esc_url( $this->admin_page_url() ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $r->id ); ?>" />
									<button type="submit" name="plseo_delete_redirect" class="button button-small button-link-delete"
									        onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'perrylabs-seo' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'perrylabs-seo' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Pagination.
			$total_pages = ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links( array(
						'base'    => add_query_arg( 'paged', '%#%', $this->admin_page_url() ),
						'format'  => '',
						'current' => $current_page,
						'total'   => $total_pages,
					) )
				);
				echo '</div></div>';
			}
			?>
		<?php endif; ?>
		<?php
	}

	private function render_404_log_tab(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_404_LOG;

		// Pagination.
		$per_page     = 50;
		$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset       = ( $current_page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY hits DESC, last_hit DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
		?>
		<h3><?php esc_html_e( '404 Log', 'perrylabs-seo' ); ?></h3>
		<p class="description"><?php esc_html_e( 'URLs that returned a 404 response. Sorted by most hits.', 'perrylabs-seo' ); ?></p>

		<?php if ( empty( $entries ) ) : ?>
			<p><?php esc_html_e( 'No 404 errors logged yet.', 'perrylabs-seo' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'URL', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Hits', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Last Hit', 'perrylabs-seo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'perrylabs-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( $entry->url ); ?></code></td>
							<td><?php echo $entry->referrer ? esc_html( $entry->referrer ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( number_format_i18n( $entry->hits ) ); ?></td>
							<td><?php echo esc_html( $entry->last_hit ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'redirects', $this->admin_page_url() ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="log_id" value="<?php echo esc_attr( $entry->id ); ?>" />
									<button type="submit" name="plseo_redirect_from_404" class="button button-small">
										<?php esc_html_e( 'Create Redirect', 'perrylabs-seo' ); ?>
									</button>
								</form>
								<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'log', $this->admin_page_url() ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="log_id" value="<?php echo esc_attr( $entry->id ); ?>" />
									<button type="submit" name="plseo_delete_404" class="button button-small button-link-delete"
									        onclick="return confirm('<?php echo esc_js( __( 'Delete this log entry?', 'perrylabs-seo' ) ); ?>');">
										<?php esc_html_e( 'Delete', 'perrylabs-seo' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links( array(
						'base'    => add_query_arg( array( 'tab' => 'log', 'paged' => '%#%' ), $this->admin_page_url() ),
						'format'  => '',
						'current' => $current_page,
						'total'   => $total_pages,
					) )
				);
				echo '</div></div>';
			}
			?>
		<?php endif; ?>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Sanitize a URL path for use as a redirect source.
	 */
	private function sanitize_path( string $raw ): string {
		$path = wp_unslash( $raw );
		$path = sanitize_text_field( $path );

		// Strip scheme and host if a full URL was entered.
		$parsed = wp_parse_url( $path );
		if ( isset( $parsed['host'] ) ) {
			$path = $parsed['path'] ?? '/';
		}

		// Ensure leading slash.
		$path = '/' . ltrim( $path, '/' );

		// Ensure trailing slash (except root).
		if ( $path !== '/' ) {
			$path = rtrim( $path, '/' ) . '/';
		}

		return $path;
	}

	/**
	 * Get the base admin page URL.
	 */
	private function admin_page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Redirect back to the admin page with a status message.
	 */
	private function admin_redirect( string $status, string $message, string $tab = 'redirects' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'tab'         => $tab,
					'plseo_status' => $status,
					'plseo_msg'   => rawurlencode( $message ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
