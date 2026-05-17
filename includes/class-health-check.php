<?php
/**
 * PLSEO_Health_Check — plugin-level self-audit ("is the install configured right?").
 *
 * The site-audit module (PLSEO_Audit) checks per-post content. This module
 * checks per-site configuration — the meta-audit of the plugin itself.
 *
 * Surfaces things like:
 *   - business_name unset
 *   - business_logo unset (blocks Google rich-result eligibility)
 *   - No social URLs set (sameAs in Organization schema will be empty)
 *   - AI crawler matrix entirely empty or entirely allow (rarely intentional)
 *   - No analytics provider configured
 *   - llms.txt enabled but no intro text
 *   - IndexNow toggled off (most sites should enable it)
 *   - Default social image not set (OG image generator handles fallback, but
 *     a real image is better than the auto-generated card)
 *
 * Each finding has a severity (warn / notice) and a direct "Fix this" link to
 * the relevant tab.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Health_Check {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Run every check, return findings.
	 *
	 * @return array<int,array{id:string,severity:string,label:string,detail:string,fix:string}>
	 */
	public function run(): array {
		$out = array();
		$tab = static fn( string $tab ): string => admin_url( 'admin.php?page=plseo&tab=' . $tab );

		// Business identity completeness.
		$biz_name = trim( (string) PLSEO_Options::get( 'business_name', '' ) );
		if ( '' === $biz_name ) {
			$out[] = $this->row( 'business_name_empty', 'warn',
				__( 'Business / brand name is empty', 'perrylabs-seo' ),
				__( 'The Organization schema node falls back to the WordPress site title. Setting an explicit business name lets the schema graph be more precise.', 'perrylabs-seo' ),
				$tab( 'schema' )
			);
		}

		$biz_logo = trim( (string) PLSEO_Options::get( 'business_logo', '' ) );
		if ( '' === $biz_logo ) {
			$out[] = $this->row( 'business_logo_empty', 'warn',
				__( 'Organization logo is not set', 'perrylabs-seo' ),
				__( 'Google requires a logo for rich-result eligibility. Until set, structured data is technically valid but Google will not show enhanced result cards.', 'perrylabs-seo' ),
				$tab( 'schema' )
			);
		}

		// Social URLs feed Organization.sameAs.
		$social_set = 0;
		foreach ( array( 'twitter_handle', 'facebook_url', 'linkedin_url', 'instagram_url', 'youtube_url', 'mastodon_url' ) as $key ) {
			if ( '' !== trim( (string) PLSEO_Options::get( $key, '' ) ) ) {
				$social_set++;
			}
		}
		if ( 0 === $social_set ) {
			$out[] = $this->row( 'no_social_urls', 'notice',
				__( 'No social profile URLs set', 'perrylabs-seo' ),
				__( 'The Organization schema sameAs array will be empty. Setting at least LinkedIn and Twitter / X meaningfully improves entity resolution by Google.', 'perrylabs-seo' ),
				$tab( 'social' )
			);
		}

		// Default social image — OG generator covers fallback but a curated image is better.
		$default_img = trim( (string) PLSEO_Options::get( 'default_social_image', '' ) );
		if ( '' === $default_img ) {
			$out[] = $this->row( 'no_default_social_image', 'notice',
				__( 'No default social image set', 'perrylabs-seo' ),
				__( 'The OG image generator will create a brand card on the fly, but a curated 1200×630 image typically converts better on social shares.', 'perrylabs-seo' ),
				$tab( 'social' )
			);
		}

		// AI crawler matrix sanity.
		$ai_policy = (array) PLSEO_Options::get( 'ai_crawlers', array() );
		if ( empty( $ai_policy ) ) {
			$out[] = $this->row( 'ai_policy_unset', 'warn',
				__( 'AI crawler policy has not been set', 'perrylabs-seo' ),
				__( 'By default every AI crawler is allowed. Use the AEO tab or re-run the setup wizard to pick a posture (recommended: Block training only).', 'perrylabs-seo' ),
				$tab( 'aeo' )
			);
		} elseif ( count( array_unique( $ai_policy ) ) === 1 && reset( $ai_policy ) === 'allow' ) {
			$out[] = $this->row( 'ai_policy_all_allow', 'notice',
				__( 'AI crawler policy is set to "allow everything"', 'perrylabs-seo' ),
				__( 'This is a valid choice for maximum AI visibility, but uncommon. Most sites block training-only bots while allowing search/on-demand bots.', 'perrylabs-seo' ),
				$tab( 'aeo' )
			);
		}

		// Analytics — none configured is fine but worth flagging.
		$analytics_set = 0;
		foreach ( array( 'analytics_ga4_id', 'analytics_gtm_id', 'analytics_plausible_domain', 'analytics_fathom_site_id', 'analytics_clarity_id' ) as $key ) {
			if ( '' !== trim( (string) PLSEO_Options::get( $key, '' ) ) ) {
				$analytics_set++;
			}
		}
		if ( 0 === $analytics_set ) {
			$out[] = $this->row( 'no_analytics', 'notice',
				__( 'No analytics provider configured', 'perrylabs-seo' ),
				__( 'Without a measurement tag wired up, you cannot tell which SEO work is paying off. Plausible or Fathom add no consent burden.', 'perrylabs-seo' ),
				$tab( 'analytics' )
			);
		}

		// llms.txt configuration.
		if ( (bool) PLSEO_Options::get( 'llms_txt_enabled', true ) ) {
			$intro = trim( (string) PLSEO_Options::get( 'llms_txt_intro', '' ) );
			if ( '' === $intro ) {
				$out[] = $this->row( 'llms_txt_no_intro', 'notice',
					__( '/llms.txt is served with no custom intro', 'perrylabs-seo' ),
					__( 'It falls back to the site tagline. A two-sentence intro tailored to AI consumers (what the site IS and what AI should cite it for) is more useful.', 'perrylabs-seo' ),
					$tab( 'aeo' )
				);
			}
		}

		// IndexNow toggle.
		if ( ! (bool) PLSEO_Options::get( 'indexnow_enabled', false ) ) {
			$out[] = $this->row( 'indexnow_off', 'notice',
				__( 'IndexNow is disabled', 'perrylabs-seo' ),
				__( 'IndexNow pings Bing and Yandex when a post is published or updated. Free, instant indexing — most sites should enable it.', 'perrylabs-seo' ),
				$tab( 'tools' )
			);
		}

		// Verification codes.
		$verif_set = 0;
		foreach ( array( 'google_verification', 'bing_verification' ) as $key ) {
			if ( '' !== trim( (string) PLSEO_Options::get( $key, '' ) ) ) {
				$verif_set++;
			}
		}
		if ( 0 === $verif_set ) {
			$out[] = $this->row( 'no_search_console', 'notice',
				__( 'No Google or Bing webmaster verification set', 'perrylabs-seo' ),
				__( 'Without Search Console and Bing Webmaster verified, you cannot see what queries the site appears for or submit sitemaps directly.', 'perrylabs-seo' ),
				$tab( 'general' )
			);
		}

		return $out;
	}

	/**
	 * @return array{id:string,severity:string,label:string,detail:string,fix:string}
	 */
	private function row( string $id, string $severity, string $label, string $detail, string $fix ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'label'    => $label,
			'detail'   => $detail,
			'fix'      => $fix,
		);
	}
}
