=== EngineScript Site Exporter ===
Contributors: enginescript
Tags: backup, export, migration, site export, database export
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Export your entire WordPress site as a secure downloadable ZIP archive.

== Description ==

EngineScript Site Exporter provides WordPress administrators with a straightforward, secure way to export their entire website. With a single click, you can create a complete backup of your site's files and database, perfect for site migrations, backups, or local development environments.

Key features:
* One-Click Export: Create a complete site backup with just one click
* Database Export: Includes a full database dump in your export
* Automatic Cleanup: Exports are automatically deleted after 5 minutes to enhance security
* Secure Downloads: All exports use WordPress security tokens for protected access
* WP-CLI Integration: Leverages WP-CLI for efficient database exports when available
* Export Management: Download or manually delete export files as needed
* EngineScript Integration: Natively works with EngineScript's LEMP server environment and site import tools

This plugin is designed to work seamlessly with the EngineScript LEMP server environment:

* Native Integration: Automatically detected and configured when running on an EngineScript server
* Compatible Exports: All exports created with this plugin are directly compatible with EngineScript's site import tools
* Streamlined Migrations: Export from any WordPress site and import directly to an EngineScript-powered server
* Optimized Performance: When used on an EngineScript server, the plugin leverages server-optimized settings

The export format is specifically designed to work with EngineScript's site import functionality, allowing for seamless site migrations between WordPress installations.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/enginescript-site-exporter` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to Tools → Site Exporter in your WordPress admin.
4. Click the "Export Site" button to create a full site backup.

== Frequently Asked Questions ==

= How large of a site can I export? =

The plugin is designed to work with most WordPress sites, but very large sites (multiple GB) may encounter timeout or memory limitations depending on your hosting environment.

= Where are the export files stored? =

Exports are stored in your WordPress uploads directory, specifically at:
`[wp-root]/wp-content/uploads/enginescript-site-exporter-exports/`

= Why do export files disappear after 5 minutes? =

For security and disk space considerations, all exports are automatically deleted after 5 minutes. This ensures sensitive site data isn't left stored indefinitely.

= Can I create multiple exports? =

Yes, you can create as many exports as needed. Each will have a unique filename based on the timestamp of creation.

= Does this include my themes and plugins? =

Yes, the export includes your entire WordPress installation: themes, plugins, uploads, and the complete database.

= Can I use this plugin with non-EngineScript servers? =

Absolutely! While the plugin integrates seamlessly with EngineScript servers, it works perfectly on any WordPress installation regardless of the server environment.

= Will this work on shared hosting environments? =

Yes, the plugin is designed to be compatible with most shared hosting environments. However, large sites may encounter timeout or memory limitations on restrictive hosting plans.

== License ==

This plugin is licensed under the GPL v3 or later.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.

== Changelog ==

= 2.0.0 =
* **Critical Fix**: Fixed bug where automatic export file cleanup via WordPress cron was completely broken due to referer validation blocking cron-triggered deletions
* **Critical Fix**: Fixed deletion success/failure notices being lost after redirect
* **Security**: Fixed 9 instances of double-escaped WP_Error messages that could display garbled text to users
* **Security**: Removed redundant double escaping in admin menu titles and submit button
* **Security**: Removed overly strict realpath equality check that blocked downloads on servers with symlinked uploads
* **Performance**: Cached file size filter result to avoid redundant lookups per file during export
* **Performance**: Prevented debug error logs from autoloading on every WordPress page request
* **Code Quality**: Removed unused `sse_get_scheduled_deletions()` dead code
* **Code Quality**: Added `shell_exec` availability check in WP-CLI PATH lookup
* **i18n**: Cleaned up stale .pot entries and added missing translatable strings

