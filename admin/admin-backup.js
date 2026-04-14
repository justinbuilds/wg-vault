/* global wgvAjax */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initBackupButtons();
		initFolderPicker();
		initRestoreTab();
	} );

	// =========================================================================
	// Manual backup buttons
	// =========================================================================

	function initBackupButtons() {
		var buttons  = Array.from( document.querySelectorAll( '.wgv-button-backup' ) );
		var statusEl = document.getElementById( 'wgv-backup-status' );

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
				var backupType = button.dataset.backupType;

				setButtonsDisabled( true );
				showStatus(
					'<span class="wgv-spinner"></span> Running ' + backupType + ' backup\u2026',
					'running'
				);

				var body = new URLSearchParams();
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
							var msg = ( response.data && response.data.message )
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
	}

	// =========================================================================
	// Folder picker
	// =========================================================================

	// Navigation stack — each entry: { id: string, name: string }
	// An empty stack means we are viewing Drive root.
	var pickerStack    = [];
	var pickerOverlay  = null;
	var pickerCallback = null; // optional function(selected) called on folder select

	function initFolderPicker() {
		var openBtn = document.getElementById( 'wgv-open-folder-picker' );
		if ( openBtn ) {
			openBtn.addEventListener( 'click', function () {
				pickerStack    = [];
				pickerCallback = null;
				openModal();
			} );
		}

		var restoreBtn = document.getElementById( 'wgv-restore-browse-folder' );
		if ( restoreBtn ) {
			restoreBtn.addEventListener( 'click', function () {
				pickerStack    = [];
				pickerCallback = function ( selected ) {
					var idInput = document.getElementById( 'wgv-restore-folder-id' );
					var display = document.getElementById( 'wgv-restore-folder-display' );
					if ( idInput ) {
						idInput.value = selected.id;
					}
					if ( display ) {
						display.textContent = selected.name;
					}
					// Enable the Load Backups button once a folder is picked.
					var loadBtn = document.getElementById( 'wgv-load-backups' );
					if ( loadBtn ) {
						loadBtn.disabled = false;
					}
				};
				openModal();
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Modal lifecycle
	// -------------------------------------------------------------------------

	function openModal() {
		if ( pickerOverlay ) {
			return;
		}

		pickerOverlay = buildModalDOM();
		document.body.appendChild( pickerOverlay );
		trapFocus( pickerOverlay );
		loadFolders( 'root' );
	}

	function closeModal() {
		if ( pickerOverlay ) {
			document.body.removeChild( pickerOverlay );
			pickerOverlay = null;
		}
	}

	function buildModalDOM() {
		var overlay = document.createElement( 'div' );
		overlay.className  = 'wgv-modal-overlay';
		overlay.id         = 'wgv-modal-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', 'Select Google Drive Folder' );

		overlay.innerHTML =
			'<div class="wgv-modal">' +
				'<div class="wgv-modal-header">' +
					'<h2 class="wgv-modal-title">Select Google Drive Folder</h2>' +
					'<button type="button" class="wgv-modal-close" id="wgv-modal-close" aria-label="Close">&times;</button>' +
				'</div>' +
				'<nav class="wgv-modal-breadcrumb" id="wgv-modal-breadcrumb" aria-label="Folder path"></nav>' +
				'<div class="wgv-modal-search-wrap">' +
					'<input type="text" class="wgv-modal-search" id="wgv-modal-search" placeholder="Search folders\u2026" autocomplete="off">' +
				'</div>' +
				'<div class="wgv-modal-folder-list" id="wgv-modal-folder-list" role="listbox" aria-label="Folders"></div>' +
				'<div class="wgv-modal-footer">' +
					'<button type="button" class="button button-primary" id="wgv-modal-select">Select This Folder</button>' +
					'<button type="button" class="button button-secondary" id="wgv-modal-new-folder">New Folder</button>' +
					'<button type="button" class="button" id="wgv-modal-cancel">Cancel</button>' +
				'</div>' +
			'</div>';

		// Close on overlay backdrop click.
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				closeModal();
			}
		} );

		overlay.querySelector( '#wgv-modal-close' ).addEventListener( 'click', closeModal );
		overlay.querySelector( '#wgv-modal-cancel' ).addEventListener( 'click', closeModal );

		overlay.querySelector( '#wgv-modal-select' ).addEventListener( 'click', function () {
			selectCurrentFolder();
		} );

		overlay.querySelector( '#wgv-modal-new-folder' ).addEventListener( 'click', function () {
			showNewFolderInput();
		} );

		overlay.querySelector( '#wgv-modal-search' ).addEventListener( 'input', function () {
			filterFolderList( this.value );
		} );

		// Close on Escape key.
		overlay.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				closeModal();
			}
		} );

		return overlay;
	}

	// -------------------------------------------------------------------------
	// Breadcrumb
	// -------------------------------------------------------------------------

	function renderBreadcrumb() {
		var nav = document.getElementById( 'wgv-modal-breadcrumb' );
		if ( ! nav ) {
			return;
		}
		nav.innerHTML = '';

		var rootBtn = document.createElement( 'button' );
		rootBtn.type      = 'button';
		rootBtn.className = 'wgv-modal-breadcrumb-item';
		rootBtn.textContent = 'My Drive';
		rootBtn.addEventListener( 'click', function () {
			navigateTo( -1 );
		} );
		nav.appendChild( rootBtn );

		pickerStack.forEach( function ( item, index ) {
			var sep = document.createElement( 'span' );
			sep.className   = 'wgv-modal-breadcrumb-sep';
			sep.textContent = '/';
			sep.setAttribute( 'aria-hidden', 'true' );
			nav.appendChild( sep );

			var btn = document.createElement( 'button' );
			btn.type      = 'button';
			btn.className = 'wgv-modal-breadcrumb-item';
			btn.textContent = item.name;
			( function ( idx ) {
				btn.addEventListener( 'click', function () {
					navigateTo( idx );
				} );
			}( index ) );
			nav.appendChild( btn );
		} );
	}

	// Navigate breadcrumb: index -1 = root, otherwise trim stack to index.
	function navigateTo( index ) {
		if ( index < 0 ) {
			pickerStack = [];
			renderBreadcrumb();
			loadFolders( 'root' );
		} else {
			pickerStack = pickerStack.slice( 0, index + 1 );
			renderBreadcrumb();
			loadFolders( pickerStack[ pickerStack.length - 1 ].id );
		}
	}

	// -------------------------------------------------------------------------
	// Folder list
	// -------------------------------------------------------------------------

	function loadFolders( parentId ) {
		renderBreadcrumb();
		clearNewFolderInput();

		var listEl = document.getElementById( 'wgv-modal-folder-list' );
		var searchEl = document.getElementById( 'wgv-modal-search' );
		if ( ! listEl ) {
			return;
		}

		if ( searchEl ) {
			searchEl.value = '';
		}

		listEl.innerHTML = '<div class="wgv-modal-spinner-wrap"><span class="wgv-spinner"></span></div>';

		var body = new URLSearchParams();
		body.append( 'action',    'wgv_list_folders' );
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
				if ( response.success ) {
					renderFolderItems( response.data );
				} else {
					showFolderListError( 'Could not load folders. Is Google Drive connected?' );
				}
			} )
			.catch( function () {
				showFolderListError( 'Request failed. Please try again.' );
			} );
	}

	function renderFolderItems( folders ) {
		var listEl = document.getElementById( 'wgv-modal-folder-list' );
		if ( ! listEl ) {
			return;
		}

		listEl.innerHTML = '';

		if ( ! folders || ! folders.length ) {
			var empty = document.createElement( 'p' );
			empty.className   = 'wgv-modal-empty';
			empty.textContent = 'No folders found here.';
			listEl.appendChild( empty );
			return;
		}

		folders.forEach( function ( folder ) {
			var item = document.createElement( 'div' );
			item.className          = 'wgv-modal-folder-item';
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'data-id',   folder.id );
			item.setAttribute( 'data-name', folder.name );
			item.innerHTML =
				'<span class="dashicons dashicons-portfolio wgv-modal-folder-icon" aria-hidden="true"></span>' +
				'<span class="wgv-modal-folder-name">' + escapeHTML( folder.name ) + '</span>' +
				'<span class="wgv-modal-folder-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';

			item.addEventListener( 'click', function () {
				pickerStack.push( { id: folder.id, name: folder.name } );
				loadFolders( folder.id );
			} );

			listEl.appendChild( item );
		} );
	}

	function filterFolderList( query ) {
		var listEl = document.getElementById( 'wgv-modal-folder-list' );
		if ( ! listEl ) {
			return;
		}
		var items = listEl.querySelectorAll( '.wgv-modal-folder-item' );
		var q     = query.toLowerCase();

		items.forEach( function ( item ) {
			var name = ( item.getAttribute( 'data-name' ) || '' ).toLowerCase();
			item.style.display = ( ! q || name.indexOf( q ) !== -1 ) ? '' : 'none';
		} );
	}

	function showFolderListError( message ) {
		var listEl = document.getElementById( 'wgv-modal-folder-list' );
		if ( listEl ) {
			listEl.innerHTML =
				'<p class="wgv-modal-error">' + escapeHTML( message ) + '</p>';
		}
	}

	// -------------------------------------------------------------------------
	// New Folder inline input
	// -------------------------------------------------------------------------

	function clearNewFolderInput() {
		var existing = document.getElementById( 'wgv-new-folder-row' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}
		var newFolderBtn = document.getElementById( 'wgv-modal-new-folder' );
		if ( newFolderBtn ) {
			newFolderBtn.disabled = false;
		}
	}

	function showNewFolderInput() {
		var newFolderBtn = document.getElementById( 'wgv-modal-new-folder' );
		var listEl       = document.getElementById( 'wgv-modal-folder-list' );
		if ( ! listEl ) {
			return;
		}

		clearNewFolderInput();
		if ( newFolderBtn ) {
			newFolderBtn.disabled = true;
		}

		var row = document.createElement( 'div' );
		row.className = 'wgv-modal-new-folder-row';
		row.id        = 'wgv-new-folder-row';
		row.innerHTML =
			'<input type="text" class="wgv-modal-new-folder-input" id="wgv-new-folder-name" placeholder="New folder name\u2026">' +
			'<button type="button" class="button button-primary" id="wgv-create-folder-btn">Create</button>' +
			'<button type="button" class="button" id="wgv-cancel-new-folder-btn">Cancel</button>';

		listEl.insertBefore( row, listEl.firstChild );
		document.getElementById( 'wgv-new-folder-name' ).focus();

		document.getElementById( 'wgv-cancel-new-folder-btn' ).addEventListener( 'click', clearNewFolderInput );

		document.getElementById( 'wgv-create-folder-btn' ).addEventListener( 'click', function () {
			var name = document.getElementById( 'wgv-new-folder-name' ).value.trim();
			if ( ! name ) {
				return;
			}
			submitNewFolder( name );
		} );

		document.getElementById( 'wgv-new-folder-name' ).addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				var name = this.value.trim();
				if ( name ) {
					submitNewFolder( name );
				}
			}
		} );
	}

	function submitNewFolder( name ) {
		var createBtn = document.getElementById( 'wgv-create-folder-btn' );
		if ( createBtn ) {
			createBtn.disabled = true;
			createBtn.textContent = 'Creating\u2026';
		}

		var parentId = pickerStack.length
			? pickerStack[ pickerStack.length - 1 ].id
			: 'root';

		var body = new URLSearchParams();
		body.append( 'action',      'wgv_create_folder' );
		body.append( 'nonce',       wgvAjax.nonce );
		body.append( 'folder_name', name );
		body.append( 'parent_id',   parentId );

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
					// Navigate into the newly created folder.
					pickerStack.push( { id: response.data.id, name: response.data.name } );
					loadFolders( response.data.id );
				} else {
					showFolderListError( 'Failed to create folder. Please try again.' );
					clearNewFolderInput();
				}
			} )
			.catch( function () {
				showFolderListError( 'Request failed. Please try again.' );
				clearNewFolderInput();
			} );
	}

	// -------------------------------------------------------------------------
	// Select folder
	// -------------------------------------------------------------------------

	function selectCurrentFolder() {
		if ( ! pickerStack.length ) {
			// User clicked "Select This Folder" at the root level — not useful.
			closeModal();
			return;
		}

		var selected = pickerStack[ pickerStack.length - 1 ];

		if ( pickerCallback ) {
			// Delegate to the caller-supplied callback (e.g. Restore tab browse).
			pickerCallback( selected );
			pickerCallback = null;
		} else {
			// Default: update the Google Drive settings tab inputs.
			var inputName = document.getElementById( 'wgv-input-folder-name' );
			var inputId   = document.getElementById( 'wgv-input-root-folder-id' );
			if ( inputName ) {
				inputName.value = selected.name;
			}
			if ( inputId ) {
				inputId.value = selected.id;
			}

			var display = document.getElementById( 'wgv-folder-picker-display' );
			if ( display ) {
				display.innerHTML =
					escapeHTML( selected.name ) +
					' <span class="wgv-folder-picker__sep" aria-hidden="true">/</span> ' +
					escapeHTML( wgvAjax.siteDomain );
			}

			var reminder = document.getElementById( 'wgv-folder-save-reminder' );
			if ( reminder ) {
				reminder.style.display = '';
			}
		}

		closeModal();
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function escapeHTML( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	function trapFocus( el ) {
		el.focus();
	}

	// =========================================================================
	// Restore tab
	// =========================================================================

	function initRestoreTab() {
		initLoadBackups();
		initUploadRestore();
	}

	// -------------------------------------------------------------------------
	// Load Backups (Drive folder → backup list table)
	// -------------------------------------------------------------------------

	function initLoadBackups() {
		var btn = document.getElementById( 'wgv-load-backups' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var folderIdInput = document.getElementById( 'wgv-restore-folder-id' );
			var folderId      = folderIdInput ? folderIdInput.value.trim() : '';

			if ( ! folderId ) {
				showBackupListState( '<p class="wgv-modal-error">Please select a folder first.</p>' );
				return;
			}

			showBackupListState( '<div class="wgv-modal-spinner-wrap"><span class="wgv-spinner"></span></div>' );
			btn.disabled = true;

			var body = new URLSearchParams();
			body.append( 'action',    'wgv_list_backups' );
			body.append( 'nonce',     wgvAjax.nonce );
			body.append( 'folder_id', folderId );

			fetch( wgvAjax.ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        body,
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( response ) {
					btn.disabled = false;
					if ( response.success && response.data ) {
						renderBackupList( response.data );
					} else {
						showBackupListState( '<p class="wgv-modal-error">Could not load backups. Is Google Drive connected?</p>' );
					}
				} )
				.catch( function () {
					btn.disabled = false;
					showBackupListState( '<p class="wgv-modal-error">Request failed. Please try again.</p>' );
				} );
		} );
	}

	function showBackupListState( html ) {
		var wrap = document.getElementById( 'wgv-backup-list-wrap' );
		if ( wrap ) {
			wrap.innerHTML = html;
		}
	}

	function detectBackupType( fileName ) {
		if ( fileName.indexOf( 'wgv_database_' ) !== -1 ) { return 'Database'; }
		if ( fileName.indexOf( 'wgv_uploads_' ) !== -1 )  { return 'Uploads'; }
		if ( fileName.indexOf( 'wgv_full_' ) !== -1 )     { return 'Full Site'; }
		return 'Unknown';
	}

	function renderBackupList( files ) {
		if ( ! files || ! files.length ) {
			showBackupListState( '<p class="wgv-empty-state">No backups found in this folder.</p>' );
			return;
		}

		var html = '<table class="widefat wgv-backup-list-table">' +
			'<thead><tr>' +
			'<th>Date</th><th>Type</th><th>File Name</th><th>Size</th><th>Actions</th>' +
			'</tr></thead><tbody>';

		files.forEach( function ( file ) {
			var name = escapeHTML( file.name || '' );
			var size = file.size ? formatBytes( parseInt( file.size, 10 ) ) : '&mdash;';
			var date = file.createdTime
				? new Date( file.createdTime ).toLocaleString()
				: '&mdash;';
			var type = detectBackupType( file.name || '' );
			var fileId = escapeHTML( file.id || '' );
			var typeLower = type === 'Database' ? 'database'
				: type === 'Uploads' ? 'uploads'
				: type === 'Full Site' ? 'full'
				: 'database';

			html += '<tr>' +
				'<td class="wgv-col-date">' + date + '</td>' +
				'<td class="wgv-col-type">' + type + '</td>' +
				'<td class="wgv-col-filename">' + name + '</td>' +
				'<td class="wgv-col-filesize">' + size + '</td>' +
				'<td><button type="button" class="button wgv-button-restore" ' +
					'data-file-id="' + fileId + '" ' +
					'data-file-name="' + name + '" ' +
					'data-type="' + typeLower + '">Restore</button></td>' +
				'</tr>';
		} );

		html += '</tbody></table>';

		showBackupListState( html );

		// Bind restore buttons.
		var wrap = document.getElementById( 'wgv-backup-list-wrap' );
		if ( ! wrap ) { return; }

		var buttons = wrap.querySelectorAll( '.wgv-button-restore' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var fileId   = btn.dataset.fileId;
				var fileName = btn.dataset.fileName;
				var type     = btn.dataset.type;

				openConfirmModal( {
					type:     type,
					label:    btn.closest( 'tr' ).querySelector( '.wgv-col-type' ).textContent,
					onConfirm: function () {
						runDriveRestore( fileId, type, btn );
					},
				} );
			} );
		} );
	}

	function runDriveRestore( fileId, type, triggerBtn ) {
		var statusEl = document.getElementById( 'wgv-upload-restore-status' );
		if ( statusEl ) {
			statusEl.className = 'wgv-backup-status wgv-backup-status--running';
			statusEl.innerHTML = '<span class="wgv-spinner"></span> Running pre-backup then restoring\u2026';
		}

		if ( triggerBtn ) { triggerBtn.disabled = true; }

		var body = new URLSearchParams();
		body.append( 'action',  'wgv_restore_backup' );
		body.append( 'nonce',   wgvAjax.nonce );
		body.append( 'source',  'drive' );
		body.append( 'type',    type );
		body.append( 'file_id', fileId );

		fetch( wgvAjax.ajaxurl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        body,
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( response ) {
				if ( triggerBtn ) { triggerBtn.disabled = false; }
				if ( response.success ) {
					if ( statusEl ) {
						statusEl.className = 'wgv-backup-status wgv-backup-status--success';
						statusEl.innerHTML = '&#10003; ' + ( response.data.message || 'Restore completed.' );
					}
					setTimeout( function () { window.location.reload(); }, 2000 );
				} else {
					var msg = ( response.data && response.data.message )
						? response.data.message : 'Restore failed.';
					if ( statusEl ) {
						statusEl.className = 'wgv-backup-status wgv-backup-status--error';
						statusEl.innerHTML = '&#10007; ' + escapeHTML( msg );
					}
				}
			} )
			.catch( function () {
				if ( triggerBtn ) { triggerBtn.disabled = false; }
				if ( statusEl ) {
					statusEl.className = 'wgv-backup-status wgv-backup-status--error';
					statusEl.innerHTML = '&#10007; Request failed. Please try again.';
				}
			} );
	}

	// -------------------------------------------------------------------------
	// Upload & Restore
	// -------------------------------------------------------------------------

	function initUploadRestore() {
		var btn = document.getElementById( 'wgv-upload-restore' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var fileInput = document.getElementById( 'wgv-restore-file' );
			var typeInput = document.querySelector( 'input[name="wgv_upload_type"]:checked' );

			if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
				alert( 'Please select a backup file to upload.' );
				return;
			}

			var type = typeInput ? typeInput.value : 'database';

			openConfirmModal( {
				type:     type,
				label:    type === 'database' ? 'database'
					: type === 'uploads' ? 'uploads'
					: 'full site',
				onConfirm: function () {
					runUploadRestore( fileInput.files[ 0 ], type );
				},
			} );
		} );
	}

	function runUploadRestore( file, type ) {
		var statusEl = document.getElementById( 'wgv-upload-restore-status' );
		if ( statusEl ) {
			statusEl.className = 'wgv-backup-status wgv-backup-status--running';
			statusEl.innerHTML = '<span class="wgv-spinner"></span> Uploading and restoring\u2026';
		}

		var btn = document.getElementById( 'wgv-upload-restore' );
		if ( btn ) { btn.disabled = true; }

		var formData = new FormData();
		formData.append( 'action',      'wgv_restore_upload' );
		formData.append( 'nonce',       wgvAjax.nonce );
		formData.append( 'type',        type );
		formData.append( 'backup_file', file );

		fetch( wgvAjax.ajaxurl, {
			method:      'POST',
			credentials: 'same-origin',
			body:        formData,
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( response ) {
				if ( btn ) { btn.disabled = false; }
				if ( response.success ) {
					if ( statusEl ) {
						statusEl.className = 'wgv-backup-status wgv-backup-status--success';
						statusEl.innerHTML = '&#10003; ' + ( response.data.message || 'Restore completed.' );
					}
					setTimeout( function () { window.location.reload(); }, 2000 );
				} else {
					var msg = ( response.data && response.data.message )
						? response.data.message : 'Upload restore failed.';
					if ( statusEl ) {
						statusEl.className = 'wgv-backup-status wgv-backup-status--error';
						statusEl.innerHTML = '&#10007; ' + escapeHTML( msg );
					}
				}
			} )
			.catch( function () {
				if ( btn ) { btn.disabled = false; }
				if ( statusEl ) {
					statusEl.className = 'wgv-backup-status wgv-backup-status--error';
					statusEl.innerHTML = '&#10007; Request failed. Please try again.';
				}
			} );
	}

	// -------------------------------------------------------------------------
	// Confirmation modal
	// -------------------------------------------------------------------------

	var confirmOverlay    = null;
	var pendingRestoreAction = null;

	/**
	 * Open the destructive-action confirmation modal.
	 *
	 * @param {object} config
	 * @param {string}   config.type      'database' | 'uploads' | 'full'
	 * @param {string}   config.label     Human-readable type label for the body text.
	 * @param {function} config.onConfirm Callback executed after the user confirms.
	 */
	function openConfirmModal( config ) {
		if ( confirmOverlay ) {
			return;
		}

		pendingRestoreAction = config;
		confirmOverlay = buildConfirmModalDOM( config );
		document.body.appendChild( confirmOverlay );
		var input = confirmOverlay.querySelector( '.wgv-confirm-input' );
		if ( input ) { input.focus(); }
	}

	function closeConfirmModal() {
		if ( confirmOverlay ) {
			document.body.removeChild( confirmOverlay );
			confirmOverlay       = null;
			pendingRestoreAction = null;
		}
	}

	function buildConfirmModalDOM( config ) {
		var label = config.label || config.type || 'data';

		var overlay = document.createElement( 'div' );
		overlay.className = 'wgv-confirm-modal';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', 'Confirm Restore' );

		overlay.innerHTML =
			'<div class="wgv-modal">' +
				'<div class="wgv-warning-header">' +
					'\u26a0\ufe0f You are about to restore your site' +
				'</div>' +
				'<div class="wgv-confirm-body">' +
					'<p>This will overwrite your current <strong>' + escapeHTML( label ) + '</strong>.</p>' +
					'<p>A backup will be created automatically before the restore begins.</p>' +
					'<p><strong>This action cannot be undone.</strong></p>' +
					'<input type="text" class="wgv-confirm-input" ' +
					        'id="wgv-confirm-input" ' +
					        'placeholder="Type RESTORE to confirm" ' +
					        'autocomplete="off" ' +
					        'spellcheck="false">' +
				'</div>' +
				'<div class="wgv-modal-footer">' +
					'<button type="button" class="button button-primary" id="wgv-confirm-btn" disabled>' +
						'Confirm Restore' +
					'</button>' +
					'<button type="button" class="button" id="wgv-confirm-cancel">Cancel</button>' +
				'</div>' +
			'</div>';

		var input      = overlay.querySelector( '#wgv-confirm-input' );
		var confirmBtn = overlay.querySelector( '#wgv-confirm-btn' );
		var cancelBtn  = overlay.querySelector( '#wgv-confirm-cancel' );

		input.addEventListener( 'input', function () {
			var matches = input.value === 'RESTORE';
			confirmBtn.disabled = ! matches;
			if ( matches ) {
				input.classList.add( 'wgv-confirm-input--valid' );
			} else {
				input.classList.remove( 'wgv-confirm-input--valid' );
			}
		} );

		confirmBtn.addEventListener( 'click', function () {
			if ( input.value !== 'RESTORE' ) { return; }
			// Capture callback before closeConfirmModal nulls pendingRestoreAction.
			var callback = pendingRestoreAction ? pendingRestoreAction.onConfirm : null;
			closeConfirmModal();
			if ( callback ) {
				callback();
			}
		} );

		cancelBtn.addEventListener( 'click', closeConfirmModal );

		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) { closeConfirmModal(); }
		} );

		overlay.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { closeConfirmModal(); }
		} );

		return overlay;
	}

	// -------------------------------------------------------------------------
	// Utility — byte formatter
	// -------------------------------------------------------------------------

	function formatBytes( bytes ) {
		if ( bytes >= 1073741824 ) { return ( bytes / 1073741824 ).toFixed( 2 ) + ' GB'; }
		if ( bytes >= 1048576 )    { return ( bytes / 1048576 ).toFixed( 2 ) + ' MB'; }
		if ( bytes >= 1024 )       { return ( bytes / 1024 ).toFixed( 2 ) + ' KB'; }
		return bytes + ' B';
	}

} )();
