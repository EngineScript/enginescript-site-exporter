<?php
/**
 * EngineScript archive operations: ZIP bundle creation, files archive writing, exclusion logic.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Prevent direct execution of this component.
 *
 * @psalm-suppress ParadoxicalCondition Files may be requested outside the loaded plugin bootstrap.
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Gets the site identifier used in EngineScript archive filenames.
 *
 * @since 2.0.0
 * @return string Sanitized site identifier.
 */
function sse_get_export_site_identifier(): string {
	$site_host = sse_normalize_string_value( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( '' === $site_host ) {
		$site_host = get_bloginfo( 'name' );
	}

	return sse_build_export_site_identifier( $site_host );
}

/**
 * Turns a host name into an identifier that the archive name pattern accepts.
 *
 * An ordinary host is kept as it is, in lower case. An international name is
 * converted to its ASCII form when PHP can do so, and any other character
 * becomes a hyphen, so the result always matches the pattern that download,
 * delete, and cleanup validate against.
 *
 * @since 2.1.1
 * @param string $site_host Host name, or another site label when no host is known.
 * @return string Identifier made of lower-case letters, digits, dots, and hyphens.
 */
function sse_build_export_site_identifier( string $site_host ): string {
	// Newer PHP versions throw on an empty name instead of returning false.
	if ( '' !== $site_host && function_exists( 'idn_to_ascii' ) ) {
		$ascii_host = idn_to_ascii( $site_host );
		$site_host  = is_string( $ascii_host ) && '' !== $ascii_host ? $ascii_host : $site_host;
	}

	$site_identifier = preg_replace( [ '/[^a-z0-9.-]+/', '/\.{2,}/' ], [ '-', '.' ], strtolower( $site_host ) );
	$site_identifier = trim( substr( sse_normalize_string_value( $site_identifier ), 0, 100 ), '.-' );

	return '' === $site_identifier ? 'wordpress-site' : $site_identifier;
}

/**
 * Gets the current EngineScript export timestamp.
 *
 * @since 2.0.0
 * @return string Timestamp formatted to match EngineScript shell exports.
 */
function sse_get_export_timestamp(): string {
	return gmdate( 'Ymd_His' );
}

/**
 * Creates a site archive with database and files.
 *
 * @since 1.0.0
 * @param array  $export_paths     Export directory paths.
 * @param array  $database_file    Database file information.
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp.
 * @psalm-param array{export_dir: string, export_dir_name: string} $export_paths
 * @psalm-param array{filename: string, filepath: string} $database_file
 * @return array{filename: string, filepath: string}|WP_Error Archive info on success, WP_Error on failure.
 */
function sse_create_site_archive( array $export_paths, array $database_file, string $site_identifier, string $timestamp ): array|WP_Error {
	$requirements_result = sse_validate_archive_requirements();
	if ( is_wp_error( $requirements_result ) ) {
		return $requirements_result;
	}

	$bundle_paths = sse_prepare_engine_script_bundle_paths( $export_paths, $site_identifier, $timestamp );
	$setup_result = sse_create_bundle_staging_directories( $bundle_paths );
	if ( is_wp_error( $setup_result ) ) {
		return $setup_result;
	}

	try {
		$archive_result = sse_build_engine_script_bundle( $database_file, $bundle_paths, $site_identifier );
		if ( is_wp_error( $archive_result ) ) {
			return $archive_result;
		}

		sse_log( 'Site archive created successfully: ' . $bundle_paths['combined_zip_path'], 'info' );
		return [
			'filename' => $bundle_paths['combined_zip_filename'],
			'filepath' => $bundle_paths['combined_zip_path'],
		];
	} finally {
		sse_delete_directory_tree( $bundle_paths['staging_dir'] );
	}
}

/**
 * Validates that the server supports all archive formats used by exports.
 *
 * @since 2.0.0
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_archive_requirements(): true|WP_Error {
	$integer_result = sse_validate_export_integer_size( PHP_INT_SIZE );
	if ( is_wp_error( $integer_result ) ) {
		return $integer_result;
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_not_available', __( 'Your server does not provide ZipArchive. Enable the PHP ZIP extension to create an export.', 'enginescript-site-exporter' ) );
	}

	if ( ! function_exists( 'gzopen' ) ) {
		return new WP_Error( 'gzip_not_available', __( 'Your server does not provide gzip support. Enable the PHP zlib extension to create an export.', 'enginescript-site-exporter' ) );
	}

	// The free-space reserve is checked throughout the export, so fail here, not halfway.
	if ( ! function_exists( 'disk_free_space' ) ) {
		return new WP_Error( 'disk_free_space_not_available', __( 'Your server has disabled the PHP disk_free_space() function. Enable it to create an export.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Validates that an integer width can safely represent export byte limits.
 *
 * @since 2.1.1
 * @param int $integer_size Runtime integer width in bytes.
 * @return true|WP_Error True on success, WP_Error when unsupported.
 */
function sse_validate_export_integer_size( int $integer_size ): true|WP_Error {
	if ( $integer_size < 8 ) {
		return new WP_Error( 'unsupported_integer_size', __( 'Exports require a 64-bit PHP runtime.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Builds the staged EngineScript bundle payload.
 *
 * @since 2.0.0
 * @param array  $database_file    Database file information.
 * @param array  $bundle_paths     Bundle paths.
 * @param string $site_identifier Sanitized site identifier.
 * @psalm-param array{filename: string, filepath: string} $database_file
 * @psalm-param array{database_path: string, files_archive_path: string, manifest_path: string, database_gz_filename: string, files_archive_filename: string, combined_zip_path: string, combined_zip_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_build_engine_script_bundle( array $database_file, array $bundle_paths, string $site_identifier ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$database_result = sse_create_compressed_database_file( $database_file['filepath'], $bundle_paths['database_path'] );
	if ( is_wp_error( $database_result ) ) {
		return $database_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$file_result = sse_create_wordpress_files_archive( $bundle_paths['files_archive_path'] );
	if ( is_wp_error( $file_result ) ) {
		return $file_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$manifest_result = sse_write_engine_script_manifest( $bundle_paths, $site_identifier );
	if ( is_wp_error( $manifest_result ) ) {
		return $manifest_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$zip_result = sse_create_combined_engine_script_zip( $bundle_paths );
	if ( is_wp_error( $zip_result ) ) {
		return $zip_result;
	}

	return true;
}

/**
 * Prepares canonical EngineScript bundle paths and filenames.
 *
 * @since 2.0.0
 * @param array  $export_paths     Export directory paths.
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp.
 * @psalm-param array{export_dir: string, export_dir_name: string} $export_paths
 * @return array{staging_dir: string, database_dir: string, files_dir: string, manifest_path: string, database_gz_filename: string, database_path: string, files_archive_filename: string, files_archive_path: string, combined_zip_filename: string, combined_zip_path: string}
 */
function sse_prepare_engine_script_bundle_paths( array $export_paths, string $site_identifier, string $timestamp ): array {
	$staging_dir            = trailingslashit( $export_paths['export_dir'] ) . 'staging-' . $timestamp;
	$bundle_root_dir        = trailingslashit( $staging_dir ) . 'bundle';
	$database_dir           = trailingslashit( $bundle_root_dir ) . 'database';
	$files_dir              = trailingslashit( $bundle_root_dir ) . 'files';
	$database_gz_filename   = "{$site_identifier}_db_{$timestamp}.sql.gz";
	$files_archive_filename = "{$site_identifier}_files_{$timestamp}.tar.gz";
	$combined_zip_filename  = sse_get_engine_script_archive_filename( $site_identifier, $timestamp );

	return [
		'staging_dir'            => $staging_dir,
		'database_dir'           => $database_dir,
		'files_dir'              => $files_dir,
		'manifest_path'          => trailingslashit( $bundle_root_dir ) . 'manifest.txt',
		'database_gz_filename'   => $database_gz_filename,
		'database_path'          => trailingslashit( $database_dir ) . $database_gz_filename,
		'files_archive_filename' => $files_archive_filename,
		'files_archive_path'     => trailingslashit( $files_dir ) . $files_archive_filename,
		'combined_zip_filename'  => $combined_zip_filename,
		'combined_zip_path'      => trailingslashit( $export_paths['export_dir'] ) . $combined_zip_filename,
	];
}

/**
 * Creates bundle staging directories.
 *
 * @since 2.0.0
 * @param array $bundle_paths Bundle paths.
 * @psalm-param array{database_dir: string, files_dir: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_create_bundle_staging_directories( array $bundle_paths ): true|WP_Error {
	if ( wp_mkdir_p( $bundle_paths['database_dir'] ) && wp_mkdir_p( $bundle_paths['files_dir'] ) ) {
		return true;
	}

	return new WP_Error( 'bundle_staging_failed', __( 'Could not create the staging directories for the export.', 'enginescript-site-exporter' ) );
}

/**
 * Gets an exact local generated-file size for preflight accounting.
 *
 * @since 2.1.1
 * @param string $file_path Generated file path.
 * @return int|WP_Error File size or verification error.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_get_generated_file_size( string $file_path ): int|WP_Error {
	clearstatcache( true, $file_path );
	$file_size = @filesize( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize,WordPress.PHP.NoSilencedErrors.Discouraged -- A disappearing generated file must return a bounded error without leaking its private path.
	if ( false === $file_size ) {
		return new WP_Error( 'export_generated_size_unknown', __( 'Could not verify the size of the generated export.', 'enginescript-site-exporter' ) );
	}

	return $file_size;
}

/**
 * Projects a conservative gzip output bound for one completed input.
 *
 * @since 2.1.1
 * @param int $input_bytes Input size.
 * @return int|WP_Error Projected bytes or overflow error.
 */
function sse_get_projected_gzip_bytes( int $input_bytes ): int|WP_Error {
	$overhead = intdiv( max( 0, $input_bytes ), 1000 ) + 65536;
	if ( $input_bytes > PHP_INT_MAX - $overhead ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	return $input_bytes + $overhead;
}

/**
 * Projects the stored outer ZIP size from its completed payload files.
 *
 * @since 2.1.1
 * @param string[] $file_paths Payload paths.
 * @return int|WP_Error Projected bytes or size error.
 */
function sse_get_projected_zip_bytes( array $file_paths ): int|WP_Error {
	$projected_bytes = 1048576;
	foreach ( $file_paths as $file_path ) {
		$file_size = sse_get_generated_file_size( $file_path );
		if ( is_wp_error( $file_size ) ) {
			return $file_size;
		}

		if ( $file_size > PHP_INT_MAX - $projected_bytes ) {
			return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
		}

		$projected_bytes += $file_size;
	}

	return $projected_bytes;
}

/**
 * Gets the TAR bytes that one archive entry adds, guarding against overflow.
 *
 * The count covers the header, the content with its block padding, and the
 * long-name record that precedes an entry whose path exceeds 100 bytes.
 *
 * @since 2.1.1
 * @param int    $source_bytes Source file size, or zero for a directory.
 * @param string $archive_path Relative path stored in the TAR.
 * @return int|WP_Error TAR bytes for the entry, or an overflow error.
 */
function sse_get_projected_tar_entry_bytes( int $source_bytes, string $archive_path ): int|WP_Error {
	$source_bytes = max( 0, $source_bytes );

	// Two headers and two paddings stay below 2,048 bytes; the long-name record adds the path once.
	$metadata_reserve = 2048 + strlen( $archive_path );
	if ( $source_bytes > PHP_INT_MAX - $metadata_reserve ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	return sse_tar_get_entry_bytes( $source_bytes, $archive_path );
}

/**
 * Streams a database dump into gzip while checking live export limits.
 *
 * @since 2.1.1
 * @param resource $source_handle Open SQL input handle.
 * @param resource $target_handle Open gzip output handle.
 * @param string   $target_path   Generated gzip path.
 * @return true|WP_Error True when streaming completes, otherwise an error.
 */
function sse_stream_database_to_gzip( $source_handle, $target_handle, string $target_path ): true|WP_Error {
	while ( ! feof( $source_handle ) ) {
		$budget_check = sse_check_export_resource_budget( dirname( $target_path ) );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		$chunk = fread( $source_handle, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming a large local SQL file.
		if ( false === $chunk || false === gzwrite( $target_handle, $chunk ) || ! fflush( $target_handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Gzip streaming and flush are required for live output accounting.
			return new WP_Error( 'db_compress_write_failed', __( 'Could not compress the database dump.', 'enginescript-site-exporter' ) );
		}

		$budget_check = sse_record_generated_export_file( $target_path );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}
	}

	return true;
}

/**
 * Creates a gzip-compressed copy of the database dump.
 *
 * @since 2.0.0
 * @param string $source_path Source SQL dump path.
 * @param string $target_path Target SQL gzip path.
 * @return true|WP_Error True on success, WP_Error on failure.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_create_compressed_database_file( string $source_path, string $target_path ): true|WP_Error {
	$budget_check = sse_record_generated_export_file( $target_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$source_handle = @fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- A missing private dump returns a bounded error without leaking its path.
	if ( false === $source_handle ) {
		return new WP_Error( 'db_compress_source_failed', __( 'Could not open the database dump for compression.', 'enginescript-site-exporter' ) );
	}

	$target_handle = @gzopen( $target_path, 'wb9' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Gzip creation failures return a bounded error without leaking the private path.
	if ( false === $target_handle ) {
		fclose( $source_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local file handle opened above.
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_target_failed', __( 'Could not create the compressed database file.', 'enginescript-site-exporter' ) );
	}

	$stream_result = sse_stream_database_to_gzip( $source_handle, $target_handle, $target_path );
	fclose( $source_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing local file handle opened above.
	gzclose( $target_handle );
	if ( is_wp_error( $stream_result ) ) {
		sse_cleanup_files( [ $target_path ] );
		return $stream_result;
	}

	if ( ! sse_filesystem_file_has_content( $target_path ) ) {
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_verify_failed', __( 'The compressed database file was not created.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $target_path ) ) {
		sse_cleanup_files( [ $target_path ] );
		return new WP_Error( 'db_compress_permissions_failed', __( 'Could not secure the permissions of the compressed database file.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $target_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $target_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Creates a tar.gz archive of the WordPress files.
 *
 * Every kept file is read once and written straight into the gzip stream, so
 * the work grows with the bytes archived and no uncompressed copy is staged.
 *
 * @since 2.0.0
 * @param string $files_archive_path Target tar.gz path.
 * @return true|WP_Error True on success, WP_Error on failure.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_create_wordpress_files_archive( string $files_archive_path ): true|WP_Error {
	sse_cleanup_files( [ $files_archive_path ] );
	$budget_check = sse_record_generated_export_file( $files_archive_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$archive_handle = @gzopen( $files_archive_path, 'wb6' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gzip creation failures return a bounded error without leaking the private path.
	if ( false === $archive_handle ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return new WP_Error( 'files_archive_verify_failed', __( 'The WordPress files archive was not created.', 'enginescript-site-exporter' ) );
	}

	// The file exists as soon as it is opened, so secure it before any content is written.
	$write_result = sse_chmod_private_file( $files_archive_path )
		? sse_write_wordpress_files_to_tar( $archive_handle, $files_archive_path )
		: new WP_Error( 'files_archive_permissions_failed', __( 'Could not secure the permissions of the files archive.', 'enginescript-site-exporter' ) );
	$closed       = gzclose( $archive_handle );
	if ( is_wp_error( $write_result ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return $write_result;
	}

	if ( ! $closed || ! sse_filesystem_file_has_content( $files_archive_path ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return new WP_Error( 'files_archive_verify_failed', __( 'The WordPress files archive was not created.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $files_archive_path ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return new WP_Error( 'files_archive_permissions_failed', __( 'Could not secure the permissions of the files archive.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $files_archive_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $files_archive_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Writes the EngineScript archive manifest.
 *
 * @since 2.0.0
 * @param array  $bundle_paths     Bundle paths.
 * @param string $site_identifier Sanitized site identifier.
 * @psalm-param array{manifest_path: string, database_gz_filename: string, files_archive_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_write_engine_script_manifest( array $bundle_paths, string $site_identifier ): true|WP_Error {
	$manifest_content = implode(
		"\n",
		[
			'format=enginescript-site-archive',
			'version=1',
			'site=' . $site_identifier,
			'created_at_utc=' . gmdate( 'Y-m-d\TH:i:s\Z' ),
			'database_path=database/' . $bundle_paths['database_gz_filename'],
			'files_archive_path=files/' . $bundle_paths['files_archive_filename'],
		]
	) . "\n";

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return $filesystem;
	}

	// Later keys are informational. The lines above keep their text and order for existing importers.
	$manifest_content .= sse_get_engine_script_manifest_site_lines( $filesystem->exists( ABSPATH . 'wp-config.php' ) );

	if ( ! $filesystem->put_contents( $bundle_paths['manifest_path'], $manifest_content, SSE_PRIVATE_FILE_MODE ) ) {
		return new WP_Error( 'manifest_write_failed', __( 'Could not write the EngineScript export manifest.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $bundle_paths['manifest_path'] ) ) {
		return new WP_Error( 'manifest_permissions_failed', __( 'Could not secure the permissions of the EngineScript export manifest.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $bundle_paths['manifest_path'] );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	return true;
}

/**
 * Builds the informational manifest lines that describe the exported site.
 *
 * @since 2.1.1
 * @param bool $has_wp_config Whether wp-config.php is in the WordPress directory, and so in the files archive.
 * @return string Manifest lines, each ending in a line break.
 */
function sse_get_engine_script_manifest_site_lines( bool $has_wp_config ): string {
	$database = sse_get_wordpress_database();
	$values   = [
		'home_url'             => home_url(),
		'site_url'             => site_url(),
		'table_prefix'         => null === $database ? '' : $database->prefix,
		'wp_config_in_archive' => $has_wp_config ? 'yes' : 'no',
	];

	$lines = '';
	foreach ( $values as $key => $value ) {
		// One value per line: drop anything that could start a new one.
		$lines .= $key . '=' . sse_normalize_string_value( preg_replace( '/[\x00-\x1F\x7F]+/', '', $value ) ) . "\n";
	}

	return $lines;
}

/**
 * Registers and preflights the projected outer ZIP output.
 *
 * @since 2.1.1
 * @param array<string,string> $entries  ZIP entry paths keyed by archive name.
 * @param string               $zip_path Final ZIP path.
 * @return int|WP_Error Projected ZIP bytes or error.
 */
function sse_prepare_combined_zip_output( array $entries, string $zip_path ): int|WP_Error {
	$projected_zip_bytes = sse_get_projected_zip_bytes( array_values( $entries ) );
	if ( is_wp_error( $projected_zip_bytes ) ) {
		return $projected_zip_bytes;
	}

	sse_cleanup_files( [ $zip_path ] );
	$budget_check = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$budget_check = sse_check_generated_export_capacity( $projected_zip_bytes, dirname( $zip_path ) );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$lease_check = sse_renew_current_export_lease( true );
	return is_wp_error( $lease_check ) ? $lease_check : $projected_zip_bytes;
}

/**
 * Queues canonical EngineScript entries without finalizing the ZIP.
 *
 * @since 2.1.1
 * @param ZipArchive           $zip      Open ZIP archive.
 * @param array<string,string> $entries  ZIP entry paths keyed by archive name.
 * @param string               $zip_path Final ZIP path.
 * @return true|WP_Error True when every entry is queued.
 */
function sse_add_combined_zip_entries( ZipArchive $zip, array $entries, string $zip_path ): true|WP_Error {
	if ( ! $zip->addEmptyDir( 'database' ) ) {
		return new WP_Error( 'zip_directory_add_failed', __( 'Could not add the EngineScript directories to the ZIP archive.', 'enginescript-site-exporter' ) );
	}
	if ( ! $zip->addEmptyDir( 'files' ) ) {
		return new WP_Error( 'zip_directory_add_failed', __( 'Could not add the EngineScript directories to the ZIP archive.', 'enginescript-site-exporter' ) );
	}

	foreach ( $entries as $entry_name => $entry_path ) {
		$budget_check = sse_check_export_resource_budget( dirname( $zip_path ) );
		if ( is_wp_error( $budget_check ) ) {
			return $budget_check;
		}

		if ( ! $zip->addFile( $entry_path, $entry_name ) ) {
			return new WP_Error( 'zip_payload_add_failed', __( 'Could not add an EngineScript payload file to the ZIP archive.', 'enginescript-site-exporter' ) );
		}

		if ( ! $zip->setCompressionName( $entry_name, ZipArchive::CM_STORE ) ) {
			return new WP_Error( 'zip_store_mode_failed', __( 'Could not store an EngineScript payload file in the ZIP archive without recompression.', 'enginescript-site-exporter' ) );
		}
	}

	return true;
}

/**
 * Renews ownership and reserves remaining ZIP growth before close().
 *
 * @since 2.1.1
 * @param string $zip_path            Open ZIP path.
 * @param int    $projected_zip_bytes Conservative final ZIP projection.
 * @return true|WP_Error True when close may proceed.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_preflight_combined_zip_close( string $zip_path, int $projected_zip_bytes ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	clearstatcache( true, $zip_path );
	$current_zip_bytes = @filesize( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize,WordPress.PHP.NoSilencedErrors.Discouraged -- Some libzip builds defer creating the output until close; that expected state counts as zero current bytes.
	$current_zip_bytes = false === $current_zip_bytes ? 0 : $current_zip_bytes;
	$remaining_bytes   = max( 0, $projected_zip_bytes - $current_zip_bytes );
	return sse_check_generated_export_capacity( $remaining_bytes, dirname( $zip_path ) );
}

/**
 * Closes, verifies, secures, and records the final ZIP.
 *
 * @since 2.1.1
 * @param ZipArchive $zip      Open ZIP archive.
 * @param string     $zip_path Final ZIP path.
 * @return true|WP_Error True on success, otherwise a finalization error.
 */
function sse_finalize_combined_zip( ZipArchive $zip, string $zip_path ): true|WP_Error {
	$zip_close_status = $zip->close();
	$budget_check     = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return $budget_check;
	}

	if ( ! $zip_close_status || ! sse_filesystem_file_has_content( $zip_path ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return new WP_Error( 'zip_finalize_failed', __( 'Could not finalize or save the ZIP archive.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $zip_path ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return new WP_Error( 'zip_permissions_failed', __( 'Could not secure the permissions of the ZIP archive.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $zip_path );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $zip_path ] );
		return $budget_check;
	}

	return true;
}

/**
 * Creates the outer EngineScript ZIP archive.
 *
 * @since 2.0.0
 * @param array $bundle_paths Bundle paths.
 * @psalm-param array{combined_zip_path: string, manifest_path: string, database_path: string, database_gz_filename: string, files_archive_path: string, files_archive_filename: string, ...} $bundle_paths
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_create_combined_engine_script_zip( array $bundle_paths ): true|WP_Error {
	$entries = [
		'manifest.txt'                                     => $bundle_paths['manifest_path'],
		'database/' . $bundle_paths['database_gz_filename'] => $bundle_paths['database_path'],
		'files/' . $bundle_paths['files_archive_filename'] => $bundle_paths['files_archive_path'],
	];

	$projected_zip_bytes = sse_prepare_combined_zip_output( $entries, $bundle_paths['combined_zip_path'] );
	if ( is_wp_error( $projected_zip_bytes ) ) {
		return $projected_zip_bytes;
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $bundle_paths['combined_zip_path'], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		sse_cleanup_files( [ $bundle_paths['combined_zip_path'] ] );
		return new WP_Error(
			'zip_create_failed',
			sprintf(
				/* translators: %s: ZIP file name. */
				__( 'Could not create the ZIP file %s.', 'enginescript-site-exporter' ),
				wp_basename( $bundle_paths['combined_zip_path'] )
			)
		);
	}

	$entry_result = sse_add_combined_zip_entries( $zip, $entries, $bundle_paths['combined_zip_path'] );
	if ( is_wp_error( $entry_result ) ) {
		sse_discard_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
		return $entry_result;
	}

	$close_preflight = sse_preflight_combined_zip_close( $bundle_paths['combined_zip_path'], $projected_zip_bytes );
	if ( is_wp_error( $close_preflight ) ) {
		sse_discard_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
		return $close_preflight;
	}

	return sse_finalize_combined_zip( $zip, $bundle_paths['combined_zip_path'] );
}

/**
 * Cancels queued ZIP mutations before closing and removing partial output.
 *
 * @since 2.1.1
 * @param ZipArchive $zip      Open ZIP archive.
 * @param string     $zip_path Partial ZIP path.
 * @return void
 */
function sse_discard_combined_zip( ZipArchive $zip, string $zip_path ): void {
	$zip->unchangeAll();
	$zip->close();
	sse_cleanup_files( [ $zip_path ] );
}

/**
 * Deletes a directory tree created during export staging.
 *
 * A symbolic link is removed as a link. Its target is never entered, so a link
 * planted inside an export directory cannot make cleanup delete anything
 * outside it.
 *
 * @since 2.0.0
 * @param string      $directory      Directory to delete.
 * @param string|null $base_directory Export base directory that must contain it; the current one when null.
 * @return bool True if deleted or absent, false on failure.
 */
function sse_delete_directory_tree( string $directory, ?string $base_directory = null ): bool {
	$filesystem = sse_get_filesystem();
	$export_dir = $base_directory ?? sse_get_export_directory_path();
	if ( is_wp_error( $filesystem ) || is_wp_error( $export_dir ) ) {
		return false;
	}

	// The path must name something below the base before anything is looked at or removed.
	$directory = untrailingslashit( wp_normalize_path( $directory ) );
	if ( ! sse_check_path_traversal( $directory ) || ! str_starts_with( $directory, untrailingslashit( wp_normalize_path( $export_dir ) ) . '/' ) ) {
		return false;
	}

	if ( is_link( $directory ) ) {
		return wp_delete_file( $directory );
	}

	clearstatcache( true, $directory );
	if ( ! $filesystem->exists( $directory ) ) {
		return true;
	}

	if ( ! $filesystem->is_dir( $directory ) || ! sse_is_path_within_directory( $directory, $export_dir ) ) {
		return false;
	}

	return sse_delete_directory_contents( $directory, $filesystem ) && $filesystem->rmdir( $directory );
}

/**
 * Deletes everything inside a directory, children before parents, without following links.
 *
 * @since 2.1.1
 * @param string               $directory  Resolved directory whose contents are deleted.
 * @param WP_Filesystem_Direct $filesystem Direct filesystem instance.
 * @return bool True when every entry was removed.
 */
function sse_delete_directory_contents( string $directory, WP_Filesystem_Direct $filesystem ): bool {
	$all_removed = true;

	try {
		// The directory iterator does not descend into a symbolic link unless asked to.
		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof SplFileInfo ) {
				continue;
			}

			$path    = $entry->getPathname();
			$removed = $entry->isDir() && ! $entry->isLink() ? $filesystem->rmdir( $path ) : wp_delete_file( $path );
			if ( ! $removed ) {
				$all_removed = false;
			}
		}
	} catch ( Exception ) {
		return false;
	}

	return $all_removed;
}

/**
 * Walks the WordPress directory and writes each kept entry into the archive.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param string   $archive_path   Generated tar.gz path.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_write_wordpress_files_to_tar( $archive_handle, string $archive_path ): true|WP_Error {
	$source_path = realpath( ABSPATH );
	if ( false === $source_path ) {
		sse_log( 'Could not resolve real path for ABSPATH. Using ABSPATH directly.', 'warning' );
		$source_path = ABSPATH;
	}
	$source_path = untrailingslashit( wp_normalize_path( $source_path ) );

	try {
		// The filter decides both whether an entry is archived and whether a directory
		// is entered, so nothing below a rejected directory is read. A directory that
		// cannot be opened after all is passed over instead of ending the walk.
		$filter = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $source_path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
			static function ( mixed $file_info ) use ( $source_path ): bool {
				return $file_info instanceof SplFileInfo && sse_should_walk_export_source_entry( $file_info, $source_path );
			}
		);
		$files  = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );

		foreach ( $files as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo ) {
				continue;
			}

			$entry_result = sse_write_tar_entry( $archive_handle, $file_info, $source_path, $archive_path );
			if ( is_wp_error( $entry_result ) ) {
				return $entry_result;
			}
		}
	} catch ( Exception $e ) {
		return new WP_Error(
			'file_iteration_failed',
			sprintf(
				/* translators: %s: error message */
				__( 'The files archive could not be completed: %s', 'enginescript-site-exporter' ),
				$e->getMessage()
			)
		);
	}

	if ( ! sse_write_tar_bytes( $archive_handle, sse_tar_get_end_of_archive() ) ) {
		return new WP_Error( 'files_archive_verify_failed', __( 'The WordPress files archive was not created.', 'enginescript-site-exporter' ) );
	}

	return sse_record_generated_export_file( $archive_path );
}

/**
 * Gets an entry's path relative to the export source.
 *
 * @since 2.1.1
 * @param SplFileInfo $file_info   File information object.
 * @param string      $source_path Normalized export source directory, without a trailing slash.
 * @return string Relative path with forward slashes and no leading slash.
 */
function sse_get_export_relative_path( SplFileInfo $file_info, string $source_path ): string {
	return ltrim( substr( wp_normalize_path( $file_info->getPathname() ), strlen( $source_path ) ), '/' );
}

/**
 * Decides whether the walk keeps an entry and, for a directory, enters it.
 *
 * @since 2.1.1
 * @param SplFileInfo $file_info   File information object.
 * @param string      $source_path Normalized export source directory, without a trailing slash.
 * @return bool True to keep the entry, false to skip it and everything below it.
 */
function sse_should_walk_export_source_entry( SplFileInfo $file_info, string $source_path ): bool {
	if ( $file_info->isLink() ) {
		sse_count_skipped_export_entry( 'links' );
		sse_log( 'Skipping symbolic link during export: ' . $file_info->getPathname(), 'warning' );
		return false;
	}

	if ( ! $file_info->isReadable() ) {
		sse_count_skipped_export_entry( 'unreadable' );
		sse_log( 'Skipping unreadable file or directory: ' . $file_info->getPathname(), 'warning' );
		return false;
	}

	return ! sse_should_exclude_file( sse_get_export_relative_path( $file_info, $source_path ) );
}

/**
 * Determines if a path should be excluded from the export.
 *
 * A rule that matches a directory also excludes everything below it, because
 * the walk does not enter a rejected directory.
 *
 * @since 1.0.0
 * @param string $relative_path The relative path of the file or directory.
 * @return bool True if the path should be excluded, false otherwise.
 */
function sse_should_exclude_file( string $relative_path ): bool {
	// Exclude the contents of cache and temporary directories; the directories themselves are kept.
	if ( 1 === preg_match( '#^wp-content/(cache|upgrade|temp)/#', $relative_path ) ) {
		return true;
	}

	// Exclude version control directories and system files.
	return 1 === preg_match( '#(^|/)\.(git|svn|hg|DS_Store|htaccess|user\.ini)$#i', $relative_path );
}

/**
 * Checks a file against the per-file size limit of the current request.
 *
 * @since 2.1.1
 * @param int    $file_size     File size in bytes.
 * @param string $relative_path Relative path in the archive, for the log.
 * @return bool True when the file is larger than the limit and must be skipped.
 */
function sse_is_over_export_file_size_limit( int $file_size, string $relative_path ): bool {
	$max_file_size = sse_get_export_max_file_size();
	if ( $max_file_size <= 0 || $file_size <= $max_file_size ) {
		return false;
	}

	$file_size_label  = sse_normalize_string_value( size_format( $file_size ), (string) $file_size . ' B' );
	$limit_size_label = sse_normalize_string_value( size_format( $max_file_size ), (string) $max_file_size . ' B' );

	sse_count_skipped_export_entry( 'large' );
	sse_log( 'Excluding large file: ' . $relative_path . ' (Size: ' . $file_size_label . ', Limit: ' . $limit_size_label . ')', 'info' );
	return true;
}

/**
 * Writes one walked entry into the archive, or skips it.
 *
 * @since 2.1.1
 * @param resource    $archive_handle Open gzip output handle.
 * @param SplFileInfo $file_info      File information object.
 * @param string      $source_path    Normalized export source directory, without a trailing slash.
 * @param string      $archive_path   Generated tar.gz path.
 * @return true|WP_Error True when written or skipped, WP_Error when the export must stop.
 */
function sse_write_tar_entry( $archive_handle, SplFileInfo $file_info, string $source_path, string $archive_path ): true|WP_Error {
	$relative_path = sse_get_export_relative_path( $file_info, $source_path );
	if ( '' === $relative_path ) {
		return true;
	}

	$real_path = $file_info->getRealPath();
	if ( false === $real_path || ! sse_is_path_within_directory( $real_path, $source_path ) ) {
		sse_log( 'Skipping file outside export source: ' . $file_info->getPathname(), 'warning' );
		return true;
	}

	if ( $file_info->isDir() ) {
		return sse_write_tar_directory_entry( $archive_handle, $file_info, $relative_path . '/', $archive_path );
	}

	// A FIFO, socket, or device is never opened: opening one can block for ever.
	if ( ! $file_info->isFile() ) {
		sse_count_skipped_export_entry( 'special' );
		sse_log( 'Skipping special file during export: ' . $file_info->getPathname(), 'warning' );
		return true;
	}

	return sse_write_tar_file_entry( $archive_handle, wp_normalize_path( $real_path ), $relative_path, $archive_path );
}

/**
 * Counts one entry against the export limits and reserves room for it.
 *
 * Free space is measured on the volume that holds the archive, which is where
 * the bytes are written.
 *
 * @since 2.1.1
 * @param int    $source_bytes Source file size, or zero for a directory.
 * @param string $archive_name Path stored in the TAR, with the trailing slash for a directory.
 * @param string $archive_path Generated tar.gz path.
 * @return true|WP_Error True when the entry fits, otherwise a limit error.
 */
function sse_reserve_tar_entry( int $source_bytes, string $archive_name, string $archive_path ): true|WP_Error {
	$volume_path  = dirname( $archive_path );
	$budget_check = sse_record_export_source_entry( $source_bytes, $volume_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$entry_bytes = sse_get_projected_tar_entry_bytes( $source_bytes, $archive_name );
	if ( is_wp_error( $entry_bytes ) ) {
		return $entry_bytes;
	}

	$gzip_bytes = sse_get_projected_gzip_bytes( $entry_bytes );
	if ( is_wp_error( $gzip_bytes ) ) {
		return $gzip_bytes;
	}

	return sse_check_generated_export_capacity( $gzip_bytes, $volume_path );
}

/**
 * Writes a directory entry.
 *
 * @since 2.1.1
 * @param resource    $archive_handle Open gzip output handle.
 * @param SplFileInfo $file_info      File information object.
 * @param string      $archive_name   Path stored in the TAR, with its trailing slash.
 * @param string      $archive_path   Generated tar.gz path.
 * @return true|WP_Error True when written or skipped, WP_Error when the export must stop.
 */
function sse_write_tar_directory_entry( $archive_handle, SplFileInfo $file_info, string $archive_name, string $archive_path ): true|WP_Error {
	try {
		$mode  = (int) $file_info->getPerms();
		$mtime = (int) $file_info->getMTime();
	} catch ( RuntimeException ) {
		// The directory vanished or became unreadable after it was listed.
		sse_count_skipped_export_entry( 'unreadable' );
		return true;
	}

	$reserve_result = sse_reserve_tar_entry( 0, $archive_name, $archive_path );
	if ( is_wp_error( $reserve_result ) ) {
		return $reserve_result;
	}

	if ( ! sse_write_tar_bytes( $archive_handle, sse_tar_build_entry_header( $archive_name, 0, $mode, $mtime, '5' ) ) ) {
		return sse_get_tar_write_error( $archive_name );
	}

	return true;
}

/**
 * Opens a file and writes it as one archive entry.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param string   $file_path      Resolved source file path.
 * @param string   $relative_path  Relative path in the archive.
 * @param string   $archive_path   Generated tar.gz path.
 * @return true|WP_Error True when written or skipped, WP_Error when the export must stop.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_write_tar_file_entry( $archive_handle, string $file_path, string $relative_path, string $archive_path ): true|WP_Error {
	$source_handle = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- A site file that cannot be opened is skipped and counted; large files must be streamed.
	if ( false === $source_handle ) {
		sse_count_skipped_export_entry( 'unreadable' );
		sse_log( 'Skipping file that could not be opened: ' . $file_path, 'warning' );
		return true;
	}

	try {
		return sse_write_open_file_to_tar( $archive_handle, $source_handle, $relative_path, $archive_path );
	} finally {
		fclose( $source_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the local file handle opened above; WP_Filesystem cannot stream.
	}
}

/**
 * Writes an open file as one archive entry.
 *
 * Type, size, mode, and time are read from the open handle, so they describe
 * the file that is actually streamed, not the one that was listed earlier.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param resource $source_handle  Open source file handle.
 * @param string   $relative_path  Relative path in the archive.
 * @param string   $archive_path   Generated tar.gz path.
 * @return true|WP_Error True when written or skipped, WP_Error when the export must stop.
 */
function sse_write_open_file_to_tar( $archive_handle, $source_handle, string $relative_path, string $archive_path ): true|WP_Error {
	$stat = fstat( $source_handle );
	if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
		sse_count_skipped_export_entry( 'special' );
		sse_log( 'Skipping file that is not a regular file: ' . $relative_path, 'warning' );
		return true;
	}

	if ( sse_is_over_export_file_size_limit( $stat['size'], $relative_path ) ) {
		return true;
	}

	$reserve_result = sse_reserve_tar_entry( $stat['size'], $relative_path, $archive_path );
	if ( is_wp_error( $reserve_result ) ) {
		return $reserve_result;
	}

	if ( ! sse_write_tar_bytes( $archive_handle, sse_tar_build_entry_header( $relative_path, $stat['size'], $stat['mode'], $stat['mtime'], '0' ) ) ) {
		return sse_get_tar_write_error( $relative_path );
	}

	return sse_stream_file_to_tar( $archive_handle, $source_handle, $stat['size'], $relative_path, $archive_path );
}

/**
 * Streams a file's content into the archive while checking live export limits.
 *
 * The entry always holds exactly the size its header declares. A file that
 * ends early is completed with NUL bytes, and one that has grown is cut at
 * the declared size, so a changing file cannot shift the entries after it.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param resource $source_handle  Open source file handle.
 * @param int      $declared_size  Size written in the entry header.
 * @param string   $relative_path  Relative path in the archive.
 * @param string   $archive_path   Generated tar.gz path.
 * @return true|WP_Error True when the entry is complete, WP_Error when the export must stop.
 */
function sse_stream_file_to_tar( $archive_handle, $source_handle, int $declared_size, string $relative_path, string $archive_path ): true|WP_Error {
	$remaining   = $declared_size;
	$since_check = 0;
	while ( $remaining > 0 ) {
		$chunk = fread( $source_handle, min( 1048576, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming a large local file.
		if ( false === $chunk || '' === $chunk ) {
			break;
		}

		if ( ! sse_write_tar_bytes( $archive_handle, $chunk ) ) {
			return sse_get_tar_write_error( $relative_path );
		}

		$remaining   -= strlen( $chunk );
		$since_check += strlen( $chunk );
		if ( $since_check >= 8388608 ) {
			$since_check  = 0;
			$budget_check = sse_check_export_resource_budget( dirname( $archive_path ) );
			if ( is_wp_error( $budget_check ) ) {
				return $budget_check;
			}
		}
	}

	if ( $remaining > 0 ) {
		sse_count_skipped_export_entry( 'changed' );
		sse_log( 'File ended early while it was archived; the rest is stored as zero bytes: ' . $relative_path, 'warning' );
	}

	if ( ! sse_write_tar_zero_bytes( $archive_handle, $remaining ) || ! sse_write_tar_bytes( $archive_handle, sse_tar_get_padding( $declared_size ) ) ) {
		return sse_get_tar_write_error( $relative_path );
	}

	return true;
}

/**
 * Writes bytes to the archive stream.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param string   $bytes          Bytes to write.
 * @return bool True when every byte was accepted.
 */
function sse_write_tar_bytes( $archive_handle, string $bytes ): bool {
	return '' === $bytes || strlen( $bytes ) === gzwrite( $archive_handle, $bytes );
}

/**
 * Writes a run of NUL bytes to the archive stream in bounded pieces.
 *
 * @since 2.1.1
 * @param resource $archive_handle Open gzip output handle.
 * @param int      $count          Number of NUL bytes to write.
 * @return bool True when every byte was accepted.
 */
function sse_write_tar_zero_bytes( $archive_handle, int $count ): bool {
	while ( $count > 0 ) {
		$length = min( 1048576, $count );
		if ( ! sse_write_tar_bytes( $archive_handle, str_repeat( "\0", $length ) ) ) {
			return false;
		}

		$count -= $length;
	}

	return true;
}

/**
 * Builds the error for a failed archive write and records it.
 *
 * @since 2.1.1
 * @param string $relative_path Relative path of the entry that could not be written.
 * @return WP_Error Archive write error.
 */
function sse_get_tar_write_error( string $relative_path ): WP_Error {
	sse_log( 'Failed to add file to TAR archive: ' . $relative_path, 'error' );

	return new WP_Error(
		'file_add_failed',
		sprintf(
			/* translators: %s: file path */
			__( 'Could not add a file to the archive: %s', 'enginescript-site-exporter' ),
			$relative_path
		)
	);
}