= 1.9.1 =
* **Scheduled Deletion System Enhancements**: Implemented comprehensive dual cleanup system with both individual file cleanup (5 minutes) and bulk directory cleanup (10 minutes) as safety net
* **Enhanced Debugging**: Added comprehensive debugging system with error_log() output for WordPress cron troubleshooting when standard debug logging is disabled
* **Bulk Cleanup Handler**: Added sse_bulk_cleanup_exports_handler() to scan and clean all export files older than 5 minutes from the entire export directory
* **Improved Scheduling**: Enhanced sse_schedule_export_cleanup() with detailed logging, DISABLE_WP_CRON detection, and WordPress cron array status monitoring
* **Test Framework**: Added sse_test_cron_scheduling() function to verify WordPress cron functionality before attempting real scheduling
* **Cron Diagnostics**: Implemented sse_get_scheduled_deletions() for debugging scheduled events and cron system status
* **Verification System**: Added post-scheduling verification to confirm events are properly added to WordPress cron schedule
* **WordPress VIP Compliance**: Replaced direct PHP filesystem function is_writable() with WordPress Filesystem API (WP_Filesystem) for VIP coding standards compliance
* **Filesystem API Integration**: Added proper WordPress filesystem initialization with error handling in export preparation function
* **WordPress Coding Standards**: Fixed all inline comments punctuation, corrected Yoda conditions, aligned array formatting, standardized variable assignments, and removed debug code
* **Bug Fixes**: Resolved issue where export files were not being automatically deleted due to WordPress cron scheduling failures
* **Export Directory Consistency**: Centralized export directory naming with a shared constant so every cleanup routine targets the correct path
* **Filesystem Validation**: Added explicit directory creation and writability checks that surface actionable errors when the exports folder cannot be prepared
* **Code Quality**: Enhanced overall code readability and maintainability through standardized formatting and compliance improvements, including variable alignment fixes
* **CI Database Service**: Updated WordPress compatibility workflow database container from MariaDB 10.6 to MySQL 8.4 for production-accurate testing environment

= 1.8.5 =
* **Performance**: Added an export lock using transients to prevent concurrent export processes.
* **User Experience**: Added user-friendly file size limit selection in export form (100MB, 500MB, 1GB, or no limit).
* **Code Quality**: Centralized file extension validation and eliminated code duplication with `SSE_ALLOWED_EXTENSIONS` constant.

= 1.8.4 =
* **WordPress Coding Standards**: Comprehensive PHPCS compliance fixes across all functions
* **Code Quality**: Fixed function documentation block spacing and alignment
* **Parameter Formatting**: Standardized parameter formatting with proper spacing (e.g., `function( $param )`)
* **Yoda Conditions**: Corrected Yoda conditions for all boolean comparisons (e.g., `false === $variable`)
* **Array Formatting**: Aligned array formatting with consistent spacing (e.g., `'key' => 'value'`)
* **Multi-line Functions**: Fixed multi-line function call formatting and indentation
* **Code Consistency**: Enhanced code readability and maintainability through standardized formatting
* **Documentation Workflow**: Removed changelog.txt file to streamline documentation process
* **Version Control**: Maintaining only readme.txt (WordPress.org) and CHANGELOG.md (developers) for changelog management
* **Code Standards**: Fixed tab indentation violations to use spaces as required by WordPress coding standards
* **Security Hardening**: Added WP-CLI executable verification, sanitized WP-CLI error output (path masking), conditional --allow-root usage, stricter download data validation, and graceful scheduled deletion handling

= 1.8.3 =
* **WordPress Plugin Directory Compliance**: Updated text domain from 'EngineScript-Site-Exporter' to 'enginescript-site-exporter' (lowercase) to comply with WordPress.org plugin directory requirements
* **Load Textdomain Removal**: Removed discouraged `load_plugin_textdomain()` function call as WordPress automatically handles translations for plugins hosted on WordPress.org since version 4.6
* **Plugin Header Update**: Fixed "Text Domain" header to use only lowercase letters, numbers, and hyphens as required by WordPress standards
* **Critical Security Fix**: Resolved a fatal error caused by a missing `sse_get_safe_wp_cli_path()` function. This function is essential for securely locating the WP-CLI executable, and its absence prevented the database export process from running. The new function ensures that the plugin can reliably find WP-CLI in common locations, allowing the export to proceed as intended.

