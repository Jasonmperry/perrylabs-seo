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

	private const WIDTH   = 1200;
	private const HEIGHT  = 630;
	private const PADDING = 72;
	private const SUBDIR  = 'plseo-og';

	/**
	 * Bump to invalidate previously generated cards when the design changes —
	 * it feeds the filename hash, so old files are simply left behind and
	 * fresh ones render on next request.
	 */
	private const DESIGN_VERSION = '2';

	/** Space reserved on the right for the logo (logo size + gutter). */
	private const LOGO_SIZE = 240;

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

		$title = wp_specialchars_decode( (string) get_the_title( $post ), ENT_QUOTES );
		if ( '' === $title ) {
			return $current;
		}

		// The front page's post title is usually "Home" — the site name makes
		// a far better card headline there.
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id && $front_id === (int) $post->ID ) {
			$title = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
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

		$hash = substr( md5( self::DESIGN_VERSION . '|' . $title . '|' . get_bloginfo( 'name' ) ), 0, 10 );
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

		// Left aqua accent bar.
		imagefilledrectangle( $img, 0, 0, 10, self::HEIGHT, $accent );

		$bold    = $this->font_path( 'DMSans-Bold.ttf' );
		$regular = $this->font_path( 'DMSans-Regular.ttf' );

		if ( '' === $bold || ! function_exists( 'imagettftext' ) ) {
			// FreeType or bundled fonts unavailable — legible bitmap fallback.
			$this->render_bitmap_fallback( $img, $title, $text, $muted );
			$ok = imagepng( $img, $path, 6 );
			imagedestroy( $img );
			return (bool) $ok;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$tag  = wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );

		// Big logo, top-right: site icon (or custom logo / bundled logomark).
		$logo_drawn = $this->draw_logo( $img );

		// Title block: keep clear of the logo when one was drawn.
		$max_w = self::WIDTH - 2 * self::PADDING;
		if ( $logo_drawn ) {
			$max_w -= self::LOGO_SIZE + 48;
		}

		// Shrink-to-fit: start large, step down until the block fits 3 lines.
		$size  = 68;
		$lines = array();
		do {
			$lines = $this->wrap_ttf( $title, $bold, $size, $max_w );
			if ( count( $lines ) <= 3 ) {
				break;
			}
			$size -= 6;
		} while ( $size >= 40 );

		$line_h  = (int) round( $size * 1.25 );
		$block_h = count( $lines ) * $line_h;

		// Vertically center the title in the space above the footer.
		$footer_top = self::HEIGHT - self::PADDING - 64;
		$y          = max( self::PADDING + $size, (int) ( ( $footer_top - $block_h ) / 2 ) + $size );
		foreach ( $lines as $line ) {
			imagettftext( $img, $size, 0, self::PADDING, $y, $text, $bold, $line );
			$y += $line_h;
		}

		// Short aqua rule under the title block.
		imagefilledrectangle( $img, self::PADDING, $y - $line_h + 22, self::PADDING + 72, $y - $line_h + 28, $accent );

		// Footer: site name (aqua) with the tagline under it. When the title
		// IS the site name (front page), the tagline alone reads better.
		$fy = self::HEIGHT - self::PADDING;
		if ( 0 !== strcasecmp( trim( $title ), trim( $site ) ) && '' !== $site ) {
			imagettftext( $img, 24, 0, self::PADDING, $fy - 38, $accent, $bold, strtoupper( $site ) );
		}
		if ( '' !== $tag ) {
			imagettftext( $img, 20, 0, self::PADDING, $fy, $muted, $regular, $tag );
		}

		$ok = imagepng( $img, $path, 6 );
		imagedestroy( $img );
		return (bool) $ok;
	}

	/**
	 * Composite the site's logo large in the top-right corner.
	 * Prefers the site icon, then the customizer logo, then the bundled
	 * PerryLabs logomark. Returns true when something was drawn.
	 */
	private function draw_logo( $img ): bool {
		$path = '';

		$icon_id = (int) get_option( 'site_icon' );
		if ( $icon_id ) {
			$path = (string) get_attached_file( $icon_id );
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$path = (string) get_attached_file( $logo_id );
			}
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			$bundled = defined( 'PL_SEO_PLUGIN_DIR' )
				? PL_SEO_PLUGIN_DIR . 'includes/branding/assets/perrylabs-logomark.png'
				: '';
			if ( '' !== $bundled && is_readable( $bundled ) ) {
				$path = $bundled;
			}
		}
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file
		if ( false === $data ) {
			return false;
		}
		$logo = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid image data is an expected failure mode
		if ( ! $logo ) {
			return false;
		}

		$sw = imagesx( $logo );
		$sh = imagesy( $logo );
		if ( $sw < 1 || $sh < 1 ) {
			imagedestroy( $logo );
			return false;
		}

		// Fit inside the LOGO_SIZE box, preserving aspect ratio and alpha.
		$scale = min( self::LOGO_SIZE / $sw, self::LOGO_SIZE / $sh );
		$dw    = (int) round( $sw * $scale );
		$dh    = (int) round( $sh * $scale );
		$dx    = self::WIDTH - self::PADDING - $dw;
		$dy    = self::PADDING;

		imagealphablending( $img, true );
		imagecopyresampled( $img, $logo, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
		imagedestroy( $logo );
		return true;
	}

	/**
	 * Word-wrap a string for a TTF font by measuring real glyph widths.
	 *
	 * @return string[] Wrapped lines.
	 */
	private function wrap_ttf( string $text, string $font, int $size, int $max_w ): array {
		$words = preg_split( '/\s+/', trim( $text ) ) ?: array();
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			$box       = imagettfbbox( $size, 0, $font, $candidate );
			$width     = abs( $box[2] - $box[0] );
			if ( $width > $max_w && '' !== $line ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $candidate;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Absolute path to a bundled font, or '' when missing.
	 */
	private function font_path( string $file ): string {
		if ( ! defined( 'PL_SEO_PLUGIN_DIR' ) ) {
			return '';
		}
		$path = PL_SEO_PLUGIN_DIR . 'assets/fonts/' . $file;
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Last-resort card using GD's built-in bitmap fonts (no FreeType).
	 */
	private function render_bitmap_fallback( $img, string $title, int $text, int $muted ): void {
		$font    = 5;
		$char_w  = imagefontwidth( $font );
		$line_h  = imagefontheight( $font ) + 6;
		$max_cpl = max( 10, (int) floor( ( self::WIDTH - 2 * self::PADDING ) / $char_w ) );
		$lines   = explode( "\n", wordwrap( $title, $max_cpl, "\n", true ) );
		foreach ( $lines as $i => $line ) {
			imagestring( $img, $font, self::PADDING, self::PADDING + 40 + $i * $line_h, $line, $text );
		}
		$site = strtoupper( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		imagestring( $img, 4, self::PADDING, self::HEIGHT - self::PADDING - 24, $site, $muted );
	}
}
