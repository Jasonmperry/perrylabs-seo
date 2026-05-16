<?php
/**
 * PLSEO_Tabs — all settings tabs in one place.
 *
 * Each tab gets a register_* method that adds its sections + fields
 * using the WP Settings API. Field rendering is delegated to PLSEO_Field_Renderer
 * and per-key sanitizers are registered on PLSEO_Options.
 *
 * Keeping every tab in this single file is intentional — it's the page where
 * the entire admin-facing surface is visible. Splitting per tab made v1
 * settings hard to scan.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Tabs {

	/**
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'general'  => __( 'General', 'perrylabs-seo' ),
			'social'   => __( 'Social', 'perrylabs-seo' ),
			'sitemap'  => __( 'Sitemap', 'perrylabs-seo' ),
			'schema'   => __( 'Schema', 'perrylabs-seo' ),
			'aeo'      => __( 'AEO', 'perrylabs-seo' ),
			'tools'    => __( 'Tools', 'perrylabs-seo' ),
			'advanced' => __( 'Advanced', 'perrylabs-seo' ),
		);
	}

	public static function register_all(): void {
		self::register_general();
		self::register_social();
		self::register_sitemap();
		self::register_schema();
		self::register_aeo();
		self::register_tools();
		self::register_advanced();
	}

	private static function page( string $tab ): string {
		return PLSEO_Admin::MENU_SLUG . '-' . $tab;
	}

	/* ───────────────────────── general ───────────────────────── */

	private static function register_general(): void {
		$page = self::page( 'general' );

		add_settings_section( 'plseo_titles', __( 'Title templates', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Variables can be used:', 'perrylabs-seo' ) . ' ';
			$tokens = PLSEO_Template_Resolver::token_documentation();
			$keys   = array_keys( $tokens );
			echo '<code>' . esc_html( implode( '</code> <code>', $keys ) ) . '</code>';
			echo '</p>';
		}, $page );

		foreach ( array(
			'title_template_home'    => __( 'Home', 'perrylabs-seo' ),
			'title_template_post'    => __( 'Posts', 'perrylabs-seo' ),
			'title_template_page'    => __( 'Pages', 'perrylabs-seo' ),
			'title_template_archive' => __( 'Archives', 'perrylabs-seo' ),
		) as $key => $label ) {
			add_settings_field( $key, $label, array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_titles', array( 'key' => $key, 'size' => 70 ) );
			PLSEO_Options::register_sanitizer( $key, static fn( $v ) => sanitize_text_field( (string) $v ) );
		}

		add_settings_field( 'title_separator', __( 'Separator', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_titles', array( 'key' => 'title_separator', 'size' => 5, 'description' => __( 'Character between title and site name.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'title_separator', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_section( 'plseo_desc', __( 'Default description', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'default_description', __( 'Fallback description', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'textarea' ), $page, 'plseo_desc', array( 'key' => 'default_description', 'description' => __( 'Used on the home page and as a fallback for posts without one.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'default_description', static fn( $v ) => sanitize_textarea_field( (string) $v ) );

		add_settings_field( 'site_language', __( 'Site language code', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_desc', array( 'key' => 'site_language', 'size' => 10, 'description' => __( 'BCP-47 code like "en", "en-US". Leave empty to derive from WordPress locale.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'site_language', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_section( 'plseo_verify', __( 'Webmaster verification', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Paste only the code value, not the full <meta> tag.', 'perrylabs-seo' ) . '</p>';
		}, $page );

		foreach ( array(
			'google_verification'    => __( 'Google Search Console', 'perrylabs-seo' ),
			'bing_verification'      => __( 'Bing Webmaster', 'perrylabs-seo' ),
			'pinterest_verification' => __( 'Pinterest', 'perrylabs-seo' ),
			'yandex_verification'    => __( 'Yandex', 'perrylabs-seo' ),
			'baidu_verification'     => __( 'Baidu', 'perrylabs-seo' ),
		) as $key => $label ) {
			add_settings_field( $key, $label, array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_verify', array( 'key' => $key ) );
			PLSEO_Options::register_sanitizer( $key, static fn( $v ) => sanitize_text_field( (string) $v ) );
		}
	}

	/* ───────────────────────── social ───────────────────────── */

	private static function register_social(): void {
		$page = self::page( 'social' );

		add_settings_section( 'plseo_social_handles', __( 'Profiles', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'twitter_handle', __( 'Twitter handle', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_social_handles', array( 'key' => 'twitter_handle', 'placeholder' => '@yoursite' ) );
		PLSEO_Options::register_sanitizer( 'twitter_handle', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_field( 'twitter_card_type', __( 'Twitter card type', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'select' ), $page, 'plseo_social_handles', array(
			'key'     => 'twitter_card_type',
			'choices' => array(
				'summary'              => __( 'Summary', 'perrylabs-seo' ),
				'summary_large_image'  => __( 'Summary with large image', 'perrylabs-seo' ),
			),
		) );
		PLSEO_Options::register_sanitizer( 'twitter_card_type', static fn( $v ) => in_array( $v, array( 'summary', 'summary_large_image' ), true ) ? $v : 'summary_large_image' );

		foreach ( array(
			'facebook_url'   => __( 'Facebook URL', 'perrylabs-seo' ),
			'linkedin_url'   => __( 'LinkedIn URL', 'perrylabs-seo' ),
			'instagram_url'  => __( 'Instagram URL', 'perrylabs-seo' ),
			'youtube_url'    => __( 'YouTube URL', 'perrylabs-seo' ),
			'github_url'     => __( 'GitHub URL', 'perrylabs-seo' ),
			'mastodon_url'   => __( 'Mastodon URL', 'perrylabs-seo' ),
		) as $key => $label ) {
			add_settings_field( $key, $label, array( PLSEO_Field_Renderer::class, 'url' ), $page, 'plseo_social_handles', array( 'key' => $key ) );
			PLSEO_Options::register_sanitizer( $key, static fn( $v ) => esc_url_raw( (string) $v ) );
		}

		add_settings_section( 'plseo_social_img', __( 'Defaults', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'default_social_image', __( 'Default social image', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'image' ), $page, 'plseo_social_img', array( 'key' => 'default_social_image', 'description' => __( 'Used when a post has no featured image.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'default_social_image', static fn( $v ) => esc_url_raw( (string) $v ) );

		add_settings_field( 'og_locale', __( 'Open Graph locale', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_social_img', array( 'key' => 'og_locale', 'size' => 10, 'description' => __( 'Like en_US. Auto-derived if blank.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'og_locale', static fn( $v ) => sanitize_text_field( (string) $v ) );
	}

	/* ───────────────────────── sitemap ───────────────────────── */

	private static function register_sitemap(): void {
		$page = self::page( 'sitemap' );

		add_settings_section( 'plseo_sm', __( 'Sitemap', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . sprintf(
				/* translators: %s: sitemap URL */
				wp_kses_post( __( 'Index lives at %s. Each post type and taxonomy gets its own sub-sitemap.', 'perrylabs-seo' ) ),
				'<a href="' . esc_url( home_url( '/sitemap.xml' ) ) . '" target="_blank"><code>/sitemap.xml</code></a>'
			) . '</p>';
		}, $page );

		add_settings_field( 'sitemap_enabled', __( 'Enable sitemap', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_sm', array( 'key' => 'sitemap_enabled', 'inline_label' => __( 'Serve /sitemap.xml', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'sitemap_enabled', static fn( $v ) => (bool) $v );

		add_settings_field( 'sitemap_post_types', __( 'Post types', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'multicheck_post_types' ), $page, 'plseo_sm', array( 'key' => 'sitemap_post_types' ) );
		PLSEO_Options::register_sanitizer( 'sitemap_post_types', static fn( $v ) => array_values( array_filter( array_map( 'sanitize_key', (array) $v ) ) ) );

		add_settings_field( 'sitemap_taxonomies', __( 'Taxonomies', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'multicheck_taxonomies' ), $page, 'plseo_sm', array( 'key' => 'sitemap_taxonomies' ) );
		PLSEO_Options::register_sanitizer( 'sitemap_taxonomies', static fn( $v ) => array_values( array_filter( array_map( 'sanitize_key', (array) $v ) ) ) );

		add_settings_field( 'sitemap_include_images', __( 'Include image sitemap extensions', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_sm', array( 'key' => 'sitemap_include_images', 'inline_label' => __( 'Emit <image:image> entries per URL', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'sitemap_include_images', static fn( $v ) => (bool) $v );

		// News sitemap.
		add_settings_section( 'plseo_sm_news', __( 'Google News sitemap', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . sprintf(
				wp_kses_post( __( 'Lives at %s. Only includes articles published in the last 48 hours.', 'perrylabs-seo' ) ),
				'<a href="' . esc_url( home_url( '/sitemap-news.xml' ) ) . '" target="_blank"><code>/sitemap-news.xml</code></a>'
			) . '</p>';
		}, $page );

		add_settings_field( 'sitemap_news_enabled', __( 'Enable news sitemap', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_sm_news', array( 'key' => 'sitemap_news_enabled', 'inline_label' => __( 'Serve /sitemap-news.xml and reference it from the index', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'sitemap_news_enabled', static fn( $v ) => (bool) $v );

		add_settings_field( 'sitemap_news_post_types', __( 'News post types', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'multicheck_post_types' ), $page, 'plseo_sm_news', array( 'key' => 'sitemap_news_post_types' ) );
		PLSEO_Options::register_sanitizer( 'sitemap_news_post_types', static fn( $v ) => array_values( array_filter( array_map( 'sanitize_key', (array) $v ) ) ) );

		add_settings_field( 'sitemap_news_publication', __( 'Publication name', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_sm_news', array( 'key' => 'sitemap_news_publication', 'placeholder' => get_bloginfo( 'name' ), 'description' => __( 'Overrides site title in the <news:publication> block. Leave empty to use site title.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'sitemap_news_publication', static fn( $v ) => sanitize_text_field( (string) $v ) );

		// Video sitemap.
		add_settings_section( 'plseo_sm_video', __( 'Video sitemap', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . sprintf(
				wp_kses_post( __( 'Lives at %s. Posts containing a YouTube/Vimeo/native video are auto-included.', 'perrylabs-seo' ) ),
				'<a href="' . esc_url( home_url( '/sitemap-videos.xml' ) ) . '" target="_blank"><code>/sitemap-videos.xml</code></a>'
			) . '</p>';
		}, $page );

		add_settings_field( 'sitemap_video_enabled', __( 'Enable video sitemap', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_sm_video', array( 'key' => 'sitemap_video_enabled', 'inline_label' => __( 'Serve /sitemap-videos.xml', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'sitemap_video_enabled', static fn( $v ) => (bool) $v );
	}

	/* ───────────────────────── schema ───────────────────────── */

	private static function register_schema(): void {
		$page = self::page( 'schema' );

		add_settings_section( 'plseo_org', __( 'Organization / Business', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'business_name', __( 'Name', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org', array( 'key' => 'business_name', 'placeholder' => get_bloginfo( 'name' ) ) );
		PLSEO_Options::register_sanitizer( 'business_name', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_field( 'business_legal_name', __( 'Legal name', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org', array( 'key' => 'business_legal_name' ) );
		PLSEO_Options::register_sanitizer( 'business_legal_name', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_field( 'business_type', __( 'Schema type', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'select' ), $page, 'plseo_org', array(
			'key' => 'business_type',
			'choices' => array(
				'Organization'             => 'Organization',
				'Corporation'              => 'Corporation',
				'LocalBusiness'            => 'LocalBusiness',
				'ProfessionalService'      => 'ProfessionalService',
				'Restaurant'               => 'Restaurant',
				'Store'                    => 'Store',
				'NewsMediaOrganization'    => 'NewsMediaOrganization',
				'EducationalOrganization'  => 'EducationalOrganization',
				'NGO'                      => 'NGO',
				'GovernmentOrganization'   => 'GovernmentOrganization',
			),
		) );
		PLSEO_Options::register_sanitizer( 'business_type', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_field( 'business_logo', __( 'Logo', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'image' ), $page, 'plseo_org', array( 'key' => 'business_logo' ) );
		PLSEO_Options::register_sanitizer( 'business_logo', static fn( $v ) => esc_url_raw( (string) $v ) );

		add_settings_field( 'business_founding_date', __( 'Founded (YYYY-MM-DD)', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org', array( 'key' => 'business_founding_date', 'placeholder' => '2021-04-15' ) );
		PLSEO_Options::register_sanitizer( 'business_founding_date', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_section( 'plseo_org_addr', __( 'Address & contact', 'perrylabs-seo' ), '__return_false', $page );
		foreach ( array(
			'business_street_address' => __( 'Street', 'perrylabs-seo' ),
			'business_city'           => __( 'City', 'perrylabs-seo' ),
			'business_state'          => __( 'Region / State', 'perrylabs-seo' ),
			'business_postal_code'    => __( 'Postal code', 'perrylabs-seo' ),
			'business_country'        => __( 'Country', 'perrylabs-seo' ),
			'business_phone'          => __( 'Phone', 'perrylabs-seo' ),
		) as $key => $label ) {
			add_settings_field( $key, $label, array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org_addr', array( 'key' => $key ) );
			PLSEO_Options::register_sanitizer( $key, static fn( $v ) => sanitize_text_field( (string) $v ) );
		}
		add_settings_field( 'business_email', __( 'Email', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org_addr', array( 'key' => 'business_email' ) );
		PLSEO_Options::register_sanitizer( 'business_email', static fn( $v ) => sanitize_email( (string) $v ) );

		add_settings_section( 'plseo_org_lb', __( 'LocalBusiness extras', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Only emitted when Schema type is a LocalBusiness, Restaurant, or Store.', 'perrylabs-seo' ) . '</p>';
		}, $page );

		add_settings_field( 'business_price_range', __( 'Price range', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'text' ), $page, 'plseo_org_lb', array( 'key' => 'business_price_range', 'placeholder' => '$$' ) );
		PLSEO_Options::register_sanitizer( 'business_price_range', static fn( $v ) => sanitize_text_field( (string) $v ) );

		add_settings_field( 'business_hours', __( 'Opening hours (one per line)', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'textarea' ), $page, 'plseo_org_lb', array(
			'key' => 'business_hours',
			'description' => __( 'Example: "Mo-Fr 09:00-17:00", one entry per line.', 'perrylabs-seo' ),
			'rows' => 4,
		) );
		PLSEO_Options::register_sanitizer( 'business_hours', static fn( $v ) => sanitize_textarea_field( (string) $v ) );

		// Schema display rules.
		add_settings_section( 'plseo_schema_rules', __( 'Display rules', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Decide which schema types emit for which posts. First matching rule wins; per-post overrides on the meta box still take priority.', 'perrylabs-seo' ) . '</p>';
			echo '<p class="description">' . wp_kses_post( __( 'Format: JSON array of <code>{"when":{"post_type":"post","taxonomy":"category","term_slug":"reviews"},"emit":["Article","Review"]}</code> objects. Empty <code>when</code> = unconditional.', 'perrylabs-seo' ) ) . '</p>';
		}, $page );

		add_settings_field( 'schema_rules', __( 'Rules (JSON)', 'perrylabs-seo' ), array( __CLASS__, 'render_schema_rules_field' ), $page, 'plseo_schema_rules' );
		PLSEO_Options::register_sanitizer( 'schema_rules', static function ( $v ) {
			if ( is_string( $v ) ) {
				$decoded = json_decode( $v, true );
				$v = is_array( $decoded ) ? $decoded : array();
			}
			return PLSEO_Schema_Rules::sanitize( $v );
		} );
	}

	public static function render_schema_rules_field(): void {
		$value = (array) PLSEO_Options::get( 'schema_rules', array() );
		$json  = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json || '[]' === $json || '' === $json ) {
			$json = "[\n  { \"when\": { \"post_type\": \"post\" }, \"emit\": [\"Article\"] }\n]";
		}
		printf(
			'<textarea name="%1$s[schema_rules]" rows="10" class="large-text code" spellcheck="false">%2$s</textarea>',
			esc_attr( PLSEO_Options::OPTION_NAME ),
			esc_textarea( (string) $json )
		);
		echo '<p class="description">' . esc_html__( 'Emit accepts: Article, NewsArticle, BlogPosting, TechArticle, Event, VideoObject, Person, LocalBusiness — plus the special token "none" to suppress schema for the matched posts.', 'perrylabs-seo' ) . '</p>';
	}

	/* ───────────────────────── AEO ───────────────────────── */

	private static function register_aeo(): void {
		$page = self::page( 'aeo' );

		add_settings_section( 'plseo_aeo_intro', __( 'Answer engine optimization', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Settings that shape how AI assistants and answer engines consume your content.', 'perrylabs-seo' ) . '</p>';
		}, $page );

		add_settings_field( 'aeo_faq_autodetect', __( 'Auto-detect FAQ blocks', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_aeo_intro', array( 'key' => 'aeo_faq_autodetect', 'inline_label' => __( 'Emit FAQPage schema from Q-style headings or core/details blocks', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'aeo_faq_autodetect', static fn( $v ) => (bool) $v );

		add_settings_field( 'aeo_howto_autodetect', __( 'Auto-detect HowTo steps', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_aeo_intro', array( 'key' => 'aeo_howto_autodetect', 'inline_label' => __( 'Emit HowTo schema from "Step N" headings or the first ordered list', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'aeo_howto_autodetect', static fn( $v ) => (bool) $v );

		add_settings_field( 'aeo_quick_answer_field', __( 'Quick-answer source', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'select' ), $page, 'plseo_aeo_intro', array(
			'key' => 'aeo_quick_answer_field',
			'choices' => array(
				'first_paragraph' => __( 'First paragraph of content', 'perrylabs-seo' ),
				'excerpt'         => __( 'Post excerpt', 'perrylabs-seo' ),
				'none'            => __( 'None', 'perrylabs-seo' ),
			),
			'description' => __( 'Surfaced as the primary entity\'s "abstract" — AI overviews lift this text directly.', 'perrylabs-seo' ),
		) );
		PLSEO_Options::register_sanitizer( 'aeo_quick_answer_field', static fn( $v ) => in_array( $v, array( 'first_paragraph', 'excerpt', 'none' ), true ) ? $v : 'first_paragraph' );

		add_settings_field( 'aeo_speakable_selectors', __( 'Speakable CSS selectors', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'selector_list' ), $page, 'plseo_aeo_intro', array( 'key' => 'aeo_speakable_selectors', 'description' => __( 'One per line. Voice assistants read aloud the matched regions.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'aeo_speakable_selectors', static function ( $v ) {
			$lines = is_string( $v ) ? preg_split( '/\r?\n/', $v ) : (array) $v;
			return array_values( array_filter( array_map( static fn( $l ) => sanitize_text_field( trim( (string) $l ) ), $lines ?: array() ) ) );
		} );

		add_settings_section( 'plseo_aeo_visits', __( 'AI crawler logging', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'aeo_log_ai_visits', __( 'Log AI crawler visits', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_aeo_visits', array( 'key' => 'aeo_log_ai_visits', 'inline_label' => __( 'Record one row per (bot, URL, day). See the AEO dashboard.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'aeo_log_ai_visits', static fn( $v ) => (bool) $v );

		add_settings_field( 'aeo_ai_visit_retention_days', __( 'Retention (days)', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'number' ), $page, 'plseo_aeo_visits', array( 'key' => 'aeo_ai_visit_retention_days', 'min' => 7, 'max' => 730 ) );
		PLSEO_Options::register_sanitizer( 'aeo_ai_visit_retention_days', static fn( $v ) => max( 7, min( 730, (int) $v ) ) );

		add_settings_section( 'plseo_aeo_crawlers', __( 'AI crawler matrix', 'perrylabs-seo' ), array( __CLASS__, 'render_ai_matrix_section' ), $page );
		// The matrix renders as a single composite field for usability.
		add_settings_field( 'ai_crawlers', '', array( __CLASS__, 'render_ai_matrix_field' ), $page, 'plseo_aeo_crawlers' );
		PLSEO_Options::register_sanitizer( 'ai_crawlers', static function ( $v ) {
			$out = array();
			foreach ( (array) $v as $slug => $rule ) {
				$slug = sanitize_key( (string) $slug );
				if ( ! isset( PLSEO_AI_Crawlers::CATALOG[ $slug ] ) ) {
					continue;
				}
				$out[ $slug ] = in_array( $rule, array( 'allow', 'block', 'block_training' ), true ) ? (string) $rule : 'allow';
			}
			return $out;
		} );

		add_settings_section( 'plseo_aeo_llms', __( 'llms.txt', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . sprintf(
				wp_kses_post( __( 'Live at %1$s and %2$s.', 'perrylabs-seo' ) ),
				'<a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank"><code>/llms.txt</code></a>',
				'<a href="' . esc_url( home_url( '/llms-full.txt' ) ) . '" target="_blank"><code>/llms-full.txt</code></a>'
			) . '</p>';
		}, $page );

		add_settings_field( 'llms_txt_enabled', __( 'Enable', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_aeo_llms', array( 'key' => 'llms_txt_enabled', 'inline_label' => __( 'Serve /llms.txt', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'llms_txt_enabled', static fn( $v ) => (bool) $v );

		add_settings_field( 'llms_full_enabled', __( 'Full-content variant', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_aeo_llms', array( 'key' => 'llms_full_enabled', 'inline_label' => __( 'Also serve /llms-full.txt with the entire post body', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'llms_full_enabled', static fn( $v ) => (bool) $v );

		add_settings_field( 'llms_txt_intro', __( 'Intro text', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'textarea' ), $page, 'plseo_aeo_llms', array( 'key' => 'llms_txt_intro', 'description' => __( 'One paragraph. Used as the blockquote at the top of /llms.txt.', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'llms_txt_intro', static fn( $v ) => sanitize_textarea_field( (string) $v ) );

		add_settings_field( 'llms_txt_include_post_types', __( 'Include post types', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'multicheck_post_types' ), $page, 'plseo_aeo_llms', array( 'key' => 'llms_txt_include_post_types' ) );
		PLSEO_Options::register_sanitizer( 'llms_txt_include_post_types', static fn( $v ) => array_values( array_filter( array_map( 'sanitize_key', (array) $v ) ) ) );
	}

	public static function render_ai_matrix_section(): void {
		echo '<p>' . esc_html__( 'Choose how each known AI/LLM crawler should be treated. "Block training only" exempts on-demand/search bots (e.g. PerplexityBot) while still blocking pure training crawlers (e.g. GPTBot, ClaudeBot, CCBot).', 'perrylabs-seo' ) . '</p>';
	}

	public static function render_ai_matrix_field(): void {
		$current = (array) PLSEO_Options::get( 'ai_crawlers', array() );
		echo '<table class="widefat striped plseo-ai-matrix">';
		echo '<thead><tr><th>' . esc_html__( 'Bot', 'perrylabs-seo' ) . '</th><th>' . esc_html__( 'Operator', 'perrylabs-seo' ) . '</th><th>' . esc_html__( 'Purpose', 'perrylabs-seo' ) . '</th><th>' . esc_html__( 'Policy', 'perrylabs-seo' ) . '</th></tr></thead><tbody>';
		foreach ( PLSEO_AI_Crawlers::CATALOG as $slug => $bot ) {
			$selected = (string) ( $current[ $slug ] ?? 'allow' );
			$purpose_label = array(
				'training'  => __( 'Training corpus', 'perrylabs-seo' ),
				'search'    => __( 'Answer / search index', 'perrylabs-seo' ),
				'on_demand' => __( 'On-demand fetch', 'perrylabs-seo' ),
			)[ $bot['purpose'] ] ?? $bot['purpose'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $bot['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( $bot['operator'] ) . '</td>';
			echo '<td>' . esc_html( $purpose_label ) . '</td>';
			echo '<td>';
			printf( '<select name="%s[ai_crawlers][%s]">', esc_attr( PLSEO_Options::OPTION_NAME ), esc_attr( $slug ) );
			foreach ( array(
				'allow'          => __( 'Allow', 'perrylabs-seo' ),
				'block_training' => __( 'Block training only', 'perrylabs-seo' ),
				'block'          => __( 'Block', 'perrylabs-seo' ),
			) as $val => $label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $selected, $val, false ), esc_html( $label ) );
			}
			echo '</select>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/* ───────────────────────── tools ───────────────────────── */

	private static function register_tools(): void {
		$page = self::page( 'tools' );

		add_settings_section( 'plseo_indexnow', __( 'IndexNow', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . esc_html__( 'Ping Bing and Yandex automatically when published content changes.', 'perrylabs-seo' ) . '</p>';
		}, $page );

		add_settings_field( 'indexnow_enabled', __( 'Enable IndexNow', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_indexnow', array( 'key' => 'indexnow_enabled', 'inline_label' => __( 'Submit URL changes on publish/update', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'indexnow_enabled', static fn( $v ) => (bool) $v );

		add_settings_section( 'plseo_404', __( '404 log', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'redirects_log_404s', __( 'Log 404 hits', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_404', array( 'key' => 'redirects_log_404s', 'inline_label' => __( 'Capture not-found URLs so you can redirect them', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'redirects_log_404s', static fn( $v ) => (bool) $v );

		add_settings_field( 'redirects_log_retention_days', __( '404 log retention (days)', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'number' ), $page, 'plseo_404', array( 'key' => 'redirects_log_retention_days', 'min' => 7, 'max' => 365 ) );
		PLSEO_Options::register_sanitizer( 'redirects_log_retention_days', static fn( $v ) => max( 7, min( 365, (int) $v ) ) );
	}

	/* ───────────────────────── advanced ───────────────────────── */

	private static function register_advanced(): void {
		$page = self::page( 'advanced' );

		add_settings_section( 'plseo_noindex', __( 'Noindex archives', 'perrylabs-seo' ), '__return_false', $page );
		foreach ( array(
			'noindex_archives'   => __( 'Noindex date archives', 'perrylabs-seo' ),
			'noindex_categories' => __( 'Noindex category archives', 'perrylabs-seo' ),
			'noindex_tags'       => __( 'Noindex tag archives', 'perrylabs-seo' ),
			'noindex_authors'    => __( 'Noindex author archives', 'perrylabs-seo' ),
			'noindex_search'     => __( 'Noindex search results', 'perrylabs-seo' ),
			'noindex_404'        => __( 'Noindex 404 pages', 'perrylabs-seo' ),
		) as $key => $label ) {
			add_settings_field( $key, $label, array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_noindex', array( 'key' => $key, 'inline_label' => __( 'Add noindex', 'perrylabs-seo' ) ) );
			PLSEO_Options::register_sanitizer( $key, static fn( $v ) => (bool) $v );
		}

		add_settings_section( 'plseo_robots', __( 'robots.txt', 'perrylabs-seo' ), static function (): void {
			echo '<p>' . sprintf(
				wp_kses_post( __( 'Customize the virtual robots.txt at %s. The AI crawler matrix on the AEO tab is appended automatically.', 'perrylabs-seo' ) ),
				'<a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank"><code>/robots.txt</code></a>'
			) . '</p>';
		}, $page );

		add_settings_field( 'robots_txt_custom', __( 'Custom directives', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'code_textarea' ), $page, 'plseo_robots', array( 'key' => 'robots_txt_custom', 'rows' => 8 ) );
		PLSEO_Options::register_sanitizer( 'robots_txt_custom', static fn( $v ) => sanitize_textarea_field( (string) $v ) );

		add_settings_section( 'plseo_images', __( 'Image SEO', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'image_auto_alt', __( 'Auto-alt fallback', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_images', array( 'key' => 'image_auto_alt', 'inline_label' => __( 'Synthesize alt text from attachment / parent title when empty', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'image_auto_alt', static fn( $v ) => (bool) $v );

		add_settings_field( 'image_optimize_upload_slug', __( 'Optimize upload filenames', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_images', array( 'key' => 'image_optimize_upload_slug', 'inline_label' => __( 'Rewrite image filenames to slug form on upload (e.g. DSC_4523.jpg → founders-portrait.jpg)', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'image_optimize_upload_slug', static fn( $v ) => (bool) $v );

		add_settings_section( 'plseo_misc', __( 'Misc', 'perrylabs-seo' ), '__return_false', $page );
		add_settings_field( 'remove_emoji_scripts', __( 'WP emoji scripts', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_misc', array( 'key' => 'remove_emoji_scripts', 'inline_label' => __( 'Remove from <head> (small perf win)', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'remove_emoji_scripts', static fn( $v ) => (bool) $v );

		add_settings_field( 'rest_api_enabled', __( 'REST API', 'perrylabs-seo' ), array( PLSEO_Field_Renderer::class, 'checkbox' ), $page, 'plseo_misc', array( 'key' => 'rest_api_enabled', 'inline_label' => __( 'Expose /wp-json/plseo/v1/ endpoints to authenticated users', 'perrylabs-seo' ) ) );
		PLSEO_Options::register_sanitizer( 'rest_api_enabled', static fn( $v ) => (bool) $v );
	}
}
