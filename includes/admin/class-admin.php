<?php
/**
 * PLSEO_Admin — top-level admin menu, settings registration, screen routing.
 *
 * Renders one settings page with internal tab navigation. Every tab is
 * registered by PLSEO_Tabs, which is the only place that knows about each
 * tab's sections and fields. This file owns menu mounting, asset enqueueing,
 * and the form chrome.
 *
 * Custom screens (Redirects manager, 404 log, Bulk Editor, AEO dashboard)
 * are also mounted here as sub-menu items.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Admin {

	private static ?self $instance = null;

	public const MENU_SLUG = 'plseo';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_menu',           array( $this, 'register_menu' ) );
		add_action( 'admin_init',           array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Handle custom POST actions (redirects CRUD, CSV import, exports).
		add_action( 'admin_post_plseo_redirect_create', array( $this, 'handle_redirect_create' ) );
		add_action( 'admin_post_plseo_redirect_delete', array( $this, 'handle_redirect_delete' ) );
		add_action( 'admin_post_plseo_redirect_import', array( $this, 'handle_redirect_import' ) );
		add_action( 'admin_post_plseo_redirect_export', array( $this, 'handle_redirect_export' ) );
		add_action( 'admin_post_plseo_404_resolve',     array( $this, 'handle_404_resolve' ) );
		add_action( 'admin_post_plseo_404_delete',      array( $this, 'handle_404_delete' ) );
		add_action( 'admin_post_plseo_404_promote',     array( $this, 'handle_404_promote' ) );
		add_action( 'admin_post_plseo_settings_export', array( $this, 'handle_settings_export' ) );
		add_action( 'admin_post_plseo_settings_import', array( $this, 'handle_settings_import' ) );
	}

	/* ───────────────────────── menu ───────────────────────── */

	public function register_menu(): void {
		add_menu_page(
			__( 'SEO + AEO', 'perrylabs-seo' ),
			__( 'SEO + AEO', 'perrylabs-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_main' ),
			'dashicons-chart-line',
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'perrylabs-seo' ),
			__( 'Settings', 'perrylabs-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_main' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Redirects', 'perrylabs-seo' ),
			__( 'Redirects', 'perrylabs-seo' ),
			'manage_options',
			'plseo-redirects',
			array( $this, 'render_redirects' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( '404 log', 'perrylabs-seo' ),
			__( '404 log', 'perrylabs-seo' ),
			'manage_options',
			'plseo-404-log',
			array( $this, 'render_404_log' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bulk SEO editor', 'perrylabs-seo' ),
			__( 'Bulk editor', 'perrylabs-seo' ),
			'edit_posts',
			'plseo-bulk',
			array( PLSEO_Bulk_Editor::instance(), 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'AEO dashboard', 'perrylabs-seo' ),
			__( 'AEO dashboard', 'perrylabs-seo' ),
			'manage_options',
			'plseo-aeo-dashboard',
			array( $this, 'render_aeo_dashboard' )
		);
	}

	public function register_settings(): void {
		register_setting(
			PLSEO_Options::OPTION_GROUP,
			PLSEO_Options::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( PLSEO_Options::class, 'sanitize' ),
				'default'           => PLSEO_Options::defaults(),
			)
		);

		PLSEO_Tabs::register_all();
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_starts_with( $hook, 'toplevel_page_plseo' ) && ! str_starts_with( $hook, 'seo-aeo_page_plseo-' ) && ! str_contains( $hook, 'page_plseo' ) ) {
			// Still load the meta-box assets on post editor screens.
			if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
				wp_enqueue_style( 'plseo-meta-box', PL_SEO_PLUGIN_URL . 'assets/meta-box.css', array(), PL_SEO_VERSION );
				wp_enqueue_script( 'plseo-meta-box', PL_SEO_PLUGIN_URL . 'assets/meta-box.js', array( 'jquery' ), PL_SEO_VERSION, true );
				wp_enqueue_media();
			}
			return;
		}

		wp_enqueue_style( 'plseo-admin', PL_SEO_PLUGIN_URL . 'assets/admin.css', array(), PL_SEO_VERSION );
		wp_enqueue_script( 'plseo-admin', PL_SEO_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), PL_SEO_VERSION, true );
		wp_enqueue_media();
	}

	/* ───────────────────────── settings page chrome ───────────────────────── */

	public function render_main(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$tabs    = PLSEO_Tabs::tabs();
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'general';
		}
		?>
		<div class="wrap plseo-wrap">
			<h1><?php esc_html_e( 'PerryLabs SEO + AEO', 'perrylabs-seo' ); ?>
				<span class="plseo-codename"><?php echo esc_html( PL_SEO_CODENAME ); ?> · v<?php echo esc_html( PL_SEO_VERSION ); ?></span>
			</h1>
			<nav class="nav-tab-wrapper plseo-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"
					   href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="options.php" class="plseo-form plseo-form--<?php echo esc_attr( $current ); ?>">
				<?php
				settings_fields( PLSEO_Options::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG . '-' . $current );
				if ( ! in_array( $current, array( 'tools' ), true ) ) {
					submit_button();
				}
				?>
			</form>

			<?php if ( 'tools' === $current ) : ?>
				<?php $this->render_tools_actions(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_tools_actions(): void {
		?>
		<div class="plseo-tool-cards">
			<div class="plseo-card">
				<h3><?php esc_html_e( 'Settings export', 'perrylabs-seo' ); ?></h3>
				<p><?php esc_html_e( 'Download a JSON file containing all plugin settings. Useful for moving config between sites.', 'perrylabs-seo' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'plseo_settings_export' ); ?>
					<input type="hidden" name="action" value="plseo_settings_export" />
					<button class="button button-primary"><?php esc_html_e( 'Download settings JSON', 'perrylabs-seo' ); ?></button>
				</form>
			</div>

			<div class="plseo-card">
				<h3><?php esc_html_e( 'Settings import', 'perrylabs-seo' ); ?></h3>
				<p><?php esc_html_e( 'Upload a previously-exported JSON file. Existing settings will be replaced.', 'perrylabs-seo' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'plseo_settings_import' ); ?>
					<input type="hidden" name="action" value="plseo_settings_import" />
					<input type="file" name="plseo_import_file" accept=".json" required />
					<button class="button"><?php esc_html_e( 'Import', 'perrylabs-seo' ); ?></button>
				</form>
			</div>

			<div class="plseo-card">
				<h3><?php esc_html_e( 'IndexNow key file', 'perrylabs-seo' ); ?></h3>
				<?php $key = PLSEO_IndexNow::instance()->key(); ?>
				<p><?php esc_html_e( 'Your IndexNow key:', 'perrylabs-seo' ); ?> <code><?php echo esc_html( $key ); ?></code></p>
				<p><?php esc_html_e( 'Verification URL:', 'perrylabs-seo' ); ?>
					<a href="<?php echo esc_url( home_url( '/' . $key . '.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/' . $key . '.txt' ) ); ?></a>
				</p>
			</div>

			<div class="plseo-card">
				<h3><?php esc_html_e( 'Public endpoints', 'perrylabs-seo' ); ?></h3>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/robots.txt' ) ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/* ───────────────────────── redirects screen ───────────────────────── */

	public function render_redirects(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$mgr  = PLSEO_Redirects::instance();
		$rows = $mgr->all( 200 );
		?>
		<div class="wrap plseo-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Redirects', 'perrylabs-seo' ); ?></h1>
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
		</div>
		<?php
	}

	/* ───────────────────────── 404 log screen ───────────────────────── */

	public function render_404_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$rows = PLSEO_404_Log::instance()->recent( 100 );
		?>
		<div class="wrap plseo-wrap">
			<h1><?php esc_html_e( '404 log', 'perrylabs-seo' ); ?></h1>
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
		</div>
		<?php
	}

	/* ───────────────────────── AEO dashboard ───────────────────────── */

	public function render_aeo_dashboard(): void {
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

	/* ───────────────────────── admin-post handlers ───────────────────────── */

	public function handle_redirect_create(): void {
		check_admin_referer( 'plseo_redirect_create' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$id = PLSEO_Redirects::instance()->create( array(
			'source_url'  => (string) ( $_POST['source_url'] ?? '' ),
			'target_url'  => (string) ( $_POST['target_url'] ?? '' ),
			'status_code' => (int) ( $_POST['status_code'] ?? 301 ),
			'match_type'  => (string) ( $_POST['match_type'] ?? 'exact' ),
			'notes'       => (string) ( $_POST['notes'] ?? '' ),
		) );
		$this->redirect_back( 'plseo-redirects', $id ? 'created' : 'failed' );
	}

	public function handle_redirect_delete(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_redirect_delete_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		PLSEO_Redirects::instance()->delete( $id );
		$this->redirect_back( 'plseo-redirects', 'deleted' );
	}

	public function handle_redirect_import(): void {
		check_admin_referer( 'plseo_redirect_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		if ( empty( $_FILES['plseo_csv']['tmp_name'] ) ) {
			$this->redirect_back( 'plseo-redirects', 'no-file' );
		}
		$result = PLSEO_Redirects_CSV::import_from_path( (string) $_FILES['plseo_csv']['tmp_name'] );
		$this->redirect_back( 'plseo-redirects', 'imported', array(
			'added'   => (int) $result['added'],
			'skipped' => (int) $result['skipped'],
		) );
	}

	public function handle_redirect_export(): void {
		check_admin_referer( 'plseo_redirect_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		PLSEO_Redirects_CSV::stream_export();
	}

	public function handle_404_resolve(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_resolve_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		PLSEO_404_Log::instance()->mark_resolved( $id );
		$this->redirect_back( 'plseo-404-log', 'resolved' );
	}

	public function handle_404_delete(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_delete_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		PLSEO_404_Log::instance()->delete( $id );
		$this->redirect_back( 'plseo-404-log', 'deleted' );
	}

	public function handle_404_promote(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'plseo_404_promote_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$created = PLSEO_Redirects::instance()->create( array(
			'source_url'  => (string) ( $_POST['source_url'] ?? '' ),
			'target_url'  => (string) ( $_POST['target_url'] ?? '' ),
			'status_code' => 301,
			'match_type'  => 'exact',
			'notes'       => __( 'Promoted from 404 log', 'perrylabs-seo' ),
		) );
		if ( $created ) {
			PLSEO_404_Log::instance()->mark_resolved( $id );
		}
		$this->redirect_back( 'plseo-404-log', $created ? 'promoted' : 'failed' );
	}

	public function handle_settings_export(): void {
		check_admin_referer( 'plseo_settings_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plseo-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( array(
			'plugin'  => 'perrylabs-seo',
			'version' => PL_SEO_VERSION,
			'exported_at' => gmdate( 'c' ),
			'options' => PLSEO_Options::all(),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function handle_settings_import(): void {
		check_admin_referer( 'plseo_settings_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		if ( empty( $_FILES['plseo_import_file']['tmp_name'] ) ) {
			$this->redirect_back( self::MENU_SLUG, 'no-file', array( 'tab' => 'tools' ) );
		}
		$json = file_get_contents( (string) $_FILES['plseo_import_file']['tmp_name'] );
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
			$this->redirect_back( self::MENU_SLUG, 'bad-file', array( 'tab' => 'tools' ) );
		}
		PLSEO_Options::update( $data['options'] );
		$this->redirect_back( self::MENU_SLUG, 'imported', array( 'tab' => 'tools' ) );
	}

	private function redirect_back( string $page, string $status, array $extra = array() ): void {
		$url = add_query_arg( array_merge( array( 'page' => $page, 'plseo_status' => $status ), $extra ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
