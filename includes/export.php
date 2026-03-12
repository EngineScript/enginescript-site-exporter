<?php
/**
 * Export workflow: request handling, validation, directory setup, DB export.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Handles the site export process when the form is submitted.
 *
 * @since 1.0.0
 * @return void
 */
function sse_handle_export(): void {
	if ( ! sse_validate_export_request() ) {
		return;
	}

	// Check for and set an export lock.
	if ( get_transient( 'sse_export_lock' ) ) {
		sse_show_error_notice( __( 'An export process is already running. Please wait for it to complete before starting a new one.', 'enginescript-site-exporter' ) );
		return;
	}
	// Set lock with a 1-hour expiration to prevent permanent locks on failure.
	set_transient( 'sse_export_lock', time(), HOUR_IN_SECONDS );

	try {
		$max_exec_time = sse_get_execution_time_limit();
		if ( $max_exec_time > 0 && $max_exec_time < 1800 ) {
			sse_log( "Current execution time limit ({$max_exec_time}s) may be insufficient for large exports. Consider increasing server limits.", 'warning' );
		}

		$export_paths = sse_setup_export_directories();
		if ( is_wp_error( $export_paths ) ) {
			sse_show_error_notice( $export_paths->get_error_message() );
			return;
		}

		$database_file = sse_export_database( $export_paths['export_dir'] );
		if ( is_wp_error( $database_file ) ) {
			sse_show_error_notice( $database_file->get_error_message() );
			return;
		}

		$zip_result = sse_create_site_archive( $export_paths, $database_file );
		if ( is_wp_error( $zip_result ) ) {
			sse_cleanup_files( [ $database_file['filepath'] ] );
			sse_show_error_notice( $zip_result->get_error_message() );
			return;
		}

		sse_cleanup_files( [ $database_file['filepath'] ] );

		sse_schedule_export_cleanup( $zip_result['filepath'] );

		// Schedule a bulk cleanup sweep in case individual files were missed.
		sse_schedule_bulk_cleanup();

		sse_show_success_notice( $zip_result );
	} finally {
		// Always release the lock and clean up user preferences.
		delete_transient( 'sse_export_lock' );
		delete_transient( 'sse_export_max_file_size_' . get_current_user_id() );
	}
}

/**
 * Validates the export request for security and permissions.
 *
 * @since 1.0.0
 * @return bool True if request is valid, false otherwise.
 */
