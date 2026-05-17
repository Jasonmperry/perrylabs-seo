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

		// Schema display rules — row-based UI.
		$( document ).on( 'click', '.plseo-rules-add', function ( e ) {
			e.preventDefault();
			const $root   = $( this ).closest( '.plseo-rules' );
			const $body   = $root.find( '.plseo-rules-body' );
			const $tpl    = $root.find( '.plseo-rules-template' );
			if ( ! $tpl.length ) return;
			// Find a free row index (max existing + 1).
			let nextIdx = 0;
			$body.find( '.plseo-rules-row' ).each( function () {
				const i = parseInt( $( this ).attr( 'data-row' ), 10 );
				if ( ! isNaN( i ) && i >= nextIdx ) nextIdx = i + 1;
			} );
			// Clone template, replace __IDX__ placeholders.
			let html = $tpl.html().replace( /__IDX__/g, String( nextIdx ) );
			$body.append( html );
		} );

		$( document ).on( 'click', '.plseo-rules-del', function ( e ) {
			e.preventDefault();
			const $row  = $( this ).closest( '.plseo-rules-row' );
			const $body = $row.closest( '.plseo-rules-body' );
			$row.remove();
			// If we just removed the last row, drop a fresh blank one in so the
			// form still has something visible. Otherwise saving an empty rule
			// list submits no `schema_rules` key, which the sanitizer treats as
			// "no rules" — fine, but UX-confusing.
			if ( $body.find( '.plseo-rules-row' ).length === 0 ) {
				$body.closest( '.plseo-rules' ).find( '.plseo-rules-add' ).trigger( 'click' );
			}
		} );
	} );
} )( jQuery );