= 1.8.2 =
* **Critical Security Fix**: Resolved a fatal error caused by a missing `sse_get_safe_wp_cli_path()` function. This function is essential for securely locating the WP-CLI executable, and its absence prevented the database export process from running. The new function ensures that the plugin can reliably find WP-CLI in common locations, allowing the export to proceed as intended.

= 1.7.0 =
* **SECURITY FIX**: Resolved Server-Side Request Forgery (SSRF) vulnerability in path validation
* **Filesystem Security**: Removed filesystem probing functions (is_dir, is_readable) from user input validation
* **Attack Prevention**: Eliminated potential filesystem structure information disclosure
* **Path Validation**: Maintained robust security through safe string-based path validation
* **Codacy Compliance**: Addressed security detection for file operations on user input

= 1.6.9 =
* **Security Enhancement**: Enhanced SSRF (Server-Side Request Forgery) protection in file path validation
* **Path Validation**: Improved security by validating logical path structure before filesystem operations
* **Attack Surface Reduction**: Minimized potential attack vectors by pre-validating user input before realpath() calls
* **Security Logging**: Enhanced security event logging for better monitoring of potential attacks

= 1.6.8 =
* **Fallback Removal**: Simplified codebase by removing all fallback mechanisms for better security and performance
* **Enhanced SSRF Protection**: Strengthened Server-Side Request Forgery prevention with pre-validation of all file paths
* **Security Hardening**: Comprehensive security audit ensuring OWASP and WordPress best practices compliance
* **Code Simplification**: Reduced overall complexity by 15% through fallback removal and streamlined execution paths
* **Text Domain Fixes**: Corrected remaining lowercase text domain instances for full WordPress standards compliance
* **Performance Improvement**: Single-path execution without fallback overhead for faster operations

= 1.6.7 =
* PHPMD compliance improvements with enhanced code quality
* Fixed all CamelCase variable naming violations for better code standards
* Broke down complex functions to reduce cyclomatic complexity below threshold
* Split large functions into smaller, focused functions for better maintainability
* Eliminated unnecessary else expressions throughout codebase
* Reduced NPath complexity and improved performance
* Enhanced code structure with clear separation of concerns

= 1.6.6 =
* CRITICAL: Added missing secure download and delete handlers for export files
* Fixed all text domain inconsistencies to use 'enginescript-site-exporter'
* Enhanced shell security with improved WP-CLI path validation and security checks
* Improved path traversal protection with better edge case handling
* Enhanced global variable handling for WordPress filesystem API
* Added download rate limiting (1 download per minute per user)
* Improved scheduled deletion security with proper file validation
* Sanitized error messages to prevent server information disclosure
* Removed duplicate function definitions and improved error handling
* Added comprehensive security features including user capability verification

= 1.6.5 =
* Code quality improvements and PHPMD compliance
* Refactored entire codebase to address PHP Mess Detector warnings
* Broke down large functions into smaller, single-responsibility functions
* Converted variable names to camelCase format for better code standards
* Removed unnecessary error control operators and improved error handling
* Eliminated unnecessary else expressions and duplicate code
* Fixed naming conventions for WordPress global variables
* Split complex boolean-flag functions into separate, dedicated functions

= 1.6.4 =
* Fixed text domain mismatch to use 'enginescript-site-exporter' for WordPress plugin compliance
* Updated plugin header text domain to match expected slug format for WordPress.org directory standards

= 1.6.3 =
* Version consistency update across all plugin files and documentation

= 1.6.2 =
* Plugin renamed from "EngineScript: Simple Site Exporter" to "EngineScript Site Exporter"
* Updated text domain to 'enginescript-site-exporter' for consistency
* Updated composer package name to 'enginescript/enginescript-site-exporter'
* Updated export directory naming to 'enginescript-site-exporter-exports'
* Updated all GitHub workflows and documentation to reflect new plugin name
* Enhanced plugin branding and consistency

= 1.6.1 =
* WordPress Plugin Check compliance fixes
* Fixed timezone issues by replacing date() with gmdate() for UTC consistency
* Improved debug logging with WordPress wp_debug_log() support and proper fallback
* Fixed admin page title display issue with get_admin_page_title() usage
* Enhanced documentation with proper PHPDoc comments and phpcs annotations
* Addressed all WordPress Plugin Check warnings and errors

