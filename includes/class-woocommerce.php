<?php
/**
 * PLSEO_WooCommerce — Product / Offer / AggregateRating schema for WC products.
 *
 * Auto-detects WooCommerce. When active and on a `product` singular page,
 * appends a complete Product node to the @graph populated from WC's own data:
 *
 *   - name, description, SKU, brand
 *   - image (gallery + featured)
 *   - offers: price, currency, availability, priceValidUntil (per-product sale-end if set)
 *   - aggregateRating from the product's review average
 *   - review (limited to most recent 5 to keep payload small)
 *   - itemCondition fixed to NewCondition (configurable via filter)
 *
 * Also adds the WC product CPT to the schema_type_map default if not already
 * configured, so the rules engine has something to fall back to.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_WooCommerce {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		// Only wire up when WooCommerce is actually loaded.
		if ( ! class_exists( '\WC_Product' ) ) {
			return;
		}

		// Add `product` to the sitemap post types if not already present.
		add_filter( 'plseo_sitemap_post_types', array( $this, 'add_product_to_sitemap' ) );

		// Register the Product node contributor with the schema graph.
		add_action( 'plugins_loaded', function (): void {
			PLSEO_Schema_Graph::instance()->register_contributor(
				'wc-product', array( $this, 'product_node' ), 16
			);
		}, 20 );
	}

	/**
	 * @param array<int,string> $types
	 * @return array<int,string>
	 */
	public function add_product_to_sitemap( array $types ): array {
		if ( ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * Build the Product @graph node for the queried product.
	 *
	 * @return array<string,mixed>|null
	 */
	public function product_node( ?\WP_Post $post ): ?array {
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return null;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post );
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}

		$url   = PLSEO_Schema_Graph::current_url();
		$desc  = (string) ( method_exists( $product, 'get_short_description' ) ? $product->get_short_description() : '' );
		if ( '' === $desc ) {
			$desc = wp_strip_all_tags( (string) ( method_exists( $product, 'get_description' ) ? $product->get_description() : '' ) );
		}

		$node = array(
			'@type'             => 'Product',
			'@id'               => $url . '#product',
			'name'              => (string) $product->get_name(),
			'description'       => PLSEO_Str::clip( $desc, 600 ),
			'url'               => $url,
			'mainEntityOfPage'  => array( '@id' => PLSEO_Schema_Graph::webpage_id( $url ) ),
			'sku'               => (string) $product->get_sku(),
		);
		if ( '' === $node['sku'] ) {
			unset( $node['sku'] );
		}

		// Images: featured + gallery (cap to 5 to keep payload reasonable).
		$image_ids = array_filter( array_merge(
			array( (int) $product->get_image_id() ),
			(array) ( method_exists( $product, 'get_gallery_image_ids' ) ? $product->get_gallery_image_ids() : array() )
		) );
		$images = array();
		foreach ( array_slice( $image_ids, 0, 5 ) as $img_id ) {
			$src = wp_get_attachment_image_src( (int) $img_id, 'full' );
			if ( $src ) {
				$images[] = (string) $src[0];
			}
		}
		if ( ! empty( $images ) ) {
			$node['image'] = $images;
		}

		// Brand: use the configured business name as a default.
		$brand = (string) PLSEO_Options::get( 'business_name', (string) get_bloginfo( 'name' ) );
		if ( '' !== $brand ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		// Offers.
		$node['offers'] = $this->build_offer( $product, $url );

		// Aggregate rating (only if there are actual reviews).
		$review_count = method_exists( $product, 'get_review_count' ) ? (int) $product->get_review_count() : 0;
		$avg_rating   = method_exists( $product, 'get_average_rating' ) ? (float) $product->get_average_rating() : 0.0;
		if ( $review_count > 0 && $avg_rating > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $avg_rating, 2 ),
				'reviewCount' => $review_count,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}

		// Recent reviews (cap 5).
		$reviews = $this->recent_reviews( (int) $product->get_id(), 5 );
		if ( ! empty( $reviews ) ) {
			$node['review'] = $reviews;
		}

		/**
		 * Final filter so themes/plugins can decorate the Product node before emit.
		 */
		return (array) apply_filters( 'plseo_woocommerce_product_node', $node, $product, $post );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_offer( \WC_Product $product, string $url ): array {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$price    = (string) $product->get_price();
		$in_stock = method_exists( $product, 'is_in_stock' ) ? $product->is_in_stock() : true;

		$offer = array(
			'@type'         => 'Offer',
			'price'         => '' !== $price ? $price : '0',
			'priceCurrency' => $currency,
			'availability'  => 'https://schema.org/' . ( $in_stock ? 'InStock' : 'OutOfStock' ),
			'itemCondition' => (string) apply_filters( 'plseo_woocommerce_item_condition', 'https://schema.org/NewCondition' ),
			'url'           => $url,
		);

		// Sale validity window — gives Google explicit price stability info.
		$sale_to = method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to() : null;
		if ( $sale_to ) {
			$offer['priceValidUntil'] = $sale_to->date( 'Y-m-d' );
		}

		return $offer;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function recent_reviews( int $product_id, int $limit ): array {
		$comments = get_comments( array(
			'post_id'   => $product_id,
			'status'    => 'approve',
			'type'      => 'review',
			'number'    => $limit,
			'orderby'   => 'comment_date_gmt',
			'order'     => 'DESC',
		) );

		$out = array();
		foreach ( $comments as $c ) {
			if ( ! ( $c instanceof \WP_Comment ) ) {
				continue;
			}
			$rating = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			$entry  = array(
				'@type'        => 'Review',
				'reviewBody'   => wp_strip_all_tags( $c->comment_content ),
				'datePublished'=> mysql2date( 'c', $c->comment_date_gmt, false ),
				'author'       => array(
					'@type' => 'Person',
					'name'  => $c->comment_author ?: __( 'Anonymous', 'perrylabs-seo' ),
				),
			);
			if ( $rating > 0 ) {
				$entry['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				);
			}
			$out[] = $entry;
		}
		return $out;
	}
}
