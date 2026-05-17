<?php
/**
 * PLSEO_Robots_Preview_Screen — admin viewer for the live /robots.txt output.
 *
 * Renders exactly what WordPress would serve at /robots.txt right now, with
 * any toggles you flip on the AEO crawler matrix or the Advanced custom
 * directives reflected immediately. Saves the round trip of opening the
 * front-end and view-sourcing.
 *
 * Also shows the AEO endpoint URLs (sitemap, llms.txt, IndexNow key) for
 * quick copy-to-clipboard during Search-Console setup.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Robots_Preview_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}
		$content = self::build_output();
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Robots.txt + endpoint preview', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'Live preview of the robots.txt the site would serve right now, plus the canonical URLs for every public AEO/SEO endpoint.', 'perrylabs-seo' ); ?></p>

			<h2><?php esc_html_e( 'Live /robots.txt', 'perrylabs-seo' ); ?></h2>
			<pre class="plseo-robots-pre"><?php echo esc_html( $content ); ?></pre>
			<p>
				<a class="button" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank">
					<?php esc_html_e( 'Open /robots.txt in new tab', 'perrylabs-seo' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plseo&tab=aeo' ) ); ?>">
					<?php esc_html_e( 'Edit AI crawler matrix', 'perrylabs-seo' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=plseo&tab=advanced' ) ); ?>">
					<?php esc_html_e( 'Edit custom directives', 'perrylabs-seo' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Public endpoints', 'perrylabs-seo' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Endpoint', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'URL', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( 'Use', 'perrylabs-seo' ); ?></th></tr></thead>
				<tbody>
					<?php
					$endpoints = array(
						array( __( 'Sitemap index', 'perrylabs-seo' ),    home_url( '/sitemap.xml' ),           __( 'Submit to Google Search Console & Bing Webmaster.', 'perrylabs-seo' ) ),
						array( __( 'News sitemap',  'perrylabs-seo' ),    home_url( '/sitemap-news.xml' ),      __( 'Google News spec. Only included when enabled on the Sitemap tab.', 'perrylabs-seo' ) ),
						array( __( 'Video sitemap', 'perrylabs-seo' ),    home_url( '/sitemap-videos.xml' ),    __( 'Auto-detects YouTube/Vimeo/native video in posts.', 'perrylabs-seo' ) ),
						array( __( 'llms.txt',      'perrylabs-seo' ),    home_url( '/llms.txt' ),              __( 'Markdown index for AI assistants.', 'perrylabs-seo' ) ),
						array( __( 'llms-full.txt', 'perrylabs-seo' ),    home_url( '/llms-full.txt' ),         __( 'Full-content concatenation for AI assistants.', 'perrylabs-seo' ) ),
						array( __( 'IndexNow key',  'perrylabs-seo' ),    home_url( '/' . PLSEO_IndexNow::instance()->key() . '.txt' ), __( 'IndexNow verification file — Bing reads this on first ping.', 'perrylabs-seo' ) ),
						array( __( 'REST namespace','perrylabs-seo' ),    home_url( '/wp-json/plseo/v1/' ),     __( 'REST API base. See /wp-json for the full route list.', 'perrylabs-seo' ) ),
					);
					foreach ( $endpoints as $row ) :
					?>
						<tr>
							<td><strong><?php echo esc_html( $row[0] ); ?></strong></td>
							<td><a href="<?php echo esc_url( $row[1] ); ?>" target="_blank"><code><?php echo esc_html( $row[1] ); ?></code></a></td>
							<td><?php echo esc_html( $row[2] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<style>
			.plseo-robots-pre {
				background: #1d2327;
				color: #e6e9eb;
				padding: 14px 16px;
				border-radius: 4px;
				font-size: 12px;
				line-height: 1.5;
				max-height: 500px;
				overflow: auto;
				white-space: pre;
			}
		</style>
		<?php
	}

	/**
	 * Compose what robots.txt would actually serve right now, by calling our
	 * own filter the same way WordPress would.
	 */
	private static function build_output(): string {
		// WordPress's core robots.txt template — kept small so the user sees
		// the filterable piece in isolation. We feed it through our filter.
		$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out  = apply_filters( 'robots_txt', $core, (bool) get_option( 'blog_public', '1' ) );
		return (string) $out;
	}
}
