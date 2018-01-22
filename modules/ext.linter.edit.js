( function ( mw, $ ) {
	$( function () {
		var location = mw.config.get( 'wgLinterErrorLocation' ),
			$textbox = $( '#wpTextbox1' );

		if ( location ) {
			if ( $textbox.length ) {
				$textbox.focus().textSelection( 'setSelection', { start: location[ 0 ], end: location[ 1 ] } );
			}
			mw.hook( 've.tempWikitextReady' ).add( function () {
				mw.libs.ve.tempWikitextEditor.$element[ 0 ].setSelectionRange( location[ 0 ], location[ 1 ] );
				mw.libs.ve.tempWikitextEditor.focus();
			} );
		}
	} );
}( mediaWiki, jQuery ) );
