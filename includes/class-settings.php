<?php
/**
 * PerryLabs SEO + AEO — Settings Page
 *
 * Single settings page under Settings > PerryLabs SEO + AEO.
 * Clean UI, no dashboard widgets, no nag screens, no upsells.
 *
 * @package PerryLabs_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PerryLabs_SEO_Settings {

	/** @var string Option group name. */
	private const OPTION_GROUP = 'perrylabs_seo_options_group';

	/** @var string Option name in wp_options. */
	private const OPTION_NAME = 'perrylabs_seo_options';

	/** @var string Settings page slug. */
	private const PAGE_SLUG = 'perrylabs-seo';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Menu
	 * ────────────────────────────────────────────────────────────── */

	public function add_settings_page(): void {
		add_options_page(
			__( 'PerryLabs SEO + AEO', 'perrylabs-seo' ),
			__( 'PerryLabs SEO + AEO', 'perrylabs-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Register settings + sections + fields
	 * ────────────────────────────────────────────────────────────── */

	public function register_settings(): void {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_options' ),
			'default'           => self::defaults(),
		) );

		// ── General Section ─────────────────────────────────────────
		add_settings_section(
			'perrylabs_seo_general',
			__( 'General', 'perrylabs-seo' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'title_separator',
			__( 'Title Separator', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_general',
			array( 'key' => 'title_separator', 'size' => 5, 'description' => __( 'Character between page title and site name (e.g. | or - or &mdash;)', 'perrylabs-seo' ) )
		);

		add_settings_field(
			'default_description',
			__( 'Default Meta Description', 'perrylabs-seo' ),
			array( $this, 'render_textarea_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_general',
			array( 'key' => 'default_description', 'description' => __( 'Fallback description when no per-page description is set. Defaults to your site tagline.', 'perrylabs-seo' ) )
		);

		// ── Social Profiles Section ─────────────────────────────────
		add_settings_section(
			'perrylabs_seo_social',
			__( 'Social Profiles', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Used for Organization structured data and Open Graph defaults.', 'perrylabs-seo' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'twitter_handle',
			__( 'Twitter Handle', 'perrylabs-seo' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_social',
			array( 'key' => 'twitter_handle', 'placeholder' => '@biaboruzz', 'description' => __( 'Include the @ symbol.', 'perrylabs-seo' ) )
		);

		add_settings_field(
			'facebook_url',
			__( 'Facebook URL', 'perrylabs-seo' ),
			array( $this, 'render_url_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_social',
			array( 'key' => 'facebook_url', 'placeholder' => 'https://facebook.com/biobuzz' )
		);

		add_settings_field(
			'linkedin_url',
			__( 'LinkedIn URL', 'perrylabs-seo' ),
			array( $this, 'render_url_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_social',
			array( 'key' => 'linkedin_url', 'placeholder' => 'https://linkedin.com/company/biobuzz' )
		);

		add_settings_field(
			'default_social_image',
			__( 'Default Social Image', 'perrylabs-seo' ),
			array( $this, 'render_image_field' ),
			self::PAGE_SLUG,
			'perrylabs_seo_social',
			array( 'key' => 'default_social_image', 'description' => __( 'Fallback image for Open Graph and Twitter Cards when no featured image is set. Recommended: 1200x630px.', 'perrylabs-seo' ) )
		);

		// ── Sitemap Section ─────────────────────────────────────────
		add_settings_section(
			'perrylabs_seo_sitemap',
			__( 'XML Sitemap', 'perrylabs-seo' ),
			function () {
				$sitemap_url = home_url( '/sitemap.xml' );
				echo '<p>' . sprintf(
					/* translators: %s: sitemap URL */
					esc_html__( 'Your sitemap is available at %s', 'perrylabs-seo' ),
					'<a href="' . esc_url( $sitemap_url ) . '" target="_blank"><code>' . esc_html( $sitemap_url ) . '</code></a>'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'sitemap_post_types',
			__( 'Post Types', 'perrylabs-seo' ),
			array( $this, 'render_post_type_checkboxes' ),
			self::PAGE_SLUG,
			'perrylabs_seo_sitemap',
			array( 'key' => 'sitemap_post_types' )
		);

		add_settings_field(
			'sitemap_taxonomies',
			__( 'Taxonomies', 'perrylabs-seo' ),
			array( $this, 'render_taxonomy_checkboxes' ),
			self::PAGE_SLUG,
			'perrylabs_seo_sitemap',
			array( 'key' => 'sitemap_taxonomies' )
		);

		// ── Robots Defaults Section ─────────────────────────────────
		add_settings_section(
			'perrylabs_seo_robots',
			__( 'Robots Defaults', 'perrylabs-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Set noindex globally for archive types. Individual posts can override these.', 'perrylabs-seo' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$noindex_options = array(
			'noindex_archives'  => __( 'Date-based Archives', 'perrylabs-seo' ),
			'noindex_categories' => __( 'Category Archives', 'perrylabs-seo' ),
			'noindex_tags'      => __( 'Tag Archives', 'perrylabs-seo' ),
			'noindex_authors'   => __( 'Author Archives', 'perrylabs-seo' ),
		);

		foreach ( $noindex_options as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				array( $this, 'render_checkbox_field' ),
				self::PAGE_SLUG,
				'perrylabs_seo_robots',
				array( 'key' => $key, 'label' => sprintf(
					/* translators: %s: archive type */
					__( 'Add noindex to %s', 'perrylabs-seo' ),
					strtolower( $label )
				) )
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Default option values
	 * ────────────────────────────────────────────────────────────── */

	public static function defaults(): array {
		return array(
			'title_separator'     => '|',
			'default_description' => get_bloginfo( 'description' ),
			'twitter_handle'      => '',
			'facebook_url'        => '',
			'linkedin_url'        => '',
			'default_social_image' => '',
			'sitemap_post_types'  => array( 'post', 'page', 'biobuzz_news', 'biobuzz_event', 'biobuzz_contributor' ),
			'sitemap_taxonomies'  => array( 'category', 'post_tag', 'biobuzz_region', 'biobuzz_article_cat', 'biobuzz_event_cat' ),
			'noindex_archives'    => false,
			'noindex_categories'  => false,
			'noindex_tags'        => false,
			'noindex_authors'     => true,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Sanitize callback
	 * ────────────────────────────────────────────────────────────── */

	public function sanitize_options( mixed $input ): array {
		$defaults  = self::defaults();
		$sanitized = array();

		$sanitized['title_separator']      = sanitize_text_field( $input['title_separator'] ?? $defaults['title_separator'] );
		$sanitized['default_description']  = sanitize_textarea_field( $input['default_description'] ?? $defaults['default_description'] );
		$sanitized['twitter_handle']       = sanitize_text_field( $input['twitter_handle'] ?? '' );
		$sanitized['facebook_url']         = esc_url_raw( $input['facebook_url'] ?? '' );
		$sanitized['linkedin_url']         = esc_url_raw( $input['linkedin_url'] ?? '' );
		$sanitized['default_social_image'] = esc_url_raw( $input['default_social_image'] ?? '' );

		// Sitemap arrays.
		$sanitized['sitemap_post_types'] = array_map(
			'sanitize_text_field',
			(array) ( $input['sitemap_post_types'] ?? array() )
		);
		$sanitized['sitemap_taxonomies'] = array_map(
			'sanitize_text_field',
			(array) ( $input['sitemap_taxonomies'] ?? array() )
		);

		// Robots booleans.
		$sanitized['noindex_archives']   = ! empty( $input['noindex_archives'] );
		$sanitized['noindex_categories'] = ! empty( $input['noindex_categories'] );
		$sanitized['noindex_tags']       = ! empty( $input['noindex_tags'] );
		$sanitized['noindex_authors']    = ! empty( $input['noindex_authors'] );

		return $sanitized;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Field renderers
	 * ────────────────────────────────────────────────────────────── */

	public function render_text_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';
		$size    = $args['size'] ?? 40;
		$placeholder = $args['placeholder'] ?? '';

		printf(
			'<input type="text" name="%s[%s]" value="%s" size="%d" placeholder="%s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_attr( $value ),
			(int) $size,
			esc_attr( $placeholder )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_url_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';

		printf(
			'<input type="url" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_url( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);
	}

	public function render_textarea_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$value   = $options[ $args['key'] ] ?? '';

		printf(
			'<textarea name="%s[%s]" rows="3" cols="60" class="large-text">%s</textarea>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_textarea( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	public function render_checkbox_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, self::defaults() );
		$checked = ! empty( $options[ $args['key'] ] );

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			checked( $checked, true, false ),
			esc_html( $args['label'] )
		);
	}

	public function render_image_field( array $args ): void {
		$options   = get_option( self::OPTION_NAME, self::defaults() );
		$image_url = $options[ $args['key'] ] ?? '';

		echo '<div class="perrylabs-seo-image-field">';
		printf(
			'<input type="url" name="%s[%s]" value="%s" class="regular-text" id="perrylabs-seo-%s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['key'] ),
			esc_url( $image_url ),
			esc_attr( $args['key'] )
		);
		printf(
			' <button type="button" class="button perrylabs-seo-upload-image" data-target="perrylabs-seo-%s">%s</button>',
			esc_attr( $args['key'] ),
			esc_html__( 'Select Image', 'perrylabs-seo' )
		);

		if ( $image_url ) {
			printf( '<div style="margin-top:8px;"><img src="%s" style="max-width:300px;height:auto;" /></div>', esc_url( $image_url ) );
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
		echo '</div>';
	}

	public function render_post_type_checkboxes( array $args ): void {
		$options        = get_option( self::OPTION_NAME, self::defaults() );
		$selected       = (array) ( $options[ $args['key'] ] ?? array() );
		$post_types     = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $pt ) {
			if ( $pt->name === 'attachment' ) {
				continue;
			}
			$checked = in_array( $pt->name, $selected, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $args['key'] ),
				esc_attr( $pt->name ),
				checked( $checked, true, false ),
				esc_html( $pt->labels->name ),
				esc_html( $pt->name )
			);
		}
	}

	public function render_taxonomy_checkboxes( array $args ): void {
		$options    = get_option( self::OPTION_NAME, self::defaults() );
		$selected   = (array) ( $options[ $args['key'] ] ?? array() );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );

		foreach ( $taxonomies as $tax ) {
			if ( $tax->name === 'post_format' ) {
				continue;
			}
			$checked = in_array( $tax->name, $selected, true );
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s <code>%s</code></label>',
				esc_attr( self::OPTION_NAME ),
				esc_attr( $args['key'] ),
				esc_attr( $tax->name ),
				checked( $checked, true, false ),
				esc_html( $tax->labels->name ),
				esc_html( $tax->name )
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Settings page output
	 * ────────────────────────────────────────────────────────────── */

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Enqueue media uploader for image field.
		wp_enqueue_media();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('.perrylabs-seo-upload-image').on('click', function(e) {
				e.preventDefault();
				var button = $(this);
				var targetId = button.data('target');
				var frame = wp.media({
					title: '<?php echo esc_js( __( 'Select Social Image', 'perrylabs-seo' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Use this image', 'perrylabs-seo' ) ); ?>' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function() {
					var attachment = frame.state().get('selection').first().toJSON();
					$('#' + targetId).val(attachment.url);
					// Update preview.
					button.parent().find('img').remove();
					button.after('<div style="margin-top:8px;"><img src="' + attachment.url + '" style="max-width:300px;height:auto;" /></div>');
				});
				frame.open();
			});
		});
		</script>
		<?php
	}
}
