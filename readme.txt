=== WG Vault ===
Contributors:      webganics
Tags:              backup, google drive, scheduled backup, database backup, file backup
Requires at least: 6.0
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0+
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress backup plugin with Google Drive integration. Schedule automatic backups and store them securely in Google Drive.

== Description ==

WG Vault is a lightweight, dependency-free WordPress backup plugin developed by Webganics. It allows you to:

* Schedule automatic backups of your WordPress database and files.
* Securely upload backups to your Google Drive account.
* Configure a retention policy to automatically remove old backups.
* Receive email notifications on backup success or failure.

No Composer, no bloat — just clean, efficient PHP 8.0+ code.

== Installation ==

1. Upload the `wg-vault` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WG Vault** in the WordPress admin to configure your settings.

== Frequently Asked Questions ==

= Does this plugin require any external services? =

Google Drive integration requires a Google API OAuth 2.0 Client ID and Secret, which you can obtain from the Google Cloud Console.

= Where are backups stored locally? =

Temporary backup archives are stored in the `logs/` directory inside the plugin folder before being uploaded to Google Drive.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