function sse_validate_export_request(): bool { // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$post_action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification happens below
	if ( 'sse_export_site' !== $post_action ) {
		return false;
	}

	$post_nonce = isset( $_POST['sse_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sse_export_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This line retrieves nonce for verification
	if ( ! $post_nonce || ! wp_verify_nonce( $post_nonce, 'sse_export_action' ) ) {
		wp_die( esc_html__( 'Nonce verification failed! Please try again.', 'enginescript-site-exporter' ), 403 );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'enginescript-site-exporter' ), 403 );
	}

	// Store the user's max file size selection for use during export.
	$max_file_size = isset( $_POST['sse_max_file_size'] ) ? absint( $_POST['sse_max_file_size'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above
	set_transient( 'sse_export_max_file_size_' . get_current_user_id(), $max_file_size, HOUR_IN_SECONDS );

	return true;
}

/**
 * Sets up export directories and returns path information.
 *
 * @since 1.0.0
 * @return array{export_dir: string, export_url: string, export_dir_name: string}|WP_Error Array of paths on success, WP_Error on failure.
 */
function sse_setup_export_directories() {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
		return new WP_Error( 'upload_dir_error', __( 'Could not determine the WordPress upload directory or URL.', 'enginescript-site-exporter' ) );
	}

	$export_dir_name = SSE_EXPORT_DIR_NAME;
	$export_dir      = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	$export_url      = trailingslashit( $upload_dir['baseurl'] ) . $export_dir_name;

	if ( ! wp_mkdir_p( $export_dir ) && ! is_dir( $export_dir ) ) {
		sse_log( 'Failed to create export directory at path: ' . $export_dir, 'error' );
		return new WP_Error( 'export_dir_creation_failed', __( 'Could not create the export directory. Please verify filesystem permissions.', 'enginescript-site-exporter' ) );
	}

	$filesystem_init = sse_init_filesystem();
	if ( is_wp_error( $filesystem_init ) ) {
		return $filesystem_init;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem->is_writable( $export_dir ) ) {
		sse_log( 'Export directory is not writable: ' . $export_dir, 'error' );
		return new WP_Error( 'export_dir_not_writable', __( 'The export directory is not writable. Please adjust filesystem permissions.', 'enginescript-site-exporter' ) );
	}

	sse_create_index_file( $export_dir );

	return [
		'export_dir'      => $export_dir,
		'export_url'      => $export_url,
		'export_dir_name' => $export_dir_name,
	];
}

/**
 * Creates protection files in the export directory to prevent directory listing
 * and deny direct HTTP access to export files.
 *
 * Creates:
 * - index.php: Prevents directory listing.
 * - .htaccess: Denies direct HTTP access to all files (Apache).
 *
 * @since 2.0.0
 * @param string $export_dir The export directory path.
 * @return void
 */
function sse_create_index_file( string $export_dir ): void {
	if ( is_wp_error( sse_init_filesystem() ) ) {
		return;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem->is_writable( $export_dir ) ) {
		sse_log( 'Failed to write protection files or directory not writable: ' . $export_dir, 'error' );
		return;
	}

	// Create index.php to prevent directory listing.
	$index_file_path = trailingslashit( $export_dir ) . 'index.php';
	if ( ! file_exists( $index_file_path ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Checking controlled export directory
		$wp_filesystem->put_contents(
			$index_file_path,
			'<?php // Silence is golden.',
			FS_CHMOD_FILE
		);
	}

	// Create .htaccess to deny direct HTTP access (Apache).
	$htaccess_path = trailingslashit( $export_dir ) . '.htaccess';
	if ( ! file_exists( $htaccess_path ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Checking controlled export directory
		$htaccess_content  = "# Deny direct access to export files.\n";
		$htaccess_content .= "# For Nginx, add a location block to deny access to this directory.\n";
		$htaccess_content .= "<IfModule mod_authz_core.c>\n";
		$htaccess_content .= "\tRequire all denied\n";
		$htaccess_content .= "</IfModule>\n";
		$htaccess_content .= "<IfModule !mod_authz_core.c>\n";
		$htaccess_content .= "\tOrder deny,allow\n";
		$htaccess_content .= "\tDeny from all\n";
		$htaccess_content .= "</IfModule>\n";

		$wp_filesystem->put_contents(
			$htaccess_path,
			$htaccess_content,
			FS_CHMOD_FILE
		);
	}
}

/**
 * Finds a safe path to the WP-CLI executable.
 *
 * @since 2.0.0
 * @return string|WP_Error The path to WP-CLI on success, or a WP_Error on failure.
 */
function sse_get_safe_wp_cli_path() {
	// Check for WP-CLI in common paths.
	$common_paths = [
		ABSPATH . 'wp-cli.phar',
		dirname( ABSPATH ) . '/wp-cli.phar',
		'/usr/local/bin/wp',
		'/usr/bin/wp',
	];

	foreach ( $common_paths as $path ) {
		if ( is_executable( $path ) ) {
			return $path;
		}
	}

	// Check if 'wp' is in the system's PATH.
	// Use 'where' for Windows and 'command -v' for Unix-like systems.
	if ( function_exists( 'shell_exec' ) ) {
		$command = ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) ? 'where wp' : 'command -v wp';
		$path    = shell_exec( $command ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Safe command to find executable.

		if ( ! empty( $path ) ) {
			$trimmed = trim( $path );
			// Additional verification: ensure resolved path exists and is executable (defense-in-depth).
			if ( file_exists( $trimmed ) && is_executable( $trimmed ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Controlled path verification
				return $trimmed;
			}
		}
	}

	return new WP_Error( 'wp_cli_not_found', __( 'WP-CLI executable not found. Please ensure it is installed and in your server\'s PATH.', 'enginescript-site-exporter' ) );
}

/**
 * Exports the database and returns file information.
 *
 * @since 1.0.0
 * @param string $export_dir The directory to save the database dump.
 * @return array{filename: string, filepath: string}|WP_Error Array with file info on success, WP_Error on failure.
 */
function sse_export_database( string $export_dir ) {
	$site_name   = sanitize_file_name( get_bloginfo( 'name' ) );
	$timestamp   = gmdate( 'Y-m-d_H-i-s' );
	$db_filename = "db_dump_{$site_name}_{$timestamp}.sql";
	$db_filepath = trailingslashit( $export_dir ) . $db_filename;

	if ( ! function_exists( 'shell_exec' ) ) {
		return new WP_Error( 'shell_exec_disabled', __( 'shell_exec function is disabled on this server.', 'enginescript-site-exporter' ) );
	}

	// Enhanced WP-CLI path validation.
	$wp_cli_path = sse_get_safe_wp_cli_path();
	if ( is_wp_error( $wp_cli_path ) ) {
		return $wp_cli_path;
	}

	// Only append --allow-root if we are actually running as root (hardening).
	$allow_root_flag = '';
	if ( function_exists( 'posix_geteuid' ) ) {
		$uid = posix_geteuid();
		if ( false !== $uid && 0 === $uid ) {
			$allow_root_flag = ' --allow-root';
		}
	}
	$command = sprintf(
		'%s db export %s --path=%s%s',
		escapeshellarg( $wp_cli_path ), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
		escapeshellarg( $db_filepath ), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
		escapeshellarg( ABSPATH ), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
		$allow_root_flag
	);

	$output = shell_exec( $command . ' 2>&1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Required for WP-CLI database export: all parameters are validated and escaped with escapeshellarg()

	if ( ! file_exists( $db_filepath ) || filesize( $db_filepath ) <= 0 ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Validating WP-CLI export success
		// Sanitize WP-CLI output to avoid leaking absolute paths or sensitive data.
		$safe_output = '';
		if ( ! empty( $output ) ) {
			$lines       = array_slice( preg_split( '/\r?\n/', $output ), 0, 5 ); // Limit to first 5 lines.
			$lines       = array_map(
				static function ( $line ) {
					// Remove absolute paths (rudimentary) and collapse whitespace.
					$line = preg_replace( '#(/|[A-Za-z]:\\\\)[^\s]+#', '[path]', $line );
					$line = preg_replace( '/\s+/', ' ', $line );
					return trim( $line );
				},
				$lines
			);
			$safe_output = sanitize_text_field( implode( ' | ', $lines ) );
		}
		$error_message = $safe_output ? $safe_output : 'WP-CLI command failed silently.';
		return new WP_Error( 'db_export_failed', $error_message );
	}

	sse_log( 'Database export successful', 'info' );
	return [
		'filename' => $db_filename,
		'filepath' => $db_filepath,
	];
}
