( function ( mw, $ ) {
	$( function () {
		var location = mw.config.get( 'wgLinterErrorLocation' ),
			$textbox = $( '#wpTextbox1' );

		if ( location ) {
			if ( $textbox.length ) {
				$textbox.focus().textSelection( 'setSelection', { start: location[ 0 ], end: location[ 1 ] } );
			}
			// Register NWE code should it be loaded
			// TODO: We should somehow force source mode if VE is opened
			mw.hook( 've.activationComplete' ).add( function () {
				// Selection is reset on a setTimeout after activation, so wait for that.
				setTimeout( function () {
					var range,
						surface = ve.init.target.getSurface();

					if ( surface.getMode() === 'source' ) {
						range = surface.getModel().getRangeFromSourceOffsets( location[ 0 ], location[ 1 ] );
						surface.getModel().setLinearSelection( range );
					}
				} );
			} );
		}
	} );
}( mediaWiki, jQuery ) );
