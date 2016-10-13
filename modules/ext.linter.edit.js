( function ( mw, $ ) {
	$( function () {
		var location = mw.config.get( 'wgLinterErrorLocation' );
		if ( location ) {
			$( '#wpTextbox1' ).focus().textSelection( 'setSelection', { start: location[ 0 ], end: location[ 1 ] } );
		}
	} );
}( mediaWiki, jQuery ) );
