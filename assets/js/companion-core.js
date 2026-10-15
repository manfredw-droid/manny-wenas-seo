/**
 * Manny Wenas SEO companion: helpers shared by the block-editor sidebar and
 * the Classic Editor box.
 *
 * Nothing here analyses content or writes to the post. It reads the result the
 * metabox script already received from the server-side scorer, and moves
 * focus to existing WordPress / Manny fields when the writer asks it to.
 *
 * @package MannyWenasSEO
 */

( function ( window, document, $ ) {
	'use strict';

	var cfg  = window.mwseoCompanionCfg || { i18n: {} };
	var core = window.mwseoCompanion = window.mwseoCompanion || {};

	core.cfg  = cfg;
	core.i18n = cfg.i18n || {};

	/**
	 * Which existing control each scorer check should lead to. Checks that have
	 * no sensible destination (internal links, image alt text) are left out on
	 * purpose: Manny never invents a navigation target.
	 */
	core.CHECK_ACTIONS = {
		body_keyphrase: { type: 'field', id: 'mwseo_focus', label: 'keyphrase' },
		intro_keyphrase: { type: 'intro', label: 'intro' },
		subheading_keyphrase: { type: 'headings', label: 'showHeadings' },
		headings: { type: 'headings', label: 'showHeadings' },
		title_keyphrase: { type: 'field', id: 'mwseo_title', label: 'seoTitle', skipWhenOwned: true },
		desc_keyphrase: { type: 'field', id: 'mwseo_desc', label: 'metaDesc', skipWhenOwned: true },
		slug_keyphrase: { type: 'slug', label: 'slug' },
		clean_slug: { type: 'slug', label: 'slug' },
		related_body: { type: 'field', id: 'mwseo_related_0', label: 'related' }
	};

	/** Latest analysis result the metabox script received (or null). */
	core.latest = function () {
		return window.mwseoLast || null;
	};

	/**
	 * Subscribe to analysis results. Returns an unsubscribe function.
	 *
	 * @param {Function} cb Called with each result object.
	 */
	core.onScore = function ( cb ) {
		var handler = function ( e, res ) {
			cb( res );
		};
		$( document ).on( 'mwseo:score', handler );
		return function () {
			$( document ).off( 'mwseo:score', handler );
		};
	};

	/** Plain text of an HTML fragment, without executing anything. */
	core.plainText = function ( html ) {
		var doc = new window.DOMParser().parseFromString( String( html == null ? '' : html ), 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	};

	/** Re-open a collapsed WordPress meta box so its field can take focus. */
	function openBox( el ) {
		var box = el.closest ? el.closest( '.postbox' ) : null;
		if ( box && box.classList.contains( 'closed' ) ) {
			var toggle = box.querySelector( '.handlediv, .postbox-header button[aria-expanded]' );
			if ( toggle ) {
				toggle.click();
			}
		}
	}

	function visible( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	/**
	 * Focus an existing field by id. Returns '' on success or a message key.
	 *
	 * @param {string} id Element id.
	 */
	core.focusField = function ( id ) {
		var el = document.getElementById( id );
		if ( ! el ) {
			return 'fieldMissing';
		}
		openBox( el );
		if ( ! visible( el ) ) {
			return 'fieldHidden';
		}
		el.scrollIntoView( { block: 'center', behavior: 'auto' } );
		el.focus( { preventScroll: true } );
		return '';
	};

	/**
	 * Move focus to the native URL / permalink control of the Classic Editor.
	 * Never edits the slug and never touches the Manny slug mirror.
	 */
	core.focusClassicSlug = function () {
		var open  = document.getElementById( 'new-post-slug' );
		var edit  = document.querySelector( '#edit-slug-buttons .edit-slug' );
		var target = open && visible( open ) ? open : edit;
		if ( ! target ) {
			return 'slugMissing';
		}
		target.scrollIntoView( { block: 'center', behavior: 'auto' } );
		target.focus( { preventScroll: true } );
		return 'slugHint';
	};

	/** Debounce without timers piling up. */
	core.debounce = function ( fn, wait ) {
		var t = null;
		return function () {
			var args = arguments, self = this;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( self, args );
			}, wait );
		};
	};

	/** Traffic-light state, mirroring the scorer's own `state` field when present. */
	core.ringState = function ( res ) {
		if ( ! res ) {
			return 'none';
		}
		if ( res.state ) {
			return res.state;
		}
		return res.score >= 80 ? 'good' : ( res.score >= 50 ? 'ok' : 'bad' );
	};

	core.verdictText = function ( res ) {
		if ( ! res ) {
			return core.i18n.analysing || '';
		}
		var v = res.verdict;
		if ( typeof v === 'string' ) {
			return v;
		}
		return v && typeof v.summary === 'string' ? v.summary : '';
	};

	/** Per-viewer UI preference storage. Failure is silent: the UI still works. */
	core.store = {
		get: function ( key, fallback ) {
			try {
				var raw = window.localStorage.getItem( 'mwseo_cmp_' + key );
				return raw === null ? fallback : JSON.parse( raw );
			} catch ( e ) {
				return fallback;
			}
		},
		set: function ( key, value ) {
			try {
				window.localStorage.setItem( 'mwseo_cmp_' + key, JSON.stringify( value ) );
			} catch ( e ) {} // eslint-disable-line no-empty
		}
	};
} )( window, document, window.jQuery );
