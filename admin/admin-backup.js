/* global wgvAjax */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initBackupButtons();
		initFolderPicker();
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
	var pickerStack   = [];
	var pickerOverlay = null;

	function initFolderPicker() {
		var openBtn = document.getElementById( 'wgv-open-folder-picker' );
		if ( ! openBtn ) {
			return;
		}
		openBtn.addEventListener( 'click', function () {
			pickerStack = [];
			openModal();
		} );
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

		// Update hidden inputs.
		var inputName = document.getElementById( 'wgv-input-folder-name' );
		var inputId   = document.getElementById( 'wgv-input-root-folder-id' );
		if ( inputName ) {
			inputName.value = selected.name;
		}
		if ( inputId ) {
			inputId.value = selected.id;
		}

		// Update the breadcrumb display in the settings form.
		var display = document.getElementById( 'wgv-folder-picker-display' );
		if ( display ) {
			display.innerHTML =
				escapeHTML( selected.name ) +
				' <span class="wgv-folder-picker__sep" aria-hidden="true">/</span> ' +
				escapeHTML( wgvAjax.siteDomain );
		}

		// Show save reminder.
		var reminder = document.getElementById( 'wgv-folder-save-reminder' );
		if ( reminder ) {
			reminder.style.display = '';
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

} )();
