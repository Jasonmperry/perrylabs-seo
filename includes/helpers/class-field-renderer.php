<?php
/**
 * PLSEO_Field_Renderer — single source for rendering Settings API fields.
 *
 * Every field render is a static method that accepts an args array
 * with a 'key' (the option key). Settings tabs add a field with
 * callback ::text, ::url, ::number, ::textarea, ::checkbox, ::select,
 * ::image, ::multicheck_post_types, ::multicheck_taxonomies, ::repeater,
 * ::code_textarea. No tab files write HTML directly for fields.
 *
 * @package PerryLabs\SEO
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PLSEO_Field_Renderer {

	private const NAME_PREFIX = PLSEO_Options::OPTION_NAME;

	public static function text( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (string) PLSEO_Options::get( $key, '' );
		$size  = (int) ( $args['size'] ?? 40 );
		$ph    = (string) ( $args['placeholder'] ?? '' );

		printf(
			'<input type="text" id="plseo-%1$s" name="%2$s[%1$s]" value="%3$s" size="%4$d" placeholder="%5$s" class="regular-text" />',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			esc_attr( $value ),
			$size,
			esc_attr( $ph )
		);
		self::description( $args );
	}

	public static function url( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (string) PLSEO_Options::get( $key, '' );
		printf(
			'<input type="url" id="plseo-%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text code" placeholder="https://example.com" />',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			esc_attr( $value )
		);
		self::description( $args );
	}

	public static function number( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (string) PLSEO_Options::get( $key, '' );
		$min   = isset( $args['min'] ) ? ' min="' . esc_attr( (string) $args['min'] ) . '"' : '';
		$max   = isset( $args['max'] ) ? ' max="' . esc_attr( (string) $args['max'] ) . '"' : '';
		$step  = isset( $args['step'] ) ? ' step="' . esc_attr( (string) $args['step'] ) . '"' : '';

		printf(
			'<input type="number" id="plseo-%1$s" name="%2$s[%1$s]" value="%3$s"%4$s%5$s%6$s class="small-text" />',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			esc_attr( $value ),
			$min,
			$max,
			$step
		);
		self::description( $args );
	}

	public static function textarea( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (string) PLSEO_Options::get( $key, '' );
		$rows  = (int) ( $args['rows'] ?? 4 );

		printf(
			'<textarea id="plseo-%1$s" name="%2$s[%1$s]" rows="%3$d" class="large-text">%4$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			$rows,
			esc_textarea( $value )
		);
		self::description( $args );
	}

	public static function code_textarea( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (string) PLSEO_Options::get( $key, '' );
		$rows  = (int) ( $args['rows'] ?? 8 );

		printf(
			'<textarea id="plseo-%1$s" name="%2$s[%1$s]" rows="%3$d" class="large-text code" spellcheck="false">%4$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			$rows,
			esc_textarea( $value )
		);
		self::description( $args );
	}

	public static function checkbox( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (bool) PLSEO_Options::get( $key, false );
		$label = (string) ( $args['inline_label'] ?? '' );

		printf(
			'<label for="plseo-%1$s"><input type="checkbox" id="plseo-%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			checked( $value, true, false ),
			esc_html( $label )
		);
		self::description( $args );
	}

	public static function select( array $args ): void {
		$key     = (string) ( $args['key'] ?? '' );
		$value   = (string) PLSEO_Options::get( $key, '' );
		$choices = (array) ( $args['choices'] ?? array() );

		printf( '<select id="plseo-%1$s" name="%2$s[%1$s]">', esc_attr( $key ), esc_attr( self::NAME_PREFIX ) );
		foreach ( $choices as $option_value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $option_value ),
				selected( $value, (string) $option_value, false ),
				esc_html( (string) $label )
			);
		}
		echo '</select>';
		self::description( $args );
	}

	public static function image( array $args ): void {
		$key       = (string) ( $args['key'] ?? '' );
		$value     = (string) PLSEO_Options::get( $key, '' );
		$preview   = $value ? sprintf( '<img src="%s" alt="" class="plseo-image-preview" />', esc_url( $value ) ) : '';

		printf(
			'<div class="plseo-image-field">
				<input type="url" id="plseo-%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text code" />
				<button type="button" class="button plseo-image-pick" data-target="plseo-%1$s">%4$s</button>
				<button type="button" class="button plseo-image-clear" data-target="plseo-%1$s">%5$s</button>
				<div class="plseo-image-preview-wrap">%6$s</div>
			</div>',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			esc_attr( $value ),
			esc_html__( 'Choose image', 'perrylabs-seo' ),
			esc_html__( 'Clear', 'perrylabs-seo' ),
			$preview
		);
		self::description( $args );
	}

	public static function multicheck_post_types( array $args ): void {
		$key      = (string) ( $args['key'] ?? '' );
		$selected = (array) PLSEO_Options::get( $key, array() );
		$types    = get_post_types( array( 'public' => true ), 'objects' );

		echo '<fieldset>';
		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			printf(
				'<label style="display:inline-block;margin-right:1em;"><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s /> %5$s</label>',
				esc_attr( self::NAME_PREFIX ),
				esc_attr( $key ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $selected, true ), true, false ),
				esc_html( $type->labels->name )
			);
		}
		echo '</fieldset>';
		self::description( $args );
	}

	public static function multicheck_taxonomies( array $args ): void {
		$key      = (string) ( $args['key'] ?? '' );
		$selected = (array) PLSEO_Options::get( $key, array() );
		$taxes    = get_taxonomies( array( 'public' => true ), 'objects' );

		echo '<fieldset>';
		foreach ( $taxes as $tax ) {
			printf(
				'<label style="display:inline-block;margin-right:1em;"><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s /> %5$s</label>',
				esc_attr( self::NAME_PREFIX ),
				esc_attr( $key ),
				esc_attr( $tax->name ),
				checked( in_array( $tax->name, $selected, true ), true, false ),
				esc_html( $tax->labels->name )
			);
		}
		echo '</fieldset>';
		self::description( $args );
	}

	/**
	 * Repeater of free-form CSS selectors (one per line). Used for speakable selectors.
	 */
	public static function selector_list( array $args ): void {
		$key   = (string) ( $args['key'] ?? '' );
		$value = (array) PLSEO_Options::get( $key, array() );
		$text  = implode( "\n", array_map( 'strval', $value ) );
		printf(
			'<textarea id="plseo-%1$s" name="%2$s[%1$s]" rows="4" class="large-text code" spellcheck="false">%3$s</textarea>',
			esc_attr( $key ),
			esc_attr( self::NAME_PREFIX ),
			esc_textarea( $text )
		);
		self::description( $args );
	}

	private static function description( array $args ): void {
		if ( ! empty( $args['description_html'] ) ) {
			echo '<p class="description">' . wp_kses_post( (string) $args['description_html'] ) . '</p>';
		} elseif ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}
}
