<?php
/**
 * Plugin Name: EngineScript Site Exporter
 * Description: Exports the site files and database as a zip archive.
 * Version: 2.0.0
 * Author: EngineScript
 * Requires at least: 6.5
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: enginescript-site-exporter
 * Domain Path: /languages
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct access. Note: Using return here instead of exit.
if ( ! defined( 'ABSPATH' ) ) {
	return; // Prevent direct access.
}

// Define plugin version.
if ( ! defined( 'ES_SITE_EXPORTER_VERSION' ) ) {
	define( 'ES_SITE_EXPORTER_VERSION', '2.0.0' );
}

// Define allowed file extensions for export operations.
if ( ! defined( 'SSE_ALLOWED_EXTENSIONS' ) ) {
	define( 'SSE_ALLOWED_EXTENSIONS', [ 'zip', 'sql' ] );
}

// Define export directory name used across the plugin.
if ( ! defined( 'SSE_EXPORT_DIR_NAME' ) ) {
	define( 'SSE_EXPORT_DIR_NAME', 'enginescript-site-exporter-exports' );
}

// Define the filter name for maximum file size override.
if ( ! defined( 'SSE_FILTER_MAX_FILE_SIZE' ) ) {
	define( 'SSE_FILTER_MAX_FILE_SIZE', 'sse_max_file_size_for_export' );
}

// Define plugin file constant for use in included files.
if ( ! defined( 'SSE_PLUGIN_FILE' ) ) {
	define( 'SSE_PLUGIN_FILE', __FILE__ );
}

/**
 * WordPress Core Classes Documentation
 *
 * This plugin uses WordPress core classes which are automatically available
 * in the WordPress environment. These classes don't require explicit imports
 * or use statements as they are part of WordPress core.
 *
 * Core classes used:
 *
 * @see WP_Error - WordPress error handling class
 * @see ZipArchive - PHP ZipArchive class
 * @see RecursiveIteratorIterator - PHP SPL iterator
 * @see RecursiveDirectoryIterator - PHP SPL directory iterator
 * @see DirectoryIterator - PHP SPL directory iterator for cleanup
 * @see SplFileInfo - PHP SPL file information class
 * @see RuntimeException - PHP runtime exception class
 * @see Exception - PHP base exception class
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 */

// Load plugin components.
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/cleanup.php';
require_once __DIR__ . '/includes/download.php';

/**
 * Initialize the EngineScript Site Exporter plugin.
 *
 * This function is hooked to 'plugins_loaded' to ensure that all other plugins
 * and WordPress core functions are available before initializing this plugin.
 * This prevents load order issues and conflicts with other plugins.
 *
 * @since 1.8.5
 * @return void
 */
function sse_init_plugin(): void {
	// Hook admin menu creation.
	add_action( 'admin_menu', 'sse_admin_menu' );

	// Hook admin assets.
	add_action( 'admin_enqueue_scripts', 'sse_enqueue_admin_assets' );

	// Hook export handler.
	add_action( 'admin_init', 'sse_handle_export' );

	// Hook scheduled deletion handler.
	add_action( 'sse_delete_export_file', 'sse_delete_export_file_handler' );

	// Hook bulk cleanup handler.
	add_action( 'sse_bulk_cleanup_exports', 'sse_bulk_cleanup_exports_handler' );

	// Hook secure download handler.
	add_action( 'admin_init', 'sse_handle_secure_download' );

	// Hook export deletion handler.
	add_action( 'admin_init', 'sse_handle_export_deletion' );
}

// Initialize the plugin when all plugins are loaded.
add_action( 'plugins_loaded', 'sse_init_plugin' );
