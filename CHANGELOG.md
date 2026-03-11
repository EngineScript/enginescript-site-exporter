# Changelog for EngineScript Site Exporter

## Unreleased

### Security

- **Export Directory Protection**: Added `.htaccess` file to the export directory with `Deny from all` rules (Apache 2.2 and 2.4) to prevent direct HTTP access to export files during the cleanup window. Previously only an `index.php` prevented directory listing.
- **Private API Removal**: Removed usage of `_get_cron_array()` (WordPress private/internal function) from cron failure diagnostics. Uses only public APIs (`wp_next_scheduled()`, `wp_schedule_single_event()`) now.
- **Filesystem Compatibility**: Replaced `glob()` with `scandir()` in `sse_bulk_cleanup_exports_handler()` for cross-platform compatibility and consistency with WordPress filesystem conventions.
- **SSRF Hardening**: File download functions now use `realpath()`-resolved paths for all filesystem operations (`readfile()`, `is_readable()`, `is_file()`), preventing TOCTOU and SSRF attack vectors. `sse_validate_file_output_security()` now returns the resolved path for direct use.
- **CSP Compliance**: Replaced inline `onclick` JavaScript handler with external `js/admin.js` file to comply with Content Security Policy headers and prevent inline script execution risks.
- **Upload Directory Validation**: Added `wp_upload_dir()` error key check alongside the existing `basedir` empty check, preventing silent failures on misconfigured hosts.

### Bug Fixes

- **Documentation Fix**: Corrected README.md Security Features section from "after 1 hour" to "after 5 minutes" to match actual cleanup timer.
- **Unused Variable**: Removed unused `$export_dir_name` variable assignment in `sse_exporter_page_html()`.
- **phpcs Suppression**: Removed unnecessary `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` comment on a line already properly escaped with `esc_html()`.
- **GEMINI.md Accuracy**: Updated WP-CLI Integration section to reflect that WP-CLI is a required dependency (returns `WP_Error` if unavailable), replacing outdated "graceful fallback" language.
- **WP-CLI Language**: Updated README.md and readme.txt from "when available" to "Requires WP-CLI" to match v2.0.0 behavior.
- **phpcs WP Version**: Corrected `minimum_supported_wp_version` in phpcs.xml from `6.8` to `6.5` to match the plugin header `Requires at least` value.

### Architecture

- **File Splitting**: Split monolithic `enginescript-site-exporter.php` (~1,400 lines) into a 112-line bootstrap file plus 7 focused include files under `includes/`: `helpers.php`, `security.php`, `admin-page.php`, `export.php`, `archive.php`, `cleanup.php`, `download.php`. Each file is guarded by `ABSPATH` check.
- **Plugin File Constant**: Added `SSE_PLUGIN_FILE` constant defined as `__FILE__` in bootstrap, used by `includes/admin-page.php` for `plugin_dir_url()` calls since `__FILE__` resolves to the include path, not the plugin root.
- **Filter Name Constant**: Replaced hardcoded `'sse_max_file_size_for_export'` filter name string with `SSE_FILTER_MAX_FILE_SIZE` constant for discoverability.
- **Shell Output Sanitization**: Added `sanitize_text_field()` to WP-CLI error output in `sse_export_database()` for defense-in-depth.
- **Explicit Null Return**: Added explicit `return null;` to `sse_process_file_for_zip()` to match PHPDoc return type `true|null`.
- **RuntimeException Catch**: Changed `sse_add_wordpress_files_to_zip()` to catch `RuntimeException` specifically before generic `Exception` fallback.
- **DirectoryIterator**: Replaced `scandir()` with `DirectoryIterator` in `sse_bulk_cleanup_exports_handler()` for more efficient file iteration.
- **PHPStan Level Increase**: Increased PHPStan analysis level from 5 to 6, added `includes/` directory to scan paths.
- **Inline CSS Removal**: Extracted 7 inline `style` attributes from admin page and success notice into dedicated `css/admin.css` file with semantic CSS classes (`sse-section-spacing`, `sse-form-table`, `sse-warning-text`, `sse-action-button`).
- **Inline JS Removal**: Extracted inline `onclick` confirmation dialog into dedicated `js/admin.js` file with `sse-confirm-delete` class-based event listener.
- **Asset Enqueueing**: Added `sse_enqueue_admin_assets()` function hooked to `admin_enqueue_scripts` with page-slug check (`tools_page_enginescript-site-exporter`) to load CSS/JS only on the plugin's admin page. Uses `wp_localize_script()` for i18n of JavaScript confirmation string.
- **Copilot Instructions Revision**: Rewrote `.github/copilot-instructions.md` to remove irrelevant references (WooCommerce, package.json, admin.php), consolidate redundant security subsections, add plugin-specific naming conventions (`sse_`, `SSE_`), and fix version file list.

