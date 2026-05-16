/* PerryLabs SEO + AEO — admin scripts */
( function ( $ ) {
	'use strict';

	$( function () {
		// Media picker — works for any button with .plseo-image-pick and data-target=<input id>.
		$( document ).on( 'click', '.plseo-image-pick', function ( e ) {
			e.preventDefault();
			const targetId = $( this ).data( 'target' );
			const $field   = $( '#' + targetId );
			if ( ! $field.length ) return;

			const frame = wp.media( {
				title: 'Choose an image',
				button: { text: 'Use this image' },
				multiple: false,
				library: { type: 'image' }
			} );
			frame.on( 'select', function () {
				const att = frame.state().get( 'selection' ).first().toJSON();
				$field.val( att.url ).trigger( 'change' );
				// Refresh visible preview, if present.
				const $preview = $field.closest( '.plseo-image-field' ).find( '.plseo-image-preview-wrap' );
				if ( $preview.length ) {
					$preview.html( '<img src="' + att.url + '" alt="" class="plseo-image-preview" />' );
				}
			} );
			frame.open();
		} );

		$( document ).on( 'click', '.plseo-image-clear', function ( e ) {
			e.preventDefault();
			const $field = $( '#' + $( this ).data( 'target' ) );
			$field.val( '' ).trigger( 'change' );
			$field.closest( '.plseo-image-field' ).find( '.plseo-image-preview-wrap' ).empty();
		} );
	} );
} )( jQuery );
