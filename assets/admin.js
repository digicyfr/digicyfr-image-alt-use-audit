( function () {
	'use strict';
	var notice = document.querySelector( '.diau-result' );
	var pause = document.querySelector( '[data-diau-pause="1"]' );
	if ( notice && ! pause ) {
		window.addEventListener( 'load', function () {
			window.setTimeout( function () { notice.focus(); }, 100 );
		} );
	}
	var form = document.querySelector( 'form[data-diau-autocontinue="1"]' );
	if ( form ) {
		window.addEventListener( 'load', function () {
			var interrupted = false;
			if ( pause ) { pause.focus(); }
			[ 'keydown', 'pointerdown' ].forEach( function ( eventName ) {
				document.addEventListener( eventName, function () { interrupted = true; }, { once: true } );
			} );
			window.setTimeout( function () {
				if ( ! interrupted && 'visible' === document.visibilityState ) { HTMLFormElement.prototype.submit.call( form ); }
			}, 10000 );
		} );
	}
} )();
