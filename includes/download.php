<?php
/**
 * Download and deletion: secure file serving, rate limiting, export deletion.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Handles secure download requests for export files.
 *
 * @since 2.0.0
 * @return void
 */
function sse_handle_secure_download(): void { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['sse_secure_download'] ) || ! isset( $_GET['sse_download_nonce'] ) ) {
		return;
	}

	// Verify nonce.
	$nonce = sanitize_text_field( wp_unslash( $_GET['sse_download_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'sse_secure_download' ) ) {
		wp_die( esc_html__( 'Security check failed. Please try again.', 'enginescript-site-exporter' ), 403 );
	}

	// Verify user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to download export files.', 'enginescript-site-exporter' ), 403 );
	}

	// Verify request origin.
	$referer_check = sse_validate_request_referer();
	if ( is_wp_error( $referer_check ) ) {
		wp_die( esc_html( $referer_check->get_error_message() ), 403 );
	}

	$filename   = sanitize_file_name( wp_unslash( $_GET['sse_secure_download'] ) );
	$validation = sse_validate_export_file_for_download( $filename );

	if ( is_wp_error( $validation ) ) {
		wp_die( esc_html( $validation->get_error_message() ), 404 );
	}

	// Rate limiting check.
	if ( ! sse_check_download_rate_limit() ) {
		wp_die( esc_html__( 'Too many download requests. Please wait before trying again.', 'enginescript-site-exporter' ), 429 );
	}

	sse_serve_file_download( $validation );
}

/**
 * Handles manual deletion of export files.
 *
 * @since 2.0.0
 * @return void
 */
function sse_handle_export_deletion(): void { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['sse_delete_export'] ) || ! isset( $_GET['sse_delete_nonce'] ) ) {
		return;
	}

	// Verify nonce.
	$nonce = sanitize_text_field( wp_unslash( $_GET['sse_delete_nonce'] ) );
	if ( ! wp_verify_nonce( $nonce, 'sse_delete_export' ) ) {
		wp_die( esc_html__( 'Security check failed. Please try again.', 'enginescript-site-exporter' ), 403 );
	}

	// Verify user capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to delete export files.', 'enginescript-site-exporter' ), 403 );
	}

	// Verify request origin.
	$referer_check = sse_validate_request_referer();
	if ( is_wp_error( $referer_check ) ) {
		wp_die( esc_html( $referer_check->get_error_message() ), 403 );
	}

	$filename   = sanitize_file_name( wp_unslash( $_GET['sse_delete_export'] ) );
	$validation = sse_validate_basic_export_file( $filename );

	if ( is_wp_error( $validation ) ) {
		wp_die( esc_html( $validation->get_error_message() ), 404 );
	}

	if ( sse_safely_delete_file( $validation['filepath'] ) ) {
		sse_log( 'Manual deletion of export file: ' . $validation['filepath'], 'info' );
		wp_safe_redirect( admin_url( 'tools.php?page=enginescript-site-exporter&sse_notice=deleted' ) );
		exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress standard: exit required after wp_safe_redirect.
	}

	sse_log( 'Failed manual deletion of export file: ' . $validation['filepath'], 'error' );
	wp_safe_redirect( admin_url( 'tools.php?page=enginescript-site-exporter&sse_notice=delete_failed' ) );
	exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress standard: exit required after wp_safe_redirect.
}

/**
 * Implements basic rate limiting for downloads.
 *
 * @since 2.0.0
 * @return bool True if request is within rate limits, false otherwise.
 */
function sse_check_download_rate_limit(): bool {
	$user_id        = get_current_user_id();
	$rate_limit_key = 'sse_download_rate_limit_' . $user_id;
	$current_time   = time();

	$last_download = get_transient( $rate_limit_key );

	// Allow one download per minute per user.
	if ( false !== $last_download && is_numeric( $last_download ) && ( $current_time - $last_download ) < 60 ) {
		return false;
	}

	set_transient( $rate_limit_key, $current_time, 60 );
	return true;
}