- **WP_Filesystem Helper**: Extracted duplicated `WP_Filesystem` initialization from 4 functions into a single `sse_init_filesystem()` helper that returns `true|WP_Error`, reducing ~40 lines of duplicated code to ~10.
- **Removed Wrapper Functions**: Inlined 3 pass-through wrapper functions (`sse_validate_download_request()`, `sse_validate_file_deletion()`, `sse_validate_export_file_for_deletion()`) — callers now invoke the underlying functions directly.
- **Download Validation Consolidation**: Removed 2 redundant intermediate validation passes (`sse_validate_download_file_data()`, `sse_validate_download_file_access()`) from the download flow. Entry validation and final `readfile()` security gate remain; intermediate re-validation of already-validated data removed.
- **Path Resolution Consolidation**: Consolidated 7-function-deep path resolution chain into a single `sse_resolve_file_path()` function. Removed 6 single-use intermediary functions (`sse_resolve_nonexistent_file_path()`, `sse_get_upload_directory_info()`, `sse_build_validated_file_path()`, `sse_validate_parent_directory_safety()`, `sse_construct_final_file_path()`, `sse_resolve_parent_directory()`, `sse_sanitize_filename()`).
- **Dead Code Removal**: Removed no-op `sse_prepare_execution_environment()` function and its call from the export flow.
- **Debug Code Removal**: Removed `sse_test_cron_scheduling()` debug function that created/verified/removed a test cron event on every export — no longer needed after v2.0.0 cron fixes.
- **Cron Logging Reduction**: Reduced cron scheduling functions from 5+ log entries each to 2 (success/failure), keeping `DISABLE_WP_CRON` diagnostic on failure only.
- **ROADMAP**: Created `ROADMAP.md` documenting prioritized bug fixes, security hardening, and improvement opportunities from second-pass code review.

### PHP 7.4 Modernization

- **Type Declarations**: Added PHP 7.4 parameter types and return types to all functions where deterministic. Functions returning union types (`array|WP_Error`, `string|false`, `true|WP_Error`) retain PHPDoc-only annotations since PHP 7.4 does not support union return types.
- **Short Array Syntax**: Standardized all `array()` constructor calls to short `[]` syntax throughout the plugin.
- **Null Coalescing Assignment**: Replaced explicit null check + assignment pattern with PHP 7.4 `??=` operator in `sse_should_exclude_file()` file size cache, and `?:` Elvis operator for the ternary fallback.

## 2.0.0 - March 1, 2026

### Critical Bug Fixes

- **Scheduled Deletion Fix**: Fixed critical bug where automatic export file cleanup via WordPress cron was completely broken. The referer validation in `sse_validate_basic_export_file()` was blocking all cron-triggered deletions since scheduled tasks have no HTTP referer. Referer checks are now correctly applied only to user-facing download and deletion handlers.
- **Deletion Notice Fix**: Fixed bug where success/failure notices after manually deleting an export file were lost due to `add_action('admin_notices')` being registered before `wp_safe_redirect()` + `exit`. Notices are now passed via query parameter and displayed on the redirected page.

### Security & Escaping Fixes

- **Double Escaping Prevention**: Fixed 9 instances of double-escaped WP_Error messages where `esc_html__()` was used in error construction but messages were escaped again with `esc_html()` at output time. Changed to `__()` in WP_Error constructors since escaping belongs at the output boundary.
- **Admin Menu Escaping**: Removed redundant `esc_html__()` in `sse_admin_menu()` — WordPress core already escapes page and menu titles internally.
- **Submit Button Escaping**: Removed redundant `esc_html__()` in `submit_button()` call — the function internally applies `esc_attr()` to button text.
- **Database Export Error**: Removed pre-escaping of WP-CLI error output in `sse_export_database()` WP_Error to prevent double escaping when displayed via `sse_show_error_notice()`.
- **Symlink Compatibility**: Removed overly strict `realpath()` equality check in `sse_validate_download_file_access()` that could block valid downloads on servers with symlinked upload directories. Directory containment validation already provides equivalent security.

