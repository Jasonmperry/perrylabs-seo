<?php
/**
 * PerryLabs SEO + AEO — Post Editor Meta Box
 *
 * Clean meta box with SEO title, meta description, canonical URL,
 * noindex/nofollow, social image, and a live Google preview.
 * No focus keyword. No SEO score. No readability nonsense.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Meta_Box {

	/** @var string Nonce action. */
	private const NONCE_ACTION = 'perrylabs_seo_meta_box';

	/** @var string Nonce field name. */
	private const NONCE_NAME = 'perrylabs_seo_nonce';

	/** @var string[] Meta keys we manage. */
	private const META_KEYS = array(
		'_perrylabs_seo_title',
		'_perrylabs_seo_description',
		'_perrylabs_seo_canonical',
		'_perrylabs_seo_noindex',
		'_perrylabs_seo_nofollow',
		'_perrylabs_seo_social_image',
	);

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Supported post types
	 * ────────────────────────────────────────────────────────────── */

	private function get_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Register meta box
	 * ────────────────────────────────────────────────────────────── */

	public function register_meta_box(): void {
		foreach ( $this->get_post_types() as $post_type ) {
			add_meta_box(
				'perrylabs_seo_meta_box',
				__( 'SEO Settings', 'perrylabs-seo' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Enqueue CSS + JS on relevant screens
	 * ────────────────────────────────────────────────────────────── */

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->get_post_types(), true ) ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'perrylabs-seo-meta-box',
			JEYSEO_PLUGIN_URL . 'assets/meta-box.css',
			array(),
			JEYSEO_VERSION
		);

		wp_enqueue_script(
			'perrylabs-seo-meta-box',
			JEYSEO_PLUGIN_URL . 'assets/meta-box.js',
			array( 'jquery' ),
			JEYSEO_VERSION,
			true
		);

		$separator = perrylabs_seo_get_option( 'title_separator', '|' );
		$site_name = get_bloginfo( 'name' );

		wp_localize_script( 'perrylabs-seo-meta-box', 'perryLabsSEO', array(
			'separator' => $separator,
			'siteName'  => $site_name,
			'siteUrl'   => home_url(),
			'i18n'      => array(
				'selectImage' => __( 'Select Social Image', 'perrylabs-seo' ),
				'useImage'    => __( 'Use this image', 'perrylabs-seo' ),
			),
		) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Render meta box
	 * ────────────────────────────────────────────────────────────── */

	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$title        = get_post_meta( $post->ID, '_perrylabs_seo_title', true );
		$description  = get_post_meta( $post->ID, '_perrylabs_seo_description', true );
		$canonical    = get_post_meta( $post->ID, '_perrylabs_seo_canonical', true );
		$noindex      = (bool) get_post_meta( $post->ID, '_perrylabs_seo_noindex', true );
		$nofollow     = (bool) get_post_meta( $post->ID, '_perrylabs_seo_nofollow', true );
		$social_image = get_post_meta( $post->ID, '_perrylabs_seo_social_image', true );

		$separator = perrylabs_seo_get_option( 'title_separator', '|' );
		$site_name = get_bloginfo( 'name' );
		$permalink = get_permalink( $post->ID ) ?: home_url( '/' );

		// Build preview title.
		$preview_title = $title ?: ( $post->post_title ?: __( 'Post Title', 'perrylabs-seo' ) );
		if ( strpos( $preview_title, '%' ) !== false ) {
			$preview_title = str_replace(
				array( '%title%', '%sitename%', '%sep%' ),
				array( $post->post_title, $site_name, $separator ),
				$preview_title
			);
		} else {
			$preview_title .= ' ' . $separator . ' ' . $site_name;
		}

		$preview_description = $description ?: wp_trim_words( $post->post_excerpt ?: $post->post_content, 25, '...' );
		?>
		<div class="perrylabs-seo-metabox">

			<!-- Google Preview -->
			<div class="perrylabs-seo-preview">
				<p class="perrylabs-seo-preview__label"><?php esc_html_e( 'Search Preview', 'perrylabs-seo' ); ?></p>
				<div class="perrylabs-seo-preview__result">
					<div class="perrylabs-seo-preview__title" id="perrylabs-seo-preview-title"><?php echo esc_html( $preview_title ); ?></div>
					<div class="perrylabs-seo-preview__url" id="perrylabs-seo-preview-url"><?php echo esc_html( $permalink ); ?></div>
					<div class="perrylabs-seo-preview__desc" id="perrylabs-seo-preview-desc"><?php echo esc_html( $preview_description ); ?></div>
				</div>
			</div>

			<!-- SEO Title -->
			<div class="perrylabs-seo-field">
				<label for="perrylabs-seo-title"><?php esc_html_e( 'SEO Title', 'perrylabs-seo' ); ?></label>
				<input
					type="text"
					id="perrylabs-seo-title"
					name="_perrylabs_seo_title"
					value="<?php echo esc_attr( $title ); ?>"
					placeholder="<?php echo esc_attr( $post->post_title . ' ' . $separator . ' ' . $site_name ); ?>"
					class="widefat"
					data-counter="perrylabs-seo-title-count"
				/>
				<div class="perrylabs-seo-counter">
					<span class="perrylabs-seo-counter__bar" id="perrylabs-seo-title-bar"></span>
				</div>
				<span class="perrylabs-seo-counter__text" id="perrylabs-seo-title-count">0 / 60</span>
				<p class="description"><?php esc_html_e( 'Template tags: %title%, %sitename%, %sep%. Leave blank to auto-generate.', 'perrylabs-seo' ); ?></p>
			</div>

			<!-- Meta Description -->
			<div class="perrylabs-seo-field">
				<label for="perrylabs-seo-description"><?php esc_html_e( 'Meta Description', 'perrylabs-seo' ); ?></label>
				<textarea
					id="perrylabs-seo-description"
					name="_perrylabs_seo_description"
					rows="3"
					class="widefat"
					data-counter="perrylabs-seo-desc-count"
					placeholder="<?php esc_attr_e( 'Auto-generated from excerpt if left blank.', 'perrylabs-seo' ); ?>"
				><?php echo esc_textarea( $description ); ?></textarea>
				<div class="perrylabs-seo-counter">
					<span class="perrylabs-seo-counter__bar" id="perrylabs-seo-desc-bar"></span>
				</div>
				<span class="perrylabs-seo-counter__text" id="perrylabs-seo-desc-count">0 / 160</span>
			</div>

			<!-- Canonical URL -->
			<div class="perrylabs-seo-field">
				<label for="perrylabs-seo-canonical"><?php esc_html_e( 'Canonical URL', 'perrylabs-seo' ); ?></label>
				<input
					type="url"
					id="perrylabs-seo-canonical"
					name="_perrylabs_seo_canonical"
					value="<?php echo esc_url( $canonical ); ?>"
					placeholder="<?php echo esc_attr( $permalink ); ?>"
					class="widefat"
				/>
				<p class="description"><?php esc_html_e( 'Override the canonical URL. Leave blank to use the post permalink.', 'perrylabs-seo' ); ?></p>
			</div>

			<!-- Robots -->
			<div class="perrylabs-seo-field perrylabs-seo-field--inline">
				<label>
					<input type="checkbox" name="_perrylabs_seo_noindex" value="1" <?php checked( $noindex ); ?> />
					<?php esc_html_e( 'noindex', 'perrylabs-seo' ); ?>
				</label>
				<label>
					<input type="checkbox" name="_perrylabs_seo_nofollow" value="1" <?php checked( $nofollow ); ?> />
					<?php esc_html_e( 'nofollow', 'perrylabs-seo' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Prevent search engines from indexing or following links on this page.', 'perrylabs-seo' ); ?></p>
			</div>

			<!-- Social Image -->
			<div class="perrylabs-seo-field">
				<label for="perrylabs-seo-social-image"><?php esc_html_e( 'Social Sharing Image', 'perrylabs-seo' ); ?></label>
				<div class="perrylabs-seo-image-field">
					<input
						type="url"
						id="perrylabs-seo-social-image"
						name="_perrylabs_seo_social_image"
						value="<?php echo esc_url( $social_image ); ?>"
						placeholder="<?php esc_attr_e( 'Uses featured image if blank.', 'perrylabs-seo' ); ?>"
						class="widefat"
					/>
					<button type="button" class="button perrylabs-seo-upload-btn" data-target="perrylabs-seo-social-image">
						<?php esc_html_e( 'Select Image', 'perrylabs-seo' ); ?>
					</button>
					<?php if ( $social_image ) : ?>
						<div class="perrylabs-seo-image-preview" style="margin-top:8px;">
							<img src="<?php echo esc_url( $social_image ); ?>" style="max-width:200px;height:auto;" />
						</div>
					<?php endif; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Override the image used for Open Graph and Twitter Cards. Recommended: 1200x630px.', 'perrylabs-seo' ); ?></p>
			</div>

		</div>

		<!-- Hidden data for JS -->
		<input type="hidden" id="perrylabs-seo-post-title" value="<?php echo esc_attr( $post->post_title ); ?>" />
		<input type="hidden" id="perrylabs-seo-post-excerpt" value="<?php echo esc_attr( wp_trim_words( $post->post_excerpt ?: strip_tags( $post->post_content ), 25, '...' ) ); ?>" />
		<?php
	}

	/* ──────────────────────────────────────────────────────────────
	 * Save meta box data
	 * ────────────────────────────────────────────────────────────── */

	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type is supported.
		if ( ! in_array( $post->post_type, $this->get_post_types(), true ) ) {
			return;
		}

		// Sanitize and save each field.
		$title = sanitize_text_field( $_POST['_perrylabs_seo_title'] ?? '' );
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_title', $title );

		$description = sanitize_textarea_field( $_POST['_perrylabs_seo_description'] ?? '' );
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_description', $description );

		$canonical = esc_url_raw( $_POST['_perrylabs_seo_canonical'] ?? '' );
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_canonical', $canonical );

		$noindex = ! empty( $_POST['_perrylabs_seo_noindex'] ) ? '1' : '';
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_noindex', $noindex );

		$nofollow = ! empty( $_POST['_perrylabs_seo_nofollow'] ) ? '1' : '';
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_nofollow', $nofollow );

		$social_image = esc_url_raw( $_POST['_perrylabs_seo_social_image'] ?? '' );
		$this->update_or_delete_meta( $post_id, '_perrylabs_seo_social_image', $social_image );
	}

	/**
	 * Update post meta, or delete if value is empty.
	 */
	private function update_or_delete_meta( int $post_id, string $key, string $value ): void {
		if ( $value !== '' ) {
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
}
