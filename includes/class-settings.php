<?php
/**
 * SEO + AEO — Settings Page (Tabbed UI)
 *
 * Tabbed settings page under Settings > SEO + AEO.
 * Clean UI, no dashboard widgets, no nag screens, no upsells.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Settings {

	/** @var string Option group name. */
	private const OPTION_GROUP = 'perrylabs_seo_options_group';

	/** @var string Option name in wp_options. */
	private const OPTION_NAME = 'perrylabs_seo_options';

	/** @var string Settings page slug. */
	private const PAGE_SLUG = 'perrylabs-seo';

	/** @var string Plugin version displayed in footer. */
	private const VERSION = '1.2.0';

	/** @var ?PerryLabs_SEO_Redirects */
	private ?PerryLabs_SEO_Redirects $redirects;

	/** @var ?PerryLabs_SEO_Robots_Txt */
	private ?PerryLabs_SEO_Robots_Txt $robots;

	/** @var array Tab definitions: slug => label. */
	private array $tabs = array(
		'general'   => 'General',
		'social'    => 'Social',
		'redirects' => 'Redirects',
		'sitemap'   => 'Sitemap',
		'schema'    => 'Schema',
		'tools'     => 'Tools',
		'advanced'  => 'Advanced',
	);

	/**
	 * @param ?PerryLabs_SEO_Redirects  $redirects Redirects manager instance.
	 * @param ?PerryLabs_SEO_Robots_Txt $robots    Robots.txt manager instance.
	 */
	public function __construct( ?PerryLabs_SEO_Redirects $redirects = null, ?PerryLabs_SEO_Robots_Txt $robots = null ) {
		$this->redirects = $redirects;
		$this->robots    = $robots;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Admin assets
	 * ────────────────────────────────────────────────────────────── */

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Admin CSS.
		wp_enqueue_style(
			'perrylabs-seo-admin',
			PL_SEO_PLUGIN_URL . 'assets/admin.css',
			array(),
			self::VERSION
		);

		// Media uploader for image fields.
		wp_enqueue_media();
	}

	/* ──────────────────────────────────────────────────────────────
	 * Menu
	 * ────────────────────────────────────────────────────────────── */

	public function add_settings_page(): void {
		add_options_page(
			__( 'SEO + AEO', 'perrylabs-seo' ),
			__( 'SEO + AEO', 'perrylabs-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Current tab helper
	 * ────────────────────────────────────────────────────────────── */

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		return array_key_exists( $tab, $this->tabs ) ? $tab : 'general';
	}

	/**
	 * Return the Settings API page ID for a given tab slug.
	 */
	private function tab_page_id( string $tab ): string {
		return self::PAGE_SLUG . '-' . $tab;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Register settings + sections + fields
	 * ────────────────────────────────────────────────────────────── */

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_options' ),
			'default'           => self::defaults(),
		) );

		$this->register_general_tab();
		$this->register_social_tab();
		$this->register_sitemap_tab();
		$this->register_schema_tab();
		$this->register_tools_tab();
		$this->register_advanced_tab();
	}

	/* ── General Tab ──────────────────────────────────────────────── */

	private function register_general_tab(): void {
		$page = $this->tab_page_id( 'general' );

		// General section.
		add_settings_section(
			'perrylabs_seo_general',
			__( 'General', 'perrylabs-seo' ),
			'__return_false',
			$page
		);

		add_settings_field(
			'title_separator',
			__( 'Title Separator', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_general',
			array(
				'key'         => 'title_separator',
				'size'        => 5,
				'description' => __( 'Character between page title and site name (e.g. | or - or &mdash;).', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'default_description',
			__( 'Default Meta Description', 'perrylabs-seo' ),
			array( $this, 'render_textarea_field' ),
			$page,
			'perrylabs_seo_general',
			array(
				'key'         => 'default_description',
				'description' => __( 'Fallback description when no per-page description is set. Defaults to your site tagline.', 'perrylabs-seo' ),
			)
		);

		// Webmaster Verification Codes section.
		add_settings_section(
			'perrylabs_seo_verification',
			__( 'Webmaster Verification Codes', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Enter the verification codes provided by each search engine. Only paste the code/value, not the full meta tag.', 'perrylabs-seo' ) . '</p>';
			},
			$page
		);

		add_settings_field(
			'google_verification',
			__( 'Google Search Console', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_verification',
			array(
				'key'              => 'google_verification',
				'description_html' => sprintf(
					/* translators: %s: link to Google Search Console */
					__( 'Get your verification code from %s.', 'perrylabs-seo' ),
					'<a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Google Search Console</a>'
				),
			)
		);

		add_settings_field(
			'bing_verification',
			__( 'Bing Webmaster Tools', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_verification',
			array(
				'key'              => 'bing_verification',
				'description_html' => sprintf(
					/* translators: %s: link to Bing Webmaster Tools */
					__( 'Get your verification code from %s.', 'perrylabs-seo' ),
					'<a href="https://www.bing.com/webmasters" target="_blank" rel="noopener noreferrer">Bing Webmaster Tools</a>'
				),
			)
		);

		add_settings_field(
			'pinterest_verification',
			__( 'Pinterest', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_verification',
			array(
				'key'         => 'pinterest_verification',
				'description' => __( 'Pinterest domain verification code.', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'yandex_verification',
			__( 'Yandex Webmaster', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_verification',
			array(
				'key'         => 'yandex_verification',
				'description' => __( 'Yandex Webmaster verification code.', 'perrylabs-seo' ),
			)
		);
	}

	/* ── Social Tab ───────────────────────────────────────────────── */

	private function register_social_tab(): void {
		$page = $this->tab_page_id( 'social' );

		add_settings_section(
			'perrylabs_seo_social',
			__( 'Social Profiles', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Used for Organization structured data and Open Graph defaults.', 'perrylabs-seo' ) . '</p>';
			},
			$page
		);

		add_settings_field(
			'twitter_handle',
			__( 'Twitter Handle', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_social',
			array(
				'key'         => 'twitter_handle',
				'placeholder' => '@yourhandle',
				'description' => __( 'Include the @ symbol.', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'facebook_url',
			__( 'Facebook URL', 'perrylabs-seo' ),
			array( $this, 'render_url_field' ),
			$page,
			'perrylabs_seo_social',
			array(
				'key'         => 'facebook_url',
				'placeholder' => 'https://facebook.com/yourpage',
			)
		);

		add_settings_field(
			'linkedin_url',
			__( 'LinkedIn URL', 'perrylabs-seo' ),
			array( $this, 'render_url_field' ),
			$page,
			'perrylabs_seo_social',
			array(
				'key'         => 'linkedin_url',
				'placeholder' => 'https://linkedin.com/company/yourcompany',
			)
		);

		add_settings_field(
			'default_social_image',
			__( 'Default Social Image', 'perrylabs-seo' ),
			array( $this, 'render_image_field' ),
			$page,
			'perrylabs_seo_social',
			array(
				'key'         => 'default_social_image',
				'description' => __( 'Fallback image for Open Graph and Twitter Cards when no featured image is set. Recommended: 1200x630px.', 'perrylabs-seo' ),
			)
		);
	}

	/* ── Sitemap Tab ──────────────────────────────────────────────── */

	private function register_sitemap_tab(): void {
		$page = $this->tab_page_id( 'sitemap' );

		add_settings_section(
			'perrylabs_seo_sitemap',
			__( 'XML Sitemap', 'perrylabs-seo' ),
			function () {
				$sitemap_url = home_url( '/sitemap.xml' );
				echo '<p>' . sprintf(
					/* translators: %s: sitemap URL */
					esc_html__( 'Your sitemap is available at %s', 'perrylabs-seo' ),
					'<a href="' . esc_url( $sitemap_url ) . '" target="_blank"><code>' . esc_html( $sitemap_url ) . '</code></a>'
				) . '</p>';
			},
			$page
		);

		add_settings_field(
			'sitemap_post_types',
			__( 'Post Types', 'perrylabs-seo' ),
			array( $this, 'render_post_type_checkboxes' ),
			$page,
			'perrylabs_seo_sitemap',
			array( 'key' => 'sitemap_post_types' )
		);

		add_settings_field(
			'sitemap_taxonomies',
			__( 'Taxonomies', 'perrylabs-seo' ),
			array( $this, 'render_taxonomy_checkboxes' ),
			$page,
			'perrylabs_seo_sitemap',
			array( 'key' => 'sitemap_taxonomies' )
		);
	}

	/* ── Schema Tab ───────────────────────────────────────────────── */

	private function register_schema_tab(): void {
		$page = $this->tab_page_id( 'schema' );

		add_settings_section(
			'perrylabs_seo_business',
			__( 'Business Information', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Used for LocalBusiness structured data. See schema.org for details.', 'perrylabs-seo' ) . '</p>';
				echo '<p>';
				echo '<a href="https://schema.org/LocalBusiness" target="_blank" rel="noopener noreferrer">schema.org/LocalBusiness</a>';
				echo ' &middot; ';
				echo '<a href="https://business.google.com" target="_blank" rel="noopener noreferrer">Google Business Profile</a>';
				echo ' &middot; ';
				echo '<a href="https://mapsconnect.apple.com" target="_blank" rel="noopener noreferrer">Apple Maps Connect</a>';
				echo '</p>';
			},
			$page
		);

		add_settings_field(
			'business_name',
			__( 'Business Name', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_name', 'placeholder' => get_bloginfo( 'name' ) )
		);

		add_settings_field(
			'business_type',
			__( 'Business Type', 'perrylabs-seo' ),
			array( $this, 'render_select_field' ),
			$page,
			'perrylabs_seo_business',
			array(
				'key'     => 'business_type',
				'options' => array(
					''                        => __( '-- Select --', 'perrylabs-seo' ),
					'LocalBusiness'           => __( 'Local Business (General)', 'perrylabs-seo' ),
					'Restaurant'              => __( 'Restaurant', 'perrylabs-seo' ),
					'Store'                   => __( 'Store', 'perrylabs-seo' ),
					'ProfessionalService'     => __( 'Professional Service', 'perrylabs-seo' ),
					'MedicalBusiness'         => __( 'Medical Business', 'perrylabs-seo' ),
					'HealthAndBeautyBusiness' => __( 'Health & Beauty', 'perrylabs-seo' ),
					'FinancialService'        => __( 'Financial Service', 'perrylabs-seo' ),
					'EducationalOrganization' => __( 'Educational Organization', 'perrylabs-seo' ),
					'LegalService'            => __( 'Legal Service', 'perrylabs-seo' ),
					'RealEstateAgent'         => __( 'Real Estate Agent', 'perrylabs-seo' ),
					'AutoRepair'              => __( 'Auto Repair', 'perrylabs-seo' ),
				),
			)
		);

		add_settings_field(
			'business_street_address',
			__( 'Street Address', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_street_address' )
		);

		add_settings_field(
			'business_city',
			__( 'City', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_city' )
		);

		add_settings_field(
			'business_state',
			__( 'State / Region', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_state', 'size' => 20 )
		);

		add_settings_field(
			'business_postal_code',
			__( 'Postal Code', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_postal_code', 'size' => 15 )
		);

		add_settings_field(
			'business_country',
			__( 'Country', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array(
				'key'         => 'business_country',
				'placeholder' => 'US',
				'size'        => 10,
				'description' => __( 'Two-letter country code (e.g. US, GB, DE).', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'business_phone',
			__( 'Phone', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_phone', 'placeholder' => '+1-555-555-5555' )
		);

		add_settings_field(
			'business_email',
			__( 'Email', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			$page,
			'perrylabs_seo_business',
			array( 'key' => 'business_email', 'placeholder' => 'info@example.com' )
		);
	}

	/* ── Tools Tab ────────────────────────────────────────────────── */

	private function register_tools_tab(): void {
		$page = $this->tab_page_id( 'tools' );

		// ── IndexNow Section ──
		add_settings_section(
			'perrylabs_seo_indexnow',
			__( 'IndexNow', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'IndexNow lets you instantly notify search engines when content is created, updated, or deleted. No more waiting for crawlers.', 'perrylabs-seo' ) . '</p>';
				echo '<p><a href="https://www.indexnow.org" target="_blank" rel="noopener noreferrer">indexnow.org</a></p>';
			},
			$page
		);

		add_settings_field(
			'indexnow_enabled',
			__( 'Enable IndexNow', 'perrylabs-seo' ),
			array( $this, 'render_checkbox_field' ),
			$page,
			'perrylabs_seo_indexnow',
			array(
				'key'   => 'indexnow_enabled',
				'label' => __( 'Send IndexNow pings when content is published or updated.', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'indexnow_api_key_display',
			__( 'API Key', 'perrylabs-seo' ),
			array( $this, 'render_indexnow_api_key_field' ),
			$page,
			'perrylabs_seo_indexnow'
		);

		add_settings_field(
			'indexnow_verification_url',
			__( 'Verification URL', 'perrylabs-seo' ),
			array( $this, 'render_indexnow_verification_url_field' ),
			$page,
			'perrylabs_seo_indexnow'
		);

		add_settings_field(
			'indexnow_log_display',
			__( 'Recent Pings', 'perrylabs-seo' ),
			array( $this, 'render_indexnow_log_field' ),
			$page,
			'perrylabs_seo_indexnow'
		);

		// ── llms.txt Section ──
		add_settings_section(
			'perrylabs_seo_llms_txt',
			__( 'llms.txt', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Provide an AI-readable summary of your site content. llms.txt helps large language models understand and reference your site accurately.', 'perrylabs-seo' ) . '</p>';
				echo '<p><a href="https://llmstxt.org" target="_blank" rel="noopener noreferrer">llmstxt.org</a></p>';
			},
			$page
		);

		add_settings_field(
			'llms_txt_enabled',
			__( 'Enable llms.txt', 'perrylabs-seo' ),
			array( $this, 'render_checkbox_field' ),
			$page,
			'perrylabs_seo_llms_txt',
			array(
				'key'   => 'llms_txt_enabled',
				'label' => __( 'Serve /llms.txt and /llms-full.txt endpoints.', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'llms_txt_preview',
			__( 'Preview', 'perrylabs-seo' ),
			array( $this, 'render_llms_txt_preview_field' ),
			$page,
			'perrylabs_seo_llms_txt'
		);
	}

	/* ── Advanced Tab ─────────────────────────────────────────────── */

	private function register_advanced_tab(): void {
		$page = $this->tab_page_id( 'advanced' );

		add_settings_section(
			'perrylabs_seo_robots_defaults',
			__( 'Robots Defaults', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Set noindex globally for archive types. Individual posts can override these.', 'perrylabs-seo' ) . '</p>';
			},
			$page
		);

		$noindex_options = array(
			'noindex_archives'   => __( 'Date-based Archives', 'perrylabs-seo' ),
			'noindex_categories' => __( 'Category Archives', 'perrylabs-seo' ),
			'noindex_tags'       => __( 'Tag Archives', 'perrylabs-seo' ),
			'noindex_authors'    => __( 'Author Archives', 'perrylabs-seo' ),
		);

		foreach ( $noindex_options as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_checkbox_field' ),
				$page,
				'perrylabs_seo_robots_defaults',
				array(
					'key'   => $key,
					'label' => sprintf(
						/* translators: %s: archive type */
						__( 'Add noindex to %s', 'perrylabs-seo' ),
						strtolower( $label )
					),
				)
			);
		}

		// Robots.txt section.
		add_settings_section(
			'perrylabs_seo_robots_txt',
			__( 'Robots.txt', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'The plugin generates a production-ready robots.txt automatically. Use the custom field below to override it entirely.', 'perrylabs-seo' ) . '</p>';
			},
			$page
		);

		add_settings_field(
			'robots_block_ai_crawlers',
			__( 'Block AI Training Crawlers', 'perrylabs-seo' ),
			array( $this, 'render_checkbox_field' ),
			$page,
			'perrylabs_seo_robots_txt',
			array(
				'key'   => 'robots_block_ai_crawlers',
				'label' => __( 'Block known AI training crawlers (GPTBot, ClaudeBot, CCBot, etc.) in robots.txt', 'perrylabs-seo' ),
			)
		);

		add_settings_field(
			'robots_txt_custom',
			__( 'Custom Robots.txt', 'perrylabs-seo' ),
			array( $this, 'render_textarea_field' ),
			$page,
			'perrylabs_seo_robots_txt',
			array(
				'key'         => 'robots_txt_custom',
				'rows'        => 12,
				'description' => __( 'If set, this replaces the auto-generated robots.txt entirely. Leave empty to use the default.', 'perrylabs-seo' ),
			)
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Default option values
	 * ────────────────────────────────────────────────────────────── */

	public static function defaults(): array {
		return array(
			// General.
			'title_separator'        => '|',
			'default_description'    => get_bloginfo( 'description' ),

			// Webmaster verification.
			'google_verification'    => '',
			'bing_verification'      => '',
			'pinterest_verification' => '',
			'yandex_verification'    => '',

			// Social.
			'twitter_handle'         => '',
			'facebook_url'           => '',
			'linkedin_url'           => '',
			'default_social_image'   => '',

			// Sitemap.
			'sitemap_post_types'     => array( 'post', 'page' ),
			'sitemap_taxonomies'     => array( 'category', 'post_tag' ),

			// Schema / Business.
			'business_name'           => '',
			'business_type'           => '',
			'business_street_address' => '',
			'business_city'           => '',
			'business_state'          => '',
			'business_postal_code'    => '',
			'business_country'        => '',
			'business_phone'          => '',
			'business_email'          => '',

			// Tools — IndexNow.
			'indexnow_enabled'       => false,
			'indexnow_api_key'       => '',

			// Tools — llms.txt.
			'llms_txt_enabled'       => true,

			// Tools — Robots.txt.
			'robots_txt_custom'          => '',
			'robots_block_ai_crawlers'   => true,

			// Advanced.
			'noindex_archives'       => false,
			'noindex_categories'     => false,
			'noindex_tags'           => false,
			'noindex_authors'        => true,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Sanitize callback
	 * ────────────────────────────────────────────────────────────── */

	public function sanitize_options( mixed $input ): array {
		$defaults  = self::defaults();
		$sanitized = array();

		// General.
		$sanitized['title_separator']     = sanitize_text_field( $input['title_separator'] ?? $defaults['title_separator'] );
		$sanitized['default_description'] = sanitize_textarea_field( $input['default_description'] ?? $defaults['default_description'] );

		// Webmaster verification codes.
		$sanitized['google_verification']    = sanitize_text_field( $input['google_verification'] ?? '' );
		$sanitized['bing_verification']      = sanitize_text_field( $input['bing_verification'] ?? '' );
		$sanitized['pinterest_verification'] = sanitize_text_field( $input['pinterest_verification'] ?? '' );
		$sanitized['yandex_verification']    = sanitize_text_field( $input['yandex_verification'] ?? '' );

		// Social.
		$sanitized['twitter_handle']       = sanitize_text_field( $input['twitter_handle'] ?? '' );
		$sanitized['facebook_url']         = esc_url_raw( $input['facebook_url'] ?? '' );
		$sanitized['linkedin_url']         = esc_url_raw( $input['linkedin_url'] ?? '' );
		$sanitized['default_social_image'] = esc_url_raw( $input['default_social_image'] ?? '' );

		// Sitemap arrays.
		$sanitized['sitemap_post_types'] = array_map(
			'sanitize_text_field',
			(array) ( $input['sitemap_post_types'] ?? array() )
		);
		$sanitized['sitemap_taxonomies'] = array_map(
			'sanitize_text_field',
			(array) ( $input['sitemap_taxonomies'] ?? array() )
		);

		// Business info.
		$sanitized['business_name']           = sanitize_text_field( $input['business_name'] ?? '' );
		$sanitized['business_type']           = sanitize_text_field( $input['business_type'] ?? '' );
		$sanitized['business_street_address'] = sanitize_text_field( $input['business_street_address'] ?? '' );
		$sanitized['business_city']           = sanitize_text_field( $input['business_city'] ?? '' );
		$sanitized['business_state']          = sanitize_text_field( $input['business_state'] ?? '' );
		$sanitized['business_postal_code']    = sanitize_text_field( $input['business_postal_code'] ?? '' );
		$sanitized['business_country']        = sanitize_text_field( $input['business_country'] ?? '' );
		$sanitized['business_phone']          = sanitize_text_field( $input['business_phone'] ?? '' );
		$sanitized['business_email']          = sanitize_email( $input['business_email'] ?? '' );

		// IndexNow.
		$sanitized['indexnow_enabled'] = ! empty( $input['indexnow_enabled'] );
		$sanitized['indexnow_api_key'] = sanitize_text_field( $input['indexnow_api_key'] ?? '' );

		// Auto-generate IndexNow API key on first save if enabled and empty.
		if ( $sanitized['indexnow_enabled'] && empty( $sanitized['indexnow_api_key'] ) ) {
			$sanitized['indexnow_api_key'] = wp_generate_uuid4();
		}

		// llms.txt.
		$sanitized['llms_txt_enabled'] = ! empty( $input['llms_txt_enabled'] );

		// Robots.txt.
		$sanitized['robots_txt_custom']        = sanitize_textarea_field( $input['robots_txt_custom'] ?? '' );
		$sanitized['robots_block_ai_crawlers'] = ! empty( $input['robots_block_ai_crawlers'] );

		// Robots booleans.
		$sanitized['noindex_archives']   = ! empty( $input['noindex_archives'] );
		$sanitized['noindex_categories'] = ! empty( $input['noindex_categories'] );
		$sanitized['noindex_tags']       = ! empty( $input['noindex_tags'] );
		$sanitized['noindex_authors']    = ! empty( $input['noindex_authors'] );

		return $sanitized;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Field renderers — generic
	 * ────────────────────────────────────────────────────────────── */

	public function render_text_field( array $args ): void {
		$options     = get_option( self::OPTION_NAME, self::defaults() );
		$value       = $options[ $args['key'] ] ?? '';
		$size        = $args['size'] ?? 40;
		$placeholder = $args['placeholder'] ?? '';

		printf(
			'<input type="text" name="%s[%s]" value="%s" size="%d" placeholder="%s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_attr( $value ),
			(int) $size,
			esc_attr( $placeholder )
		);

		if ( ! empty( $args['description_html'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses( $args['description_html'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		} elseif ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_url_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';

		printf(
			'<input type="url" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_url( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);

		if ( ! empty( $args['description_html'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses( $args['description_html'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		} elseif ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_textarea_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';

		printf(
			'<textarea name="%s[%s]" rows="3" cols="60" class="large-text">%s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_textarea( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_checkbox_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$checked = ! empty( $options[ $args['key'] ] );

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			checked( $checked, true, false ),
			esc_html( $args['label'] )
		);

		if ( ! empty( $args['description_html'] ) ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses( $args['description_html'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		} elseif ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_select_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';

		printf(
			'<select name="%s[%s]">',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] )
		);
		foreach ( $args['options'] as $opt_value => $opt_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $opt_value ),
				selected( $value, $opt_value, false ),
				esc_html( $opt_label )
			);
		}
		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_image_field( array $args ): void {
		$options   = get_option( self::OPTION_NAME, self::defaults() );
		$image_url = $options[ $args['key'] ] ?? '';

		echo '<div class="perrylabs-seo-image-field">';
		printf(
			'<input type="url" name="%s[%s]" value="%s" class="regular-text" id="perrylabs-seo-%s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_url( $image_url ),
			esc_attr( $args['key'] )
		);
		printf(
			' <button type="button" class="button perrylabs-seo-upload-image" data-target="perrylabs-seo-%s">%s</button>',
			esc_attr( $args['key'] ),
			esc_html__( 'Select Image', 'perrylabs-seo' )
		);

		if ( $image_url ) {
			printf( '<div style="margin-top:8px;"><img src="%s" style="max-width:300px;height:auto;" /></div>', esc_url( $image_url ) );
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
		echo '</div>';
	}

	public function render_post_type_checkboxes( array $args ): void {
		$options    = get_option( self::OPTION_NAME, self::defaults() );
		$selected   = (array) ( $options[ $args['key'] ] ?? array() );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$checked = in_array( $pt->name, $selected, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $args['key'] ),
				esc_attr( $pt->name ),
				checked( $checked, true, false ),
				esc_html( $pt->labels->name ),
				esc_html( $pt->name )
			);
		}
	}

	public function render_taxonomy_checkboxes( array $args ): void {
		$options    = get_option( self::OPTION_NAME, self::defaults() );
		$selected   = (array) ( $options[ $args['key'] ] ?? array() );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			$checked = in_array( $tax->name, $selected, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $args['key'] ),
				esc_attr( $tax->name ),
				checked( $checked, true, false ),
				esc_html( $tax->labels->name ),
				esc_html( $tax->name )
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Field renderers — Tools tab (IndexNow + llms.txt)
	 * ────────────────────────────────────────────────────────────── */

	public function render_indexnow_api_key_field(): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$api_key = $options['indexnow_api_key'] ?? '';

		if ( $api_key ) {
			printf(
				'<input type="text" value="%s" class="regular-text" readonly="readonly" />',
				esc_attr( $api_key )
			);
			// Hidden field to preserve value on save.
			printf(
				'<input type="hidden" name="%s[indexnow_api_key]" value="%s" />',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $api_key )
			);
			echo '<p class="description">' . esc_html__( 'Auto-generated UUID. This key is used for IndexNow verification.', 'perrylabs-seo' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'An API key will be auto-generated when IndexNow is enabled and settings are saved.', 'perrylabs-seo' ) . '</p>';
			printf(
				'<input type="hidden" name="%s[indexnow_api_key]" value="" />',
				esc_attr( self::OPTION_NAME )
			);
		}
	}

	public function render_indexnow_verification_url_field(): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$api_key = $options['indexnow_api_key'] ?? '';

		if ( $api_key ) {
			$url = home_url( '/' . $api_key . '.txt' );
			printf(
				'<input type="text" value="%s" class="regular-text" readonly="readonly" />',
				esc_attr( $url )
			);
			echo '<p class="description">' . esc_html__( 'Search engines will request this URL to verify your API key.', 'perrylabs-seo' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Available after an API key is generated.', 'perrylabs-seo' ) . '</p>';
		}
	}

	public function render_indexnow_log_field(): void {
		$log = get_transient( 'perrylabs_seo_indexnow_log' );

		if ( empty( $log ) || ! is_array( $log ) ) {
			echo '<p class="description">' . esc_html__( 'No recent pings recorded.', 'perrylabs-seo' ) . '</p>';
			return;
		}

		echo '<table class="widefat fixed striped" style="max-width:700px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'perrylabs-seo' ) . '</th>';
		echo '<th style="width:160px;">' . esc_html__( 'Time', 'perrylabs-seo' ) . '</th>';
		echo '<th style="width:80px;">' . esc_html__( 'Status', 'perrylabs-seo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $log ) as $entry ) {
			printf(
				'<tr><td><code style="font-size:12px;">%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $entry['url'] ?? '' ),
				esc_html( $entry['time'] ?? '' ),
				esc_html( $entry['status'] ?? '' )
			);
		}

		echo '</tbody></table>';
	}

	public function render_llms_txt_preview_field(): void {
		$llms_url      = home_url( '/llms.txt' );
		$llms_full_url = home_url( '/llms-full.txt' );

		printf(
			'<a href="%s" target="_blank"><code>%s</code></a>',
			esc_url( $llms_url ),
			esc_html( $llms_url )
		);
		echo '<br />';
		printf(
			'<a href="%s" target="_blank"><code>%s</code></a>',
			esc_url( $llms_full_url ),
			esc_html( $llms_full_url )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Settings page output (tabbed)
	 * ────────────────────────────────────────────────────────────── */

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = $this->current_tab();
		$base_url    = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO + AEO', 'perrylabs-seo' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $this->tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
					   class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="perrylabs-seo-tab-content" style="margin-top: 20px;">
				<?php
				switch ( $current_tab ) {
					case 'redirects':
						$this->render_redirects_tab();
						break;

					case 'tools':
						$this->render_tools_tab();
						break;

					default:
						$this->render_settings_form_tab( $current_tab );
						break;
				}
				?>
			</div>

			<div style="margin-top: 30px; padding-top: 10px; border-top: 1px solid #ccd0d4; text-align: right;">
				<span style="color: #999; font-size: 12px;">v<?php echo esc_html( self::VERSION ); ?></span>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('.perrylabs-seo-upload-image').on('click', function(e) {
				e.preventDefault();
				var button = $(this);
				var targetId = button.data('target');
				var frame = wp.media({
					title: '<?php echo esc_js( __( 'Select Social Image', 'perrylabs-seo' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Use this image', 'perrylabs-seo' ) ); ?>' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#' + targetId).val(attachment.url);
					button.parent().find('img').remove();
					button.after('<div style="margin-top:8px;"><img src="' + attachment.url + '" style="max-width:300px;height:auto;" /></div>');
				});
				frame.open();
			});
		});
		</script>
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tab rendering — Settings API form
	 * (general, social, sitemap, schema, advanced)
	 * ────────────────────────────────────────────────────────────── */

	private function render_settings_form_tab( string $tab ): void {
		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( $this->tab_page_id( $tab ) );
		submit_button();
		echo '</form>';
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tab rendering — Redirects (delegated, no settings form)
	 * ────────────────────────────────────────────────────────────── */

	private function render_redirects_tab(): void {
		if ( $this->redirects && method_exists( $this->redirects, 'render_tab_content' ) ) {
			$this->redirects->render_tab_content();
		} else {
			echo '<p>' . esc_html__( 'Redirects module is not available.', 'perrylabs-seo' ) . '</p>';
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tab rendering — Tools (hybrid: settings form + delegated robots)
	 * ────────────────────────────────────────────────────────────── */

	private function render_tools_tab(): void {
		// Settings form for IndexNow and llms.txt fields.
		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( $this->tab_page_id( 'tools' ) );
		submit_button();
		echo '</form>';

		// Robots.txt section — delegated, outside the settings form.
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Robots.txt', 'perrylabs-seo' ) . '</h2>';

		if ( $this->robots && method_exists( $this->robots, 'render_tab_content' ) ) {
			$this->robots->render_tab_content();
		} else {
			echo '<p>' . esc_html__( 'Robots.txt manager is not available.', 'perrylabs-seo' ) . '</p>';
		}
	}
}
