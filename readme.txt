=== WP PANEL Backup ===
Contributors: naibabiji
Tags: backup, migration, database
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export your WordPress files and database as one downloadable TAR or as separate ZIP volumes with a manifest and file index.

== Description ==

WP PANEL Backup exports files and directories from the site root and all supported tables in the connected database. Download the entire backup as one TAR, or download its JSON manifest, file index, and ZIP volumes separately.

Run a normal backup while the site remains available, use temporary maintenance during the backup, or keep the source site in maintenance for a migration. Keep the backup page open to process batches. You can leave the page and return to continue. A normal backup can capture files and database contents from different times while the site remains writable.

The progress view reminds you that large sites can take longer to back up. It shows estimated database table storage, the uncompressed size of the files included after scanning, and how many bytes of SQL and site files have been processed.

= Hosting requirements =

* 64-bit PHP with the ZIP extension and a native mysqli connection.
* Use a WordPress release that supports the PHP version installed on the source server.
* A single-site WordPress installation at the web root with matching site and WordPress URLs, a standard domain name, and standard HTTP or HTTPS ports.
* InnoDB tables and a database account that can read the tables and inspect database objects. Views, triggers, scheduled events, stored procedures, and stored functions are unsupported.
* WordPress Multisite, subdirectory installations, internationalized domain names, custom content directories, and uploads outside the site root are unsupported. Symbolic links, hard links, and special files in the backup scope stop the backup check. Some settings that depend on the source host, such as a custom database drop-in, also stop the check.

== Installation ==

1. Upload and activate the plugin in the WordPress administration area.
2. Open Tools > WP PANEL Backup, or use the backup link on the Plugins screen.
3. Choose a backup mode and a ZIP volume size, then start the backup.
4. Open the backup list to download a completed backup.

The current administration interface is in Simplified Chinese.

== Frequently Asked Questions ==

= Can I back up a site that uses an older PHP version? =

Yes, provided the source WordPress and PHP versions meet the requirements above and the source passes preflight. When the source PHP series differs from WP PANEL's PHP 8.3, the backup page displays an advisory and lets the export continue. After a restore, differences in PHP can cause WordPress, themes, plugins, or custom code to fail. Check the PHP error log on the destination to identify what needs repair.

= Which files are excluded? =

Hidden files and directories, the plugin's own workspace, and the object-cache.php and advanced-cache.php files in the standard wp-content directory are excluded. Identified third-party cache and backup directories are excluded by default. You can adjust those two exclusions independently. Excluded source files remain in place. Configure caching again on the destination site as needed.

= Where are backups kept and when are they removed? =

Backups are stored in an automatically created workspace with a random suffix under wp-content. Up to two completed backups are kept for a maximum of seven days each. Incomplete backups expire seven days after the last successful batch. Later backup management actions clean up expired material, and the administrator can also remove it manually.

Backups contain confidential data, including the database and a copy of the site's configuration. The plugin creates directory index files to prevent listing on servers that use them, plus deny rules for Apache and IIS where their per-directory configuration is enabled. On Nginx and other servers that do not apply these rules, configure a server rule to deny access to the workspace and its contents before creating backups. The backup list provides an Nginx example for the standard /wp-content/wp-panel-backup-storage- prefix. Authenticated downloads from the backup list do not use public file URLs. The plugin does not make a loopback request to verify server rules.

= How can I end maintenance mode? =

Temporary maintenance ends automatically when the backup succeeds. To end it earlier, use the temporary maintenance release action on the backup creation page. Migration mode keeps the source site in maintenance until an administrator cancels migration and reopens the site.

= Can WP PANEL import the backup? =

The export contains a JSON manifest, a line-delimited JSON file index, and self-contained ZIP volumes. The TAR option bundles those same files. The plugin creates source exports. The WP PANEL one-click importer is not yet available. The original wp-config.php is included for migration reference.

== Changelog ==

= 1.0.0 =
* Adds site and database export, three backup modes, and whole-backup or separate file downloads.