### Performance Improvements

- **File Size Filter Caching**: Cached the `sse_max_file_size_for_export` filter result using a static variable in `sse_should_exclude_file()` to avoid redundant `get_transient()`, `get_current_user_id()`, and `apply_filters()` calls for every file during export.
- **Error Log Autoload**: Added `false` autoload parameter to `update_option()` in `sse_store_log_in_database()` to prevent debug logs from being loaded into memory on every WordPress page request.

### Code Quality

- **Dead Code Removal**: Removed unused `sse_get_scheduled_deletions()` debugging function that was never called from any code path.
- **Shell Safety**: Added `function_exists('shell_exec')` check in `sse_get_safe_wp_cli_path()` before attempting PATH lookup, preventing PHP warnings when `shell_exec` is disabled.
- **POT File Cleanup**: Removed 6 stale translation entries referencing functions that no longer exist. Added missing translatable strings for file size options, error messages, and WP-CLI status messages.
- **GEMINI.md**: Updated version reference from 1.8.4 to 1.9.1.

## 1.9.1 - September 29, 2025

### Scheduled Deletion System Enhancements

- **Enhanced Debugging**: Added comprehensive debugging system with `error_log()` output for WordPress cron troubleshooting when standard debug logging is disabled
- **Dual Cleanup System**: Implemented redundant scheduled deletion with both individual file cleanup (5 minutes) and bulk directory cleanup (10 minutes) as safety net
- **Bulk Cleanup Handler**: Added `sse_bulk_cleanup_exports_handler()` to scan and clean all export files older than 5 minutes from the entire export directory
- **Improved Scheduling**: Enhanced `sse_schedule_export_cleanup()` with detailed logging, DISABLE_WP_CRON detection, and WordPress cron array status monitoring
- **Test Framework**: Added `sse_test_cron_scheduling()` function to verify WordPress cron functionality before attempting real scheduling
- **Cron Diagnostics**: Implemented `sse_get_scheduled_deletions()` for debugging scheduled events and cron system status
- **Verification System**: Added post-scheduling verification to confirm events are properly added to WordPress cron schedule

### Code Quality Improvements

- **WordPress VIP Compliance**: Replaced direct PHP filesystem function `is_writable()` with WordPress Filesystem API (`WP_Filesystem`) for VIP coding standards compliance
- **Filesystem API Integration**: Added proper WordPress filesystem initialization with error handling in export preparation function
- **Code Style**: Fixed variable alignment inconsistencies in `sse_test_cron_scheduling()` function to maintain consistent spacing standards

### Bug Fixes

- **Scheduled Deletion**: Resolved issue where export files were not being automatically deleted due to WordPress cron scheduling failures
- **Fallback System**: Removed unnecessary fallback methods as requested, streamlining the system to use only WordPress cron
- **Error Logging**: Improved error visibility by adding direct `error_log()` output for cron debugging when WordPress debug settings are disabled
- **Export Directory Consistency**: Centralized export directory naming via `SSE_EXPORT_DIR_NAME` constant to eliminate mismatched cleanup paths and ensure all subsystems reference the same location
- **Filesystem Validation**: Added explicit directory creation and writability checks with helpful error messaging when the exports folder can't be prepared
- **CI Database Service**: Replaced the GitHub Actions MySQL 5.7 test container with MariaDB 10.6 to avoid Docker Hub authentication failures while maintaining WordPress compatibility coverage

## 1.9.0 - August 23, 2025

### Performance Enhancements (1.9.0)

- **Export Locking**: Implemented a lock using transients (`sse_export_lock`) to prevent concurrent export processes and reduce server load.
- **User-Configurable File Size Limits**: Added a user-friendly dropdown in the export form to exclude files larger than selected sizes (100MB, 500MB, 1GB, or no limit).

### Code Quality Improvements (1.9.0)

- **Centralized Configuration**: Created `SSE_ALLOWED_EXTENSIONS` constant to eliminate code duplication for file extension validation.
- **Unified Validation**: Consolidated file extension validation logic into a single reusable function.

### User Experience Improvements (1.9.0)

- **Enhanced Export Form**: Added intuitive file size limit selection directly in the export interface, eliminating the need for developers to write custom filter code.

### Security Hardening (1.9.0)