= 1.6.0 =
* Major security and code quality improvements
* Enhanced logging system with WP_DEBUG integration and database storage for critical errors
* Improved file operations using WordPress Filesystem API instead of direct file functions
* Added execution time safety with reasonable limits and proper logging
* Implemented comprehensive path validation to prevent directory traversal attacks
* Standardized text domain across all translatable strings
* Pinned GitHub Actions to specific commit hashes for improved security
* Updated all repository references and workflow configurations
* Created WordPress-compatible readme.txt file
* Updated composer.json with correct package information and GPL-3.0-or-later license
* Fixed code structure issues and improved WordPress coding standards compliance

= 1.5.9 =
* Reduced export file auto-deletion time from 1 hour to 5 minutes for improved security
* Removed dependency on external systems for file security management
* Simplified user interface by removing environment-specific messaging
* Enhanced self-containment of the plugin's security features

= 1.5.8 =
* Refactored validation functions to eliminate code duplication
* Created shared validation function for both download and deletion operations
* Improved code maintainability while preserving security controls
* Updated license to GPL v3
* Enhanced file path validation
* Strengthened regex pattern for export file validation
* Added proper documentation for security-related functions

= 1.5.7 =
* Implemented comprehensive file path validation function to prevent directory traversal attacks
* Added referrer checks for download and delete operations
* Enhanced file pattern validation with stronger regex patterns
* Improved path display in admin interface
* Added security headers to file download operations
* Implemented strict comparison operators throughout the plugin
* Consistently applied sanitization to nonce values before verification

= 1.5.6 =
* Added more detailed logging for export operations
* Improved error handling during file operations
* Fixed potential memory issues during export of large sites
* Resolved a race condition in the scheduled deletion process

= 1.5.5 =
* Added automatic deletion of export files after 1 hour
* Implemented secure download mechanism through WordPress admin
* Added ability to manually delete export files
* Enhanced file export process with better error handling
* Improved progress feedback during export operations

= 1.5.4 =
* Added deletion request validation and confirmation
* Implemented redirect after deletion with status notification
* Fixed database export issues on some hosting environments

= 1.5.3 =
* Added manual export file deletion
* Enhanced security for file operations
* Better error handling for WP-CLI operations
* Improved user interface with clearer notifications

= 1.5.2 =
* Added WP-CLI integration for database exports
* Implemented fallback methods for database exports
* Fixed ZIP creation issues on certain hosting environments

= 1.5.1 =
* Enhanced ZIP file creation process
* Improved handling of large files
* Added exclusion for cache and temporary directories

= 1.5.0 =
* Initial Release
* Basic site export functionality
* Database and file export
* Simple admin interface

== Upgrade Notice ==

= 1.6.8 =
Major security hardening and code simplification update: Removed all fallback mechanisms, enhanced SSRF protection, comprehensive security audit following OWASP and WordPress best practices. Highly recommended security update for all users.

= 1.6.7 =
Critical compliance and security update: PHPMD/PHPStan Level 8 compliance, WordPress Plugin Check fixes, comprehensive input sanitization and output escaping. Required update for WordPress.org compatibility.

= 1.6.1 =
WordPress Plugin Check compliance update: Fixed timezone issues, improved debug logging, and addressed all plugin check warnings. Recommended update for WordPress.org submission.

= 1.6.0 =
Major security and code quality update: Enhanced logging system, improved file operations with WordPress Filesystem API, execution time safety improvements, comprehensive path validation, standardized text domains, and GitHub Actions security updates. Recommended upgrade for all users.

= 1.5.9 =
This update improves security by reducing export file auto-deletion time from 1 hour to 5 minutes and enhances overall plugin security with simplified, self-contained security features.

= 1.5.8 =
This update includes improved code quality, better validation functions, and enhanced security with file path validation and stronger regex patterns. Includes update to GPL v3 license.

= 1.5.7 =
Important security update: Includes comprehensive file path validation, referrer checks for download operations, enhanced validation patterns, and improved security headers.
