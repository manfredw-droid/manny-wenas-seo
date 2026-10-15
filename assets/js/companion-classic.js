/**
 * Manny Wenas SEO companion: Classic Editor box.
 *
 * Lives in WordPress's native side column. Minimise / hide / reorder use the
 * native meta box controls (persisted by core). Headings are read from
 * TinyMCE (Visual) or the textarea (Text); navigation only moves the cursor
 * and never edits the content.
 *
 * @package MannyWenasSEO
 */

( function ( window, document, $, core ) {
	'use strict';

	var box = document.getElementById( 'mwseo-cmp' );
	if ( ! box || ! core ) {
		return;
	}

	var t        = core.i18n;
	var $status  = $( '#mwseo-cmp-status' );
	var $list    = $( '#mwseo-cmp-headings' );
	var lastSig  = null;
	var headings = [];

	function say( key ) {
		$status.text( key ? ( t[ key ] || '' ) : '' );
	}

	function editor() {
		var ed = window.tinyMCE && window.tinyMCE.get( 'content' );
		return ed && ! ed.isHidden() ? ed : null;
	}

	/** H2/H3 in document order, from whichever editor tab is showing. */
	function readHeadings() {
		var ed  = editor();
		var out = [];
		if ( ed ) {
			Array.prototype.forEach.call( ed.getBody().querySelectorAll( 'h2, h3' ), function ( node, i ) {
				out.push( { index: i, level: node.nodeName === 'H3' ? 3 : 2, text: core.plainText( node.innerHTML ), node: node } );
			} );
			return out;
		}
		var raw = $( '#content' ).val() || '';
		var re  = /<h([23])\b[^>]*>([\s\S]*?)<\/h\1>/gi;
		var m, i = 0;
		while ( ( m = re.exec( raw ) ) ) {
			out.push( { index: i++, level: Number( m[ 1 ] ), text: core.plainText( m[ 2 ] ), offset: m.index } );
		}
		return out;
	}

	function renderHeadings() {
		headings = readHeadings();
		var sig = JSON.stringify( headings.map( function ( h ) {
			return [ h.level, h.text ];
		} ) );
		if ( sig === lastSig ) {
			return; // Nothing the writer would see has changed.
		}
		lastSig = sig;
		$list.empty();
		if ( ! headings.length ) {
			$( '<li class="mwseo-cmp__empty"/>' ).text( t.noHeadings ).appendTo( $list );
			return;
		}
		headings.forEach( function ( h, i ) {
			var $li = $( '<li/>' ).addClass( 'mwseo-cmp__heading mwseo-cmp__heading--h' + h.level );
			if ( h.level === 3 ) {
				$li.css( 'margin-left', '16px' );
			}
			$( '<button type="button" class="mwseo-cmp__heading-btn"/>' )
				.attr( 'aria-label', t.goToHeading.replace( '%s', h.text || t.untitled ) )
				.append( $( '<span class="mwseo-cmp__level" aria-hidden="true"/>' ).text( h.level === 3 ? t.level3 : t.level2 ) )
				.append( $( '<span class="mwseo-cmp__heading-text"/>' ).text( h.text || t.untitled ) )
				.on( 'click', function () {
					goToHeading( i );
				} )
				.appendTo( $li );
			$li.appendTo( $list );
		} );
	}

	/** Place the cursor at the heading. Visual: TinyMCE API. Text: textarea selection. */
	function goToHeading( i ) {
		var ed = editor();
		var h  = readHeadings()[ i ];
		if ( ! h ) {
			return;
		}
		say( '' );
		if ( ed ) {
			ed.focus();
			ed.selection.setCursorLocation( h.node, 0 );
			ed.selection.scrollIntoView( h.node );
			return;
		}
		var ta = document.getElementById( 'content' );
		if ( ta ) {
			placeInTextarea( ta, h.offset );
		}
	}

	/** Text tab: set the caret, then bring that line into the textarea's view (approximate for wrapped lines). */
	function placeInTextarea( ta, offset ) {
		ta.focus( { preventScroll: true } );
		ta.setSelectionRange( offset, offset );
		var before = ta.value.slice( 0, offset ).split( '\n' ).length - 1;
		var lh     = parseFloat( window.getComputedStyle( ta ).lineHeight ) || 20;
		ta.scrollTop = Math.max( 0, before * lh - ta.clientHeight / 3 );
		ta.scrollIntoView( { block: 'nearest', behavior: 'auto' } );
	}

	function goToIntro() {
		var ed = editor();
		if ( ed ) {
			var p = Array.prototype.filter.call( ed.getBody().querySelectorAll( 'p' ), function ( n ) {
				return core.plainText( n.innerHTML ) !== '';
			} )[ 0 ];
			if ( ! p ) {
				say( 'noIntro' );
				return;
			}
			say( '' );
			ed.focus();
			ed.selection.setCursorLocation( p, 0 );
			ed.selection.scrollIntoView( p );
			return;
		}
		var ta = document.getElementById( 'content' );
		if ( ta && ta.value.trim() ) {
			say( '' );
			placeInTextarea( ta, 0 );
		} else {
			say( 'noIntro' );
		}
	}

	function act( action ) {
		if ( action.type === 'field' ) {
			say( core.focusField( action.id ) );
		} else if ( action.type === 'intro' ) {
			goToIntro();
		} else if ( action.type === 'headings' ) {
			var first = $list.find( 'button' ).get( 0 );
			if ( first ) {
				say( '' );
				first.focus();
			} else {
				say( 'noHeadings' );
			}
		} else if ( action.type === 'slug' ) {
			say( core.focusClassicSlug() );
		}
	}

	// ---- Quick buttons --------------------------------------------------------

	function addQuick( label, run ) {
		$( '<button type="button" class="button button-small"/>' ).text( label ).on( 'click', run ).appendTo( '#mwseo-cmp-quick' );
	}
	addQuick( t.keyphrase, function () {
		say( core.focusField( 'mwseo_focus' ) );
	} );
	if ( ! core.cfg.ownsTitle ) {
		addQuick( t.seoTitle, function () {
			say( core.focusField( 'mwseo_title' ) );
		} );
		addQuick( t.metaDesc, function () {
			say( core.focusField( 'mwseo_desc' ) );
		} );
	}
	addQuick( t.slug, function () {
		say( core.focusClassicSlug() );
	} );

	// ---- Score + checks (read from the existing analysis) -----------------------

	function renderScore( res ) {
		$( '#mwseo-cmp-ring' ).attr( 'data-state', core.ringState( res ) );
		$( '#mwseo-cmp-ring-num' ).text( res ? res.score : '–' );
		$( '#mwseo-cmp-ring' ).attr( 'aria-label', t.score + ( res ? ': ' + res.score : '' ) );
		$( '#mwseo-cmp-verdict' ).text( core.verdictText( res ) );
		var $checks = $( '#mwseo-cmp-checks' ).empty();
		( res && res.checks ? res.checks : [] ).forEach( function ( c ) {
			if ( ! c.message ) {
				return;
			}
			var $li = $( '<li/>' ).addClass( 'mwseo-' + c.status );
			$( '<span class="mwseo-pts"/>' ).text( c.na ? t.na : c.points + '/' + c.max ).appendTo( $li );
			$li.append( document.createTextNode( ' ' + c.message + ' ' ) );
			var action = core.CHECK_ACTIONS[ c.id ];
			if ( action && ! ( action.skipWhenOwned && core.cfg.ownsTitle ) ) {
				$( '<button type="button" class="mwseo-cmp__go"/>' )
					.text( t.jump + ' ' + ( t[ action.label ] || '' ) )
					.on( 'click', function () {
						act( action );
					} ).appendTo( $li );
			}
			$li.appendTo( $checks );
		} );
	}
	renderScore( core.latest() );
	core.onScore( renderScore );

	// ---- Live headings: bound to editor events, no polling -----------------------

	var refresh = core.debounce( renderHeadings, 400 );
	var bound   = [];
	function bindEditor( ed ) {
		if ( ed && ed.id === 'content' && bound.indexOf( ed ) === -1 ) {
			bound.push( ed );
			ed.on( 'input keyup change Undo Redo SetContent', refresh );
			refresh();
		}
	}
	$( document ).on( 'tinymce-editor-init', function ( e, ed ) {
		bindEditor( ed );
	} );
	bindEditor( window.tinyMCE && window.tinyMCE.get( 'content' ) );
	$( '#content' ).on( 'input change', refresh );
	$( '#content-tmce, #content-html' ).on( 'click', function () {
		lastSig = null;
		setTimeout( renderHeadings, 0 );
	} );
	renderHeadings();

	// ---- Optional: hide while scrolling down (off by default) -------------------

	var $auto = $( '#mwseo-cmp-autohide' );
	$auto.prop( 'checked', !! core.store.get( 'autohide', false ) );

	var lastY = window.pageYOffset, ticking = false;
	function onScroll() {
		if ( ticking ) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame( function () {
			var y = window.pageYOffset;
			if ( Math.abs( y - lastY ) > 12 ) {
				box.classList.toggle( 'is-away', $auto.prop( 'checked' ) &&
					! box.contains( document.activeElement ) && y > lastY && y > 200 );
				lastY = y;
			}
			ticking = false;
		} );
	}
	function applyAutoHide() {
		var on = $auto.prop( 'checked' );
		core.store.set( 'autohide', on );
		window.removeEventListener( 'scroll', onScroll );
		box.classList.remove( 'is-away' );
		if ( on ) {
			lastY = window.pageYOffset;
			window.addEventListener( 'scroll', onScroll, { passive: true } );
		}
	}
	$auto.on( 'change', applyAutoHide );
	applyAutoHide();

	// A focused control inside the companion must never be hidden under the writer's keyboard.
	box.addEventListener( 'focusin', function () {
		box.classList.remove( 'is-away' );
	} );
} )( window, document, window.jQuery, window.mwseoCompanion );
