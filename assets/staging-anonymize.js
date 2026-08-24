/**
 * Browser-driven anonymization run.
 *
 * The work happens in bounded ticks on the server; this only keeps asking
 * for the next one until the server says it is finished. Nothing about the
 * decision to proceed lives here — every guard (staging verdict, host
 * confirmation, capability, nonce) is re-checked server-side on every
 * single tick, because anything sent from a browser is a suggestion.
 */
( function () {
	'use strict';

	var config = window.braceStagingAnonymize;

	if ( ! config ) {
		return;
	}

	var button = document.getElementById( 'brace-sa-run' );
	var host = document.getElementById( 'brace-sa-host' );
	var backup = document.getElementById( 'brace-sa-backup' );
	var progress = document.getElementById( 'brace-sa-progress' );

	if ( ! button || ! host || ! progress ) {
		return;
	}

	function show( message, kind ) {
		progress.hidden = false;
		progress.className = 'notice notice-' + ( kind || 'info' ) + ' inline';
		progress.innerHTML = '';

		var paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		progress.appendChild( paragraph );

		return progress;
	}

	function showCounts( message, counts, kind ) {
		var box = show( message, kind );

		if ( ! counts ) {
			return;
		}

		var list = document.createElement( 'ul' );
		list.style.margin = '0 0 0 1.5em';
		list.style.listStyle = 'disc';

		Object.keys( counts ).forEach( function ( stage ) {
			var item = document.createElement( 'li' );
			item.textContent = stage + ': ' + counts[ stage ];
			list.appendChild( item );
		} );

		box.appendChild( list );
	}

	function tick( step ) {
		var body = new FormData();
		body.append( 'action', config.action );
		body.append( '_wpnonce', config.nonce );
		body.append( 'confirm_host', host.value );
		body.append( 'step', step );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var reason =
						payload && payload.data && payload.data.message
							? payload.data.message
							: response.status;
					throw new Error( reason );
				}

				return payload.data;
			} );
		} );
	}

	function run() {
		tick( 'run' )
			.then( function ( data ) {
				if ( ! data.finished ) {
					showCounts(
						config.i18n.stage.replace( '%s', data.stage ),
						data.changed
					);
					// Yield to the browser between ticks: a tight loop of
					// five-second requests with no gap reads as a hung tab.
					window.setTimeout( run, 200 );
					return;
				}

				showCounts( config.i18n.done, data.changed, 'success' );

				if ( data.kept && data.kept.length ) {
					var kept = document.createElement( 'p' );
					kept.innerHTML = '<strong></strong>';
					kept.querySelector( 'strong' ).textContent = data.kept.join( ' ' );
					progress.appendChild( kept );
				}

				button.disabled = false;
			} )
			.catch( function ( error ) {
				show(
					config.i18n.failed.replace( '%s', error.message || config.i18n.network ),
					'error'
				);
				button.disabled = false;
			} );
	}

	button.addEventListener( 'click', function () {
		if ( ! window.confirm( config.i18n.confirm ) ) {
			return;
		}

		button.disabled = true;

		if ( backup && backup.checked ) {
			show( config.i18n.backingUp );

			tick( 'backup' )
				.then( run )
				.catch( function ( error ) {
					show(
						config.i18n.failed.replace(
							'%s',
							error.message || config.i18n.network
						),
						'error'
					);
					button.disabled = false;
				} );

			return;
		}

		run();
	} );
} )();
