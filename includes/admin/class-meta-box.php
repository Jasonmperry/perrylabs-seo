<?php
/**
 * PLSEO_Meta_Box — per-post SEO + AEO editor and content-analysis report.
 *
 * Renders a meta box on every public post type with:
 *   - SEO title, description, canonical, social image overrides
 *   - Noindex / nofollow toggles
 *   - Schema type override
 *   - Hreflang alternate URLs
 *   - Per-post quick answer + speakable region selector overrides
 *   - Live content analysis report from PLSEO_Content_Analysis
 *   - Internal-link suggester for a chosen focus keyword
 *
 * All meta keys are `_plseo_*`. The hreflang field accepts one
 * "lang|url" pair per line; PLSEO_Meta_Tags renders the alternates.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Meta_Box {

	private static ?self $instance = null;

	private const NONCE = 'plseo_meta_box';

	private const FIELDS = array(
		'title',
		'description',
		'canonical',
		'noindex',
		'nofollow',
		'social_image',
		'schema_type',
		'hreflang',
		'quick_answer',
		'focus_keyword',
		'cornerstone',
	);

	private const SCHEMA_CHOICES = array(
		''               => 'Auto (post-type default)',
		'Article'        => 'Article',
		'NewsArticle'    => 'NewsArticle',
		'BlogPosting'    => 'BlogPosting',
		'WebPage'        => 'WebPage',
		'FAQPage'        => 'FAQPage',
		'HowTo'          => 'HowTo',
		'Event'          => 'Event',
		'VideoObject'    => 'VideoObject',
		'LocalBusiness'  => 'LocalBusiness',
		'Person'         => 'Person',
		'none'           => 'None (disable schema for this post)',
	);

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post',      array( $this, 'save' ), 10, 2 );
	}

	public function register(): void {
		foreach ( $this->target_post_types() as $type ) {
			add_meta_box(
				'plseo-meta-box',
				__( 'SEO + AEO', 'perrylabs-seo' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'high'
			);
		}
	}

	/** @return array<int,string> */
	private function target_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );

		$vals = array();
		foreach ( self::FIELDS as $f ) {
			$vals[ $f ] = (string) plseo_get_post_meta( $post->ID, $f, '' );
		}

		$analysis = PLSEO_Content_Analysis::analyze( $post );
		$overall  = PLSEO_Content_Analysis::overall_severity( $analysis );
		?>
		<div class="plseo-mb">
			<div class="plseo-mb__tabs">
				<button type="button" class="plseo-mb__tab is-active" data-target="seo">SEO</button>
				<button type="button" class="plseo-mb__tab" data-target="preview"><?php esc_html_e( 'Preview', 'perrylabs-seo' ); ?></button>
				<button type="button" class="plseo-mb__tab" data-target="social">Social</button>
				<button type="button" class="plseo-mb__tab" data-target="schema">Schema</button>
				<button type="button" class="plseo-mb__tab" data-target="aeo">AEO</button>
				<button type="button" class="plseo-mb__tab" data-target="advanced">Advanced</button>
				<button type="button" class="plseo-mb__tab" data-target="analysis">
					<?php esc_html_e( 'Analysis', 'perrylabs-seo' ); ?>
					<span class="plseo-pill plseo-pill--<?php echo esc_attr( $overall ); ?>"><?php echo esc_html( ucfirst( $overall ) ); ?></span>
				</button>
			</div>

			<div class="plseo-mb__panel is-active" data-panel="seo">
				<p>
					<label for="plseo-title"><strong><?php esc_html_e( 'SEO title', 'perrylabs-seo' ); ?></strong></label><br>
					<input type="text" id="plseo-title" name="_plseo_title" value="<?php echo esc_attr( $vals['title'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'Leave empty to use the template', 'perrylabs-seo' ); ?>">
					<span class="plseo-counter" data-target="plseo-title" data-good="30,60"></span>
				</p>
				<p>
					<label for="plseo-description"><strong><?php esc_html_e( 'Meta description', 'perrylabs-seo' ); ?></strong></label><br>
					<textarea id="plseo-description" name="_plseo_description" rows="3" class="widefat"><?php echo esc_textarea( $vals['description'] ); ?></textarea>
					<span class="plseo-counter" data-target="plseo-description" data-good="120,160"></span>
				</p>
				<p>
					<label for="plseo-canonical"><strong><?php esc_html_e( 'Canonical URL', 'perrylabs-seo' ); ?></strong></label><br>
					<input type="url" id="plseo-canonical" name="_plseo_canonical" value="<?php echo esc_attr( $vals['canonical'] ); ?>" class="widefat code">
				</p>
			</div>

			<div class="plseo-mb__panel" data-panel="preview">
				<?php
				$preview_title = '' !== $vals['title']
					? PLSEO_Template_Resolver::resolve( $vals['title'], PLSEO_Template_Resolver::context_for_post( $post ) )
					: PLSEO_Template_Resolver::resolve(
						(string) PLSEO_Options::get( 'page' === $post->post_type ? 'title_template_page' : 'title_template_post', '%post_title% %sep% %site_name%' ),
						PLSEO_Template_Resolver::context_for_post( $post )
					);
				$preview_desc  = '' !== $vals['description'] ? $vals['description'] : (string) $post->post_excerpt;
				$preview_url   = '' !== $vals['canonical']   ? $vals['canonical']   : (string) get_permalink( $post );
				$preview_img   = '' !== $vals['social_image']
					? $vals['social_image']
					: ( (string) get_the_post_thumbnail_url( $post, 'full' ) ?: (string) PLSEO_Options::get( 'default_social_image', '' ) );
				$twitter_card  = (string) PLSEO_Options::get( 'twitter_card_type', 'summary_large_image' );
				$site_name     = (string) get_bloginfo( 'name' );
				?>
				<div class="plseo-preview-grid">
					<div class="plseo-preview plseo-preview--google" data-preview="google">
						<h4><?php esc_html_e( 'Google search', 'perrylabs-seo' ); ?></h4>
						<div class="plseo-snip plseo-snip--google">
							<div class="plseo-snip__site"><?php echo esc_html( $site_name ); ?> · <span class="plseo-snip__crumb"><?php echo esc_html( wp_parse_url( $preview_url, PHP_URL_HOST ) ?: '' ); ?></span></div>
							<a class="plseo-snip__title" data-preview-field="title" href="#"><?php echo esc_html( $preview_title ); ?></a>
							<div class="plseo-snip__desc" data-preview-field="description"><?php echo esc_html( $preview_desc ); ?></div>
						</div>
					</div>

					<div class="plseo-preview plseo-preview--x" data-preview="x">
						<h4><?php esc_html_e( 'X / Twitter', 'perrylabs-seo' ); ?></h4>
						<div class="plseo-snip plseo-snip--x plseo-snip--x-<?php echo esc_attr( $twitter_card ); ?>">
							<?php if ( '' !== $preview_img ) : ?>
								<div class="plseo-snip__img" data-preview-field="image" style="background-image:url('<?php echo esc_url( $preview_img ); ?>')"></div>
							<?php else : ?>
								<div class="plseo-snip__img plseo-snip__img--empty" data-preview-field="image"><?php esc_html_e( 'No image set', 'perrylabs-seo' ); ?></div>
							<?php endif; ?>
							<div class="plseo-snip__body">
								<div class="plseo-snip__title" data-preview-field="title"><?php echo esc_html( $preview_title ); ?></div>
								<div class="plseo-snip__desc"  data-preview-field="description"><?php echo esc_html( $preview_desc ); ?></div>
								<div class="plseo-snip__url"   data-preview-field="url"><?php echo esc_html( (string) wp_parse_url( $preview_url, PHP_URL_HOST ) ); ?></div>
							</div>
						</div>
					</div>

					<div class="plseo-preview plseo-preview--fb" data-preview="fb">
						<h4><?php esc_html_e( 'Facebook / LinkedIn', 'perrylabs-seo' ); ?></h4>
						<div class="plseo-snip plseo-snip--fb">
							<?php if ( '' !== $preview_img ) : ?>
								<div class="plseo-snip__img" data-preview-field="image" style="background-image:url('<?php echo esc_url( $preview_img ); ?>')"></div>
							<?php else : ?>
								<div class="plseo-snip__img plseo-snip__img--empty" data-preview-field="image"><?php esc_html_e( 'No image set', 'perrylabs-seo' ); ?></div>
							<?php endif; ?>
							<div class="plseo-snip__body">
								<div class="plseo-snip__url plseo-snip__url--fb" data-preview-field="url"><?php echo esc_html( strtoupper( (string) wp_parse_url( $preview_url, PHP_URL_HOST ) ) ); ?></div>
								<div class="plseo-snip__title" data-preview-field="title"><?php echo esc_html( $preview_title ); ?></div>
								<div class="plseo-snip__desc"  data-preview-field="description"><?php echo esc_html( $preview_desc ); ?></div>
							</div>
						</div>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'Previews update live as you type in the SEO tab.', 'perrylabs-seo' ); ?></p>
			</div>

			<div class="plseo-mb__panel" data-panel="social">
				<p>
					<label for="plseo-social_image"><strong><?php esc_html_e( 'Social image override', 'perrylabs-seo' ); ?></strong></label><br>
					<input type="url" id="plseo-social_image" name="_plseo_social_image" value="<?php echo esc_attr( $vals['social_image'] ); ?>" class="widefat code">
					<button type="button" class="button plseo-image-pick" data-target="plseo-social_image"><?php esc_html_e( 'Pick from media', 'perrylabs-seo' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'If empty, the featured image is used.', 'perrylabs-seo' ); ?></p>
			</div>

			<div class="plseo-mb__panel" data-panel="schema">
				<p>
					<label for="plseo-schema_type"><strong><?php esc_html_e( 'Schema type', 'perrylabs-seo' ); ?></strong></label><br>
					<select id="plseo-schema_type" name="_plseo_schema_type">
						<?php foreach ( self::SCHEMA_CHOICES as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $vals['schema_type'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>

			<div class="plseo-mb__panel" data-panel="aeo">
				<p>
					<label for="plseo-quick_answer"><strong><?php esc_html_e( 'Quick answer / TL;DR', 'perrylabs-seo' ); ?></strong></label><br>
					<textarea id="plseo-quick_answer" name="_plseo_quick_answer" rows="3" class="widefat" placeholder="<?php esc_attr_e( 'Two or three sentences AI answers can lift verbatim. Falls back to first paragraph.', 'perrylabs-seo' ); ?>"><?php echo esc_textarea( $vals['quick_answer'] ); ?></textarea>
				</p>
				<p>
					<label for="plseo-focus_keyword"><strong><?php esc_html_e( 'Focus keywords', 'perrylabs-seo' ); ?></strong></label><br>
					<input type="text" id="plseo-focus_keyword" name="_plseo_focus_keyword" value="<?php echo esc_attr( $vals['focus_keyword'] ); ?>" class="widefat">
					<span class="description"><?php esc_html_e( 'Comma-separated. Each one is checked against the content; the first drives internal-link suggestions.', 'perrylabs-seo' ); ?></span>
				</p>

				<?php
				$primary_keyword = '' !== $vals['focus_keyword']
					? trim( (string) explode( ',', $vals['focus_keyword'] )[0] )
					: '';
				$suggestions     = '' !== $primary_keyword ? PLSEO_Content_Analysis::suggest_internal_links( (int) $post->ID, $primary_keyword ) : array();
				?>
				<?php if ( ! empty( $suggestions ) ) : ?>
					<h4><?php esc_html_e( 'Internal-link suggestions', 'perrylabs-seo' ); ?></h4>
					<ul class="plseo-link-suggestions">
						<?php foreach ( $suggestions as $s ) : ?>
							<li><a href="<?php echo esc_url( $s['permalink'] ); ?>" target="_blank"><?php echo esc_html( $s['title'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<div class="plseo-mb__panel" data-panel="advanced">
				<p>
					<label><input type="checkbox" name="_plseo_noindex" value="1" <?php checked( $vals['noindex'], '1' ); ?>> <?php esc_html_e( 'Noindex this post', 'perrylabs-seo' ); ?></label><br>
					<label><input type="checkbox" name="_plseo_nofollow" value="1" <?php checked( $vals['nofollow'], '1' ); ?>> <?php esc_html_e( 'Nofollow links from this post', 'perrylabs-seo' ); ?></label><br>
					<label title="<?php esc_attr_e( 'Promotes this post in /llms.txt featured and gives it sitemap priority 1.0.', 'perrylabs-seo' ); ?>"><input type="checkbox" name="_plseo_cornerstone" value="1" <?php checked( $vals['cornerstone'], '1' ); ?>> <?php esc_html_e( 'Mark as cornerstone content', 'perrylabs-seo' ); ?></label>
				</p>
				<p>
					<label for="plseo-hreflang"><strong><?php esc_html_e( 'Hreflang alternates', 'perrylabs-seo' ); ?></strong></label><br>
					<textarea id="plseo-hreflang" name="_plseo_hreflang" rows="4" class="widefat code" placeholder="en-US|https://example.com/en/post"><?php echo esc_textarea( $vals['hreflang'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'One per line: language|url.', 'perrylabs-seo' ); ?></span>
				</p>
			</div>

			<div class="plseo-mb__panel" data-panel="analysis">
				<table class="plseo-analysis">
					<tbody>
						<?php foreach ( $analysis as $row ) : ?>
							<tr class="plseo-analysis-row plseo-analysis-row--<?php echo esc_attr( $row['severity'] ); ?>">
								<td class="plseo-analysis-badge"><span class="plseo-pill plseo-pill--<?php echo esc_attr( $row['severity'] ); ?>"><?php echo esc_html( strtoupper( substr( $row['severity'], 0, 4 ) ) ); ?></span></td>
								<td>
									<strong><?php echo esc_html( $row['label'] ); ?></strong>
									<?php if ( ! empty( $row['detail'] ) ) : ?><br><span class="description"><?php echo esc_html( $row['detail'] ); ?></span><?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( (string) $_POST[ self::NONCE . '_nonce' ], self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$sanitize = array(
			'title'        => 'sanitize_text_field',
			'description'  => 'sanitize_textarea_field',
			'canonical'    => 'esc_url_raw',
			'social_image' => 'esc_url_raw',
			'schema_type'  => 'sanitize_text_field',
			'hreflang'     => 'sanitize_textarea_field',
			'quick_answer' => 'sanitize_textarea_field',
			'focus_keyword'=> 'sanitize_text_field',
		);

		foreach ( $sanitize as $field => $fn ) {
			$key   = '_plseo_' . $field;
			$raw   = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
			$clean = '' === $raw ? '' : call_user_func( $fn, $raw );
			if ( '' === $clean ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $clean );
			}
		}

		// Booleans (checkbox keys present only when checked).
		foreach ( array( 'noindex', 'nofollow', 'cornerstone' ) as $field ) {
			$key = '_plseo_' . $field;
			if ( ! empty( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, '1' );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}
