# EngineScript Site Exporter

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/94ac1b08e70a48cc895d8522dffcf472)](https://app.codacy.com/gh/EngineScript/enginescript-site-exporter/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![License](https://img.shields.io/badge/License-GPL%20v3-green.svg?logo=gnu)](https://www.gnu.org/licenses/gpl-3.0.html)
[![WordPress Compatible](https://img.shields.io/badge/WordPress-6.5%2B-blue.svg?logo=wordpress)](https://wordpress.org/)
[![PHP Compatible](https://img.shields.io/badge/PHP-7.4%2B-purple.svg?logo=php)](https://www.php.net/)

## Current Version
[![Version](https://img.shields.io/badge/Version-2.0.0-orange.svg?logo=github)](https://github.com/EngineScript/enginescript-site-exporter/releases/latest/download/enginescript-site-exporter-2.0.0.zip)

## Description
A WordPress plugin that exports your entire site, including files and database, as a secure, downloadable ZIP archive.

EngineScript Site Exporter provides WordPress administrators with a straightforward, secure way to export their entire website. With a single click, you can create a complete backup of your site's files and database, perfect for site migrations, backups, or local development environments.

### Key Features

- **One-Click Export**: Create a complete site backup with just one click
- **Database Export**: Includes a full database dump in your export
- **Automatic Cleanup**: Exports are automatically deleted after 5 minutes to save disk space
- **Secure Downloads**: All exports use WordPress security tokens for protected access
- **WP-CLI Integration**: Leverages WP-CLI for efficient database exports when available
- **Export Management**: Download or manually delete export files as needed
- **EngineScript Integration**: Natively works with EngineScript's LEMP server environment and site import tools

## EngineScript Integration

This plugin is designed to work seamlessly with the [EngineScript LEMP server](https://github.com/EngineScript/EngineScript) environment:

- **Native Integration**: Automatically detected and configured when running on an EngineScript server
- **Compatible Exports**: All exports created with this plugin are directly compatible with EngineScript's site import tools
- **Streamlined Migrations**: Export from any WordPress site and import directly to an EngineScript-powered server
- **Optimized Performance**: When used on an EngineScript server, the plugin leverages server-optimized settings

The export format is specifically designed to work with EngineScript's site import functionality, allowing for seamless site migrations between WordPress installations.

## Installation

1. Download the plugin zip file
2. Log in to your WordPress admin panel
3. Go to Plugins → Add New
4. Click the "Upload Plugin" button at the top of the page
5. Choose the downloaded zip file and click "Install Now"
6. After installation, click "Activate Plugin"

## Usage

### Creating a Site Export

1. Navigate to Tools → Site Exporter in your WordPress admin
2. Click the "Export Site" button
3. Wait for the export process to complete
4. When finished, use the "Download Export File" button to save your backup

### Managing Export Files

- **Download**: Click the "Download Export File" button next to any export
- **Delete**: Click "Delete Export File" to remove an export you no longer need
- **Auto-Cleanup**: Exports are automatically deleted after 5 minutes

## Requirements

- WordPress 6.5 or higher
- PHP 7.4 or higher
- Write access to the WordPress uploads directory
- For database exports: MySQL access or WP-CLI installed

## Security Features

EngineScript Site Exporter is built with security as a priority:

- **Export Authentication**: Only authorized administrators can create and download exports
- **Secure Downloads**: All downloads are validated with WordPress nonces
- **Request Validation**: Referrer checking for all operations
- **Path Traversal Protection**: Comprehensive file path validation
- **Automatic Deletion**: Exports are automatically cleaned up after 1 hour
- **Security Headers**: Implements proper headers for download operations
- **Secure File Handling**: Uses WordPress Filesystem API for file operations

## Frequently Asked Questions

### How large of a site can I export?

The plugin is designed to work with most WordPress sites, but very large sites (multiple GB) may encounter timeout or memory limitations depending on your hosting environment.

### Where are the export files stored?

Exports are stored in your WordPress uploads directory, specifically at:
`[wp-root]/wp-content/uploads/enginescript-site-exporter-exports/`

### Why do export files disappear after 5 minutes?

For security and disk space considerations, all exports are automatically deleted after 5 minutes. This ensures sensitive site data isn't left stored indefinitely.

### Can I create multiple exports?

Yes, you can create as many exports as needed. Each will have a unique filename based on the timestamp of creation.

### Does this include my themes and plugins?

Yes, the export includes your entire WordPress installation: themes, plugins, uploads, and the complete database.

### Can I use this plugin with non-EngineScript servers?

Absolutely! While the plugin integrates seamlessly with EngineScript servers, it works perfectly on any WordPress installation regardless of the server environment.

## Changelog

See the [CHANGELOG.md](CHANGELOG.md) file for a complete list of changes.

## License

This plugin is licensed under the [GPL v3 or later](https://www.gnu.org/licenses/gpl-3.0.html).

## Credits

EngineScript Site Exporter is developed and maintained by [EngineScript](https://github.com/EngineScript/EngineScript).

## Support

For support, feature requests, or bug reports, please [create an issue](https://github.com/EngineScript/enginescript-site-exporter/issues) on our GitHub repository.
