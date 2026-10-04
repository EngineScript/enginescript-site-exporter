# EngineScript Site Exporter

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/94ac1b08e70a48cc895d8522dffcf472)](https://app.codacy.com/gh/EngineScript/enginescript-site-exporter/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![License](https://img.shields.io/badge/License-GPL%20v3-green.svg?logo=gnu)](https://www.gnu.org/licenses/gpl-3.0.html)
[![WordPress Compatible](https://img.shields.io/badge/WordPress-6.8%2B-blue.svg?logo=wordpress)](https://wordpress.org/)
[![PHP Compatible](https://img.shields.io/badge/PHP-8.2%2B-purple.svg?logo=php)](https://www.php.net/)

## Current Version

[![Version](https://img.shields.io/badge/Version-2.1.0-orange.svg?logo=github)](https://github.com/EngineScript/enginescript-site-exporter/releases/latest/download/enginescript-site-exporter-2.1.0.zip)

## Description

A WordPress plugin that exports your entire site, including files and the database, as a secure, downloadable EngineScript-compatible ZIP archive.

EngineScript Site Exporter lets authorized WordPress administrators export the database and eligible files under the WordPress installation directory for migration or backup. File exclusions and resource limits apply; verify the archive before relying on it as a backup.

### Key Features

- **One-Click Export**: Start a site export from the WordPress admin page
- **EngineScript Archive Format**: Creates the combined ZIP format accepted by EngineScript's current `vhost-import.sh`
- **Database Export**: Includes a gzip-compressed database dump in your export
- **Automatic Cleanup**: Schedules export deletion for 5 minutes after creation
- **Secure Downloads**: All exports use WordPress security tokens for protected access
- **WP-CLI Integration**: Requires WP-CLI for efficient database exports
- **Export Management**: Download or manually delete export files as needed
- **EngineScript Import Compatibility**: Produces the archive layout used by EngineScript's import tools

## EngineScript Integration

This plugin does not detect or require an EngineScript server. It can run on standard WordPress installations, while the generated archive format is designed for the [EngineScript LEMP server](https://github.com/EngineScript/EngineScript) import workflow:

- **Compatible Exports**: Exports use the ZIP container expected by EngineScript's site import tools
- **Streamlined Migrations**: Export from a WordPress site that meets the requirements below for import into an EngineScript-powered server
- **Format Optimization**: The bundle layout matches EngineScript's `manifest.txt` plus compressed database/files archive format

The export format matches EngineScript's canonical combined site archive:

```text
manifest.txt
database/<site>_db_<timestamp>.sql.gz
files/<site>_files_<timestamp>.tar.gz
```

The downloaded ZIP is named `<site>_enginescript_site_export_<timestamp>.zip`.

## Installation

1. Download the plugin ZIP file
2. Log in to your WordPress admin panel
3. Go to Plugins → Add New
4. Click the "Upload Plugin" button at the top of the page
5. Choose the downloaded ZIP file and click "Install Now"
6. After installation, click "Activate Plugin"

## Usage

### Creating a Site Export

1. On a single-site installation, navigate to Tools → Site Exporter. On multisite, use Settings → Site Exporter in Network Admin
2. Click the "Export Site" button
3. Wait for the export process to complete
4. When finished, use the "Download Export File" button in the list of available exports to save your backup

### Managing Export Files

- **Download**: Click the "Download Export File" button next to any export
- **Delete**: Click "Delete Export File" to remove an export you no longer need
- **Auto-Cleanup**: Deletion is scheduled for 5 minutes after creation and runs when WordPress cron processes the event
- **Recent Activity**: The page lists recent exports, downloads, deletions, errors, and security events
- **Multisite**: Activate the plugin for the whole network; it cannot be activated on a single site of a network
- **Deactivation and Deletion**: Deactivating the plugin deletes every export archive and scheduled event. Deleting it also removes its stored records and its export directory

## Requirements

- WordPress 6.8 or higher
- 64-bit PHP 8.2 or higher with ZipArchive and gzip (zlib) support
- Direct local filesystem access through the WordPress Filesystem API
- Write access to a private WordPress temporary directory. If your host's temp directory is inside a web-served directory (the WordPress directory, the content or uploads directory, or the document root), define `WP_TEMP_DIR` as a private writable path outside the web root. The plugin does not fall back to the uploads directory.
- WP-CLI installed at `/usr/local/bin/wp` or `/usr/bin/wp` for database exports. To use another trusted executable, define `SSE_WP_CLI_PATH` or filter `sse_wp_cli_path`; local paths must pass ownership and permission checks.
- A POSIX host with PHP's POSIX functions and a trusted `/usr/bin/setsid` or `/bin/setsid` executable. Database exports run in an owned process group so timeouts stop WP-CLI and its database-client descendants; unsupported hosts fail closed before starting the process.

## Security Features

EngineScript Site Exporter is built with security as a priority:

- **Export Authentication**: Only authorized administrators can create and download exports; multisite exports require a network-capable administrator
- **Secure Downloads**: All downloads are validated with WordPress nonces
- **Request Validation**: WordPress nonce validation for all admin actions
- **Path Traversal Protection**: Comprehensive file path validation
- **Private Export Storage**: Exports are staged in random private directories with `0700` directories and `0600` files, below a base directory whose name another local user cannot predict
- **Automatic Deletion**: Schedules cleanup after 5 minutes; manual deletion is available if cron is delayed, and deactivating the plugin deletes every export
- **Security Headers**: Implements proper headers for download operations
- **Secure File Handling**: Uses WordPress Filesystem API for file operations

One known limit: while the database is dumped, other local users of the server can see the path of the dump, which includes the private directory name, in the process list. They still cannot read the directory or its files.

## Frequently Asked Questions

### Who can create an export, and what does it contain?

On a single site, any user with the `manage_options` capability, which by default means every administrator. On a multisite network, only a super admin or a user with `manage_network_options`. There is no separate export capability and no second confirmation.

An export contains the whole site: every eligible file under the WordPress directory, including `wp-config.php` when it is stored there, and the complete database, including user password hashes and every secret stored in the options. Treat an export like the server itself, and give that capability only to people you would trust with it.

### How large of a site can I export?

Default limits are 250,000 source entries, 50 GiB of source data, 100 GiB of generated files, 30 minutes of processing time, and a 1 GiB free-disk reserve. Server administrators can tune the export filters; hosting limits may stop work earlier. The per-file size selector does not disable these overall limits.

### Where are the export files stored?

Exports are stored in WordPress' temporary directory, in a directory whose name is specific to the installation:
`<temp-dir>/enginescript-site-exporter-exports-<16 characters>/`

Each export is written inside a random private child directory with private filesystem permissions. The plugin refuses to export if the temporary directory resolves inside a web-served directory: the WordPress directory, the content or uploads directory, or the document root. In that case, define `WP_TEMP_DIR` in `wp-config.php` as a private writable path outside the web root. The plugin does not fall back to the uploads directory.

### When are export files deleted?

The plugin schedules deletion for 5 minutes after creation to reduce the time sensitive data remains on the server. Actual deletion depends on WordPress cron running successfully, so it may occur later. Download the archive promptly and delete it manually when finished. If `DISABLE_WP_CRON` is enabled, configure an external cron runner. Deactivating the plugin deletes every export at once, because an inactive plugin can no longer delete them.

### Can I create multiple exports?

Yes, sequentially. Only one export can run at a time within a single-site installation or the current multisite network. Each export uses a separate random private directory. The one-at-a-time rule relies on a database lock and on reading the current state of the database, so it is not supported on installations that send reads to database replicas.

### Does this include my themes and plugins?

The export includes eligible themes, plugins, uploads, and other files under the WordPress installation directory, plus the database dump. It skips symbolic links, unreadable entries, special files such as sockets and named pipes, version-control directories (`.git`, `.svn`, `.hg`), selected cache and temporary paths, certain hidden files, and files excluded by the chosen per-file size limit. Files outside that directory, such as a parent-directory `wp-config.php`, are not included. On multisite, the export covers the network database and shared installation, not just one blog.

### What information is logged?

The plugin stores up to 20 records per site in the database: each export, download, and deletion, and any error or security event. A record holds the time, the type, the message, and the user ID. No IP address is stored. Stored messages redact local absolute paths and are limited to 1,000 bytes. These records are kept whether or not debug logging is on, and the exporter page shows them. Their text is in English. When both `WP_DEBUG` and `WP_DEBUG_LOG` are enabled, the plugin also writes diagnostic messages to the WordPress debug log.

Database records older than seven days are removed when housekeeping, recovery, or a later log write runs; cron delays can extend that period. The WordPress debug log is separate: messages there can include local paths, though never the random name of a private export directory, and its retention and access controls are managed by the host. Review and redact logs before sharing them.

### What does the EngineScript importer need from an export?

EngineScript's `vhost-import.sh` finds the site through a `wp-config.php` inside the files archive and reads the site address from `WP_HOME` or `WP_SITEURL` in that file. An export from a site that keeps `wp-config.php` above the WordPress directory, or that defines neither constant, is still a complete backup, but the importer stops on it. The exporter page warns when either is the case. For reference, `manifest.txt` also records `home_url`, `site_url`, `table_prefix`, and `wp_config_in_archive`.

### Can I use this plugin with non-EngineScript servers?

Absolutely. The plugin does not require an EngineScript server; it creates an archive format designed for EngineScript's importer.

## Changelog

See the [CHANGELOG.md](CHANGELOG.md) file for a complete list of changes.

## License

This plugin is licensed under the [GPL v3 or later](https://www.gnu.org/licenses/gpl-3.0.html).

## Credits

EngineScript Site Exporter is developed and maintained by [EngineScript](https://github.com/EngineScript/EngineScript).

## Support

For support, feature requests, or bug reports, please [create an issue](https://github.com/EngineScript/enginescript-site-exporter/issues) on our GitHub repository.
