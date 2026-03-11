# EngineScript Site Exporter - WordPress Plugin

## Project Overview

This is a secure WordPress site export plugin that creates complete site backups including files and database as downloadable ZIP archives. Designed for WordPress administrators who need reliable, secure site exports for migrations, backups, or development purposes.

## Plugin Details

- **Name:** EngineScript Site Exporter
- **Version:** 2.0.0
- **WordPress Compatibility:** 6.5+
- **PHP Compatibility:** 7.4+
- **License:** GPL-3.0-or-later
- **Text Domain:** enginescript-site-exporter

## Architecture & Design Patterns

### Modular Plugin Architecture

The plugin uses a bootstrap + include files architecture:

```php
// enginescript-site-exporter.php — Bootstrap (constants, require_once, init)
// includes/helpers.php     — Logging, IP detection, execution time, filesystem init
// includes/security.php    — Path validation, traversal checks, extension validation
// includes/admin-page.php  — Admin UI rendering, notices, asset enqueueing
// includes/export.php      — Export workflow, validation, directory setup, DB export
// includes/archive.php     — ZIP operations, file iteration, exclusion logic
// includes/cleanup.php     — Deletion handler, scheduling, bulk cleanup
// includes/download.php    — Secure download handler, headers, rate limiting
```

### Plugin Initialization

The plugin uses proper WordPress initialization patterns:

```php
function sse_init_plugin() {
    // Hook admin menu creation
    add_action( 'admin_menu', 'sse_admin_menu' );
    // Other initialization code
}
add_action( 'plugins_loaded', 'sse_init_plugin' );
```

### File Structure

- `enginescript-site-exporter.php` - Bootstrap: constants, require_once, plugin init
- `includes/helpers.php` - Logging, IP detection, execution time, filesystem init
- `includes/security.php` - Path validation, traversal checks, extension validation
- `includes/admin-page.php` - Admin UI rendering, notices, asset enqueueing
- `includes/export.php` - Export workflow, validation, directory setup, DB export
- `includes/archive.php` - ZIP operations, file iteration, exclusion logic
- `includes/cleanup.php` - Deletion handler, scheduling, bulk cleanup
- `includes/download.php` - Secure download handler, headers, rate limiting
- `css/admin.css` - Admin page styles (enqueued on plugin page only)
- `js/admin.js` - Admin page scripts (enqueued on plugin page only)
- `languages/` - Translation files (.pot file included)
- `CHANGELOG.md` - Developer changelog
- `README.md` - Developer documentation
- `readme.txt` - WordPress.org plugin directory readme
- `ROADMAP.md` - Prioritized improvement roadmap
- `.github/workflows/` - CI/CD automation

## WordPress Coding Standards

### Naming Conventions

- **Functions:** `sse_snake_case` (WordPress standard with plugin prefix)
- **Variables:** `$snake_case`
- **Constants:** `SSE_UPPER_SNAKE_CASE`
- **Text Domain:** Always use `'enginescript-site-exporter'`

### Security Requirements

- Always use `esc_html()`, `esc_attr()`, `esc_url()` for output
- Sanitize input with `sanitize_text_field()`, `wp_unslash()`, etc.
- Use `current_user_can( 'manage_options' )` for capability checks
- Implement proper nonce verification for all forms and actions
- Use WordPress Filesystem API for file operations
- Validate all file paths to prevent directory traversal

### WordPress Integration

- **Hooks:** Proper use of actions and filters
- **File Operations:** WordPress Filesystem API only
- **Database:** WP-CLI integration for secure database exports
- **Internationalization:** All strings use `esc_html__()` or `esc_html_e()`
- **Admin Interface:** Proper admin page integration

## Plugin-Specific Context

### Core Functionality

#### Site Export Process

- **File Export:** Complete WordPress installation including themes, plugins, uploads
- **Database Export:** Secure database dump using WP-CLI when available
- **ZIP Creation:** All files compressed into downloadable archive
- **Automatic Cleanup:** Export files auto-deleted after 5 minutes for security

#### Security Features

