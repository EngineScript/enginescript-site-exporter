<?php
/**
 * Security: path validation, file validation, traversal checks, referer verification.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Validates a file path for directory traversal attempts.
 *
 * @since 2.0.0
 * @param string $normalized_file_path The normalized file path to check.
 * @return bool True if path is safe, false if contains traversal patterns.
 */
function sse_check_path_traversal( string $normalized_file_path ): bool {
	// Block obvious directory traversal attempts.
	if ( strpos( $normalized_file_path, '..' ) !== false ||
			 strpos( $normalized_file_path, '/./' ) !== false ||
			 strpos( $normalized_file_path, '\\' ) !== false ) {
		return false;
	}
	return true;
}

/**
 * Resolves real file path, handling non-existent files securely.
 *
 * For existing files, returns the realpath() directly. For non-existent files (e.g. during
 * pre-creation validation), validates that the parent directory is within the WordPress uploads
 * directory and constructs a safe path from the resolved parent and sanitized filename.
 *
 * @since 2.0.0
 * @param string $normalized_file_path The normalized file path.
 * @return string|false Real file path on success, false on failure.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
function sse_resolve_file_path( string $normalized_file_path ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- Consolidated from 7 single-use functions for readability.
	// Security: Only allow files with safe extensions.
	if ( ! sse_validate_file_extension( $normalized_file_path ) ) {
		return false;
	}

	// Fast path: file exists, realpath resolves directly.
	$real_file_path = realpath( $normalized_file_path );
	if ( false !== $real_file_path ) {
		return $real_file_path;
	}

	// File doesn't exist yet — validate parent directory is within WordPress uploads.
	$upload_dir = wp_upload_dir();
	if ( ! isset( $upload_dir['basedir'] ) || empty( $upload_dir['basedir'] ) ) {
		sse_log( 'Could not determine WordPress upload directory for validation', 'error' );
		return false;
	}

	$upload_real_path = realpath( $upload_dir['basedir'] );
	if ( false === $upload_real_path ) {
		sse_log( 'Could not resolve WordPress upload directory real path', 'error' );
		return false;
	}

	$parent_dir = dirname( $normalized_file_path );
	$filename   = basename( $normalized_file_path );

	// Pre-validate parent directory path safety.
	if ( strpos( $parent_dir, '..' ) !== false || strpos( $parent_dir, 'wp-config' ) !== false ) {
		sse_log( 'Rejected unsafe parent directory path: ' . $parent_dir, 'security' );
		return false;
	}

	$norm_parent_dir = wp_normalize_path( $parent_dir );
	$norm_upload_dir = wp_normalize_path( $upload_dir['basedir'] );

	if ( strpos( $norm_parent_dir, $norm_upload_dir ) !== 0 ) {
		sse_log( 'Parent directory not within WordPress upload directory: ' . $parent_dir, 'security' );
		return false;
	}

	// Resolve parent directory and validate it's still within uploads after symlink resolution.
	$real_parent_dir = realpath( $norm_parent_dir );
	if ( false === $real_parent_dir || strpos( $real_parent_dir, $upload_real_path ) !== 0 ) {
		sse_log( 'Parent directory real path validation failed', 'security' );
		return false;
	}

	// Sanitize filename to prevent directory traversal.
	$filename = sanitize_file_name( $filename );
	if ( strpos( $filename, '..' ) !== false || strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
		sse_log( 'Filename contains invalid characters: ' . $filename, 'security' );
		return false;
	}

	return trailingslashit( $real_parent_dir ) . $filename;
}

/**
 * Validates file extension against allowed list.
 *
 * @since 2.0.0
 * @param string $file_path The file path to check.
 * @return bool True if extension is allowed, false otherwise.
 */
function sse_validate_file_extension( string $file_path ): bool {
	$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

	if ( ! in_array( $file_extension, SSE_ALLOWED_EXTENSIONS, true ) ) {
		sse_log( 'Rejected file access - invalid extension: ' . $file_extension, 'security' );
		return false;
	}

	return true;
}

/**
 * Checks if a file path is within the allowed base directory.
 *
 * @since 2.0.0
 * @param string|false $real_file_path The real file path or false if resolution failed.
 * @param string       $real_base_dir  The real base directory path.
 * @return bool True if the file is within the base directory, false otherwise.
 */
function sse_check_path_within_base( $real_file_path, string $real_base_dir ): bool {
	// Ensure both paths are available for comparison.
	if ( false === $real_file_path ) {
		return false;
	}

	// Ensure the file path starts with the base directory (with trailing slash).
	$real_base_dir  = rtrim( $real_base_dir, '/' ) . '/';
	$real_file_path = rtrim( $real_file_path, '/' ) . '/';

	$is_within_base = strpos( $real_file_path, $real_base_dir ) === 0;

	if ( ! $is_within_base ) {
		sse_log( 'Path validation failed - path outside base directory. File: ' . $real_file_path . ', Base: ' . $real_base_dir, 'warning' );
	}

	return $is_within_base;
}

