<?php
/**
 * PLSEO_Schema_Test_Screen — render the @graph that would emit for any post.
 *
 * Admin → SEO + AEO → Schema test. Pick a post (search-as-you-type), see the
 * full @graph payload as syntax-highlighted JSON plus a quick-summary tree.
 * Useful for:
 *   - Debugging why a rule didn't match
 *   - Confirming a per-post override took effect
 *   - Linking to validator.schema.org with the rendered payload
 *
 * Implementation note: we cannot just call the contributors directly because
 * the graph reads `get_queried_object()` / `is_singular()`. We bootstrap a
 * fake main-query state by setting up the queried object and the singular
 * flags, then capture the output of `PLSEO_Schema_Graph::output()` into a
 * buffer.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Schema_Test_Screen {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'perrylabs-seo' ) );
		}

		$post_id   = isset( $_GET['plseo_post'] ) ? (int) $_GET['plseo_post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post      = $post_id ? get_post( $post_id ) : null;
		$graph     = null;
		$validator = '';
		if ( $post instanceof \WP_Post ) {
			$graph = self::build_graph_for_post( $post );
			if ( $graph ) {
				$validator = 'https://validator.schema.org/#url=' . rawurlencode( (string) get_permalink( $post ) );
			}
		}
		?>
		<div class="wrap plseo-wrap">
			<?php PLSEO_Admin::page_header( __( 'Schema test', 'perrylabs-seo' ) ); ?>
			<p class="description"><?php esc_html_e( 'Render the @graph that would emit on the front-end of any post or page. Use it to confirm display rules, per-post overrides, and rich-result eligibility before you publish.', 'perrylabs-seo' ); ?></p>

			<form method="get" class="plseo-schema-test-form">
				<input type="hidden" name="page" value="plseo-schema-test" />
				<p>
					<label for="plseo_post"><?php esc_html_e( 'Post ID', 'perrylabs-seo' ); ?></label>
					<input type="number" id="plseo_post" name="plseo_post" value="<?php echo esc_attr( (string) $post_id ); ?>" min="1" class="small-text" />
					<button class="button button-primary"><?php esc_html_e( 'Render', 'perrylabs-seo' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Tip: hover the title in the wp-admin post list to see the ID in the URL, or use the Quickedit panel.', 'perrylabs-seo' ); ?></p>
			</form>

			<?php if ( $post instanceof \WP_Post && is_array( $graph ) ) : ?>
				<div class="plseo-schema-result">
					<h2><?php
						printf(
							'%s <code>#%d</code> · <a href="%s" target="_blank">%s</a>',
							esc_html( get_the_title( $post ) ),
							(int) $post->ID,
							esc_url( (string) get_permalink( $post ) ),
							esc_html__( 'view on site', 'perrylabs-seo' )
						);
					?></h2>

					<p>
						<a href="<?php echo esc_url( $validator ); ?>" target="_blank" class="button">
							<?php esc_html_e( 'Validate at validator.schema.org →', 'perrylabs-seo' ); ?>
						</a>
					</p>

					<h3><?php esc_html_e( 'Node summary', 'perrylabs-seo' ); ?></h3>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( '@type', 'perrylabs-seo' ); ?></th><th><?php esc_html_e( '@id', 'perrylabs-seo' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $graph['@graph'] as $node ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) ( $node['@type'] ?? '?' ) ); ?></code></td>
									<td><code><?php echo esc_html( (string) ( $node['@id'] ?? '' ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<h3><?php esc_html_e( 'Full JSON-LD', 'perrylabs-seo' ); ?></h3>
					<pre class="plseo-schema-json"><?php echo esc_html( (string) wp_json_encode( $graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				</div>
			<?php elseif ( $post_id ) : ?>
				<div class="notice notice-error inline"><p><?php
					if ( ! $post instanceof \WP_Post ) {
						esc_html_e( 'No post found with that ID.', 'perrylabs-seo' );
					} else {
						esc_html_e( 'Schema graph emitted no nodes for this post. Check whether the per-post schema_type is set to "none".', 'perrylabs-seo' );
					}
				?></p></div>
			<?php endif; ?>

			<?php PLSEO_Admin::page_footer(); ?>
		</div>
		<style>
			.plseo-schema-json {
				background: #1d2327;
				color: #e6e9eb;
				padding: 16px;
				border-radius: 4px;
				max-height: 600px;
				overflow: auto;
				font-size: 12px;
				line-height: 1.45;
			}
		</style>
		<?php
	}

	/**
	 * Bootstrap a fake "singular" query for $post, walk the contributors, return
	 * the would-be @graph payload. Wraps the operation in a try/finally so we
	 * always restore the original query state.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function build_graph_for_post( \WP_Post $post ): ?array {
		global $wp_query;
		// Reference the global $post separately — PHP's `global` statement
		// doesn't support aliasing.
		$globals_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$prev_query   = $wp_query;
		$prev_post    = $globals_post;

		$wp_query                     = new \WP_Query();
		$wp_query->is_singular        = true;
		$wp_query->is_single          = ( 'post' === $post->post_type );
		$wp_query->is_page            = ( 'page' === $post->post_type );
		$wp_query->queried_object     = $post;
		$wp_query->queried_object_id  = (int) $post->ID;
		$wp_query->post               = $post;
		$wp_query->posts              = array( $post );
		$GLOBALS['post']              = $post;

		try {
			$nodes        = array();
			$contributors = self::collect_contributors();
			foreach ( $contributors as $c ) {
				$result = call_user_func( $c['callback'], $post );
				if ( empty( $result ) ) {
					continue;
				}
				if ( isset( $result[0] ) && is_array( $result[0] ) ) {
					foreach ( $result as $n ) {
						if ( is_array( $n ) ) {
							$nodes[] = $n;
						}
					}
				} elseif ( is_array( $result ) ) {
					$nodes[] = $result;
				}
			}

			// Dedupe by @id (last writer wins).
			$by_id = array();
			$loose = array();
			foreach ( $nodes as $n ) {
				if ( ! empty( $n['@id'] ) ) {
					$by_id[ (string) $n['@id'] ] = $n;
				} else {
					$loose[] = $n;
				}
			}
			$graph = array_merge( array_values( $by_id ), $loose );
			$graph = (array) apply_filters( 'plseo_schema_graph', $graph, $post );

			if ( empty( $graph ) ) {
				return null;
			}
			return array( '@context' => 'https://schema.org', '@graph' => $graph );
		} finally {
			$wp_query        = $prev_query;
			$GLOBALS['post'] = $prev_post;
		}
	}

	/**
	 * Reach into PLSEO_Schema_Graph to get its contributors list. The class
	 * keeps that private; we use reflection rather than expanding the public
	 * API just for one admin tool.
	 *
	 * @return array<int,array{id:string,callback:callable,priority:int}>
	 */
	private static function collect_contributors(): array {
		$graph = PLSEO_Schema_Graph::instance();
		$ref   = new \ReflectionObject( $graph );
		if ( ! $ref->hasProperty( 'contributors' ) ) {
			return array();
		}
		$prop = $ref->getProperty( 'contributors' );
		$prop->setAccessible( true );
		$list = (array) $prop->getValue( $graph );
		usort( $list, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		return $list;
	}
}
