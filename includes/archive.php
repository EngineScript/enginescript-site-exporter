<?php
/**
 * ZIP archive operations: creation, file iteration, exclusion logic.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Creates a site archive with database and files.
 *
 * @since 1.0.0
 * @param array{export_dir: string, export_url: string, export_dir_name: string} $export_paths  Export directory paths.
 * @param array{filename: string, filepath: string} $database_file Database file information.
 * @return array{filename: string, filepath: string}|WP_Error Archive info on success, WP_Error on failure.
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
 * @since 1.0.0
 * @param ZipArchive $zip        The zip archive object.
 * @param string     $export_dir The export directory to exclude.
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
	} catch ( RuntimeException $e ) {
		return new WP_Error(
			'file_iteration_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'Error during file processing: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
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
 * @since 2.0.0
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

	return sse_add_file_to_zip( $zip, $file_info, $file, $pathname, $relative_path ) ? true : null;
}

/**
 * Adds a file or directory to the zip archive.
 *
 * @since 1.0.0
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
 * @since 1.0.0
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
			SSE_FILTER_MAX_FILE_SIZE,
			get_transient( 'sse_export_max_file_size_' . get_current_user_id() ) ?: 0
		);

		if ( $cached_max_file_size > 0 && $file_info->getSize() > $cached_max_file_size ) {
			sse_log( 'Excluding large file: ' . $pathname . ' (Size: ' . size_format( $file_info->getSize() ) . ', Limit: ' . size_format( $cached_max_file_size ) . ')', 'info' );
			return true;
		}
	}

	return false;
}
