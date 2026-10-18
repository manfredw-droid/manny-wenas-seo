/** Native Gutenberg state and live analysis. No content or score is persisted here. */
( function ( window, document, wp ) {
	'use strict';
	var cfg = window.mwseoCompanionCfg || { i18n: {} };
	var core = window.mwseoCompanion = {};
	core.cfg = cfg;
	core.i18n = cfg.i18n;
	var listeners = [], latest = null, timer, generation = 0, last = null;
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


	core.latest = function () { return latest; };
	core.onScore = function ( cb ) {
		listeners.push( cb );
		return function () { listeners = listeners.filter( function ( fn ) { return fn !== cb; } ); };
	};
	function publish( result, error ) {
		latest = result;
		window.mwseoAnalysisError = error || '';
		listeners.slice().forEach( function ( fn ) { fn( result ); } );
	}
	core.plainText = function ( html ) {
		var doc = new window.DOMParser().parseFromString( String( html == null ? '' : html ), 'text/html' );
		return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	};
	core.focusField = function ( id ) {
		var node = document.getElementById( id );
		if ( ! node ) { return 'fieldHidden'; }
		node.scrollIntoView( { block: 'center', behavior: 'auto' } );
		node.focus( { preventScroll: true } );
		return '';
	};
	core.value = function ( meta, key ) {
		var value = meta[ '_mwseo_' + key ];
		return value === undefined ? ( key === 'related' ? [] : '' ) : value;
	};
	core.edit = function ( key, value ) {
		var patch = {};
		patch[ '_mwseo_' + key ] = value;
		wp.data.dispatch( 'core/editor' ).editPost( { meta: patch } );
	};
	function state() {
		var ed = wp.data.select( 'core/editor' );
		if ( ! ed || ! ed.getCurrentPostId || Number( ed.getCurrentPostId() ) !== Number( cfg.postId ) ||
			! ed.getCurrentPost() || Number( ed.getCurrentPost().id ) !== Number( cfg.postId ) ) { return null; }
		var meta = ed.getEditedPostAttribute( 'meta' );
		var content = ed.getEditedPostContent();
		if ( ! meta || typeof content !== 'string' ) { return null; }
		var result = { post_id: cfg.postId, content: content, slug: ed.getEditedPostAttribute( 'slug' ) || '' };
		[ 'focus', 'related', 'title', 'desc' ].forEach( function ( key ) {
			result[ key ] = cfg.ownsTitle && ( key === 'title' || key === 'desc' )
				? ( cfg.effectiveTitle || {} )[ key ] || '' : core.value( meta, key );
		} );
		return result;
	}
	function observe() {
		var data = state(), signature = JSON.stringify( data );
		if ( signature === last ) { return; }
		last = signature;
		var ticket = ++generation;
		clearTimeout( timer );
		if ( ! data ) { return; }
		timer = setTimeout( function () {
			wp.apiFetch( { path: '/mwseo/v1/analyze', method: 'POST', data: data } ).then( function ( result ) {
				if ( ticket === generation ) { publish( result ); }
			} ).catch( function () {
				if ( ticket === generation ) { publish( null, cfg.i18n.error ); }
			} );
		}, 700 );
	}
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
			return window.mwseoAnalysisError || core.i18n.analysing || '';
		}
		var v = res.verdict;
		if ( typeof v === 'string' ) {
			return v;
		}
		return v && typeof v.summary === 'string' ? v.summary : '';
	};


	// One subscription for the editor lifetime, independent of sidebar visibility.
	wp.data.subscribe( observe );
	observe();
} )( window, document, window.wp );
