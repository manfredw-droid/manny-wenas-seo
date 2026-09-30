/**
 * Manny Wenas SEO: bulk AI meta generation (sequential, one request per post).
 */
( function ( $, wp, cfg ) {
	'use strict';

	$( function () {
		$( '#mwseo-bulk-all' ).on( 'change', function () {
			$( '.mwseo-bulk-check' ).prop( 'checked', this.checked );
		} );

		$( '#mwseo-bulk-run' ).on( 'click', function () {
			var $btn = $( this ).prop( 'disabled', true );
			var overwrite = $( '#mwseo-overwrite' ).is( ':checked' );
			var $rows = $( '#mwseo-bulk-table tbody tr' ).filter( function () {
				return $( this ).find( '.mwseo-bulk-check' ).is( ':checked' );
			} ).toArray();

			( function next() {
				var row = $rows.shift();
				if ( ! row ) {
					$btn.prop( 'disabled', false );
					return;
				}
				var $row = $( row );
				$row.find( '.mwseo-c-status' ).text( cfg.working );
				wp.apiFetch( {
					path: '/mwseo/v1/ai/generate',
					method: 'POST',
					data: { post_id: $row.data( 'id' ), overwrite: overwrite }
				} ).then( function ( res ) {
					$row.find( '.mwseo-c-title' ).text( res.title );
					$row.find( '.mwseo-c-desc' ).text( res.desc );
					$row.find( '.mwseo-c-status' ).text( res.skipped ? cfg.skipped : cfg.done );
				} ).catch( function ( err ) {
					$row.find( '.mwseo-c-status' ).text( cfg.error + ': ' + ( ( err && err.message ) || '' ) );
				} ).then( next );
			}() );
		} );
	} );
} )( jQuery, window.wp, window.mwseoBulk );
