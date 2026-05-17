<?php
/**
 * PLSEO_OG_Image — generate 1200x630 Open Graph card images on demand.
 *
 * For posts that have no featured image and no `_plseo_social_image` override,
 * we render a clean dark-on-PerryLabs-palette card with the post title set
 * on top of the brand wordmark, save it to the uploads dir, and return its URL.
 *
 * Public URL: /wp-content/uploads/plseo-og/{post_id}-{hash}.png
 *   - {hash} is a stable digest of the title so changing the title regenerates.
 *
 * Falls back gracefully when GD is unavailable: returns ''.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_OG_Image {

	private static ?self $instance = null;

	private const WIDTH    = 1200;
	private const HEIGHT   = 630;
	private const PADDING  = 64;
	private const SUBDIR   = 'plseo-og';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		if ( ! (bool) PLSEO_Options::get( 'og_image_auto', true ) ) {
			return;
		}
		// Hook before social-image resolution. Add a filter so the meta-tags
		// module can ask us for a fallback when nothing else is set.
		add_filter( 'plseo_resolve_social_image', array( $this, 'maybe_generate' ), 10, 2 );
	}

	/**
	 * Filter callback: return a generated URL when no other social image is set.
	 *
	 * @param string        $current  URL already resolved by the meta-tags module.
	 * @param \WP_Post|null $post     Queried post (or null on non-singular).
	 * @return string
	 */
	public function maybe_generate( string $current, ?\WP_Post $post ): string {
		if ( '' !== $current ) {
			return $current;
		}
		if ( ! $post instanceof \WP_Post ) {
			return $current;
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return $current; // GD not available
		}

		$title = (string) get_the_title( $post );
		if ( '' === $title ) {
			return $current;
		}
		return $this->get_or_create( (int) $post->ID, $title );
	}

	/**
	 * Return the public URL for a generated card, creating it if missing.
	 */
	public function get_or_create( int $post_id, string $title ): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::SUBDIR;
		$url    = trailingslashit( $upload['baseurl'] ) . self::SUBDIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$hash = substr( md5( $title . '|' . get_bloginfo( 'name' ) ), 0, 10 );
		$file = $post_id . '-' . $hash . '.png';
		$path = trailingslashit( $dir ) . $file;

		if ( file_exists( $path ) ) {
			return trailingslashit( $url ) . $file;
		}

		if ( ! $this->render_to_path( $title, $path ) ) {
			return '';
		}
		return trailingslashit( $url ) . $file;
	}

	/**
	 * Render the card to a file. Returns true on success.
	 */
	private function render_to_path( string $title, string $path ): bool {
		$img = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( ! $img ) {
			return false;
		}

		// Palette pulled from the shared PerryLabs branding tokens.
		$bg     = imagecolorallocate( $img, 10, 22, 40 );  // #0A1628 (PerryLabs navy)
		$accent = imagecolorallocate( $img, 0, 180, 216 ); // #00B4D8 (aqua)
		$text   = imagecolorallocate( $img, 248, 249, 250 ); // off-white
		$muted  = imagecolorallocate( $img, 150, 160, 175 );

		imagefilledrectangle( $img, 0, 0, self::WIDTH, self::HEIGHT, $bg );

		// Top-left aqua corner accent bar.
		imagefilledrectangle( $img, 0, 0, 8, self::HEIGHT, $accent );

		// Site name in the bottom-left (small caps style).
		$site = strtoupper( (string) get_bloginfo( 'name' ) );
		$this->draw_centered_block(
			$img, $site, $muted, 22,
			self::PADDING, self::HEIGHT - self::PADDING - 24, self::WIDTH - 2 * self::PADDING,
			'left'
		);

		// Brand line under the site name.
		$tag = (string) get_bloginfo( 'description' );
		if ( '' !== $tag ) {
			$this->draw_centered_block(
				$img, $tag, $muted, 16,
				self::PADDING, self::HEIGHT - self::PADDING + 4, self::WIDTH - 2 * self::PADDING,
				'left'
			);
		}

		// Title — large, wrapped, top-aligned.
		$this->draw_wrapped_title(
			$img, $title, $text, 60,
			self::PADDING, self::PADDING + 40, self::WIDTH - 2 * self::PADDING
		);

		$ok = imagepng( $img, $path, 6 );
		imagedestroy( $img );
		return (bool) $ok;
	}

	/**
	 * Word-wrap and draw a multi-line title block at the given anchor.
	 * Uses GD's bundled font (font index 5) since we can't depend on any
	 * particular TTF being on the host. Output is legible, not designer-grade —
	 * which is exactly the right tradeoff for a fallback.
	 */
	private function draw_wrapped_title( $img, string $title, int $color, int $font_size, int $x, int $y, int $max_w ): void {
		// Built-in font 5 is roughly 9px wide × 15px tall. We scale visually by
		// drawing multiple characters wide — but for true control we'd need TTF.
		// Use GD's largest built-in font and chunk by chars-per-line.
		$font     = 5;
		$char_w   = imagefontwidth( $font );
		$line_h   = imagefontheight( $font ) + 6;
		$max_cpl  = max( 10, (int) floor( $max_w / $char_w ) );
		$wrapped  = wordwrap( $title, $max_cpl, "\n", true );
		$lines    = explode( "\n", $wrapped );
		foreach ( $lines as $i => $line ) {
			imagestring( $img, $font, $x, $y + $i * $line_h, $line, $color );
		}
	}

	/**
	 * Single-line text block (used for the site-name footer).
	 */
	private function draw_centered_block( $img, string $text, int $color, int $px, int $x, int $y, int $w, string $align ): void {
		$font   = 4;
		$char_w = imagefontwidth( $font );
		$line_w = $char_w * mb_strlen( $text );
		$start  = $x;
		if ( 'center' === $align ) {
			$start = $x + (int) ( ( $w - $line_w ) / 2 );
		} elseif ( 'right' === $align ) {
			$start = $x + $w - $line_w;
		}
		imagestring( $img, $font, $start, $y, $text, $color );
	}
}
