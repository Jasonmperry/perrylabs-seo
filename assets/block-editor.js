/**
 * PerryLabs SEO + AEO — Gutenberg sidebar.
 *
 * Mounts a "PerryLabs SEO" sidebar in the block editor with the per-post SEO,
 * AEO, and advanced fields. Uses the post-meta REST endpoints registered by
 * PLSEO_Block_Editor so saves flow through the editor's native save path
 * (no separate AJAX, no separate Save button).
 *
 * Built with the WordPress JS globals — no build step required.
 */
( function ( wp, config ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.editPost ) {
		return;
	}

	const { registerPlugin } = wp.plugins;
	const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
	const { PanelBody, TextControl, TextareaControl, SelectControl, CheckboxControl, Notice } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { createElement: h, Fragment } = wp.element;
	const { __ } = wp.i18n;

	const SCHEMA_OPTIONS = Object.entries( config.schemaTypes || {} ).map( function ( pair ) {
		return { label: pair[ 1 ], value: pair[ 0 ] };
	} );

	function clampBand( len, lo, hi ) {
		if ( ! len ) return 'bad';
		if ( len < lo - 8 || len > hi + 16 ) return 'bad';
		if ( len < lo || len > hi ) return 'warn';
		return 'good';
	}

	function CharCounter( props ) {
		const len  = ( props.value || '' ).length;
		const band = clampBand( len, props.lo, props.hi );
		return h(
			'p',
			{ className: 'plseo-block-counter plseo-block-counter--' + band },
			len + ' / ' + props.lo + '–' + props.hi + ' chars'
		);
	}

	function readMeta( meta, key, fallback ) {
		if ( ! meta ) return fallback || '';
		const v = meta[ key ];
		return ( v === null || v === undefined ) ? ( fallback || '' ) : v;
	}

	function Sidebar() {
		const postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		const meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );

		function update( key, value ) {
			editPost( { meta: Object.assign( {}, meta, { [ key ]: value } ) } );
		}

		if ( ! postType ) {
			return h( PluginSidebar, { name: 'plseo-sidebar', title: 'PerryLabs SEO' },
				h( PanelBody, {}, h( Notice, { status: 'info', isDismissible: false }, __( 'Loading…', 'perrylabs-seo' ) ) )
			);
		}

		const seoTitle      = readMeta( meta, '_plseo_title' );
		const seoDesc       = readMeta( meta, '_plseo_description' );
		const canonical     = readMeta( meta, '_plseo_canonical' );
		const focusKeyword  = readMeta( meta, '_plseo_focus_keyword' );
		const quickAnswer   = readMeta( meta, '_plseo_quick_answer' );
		const schemaType    = readMeta( meta, '_plseo_schema_type' );
		const cornerstone   = readMeta( meta, '_plseo_cornerstone' ) === '1';
		const noindex       = readMeta( meta, '_plseo_noindex' ) === '1';
		const nofollow      = readMeta( meta, '_plseo_nofollow' ) === '1';
		const socialImage   = readMeta( meta, '_plseo_social_image' );

		const titleBands = config.titleBands || { min: 30, max: 60 };
		const descBands  = config.descBands  || { min: 120, max: 160 };

		return h( Fragment, {},
			h( PluginSidebarMoreMenuItem, { target: 'plseo-sidebar' }, 'PerryLabs SEO' ),
			h( PluginSidebar,
				{ name: 'plseo-sidebar', title: 'PerryLabs SEO', icon: 'chart-line' },
				h( PanelBody, { title: __( 'SEO basics', 'perrylabs-seo' ), initialOpen: true },
					h( TextControl, {
						label: __( 'SEO title', 'perrylabs-seo' ),
						help:  __( 'Leave empty to use the site title template.', 'perrylabs-seo' ),
						value: seoTitle,
						onChange: function ( v ) { update( '_plseo_title', v ); },
					} ),
					h( CharCounter, { value: seoTitle, lo: titleBands.min, hi: titleBands.max } ),
					h( TextareaControl, {
						label: __( 'Meta description', 'perrylabs-seo' ),
						value: seoDesc,
						rows: 3,
						onChange: function ( v ) { update( '_plseo_description', v ); },
					} ),
					h( CharCounter, { value: seoDesc, lo: descBands.min, hi: descBands.max } ),
					h( TextControl, {
						label: __( 'Canonical URL', 'perrylabs-seo' ),
						value: canonical,
						type:  'url',
						onChange: function ( v ) { update( '_plseo_canonical', v ); },
					} )
				),
				h( PanelBody, { title: __( 'AEO', 'perrylabs-seo' ), initialOpen: false },
					h( TextControl, {
						label: __( 'Focus keywords (comma-separated)', 'perrylabs-seo' ),
						value: focusKeyword,
						onChange: function ( v ) { update( '_plseo_focus_keyword', v ); },
					} ),
					h( TextareaControl, {
						label: __( 'Quick answer / TL;DR', 'perrylabs-seo' ),
						help:  __( '2–3 sentences AI answers can lift verbatim.', 'perrylabs-seo' ),
						value: quickAnswer,
						rows: 3,
						onChange: function ( v ) { update( '_plseo_quick_answer', v ); },
					} )
				),
				h( PanelBody, { title: __( 'Schema', 'perrylabs-seo' ), initialOpen: false },
					h( SelectControl, {
						label: __( 'Schema type override', 'perrylabs-seo' ),
						value: schemaType,
						options: SCHEMA_OPTIONS,
						onChange: function ( v ) { update( '_plseo_schema_type', v ); },
					} ),
					h( TextControl, {
						label: __( 'Social image URL', 'perrylabs-seo' ),
						value: socialImage,
						type:  'url',
						onChange: function ( v ) { update( '_plseo_social_image', v ); },
					} )
				),
				h( PanelBody, { title: __( 'Advanced', 'perrylabs-seo' ), initialOpen: false },
					h( CheckboxControl, {
						label: __( 'Cornerstone content', 'perrylabs-seo' ),
						help:  __( 'Sitemap priority 1.0, auto-included in /llms.txt featured.', 'perrylabs-seo' ),
						checked: cornerstone,
						onChange: function ( v ) { update( '_plseo_cornerstone', v ? '1' : '' ); },
					} ),
					h( CheckboxControl, {
						label: __( 'Noindex this post', 'perrylabs-seo' ),
						checked: noindex,
						onChange: function ( v ) { update( '_plseo_noindex', v ? '1' : '' ); },
					} ),
					h( CheckboxControl, {
						label: __( 'Nofollow links from this post', 'perrylabs-seo' ),
						checked: nofollow,
						onChange: function ( v ) { update( '_plseo_nofollow', v ? '1' : '' ); },
					} )
				)
			)
		);
	}

	registerPlugin( 'plseo-sidebar', { render: Sidebar, icon: 'chart-line' } );
} )( window.wp, window.PLSEO_BLOCK || {} );
