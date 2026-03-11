<?php
/**
 * Helper utilities: logging, IP detection, execution time, filesystem init.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Safely get client IP address.
 *
 * @since 1.0.0
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
}

/**
 * Stores important log messages in database for review.
 *
 * @since 1.0.0
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
}

/**
 * Outputs log message to WordPress debug log or error_log.
 *
 * @since 1.0.0
 * @param string $formatted_message The formatted log message.
 * @return void
 */
function sse_output_log_message( string $formatted_message ): void {
	// Use WordPress logging (wp_debug_log is available in WP 5.1+).
	if ( function_exists( 'wp_debug_log' ) ) {
		wp_debug_log( $formatted_message );
	}
}

/**
 * Safely log plugin messages
 *
 * @since 1.0.0
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
}

/**
 * Safely get the PHP execution time limit.
 *
 * @since 1.0.0
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
}

/**
 * Initializes the WordPress Filesystem API.
 *
 * Centralizes the repeated WP_Filesystem initialization pattern
 * used across multiple functions.
 *
 * @since 2.0.0
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
