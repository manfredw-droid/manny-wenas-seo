/**
 * Manny Wenas SEO admin screens: media-library picker for attachment-ID settings.
 *
 * The stored value stays the numeric attachment ID in the original field. Without this
 * script the number field remains usable.
 */
( function ( $, wp, cfg ) {
	'use strict';
	if ( ! wp || ! wp.media || ! cfg ) { return; }
	var t = cfg.i18n || {};

	function enhance( root ) {
		var $root = $( root ), $input = $root.find( '.mwseo-media__id' ), $preview = $root.find( '.mwseo-media__preview' );
		if ( ! $input.length || $root.hasClass( 'is-enhanced' ) ) { return; }
		$root.addClass( 'is-enhanced' );
		var $select = $( '<button type="button" class="button"></button>' );
		var $remove = $( '<button type="button" class="button-link mwseo-media__remove"></button>' ).text( t.remove );
		var $empty = $( '<span class="mwseo-media__empty"></span>' ).text( t.none );
		$root.append( $select, $remove );
		var frame;

		function render( url ) {
			var has = parseInt( $input.val(), 10 ) > 0;
			$preview.empty();
			if ( has && url ) { $preview.append( $( '<img alt="">' ).attr( 'src', url ) ); }
			$empty.toggle( ! has );
			$remove.toggle( has );
			$select.text( has ? t.change : t.select );
			if ( ! $empty.parent().length ) { $preview.after( $empty ); }
		}

		$select.on( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( { title: t.chooseImage, button: { text: t.useImage }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var item = frame.state().get( 'selection' ).first().toJSON();
					var sizes = item.sizes || {};
					$input.val( item.id ).trigger( 'change' );
					render( ( sizes.thumbnail || sizes.full || item ).url );
				} );
			}
			frame.open();
			// Start on the library, not the upload tab, so an existing image is one click away.
			if ( frame.content && frame.content.mode ) { frame.content.mode( 'browse' ); }
		} );
		$remove.on( 'click', function () {
			$input.val( 0 ).trigger( 'change' );
			render( '' );
			$select.trigger( 'focus' );
		} );
		render( $preview.find( 'img' ).attr( 'src' ) );
	}

	$( function () { $( '[data-mwseo-media]' ).each( function () { enhance( this ); } ); } );
}( window.jQuery, window.wp, window.mwseoAdmin ) );
