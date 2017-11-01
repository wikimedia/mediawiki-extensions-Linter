( function ( mw, $ ) {
	$( function () {
		var location = mw.config.get( 'wgLinterErrorLocation' ),
			$textbox = $( '#wpTextbox1' );

		/**
		 * Convert the normal offset for one that is usable
		 * by VE's DOM that changes newlines into <p>
		 *
		 * @param {ve.ui.Surface} surface
		 * @param {int} offset
		 * @return {int}
		 */
		function fixOffset( surface, offset ) {
			return ( surface.getDom().slice( 0, offset ).match( /\n/g ) || [] ).length + 1 + offset;
		}

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
						range = new ve.Range( fixOffset( surface, location[ 0 ] ), fixOffset( surface, location[ 1 ] ) );
						surface.getModel().setLinearSelection( range );
					}
				} );
			} );
		}
	} );
}( mediaWiki, jQuery ) );
