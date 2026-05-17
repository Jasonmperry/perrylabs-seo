<?php
/**
 * PLSEO_Admin — top-level admin menu, settings registration, asset enqueueing.
 *
 * This file owns three things and nothing more:
 *   1. The wp-admin menu structure.
 *   2. Registering settings + asset enqueues.
 *   3. Rendering the chrome (tabs + form) around tab content from PLSEO_Tabs.
 *
 * Custom admin screens live in their own files:
 *   - PLSEO_Redirects_Screen
 *   - PLSEO_Log404_Screen
 *   - PLSEO_AEO_Dashboard_Screen
 *   - PLSEO_Audit_Screen
 *   - PLSEO_Bulk_Editor
 *
 * admin-post handlers live in PLSEO_Admin_Actions.
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
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		PLSEO_Admin_Actions::boot();
		PLSEO_Bulk_Alt_Editor::boot();
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

		add_submenu_page( self::MENU_SLUG, __( 'Settings', 'perrylabs-seo' ),       __( 'Settings', 'perrylabs-seo' ),       'manage_options', self::MENU_SLUG,           array( $this, 'render_main' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Redirects', 'perrylabs-seo' ),      __( 'Redirects', 'perrylabs-seo' ),      'manage_options', 'plseo-redirects',          array( PLSEO_Redirects_Screen::class,     'render' ) );
		add_submenu_page( self::MENU_SLUG, __( '404 log', 'perrylabs-seo' ),        __( '404 log', 'perrylabs-seo' ),        'manage_options', 'plseo-404-log',            array( PLSEO_Log404_Screen::class,        'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Bulk SEO editor', 'perrylabs-seo' ),__( 'Bulk editor', 'perrylabs-seo' ),    'edit_posts',     'plseo-bulk',               array( PLSEO_Bulk_Editor::instance(),     'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Bulk image alt', 'perrylabs-seo' ), __( 'Bulk image alt', 'perrylabs-seo' ), 'upload_files',   'plseo-bulk-alt',           array( PLSEO_Bulk_Alt_Editor::class,      'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'AEO dashboard', 'perrylabs-seo' ),  __( 'AEO dashboard', 'perrylabs-seo' ),  'manage_options', 'plseo-aeo-dashboard',      array( PLSEO_AEO_Dashboard_Screen::class, 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Site audit', 'perrylabs-seo' ),     __( 'Site audit', 'perrylabs-seo' ),     'manage_options', 'plseo-audit',              array( PLSEO_Audit_Screen::class,         'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Search log', 'perrylabs-seo' ),     __( 'Search log', 'perrylabs-seo' ),     'manage_options', 'plseo-search-log',         array( PLSEO_Search_Log_Screen::class,    'render' ) );
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
		$is_plugin_screen = (bool) preg_match( '/page_plseo/', $hook );
		$is_editor        = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

		if ( $is_editor ) {
			wp_enqueue_style(  'plseo-meta-box', PL_SEO_PLUGIN_URL . 'assets/meta-box.css', array(),             PL_SEO_VERSION );
			wp_enqueue_script( 'plseo-meta-box', PL_SEO_PLUGIN_URL . 'assets/meta-box.js',  array( 'jquery' ),   PL_SEO_VERSION, true );
			wp_enqueue_media();
			return;
		}

		if ( ! $is_plugin_screen ) {
			return;
		}

		PerryLabs_Branding::enqueue_tokens(
			PL_SEO_PLUGIN_URL . 'includes/branding/tokens.css',
			PL_SEO_VERSION
		);

		wp_enqueue_style(  'plseo-admin', PL_SEO_PLUGIN_URL . 'assets/admin.css', array( 'perrylabs-tokens' ), PL_SEO_VERSION );
		wp_enqueue_script( 'plseo-admin', PL_SEO_PLUGIN_URL . 'assets/admin.js',  array( 'jquery' ),           PL_SEO_VERSION, true );
		wp_enqueue_media();
	}

	/**
	 * Render the standard PerryLabs branded header for any SEO admin page.
	 *
	 * @param string $title Page heading (the per-screen subtitle).
	 */
	public static function page_header( string $title ): void {
		PerryLabs_Branding::header( $title, PL_SEO_VERSION );
	}

	/**
	 * Render the standard PerryLabs branded footer.
	 */
	public static function page_footer(): void {
		PerryLabs_Branding::footer();
	}

	/* ───────────────────────── settings page ───────────────────────── */

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
			<?php self::page_header( __( 'SEO + AEO', 'perrylabs-seo' ) ); ?>
			<p class="plseo-codename"><?php echo esc_html( PL_SEO_CODENAME ); ?></p>
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
				if ( 'tools' !== $current ) {
					submit_button();
				}
				?>
			</form>

			<?php if ( 'tools' === $current ) { $this->render_tools_actions(); } ?>

			<?php self::page_footer(); ?>
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
}
