<?php
/**
 * PLSEO_User_Profile — Author E-E-A-T fields on the user-profile screen.
 *
 * PLSEO_Schema_Content::author_person() already reads three user-meta keys:
 *   - plseo_same_as     (one URL per line; becomes the Person.sameAs array)
 *   - plseo_credentials (free text; becomes Person.hasCredential.credentialCategory)
 *   - plseo_expertise   (comma-separated; becomes Person.knowsAbout)
 *
 * This module gives those fields a UI on Users → Edit so they actually get
 * populated. E-E-A-T (Experience, Expertise, Authoritativeness, Trust) is
 * weighted heavily by Google in 2024+ and surfaces directly in AI overviews,
 * so having the author Person node fully fleshed out is a meaningful signal.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_User_Profile {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'show_user_profile',        array( $this, 'render_fields' ) );
		add_action( 'edit_user_profile',        array( $this, 'render_fields' ) );
		add_action( 'personal_options_update',  array( $this, 'save_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_fields' ) );
	}

	public function render_fields( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$same_as     = (string) get_user_meta( $user->ID, 'plseo_same_as', true );
		$credentials = (string) get_user_meta( $user->ID, 'plseo_credentials', true );
		$expertise   = (string) get_user_meta( $user->ID, 'plseo_expertise', true );
		?>
		<h2><?php esc_html_e( 'Author E-E-A-T (PerryLabs SEO)', 'perrylabs-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These fields flow into the Person schema node when this user authors a post. Google and AI search engines weight Experience, Expertise, Authoritativeness, and Trust signals — completing this section meaningfully improves citation likelihood.', 'perrylabs-seo' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="plseo_same_as"><?php esc_html_e( 'Author profile URLs (one per line)', 'perrylabs-seo' ); ?></label></th>
				<td>
					<textarea name="plseo_same_as" id="plseo_same_as" rows="4" class="large-text code" placeholder="https://www.linkedin.com/in/yourhandle/&#10;https://twitter.com/yourhandle&#10;https://github.com/yourhandle"><?php echo esc_textarea( $same_as ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Becomes the Person.sameAs array. Include LinkedIn, X/Twitter, GitHub, Mastodon, Wikipedia, ORCID, or any canonical profile URL for this author.', 'perrylabs-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="plseo_credentials"><?php esc_html_e( 'Credentials', 'perrylabs-seo' ); ?></label></th>
				<td>
					<input type="text" name="plseo_credentials" id="plseo_credentials" value="<?php echo esc_attr( $credentials ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Ph.D. in Computer Science, MD, JD, CPA', 'perrylabs-seo' ); ?>" />
					<p class="description"><?php esc_html_e( 'Becomes Person.hasCredential.credentialCategory. Short text. Most relevant for medical, legal, financial, or scientific authors where credentials matter for content trust.', 'perrylabs-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="plseo_expertise"><?php esc_html_e( 'Areas of expertise (comma-separated)', 'perrylabs-seo' ); ?></label></th>
				<td>
					<input type="text" name="plseo_expertise" id="plseo_expertise" value="<?php echo esc_attr( $expertise ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. AI strategy, cloud architecture, public health policy', 'perrylabs-seo' ); ?>" />
					<p class="description"><?php esc_html_e( 'Becomes Person.knowsAbout — a structured list of topics this author is qualified to write about. Helps AI engines surface the right author for the right query.', 'perrylabs-seo' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		update_user_meta(
			$user_id,
			'plseo_same_as',
			sanitize_textarea_field( (string) ( $_POST['plseo_same_as'] ?? '' ) )
		);
		update_user_meta(
			$user_id,
			'plseo_credentials',
			sanitize_text_field( (string) ( $_POST['plseo_credentials'] ?? '' ) )
		);
		update_user_meta(
			$user_id,
			'plseo_expertise',
			sanitize_text_field( (string) ( $_POST['plseo_expertise'] ?? '' ) )
		);
	}
}