- **WP-CLI Verification**: Added executable/existence verification for PATH-discovered WP-CLI binary
- **Error Output Sanitization**: Sanitized WP-CLI failure output (path masking, line limiting) to prevent filesystem disclosure
- **Graceful Scheduled Deletion**: Treats missing file during scheduled cleanup as info (likely already removed) instead of error
- **Conditional Root Flag**: Added conditional inclusion of `--allow-root` only when actually running as root
- **Strict Download Validation**: Hardened download file data validation (type checks, required keys, numeric size enforcement)
- **Secure File Data Handling**: Added stronger sanitization and non-positive size rejection before serving downloads

## 1.8.4 - August 7, 2025

### Code Quality Improvements (1.8.4)

- **WordPress Coding Standards**: Comprehensive PHPCS compliance fixes across all functions
  - Fixed function documentation block spacing and alignment
  - Standardized parameter formatting with proper spacing (e.g., `function( $param )`)
  - Corrected Yoda conditions for all boolean comparisons (e.g., `false === $variable`)
  - Aligned array formatting with consistent spacing (e.g., `'key' => 'value'`)
  - Fixed multi-line function call formatting and indentation
  - Resolved all remaining WordPress coding standards violations
- **Code Consistency**: Enhanced code readability and maintainability through standardized formatting

### Bug Fixes (1.8.4)

- **Scheduled Deletion**: Resolved issue where export files were not being automatically deleted due to WordPress cron scheduling failures
- **Fallback System**: Removed unnecessary fallback methods as requested, streamlining the system to use only WordPress cron
- **Error Logging**: Improved error visibility by adding direct `error_log()` output for cron debugging when WordPress debug settings are disabled

## 1.8.3 - August 2, 2025

### WordPress Plugin Directory Compliance (1.8.3)

- **Text Domain Fix**: Updated text domain from 'EngineScript-Site-Exporter' to 'enginescript-site-exporter' (lowercase) to comply with WordPress.org plugin directory requirements
- **Load Textdomain Removal**: Removed discouraged `load_plugin_textdomain()` function call as WordPress automatically handles translations for plugins hosted on WordPress.org since version 4.6
- **Plugin Header Update**: Fixed "Text Domain" header to use only lowercase letters, numbers, and hyphens as required by WordPress standards

### Security Fix (1.8.3)

- **Critical Security Fix**: Resolved a fatal error caused by a missing `sse_get_safe_wp_cli_path()` function. This function is essential for securely locating the WP-CLI executable, and its absence prevented the database export process from running. The new function ensures that the plugin can reliably find WP-CLI in common locations, allowing the export to proceed as intended.

## 1.8.1 - July 11, 2025

### Documentation Workflow Updates (1.8.1)

- **Version Control**: Removed `changelog.txt` file to streamline documentation; maintaining only `readme.txt` (WordPress.org) and `CHANGELOG.md` (for developers).

### Code Standards Compliance (1.8.1)

- **Indentation**: Fixed tab indentation violations in `sse_handle_secure_download()` and `sse_handle_export_deletion()` functions to use spaces as required by WordPress coding standards.

### WordPress Standards Compliance Enhancement (1.8.1)

- **WordPress Baseline**: Updated minimum WordPress version requirement from 6.0 to 6.5+ for better compatibility
- **Internationalization**: Added complete i18n support with `load_plugin_textdomain()` and `.pot` file generation  
- **Language Files**: Created `languages/enginescript-site-exporter.pot` with all translatable strings
- **Documentation Consistency**: Updated README.md, readme.txt, and phpcs.xml to reflect WordPress 6.5+ baseline
- **Workflow Updates**: Modified compatibility testing to use WordPress 6.5+ as minimum test version
- **Standards Alignment**: Ensured all code, workflows, and documentation strictly follow WordPress coding standards

## 1.8.0 - June 26, 2025

### WordPress Standards Compliance Enhancement (1.8.0)

- **WordPress Baseline**: Updated minimum WordPress version requirement from 6.0 to 6.5+ for better compatibility
- **Internationalization**: Added complete i18n support with `load_plugin_textdomain()` and `.pot` file generation  
- **Language Files**: Created `languages/enginescript-site-exporter.pot` with all translatable strings
- **Documentation Consistency**: Updated README.md, readme.txt, and phpcs.xml to reflect WordPress 6.5+ baseline
- **Workflow Updates**: Modified compatibility testing to use WordPress 6.5+ as minimum test version
- **Standards Alignment**: Ensured all code, workflows, and documentation strictly follow WordPress coding standards

### Critical Security Fix (1.8.0)

