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
 * @see SplFileInfo - PHP SPL file information class
 * @see Exception - PHP base exception class
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) - Single file WordPress plugin architecture
 */

/**
 * Safely get client IP address.
 *
 * @return string Client IP address or 'unknown' if not available.
 */
function sse_get_client_ip(): string {
	// WordPress-style IP detection with validation.
	$client_ip = 'unknown';

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- $_SERVER['REMOTE_ADDR'] is safe for IP logging when properly sanitized
	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	// Basic IP validation.
	if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) !== false ) {
		return $client_ip;
	}

	return 'unknown';
} // end sse_get_client_ip()

/**
 * Stores important log messages in database for review.
 *
 * @param string $message The log message.
 * @param string $level   The log level.
 * @return void
 */
function sse_store_log_in_database( string $message, string $level ): void {
	// Store last 20 important messages in an option.
	$logs   = get_option( 'sse_error_logs', [] );
	$logs[] = [
		'time'    => time(),
		'level'   => $level,
		'message' => $message,
		'user_id' => get_current_user_id(),
		'ip'      => sse_get_client_ip(),
	];

	// Keep only the most recent 20 logs.
	if ( count( $logs ) > 20 ) {
		$logs = array_slice( $logs, -20 );
	}

	update_option( 'sse_error_logs', $logs, false );
} // end sse_store_log_in_database()

/**
 * Outputs log message to WordPress debug log or error_log.
 *
 * @param string $formatted_message The formatted log message.
 * @return void
 */
function sse_output_log_message( string $formatted_message ): void {
	// Use WordPress logging (wp_debug_log is available in WP 5.1+).
	if ( function_exists( 'wp_debug_log' ) ) {
		wp_debug_log( $formatted_message );
	}
} // end sse_output_log_message()

/**
 * Safely log plugin messages
 *
 * @param string $message The message to log.
 * @param string $level   The log level (error, warning, info).
 * @return void
 */
function sse_log( string $message, string $level = 'info' ): void {
	// Check if WP_DEBUG is enabled.
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	// Format the message with a timestamp (using GMT to avoid timezone issues).
	$formatted_message = sprintf(
		'[%s] [%s] %s: %s',
		gmdate( 'Y-m-d H:i:s' ),
		'EngineScript Site Exporter',
		strtoupper( $level ),
		$message
	);

	// Only log if debug logging is enabled.
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	sse_output_log_message( $formatted_message );

	// Store logs in the database (errors and security events to prevent issues).
	if ( 'error' === $level || 'security' === $level ) {
		sse_store_log_in_database( $message, $level );
	}
} // end sse_log()

/**
 * Safely get the PHP execution time limit.
 *
 * @return int Current PHP execution time limit in seconds.
 */
function sse_get_execution_time_limit(): int {
	// Get the current execution time limit.
	$max_exec_time = ini_get( 'max_execution_time' );

	// Handle all possible return types from ini_get().
	if ( false === $max_exec_time ) {
		// Ini_get failed.
		return 30;
	}

	if ( '' === $max_exec_time ) {
		// Empty string returned.
		return 30;
	}

	if ( ! is_numeric( $max_exec_time ) ) {
		// Non-numeric value returned.
		return 30;
	}

	return (int) $max_exec_time;
} // end sse_get_execution_time_limit()

// --- Admin Menu ---
/**
 * Adds the Site Exporter page to the WordPress admin menu.
 *
 * @return void
 */
function sse_admin_menu(): void {
	add_management_page(
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ), // Page title (escaped by WordPress core).
		__( 'Site Exporter', 'enginescript-site-exporter' ),               // Menu title (escaped by WordPress core).
		'manage_options', // Capability required.
		'enginescript-site-exporter',
		'sse_exporter_page_html'
	);
}

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

// --- Exporter Page HTML ---
/**
 * Renders the exporter page HTML interface.
 *
 * @return void
 */
