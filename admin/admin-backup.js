/* global wgvAjax */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		const buttons  = Array.from( document.querySelectorAll( '.wgv-button-backup' ) );
		const statusEl = document.getElementById( 'wgv-backup-status' );

		if ( ! buttons.length || ! statusEl ) {
			return;
		}

		function setButtonsDisabled( disabled ) {
			buttons.forEach( function ( btn ) {
				btn.disabled = disabled;
			} );
		}

		function showStatus( html, modifier ) {
			statusEl.className = 'wgv-backup-status wgv-backup-status--' + modifier;
			statusEl.innerHTML = html;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				const backupType = button.dataset.backupType;

				setButtonsDisabled( true );
				showStatus(
					'<span class="wgv-spinner"></span> Running ' + backupType + ' backup\u2026',
					'running'
				);

				const body = new URLSearchParams();
				body.append( 'action',      'wgv_run_backup' );
				body.append( 'backup_type', backupType );
				body.append( 'nonce',       wgvAjax.nonce );

				fetch( wgvAjax.ajaxurl, {
					method:      'POST',
					credentials: 'same-origin',
					body:        body,
				} )
					.then( function ( res ) {
						return res.json();
					} )
					.then( function ( response ) {
						if ( response.success ) {
							showStatus(
								'&#10003; ' + ( response.data.message || 'Backup completed successfully.' ),
								'success'
							);
							setTimeout( function () {
								window.location.reload();
							}, 2000 );
						} else {
							const msg = ( response.data && response.data.message )
								? response.data.message
								: 'Backup failed.';
							showStatus( '&#10007; ' + msg, 'error' );
							setButtonsDisabled( false );
						}
					} )
					.catch( function () {
						showStatus( '&#10007; Request failed. Please try again.', 'error' );
						setButtonsDisabled( false );
					} );
			} );
		} );
	} );
} )();