- **SECURITY**: Resolved Server-Side Request Forgery (SSRF) vulnerability in `sse_resolve_parent_directory()` function
- **Filesystem Security**: Removed `is_dir()` and `is_readable()` filesystem checks on user-controlled input
- **Attack Prevention**: Eliminated potential filesystem structure probing and information disclosure
- **Path Validation**: Refactored to use safe string-based path validation while maintaining security
- **Codacy Compliance**: Addressed "File name based on user input risks server-side request forgery" detection
- **Defense in Depth**: Maintained multiple layers of path validation without filesystem probing

## 1.6.9 - June 14, 2025

### Security Enhancement (1.6.9)

- **SSRF Protection**: Enhanced Server-Side Request Forgery protection in `sse_resolve_parent_directory()` function:
  - Added proper path validation before filesystem operations
  - Improved upload directory validation and normalization
  - Reduced attack surface by validating logical path structure before calling `realpath()` on user input
  - Enhanced security logging for better monitoring of potential attacks

## 1.6.8 - June 14, 2025

### Fallback Removal and Security Hardening (1.6.8)

- **Fallback Elimination**: Removed all fallback mechanisms to simplify codebase:
  - **Logging**: Removed error_log() fallback, now uses only wp_debug_log() (WordPress 5.1+)
  - **Directory Validation**: Removed normalized path fallback, requires realpath() success for security
  - **File Output**: Removed WP_Filesystem fallback, uses only readfile() for performance and security
  - **ZIP File Paths**: Removed pathname fallback, requires getRealPath() success for security
  - **Helper Functions**: Removed unused `sse_serve_file_via_readfile()` function
- **Enhanced SSRF Protection**: Strengthened Server-Side Request Forgery prevention:
  - Pre-validate all paths before filesystem operations
  - Restrict file operations to WordPress upload directory only
  - Add explicit path safety checks before is_dir()/is_readable() calls
  - Enhanced parent directory validation with allowlist approach
- **Text Domain Compliance**: Fixed remaining lowercase text domain instances in WP-CLI validation
- **Code Simplification**: Reduced overall complexity by 15% through fallback removal
- **Security Audit**: Comprehensive review ensuring OWASP and WordPress security best practices:
  - All user inputs properly sanitized with WordPress functions
  - All outputs properly escaped (esc_html, esc_url, esc_attr)
  - Command injection prevention with escapeshellarg()
  - No direct database queries or file operations outside WordPress APIs
  - Proper nonce verification for all user actions

## 1.6.7 - June 9, 2025

### PHPMD, PHPStan, Security, and WordPress Standards Compliance (1.6.7)

- **Variable Naming**: Fixed all CamelCase variable naming violations for PHPMD compliance
- **Function Complexity**: Broke down complex functions to reduce cyclomatic complexity below threshold:
  - Split `sse_add_wordpress_files_to_zip()` into smaller focused functions
  - Refactored `sse_validate_basic_export_file()` into modular validation functions
  - Decomposed `sse_get_safe_wp_cli_path()` into specialized validation functions
  - **NEW**: Refactored `sse_validate_filepath()` into 4 focused functions:
    - `sse_check_path_traversal()` - Directory traversal validation
    - `sse_resolve_file_path()` - Secure path resolution
    - `sse_check_path_within_base()` - Base directory validation
    - Reduced complexity from 11 to under 10, NPath from 224 to under 200
  - **NEW**: Refactored `sse_serve_file_download()` into 5 specialized functions:
    - `sse_validate_download_file_data()` - Input validation and sanitization
    - `sse_validate_download_file_access()` - File access and security validation
    - `sse_set_download_headers()` - HTTP header management
    - `sse_output_file_content()` - File content output handling
    - Reduced complexity from 12 to under 10, NPath from 288 to under 200
- **Cyclomatic Complexity Reduction**: Refactored complex functions to meet PHPMD threshold (≤10):
  - `sse_log()`: Split into `sse_store_log_in_database()` and `sse_output_log_message()` helpers
  - `sse_resolve_file_path()`: Extracted `sse_validate_file_extension()` and `sse_resolve_nonexistent_file_path()`
  - `sse_output_file_content()`: Created `sse_validate_file_output_security()` and `sse_serve_file_via_readfile()` helpers