/**
 * Validate that a file path is within the allowed directory.
 *
 * @since 1.0.0
 * @param string $file_path The file path to validate.
 * @param string $base_dir  The base directory that the file should be within.
 * @return bool True if the file path is safe, false otherwise.
 */
function sse_validate_filepath( string $file_path, string $base_dir ): bool {
	// Sanitize and normalize paths to handle different separators and resolve . and ..
	$normalized_file_path = wp_normalize_path( wp_unslash( $file_path ) );
	$normalized_base_dir  = wp_normalize_path( $base_dir );

	// Check for directory traversal attempts.
	if ( ! sse_check_path_traversal( $normalized_file_path ) ) {
		return false;
	}

	// Resolve real paths to prevent directory traversal.
	$real_file_path = sse_resolve_file_path( $normalized_file_path );
	$real_base_dir  = realpath( $normalized_base_dir );

	// Base directory must be resolvable for security.
	if ( false === $real_base_dir ) {
		sse_log( 'Could not resolve base directory: ' . $normalized_base_dir, 'security' );
		return false;
	}

	// Validate path is within base directory.
	return sse_check_path_within_base( $real_file_path, $real_base_dir );
}

/**
 * Validates export file for download operations.
 *
 * @since 2.0.0
 * @param string $filename The filename to validate.
 * @return array{filepath: string, filename: string, filesize: int}|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_for_download( string $filename ) {
	$basic_validation = sse_validate_basic_export_file( $filename );
	if ( is_wp_error( $basic_validation ) ) {
		return $basic_validation;
	}

	global $wp_filesystem;
	$file_path = $basic_validation['filepath'];

	// Check if file is readable.
	if ( ! $wp_filesystem->is_readable( $file_path ) ) {
		return new WP_Error( 'file_not_readable', __( 'Export file not readable.', 'enginescript-site-exporter' ) );
	}

	// Get file size using WP Filesystem.
	$file_size = $wp_filesystem->size( $file_path );
	if ( ! $file_size ) {
		return new WP_Error( 'file_size_error', __( 'Could not determine file size.', 'enginescript-site-exporter' ) );
	}

	$basic_validation['filesize'] = $file_size;
	return $basic_validation;
}

/**
 * Performs basic validation common to both download and deletion operations.
 *
 * @since 2.0.0
 * @param string $filename The filename to validate.
 * @return array{filepath: string, filename: string}|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_basic_export_file( string $filename ) {
	$basic_checks = sse_validate_filename_format( $filename );
	if ( is_wp_error( $basic_checks ) ) {
		return $basic_checks;
	}

	$path_validation = sse_validate_export_file_path( $filename );
	if ( is_wp_error( $path_validation ) ) {
		return $path_validation;
	}

	$existence_check = sse_validate_file_existence( $path_validation['filepath'] );
	if ( is_wp_error( $existence_check ) ) {
		return $existence_check;
	}

	return $path_validation;
}

/**
 * Validates filename format and basic security checks.
 *
 * @since 2.0.0
 * @param string $filename The filename to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_filename_format( string $filename ) {
	if ( empty( $filename ) ) {
		return new WP_Error( 'invalid_request', __( 'No file specified.', 'enginescript-site-exporter' ) );
	}

	// Prevent path traversal attacks.
	if ( strpos( $filename, '/' ) !== false || strpos( $filename, '\\' ) !== false ) {
		return new WP_Error( 'invalid_filename', __( 'Invalid filename.', 'enginescript-site-exporter' ) );
	}

	// Validate that it's our export file format.
	if ( ! preg_match( '/^site_export_sse_[a-f0-9]{7}_[a-zA-Z0-9_-]+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename ) ) {
		return new WP_Error( 'invalid_format', __( 'Invalid export file format.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Validates export file path and directory security.
 *
 * @since 2.0.0
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_path( string $filename ) {
	// Get the full path to the file.
	$upload_dir = wp_upload_dir();
	$export_dir = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	$file_path  = trailingslashit( $export_dir ) . $filename;

	// Validate the file path is within our export directory.
	if ( ! sse_validate_filepath( $file_path, $export_dir ) ) {
		return new WP_Error( 'invalid_path', __( 'Invalid file path.', 'enginescript-site-exporter' ) );
	}

	return [
		'filepath' => $file_path,
		'filename' => basename( $file_path ),
	];
}

/**
 * Validates file existence using WordPress filesystem.
 *
 * @since 2.0.0
 * @param string $file_path The file path to check.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_file_existence( string $file_path ) {
	$filesystem_init = sse_init_filesystem();
	if ( is_wp_error( $filesystem_init ) ) {
		return $filesystem_init;
	}

	global $wp_filesystem;

	if ( ! $wp_filesystem->exists( $file_path ) ) {
		return new WP_Error( 'file_not_found', __( 'Export file not found.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Validates request referer for security.
 *
 * @since 2.0.0
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_request_referer() {
	// Add referer check for request validation.
	$referer = wp_get_referer();
	if ( ! $referer || strpos( $referer, admin_url() ) !== 0 ) {
		return new WP_Error( 'invalid_request_source', __( 'Invalid request source.', 'enginescript-site-exporter' ) );
	}

	return true;
}
