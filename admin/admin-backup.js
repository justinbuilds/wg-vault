/* global wgvAjax */
( function () {
	'use strict';

	// ---------------------------------------------------------------------------
	// Folder picker
	// ---------------------------------------------------------------------------

	/**
	 * Escape a string for safe HTML insertion.
	 *
	 * @param {string} str Raw string.
	 * @return {string} HTML-escaped string.
	 */
	function escHtml( str ) {
		const div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( String( str ) ) );
		return div.innerHTML;
	}

	/**
	 * Folder picker state and behaviour.
	 * Opens a modal that lets the admin browse Google Drive folders.
	 * Calling selectCurrentFolder() stores the selection in hidden form inputs
	 * and updates the breadcrumb display on the settings form.
	 */
	var wgvFolderPicker = ( function () {
		var currentFolderId   = 'root';
		var currentFolderName = 'My Drive';
		var navStack          = []; // Array of { id, name }

		function open() {
			navStack          = [];
			currentFolderId   = 'root';
			currentFolderName = 'My Drive';

			var modal = document.getElementById( 'wgv-folder-picker-modal' );
			if ( ! modal ) {
				return;
			}

			modal.style.display = 'flex';
			loadFolders( 'root' );
		}

		function close() {
			var modal = document.getElementById( 'wgv-folder-picker-modal' );
			if ( modal ) {
				modal.style.display = 'none';
			}
		}

		function loadFolders( parentId ) {
			var foldersEl = document.getElementById( 'wgv-picker-folders' );
			if ( ! foldersEl ) {
				return;
			}

			foldersEl.innerHTML = '<p class="wgv-picker-loading">Loading\u2026</p>';
			updatePickerBreadcrumb();

			var body = new URLSearchParams();
			body.append( 'action',    'wgv_list_drive_folders' );
			body.append( 'nonce',     wgvAjax.nonce );
			body.append( 'parent_id', parentId );

			fetch( wgvAjax.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( response ) {
					if ( ! response.success ) {
						foldersEl.innerHTML = '<p class="wgv-picker-error">Failed to load folders.</p>';
						return;
					}
					renderFolders( response.data.folders );
				} )
				.catch( function () {
					foldersEl.innerHTML = '<p class="wgv-picker-error">Request failed. Please try again.</p>';
				} );
		}

		function renderFolders( folders ) {
			var foldersEl = document.getElementById( 'wgv-picker-folders' );
			if ( ! foldersEl ) {
				return;
			}

			if ( ! folders || ! folders.length ) {
				foldersEl.innerHTML = '<p class="wgv-picker-empty">No sub-folders found.</p>';
				return;
			}

			foldersEl.innerHTML = '';

			folders.forEach( function ( folder ) {
				var btn = document.createElement( 'button' );
				btn.type      = 'button';
				btn.className = 'wgv-picker-folder-item';
				btn.setAttribute( 'role', 'listitem' );
				btn.innerHTML =
					'<span class="dashicons dashicons-category" aria-hidden="true"></span> ' +
					escHtml( folder.name );

				btn.addEventListener( 'click', function () {
					navigateInto( folder.id, folder.name );
				} );

				foldersEl.appendChild( btn );
			} );
		}

		function navigateInto( folderId, folderName ) {
			navStack.push( { id: currentFolderId, name: currentFolderName } );
			currentFolderId   = folderId;
			currentFolderName = folderName;
			loadFolders( folderId );
		}

		function updatePickerBreadcrumb() {
			var el = document.getElementById( 'wgv-picker-breadcrumb' );
			if ( ! el ) {
				return;
			}

			el.innerHTML = '';

			navStack.forEach( function ( item, idx ) {
				var navBtn = document.createElement( 'button' );
				navBtn.type      = 'button';
				navBtn.className = 'wgv-picker-nav-item';
				navBtn.textContent = item.name;
				navBtn.addEventListener( 'click', function () {
					var target = navStack[ idx ];
					navStack   = navStack.slice( 0, idx );
					currentFolderId   = target.id;
					currentFolderName = target.name;
					loadFolders( currentFolderId );
				} );
				el.appendChild( navBtn );

				var sep = document.createElement( 'span' );
				sep.className   = 'wgv-breadcrumb-sep';
				sep.textContent = ' / ';
				sep.setAttribute( 'aria-hidden', 'true' );
				el.appendChild( sep );
			} );

			var current = document.createElement( 'span' );
			current.className   = 'wgv-picker-nav-current';
			current.textContent = currentFolderName;
			el.appendChild( current );
		}

		/**
		 * Store the currently-browsed folder as the backup root and update the
		 * settings form breadcrumb.
		 *
		 * The selected folder becomes the ROOT folder. A site-domain subfolder is
		 * created automatically by get_or_create_folder() on the next backup run,
		 * so drive_folder_id is seeded to the same value and overwritten then.
		 */
		function selectCurrentFolder() {
			var folderName   = currentFolderName;
			var folderId     = currentFolderId === 'root' ? '' : currentFolderId;
			var rootFolderId = folderId;
			var siteDomain   = ( wgvAjax.siteDomain ) ? wgvAjax.siteDomain : '';

			var nameInput    = document.getElementById( 'wgv-folder-name-input' );
			var rootInput    = document.getElementById( 'wgv-root-folder-id-input' );
			var folderInput  = document.getElementById( 'wgv-folder-id-input' );
			var breadcrumbEl = document.getElementById( 'wgv-folder-breadcrumb' );

			if ( nameInput )   { nameInput.value   = folderName;   }
			if ( rootInput )   { rootInput.value   = rootFolderId; }
			if ( folderInput ) { folderInput.value  = folderId;     }

			if ( breadcrumbEl ) {
				breadcrumbEl.innerHTML =
					'<span>' + escHtml( folderName ) + '</span>' +
					'<span class="wgv-breadcrumb-sep" aria-hidden="true">&nbsp;/&nbsp;</span>' +
					'<span>' + escHtml( siteDomain ) + '</span>';
			}

			close();
		}

		return {
			open:               open,
			close:              close,
			selectCurrentFolder: selectCurrentFolder,
		};
	} )();

	document.addEventListener( 'DOMContentLoaded', function () {
		// Folder picker button bindings.
		var chooseBtn   = document.getElementById( 'wgv-choose-folder-btn' );
		var selectBtn   = document.getElementById( 'wgv-select-folder-btn' );
		var cancelBtn   = document.getElementById( 'wgv-cancel-picker' );
		var closeBtn    = document.getElementById( 'wgv-close-picker' );
		var backdrop    = document.getElementById( 'wgv-modal-backdrop' );

		if ( chooseBtn ) {
			chooseBtn.addEventListener( 'click', function () {
				wgvFolderPicker.open();
			} );
		}
		if ( selectBtn ) {
			selectBtn.addEventListener( 'click', function () {
				wgvFolderPicker.selectCurrentFolder();
			} );
		}
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				wgvFolderPicker.close();
			} );
		}
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				wgvFolderPicker.close();
			} );
		}
		if ( backdrop ) {
			backdrop.addEventListener( 'click', function () {
				wgvFolderPicker.close();
			} );
		}
	} );

	// ---------------------------------------------------------------------------
	// Manual backup buttons
	// ---------------------------------------------------------------------------

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