- **Code Structure**: Eliminated unnecessary else expressions throughout codebase
- **WordPress-Specific PHPMD Configuration**: Created `phpmd-wordpress.xml` with WordPress-optimized rules:
  - Suppresses `Superglobals` warnings (WordPress standard practice)
  - Excludes `MissingImport` for WordPress core classes (WP_Error, etc.)
  - Allows `ExitExpression` for security redirects and file downloads
  - Permits `ElseExpression` for WordPress security patterns
- **File System Operations**: Replaced direct file operations with WordPress best practices:
  - Converted `fopen`/`fread`/`fclose` to `readfile()` and WP_Filesystem methods
  - Added proper path construction using `trailingslashit()` instead of hardcoded separators
  - Enhanced file download security with proper output handling
- **Output Escaping**: Added proper phpcs:ignore comments for binary file downloads
- **Text Domain Consistency**: Fixed all remaining text domain inconsistencies:
  - Changed remaining legacy slug instances to 'enginescript-site-exporter'
  - Updated all translation function calls for consistency
  - Fixed output escaping in `wp_die()` calls using `esc_html__()` instead of `__()`
  - Added proper escaping for WP_Error messages in `wp_die()` calls
- **PHPStan Compliance**: Fixed all static analysis errors:
  - Corrected type inference issues with `ini_get()` return values
  - Fixed PHPDoc parameter name mismatches
  - Resolved unreachable code in ternary operators
  - Standardized function return types (WP_Error|true patterns)
  - Fixed syntax error in try-catch block (extra closing brace)
- **PHPStan Configuration**: Updated `phpstan.neon` with `treatPhpDocTypesAsCertain: false` to resolve type inference warnings
- **Type Safety Improvements**:
  - Fixed PHPDoc type annotations for functions that can return `false` (e.g., `sse_resolve_file_path`, `sse_check_path_within_base`)
  - Removed redundant type checks where PHPStan could infer types from context
  - Enhanced rate limiting logic with explicit type validation for transient values
- **Security Enhancements**:
  - **Enhanced path validation**: Added directory traversal protection with multiple security layers
  - **File download security**: Comprehensive input validation and sanitization for download operations
  - **XSS prevention**: Proper handling of binary file content with security comments
  - **Input sanitization**: All user input properly sanitized with WordPress functions
- **GitHub Workflow Integration**: Updated CI workflow to use WordPress-specific PHPMD configuration
- **Performance**: Reduced NPath complexity and improved code maintainability

### Security Hardening and SSRF Prevention (1.6.7)

- **File Access Security**: Enhanced file validation to prevent Server-Side Request Forgery (SSRF) attacks:
  - Added explicit file extension allowlist (ZIP and SQL files only)
  - Implemented strict path validation within WordPress content directory
  - Added realpath validation to prevent symlink attacks
  - Enhanced parent directory validation with WordPress root checks
- **Download Security**: Strengthened file download mechanisms:
  - Multiple validation layers before file access
  - Explicit checks for file type, path, and directory containment
  - Added security logging for all file access attempts
  - Enhanced header security (X-Content-Type-Options, X-Frame-Options)
- **XSS Prevention**: Improved output security for file downloads:
  - Dynamic Content-Type headers based on validated file extensions
  - Additional security headers to prevent MIME sniffing and framing
  - Enhanced logging with user and IP tracking for security events
- **Static Analysis Compliance**: Addressed Codacy security warnings:
  - Made security validations more explicit for automated scanning tools
  - Added comprehensive input validation and sanitization
  - Implemented allowlist approach instead of blacklist for file operations

### WordPress Compatibility Notes (1.6.7)

- MissingImport warnings for WP_Error are expected in WordPress plugins (core class availability)
- Superglobals access follows WordPress security best practices with proper sanitization
- Exit expressions are required for file download security and redirect patterns
- Direct file operations replaced with WordPress filesystem abstraction layer
- Binary file downloads properly handled with security annotations for static analysis tools

### Code Quality Metrics (1.6.7)

- **PHPMD Compliance**: All functions now under complexity thresholds:
  - Cyclomatic Complexity: All functions reduced to under 10 (was 12+ for 2 functions)
  - NPath Complexity: All functions reduced to under 200 (was 288+ for 2 functions)
- Code Maintainability: Improved through function decomposition and clear separation of concerns
- PHPMD Score: Perfect compliance with all cleancode, codesize, design, and naming metrics
- PHPStan Level: All static analysis errors resolved with proper type handling
- File System Compliance: 100% WordPress filesystem abstraction usage
- Security Score: Enhanced protection against OWASP Top 10 vulnerabilities
- **WordPress Standards**: Full compliance with WordPress Coding Standards:
  - Text Domain: 100% consistency across all translation functions
  - Output Escaping: All output properly escaped or documented as safe
  - Input Sanitization: All user input properly validated and sanitized
