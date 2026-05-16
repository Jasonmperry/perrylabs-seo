<?php
/**
 * PLSEO_Analytics — first-party analytics installer with consent integration.
 *
 * Five supported providers, each one a paste-in ID:
 *   - GA4 (G-XXXXX)
 *   - Google Tag Manager (GTM-XXXXX)
 *   - Plausible (data-domain)
 *   - Fathom (site ID)
 *   - Microsoft Clarity (project ID)
 *
 * If PerryLabs Cookie Notice is active (`function_exists('plcn_register_script')`),
 * the analytics snippet is registered with Cookie Monster so it loads only after
 * the visitor consents to the appropriate category (analytics or marketing).
 *
 * If Cookie Notice is not active, the snippet is injected directly into `<head>`.
 * The admin tab makes this fallback explicit so the user knows.
 *
 * Plausible and Fathom are first-party-friendly (no cookies by default) and are
 * loaded directly regardless of consent unless the user opts otherwise.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Analytics {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Don't inject into admin, feeds, REST, or for users with edit_posts (avoids
		// inflating analytics with editor traffic).
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		add_action( 'wp_head',   array( $this, 'render_head' ),   2 );
		add_action( 'wp_footer', array( $this, 'render_footer' ), 99 );
	}

	private function should_emit(): bool {
		if ( current_user_can( 'edit_posts' ) && ! (bool) PLSEO_Options::get( 'analytics_track_editors', false ) ) {
			return false;
		}
		return true;
	}

	/* ───────────────────────── head ───────────────────────── */

	public function render_head(): void {
		if ( ! $this->should_emit() ) {
			return;
		}

		$ga4 = trim( (string) PLSEO_Options::get( 'analytics_ga4_id', '' ) );
		if ( '' !== $ga4 ) {
			$this->emit_ga4( $ga4 );
		}

		$gtm = trim( (string) PLSEO_Options::get( 'analytics_gtm_id', '' ) );
		if ( '' !== $gtm ) {
			$this->emit_gtm_head( $gtm );
		}

		$plausible = trim( (string) PLSEO_Options::get( 'analytics_plausible_domain', '' ) );
		if ( '' !== $plausible ) {
			$this->emit_plausible( $plausible );
		}

		$fathom = trim( (string) PLSEO_Options::get( 'analytics_fathom_site_id', '' ) );
		if ( '' !== $fathom ) {
			$this->emit_fathom( $fathom );
		}

		$clarity = trim( (string) PLSEO_Options::get( 'analytics_clarity_id', '' ) );
		if ( '' !== $clarity ) {
			$this->emit_clarity( $clarity );
		}

		$apollo = trim( (string) PLSEO_Options::get( 'analytics_apollo_app_id', '' ) );
		if ( '' !== $apollo && $this->apollo_env_ok() ) {
			$this->emit_apollo( $apollo );
		}
	}

	/**
	 * Apollo's tracker is a sales-intent pixel — on most installs it should only
	 * fire in production. The `analytics_apollo_live_only` option gates that;
	 * when set, we look at PANTHEON_ENVIRONMENT (Pantheon-hosted sites) or
	 * WP_ENV (a common convention) before emitting.
	 */
	private function apollo_env_ok(): bool {
		if ( ! (bool) PLSEO_Options::get( 'analytics_apollo_live_only', true ) ) {
			return true;
		}
		$env = '';
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			$env = (string) constant( 'PANTHEON_ENVIRONMENT' );
		} elseif ( defined( 'WP_ENV' ) ) {
			$env = (string) constant( 'WP_ENV' );
		}
		return '' === $env || in_array( $env, array( 'live', 'production', 'prod' ), true );
	}

	public function render_footer(): void {
		// GTM noscript belongs in <body> per Google's spec; the closest hook we
		// have without theme support is wp_footer.
		if ( ! $this->should_emit() ) {
			return;
		}
		$gtm = trim( (string) PLSEO_Options::get( 'analytics_gtm_id', '' ) );
		if ( '' === $gtm ) {
			return;
		}
		printf(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
			esc_attr( $gtm )
		);
	}

	/* ───────────────────────── providers ───────────────────────── */

	private function emit_ga4( string $id ): void {
		$src    = sprintf( 'https://www.googletagmanager.com/gtag/js?id=%s', rawurlencode( $id ) );
		$inline = sprintf(
			"window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',%s);",
			wp_json_encode( $id )
		);
		$this->emit_or_gate( 'ga4', 'analytics', $src, $inline, array( 'async' => true ) );
	}

	private function emit_gtm_head( string $id ): void {
		$inline = sprintf(
			"(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',%s);",
			wp_json_encode( $id )
		);
		$this->emit_or_gate( 'gtm', 'analytics', '', $inline, array() );
	}

	private function emit_plausible( string $domain ): void {
		$src = 'https://plausible.io/js/script.js';
		// Plausible is cookieless — no consent required by default but the
		// user can opt to gate it via filter.
		$attrs = array( 'data-domain' => $domain, 'defer' => true );
		$gated = (bool) apply_filters( 'plseo_analytics_gate_plausible', false );
		if ( $gated ) {
			$this->emit_or_gate( 'plausible', 'analytics', $src, '', $attrs );
		} else {
			$this->emit_direct( $src, '', $attrs );
		}
	}

	private function emit_fathom( string $site_id ): void {
		$src   = 'https://cdn.usefathom.com/script.js';
		$attrs = array( 'data-site' => $site_id, 'defer' => true );
		$gated = (bool) apply_filters( 'plseo_analytics_gate_fathom', false );
		if ( $gated ) {
			$this->emit_or_gate( 'fathom', 'analytics', $src, '', $attrs );
		} else {
			$this->emit_direct( $src, '', $attrs );
		}
	}

	private function emit_clarity( string $id ): void {
		$inline = sprintf(
			"(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script',%s);",
			wp_json_encode( $id )
		);
		$this->emit_or_gate( 'clarity', 'analytics', '', $inline, array() );
	}

	private function emit_apollo( string $app_id ): void {
		$inline = sprintf(
			"function initApollo(){var n=Math.random().toString(36).substring(7),o=document.createElement('script');o.src='https://assets.apollo.io/micro/website-tracker/tracker.iife.js?nocache='+n;o.async=!0;o.defer=!0;o.onload=function(){window.trackingFunctions.onLoad({appId:%s})};document.head.appendChild(o);}initApollo();",
			wp_json_encode( $app_id )
		);
		// Apollo is sales-intent / contact-enrichment — categorize as marketing.
		$this->emit_or_gate( 'apollo', 'marketing', '', $inline, array() );
	}

	/* ───────────────────────── emission strategies ───────────────────────── */

	/**
	 * Inject directly into <head>. Used when no consent layer is present.
	 *
	 * @param array<string,mixed> $attrs
	 */
	private function emit_direct( string $src, string $inline, array $attrs ): void {
		if ( '' !== $src ) {
			$attr_str = '';
			foreach ( $attrs as $name => $value ) {
				if ( true === $value ) {
					$attr_str .= ' ' . esc_attr( (string) $name );
				} else {
					$attr_str .= sprintf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
				}
			}
			printf( '<script src="%s"%s></script>' . "\n", esc_url( $src ), $attr_str );
		}
		if ( '' !== $inline ) {
			echo '<script>' . $inline . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * If Cookie Monster is present, register the script with it (consent-gated).
	 * Otherwise inject directly.
	 *
	 * @param array<string,mixed> $attrs
	 */
	private function emit_or_gate( string $handle, string $category, string $src, string $inline, array $attrs ): void {
		if ( function_exists( 'plcn_register_script' ) ) {
			// Convert our attrs array to Cookie Monster's `attrs` (sequential list of attr names).
			$cm_attrs = array();
			foreach ( $attrs as $k => $v ) {
				$cm_attrs[] = true === $v ? (string) $k : sprintf( '%s="%s"', $k, $v );
			}
			plcn_register_script( 'plseo-' . $handle, array(
				'label'    => 'PerryLabs SEO · ' . strtoupper( $handle ),
				'category' => $category,
				'src'      => $src,
				'inline'   => $inline,
				'attrs'    => $cm_attrs,
				'load_in'  => 'head',
			) );
			return;
		}
		$this->emit_direct( $src, $inline, $attrs );
	}

	/* ───────────────────────── utility for the admin tab ───────────────────────── */

	public static function consent_status_text(): string {
		return function_exists( 'plcn_register_script' )
			? __( 'PerryLabs Cookie Notice is active. Analytics are gated by visitor consent.', 'perrylabs-seo' )
			: __( 'No consent layer detected. Analytics will load directly. Install PerryLabs Cookie Notice (or any consent plugin that exposes plcn_register_script) to gate them.', 'perrylabs-seo' );
	}
}
