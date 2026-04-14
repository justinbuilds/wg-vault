=== WG Vault ===
Contributors: webganics
Tags: backup, google drive, scheduled backup, database backup
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress backup plugin with Google Drive integration. Schedule automatic backups — developed by Webganics.

== Description ==

WG Vault is a lightweight, dependency-free WordPress backup plugin developed by Webganics. It allows you to:

* Schedule automatic backups of your WordPress database and files.
* Securely upload backups to your Google Drive account.
* Configure a retention policy to automatically remove old backups.
* Receive email notifications on backup failure.

No Composer, no bloat — just clean, efficient PHP 8.0+ code.

== Installation ==

1. Upload the `wg-vault` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WG Vault** in the WordPress admin to configure your settings.

== Frequently Asked Questions ==

= Does this plugin require any external services? =

Google Drive integration requires a Google API OAuth 2.0 Client ID and Secret, which you can obtain from the Google Cloud Console.

= Where are backups stored temporarily? =

Temporary backup archives are staged in `wp-content/wgv-temp/` before being uploaded to Google Drive. They are deleted immediately after a confirmed upload.

== Changelog ==

= 1.1.2 =
* Version alignment fix

= 1.1.1 =
* Fixed folder picker not saving selected folder correctly
* Fixed drive_folder_id and drive_root_folder_id persistence on save

= 1.1.0 =
* Added Restore tab with Google Drive browser and file upload restore
* Added Drive folder picker with tree navigation
* Added two-level folder structure: root folder / site domain / backups
* Added typed confirmation (RESTORE) before any restore operation
* Added automatic pre-restore backup safety measure
* Added restore log table
* Switched manual backup trigger to AJAX with progress UI
* Fixed OAuth redirect_uri flow
* Fixed Drive folder name encoding

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
