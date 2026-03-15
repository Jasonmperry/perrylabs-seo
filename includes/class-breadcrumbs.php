<?php
/**
 * PerryLabs SEO + AEO — Breadcrumbs
 *
 * Outputs semantic breadcrumb markup (HTML + JSON-LD).
 * Usage: perrylabs_seo_breadcrumbs() in templates.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Breadcrumbs {

	/**
	 * Render breadcrumbs.
	 *
	 * @param array $args {
	 *     Optional configuration.
	 *
	 *     @type string $wrapper_tag  Outer HTML tag. Default 'nav'.
	 *     @type string $wrapper_class CSS class for wrapper. Default 'perrylabs-seo-breadcrumbs'.
	 *     @type string $separator     Separator between items. Default ' &raquo; '.
	 *     @type string $home_label    Label for home link. Default 'Home'.
	 *     @type bool   $show_current  Whether to show current page. Default true.
	 * }
	 */
	public static function render( array $args = array() ): void {
		$defaults = array(
			'wrapper_tag'   => 'nav',
			'wrapper_class' => 'perrylabs-seo-breadcrumbs',
			'separator'     => ' <span class="perrylabs-seo-breadcrumbs__sep" aria-hidden="true">&raquo;</span> ',
			'home_label'    => __( 'Home', 'perrylabs-seo' ),
			'show_current'  => true,
		);

		$args  = wp_parse_args( $args, $defaults );
		$items = self::build_items( $args );

		if ( empty( $items ) ) {
			return;
		}

		// HTML output.
		$tag = tag_escape( $args['wrapper_tag'] );
		echo '<' . $tag . ' class="' . esc_attr( $args['wrapper_class'] ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'perrylabs-seo' ) . '">';
		echo '<ol class="perrylabs-seo-breadcrumbs__list">';

		$count = count( $items );
		foreach ( $items as $i => $item ) {
			$is_last = ( $i === $count - 1 );

			echo '<li class="perrylabs-seo-breadcrumbs__item">';

			if ( $is_last && $args['show_current'] ) {
				echo '<span class="perrylabs-seo-breadcrumbs__current" aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			} elseif ( ! $is_last && $item['url'] ) {
				echo '<a href="' . esc_url( $item['url'] ) . '" class="perrylabs-seo-breadcrumbs__link">' . esc_html( $item['name'] ) . '</a>';
			} else {
				echo '<span>' . esc_html( $item['name'] ) . '</span>';
			}

			if ( ! $is_last ) {
				echo $args['separator']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Contains allowed HTML entities.
			}

			echo '</li>';
		}

		echo '</ol>';
		echo '</' . $tag . '>';

		// JSON-LD output.
		self::render_jsonld( $items );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Build breadcrumb items
	 * ────────────────────────────────────────────────────────────── */

	private static function build_items( array $args ): array {
		$items = array();

		// Always start with Home.
		$items[] = array(
			'name' => $args['home_label'],
			'url'  => home_url( '/' ),
		);

		// Don't show breadcrumbs on the front page.
		if ( is_front_page() ) {
			return array();
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post ) {
				return $items;
			}

			// Post type archive link (for CPTs).
			$post_type = get_post_type_object( $post->post_type );
			if ( $post_type && $post_type->has_archive ) {
				$archive_url = get_post_type_archive_link( $post->post_type );
				if ( $archive_url ) {
					$items[] = array(
						'name' => $post_type->labels->name,
						'url'  => $archive_url,
					);
				}
			}

			// Category/taxonomy for articles.
			if ( $post->post_type === 'biobuzz_news' ) {
				$terms = get_the_terms( $post->ID, 'biobuzz_article_cat' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$term = $terms[0];
					$items[] = array(
						'name' => $term->name,
						'url'  => get_term_link( $term ),
					);
				}
			} elseif ( $post->post_type === 'biobuzz_event' ) {
				$terms = get_the_terms( $post->ID, 'biobuzz_event_cat' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$term = $terms[0];
					$items[] = array(
						'name' => $term->name,
						'url'  => get_term_link( $term ),
					);
				}
			} elseif ( $post->post_type === 'post' ) {
				$categories = get_the_category( $post->ID );
				if ( ! empty( $categories ) ) {
					// Use primary category (first one).
					$items[] = array(
						'name' => $categories[0]->name,
						'url'  => get_category_link( $categories[0]->term_id ),
					);
				}
			}

			// Page hierarchy (for pages with parent).
			if ( $post->post_type === 'page' && $post->post_parent ) {
				$ancestors = array_reverse( get_post_ancestors( $post->ID ) );
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor = get_post( $ancestor_id );
					if ( $ancestor ) {
						$items[] = array(
							'name' => $ancestor->post_title,
							'url'  => get_permalink( $ancestor ),
						);
					}
				}
			}

			// Current page.
			$items[] = array(
				'name' => $post->post_title,
				'url'  => get_permalink( $post ),
			);

		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				// Parent terms.
				if ( $term->parent ) {
					$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
					foreach ( $ancestors as $ancestor_id ) {
						$ancestor = get_term( $ancestor_id, $term->taxonomy );
						if ( $ancestor && ! is_wp_error( $ancestor ) ) {
							$items[] = array(
								'name' => $ancestor->name,
								'url'  => get_term_link( $ancestor ),
							);
						}
					}
				}

				$items[] = array(
					'name' => $term->name,
					'url'  => get_term_link( $term ),
				);
			}

		} elseif ( is_post_type_archive() ) {
			$post_type = get_queried_object();
			if ( $post_type ) {
				$items[] = array(
					'name' => $post_type->labels->name,
					'url'  => get_post_type_archive_link( $post_type->name ),
				);
			}

		} elseif ( is_date() ) {
			if ( is_year() ) {
				$items[] = array(
					'name' => get_the_date( 'Y' ),
					'url'  => '',
				);
			} elseif ( is_month() ) {
				$items[] = array(
					'name' => get_the_date( 'Y' ),
					'url'  => get_year_link( get_the_date( 'Y' ) ),
				);
				$items[] = array(
					'name' => get_the_date( 'F' ),
					'url'  => '',
				);
			} elseif ( is_day() ) {
				$items[] = array(
					'name' => get_the_date( 'Y' ),
					'url'  => get_year_link( get_the_date( 'Y' ) ),
				);
				$items[] = array(
					'name' => get_the_date( 'F' ),
					'url'  => get_month_link( get_the_date( 'Y' ), get_the_date( 'm' ) ),
				);
				$items[] = array(
					'name' => get_the_date( 'j' ),
					'url'  => '',
				);
			}

		} elseif ( is_author() ) {
			$author = get_queried_object();
			if ( $author ) {
				$items[] = array(
					'name' => $author->display_name,
					'url'  => '',
				);
			}

		} elseif ( is_search() ) {
			$items[] = array(
				'name' => sprintf(
					/* translators: %s: search query */
					__( 'Search: %s', 'perrylabs-seo' ),
					get_search_query()
				),
				'url' => '',
			);

		} elseif ( is_404() ) {
			$items[] = array(
				'name' => __( 'Page Not Found', 'perrylabs-seo' ),
				'url'  => '',
			);
		}

		return $items;
	}

	/* ──────────────────────────────────────────────────────────────
	 * JSON-LD breadcrumb schema
	 * ────────────────────────────────────────────────────────────── */

	private static function render_jsonld( array $items ): void {
		if ( count( $items ) < 2 ) {
			return;
		}

		$list_items = array();
		foreach ( $items as $position => $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'name'     => $item['name'],
			);

			if ( $item['url'] ) {
				$entry['item'] = $item['url'];
			}

			$list_items[] = $entry;
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list_items,
		);

		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo "\n</script>\n";
	}
}