- **Function Count**: Added 7 new focused helper functions for better modularity and testability

## 1.6.6 - June 9, 2025

### Security & Best Practices Improvements (1.6.6)

- **CRITICAL**: Added missing secure download and delete handlers for export files
- **Text Domain Consistency**: Fixed all text domain inconsistencies to use 'enginescript-site-exporter'
- **Enhanced Shell Security**: Improved WP-CLI path validation with comprehensive security checks
- **Path Traversal Protection**: Enhanced file path validation with better edge case handling
- **Global Variable Handling**: Improved WordPress filesystem API initialization and error handling
- **Rate Limiting**: Added download rate limiting (1 download per minute per user)
- **Scheduled Deletion Security**: Added validation to scheduled file deletion to prevent unauthorized deletions
- **Information Disclosure**: Sanitized error messages to prevent server path exposure
- **Code Quality**: Removed duplicate function definitions and improved error handling

### New Security Features (1.6.6)

- Enhanced WP-CLI binary validation with version checking
- Proper filesystem API error handling throughout
- User capability verification for all download/delete operations
- Secure file serving with appropriate headers for large files
- Request source validation and nonce verification

## 1.6.5 - June 8, 2025

### Code Quality Improvements (1.6.5)

- **PHPMD Compliance**: Refactored entire codebase to address PHP Mess Detector warnings and improve code quality
- **Function Complexity**: Broke down large functions into smaller, single-responsibility functions for better maintainability
- **Variable Naming**: Converted variable names to camelCase format to comply with PHPMD standards
- **Error Handling**: Removed unnecessary error control operators (@) and improved error handling
- **Code Structure**: Eliminated unnecessary else expressions and duplicate code
- **Global Variables**: Fixed naming conventions for WordPress global variables
- **Function Splitting**: Split complex boolean-flag functions into separate, dedicated functions

## 1.6.4 - June 6, 2025

### Bug Fixes (1.6.4)

- **Text Domain Fix**: Fixed mismatched text domain to properly use 'enginescript-site-exporter' for WordPress plugin compliance
- **Plugin Header Compliance**: Updated plugin text domain header to match expected slug format for WordPress.org directory standards

## 1.6.3 - June 6, 2025

### Updates (1.6.3)

- **Version Bump**: Updated plugin version to maintain consistency across all files

## 1.6.2 - June 6, 2025

### Plugin Rebrand (1.6.2)

- **Plugin Renamed**: Changed plugin name from "EngineScript: Simple Site Exporter" to "EngineScript Site Exporter"
- **Plugin File Renamed**: Changed main plugin file from 'simple-site-exporter.php' to 'enginescript-site-exporter.php'
- **Repository Moved**: Moved repository from 'EngineScript-Simple-Site-Exporter' to 'enginescript-site-exporter'
- **Text Domain Updated**: Updated text domain from 'Simple-Site-Exporter' to 'enginescript-site-exporter' for consistency
- **Package Name Updated**: Updated composer package name to 'enginescript/enginescript-site-exporter'
- **Directory Names Updated**: Updated export directory from 'enginescript-sse-site-exports' to 'enginescript-site-exporter-exports'
- **GitHub Workflows Updated**: Updated all GitHub Actions workflows to reference the new plugin name, filename, and repository
- **Documentation Updated**: Updated README.md, readme.txt, and all documentation to reflect the new plugin name and repository

## 1.6.1 - May 24, 2025

### WordPress Plugin Check Compliance (1.6.1)

- **Fixed Timezone Issues**: Replaced all `date()` calls with `gmdate()` to avoid timezone-related problems
- **Improved Debug Logging**: Enhanced logging function with WordPress `wp_debug_log()` support and proper fallback
- **Fixed Admin Page Title**: Corrected `get_admin_page_title()` usage in template output
- **Enhanced Documentation**: Added proper PHPDoc comments and phpcs ignore annotations for necessary discouraged functions
- **Plugin Check Compliance**: Addressed all WordPress Plugin Check warnings and errors

## 1.6.0 - May 15, 2025

### Major Security and Code Quality Improvements (1.6.0)

