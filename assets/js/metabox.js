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
	var slugAdapter = null;
	var slugBootSubscription = null;

	/** One editor intention; no second slug write through the metabox form. */
	function initSlugAdapter() {
		if ( ! document.getElementById ) {
			return;
		}
		var field = document.getElementById( 'mwseo_slug' );
		if ( ! field ) {
			return;
		}
		if ( ! document.body.classList.contains( 'block-editor-page' ) ) {
			field.readOnly = true;
			function observeClassicSlug() {
				var value = readClassicSlug();
				if ( field.value !== value ) {
					field.value = value;
					schedule();
				}
			}
			observeClassicSlug();
			$( '#post' ).on( 'input change keyup', '#post_name, #new-post-slug', observeClassicSlug );
			var box = document.getElementById( 'edit-slug-box' );
			if ( box && window.MutationObserver ) {
				new window.MutationObserver( observeClassicSlug ).observe( box, { childList: true, subtree: true, characterData: true } );
			}
			return;
		}
		var confirmed = field.value, draft = false, generation = 0, writing = false;
		var readNative, writeNative;
		var lastNative, saving = false, manual = false, refreshing = false;
		var block = document.body.classList.contains( 'block-editor-page' );
		var ed, dispatch, core;
		function availability( ready, message ) {
			field.readOnly = ! ready;
			var text = ready ? '' : ( message || cfg.i18n.slugNative );
			if ( $( '#mwseo-slug-help' ).text() !== text ) {
				$( '#mwseo-slug-help' ).text( text );
			}
		}
		function showNative() {
			var value = readNative();
			if ( value !== lastNative ) {
				lastNative = value;
				var edits = block ? ed.getPostEdits() : null;
				if ( ! writing && ( ( ! refreshing && ! saving ) ||
					( edits && Object.prototype.hasOwnProperty.call( edits, 'slug' ) ) ) ) {
					generation++;
					confirmed = value;
					draft = false;
					field.value = value;
					schedule();
				}
			}
		}
		function write( value ) {
			writing = true;
			lastNative = value;
			try {
				writeNative( value );
			} finally {
				writing = false;
			}
		}
		function confirm() {
			if ( ! draft ) {
				return;
			}
			if ( ! field.value.trim() ) {
				field.value = confirmed;
			}
			confirmed = field.value;
			draft = false;
			write( field.value );
			schedule();
		}
		function refreshSaved() {
			var ticket = generation, type = ed.getCurrentPostType(), id = ed.getCurrentPostId();
			refreshing = true;
			core.invalidateResolution( 'getEntityRecord', [ 'postType', type, id ] );
			Promise.resolve( wp.data.resolveSelect( 'core' ).getEntityRecord( 'postType', type, id ) ).then(
				function ( post ) {
					if ( ticket === generation && post && typeof post.slug === 'string' &&
						! Object.prototype.hasOwnProperty.call( ed.getPostEdits(), 'slug' ) ) {
						confirmed = post.slug;
						lastNative = readNative();
						field.value = draft ? field.value : post.slug;
						schedule();
					}
				}
			).catch( function () { availability( false ); } ).then( function () { refreshing = false; } );
		}
		if ( block ) {
			ed = editorSelect();
			dispatch = wp.data && wp.data.dispatch && wp.data.dispatch( 'core/editor' );
			core = wp.data && wp.data.dispatch && wp.data.dispatch( 'core' );
			if ( ! ed || ! ed.getEditedPostAttribute || ! ed.getCurrentPostId || ! ed.getCurrentPostType ||
				! ed.getPostEdits || ! ed.isSavingPost || ! ed.isAutosavingPost || ! ed.didPostSaveRequestSucceed ||
				! dispatch || ! dispatch.editPost || ! core || ! core.invalidateResolution ||
				! wp.data.resolveSelect || ! wp.data.subscribe || ! ed.getCurrentPostId() ) {
				availability( false );
				if ( wp.data && wp.data.subscribe && ! slugBootSubscription &&
					( ! ed || ! ed.getCurrentPostId || ! ed.getCurrentPostId() ) ) {
					slugBootSubscription = wp.data.subscribe( function () {
						// An emit that began before the unsubscribe can still call this listener again.
						if ( ! slugBootSubscription ) {
							return;
						}
						var ready = editorSelect();
						if ( ready && ready.getCurrentPostId && ready.getCurrentPostId() ) {
							var stop = slugBootSubscription;
							slugBootSubscription = null;
							stop();
							initSlugAdapter();
						}
					} );
				}
				return;
			}
			readNative = function () { return ed.getEditedPostAttribute( 'slug' ) || ''; };
			writeNative = function ( value ) { dispatch.editPost( { slug: value } ); };
			lastNative = confirmed = readNative();
			wp.data.subscribe( function () {
				var meta = wp.data.select( 'core/edit-post' );
				var active = ed.isSavingPost() || !! ( meta && meta.isSavingMetaBoxes && meta.isSavingMetaBoxes() );
				if ( ed.isSavingPost() && ! ed.isAutosavingPost() ) {
					manual = true;
				}
				var started = active && ! saving;
				var finished = saving && ! active;
				saving = active;
				if ( started ) {
					confirm();
				}
				if ( finished && manual && ed.didPostSaveRequestSucceed() ) {
					manual = false;
					refreshSaved();
				} else {
					showNative();
				}
			} );
		}
		field.value = confirmed;
		availability( true );
		slugAdapter = {
			read: function () { return draft ? field.value : readNative(); }
		};
		$( field ).on( 'input', function () {
			generation++;
			draft = true;
			write( field.value.trim() ? field.value : confirmed );
			schedule();
		} ).on( 'change blur', confirm ).on( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) { event.preventDefault(); confirm(); }
		} );
	}

	function editorSelect() {
		return wp.data && wp.data.select ? wp.data.select( 'core/editor' ) : null;
	}

	function getContent() {
		if ( document.body.classList.contains( 'block-editor-page' ) ) {
			var ed = contentEditor();
			var content = ed ? ed.getEditedPostContent() : null;
			return typeof content === 'string' ? content : null;
		}
		var classicEditor = window.tinyMCE && window.tinyMCE.get( 'content' );
		// Before TinyMCE initializes, the textarea still owns the existing content.
		if ( classicEditor && classicEditor.initialized && ! classicEditor.isHidden() ) {
			return classicEditor.getContent();
		}
		var value = $( '#content' ).val();
		return typeof value === 'string' ? value : null;
	}

	/** A loaded store is not necessarily the active post editor. */
	function contentEditor() {
		var ed = editorSelect();
		if ( ! ed || ! ed.getEditedPostContent || ! ed.getCurrentPostId ||
			! ed.getCurrentPostType || ! ed.getCurrentPost ) {
			return null;
		}
		var id = Number( ed.getCurrentPostId() );
		var post = ed.getCurrentPost();
		return id > 0 && id === Number( cfg.postId ) && ed.getCurrentPostType() &&
			post && Number( post.id ) === id ? ed : null;
	}

	function readClassicSlug() {
		return $( '#post_name' ).val() || $( '#editable-post-name-full' ).text() || '';
	}

	function getSlug() {
		if ( ! document.body.classList.contains( 'block-editor-page' ) ) {
			return readClassicSlug();
		}
		if ( slugAdapter ) {
			return slugAdapter.read();
		}
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

	// Traffic light: 80+ green, 50-79 orange, below 50 red (mirrors MWSEO_Scorer).
	function ringState( score ) {
		if ( score >= 80 ) {
			return 'good'; }
		if ( score >= 50 ) {
			return 'ok'; }
		return 'bad';
	}

	function render( res ) {
		// Read-only hand-off for the editor companion (no second analysis).
		window.mwseoLast = res;
		window.mwseoAnalysisError = '';
		$( '#mwseo-ring' ).attr( 'data-state', ringState( res.score ) );
		$( '#mwseo-ring-num' ).text( res.score );
		var verdict = res.verdict;
		var verdictText = '';
		if ( typeof verdict === 'string' ) {
			verdictText = verdict;
		} else if ( verdict && typeof verdict === 'object' && ! Array.isArray( verdict )
			&& typeof verdict.summary === 'string' ) {
			verdictText = verdict.summary;
		}
		$( '#mwseo-verdict' ).text( verdictText.trim() ? verdictText : cfg.i18n.error );
		$( '#mwseo-subscores' ).text(
			Object.keys( res.sections || {} ).map(
				function ( key ) {
					var s = res.sections[ key ];
					return s.label + ' ' + ( s.na ? cfg.i18n.na : s.points + '/' + s.max );
				}
			).join( ' · ' )
		);
		var $list = $( '#mwseo-checks' ).empty();
		res.checks.forEach(
			function ( c ) {
				if ( ! c.message ) {
						return;
				}
				$( '<li/>' )
				.addClass( 'mwseo-' + c.status )
				.append( $( '<span class="mwseo-pts"/>' ).text( c.na ? cfg.i18n.na : c.points + '/' + c.max ) )
				.append( document.createTextNode( ' ' + c.message ) )
				.appendTo( $list );
			}
		);
		var $tips = $( '#mwseo-tips' ).empty();
		( res.notices || [] ).forEach(
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
		var data = state();
		if ( typeof data.content !== 'string' ) {
			return;
		}
		wp.apiFetch( { path: '/mwseo/v1/analyze', method: 'POST', data: data } )
			.then(
				function ( res ) {
					if ( ticket === inFlight ) {
							render( res );
					}
				}
			)
			.catch(
				function () {
					if ( ticket === inFlight ) {
						window.mwseoLast = null;
						window.mwseoAnalysisError = cfg.i18n.error;
						$( '#mwseo-ring' ).attr( 'data-state', 'none' );
						$( '#mwseo-ring-num' ).text( '–' );
						$( '#mwseo-verdict' ).text( cfg.i18n.error );
						$( '#mwseo-subscores' ).text( '' );
						$( '#mwseo-checks, #mwseo-tips' ).empty();
						// Keep mwseo:score reserved for unchanged, successful result objects.
						try {
							$( document ).trigger( 'mwseo:analysis-error' );
						} catch ( e ) {} // eslint-disable-line no-empty
					}
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
		if ( ! cfg.hasGsc ) {
			$( '<li/>' ).text( cfg.i18n.notConnected ).appendTo( $ul );
			return;
		}
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

	var trendsTimer = null;
	var trendsReq   = null;

	/**
	 * Show the Google Trends indicator for the focus keyphrase. The widget stays
	 * hidden unless data is available.
	 */
	function loadTrends() {
		var $t     = $( '#mwseo-trends' );
		var phrase = $.trim( $( '#mwseo_focus' ).val() || '' );
		if ( trendsReq ) {
			trendsReq.abort();
			trendsReq = null;
		}
		$t.prop( 'hidden', true ).empty();
		if ( ! phrase || ! cfg.ajax ) {
			return;
		}
		trendsReq = $.post(
			cfg.ajax.url,
			{ action: cfg.ajax.action, nonce: cfg.ajax.nonce, keyphrase: phrase }
		).done(
			function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				$t.empty()
					.append( $( '<span class="mwseo-trends-dot"/>' ).attr( 'data-level', res.data.level ) )
					.append( document.createTextNode( cfg.i18n.trends + ': ' + cfg.i18n.average + ' ' + res.data.average + '/100' ) )
					.prop( 'hidden', false );
			}
		);
	}

	function scheduleTrends() {
		clearTimeout( trendsTimer );
		trendsTimer = setTimeout( loadTrends, 800 );
	}

	$(
		function () {
			initSlugAdapter();
			$( '#mwseo_focus' ).on( 'input', scheduleTrends );
			loadTrends();
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

			// Content updates follow the active editor, not store availability.
			function contentChanged() {
				++inFlight;
				schedule();
			}
			if ( document.body.classList.contains( 'block-editor-page' ) ) {
				var last = null;
				if ( ! wp.data || ! wp.data.subscribe ) {
					return;
				}
				wp.data.subscribe(
					function () {
						var ed = contentEditor();
						if ( ! ed ) {
							last = null;
							++inFlight;
							clearTimeout( timer );
							return;
						}
						var sig = ed.getEditedPostContent() + '|' + ( ed.getEditedPostAttribute( 'slug' ) || '' );
						if ( sig !== last ) {
								last = sig;
								contentChanged();
						}
					}
				);
			} else {
					var boundEditors = [];
					function bindContentEditor( ed ) {
						if ( ed && ed.id === 'content' && boundEditors.indexOf( ed ) === -1 ) {
							boundEditors.push( ed );
							ed.on( 'init input keyup change Undo Redo', contentChanged );
							contentChanged();
						}
					}
					$( document ).on(
						'tinymce-editor-init',
						function ( e, ed ) {
							bindContentEditor( ed );
						}
					);
					bindContentEditor( window.tinyMCE && window.tinyMCE.get( 'content' ) );
					$( '#content' ).on( 'input change', contentChanged );
					$( '#content-tmce, #content-html' ).on( 'click', function () {
						setTimeout( contentChanged, 0 );
					} );
			}
		}
	);
} )( jQuery, window.wp || {}, window.mwseoBox );
