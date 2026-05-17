<?php
/**
 * PLSEO_Setup_Wizard — guided first-activation walkthrough.
 *
 * Shown once after activation (when the option `plseo_setup_completed` is
 * falsy) via an admin-notice with a CTA, and accessible at any time via
 * `wp-admin/admin.php?page=plseo-setup`. Two-step flow:
 *
 *   1. Business — name, type, logo, social URLs.
 *   2. AEO posture — pick a profile for the AI crawler matrix
 *      (Allow AI / Block training only / Block all).
 *
 * Existing values are preserved — the wizard merges into plseo_options
 * rather than overwriting. Skip is always available.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Setup_Wizard {

	private static ?self $instance = null;

	private const OPTION_DONE = 'plseo_setup_completed';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_menu',                    array( $this, 'register_menu' ), 99 );
		add_action( 'admin_notices',                 array( $this, 'maybe_show_nudge' ) );
		add_action( 'admin_post_plseo_setup_save',   array( $this, 'handle_save' ) );
		add_action( 'admin_post_plseo_setup_skip',   array( $this, 'handle_skip' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			null, // Hidden — accessible by direct URL only.
			__( 'PerryLabs SEO setup', 'perrylabs-seo' ),
			__( 'Setup', 'perrylabs-seo' ),
			'manage_options',
			'plseo-setup',
			array( $this, 'render' )
		);
	}

	public function maybe_show_nudge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPTION_DONE ) ) {
			return;
		}
		$current = $_GET['page'] ?? '';
		if ( 'plseo-setup' === $current ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s" class="button button-primary" style="margin-left:8px">%s</a> <a href="%s">%s</a></p></div>',
			esc_html__( 'PerryLabs SEO + AEO', 'perrylabs-seo' ),
			esc_html__( 'Take 60 seconds to set the basics — business info, social links, AI crawler posture — so the schema graph and llms.txt land complete.', 'perrylabs-seo' ),
			esc_url( admin_url( 'admin.php?page=plseo-setup' ) ),
			esc_html__( 'Start setup', 'perrylabs-seo' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plseo_setup_skip' ), 'plseo_setup_skip' ) ),
			esc_html__( 'skip', 'perrylabs-seo' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$step       = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : 'business';
		$next_step  = 'business' === $step ? 'aeo' : 'done';
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'PerryLabs SEO setup', 'perrylabs-seo' ) ); ?>

			<div class="plseo-wizard-steps">
				<span class="plseo-wizard-step <?php echo 'business' === $step ? 'is-active' : 'is-done'; ?>">1. Business</span>
				<span class="plseo-wizard-step <?php echo 'aeo' === $step ? 'is-active' : ''; ?>">2. AEO posture</span>
				<span class="plseo-wizard-step">3. Done</span>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="plseo-wizard-form">
				<?php wp_nonce_field( 'plseo_setup_save' ); ?>
				<input type="hidden" name="action" value="plseo_setup_save" />
				<input type="hidden" name="step"   value="<?php echo esc_attr( $step ); ?>" />
				<input type="hidden" name="next"   value="<?php echo esc_attr( $next_step ); ?>" />

				<?php if ( 'business' === $step ) : self::render_step_business(); ?>
				<?php elseif ( 'aeo' === $step ) : self::render_step_aeo(); ?>
				<?php else : self::render_step_done(); ?>
				<?php endif; ?>

				<?php if ( 'done' !== $step ) : ?>
					<p class="submit">
						<button class="button button-primary"><?php
							echo 'aeo' === $step
								? esc_html__( 'Finish setup', 'perrylabs-seo' )
								: esc_html__( 'Continue →', 'perrylabs-seo' );
						?></button>
						<a class="button-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plseo_setup_skip' ), 'plseo_setup_skip' ) ); ?>"><?php esc_html_e( 'Skip setup — I\'ll configure manually', 'perrylabs-seo' ); ?></a>
					</p>
				<?php endif; ?>
			</form>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<?php
	}

	private static function render_step_business(): void {
		?>
		<h2><?php esc_html_e( 'Tell us about the business', 'perrylabs-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These flow into the Organization JSON-LD node every page references. You can edit any of this later on the Schema tab.', 'perrylabs-seo' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="business_name"><?php esc_html_e( 'Name', 'perrylabs-seo' ); ?></label></th>
				<td><input type="text" id="business_name" name="business_name" value="<?php echo esc_attr( (string) PLSEO_Options::get( 'business_name', '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="business_type"><?php esc_html_e( 'Type', 'perrylabs-seo' ); ?></label></th>
				<td>
					<select id="business_type" name="business_type">
						<?php
						$type_options = array(
							'Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService',
							'NewsMediaOrganization', 'EducationalOrganization', 'NGO', 'Person',
						);
						$current      = (string) PLSEO_Options::get( 'business_type', 'Organization' );
						foreach ( $type_options as $t ) {
							printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $t ), selected( $current, $t, false ) );
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="business_logo"><?php esc_html_e( 'Logo URL', 'perrylabs-seo' ); ?></label></th>
				<td>
					<input type="url" id="business_logo" name="business_logo" value="<?php echo esc_attr( (string) PLSEO_Options::get( 'business_logo', '' ) ); ?>" class="regular-text code" placeholder="https://example.com/logo.png" />
					<p class="description"><?php esc_html_e( 'Required for Google rich-result eligibility. Square 1:1 ratio preferred.', 'perrylabs-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="twitter_handle"><?php esc_html_e( 'Twitter / X handle', 'perrylabs-seo' ); ?></label></th>
				<td><input type="text" id="twitter_handle" name="twitter_handle" value="<?php echo esc_attr( (string) PLSEO_Options::get( 'twitter_handle', '' ) ); ?>" placeholder="@yourhandle" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="linkedin_url"><?php esc_html_e( 'LinkedIn URL', 'perrylabs-seo' ); ?></label></th>
				<td><input type="url" id="linkedin_url" name="linkedin_url" value="<?php echo esc_attr( (string) PLSEO_Options::get( 'linkedin_url', '' ) ); ?>" class="regular-text code" /></td>
			</tr>
		</table>
		<?php
	}

	private static function render_step_aeo(): void {
		$current_policy = (array) PLSEO_Options::get( 'ai_crawlers', array() );
		$default_choice = self::current_ai_profile( $current_policy );
		?>
		<h2><?php esc_html_e( 'AI crawler posture', 'perrylabs-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Decide how AI crawlers should treat your site. You can fine-tune individual bots later on the AEO tab.', 'perrylabs-seo' ); ?></p>

		<fieldset class="plseo-wizard-radio-cards">
			<label class="plseo-wizard-card">
				<input type="radio" name="ai_profile" value="allow" <?php checked( $default_choice, 'allow' ); ?> />
				<div>
					<strong><?php esc_html_e( 'Allow everything (max AI visibility)', 'perrylabs-seo' ); ?></strong>
					<p><?php esc_html_e( 'All recognized AI crawlers are permitted, including training scrapers. Best for content you want maximally discoverable across ChatGPT, Claude, Perplexity, and future AI search.', 'perrylabs-seo' ); ?></p>
				</div>
			</label>

			<label class="plseo-wizard-card">
				<input type="radio" name="ai_profile" value="block_training" <?php checked( $default_choice, 'block_training' ); ?> />
				<div>
					<strong><?php esc_html_e( 'Block training, allow citation (recommended)', 'perrylabs-seo' ); ?></strong>
					<p><?php esc_html_e( 'Training-only bots (GPTBot, ClaudeBot, CCBot, Google-Extended, etc.) blocked. On-demand and search bots (ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-SearchBot) allowed so you still appear in AI answers. Best for most sites.', 'perrylabs-seo' ); ?></p>
				</div>
			</label>

			<label class="plseo-wizard-card">
				<input type="radio" name="ai_profile" value="block" <?php checked( $default_choice, 'block' ); ?> />
				<div>
					<strong><?php esc_html_e( 'Block all AI crawlers', 'perrylabs-seo' ); ?></strong>
					<p><?php esc_html_e( 'Every recognized AI crawler is disallowed via robots.txt. You will not appear in AI answers. Best for sensitive content or sites that have a separate licensing arrangement.', 'perrylabs-seo' ); ?></p>
				</div>
			</label>
		</fieldset>

		<h3 style="margin-top:24px"><?php esc_html_e( 'AEO features', 'perrylabs-seo' ); ?></h3>
		<p>
			<label><input type="checkbox" name="llms_txt_enabled" value="1" <?php checked( (bool) PLSEO_Options::get( 'llms_txt_enabled', true ) ); ?> /> <?php esc_html_e( 'Serve /llms.txt — markdown summary for AI assistants', 'perrylabs-seo' ); ?></label><br>
			<label><input type="checkbox" name="aeo_faq_autodetect" value="1" <?php checked( (bool) PLSEO_Options::get( 'aeo_faq_autodetect', true ) ); ?> /> <?php esc_html_e( 'Auto-emit FAQPage schema from question-style headings + core/details blocks', 'perrylabs-seo' ); ?></label><br>
			<label><input type="checkbox" name="aeo_howto_autodetect" value="1" <?php checked( (bool) PLSEO_Options::get( 'aeo_howto_autodetect', true ) ); ?> /> <?php esc_html_e( 'Auto-emit HowTo schema from step-style headings + ordered lists', 'perrylabs-seo' ); ?></label>
		</p>
		<?php
	}

	private static function render_step_done(): void {
		?>
		<h2><?php esc_html_e( 'Setup complete', 'perrylabs-seo' ); ?></h2>
		<p><?php esc_html_e( 'You\'re ready. Some useful next steps:', 'perrylabs-seo' ); ?></p>
		<ul style="margin-left:1em;list-style:disc">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=plseo&tab=schema' ) ); ?>"><?php esc_html_e( 'Add address / contact details to the Schema tab', 'perrylabs-seo' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=plseo&tab=analytics' ) ); ?>"><?php esc_html_e( 'Wire up GA4 / Plausible / Fathom on the Analytics tab', 'perrylabs-seo' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=plseo-audit' ) ); ?>"><?php esc_html_e( 'Run the first site audit', 'perrylabs-seo' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Fill in author E-E-A-T fields on your user profile', 'perrylabs-seo' ); ?></a></li>
		</ul>
		<?php
	}

	public function handle_save(): void {
		check_admin_referer( 'plseo_setup_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		$step = isset( $_POST['step'] ) ? sanitize_key( (string) $_POST['step'] ) : 'business';
		$next = isset( $_POST['next'] ) ? sanitize_key( (string) $_POST['next'] ) : 'done';

		$updates = array();
		if ( 'business' === $step ) {
			$updates = array(
				'business_name'   => sanitize_text_field( (string) ( $_POST['business_name']   ?? '' ) ),
				'business_type'   => sanitize_text_field( (string) ( $_POST['business_type']   ?? 'Organization' ) ),
				'business_logo'   => esc_url_raw(        (string) ( $_POST['business_logo']   ?? '' ) ),
				'twitter_handle'  => sanitize_text_field( (string) ( $_POST['twitter_handle']  ?? '' ) ),
				'linkedin_url'    => esc_url_raw(        (string) ( $_POST['linkedin_url']    ?? '' ) ),
			);
		} elseif ( 'aeo' === $step ) {
			$profile = sanitize_key( (string) ( $_POST['ai_profile'] ?? 'block_training' ) );
			$updates['ai_crawlers']           = self::ai_profile_to_matrix( $profile );
			$updates['llms_txt_enabled']      = ! empty( $_POST['llms_txt_enabled'] );
			$updates['aeo_faq_autodetect']    = ! empty( $_POST['aeo_faq_autodetect'] );
			$updates['aeo_howto_autodetect']  = ! empty( $_POST['aeo_howto_autodetect'] );
		}

		PLSEO_Options::update( $updates );

		if ( 'done' === $next ) {
			update_option( self::OPTION_DONE, time() );
			wp_safe_redirect( admin_url( 'admin.php?page=plseo-setup&step=done' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=plseo-setup&step=' . $next ) );
		}
		exit;
	}

	public function handle_skip(): void {
		check_admin_referer( 'plseo_setup_skip' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'perrylabs-seo' ) );
		}
		update_option( self::OPTION_DONE, time() );
		wp_safe_redirect( admin_url( 'admin.php?page=plseo' ) );
		exit;
	}

	/* ───────────────────────── helpers ───────────────────────── */

	/**
	 * Convert a profile choice to the per-bot policy matrix.
	 *
	 * @return array<string,string>
	 */
	private static function ai_profile_to_matrix( string $profile ): array {
		$rule = match ( $profile ) {
			'allow' => 'allow',
			'block' => 'block',
			default => 'block_training',
		};
		$out = array();
		foreach ( PLSEO_AI_Crawlers::CATALOG as $slug => $_bot ) {
			$out[ $slug ] = $rule;
		}
		return $out;
	}

	/**
	 * Best-guess current profile from an existing matrix (for radio default).
	 *
	 * @param array<string,string> $policy
	 */
	private static function current_ai_profile( array $policy ): string {
		if ( empty( $policy ) ) {
			return 'block_training'; // recommended default
		}
		$values = array_values( $policy );
		if ( count( array_unique( $values ) ) === 1 ) {
			return $values[0];
		}
		return 'block_training';
	}
}