- **Path Traversal Protection:** Comprehensive file path validation
- **File Access Control:** Strict file extension allowlist (ZIP, SQL only)
- **Authentication:** Admin capability checks for all operations
- **Rate Limiting:** Download throttling (1 request per minute per user)
- **Nonce Verification:** CSRF protection on all forms and actions

#### Performance Optimizations

- **Export Locking:** Transient-based system prevents concurrent exports
- **Memory Management:** Stream-based file operations for large files
- **User-Configurable Limits:** File size filtering (100MB, 500MB, 1GB options)
- **Resource Management:** Proper execution time limits and cleanup

#### Admin Interface

- **Export Form:** User-friendly interface with file size options
- **File Management:** Download and delete export files
- **Status Feedback:** Clear success/error messages
- **Security Notices:** User guidance on file security

### File Operation Security

- **Directory Validation:** All paths validated within WordPress upload directory
- **Extension Allowlist:** Only ZIP and SQL files allowed for download
- **Path Resolution:** Multiple validation layers prevent symlink attacks
- **Real Path Validation:** Prevents path manipulation vulnerabilities

### Performance Considerations

- **Large Site Support:** Handles multi-GB WordPress installations
- **Memory Efficiency:** Files processed individually to avoid exhaustion
- **Export Scalability:** Optimized for various hosting environments
- **Cleanup Efficiency:** Automatic file removal prevents disk space issues

### WP-CLI Integration

- **Database Export:** Efficient database dumps via WP-CLI
- **Security Validation:** WP-CLI executable verification
- **Error Handling:** Returns WP_Error when WP-CLI is unavailable (required dependency)
- **Root Detection:** Conditional --allow-root flag usage

## Development Standards

### Error Handling

- **WP_Error Usage:** Consistent error object returns throughout
- **Comprehensive Logging:** Structured logging with severity levels
- **Security Logging:** Detailed logs for security events
- **User Feedback:** Clear error messages without information disclosure

### Documentation

- **PHPDoc Compliance:** Complete documentation for all functions
- **Security Comments:** Detailed security justifications
- **Code Examples:** Clear usage examples in documentation
- **Version Control:** Comprehensive changelog maintenance

### Testing & Quality Assurance

- **PHPStan Level 5:** Static analysis compliance
- **PHPCS WordPress Standards:** Full coding standards compliance
- **PHPMD Compliance:** Code quality and complexity management
- **Security Analysis:** Regular vulnerability assessments

## When Reviewing Code

### Critical Issues to Flag

1. **File Security Vulnerabilities** (path traversal, unauthorized access)
2. **Export Process Security** (file cleanup, access controls)
3. **Memory Management** (large file handling, resource limits)
4. **WordPress Standard Violations** (coding standards, API usage)
5. **Permission and Capability Issues** (admin access, nonce verification)

### Plugin-Specific Security Concerns

1. **File Path Validation:** Ensure all paths are properly validated
2. **Export File Access:** Verify download security and cleanup
3. **Database Export Security:** Check WP-CLI command construction
4. **Upload Directory Security:** Validate file operations within allowed areas
5. **Temporary File Management:** Ensure proper cleanup of export files

### Performance Focus Areas

1. **Large File Handling:** Memory-efficient file operations
2. **Export Process Optimization:** Minimize resource usage
3. **Concurrent Export Prevention:** Export locking mechanisms
4. **File Size Management:** User-configurable limits and filtering
5. **Cleanup Efficiency:** Automatic file removal processes

### Positive Patterns to Recognize

1. **Security-First Design:** Multiple validation layers
2. **WordPress API Compliance:** Proper use of WordPress functions
3. **User Experience:** Clear interface and feedback
4. **Performance Optimization:** Efficient resource management
5. **Documentation Quality:** Comprehensive code documentation

### Suggestions to Provide

1. **WordPress-Specific Solutions:** Prefer WordPress APIs over generic PHP
2. **Security Enhancements:** Additional validation and protection layers
3. **Performance Improvements:** Memory and resource optimizations
4. **User Experience:** Interface and workflow improvements
5. **Documentation Updates:** Code and user documentation

Remember: This plugin prioritizes security, file operation safety, and WordPress ecosystem compatibility. All file operations must be thoroughly validated and secure.
