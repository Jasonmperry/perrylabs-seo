<?php
/**
 * PLSEO_Compat — runtime compatibility checks + admin warnings.
 *
 * Surfaces the few situations where this plugin sharing a site with another
 * SEO plugin (or running on an environment we don't fully support) is going
 * to produce a poor result. Most checks are admin-only — the public site
 * keeps working.
 *
 * Specifically:
 *   - Detect Yoast SEO active alongside us → both will emit JSON-LD + meta
 *     tags. Show a dismissable admin notice pointing at the importer.
 *   - Same for RankMath.
 *   - Same for All in One SEO (AIOSEO).
 *   - Detect PHP version below the actual minimum (8.0). The plugin header
 *     advertises 8.0 so WP itself blocks activation on older PHP — but if
 *     someone copies files in manually and bypasses the activation check,
 *     warn them too.
 *
 * Dismissals are per-user via a user-meta key so each editor has their own.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Compat {

	private static ?self $instance = null;

	private const MIN_PHP = '8.0';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'admin_notices',                array( $this, 'render_notices' ) );
		add_action( 'admin_post_plseo_dismiss_notice', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Conflict detection — runs only on admin requests, only for users who
	 * can manage the site, and only when we haven't already been dismissed.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( $this->collect_issues() as $key => $issue ) {
			if ( $this->is_dismissed( (string) $key ) ) {
				continue;
			}
			$dismiss_url = wp_nonce_url(
				add_query_arg( array( 'action' => 'plseo_dismiss_notice', 'k' => $key ), admin_url( 'admin-post.php' ) ),
				'plseo_dismiss_' . $key
			);
			printf(
				'<div class="notice notice-%s"><p>%s%s</p></div>',
				esc_attr( (string) $issue['level'] ),
				wp_kses_post( (string) $issue['html'] ),
				$issue['dismissable']
					? ' <a href="' . esc_url( $dismiss_url ) . '" style="float:right">' . esc_html__( 'Dismiss', 'perrylabs-seo' ) . '</a>'
					: ''
			);
		}
	}

	/**
	 * @return array<string,array{level:string,html:string,dismissable:bool}>
	 */
	private function collect_issues(): array {
		$out = array();

		// PHP version below stated minimum. Only fires when somehow loaded
		// despite WP's own check (e.g. copy-in install bypassing activate).
		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$out['php_too_old'] = array(
				'level'       => 'error',
				'html'        => sprintf(
					/* translators: %1$s required, %2$s actual */
					__( '<strong>PerryLabs SEO + AEO:</strong> requires PHP %1$s or higher. This server is running PHP %2$s. The plugin will not function correctly until PHP is upgraded.', 'perrylabs-seo' ),
					self::MIN_PHP,
					PHP_VERSION
				),
				'dismissable' => false,
			);
		}

		// Yoast SEO.
		if ( $this->is_active( 'wordpress-seo/wp-seo.php' ) || defined( 'WPSEO_VERSION' ) ) {
			$out['conflict_yoast'] = $this->conflict_notice( 'Yoast SEO', 'plseo-import' );
		}

		// RankMath.
		if ( $this->is_active( 'seo-by-rank-math/rank-math.php' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$out['conflict_rankmath'] = $this->conflict_notice( 'RankMath SEO', 'plseo-import' );
		}

		// All in One SEO.
		if ( $this->is_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) || defined( 'AIOSEO_VERSION' ) ) {
			$out['conflict_aioseo'] = array(
				'level'       => 'warning',
				'html'        => sprintf(
					/* translators: %s plugin name */
					__( '<strong>PerryLabs SEO + AEO:</strong> %s is active. Both plugins will emit meta tags and JSON-LD, which can produce conflicting signals. Deactivate one of them.', 'perrylabs-seo' ),
					'All in One SEO'
				),
				'dismissable' => true,
			);
		}

		return $out;
	}

	/**
	 * @return array{level:string,html:string,dismissable:bool}
	 */
	private function conflict_notice( string $plugin_name, string $import_screen_slug ): array {
		return array(
			'level'       => 'warning',
			'html'        => sprintf(
				/* translators: 1: other plugin name, 2: import screen URL */
				__( '<strong>PerryLabs SEO + AEO:</strong> %1$s is active. Both plugins will emit meta tags and JSON-LD, which can produce conflicting signals. <a href="%2$s">Import its data and then deactivate %1$s →</a>', 'perrylabs-seo' ),
				esc_html( $plugin_name ),
				esc_url( admin_url( 'admin.php?page=' . $import_screen_slug ) )
			),
			'dismissable' => true,
		);
	}

	private function is_active( string $plugin_file ): bool {
		// Use the canonical helper when loaded; otherwise look at the active_plugins option.
		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( $plugin_file );
		}
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( $plugin_file, $active, true );
	}

	private function is_dismissed( string $key ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, 'plseo_notice_dismissed_' . $key, true );
	}

	public function handle_dismiss(): void {
		$key = isset( $_GET['k'] ) ? sanitize_key( wp_unslash( (string) $_GET['k'] ) ) : '';
		check_admin_referer( 'plseo_dismiss_' . $key );
		if ( '' === $key ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url() );
			exit;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, 'plseo_notice_dismissed_' . $key, 1 );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