/**
 * Sets appropriate headers for file download.
 *
 * @since 2.0.0
 * @param string $filename  The filename for download.
 * @param int    $filesize  The file size in bytes.
 * @return void
 */
function sse_set_download_headers( string $filename, int $filesize ): void {
	// Security: Set safe Content-Type based on file extension to prevent XSS.
	$file_extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	switch ( $file_extension ) {
		case 'zip':
			$content_type = 'application/zip';
			break;
		case 'sql':
			$content_type = 'application/sql';
			break;
		default:
			// Security: Default to octet-stream for unknown types to prevent execution.
			$content_type = 'application/octet-stream';
			break;
	}

	// Security: Set headers to prevent XSS and ensure proper download behavior.
	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
	header( 'Content-Length: ' . absint( $filesize ) );
	header( 'Cache-Control: no-cache, no-store, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	header( 'X-Content-Type-Options: nosniff' ); // Security: Prevent MIME sniffing.
	header( 'X-Frame-Options: DENY' ); // Security: Prevent framing.

	// Disable output buffering for large files.
	if ( ob_get_level() ) {
		ob_end_clean();
	}
}

/**
 * Validates file output security before serving download.
 *
 * Security: Returns the realpath()-resolved filepath to ensure the path used for
 * readfile() is the same path that was validated (prevents TOCTOU and SSRF).
 *
 * @since 2.0.0
 * @param string $filepath The file path to validate.
 * @return string The realpath()-resolved file path, safe for readfile().
 */
function sse_validate_file_output_security( string $filepath ): string {
	// Security: Final validation before file output to prevent SSRF.
	if ( ! sse_validate_file_extension( $filepath ) ) {
		sse_log( 'Security: Blocked attempt to serve file with invalid extension: ' . pathinfo( $filepath, PATHINFO_EXTENSION ), 'security' );
		wp_die( esc_html__( 'Access denied - invalid file type.', 'enginescript-site-exporter' ) );
	}

	// Security: Ensure file is within our controlled directory before serving.
	$upload_dir      = wp_upload_dir();
	$export_dir      = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	$real_export_dir = realpath( $export_dir );
	$real_file_path  = realpath( $filepath );

	if ( false === $real_export_dir || false === $real_file_path || 0 !== strpos( $real_file_path, $real_export_dir ) ) {
		sse_log( 'Security: File not within controlled export directory: ' . $filepath, 'security' );
		wp_die( esc_html__( 'Access denied.', 'enginescript-site-exporter' ) );
	}

	return $real_file_path;
}

/**
 * Outputs file content for download using WordPress filesystem.
 *
 * @since 2.0.0
 * @param string $filepath The validated file path.
 * @param string $filename The filename for logging.
 * @return void
 * @throws Exception If file cannot be served.
 */
function sse_output_file_content( string $filepath, string $filename ): void {
	// Security: Validate and resolve to realpath before any filesystem access.
	$resolved_path = sse_validate_file_output_security( $filepath );

	// Security: Use resolved path (from realpath) for all filesystem operations to prevent SSRF/TOCTOU.
	if ( function_exists( 'readfile' ) && is_readable( $resolved_path ) && is_file( $resolved_path ) ) {
		readfile( $resolved_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Security validated export file download.
		sse_log( 'Secure file download served via readfile: ' . $filename, 'info' );
		exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Required to terminate script after file download.
	}

	sse_log( 'Failed to serve secure file download: ' . $filename, 'error' );
	wp_die( esc_html__( 'Unable to serve file download.', 'enginescript-site-exporter' ) );
}

/**
 * Serves a file download with enhanced security validation.
 *
 * @since 2.0.0
 * @param array{filename: string, filesize: int, filepath: string} $file_data Validated file information array.
 * @return void
 */
function sse_serve_file_download( array $file_data ): void {
	// Set download headers.
	sse_set_download_headers( $file_data['filename'], $file_data['filesize'] );

	// Output file content (includes final security validation before readfile).
	sse_output_file_content( $file_data['filepath'], $file_data['filename'] );
}
