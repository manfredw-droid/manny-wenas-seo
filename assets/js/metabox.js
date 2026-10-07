/**
 * Manny Wenas SEO: metabox live analysis.
 *
 * Analysis runs server-side (single source of truth); this script only
 * gathers editor state, debounces requests and renders the result.
 *
 * @package MannyWenasSEO
 */

( function ( $, wp, cfg ) {
	'use strict';

	if ( ! cfg ) {
		return;
	}

	var timer    = null;
	var inFlight = 0;

	function editorSelect() {
		return wp.data && wp.data.select ? wp.data.select( 'core/editor' ) : null;
	}

	function getContent() {
		var ed = editorSelect();
		if ( ed && ed.getEditedPostContent ) {
			return ed.getEditedPostContent();
		}
		if ( window.tinyMCE && window.tinyMCE.get( 'content' ) && ! window.tinyMCE.get( 'content' ).isHidden() ) {
			return window.tinyMCE.get( 'content' ).getContent();
		}
		return $( '#content' ).val() || '';
	}

	function getSlug() {
		var ed = editorSelect();
		if ( ed && ed.getEditedPostAttribute ) {
			return ed.getEditedPostAttribute( 'slug' ) || '';
		}
		return $( '#post_name' ).val() || $( '#editable-post-name-full' ).text() || '';
	}

	function state() {
		return {
			post_id: cfg.postId,
			focus: $( '#mwseo_focus' ).val(),
			related: $( '.mwseo-related-input' ).map(
				function () {
					return this.value; }
			).get(),
		title: $( '#mwseo_title' ).val(),
		desc: $( '#mwseo_desc' ).val(),
		slug: getSlug(),
		content: getContent()
		};
	}

	function ringState( score ) {
		if ( score >= 80 ) {
			return 'good'; }
		if ( score >= 50 ) {
			return 'ok'; }
		return 'bad';
	}

	function render( res ) {
		$( '#mwseo-ring' ).attr( 'data-state', ringState( res.score ) );
		$( '#mwseo-ring-num' ).text( res.score );
		$( '#mwseo-verdict' ).text( res.verdict );
		$( '#mwseo-subscores' ).text(
			cfg.i18n.seo + ' ' + res.seo.points + '/' + res.seo.max + ' · ' +
			cfg.i18n.readability + ' ' + res.readability.points + '/' + res.readability.max
		);
		var $list = $( '#mwseo-checks' ).empty();
		res.checks.forEach(
			function ( c ) {
				if ( ! c.message ) {
						return;
				}
				$( '<li/>' )
				.addClass( 'mwseo-' + c.status )
				.append( $( '<span class="mwseo-pts"/>' ).text( c.points + '/' + c.max ) )
				.append( document.createTextNode( ' ' + c.message ) )
				.appendTo( $list );
			}
		);
		var $tips = $( '#mwseo-tips' ).empty();
		( res.tips || [] ).forEach(
			function ( t ) {
				$( '<li/>' ).text( t ).appendTo( $tips );
			}
		);
		if ( window.wp && wp.data && wp.data.dispatch ) {
			try {
				$( document ).trigger( 'mwseo:score', [ res ] );
			} catch ( e ) {
			} // eslint-disable-line no-empty
		}
	}

	function analyse() {
		var ticket = ++inFlight;
		wp.apiFetch( { path: '/mwseo/v1/analyze', method: 'POST', data: state() } )
			.then(
				function ( res ) {
					if ( ticket === inFlight ) {
							render( res );
					}
				}
			)
			.catch(
				function () {
					$( '#mwseo-verdict' ).text( cfg.i18n.error );
				}
			);
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( analyse, 700 );
	}

	function counters() {
		$( '.mwseo-counter' ).each(
			function () {
				var $c = $( this );
				var $f = $( '#' + $c.data( 'for' ) );
				var n  = $f.val().length;
				var ok = n >= $c.data( 'min' ) && n <= $c.data( 'max' );
				$c.text( n + ' ' + cfg.i18n.chars + ' (' + $c.data( 'min' ) + '–' + $c.data( 'max' ) + ')' )
				.toggleClass( 'mwseo-counter-ok', ok );
			}
		);
	}

	function loadGsc() {
		if ( ! cfg.hasGsc || ! cfg.postId ) {
			return;
		}
		wp.apiFetch( { path: '/mwseo/v1/gsc/' + cfg.postId } ).then(
			function ( res ) {
				var $b = $( '#mwseo-gsc-body' ).empty();
				if ( ! res.rows || ! res.rows.length ) {
						$b.append( $( '<em/>' ).text( cfg.i18n.noData ) );
						return;
				}
				var $t = $( '<table class="widefat striped"><thead><tr><th>Query</th><th>Clicks</th><th>Impr.</th><th>Pos.</th></tr></thead><tbody/></table>' );
				res.rows.slice( 0, 10 ).forEach(
					function ( r ) {
						$( '<tr/>' )
						.append( $( '<td/>' ).text( r.query ) )
						.append( $( '<td/>' ).text( r.clicks ) )
						.append( $( '<td/>' ).text( r.impressions ) )
						.append( $( '<td/>' ).text( r.position ) )
						.appendTo( $t.find( 'tbody' ) );
					}
				);
				$b.append( $t );
				window.mwseoGscRows = res.rows;
			}
		).catch(
			function ( err ) {
				$( '#mwseo-gsc-body' ).text( ( err && err.message ) || cfg.i18n.error );
			}
		);
	}

	function suggest() {
		var focus = ( $( '#mwseo_focus' ).val() || '' ).toLowerCase();
		var rows  = ( window.mwseoGscRows || [] ).filter(
			function ( r ) {
				return r.query.toLowerCase() !== focus;
			}
		).slice( 0, 6 );
		var $ul   = $( '#mwseo-suggestions' ).empty();
		if ( ! rows.length ) {
			$( '<li/>' ).text( cfg.i18n.noData ).appendTo( $ul );
			return;
		}
		rows.forEach(
			function ( r ) {
				var $li = $( '<li/>' ).text( r.query + ' (' + r.impressions + ') ' );
				$( '<button type="button" class="button-link"/>' ).text( cfg.i18n.use ).on(
					'click',
					function () {
						var $empty = $( '.mwseo-related-input' ).filter(
							function () {
								return ! this.value; }
						).first();
						( $empty.length ? $empty : $( '.mwseo-related-input' ).last() ).val( r.query ).trigger( 'input' );
					}
				).appendTo( $li );
				$li.appendTo( $ul );
			}
		);
	}

	$(
		function () {
			$( '.mwseo-box' ).on(
				'input change',
				'input, textarea',
				function () {
					counters();
					schedule();
				}
			);
			$( '#mwseo-suggest' ).on( 'click', suggest );
			$( '#title' ).on( 'input', schedule );
			counters();
			analyse();
			loadGsc();

			// Block editor: re-analyse when the content or slug changes.
			if ( wp.data && wp.data.subscribe && editorSelect() ) {
				var last = '';
				wp.data.subscribe(
					function () {
						var ed = editorSelect();
						if ( ! ed || ! ed.getEditedPostContent ) {
									return;
						}
						var sig = ed.getEditedPostContent() + '|' + ( ed.getEditedPostAttribute( 'slug' ) || '' );
						if ( sig !== last ) {
								last = sig;
								schedule();
						}
					}
				);
			} else {
					$( document ).on(
						'tinymce-editor-init',
						function ( e, ed ) {
							ed.on( 'keyup change', schedule );
						}
					);
					$( '#content' ).on( 'input', schedule );
			}
		}
	);
} )( jQuery, window.wp || {}, window.mwseoBox );
