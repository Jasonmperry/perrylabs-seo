<?php
/**
 * PLSEO_Options — single source of truth for plugin settings.
 *
 * One option row (`plseo_options`) holds everything. Modules read via PLSEO_Options::get( $key )
 * and never call get_option() directly. Sanitization is registered per key so tabs stay thin.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Options {

	public const OPTION_NAME  = 'plseo_options';
	public const OPTION_GROUP = 'plseo_options_group';

	/**
	 * Sanitizer registry. Populated lazily so tabs can append.
	 *
	 * @var array<string,callable>
	 */
	private static array $sanitizers = array();

	/**
	 * In-process cache so PLSEO_Options::get() inside a request stays O(1).
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values for every option key. Tabs reference this both for rendering
	 * fallback values and for the activation seed.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// ── General ───────────────────────────────────────────
			'title_template_post'    => '%post_title% %sep% %site_name%',
			'title_template_page'    => '%post_title% %sep% %site_name%',
			'title_template_archive' => '%archive_title% %sep% %site_name%',
			'title_template_home'    => '%site_name% %sep% %site_tagline%',
			'title_separator'        => '|',
			'default_description'    => '',
			'site_language'          => '',
			'google_verification'    => '',
			'bing_verification'      => '',
			'pinterest_verification' => '',
			'yandex_verification'    => '',
			'baidu_verification'     => '',

			// ── Social ────────────────────────────────────────────
			'twitter_handle'         => '',
			'twitter_card_type'      => 'summary_large_image',
			'facebook_url'           => '',
			'linkedin_url'           => '',
			'instagram_url'          => '',
			'youtube_url'            => '',
			'github_url'             => '',
			'mastodon_url'           => '',
			'default_social_image'   => '',
			'og_locale'              => '',

			// ── Sitemap ───────────────────────────────────────────
			'sitemap_enabled'        => true,
			'sitemap_post_types'     => array( 'post', 'page' ),
			'sitemap_taxonomies'     => array( 'category', 'post_tag' ),
			'sitemap_include_images' => true,
			'sitemap_include_videos' => false,
			'sitemap_news_enabled'   => false,
			'sitemap_news_post_types'  => array( 'post' ),
			'sitemap_news_publication' => '',
			'sitemap_video_enabled'  => false,
			'sitemap_exclude_ids'    => array(),

			// ── Schema / Business ─────────────────────────────────
			'business_name'           => '',
			'business_type'           => 'Organization',
			'business_legal_name'     => '',
			'business_logo'           => '',
			'business_street_address' => '',
			'business_city'           => '',
			'business_state'          => '',
			'business_postal_code'    => '',
			'business_country'        => '',
			'business_phone'          => '',
			'business_email'          => '',
			'business_founding_date'  => '',
			'business_price_range'    => '',
			'business_hours'          => '',
			'schema_type_map'         => array(
				'post' => 'Article',
				'page' => 'WebPage',
			),
			'schema_rules'            => array(),

			// ── Redirects ─────────────────────────────────────────
			'redirects_log_404s'     => true,
			'redirects_log_retention_days' => 60,

			// ── AEO ──────────────────────────────────────────────
			'aeo_speakable_selectors'   => array( '.speakable', '[data-speakable]' ),
			'aeo_faq_autodetect'        => true,
			'aeo_howto_autodetect'      => true,
			'aeo_quick_answer_field'    => 'first_paragraph',
			'aeo_log_ai_visits'         => true,
			'aeo_ai_visit_retention_days' => 90,
			'aeo_eeat_default_author_id'  => 0,

			// ── llms.txt ──────────────────────────────────────────
			'llms_txt_enabled'       => true,
			'llms_txt_intro'         => '',
			'llms_txt_featured_ids'  => array(),
			'llms_txt_include_post_types' => array( 'post', 'page' ),
			'llms_full_enabled'      => true,

			// ── Robots.txt ────────────────────────────────────────
			'robots_txt_custom'      => '',

			// ── IndexNow ──────────────────────────────────────────
			'indexnow_enabled'       => false,
			'indexnow_engines'       => array( 'bing', 'yandex' ),

			// ── Image SEO ──────────────────────────────────────────
			'image_auto_alt'             => true,
			'image_optimize_upload_slug' => false,

			// ── Advanced ──────────────────────────────────────────
			'noindex_archives'       => false,
			'noindex_categories'     => false,
			'noindex_tags'           => true,
			'noindex_authors'        => true,
			'noindex_search'         => true,
			'noindex_404'            => true,
			'remove_emoji_scripts'   => false,
			'rest_api_enabled'       => true,

			// ── Internal ──────────────────────────────────────────
			'_first_install_at'      => 0,
			'_schema_version'        => 2,
		);
	}

	/**
	 * Register a sanitizer for one option key. Called by each tab during boot.
	 *
	 * @param string   $key       Option key.
	 * @param callable $sanitizer Receives the raw input, returns sanitized value.
	 */
	public static function register_sanitizer( string $key, callable $sanitizer ): void {
		self::$sanitizers[ $key ] = $sanitizer;
	}

	/**
	 * Read one key with a typed fallback.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Returned when the key is missing.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = '' ): mixed {
		if ( null === self::$cache ) {
			$saved        = get_option( self::OPTION_NAME, array() );
			self::$cache  = is_array( $saved ) ? array_merge( self::defaults(), $saved ) : self::defaults();
		}
		return self::$cache[ $key ] ?? $default;
	}

	/**
	 * Read everything as a single array.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			self::get( '_schema_version' ); // populate cache
		}
		return self::$cache ?? array();
	}

	/**
	 * Persist one or more values. Bypasses Settings API; use sparingly
	 * (mostly from migrations, CLI, REST endpoints).
	 *
	 * @param array<string,mixed> $values
	 */
	public static function update( array $values ): void {
		$current        = self::all();
		$next           = array_merge( $current, $values );
		self::$cache    = $next;
		update_option( self::OPTION_NAME, $next );
	}

	/**
	 * Sanitize-route an incoming Settings API submission.
	 *
	 * Any key without a registered sanitizer falls back to wp_strip_all_tags
	 * for scalars and a no-op for arrays — defensive, never silently drops values.
	 *
	 * @param mixed $input Raw POSTed input from options.php.
	 * @return array<string,mixed>
	 */
	public static function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::all();
		}

		$current  = self::all();
		$defaults = self::defaults();
		$result   = $current;

		// Iterate defaults so we don't accept unknown keys (basic schema enforcement).
		foreach ( $defaults as $key => $default_value ) {
			$present = array_key_exists( $key, $input );

			// Checkboxes drop off when unchecked. For any bool default, "missing" means false —
			// regardless of whether a sanitizer is registered. Otherwise checkboxes are
			// unflippable once set to true. This is the canonical Settings-API trap.
			if ( ! $present ) {
				if ( is_bool( $default_value ) ) {
					$result[ $key ] = false;
				}
				// Non-bool keys keep their existing value when absent from the submission.
				continue;
			}

			if ( isset( self::$sanitizers[ $key ] ) ) {
				$result[ $key ] = call_user_func( self::$sanitizers[ $key ], $input[ $key ], $current[ $key ] ?? $default_value );
			} elseif ( is_array( $default_value ) ) {
				$result[ $key ] = is_array( $input[ $key ] ) ? $input[ $key ] : array();
			} else {
				$result[ $key ] = wp_strip_all_tags( (string) $input[ $key ] );
			}
		}

		// Preserve internal keys regardless of input.
		$result['_first_install_at'] = $current['_first_install_at'] ?? time();
		$result['_schema_version']   = $defaults['_schema_version'];

		self::$cache = $result;
		return $result;
	}

	/** Clear the in-process cache (after CLI/REST writes). */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