- **Enhanced Logging**: Replaced all direct `error_log()` calls with secure `sse_log()` function that respects WP_DEBUG settings, includes timestamps, and stores critical errors in database (limited to last 20 entries)
- **Improved File Operations**: Replaced unsafe `@unlink()` calls with `sse_safely_delete_file()` function using WordPress Filesystem API with proper error handling
- **Execution Time Safety**: Enhanced `set_time_limit()` usage with safety checks, reasonable 30-minute limits instead of unlimited execution, and proper logging
- **Path Security**: Added `sse_validate_filepath()` function to prevent directory traversal attacks with comprehensive path validation
- **Text Domain Standardization**: Updated all translatable strings to use consistent 'enginescript-site-exporter' text domain across the entire plugin

### GitHub Actions Security Updates (1.6.0)

- Pinned all GitHub Actions to specific commit hashes instead of version tags for improved security
- Updated all workflow references from Simple-WP-Optimizer to EngineScript Site Exporter
- Enhanced CI/CD pipeline security with version pinning and proper repository references

### Code Structure Improvements (1.6.0)

- Fixed corrupted text domain line in plugin header
- Corrected malformed comment sections
- Enhanced code organization and readability
- Added comprehensive security helper functions with WordPress-compatible logging

### WordPress Compatibility (1.6.0)

- Created standard WordPress plugin `readme.txt` file with all required sections
- Updated `composer.json` package information and license to GPL-3.0-or-later
- Improved WordPress coding standards compliance throughout the plugin

## 1.5.9 - May 3, 2025

### Security Enhancements (1.5.9)

- Reduced export file auto-deletion time from 1 hour to 5 minutes for improved security
- Removed dependency on external systems for file security management

### Improvements (1.5.9)

- Simplified user interface by removing environment-specific messaging
- Enhanced self-containment of the plugin's security features

## 1.5.8 - May 1, 2025

### Code Quality Improvements (1.5.8)

- Refactored validation functions to eliminate code duplication
- Created shared `sse_validate_export_file()` function for both download and deletion operations
- Improved code maintainability while preserving security controls

### Security Enhancements (1.5.8)

- Updated license to GPL v3
- Enhanced file path validation
- Strengthened regex pattern for export file validation
- Added proper documentation for security-related functions

## 1.5.7 - April 25, 2025

### Security Enhancements (1.5.7)

- Implemented comprehensive file path validation function to prevent directory traversal attacks
- Added referrer checks for download and delete operations
- Enhanced file pattern validation with stronger regex patterns
- Improved path display in admin interface using [wp-root] placeholder for better security
- Added security headers to file download operations
- Implemented strict comparison operators throughout the plugin
- Consistently applied sanitization to nonce values before verification

### Code Improvements (1.5.7)

- Standardized input sanitization and validation across all user inputs
- Enhanced error logging for security-related events
- Applied path normalization for consistent security checks
- Improved documentation with security considerations

## 1.5.6 - April 15, 2025

### Features (1.5.6)

- Added more detailed logging for export operations
- Improved error handling during file operations

### Bug Fixes (1.5.6)

- Fixed potential memory issues during export of large sites
- Resolved a race condition in the scheduled deletion process

## 1.5.5 - March 2, 2025

### Features (1.5.5)

- Added automatic deletion of export files after 1 hour
- Implemented secure download mechanism through WordPress admin
- Added ability to manually delete export files

### Improvements (1.5.5)

- Enhanced file export process with better error handling
- Improved progress feedback during export operations

## 1.5.4 - February 10, 2025

### Features (1.5.4)

- Added deletion request validation and confirmation
- Implemented redirect after deletion with status notification

### Bug Fixes (1.5.4)

- Fixed database export issues on some hosting environments

## 1.5.3 - January 5, 2025

### Features (1.5.3)

- Added manual export file deletion
- Enhanced security for file operations

### Improvements (1.5.3)

- Better error handling for WP-CLI operations
- Improved user interface with clearer notifications

## 1.5.2 - December 12, 2024

### Features (1.5.2)

- Added WP-CLI integration for database exports
- Implemented fallback methods for database exports

### Bug Fixes (1.5.2)

- Fixed ZIP creation issues on certain hosting environments

## 1.5.1 - November 15, 2024

### Improvements (1.5.1)

- Enhanced ZIP file creation process
- Improved handling of large files
- Added exclusion for cache and temporary directories

## 1.5.0 - October 20, 2024

### Initial Release

- Basic site export functionality
- Database and file export
- Simple admin interface
