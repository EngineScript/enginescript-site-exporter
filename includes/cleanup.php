<?php
/**
 * File cleanup: scheduled deletion, bulk cleanup, temporary file removal.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Cleans up temporary files.
 *
 * @since 1.0.0
 * @param array $files Array of file paths to delete.
 * @return void
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
 * @since 1.0.0
 * @param string $zip_filepath The zip file path to schedule for deletion.
 * @return void
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
 *
 * @since 2.0.0
 * @return void
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
 *
 * @since 2.0.0
 * @return void
 */
function sse_bulk_cleanup_exports_handler(): void {
	sse_log( 'Bulk export cleanup handler triggered', 'info' );
	
	$upload_dir = wp_upload_dir();
	$export_dir = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	
	if ( ! is_dir( $export_dir ) ) {
		sse_log( 'Export directory does not exist, nothing to clean up', 'info' );
		return;
	}
	
	try {
		$dir_iterator = new DirectoryIterator( $export_dir );
	} catch ( RuntimeException $e ) {
		sse_log( 'Failed to read export directory: ' . $e->getMessage(), 'error' );
		return;
	}

	$files = [];
	foreach ( $dir_iterator as $entry ) {
		if ( $entry->isDot() || ! $entry->isFile() ) {
			continue;
		}
		if ( '.zip' === substr( $entry->getFilename(), -4 ) ) {
			$files[] = $entry->getPathname();
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

/**
 * Handles scheduled deletion of export files.
 *
 * @since 1.0.0
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
 * @since 1.0.0
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
