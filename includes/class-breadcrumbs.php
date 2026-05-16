<?php
/**
 * PLSEO_Breadcrumbs — semantic breadcrumb trail + BreadcrumbList JSON-LD node.
 *
 * Theme integration: <?php plseo_breadcrumbs(); ?>
 *
 * Always also contributes a node to the schema @graph, so even themes that
 * don't render the visual breadcrumb still get the structured-data benefit.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Breadcrumbs {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		// Register schema contributor lazily so other modules have already booted.
		add_action( 'plugins_loaded', function (): void {
			PLSEO_Schema_Graph::instance()->register_contributor(
				'breadcrumbs',
				array( $this, 'schema_node' ),
				12
			);
		}, 20 );
	}

	/**
	 * Render the trail as HTML.
	 *
	 * @param array<string,mixed> $args  Overrides: 'separator', 'home_label', 'class'.
	 */
	public function render( array $args = array() ): void {
		$args = wp_parse_args( $args, array(
			'separator'  => '<span class="plseo-crumb-sep" aria-hidden="true">›</span>',
			'home_label' => __( 'Home', 'perrylabs-seo' ),
			'class'      => 'plseo-breadcrumbs',
		) );

		$crumbs = $this->build_trail( (string) $args['home_label'] );
		if ( empty( $crumbs ) ) {
			return;
		}

		printf( '<nav class="%s" aria-label="%s"><ol>', esc_attr( (string) $args['class'] ), esc_attr__( 'Breadcrumb', 'perrylabs-seo' ) );
		$last = count( $crumbs ) - 1;
		foreach ( $crumbs as $i => $c ) {
			echo '<li>';
			if ( $i < $last && ! empty( $c['url'] ) ) {
				printf( '<a href="%s">%s</a>', esc_url( (string) $c['url'] ), esc_html( (string) $c['name'] ) );
			} else {
				printf( '<span aria-current="page">%s</span>', esc_html( (string) $c['name'] ) );
			}
			if ( $i < $last ) {
				echo ' ' . wp_kses_post( (string) $args['separator'] ) . ' ';
			}
			echo '</li>';
		}
		echo '</ol></nav>';
	}

	/**
	 * BreadcrumbList @graph node.
	 *
	 * @return array<string,mixed>|null
	 */
	public function schema_node( ?\WP_Post $post ): ?array {
		$crumbs = $this->build_trail( (string) __( 'Home', 'perrylabs-seo' ) );
		if ( count( $crumbs ) < 2 ) {
			return null;
		}
		$items = array();
		foreach ( $crumbs as $i => $c ) {
			$items[] = array_filter( array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => (string) $c['name'],
				'item'     => ! empty( $c['url'] ) ? (string) $c['url'] : null,
			) );
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => PLSEO_Schema_Graph::current_url() . '#breadcrumbs',
			'itemListElement' => $items,
		);
	}

	/**
	 * Build the active trail based on the current query context.
	 *
	 * @return array<int,array{name:string,url?:string}>
	 */
	private function build_trail( string $home_label ): array {
		$crumbs = array(
			array(
				'name' => $home_label,
				'url'  => home_url( '/' ),
			),
		);

		if ( is_front_page() ) {
			return $crumbs;
		}

		if ( is_search() ) {
			$crumbs[] = array(
				'name' => sprintf( __( 'Search: "%s"', 'perrylabs-seo' ), get_search_query() ),
			);
			return $crumbs;
		}

		if ( is_404() ) {
			$crumbs[] = array( 'name' => __( 'Page not found', 'perrylabs-seo' ) );
			return $crumbs;
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				// Ancestors for hierarchical types.
				if ( is_post_type_hierarchical( $post->post_type ) ) {
					$ancestors = array_reverse( get_post_ancestors( $post ) );
					foreach ( $ancestors as $aid ) {
						$crumbs[] = array(
							'name' => (string) get_the_title( $aid ),
							'url'  => (string) get_permalink( $aid ),
						);
					}
				} elseif ( 'post' === $post->post_type ) {
					$cats = get_the_category( $post->ID );
					if ( ! empty( $cats ) ) {
						$primary = $cats[0];
						$crumbs[] = array(
							'name' => (string) $primary->name,
							'url'  => (string) get_category_link( $primary->term_id ),
						);
					}
				}
				$crumbs[] = array( 'name' => (string) get_the_title( $post ) );
			}
			return $crumbs;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				if ( $term->parent ) {
					foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) ) as $aid ) {
						$ancestor = get_term( $aid, $term->taxonomy );
						if ( $ancestor instanceof \WP_Term ) {
							$crumbs[] = array(
								'name' => $ancestor->name,
								'url'  => (string) get_term_link( $ancestor ),
							);
						}
					}
				}
				$crumbs[] = array( 'name' => (string) $term->name );
			}
			return $crumbs;
		}

		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof \WP_User ) {
				$crumbs[] = array( 'name' => (string) $user->display_name );
			}
			return $crumbs;
		}

		if ( is_date() ) {
			if ( is_year() ) {
				$crumbs[] = array( 'name' => (string) get_the_date( 'Y' ) );
			} elseif ( is_month() ) {
				$crumbs[] = array( 'name' => (string) get_the_date( 'F Y' ) );
			} elseif ( is_day() ) {
				$crumbs[] = array( 'name' => (string) get_the_date() );
			}
			return $crumbs;
		}

		if ( is_post_type_archive() ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->labels->name ) ) {
				$crumbs[] = array( 'name' => (string) $obj->labels->name );
			}
			return $crumbs;
		}

		return $crumbs;
	}
}
