<?php
/**
 * PLSEO_Robots_Txt — assemble robots.txt content.
 *
 * Composition (top to bottom):
 *   1. Core "User-agent: * / Disallow: /wp-admin" baseline (let WP handle this).
 *   2. AI crawler block emitted by PLSEO_AI_Crawlers.
 *   3. Sitemap reference.
 *   4. llms.txt reference (informational; AI crawlers fetch it via URL).
 *   5. User-supplied custom directives.
 *
 * Works whether or not a physical robots.txt file exists — WP serves a virtual one
 * via the `robots_txt` filter when none is present on disk.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Robots_Txt {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_filter( 'robots_txt', array( $this, 'filter' ), 100, 2 );
	}

	public function filter( string $core_output, bool $public ): string {
		// If the site is set to "discourage search engines" we don't want to override
		// WP's protective output. Add nothing.
		if ( ! $public ) {
			return $core_output;
		}

		$parts = array( rtrim( $core_output ) );

		// AI crawlers.
		$ai_block = PLSEO_AI_Crawlers::instance()->robots_block();
		if ( '' !== $ai_block ) {
			$parts[] = "# AI / LLM crawlers (managed by PerryLabs SEO + AEO)\n" . $ai_block;
		}

		// Sitemap.
		if ( (bool) PLSEO_Options::get( 'sitemap_enabled', true ) ) {
			$parts[] = 'Sitemap: ' . home_url( '/sitemap.xml' );
		}

		// llms.txt pointer (informational — robots.txt has no native llms.txt directive,
		// but pointing AI crawlers at it via a comment is a common practice).
		if ( (bool) PLSEO_Options::get( 'llms_txt_enabled', true ) ) {
			$parts[] = '# LLM-friendly summary: ' . home_url( '/llms.txt' );
		}

		// Custom user-supplied directives.
		$custom = trim( (string) PLSEO_Options::get( 'robots_txt_custom', '' ) );
		if ( '' !== $custom ) {
			$parts[] = "# Custom\n" . $custom;
		}

		return implode( "\n\n", array_filter( $parts ) ) . "\n";
	}
}
