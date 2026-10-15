/**
 * Manny Wenas SEO companion: block editor (Gutenberg) sidebar.
 *
 * Uses WordPress's own PluginSidebar, so the companion lives in the native
 * sidebar region (pinned Manny icon in the editor toolbar) and never overlays
 * the writing canvas. Open/closed and pinned state are persisted by core.
 *
 * Headings come from the block-editor store (core/heading blocks), not from
 * the DOM. Clicking one calls the supported selectBlock() action; no content
 * or attribute is ever changed.
 *
 * @package MannyWenasSEO
 */

( function ( wp, core ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! core ) {
		return;
	}

	var el               = wp.element.createElement;
	var useState         = wp.element.useState;
	var useEffect        = wp.element.useEffect;
	var useRef           = wp.element.useRef;
	var Fragment         = wp.element.Fragment;
	var useSelect        = wp.data.useSelect;
	var Button           = wp.components.Button;
	var PanelBody        = wp.components.PanelBody;
	var t                = core.i18n;
	var editorApi        = wp.editor || {};
	var editPostApi      = wp.editPost || {};
	var PluginSidebar    = editorApi.PluginSidebar || editPostApi.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorApi.PluginSidebarMoreMenuItem || editPostApi.PluginSidebarMoreMenuItem;

	if ( ! PluginSidebar ) {
		return;
	}

	var NAME = 'mwseo-companion';

	/** Approved monogram, traced from the approved artwork (see assets/images/mwseo-logo.svg). */
	var LOGO_PATH = 'M331.49 105.7 L314.83 105.7 L243.72 293.08 L211.65 219.25 L204.75 233.04 L237.67 310.28 L244.76 324.05 L319.61 127.41 L319.61 295.25 L331.49 294.47Z M125.55 78.43 L125.55 185.86 L137.43 215.69 L137.43 108.14 L189.19 199.88 L195.72 187.64 L135.59 78.43Z M275.4 78.49 L265.48 78.49 L197.62 201.07 L157.22 292.1 L152.47 281.76 L86.27 106.12 L69.16 106.12 L69.16 294.77 L81.16 294.77 L81.16 129.53 L86.87 141.89 L155.93 323.75 L160.78 315.03 L207.49 207.37 L263.5 107.13 L263.49 121.21 L263.49 217.63 L275.4 185.98Z';

	var icon = el(
		wp.primitives.SVG,
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 400 400', width: 24, height: 24 },
		el( wp.primitives.Circle, { cx: 200, cy: 200, r: 200, fill: '#721E97' } ),
		el( wp.primitives.Path, { d: LOGO_PATH, fill: '#ffffff' } )
	);

	// --- Block-editor helpers -------------------------------------------------

	var textCache = {};
	function textOf( value ) {
		var key = String( value == null ? '' : value );
		if ( ! Object.prototype.hasOwnProperty.call( textCache, key ) ) {
			if ( Object.keys( textCache ).length > 500 ) {
				textCache = {};
			}
			textCache[ key ] = core.plainText( key );
		}
		return textCache[ key ];
	}

	/** H2/H3 headings as a JSON string, so unrelated editor changes do not re-render. */
	function headingSignature( select ) {
		var be  = select( 'core/block-editor' );
		var ids = be.getBlocksByName ? be.getBlocksByName( 'core/heading' ) : [];
		var out = [];
		ids.forEach( function ( id ) {
			var a     = be.getBlockAttributes( id ) || {};
			var level = Number( a.level ) || 2;
			if ( level === 2 || level === 3 ) {
				out.push( [ id, level, textOf( a.content ) ] );
			}
		} );
		return JSON.stringify( out );
	}

	function canvasDocuments() {
		var docs   = [ document ];
		var frames = document.querySelectorAll( 'iframe[name="editor-canvas"]' );
		Array.prototype.forEach.call( frames, function ( f ) {
			try {
				if ( f.contentDocument ) {
					docs.push( f.contentDocument );
				}
			} catch ( e ) {} // eslint-disable-line no-empty
		} );
		return docs;
	}

	/** selectBlock() focuses the block; this only makes sure it is on screen. */
	function ensureVisible( clientId ) {
		window.requestAnimationFrame( function () {
			canvasDocuments().some( function ( doc ) {
				var node = doc.getElementById( 'block-' + clientId );
				if ( ! node ) {
					return false;
				}
				var r = node.getBoundingClientRect();
				var h = doc.defaultView.innerHeight;
				if ( r.top < 0 || r.bottom > h ) {
					node.scrollIntoView( { block: 'center', behavior: 'auto' } );
				}
				return true;
			} );
		} );
	}

	function goToBlock( clientId ) {
		wp.data.dispatch( 'core/block-editor' ).selectBlock( clientId );
		ensureVisible( clientId );
	}

	function firstParagraphId() {
		var be  = wp.data.select( 'core/block-editor' );
		var ids = be.getBlocksByName ? be.getBlocksByName( 'core/paragraph' ) : [];
		for ( var i = 0; i < ids.length; i++ ) {
			var a = be.getBlockAttributes( ids[ i ] ) || {};
			if ( textOf( a.content ) ) {
				return ids[ i ];
			}
		}
		return null;
	}

	/** Open WordPress's own document sidebar and focus its URL control. */
	function goToNativeSlug( done ) {
		var ep = wp.data.dispatch( 'core/edit-post' );
		if ( ep && ep.openGeneralSidebar ) {
			ep.openGeneralSidebar( 'edit-post/document' );
		} else if ( wp.data.dispatch( 'core/interface' ) ) {
			wp.data.dispatch( 'core/interface' ).enableComplementaryArea( 'core/edit-post', 'edit-post/document' );
		}
		var tries = 0;
		( function look() {
			var target = document.querySelector( '.editor-post-url__panel-toggle, .edit-post-post-url__toggle, .editor-post-url .components-button' );
			if ( target ) {
				target.focus();
				done( 'slugHint' );
			} else if ( ++tries < 15 ) {
				setTimeout( look, 60 );
			} else {
				done( 'slugOpened' );
			}
		} )();
	}

	// --- UI -------------------------------------------------------------------

	function Ring( props ) {
		var res = props.res;
		return el(
			'div',
			{ className: 'mwseo-cmp__head' },
			el(
				'div',
				{ className: 'mwseo-ring mwseo-cmp__ring', 'data-state': core.ringState( res ), role: 'img', 'aria-label': t.score + ( res ? ': ' + res.score : '' ) },
				el( 'span', null, res ? res.score : '–' )
			),
			el( 'strong', { className: 'mwseo-cmp__verdict' }, core.verdictText( res ) )
		);
	}

	function Companion() {
		var _res    = useState( core.latest() );
		var res     = _res[ 0 ];
		var setRes  = _res[ 1 ];
		var _open   = useState( true );
		var open    = _open[ 0 ];
		var setOpen = _open[ 1 ];
		var _msg    = useState( '' );
		var msg     = _msg[ 0 ];
		var setMsg  = _msg[ 1 ];
		var listRef = useRef( null );

		useEffect( function () {
			setRes( core.latest() );
			return core.onScore( setRes );
		}, [] );

		var signature = useSelect( headingSignature, [] );
		var headings  = JSON.parse( signature );

		function say( key ) {
			setMsg( key ? ( t[ key ] || '' ) : '' );
		}

		function field( id ) {
			say( core.focusField( id ) );
		}

		function intro() {
			var id = firstParagraphId();
			if ( id ) {
				say( '' );
				goToBlock( id );
			} else {
				say( 'noIntro' );
			}
		}

		function showHeadings() {
			say( '' );
			setOpen( true );
			setTimeout( function () {
				var first = listRef.current && listRef.current.querySelector( 'button' );
				if ( first ) {
					first.focus();
				}
			}, 0 );
		}

		function act( action ) {
			if ( action.type === 'field' ) {
				field( action.id );
			} else if ( action.type === 'intro' ) {
				intro();
			} else if ( action.type === 'headings' ) {
				showHeadings();
			} else if ( action.type === 'slug' ) {
				goToNativeSlug( say );
			}
		}

		// Group H3 under the H2 before it. An H3 with no H2 before it stays top level.
		var tree = [];
		headings.forEach( function ( h ) {
			var node = { id: h[ 0 ], level: h[ 1 ], text: h[ 2 ], children: [] };
			if ( node.level === 3 && tree.length ) {
				tree[ tree.length - 1 ].children.push( node );
			} else {
				tree.push( node );
			}
		} );

		function headingItem( n ) {
			var label = t.goToHeading.replace( '%s', n.text || t.untitled );
			return el(
				'li',
				{ key: n.id, className: 'mwseo-cmp__heading mwseo-cmp__heading--h' + n.level },
				el(
					Button,
					{ className: 'mwseo-cmp__heading-btn', variant: 'tertiary', 'aria-label': label, onClick: function () {
						say( '' );
						goToBlock( n.id );
					} },
					el( 'span', { className: 'mwseo-cmp__level', 'aria-hidden': 'true' }, n.level === 3 ? t.level3 : t.level2 ),
					el( 'span', { className: 'mwseo-cmp__heading-text' }, n.text || t.untitled )
				),
				n.children.length ? el( 'ul', { className: 'mwseo-cmp__sub' }, n.children.map( headingItem ) ) : null
			);
		}

		var quick = [
			{ label: t.keyphrase, run: function () {
				field( 'mwseo_focus' );
			} }
		];
		if ( ! core.cfg.ownsTitle ) {
			quick.push( { label: t.seoTitle, run: function () {
				field( 'mwseo_title' );
			} } );
			quick.push( { label: t.metaDesc, run: function () {
				field( 'mwseo_desc' );
			} } );
		}
		quick.push( { label: t.slug, run: function () {
			goToNativeSlug( say );
		} } );

		var checks = ( res && res.checks ? res.checks : [] ).filter( function ( c ) {
			return c.message;
		} );

		return el(
			'div',
			{ className: 'mwseo-cmp' },
			el( Ring, { res: res } ),
			el(
				PanelBody,
				{ title: t.fields, initialOpen: true },
				el( 'div', { className: 'mwseo-cmp__quick' }, quick.map( function ( q ) {
					return el( Button, { key: q.label, variant: 'secondary', size: 'small', onClick: q.run }, q.label );
				} ) )
			),
			el(
				PanelBody,
				{ title: t.headings, opened: open, onToggle: function () {
					setOpen( ! open );
				} },
				el( 'div', { ref: listRef },
					tree.length
						? el( 'ul', { className: 'mwseo-cmp__headings' }, tree.map( headingItem ) )
						: el( 'p', { className: 'mwseo-cmp__empty' }, t.noHeadings )
				)
			),
			el(
				PanelBody,
				{ title: t.checks, initialOpen: false },
				el( 'ul', { className: 'mwseo-cmp__checks' }, checks.map( function ( c ) {
					var action = core.CHECK_ACTIONS[ c.id ];
					if ( action && action.skipWhenOwned && core.cfg.ownsTitle ) {
						action = null;
					}
					return el(
						'li',
						{ key: c.id, className: 'mwseo-' + c.status },
						el( 'span', { className: 'mwseo-pts' }, c.na ? t.na : c.points + '/' + c.max ),
						' ' + c.message + ' ',
						action ? el( Button, { variant: 'link', className: 'mwseo-cmp__go', onClick: function () {
							act( action );
						} }, t.jump + ' ' + ( t[ action.label ] || '' ) ) : null
					);
				} ) )
			),
			el( 'p', { className: 'mwseo-cmp__status', role: 'status' }, msg )
		);
	}

	function Plugin() {
		return el(
			Fragment,
			null,
			PluginSidebarMoreMenuItem ? el( PluginSidebarMoreMenuItem, { target: NAME, icon: icon }, t.title ) : null,
			el( PluginSidebar, { name: NAME, title: t.title, icon: icon, className: 'mwseo-cmp-sidebar' }, el( Companion ) )
		);
	}

	wp.plugins.registerPlugin( NAME, { render: Plugin, icon: icon } );
} )( window.wp, window.mwseoCompanion );
