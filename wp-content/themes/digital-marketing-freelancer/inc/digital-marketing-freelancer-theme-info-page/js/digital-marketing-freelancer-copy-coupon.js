( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.digital-marketing-freelancer-pro-card__coupon' );

		if ( ! button ) {
			return;
		}

		var code = button.getAttribute( 'data-coupon-code' );

		if ( ! code ) {
			return;
		}

		var onCopied = function () {
			button.classList.add( 'is-copied' );

			clearTimeout( button.copiedTimeout );
			button.copiedTimeout = setTimeout( function () {
				button.classList.remove( 'is-copied' );
			}, 2000 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( code ).then( onCopied );
			return;
		}

		var textarea = document.createElement( 'textarea' );
		textarea.value = code;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();

		try {
			document.execCommand( 'copy' );
			onCopied();
		} finally {
			document.body.removeChild( textarea );
		}
	} );
} )();