function sse_exporter_page_html(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'enginescript-site-exporter' ), 403 );
	}

	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		 wp_die( esc_html__( 'Could not determine the WordPress upload directory.', 'enginescript-site-exporter' ) );
	}
	$export_dir_path = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	$display_path    = str_replace( ABSPATH, '', $export_dir_path );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php
		// Display deletion feedback notices from redirect. phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only parameter, no state change.
		if ( isset( $_GET['sse_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sse_notice_type = sanitize_key( $_GET['sse_notice'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'deleted' === $sse_notice_type ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Export file successfully deleted.', 'enginescript-site-exporter' ) . '</p></div>';
			} elseif ( 'delete_failed' === $sse_notice_type ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete export file.', 'enginescript-site-exporter' ) . '</p></div>';
			}
		}
		?>
		<p><?php esc_html_e( 'Click the button below to generate a zip archive containing your WordPress files and a database dump (.sql file).', 'enginescript-site-exporter' ); ?></p>
		<p><strong><?php esc_html_e( 'Warning:', 'enginescript-site-exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'enginescript-site-exporter' ); ?></p>
		<p style="margin-top: 15px;">
			<?php
			// printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
			printf(
				// translators: %s: directory path.
				esc_html__( 'Exported .zip files will be saved in the following directory on the server: %s', 'enginescript-site-exporter' ),
				'<code>' . esc_html( $display_path ) . '</code>'
			);
			?>
		</p>
		<form method="post" action="" style="margin-top: 15px;">
			<?php wp_nonce_field( 'sse_export_action', 'sse_export_nonce' ); ?>
			<input type="hidden" name="action" value="sse_export_site">
			
			<table class="form-table" style="margin-bottom: 20px;">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sse_max_file_size"><?php esc_html_e( 'Maximum File Size', 'enginescript-site-exporter' ); ?></label>
						</th>
						<td>
							<select name="sse_max_file_size" id="sse_max_file_size">
								<option value="0"><?php esc_html_e( 'No limit (include all files)', 'enginescript-site-exporter' ); ?></option>
								<option value="104857600"><?php esc_html_e( '100 MB', 'enginescript-site-exporter' ); ?></option>
								<option value="524288000"><?php esc_html_e( '500 MB', 'enginescript-site-exporter' ); ?></option>
								<option value="1073741824"><?php esc_html_e( '1 GB', 'enginescript-site-exporter' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Files larger than this size will be excluded from the export. Choose "No limit" to include all files regardless of size.', 'enginescript-site-exporter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			
			<?php submit_button( __( 'Export Site', 'enginescript-site-exporter' ) ); ?>
		</form>
		<hr>
		<p>
			<?php esc_html_e( 'This plugin is part of the EngineScript project.', 'enginescript-site-exporter' ); ?>
			<a href="https://github.com/EngineScript/EngineScript" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Visit the EngineScript GitHub page', 'enginescript-site-exporter' ); ?>
			</a>
		</p>
		<p style="color: #b94a48; font-weight: bold;">
			<?php esc_html_e( 'Important:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'The exported zip file is publicly accessible while it remains in the above directory. For security, you should remove the exported file from the server once you are finished downloading it.', 'enginescript-site-exporter' ); ?>
		</p>
		<p style="color: #b94a48; font-weight: bold;">
			<?php esc_html_e( 'Security Notice:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'For your protection, the exported zip file will be automatically deleted from the server 5 minutes after it is created.', 'enginescript-site-exporter' ); ?>
		</p>
	</div>
	<?php
}

// --- Handle Export Action ---
/**
 * Handles the site export process when the form is submitted.
 *
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
} // end sse_validate_export_request()

/**
 * Initializes the WordPress Filesystem API.
 *
 * Centralizes the repeated WP_Filesystem initialization pattern
 * used across multiple functions.
 *
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_init_filesystem() {
	global $wp_filesystem;

	if ( ! empty( $wp_filesystem ) ) {
		return true;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! WP_Filesystem() ) {
		sse_log( 'Failed to initialize WordPress filesystem API', 'error' );
		return new WP_Error( 'filesystem_init_failed', __( 'Failed to initialize WordPress filesystem API.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Sets up export directories and returns path information.
 *
 * @return array|WP_Error Array of paths on success, WP_Error on failure.
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
		$htaccess_content = "# Deny direct access to export files.\n";
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
 * @param string $export_dir The directory to save the database dump.
 * @return array|WP_Error Array with file info on success, WP_Error on failure.
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
			$safe_output = implode( ' | ', $lines );
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

/**
 * Creates a site archive with database and files.
 *
 * @param array $export_paths Export directory paths.
 * @param array $database_file Database file information.
 * @return array|WP_Error Archive info on success, WP_Error on failure.
 */
function sse_create_site_archive( array $export_paths, array $database_file ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_not_available', __( 'ZipArchive class is not available on your server. Cannot create zip file.', 'enginescript-site-exporter' ) );
	}

	$site_name    = sanitize_file_name( get_bloginfo( 'name' ) );
	$timestamp    = gmdate( 'Y-m-d_H-i-s' );
	$random_str   = substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	$zip_filename = "site_export_sse_{$random_str}_{$site_name}_{$timestamp}.zip";
	$zip_filepath = trailingslashit( $export_paths['export_dir'] ) . $zip_filename;

	$zip = new ZipArchive();
	if ( $zip->open( $zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
		return new WP_Error(
			'zip_create_failed',
			sprintf(
				/* translators: %s: filename */
				__( 'Could not create zip file at %s', 'enginescript-site-exporter' ),
				basename( $zip_filepath ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_basename -- Safe usage: $zip_filepath is constructed from controlled inputs (WordPress upload dir + sanitized filename), not user input.
			)
		);
	}

	// Add database dump to zip.
	if ( ! $zip->addFile( $database_file['filepath'], $database_file['filename'] ) ) {
		$zip->close();
		return new WP_Error( 'zip_db_add_failed', __( 'Failed to add database file to zip archive.', 'enginescript-site-exporter' ) );
	}

	$file_result = sse_add_wordpress_files_to_zip( $zip, $export_paths['export_dir'] );
	if ( is_wp_error( $file_result ) ) {
		$zip->close();
		return $file_result;
	}

	$zip_close_status = $zip->close();

	if ( ! $zip_close_status || ! file_exists( $zip_filepath ) ) {
		return new WP_Error( 'zip_finalize_failed', __( 'Failed to finalize or save the zip archive after processing files.', 'enginescript-site-exporter' ) );
	}

	sse_log( 'Site archive created successfully: ' . $zip_filepath, 'info' );
	return [
		'filename' => $zip_filename,
		'filepath' => $zip_filepath,
	];
}

/**
 * Adds WordPress files to the zip archive.
 *
 * @param ZipArchive $zip         The zip archive object.
 * @param string     $export_dir  The export directory to exclude.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_add_wordpress_files_to_zip( ZipArchive $zip, string $export_dir ) {
	$source_path = realpath( ABSPATH );
	if ( ! $source_path ) {
		sse_log( 'Could not resolve real path for ABSPATH. Using ABSPATH directly.', 'warning' );
		$source_path = ABSPATH;
	}

	try {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $files as $file_info ) {
			sse_process_file_for_zip( $zip, $file_info, $source_path, $export_dir );
		}
	} catch ( Exception $e ) {
		return new WP_Error(
			'file_iteration_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Error during file processing: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	}

	return true;
}

/**
 * Process a single file for addition to ZIP archive.
 *
 * @param ZipArchive  $zip         ZIP archive object.
 * @param SplFileInfo $file_info   File information object.
 * @param string      $source_path Source directory path.
 * @param string      $export_dir  Export directory to exclude.
 * @return true|null True on success, null if skipped.
 */
function sse_process_file_for_zip( ZipArchive $zip, SplFileInfo $file_info, string $source_path, string $export_dir ) {
	if ( ! $file_info->isReadable() ) {
		sse_log( 'Skipping unreadable file/dir: ' . $file_info->getPathname(), 'warning' );
		return null;
	}

	$file          = $file_info->getRealPath();
	$pathname      = $file_info->getPathname();
	$relative_path = ltrim( substr( $pathname, strlen( $source_path ) ), '/' );

	if ( empty( $relative_path ) ) {
		return null;
	}

	if ( sse_should_exclude_file( $pathname, $relative_path, $export_dir, $file_info ) ) {
		return null;
	}

	return sse_add_file_to_zip( $zip, $file_info, $file, $pathname, $relative_path );
}

/**
 * Adds a file or directory to the zip archive.
 *
 * @param ZipArchive   $zip           The zip archive object.
 * @param SplFileInfo  $file_info     File information object.
 * @param string|false $file          Real file path or false if getRealPath() failed.
 * @param string       $pathname      Original pathname.
 * @param string       $relative_path Relative path in archive.
 * @return true
 */
function sse_add_file_to_zip( ZipArchive $zip, SplFileInfo $file_info, $file, string $pathname, string $relative_path ): bool {
	if ( $file_info->isDir() ) {
		if ( ! $zip->addEmptyDir( $relative_path ) ) {
			sse_log( 'Failed to add directory to zip: ' . $relative_path, 'error' );
		}
		return true;
	}

	if ( $file_info->isFile() ) {
		// Use real path (getRealPath() must succeed for security).
		if ( false === $file ) {
			sse_log( 'Skipping file with unresolvable real path: ' . $pathname, 'warning' );
			return true; // Skip this file but continue processing.
		}

		$file_to_add = $file;

		if ( ! $zip->addFile( $file_to_add, $relative_path ) ) {
			sse_log( 'Failed to add file to zip: ' . $relative_path . ' (Source: ' . $file_to_add . ')', 'error' );
		}
	}

	return true;
}

/**
 * Determines if a file should be excluded from the export.
 *
 * @param string      $pathname      The full pathname.
 * @param string      $relative_path The relative path.
 * @param string      $export_dir    The export directory to exclude.
 * @param SplFileInfo $file_info     File information object.
 * @return bool True if file should be excluded.
 */
function sse_should_exclude_file( string $pathname, string $relative_path, string $export_dir, SplFileInfo $file_info ): bool {
	// Exclude export directory.
	if ( strpos( $pathname, $export_dir ) === 0 ) {
		return true;
	}

	// Exclude cache and temporary directories.
	if ( preg_match( '#^wp-content/(cache|upgrade|temp)/#', $relative_path ) ) {
		return true;
	}

	// Exclude version control and system files.
	if ( preg_match( '#(^|/)\.(git|svn|hg|DS_Store|htaccess|user\.ini)$#i', $relative_path ) ) {
		return true;
	}

	// Exclude files based on size.
	if ( $file_info->isFile() ) {
		// Cache the max file size to avoid repeated transient/filter lookups per file.
		static $cached_max_file_size = null;

		/**
		 * Filters the maximum allowed file size for inclusion in the export.
		 *
		 * @since 1.8.5
		 *
		 * @param int $max_file_size Maximum file size in bytes. Default is user's selection or 0 (no limit).
		 */
		$cached_max_file_size ??= (int) apply_filters(
			'sse_max_file_size_for_export',
			get_transient( 'sse_export_max_file_size_' . get_current_user_id() ) ?: 0
		);

		if ( $cached_max_file_size > 0 && $file_info->getSize() > $cached_max_file_size ) {
			sse_log( 'Excluding large file: ' . $pathname . ' (Size: ' . size_format( $file_info->getSize() ) . ', Limit: ' . size_format( $cached_max_file_size ) . ')', 'info' );
			return true;
		}
	}

	return false;
}

/**
 * Shows an error notice to the user.
 *
 * @param string $message The error message to display.
 */
function sse_show_error_notice( string $message ): void {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	);
	sse_log( 'Export error: ' . $message, 'error' );
}

/**
 * Shows a success notice to the user.
 *
 * @param array $zip_result The zip file information.
 */
function sse_show_success_notice( array $zip_result ): void {
	add_action(
		'admin_notices',
		function () use ( $zip_result ) {
			$download_url = add_query_arg(
				[
					'sse_secure_download' => $zip_result['filename'],
					'sse_download_nonce'  => wp_create_nonce( 'sse_secure_download' ),
				],
				admin_url()
			);

			$delete_url = add_query_arg(
				[
					'sse_delete_export' => $zip_result['filename'],
					'sse_delete_nonce'  => wp_create_nonce( 'sse_delete_export' ),
				],
				admin_url()
			);

			$display_zip_path = str_replace( ABSPATH, '[wp-root]/', $zip_result['filepath'] );
			$display_zip_path = preg_replace( '|/+|', '/', $display_zip_path );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php esc_html_e( 'Site export successfully created!', 'enginescript-site-exporter' ); ?>
					<a href="<?php echo esc_url( $download_url ); ?>" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'Download Export File', 'enginescript-site-exporter' ); ?>
					</a>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-secondary" style="margin-left: 10px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this export file?', 'enginescript-site-exporter' ); ?>');">
						<?php esc_html_e( 'Delete Export File', 'enginescript-site-exporter' ); ?>
					</a>
				</p>
				<p><small>
					<?php
					printf(
						/* translators: %s: file path */
						esc_html__( 'File location: %s', 'enginescript-site-exporter' ),
						'<code title="' . esc_attr__( 'Path is relative to WordPress root directory', 'enginescript-site-exporter' ) . '">' .
						esc_html( $display_zip_path ) . '</code>'
					);
					?>
				</small></p>
			</div>
			<?php
		}
	);
	sse_log( 'Export successful. File saved to ' . $zip_result['filepath'], 'info' );
}

/**
 * Cleans up temporary files.
 *
 * @param array $files Array of file paths to delete.
 */
function sse_cleanup_files( array $files ): void {
	foreach ( $files as $file ) {
		if ( file_exists( $file ) ) {
			sse_safely_delete_file( $file );
			sse_log( 'Cleaned up temporary file: ' . $file, 'info' );
		}
	}
}

/**
 * Schedules cleanup of export files.
 *
 * @param string $zip_filepath The zip file path to schedule for deletion.
 */
function sse_schedule_export_cleanup( string $zip_filepath ): void {
	// Check if already scheduled.
	if ( wp_next_scheduled( 'sse_delete_export_file', [ $zip_filepath ] ) ) {
		return;
	}

	$scheduled_time = time() + ( 5 * 60 );
	$result         = wp_schedule_single_event( $scheduled_time, 'sse_delete_export_file', [ $zip_filepath ] );

	if ( false === $result ) {
		sse_log( 'Failed to schedule export file deletion: ' . $zip_filepath, 'error' );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( $cron_disabled ) {
			sse_log( 'DISABLE_WP_CRON is true — cron events will not fire automatically', 'warning' );
		}
	} else {
		sse_log( 'Export file deletion scheduled for ' . gmdate( 'Y-m-d H:i:s', $scheduled_time ) . ' GMT: ' . $zip_filepath, 'info' );
	}
}

/**
 * Schedules a bulk cleanup of all export files in the upload directory.
 * This runs as a safety net to catch any files that individual cleanup missed.
 */
function sse_schedule_bulk_cleanup(): void {
	if ( wp_next_scheduled( 'sse_bulk_cleanup_exports' ) ) {
		return;
	}

	$scheduled_time = time() + ( 10 * 60 );
	$result         = wp_schedule_single_event( $scheduled_time, 'sse_bulk_cleanup_exports' );

	if ( false === $result ) {
		sse_log( 'Failed to schedule bulk export cleanup', 'error' );
	}
}

/**
 * Handles bulk cleanup of all export files older than 5 minutes.
 * This is a safety net to catch any files missed by individual cleanup.
 */
function sse_bulk_cleanup_exports_handler(): void {
	sse_log( 'Bulk export cleanup handler triggered', 'info' );
	
	$upload_dir = wp_upload_dir();
	$export_dir = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	
	if ( ! is_dir( $export_dir ) ) {
		sse_log( 'Export directory does not exist, nothing to clean up', 'info' );
		return;
	}
	
	$dir_contents = scandir( $export_dir ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_scandir -- Scanning controlled export directory
	if ( false === $dir_contents ) {
		sse_log( 'Failed to read export directory', 'error' );
		return;
	}

	$files = [];
	foreach ( $dir_contents as $entry ) {
		if ( '.zip' === substr( $entry, -4 ) ) {
			$files[] = trailingslashit( $export_dir ) . $entry;
		}
	}

	if ( empty( $files ) ) {
		sse_log( 'No export files found in bulk cleanup', 'info' );
		return;
	}
	
	$cleaned_count = 0;
	$cutoff_time   = time() - ( 5 * 60 ); // Files older than 5 minutes.
	
	foreach ( $files as $file_path ) {
		$file_time = filemtime( $file_path );
		
		if ( $file_time && $file_time < $cutoff_time ) {
			// File is older than 5 minutes, validate it's an export file.
			$filename   = basename( $file_path );
			$validation = sse_validate_basic_export_file( $filename );
			
			if ( ! is_wp_error( $validation ) ) {
				if ( sse_safely_delete_file( $file_path ) ) {
					sse_log( 'Bulk cleanup deleted export file: ' . $file_path, 'info' );
					$cleaned_count++;
				} else {
					sse_log( 'Bulk cleanup failed to delete: ' . $file_path, 'error' );
				}
			} else {
				sse_log( 'Bulk cleanup skipped invalid file: ' . $file_path . ' - ' . $validation->get_error_message(), 'warning' );
			}
		}
	}
	
	sse_log( "Bulk cleanup completed. Deleted {$cleaned_count} export files.", 'info' );
}

// --- Scheduled Deletion Handler ---

/**
 * Handles scheduled deletion of export files.
 *
 * @param string $file File path to delete.
 * @return void
 */
function sse_delete_export_file_handler( string $file ): void {
	sse_log( 'Scheduled deletion handler triggered for file: ' . $file, 'info' );
	
	// Validate that this is actually an export file before deletion.
	$filename = basename( $file );

	// Use the same validation as manual deletion for consistency.
	$validation = sse_validate_basic_export_file( $filename );
	if ( is_wp_error( $validation ) ) {
		sse_log( 'Scheduled deletion blocked - invalid file: ' . $file . ' - ' . $validation->get_error_message(), 'warning' );
		return;
	}

	if ( file_exists( $file ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Controlled scheduled deletion validation
		if ( sse_safely_delete_file( $file ) ) {
			sse_log( 'Scheduled deletion successful: ' . $file, 'info' );
			return;
		}
		sse_log( 'Scheduled deletion failed: ' . $file, 'error' );
	} else {
		// Graceful handling: file already gone (likely manually deleted) - not an error.
		sse_log( 'Scheduled deletion skipped - file already removed: ' . $file, 'info' );
	}
}

/**
 * Safely delete a file using WordPress Filesystem API.
 *
 * @param string $filepath Path to the file to delete.
 * @return bool Whether the file was deleted successfully.
 */
function sse_safely_delete_file( string $filepath ): bool {
	if ( is_wp_error( sse_init_filesystem() ) ) {
		return false;
	}

	global $wp_filesystem;

	// Check if the file exists using WP Filesystem.
	if ( $wp_filesystem->exists( $filepath ) ) {
		return $wp_filesystem->delete( $filepath, false, 'f' );
	}

	return false;
}

/**
 * Validates a file path for directory traversal attempts.
 *
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
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
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
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
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

// --- Secure Download Handler ---
/**
 * Handles secure download requests for export files.
 *
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
 * @param array $file_data Validated file information array.
 * @return void
 */
function sse_serve_file_download( array $file_data ): void {
	// Set download headers.
	sse_set_download_headers( $file_data['filename'], $file_data['filesize'] );
	
	// Output file content (includes final security validation before readfile).
	sse_output_file_content( $file_data['filepath'], $file_data['filename'] );
}
