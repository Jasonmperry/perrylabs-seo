/* PerryLabs SEO + AEO — per-post meta box */
( function ( $ ) {
	'use strict';

	$( function () {
		// Tab switching.
		$( document ).on( 'click', '.plseo-mb__tab', function ( e ) {
			e.preventDefault();
			const $btn   = $( this );
			const target = $btn.data( 'target' );
			$btn.siblings().removeClass( 'is-active' );
			$btn.addClass( 'is-active' );
			$btn.closest( '.plseo-mb' ).find( '.plseo-mb__panel' ).removeClass( 'is-active' );
			$btn.closest( '.plseo-mb' ).find( '.plseo-mb__panel[data-panel="' + target + '"]' ).addClass( 'is-active' );
		} );

		// Live character counters with good/warn/bad bands.
		function bandClass( len, lo, hi ) {
			if ( len === 0 ) return 'is-bad';
			if ( len < lo - 8 || len > hi + 16 ) return 'is-bad';
			if ( len < lo || len > hi ) return 'is-warn';
			return 'is-good';
		}
		function updateCounter( $counter ) {
			const targetId = $counter.data( 'target' );
			const $field   = $( '#' + targetId );
			if ( ! $field.length ) return;
			const good     = String( $counter.data( 'good' ) || '0,0' ).split( ',' );
			const lo       = parseInt( good[ 0 ], 10 );
			const hi       = parseInt( good[ 1 ], 10 );
			const len      = ( $field.val() || '' ).toString().length;
			$counter
				.removeClass( 'is-good is-warn is-bad' )
				.addClass( bandClass( len, lo, hi ) )
				.text( len + ' / ' + lo + '–' + hi + ' chars' );
		}
		$( '.plseo-counter' ).each( function () {
			const $c = $( this );
			updateCounter( $c );
			$( '#' + $c.data( 'target' ) ).on( 'input keyup change', function () { updateCounter( $c ); } );
		} );

		// Media picker (mirrors admin.js — duplicated so meta-box.js stays self-contained).
		$( document ).on( 'click', '.plseo-image-pick', function ( e ) {
			e.preventDefault();
			const $field = $( '#' + $( this ).data( 'target' ) );
			if ( ! $field.length || typeof wp === 'undefined' ) return;
			const frame = wp.media( { title: 'Choose an image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } } );
			frame.on( 'select', function () {
				$field.val( frame.state().get( 'selection' ).first().toJSON().url ).trigger( 'change' );
			} );
			frame.open();
		} );
	} );
} )( jQuery );
