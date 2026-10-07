<?php
/**
 * Export workflow: request handling, resource limits, directory setup, database export.
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct execution of this component.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Gets a monotonic timestamp for request-local duration measurements.
 *
 * @since 2.1.1
 * @return float Monotonic seconds.
 */
function sse_get_monotonic_time(): float {
	return hrtime( true ) / 1000000000;
}

/**
 * Gets the per-file size limits offered on the export form.
 *
 * The form markup and the request validation both read this list, so a
 * submitted value is accepted only when the form could have sent it.
 *
 * @since 2.1.1
 * @return array<int,string> Labels keyed by the limit in bytes; zero means no limit.
 */
function sse_get_export_file_size_options(): array {
	return [
		0          => __( 'No per-file limit', 'enginescript-site-exporter' ),
		104857600  => __( '100 MiB', 'enginescript-site-exporter' ),
		524288000  => __( '500 MiB', 'enginescript-site-exporter' ),
		1073741824 => __( '1 GiB', 'enginescript-site-exporter' ),
	];
}

/**
 * Initializes aggregate resource accounting for one export request.
 *
 * The per-file size limit travels with the request from here on, so another
 * submission by the same user cannot change or remove it.
 *
 * @since 2.1.1
 * @param int $selected_max_file_size Per-file size limit selected on the form, in bytes; zero means no limit.
 * @return void
 */
function sse_initialize_export_resource_budget( int $selected_max_file_size = 0 ): void {
	$selected_max_file_size = max( 0, $selected_max_file_size );

	/**
	 * Filters the maximum allowed file size for inclusion in the export.
	 *
	 * @since 1.8.5
	 *
	 * @param int $max_file_size Maximum file size in bytes. Default is user's selection or 0 (no limit).
	 */
	$filtered_max_file_size = sse_normalize_nonnegative_integer( apply_filters( SSE_FILTER_MAX_FILE_SIZE, $selected_max_file_size ) );

	sse_set_export_resource_budget(
		[
			'started_at'      => sse_get_monotonic_time(),
			'entries'         => 0,
			'source_bytes'    => 0,
			'generated_paths' => [],
			'max_file_size'   => false === $filtered_max_file_size ? $selected_max_file_size : $filtered_max_file_size,
			'skipped'         => sse_get_empty_skipped_export_entry_counts(),
		]
	);
}

/**
 * Gets the zeroed counters for entries the files archive leaves out.
 *
 * @since 2.1.1
 * @return array{unreadable:int,links:int,special:int,large:int,changed:int} Counters keyed by reason.
 */
function sse_get_empty_skipped_export_entry_counts(): array {
	return [
		'unreadable' => 0,
		'links'      => 0,
		'special'    => 0,
		'large'      => 0,
		'changed'    => 0,
	];
}

/**
 * Narrows the stored skipped-entry counters to their canonical shape.
 *
 * @since 2.1.1
 * @param mixed $skipped Untrusted request-local value.
 * @return array{unreadable:int,links:int,special:int,large:int,changed:int}|null Canonical counters, or null.
 */
function sse_normalize_skipped_export_entry_counts( mixed $skipped ): ?array {
	if ( ! is_array( $skipped ) ) {
		return null;
	}

	$counts = sse_get_empty_skipped_export_entry_counts();
	foreach ( array_keys( $counts ) as $reason ) {
		$count = sse_normalize_nonnegative_integer( $skipped[ $reason ] ?? null );
		if ( false === $count ) {
			return null;
		}

		$counts[ $reason ] = $count;
	}

	return $counts;
}

/**
 * Counts one entry that the files archive leaves out or stores incomplete.
 *
 * @since 2.1.1
 * @param string $reason Counter to raise.
 * @psalm-param 'unreadable'|'links'|'special'|'large'|'changed' $reason
 * @return void
 */
function sse_count_skipped_export_entry( string $reason ): void {
	$budget = sse_get_export_resource_budget();
	if ( null === $budget ) {
		return;
	}

	++$budget['skipped'][ $reason ];
	sse_set_export_resource_budget( $budget );
}

/**
 * Gets the counters for entries the files archive left out so far.
 *
 * @since 2.1.1
 * @return array{unreadable:int,links:int,special:int,large:int,changed:int} Counters keyed by reason.
 */
function sse_get_skipped_export_entry_counts(): array {
	$budget = sse_get_export_resource_budget();

	return null === $budget ? sse_get_empty_skipped_export_entry_counts() : $budget['skipped'];
}

/**
 * Gets the per-file size limit that applies to the current export request.
 *
 * @since 2.1.1
 * @return int Limit in bytes; zero means no limit.
 */
function sse_get_export_max_file_size(): int {
	$budget = sse_get_export_resource_budget();

	return null === $budget ? 0 : $budget['max_file_size'];
}

/**
 * Clears request-local aggregate resource accounting.
 *
 * @since 2.1.1
 * @return void
 */
function sse_clear_export_resource_budget(): void {
	unset( $GLOBALS['sse_export_resource_budget'] );
}

/**
 * Narrows request-local export resource accounting to its canonical shape.
 *
 * @since 2.1.1
 * @param mixed $budget Untrusted request-local global value.
 * @return array{started_at:float,entries:int,source_bytes:int,generated_paths:array<string,true>,max_file_size:int,skipped:array{unreadable:int,links:int,special:int,large:int,changed:int}}|null Canonical budget, or null.
 */
function sse_normalize_export_resource_budget( mixed $budget ): ?array {
	if (
		! is_array( $budget )
		|| ! isset( $budget['started_at'], $budget['entries'], $budget['source_bytes'], $budget['generated_paths'], $budget['max_file_size'], $budget['skipped'] )
		|| ! is_array( $budget['generated_paths'] )
		|| ( ! is_int( $budget['started_at'] ) && ! is_float( $budget['started_at'] ) )
	) {
		return null;
	}

	$started_at   = (float) $budget['started_at'];
	$entries      = sse_normalize_nonnegative_integer( $budget['entries'] );
	$source_bytes = sse_normalize_nonnegative_integer( $budget['source_bytes'] );
	if ( ! is_finite( $started_at ) || $started_at < 0 || false === $entries || false === $source_bytes ) {
		return null;
	}

	$max_file_size = sse_normalize_nonnegative_integer( $budget['max_file_size'] );
	$skipped       = sse_normalize_skipped_export_entry_counts( $budget['skipped'] );
	if ( false === $max_file_size || null === $skipped ) {
		return null;
	}

	return [
		'started_at'      => $started_at,
		'entries'         => $entries,
		'source_bytes'    => $source_bytes,
		'generated_paths' => sse_normalize_generated_export_paths( $budget['generated_paths'] ),
		'max_file_size'   => $max_file_size,
		'skipped'         => $skipped,
	];
}

/**
 * Narrows the tracked generated paths to their canonical shape.
 *
 * @since 2.1.1
 * @param array<array-key,mixed> $paths Untrusted request-local value.
 * @return array<string,true> Tracked paths.
 */
function sse_normalize_generated_export_paths( array $paths ): array {
	$generated_paths = [];
	foreach ( array_keys( $paths ) as $path ) {
		if ( is_string( $path ) && true === $paths[ $path ] ) {
			$generated_paths[ $path ] = true;
		}
	}

	return $generated_paths;
}

/**
 * Gets canonical request-local export resource accounting.
 *
 * @since 2.1.1
 * @return array{started_at:float,entries:int,source_bytes:int,generated_paths:array<string,true>,max_file_size:int,skipped:array{unreadable:int,links:int,special:int,large:int,changed:int}}|null Canonical budget, or null.
 */
function sse_get_export_resource_budget(): ?array {
	return sse_normalize_export_resource_budget( $GLOBALS['sse_export_resource_budget'] ?? null );
}

/**
 * Stores canonical request-local export resource accounting.
 *
 * @since 2.1.1
 * @param array $budget Canonical export resource budget.
 * @psalm-param array{started_at:float,entries:int,source_bytes:int,generated_paths:array<string,true>,max_file_size:int,skipped:array{unreadable:int,links:int,special:int,large:int,changed:int}} $budget
 * @return void
 */
function sse_set_export_resource_budget( array $budget ): void {
	$GLOBALS['sse_export_resource_budget'] = $budget;
}

/**
 * Gets a positive integer export limit after applying a filter.
 *
 * @since 2.1.1
 * @param non-empty-string $filter_name   Filter name.
 * @param int              $default_value Default limit.
 * @return int Positive configured limit.
 */
function sse_get_export_resource_limit( string $filter_name, int $default_value ): int {
	$value = sse_normalize_nonnegative_integer( apply_filters( $filter_name, $default_value ) );
	return false !== $value && $value > 0 ? $value : $default_value;
}

/**
 * Aligns PHP's request timer with the bounded overall export policy.
 *
 * The limit is never made unlimited or raised above the plugin's conservative
 * 30-minute default. Hosts that prohibit runtime changes retain their lower
 * limit and the owner-bound recovery event handles an interrupted request.
 *
 * @since 2.1.1
 * @return void
 */
function sse_raise_export_execution_time_limit(): void {
	if ( ! function_exists( 'set_time_limit' ) ) {
		return;
	}

	$current_limit = sse_get_execution_time_limit();
	$target_limit  = min( SSE_DEFAULT_MAX_EXPORT_SECONDS, sse_get_export_resource_limit( 'sse_max_export_seconds', SSE_DEFAULT_MAX_EXPORT_SECONDS ) );
	if ( 0 === $current_limit || $current_limit >= $target_limit ) {
		return;
	}

	set_time_limit( $target_limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Finite request timer capped by export policy; monotonic budgets and owner-bound recovery remain enforced.
}

/**
 * Gets the remaining monotonic overall work budget.
 *
 * @since 2.1.1
 * @return float|WP_Error Positive remaining seconds, or a limit error.
 */
function sse_get_remaining_export_seconds(): float|WP_Error {
	$budget = sse_get_export_resource_budget();
	if ( null === $budget ) {
		return new WP_Error( 'export_budget_uninitialized', __( 'The resource accounting of the export was not initialized.', 'enginescript-site-exporter' ) );
	}

	$max_seconds = sse_get_export_resource_limit( 'sse_max_export_seconds', SSE_DEFAULT_MAX_EXPORT_SECONDS );
	$remaining   = (float) $max_seconds - ( sse_get_monotonic_time() - $budget['started_at'] );
	if ( $remaining <= 0 ) {
		return new WP_Error( 'export_time_limit', __( 'The export exceeded its configured processing time limit.', 'enginescript-site-exporter' ) );
	}

	return $remaining;
}

/**
 * Totals the current sizes of every tracked generated path.
 *
 * @since 2.1.1
 * @param array $budget Canonical resource budget.
 * @psalm-param array{generated_paths:array<string,true>,...} $budget
 * @return int|WP_Error Generated bytes or a size-limit error on overflow.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_get_generated_export_bytes( array $budget ): int|WP_Error {
	$generated_bytes = 0;
	foreach ( array_keys( $budget['generated_paths'] ) as $generated_path ) {
		clearstatcache( true, $generated_path );
		$file_size = @filesize( $generated_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Tracked outputs may not exist yet or may be removed between live checks.
		if ( false === $file_size ) {
			continue;
		}

		if ( $file_size > PHP_INT_MAX - $generated_bytes ) {
			return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
		}

		$generated_bytes += $file_size;
	}

	return $generated_bytes;
}

/**
 * Checks elapsed time, lease liveness, and reserved free disk space.
 *
 * @since 2.1.1
 * @param string $path             Filesystem path whose volume should be checked.
 * @param int    $additional_bytes Additional output bytes that must fit safely.
 * @return true|WP_Error True within limits, otherwise an error.
 */
function sse_check_export_resource_budget( string $path, int $additional_bytes = 0 ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease();
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}

	$remaining = sse_get_remaining_export_seconds();
	if ( is_wp_error( $remaining ) ) {
		return $remaining;
	}

	// Deactivating the plugin removes the working directory of an export that is still running.
	clearstatcache( true, $path );
	if ( ! is_dir( $path ) ) {
		return new WP_Error( 'export_directory_missing', __( 'The export stopped because its working directory was removed.', 'enginescript-site-exporter' ) );
	}

	$free_bytes = disk_free_space( $path );
	$minimum    = sse_get_export_resource_limit( 'sse_min_export_free_disk_bytes', SSE_DEFAULT_MIN_FREE_DISK_BYTES );
	if ( false === $free_bytes || $free_bytes < (float) $minimum || (float) max( 0, $additional_bytes ) > $free_bytes - (float) $minimum ) {
		return new WP_Error( 'export_disk_limit', __( 'The export stopped because the filesystem does not have enough free space.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Checks current and projected generated bytes before a blocking write.
 *
 * @since 2.1.1
 * @param int    $additional_bytes Projected additional output bytes.
 * @param string $volume_path      Path used for the free-space check.
 * @return true|WP_Error True when projected output fits.
 */
function sse_check_generated_export_capacity( int $additional_bytes, string $volume_path ): true|WP_Error {
	$budget = sse_get_export_resource_budget();
	if ( null === $budget ) {
		return new WP_Error( 'export_budget_uninitialized', __( 'The resource accounting of the export was not initialized.', 'enginescript-site-exporter' ) );
	}

	$generated_bytes = sse_get_generated_export_bytes( $budget );
	if ( is_wp_error( $generated_bytes ) ) {
		return $generated_bytes;
	}

	$additional_bytes = max( 0, $additional_bytes );
	$maximum_bytes    = sse_get_export_resource_limit( 'sse_max_export_generated_bytes', SSE_DEFAULT_MAX_EXPORT_GENERATED_BYTES );
	if ( $generated_bytes > $maximum_bytes || $additional_bytes > $maximum_bytes - $generated_bytes ) {
		return new WP_Error( 'export_generated_size_limit', __( 'The generated export exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	return sse_check_export_resource_budget( $volume_path, $additional_bytes );
}

/**
 * Records one source archive entry against aggregate limits.
 *
 * @since 2.1.1
 * @param int    $source_bytes Source file size, or zero for a directory.
 * @param string $volume_path  Path used for the free-space check.
 * @return true|WP_Error True within limits, otherwise an error.
 */
function sse_record_export_source_entry( int $source_bytes, string $volume_path ): true|WP_Error {
	$budget = sse_get_export_resource_budget();
	if ( null === $budget ) {
		return new WP_Error( 'export_budget_uninitialized', __( 'The resource accounting of the export was not initialized.', 'enginescript-site-exporter' ) );
	}

	$source_bytes = max( 0, $source_bytes );
	$max_entries  = sse_get_export_resource_limit( 'sse_max_export_entries', SSE_DEFAULT_MAX_EXPORT_ENTRIES );
	if ( $budget['entries'] >= $max_entries ) {
		return new WP_Error( 'export_entry_limit', __( 'The export contains more files and directories than the configured limit allows.', 'enginescript-site-exporter' ) );
	}

	$max_source_bytes = sse_get_export_resource_limit( 'sse_max_export_source_bytes', SSE_DEFAULT_MAX_EXPORT_SOURCE_BYTES );
	if ( $budget['source_bytes'] > $max_source_bytes || $source_bytes > $max_source_bytes - $budget['source_bytes'] ) {
		return new WP_Error( 'export_source_size_limit', __( 'The export source exceeds the configured aggregate size limit.', 'enginescript-site-exporter' ) );
	}

	$budget['entries']      += 1;
	$budget['source_bytes'] += $source_bytes;
	sse_set_export_resource_budget( $budget );

	return sse_check_export_resource_budget( $volume_path );
}

/**
 * Records a generated file against the aggregate generated-byte limit.
 *
 * @since 2.1.1
 * @param string $file_path Generated file path.
 * @return true|WP_Error True within limits, otherwise an error.
 */
function sse_record_generated_export_file( string $file_path ): true|WP_Error {
	$budget = sse_get_export_resource_budget();
	if ( null === $budget ) {
		return new WP_Error( 'export_budget_uninitialized', __( 'The resource accounting of the export was not initialized.', 'enginescript-site-exporter' ) );
	}

	$budget['generated_paths'][ $file_path ] = true;
	sse_set_export_resource_budget( $budget );

	return sse_check_generated_export_capacity( 0, dirname( $file_path ) );
}

/**
 * Handles the site export process when the form is submitted.
 *
 * @since 1.0.0
 * @return void
 */
function sse_handle_export(): void {
	$selected_max_file_size = sse_validate_export_request();
	if ( false === $selected_max_file_size ) {
		sse_redirect_to_exporter_page();
	}

	$lease = sse_acquire_export_lease();
	if ( is_wp_error( $lease ) ) {
		sse_show_error_notice( $lease->get_error_message() );
		sse_redirect_to_exporter_page();
	}

	sse_set_current_export_lease( $lease );
	$recovery_schedule = sse_schedule_export_recovery( $lease );
	if ( is_wp_error( $recovery_schedule ) ) {
		sse_release_export_lease( $lease );
		sse_clear_current_export_lease();
		sse_show_error_notice( $recovery_schedule->get_error_message() );
		sse_redirect_to_exporter_page();
	}

	sse_initialize_export_resource_budget( $selected_max_file_size );
	sse_cleanup_stale_export_directories();

	$previous_umask = umask( 0077 );
	$export_paths   = null;
	$export_success = false;

	try {
		$zip_result = sse_execute_export_workflow( $lease, $export_paths );
		if ( is_wp_error( $zip_result ) ) {
			sse_show_error_notice( $zip_result->get_error_message() );
		} else {
			sse_show_success_notice( $zip_result );
			$export_success = true;
		}
	} finally {
		if ( is_array( $export_paths ) && ! $export_success ) {
			sse_delete_directory_tree( $export_paths['export_dir'] );
		}

		umask( $previous_umask );
		// Always release the owned lease and clear request-local state.
		$current_lease = sse_get_current_export_lease();
		if ( null !== $current_lease && ! sse_release_export_lease( $current_lease ) ) {
			sse_log( 'The owned export lease was not present during final release.', 'warning' );
		}
		sse_clear_current_export_lease();
		sse_clear_export_resource_budget();
	}

	sse_redirect_to_exporter_page();
}

/**
 * Executes the export pipeline after request authorization and lease acquisition.
 *
 * @since 2.1.1
 * @param array      $lease        Owned export lease, updated with the private directory.
 * @param array|null $export_paths Export paths populated after directory setup.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @psalm-param array{export_dir:string,export_dir_name:string}|null $export_paths
 * @return array{filename:string,filepath:string}|WP_Error Final archive data on success.
 */
function sse_execute_export_workflow( array &$lease, ?array &$export_paths ): array|WP_Error {
	$directory_result = sse_prepare_export_workflow_directory( $lease );
	if ( is_wp_error( $directory_result ) ) {
		return $directory_result;
	}

	$export_paths = $directory_result;
	return sse_build_export_workflow_archive( $export_paths );
}

/**
 * Verifies prerequisites and creates the lease-bound private export directory.
 *
 * @since 2.1.1
 * @param array $lease Owned export lease, updated with the private directory.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return array{export_dir:string,export_dir_name:string}|WP_Error Export paths or error.
 */
function sse_prepare_export_workflow_directory( array &$lease ): array|WP_Error {
	$requirements_result = sse_validate_archive_requirements();
	if ( is_wp_error( $requirements_result ) ) {
		return $requirements_result;
	}
	sse_raise_export_execution_time_limit();

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		return $lease_check;
	}
	$current_lease = sse_get_current_export_lease();
	if ( null === $current_lease ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}
	$lease = $current_lease;

	$max_exec_time = sse_get_execution_time_limit();
	if ( $max_exec_time > 0 && $max_exec_time < SSE_DEFAULT_MAX_EXPORT_SECONDS ) {
		sse_log( "Current execution time limit ({$max_exec_time}s) may be insufficient for large exports. Consider increasing server limits.", 'warning' );
	}

	$directory_result = sse_setup_export_directories();
	if ( is_wp_error( $directory_result ) ) {
		return $directory_result;
	}

	$lease_update = sse_update_export_lease_directory( $lease, $directory_result['export_dir_name'] );
	if ( is_wp_error( $lease_update ) ) {
		sse_delete_directory_tree( $directory_result['export_dir'] );
		return $lease_update;
	}
	$lease = $lease_update;

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		sse_delete_directory_tree( $directory_result['export_dir'] );
		return $lease_check;
	}

	$budget_check = sse_check_export_resource_budget( $directory_result['export_dir'] );
	if ( is_wp_error( $budget_check ) ) {
		sse_delete_directory_tree( $directory_result['export_dir'] );
		return $budget_check;
	}

	return $directory_result;
}

/**
 * Builds and schedules cleanup for the final export archive.
 *
 * @since 2.1.1
 * @param array $export_paths Private export paths.
 * @psalm-param array{export_dir:string,export_dir_name:string} $export_paths
 * @return array{filename:string,filepath:string}|WP_Error Final archive data or error.
 */
function sse_build_export_workflow_archive( array $export_paths ): array|WP_Error {

	$site_identifier = sse_get_export_site_identifier();
	$timestamp       = sse_get_export_timestamp();
	$database_file   = sse_export_database( $export_paths['export_dir'], $site_identifier, $timestamp );
	if ( is_wp_error( $database_file ) ) {
		return $database_file;
	}

	$database_check = sse_validate_exported_database_file( $database_file );
	if ( is_wp_error( $database_check ) ) {
		return $database_check;
	}

	$zip_result = sse_create_site_archive( $export_paths, $database_file, $site_identifier, $timestamp );
	sse_cleanup_files( [ $database_file['filepath'] ] );
	if ( is_wp_error( $zip_result ) ) {
		return $zip_result;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		sse_cleanup_files( [ $zip_result['filepath'] ] );
		return $lease_check;
	}

	sse_schedule_export_cleanup( $zip_result['filepath'] );
	sse_schedule_bulk_cleanup();

	return $zip_result;
}

/**
 * Records the completed database dump between forced lease checks.
 *
 * @since 2.1.1
 * @param array{filename:string,filepath:string} $database_file Database dump data.
 * @return true|WP_Error True when the dump remains within the live budget.
 */
function sse_validate_exported_database_file( array $database_file ): true|WP_Error {
	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		sse_cleanup_files( [ $database_file['filepath'] ] );
		return $lease_check;
	}

	$budget_check = sse_record_generated_export_file( $database_file['filepath'] );
	if ( is_wp_error( $budget_check ) ) {
		sse_cleanup_files( [ $database_file['filepath'] ] );
		return $budget_check;
	}

	$lease_check = sse_renew_current_export_lease( true );
	if ( is_wp_error( $lease_check ) ) {
		sse_cleanup_files( [ $database_file['filepath'] ] );
		return $lease_check;
	}

	return true;
}

/**
 * Validates the export request for security and permissions.
 *
 * @since 1.0.0
 * @return int|false Selected per-file size limit in bytes when the request is valid, false otherwise.
 */
function sse_validate_export_request(): int|false {
	$post_action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
	if ( 'sse_export_site' !== $post_action ) {
		return false;
	}

	check_admin_referer( 'sse_export_action', 'sse_export_nonce' );

	if ( ! sse_current_user_can_export_site() ) {
		sse_wp_die( __( 'You do not have permission to perform this action.', 'enginescript-site-exporter' ), 403 );
	}

	// Accept only a size that the form offers; anything else means no limit.
	$requested_size = isset( $_POST['sse_max_file_size'] ) && is_string( $_POST['sse_max_file_size'] ) ? sanitize_text_field( wp_unslash( $_POST['sse_max_file_size'] ) ) : '';
	foreach ( array_keys( sse_get_export_file_size_options() ) as $offered_size ) {
		if ( (string) $offered_size === $requested_size ) {
			return $offered_size;
		}
	}

	return 0;
}

/**
 * Gets warnings about conditions that EngineScript's importer does not accept.
 *
 * The importer finds the site through a wp-config.php inside the files archive
 * and reads the site address from WP_HOME or WP_SITEURL in that file. An
 * export from a site that does not meet this is still a complete backup, so
 * these are warnings and never stop the export.
 *
 * @since 2.1.1
 * @return string[] Warning messages; empty when the archive can be imported.
 */
function sse_get_import_requirement_warnings(): array {
	$warnings   = [];
	$filesystem = sse_get_filesystem();
	if ( ! is_wp_error( $filesystem ) && ! $filesystem->exists( ABSPATH . 'wp-config.php' ) ) {
		$warnings[] = __( 'This site keeps wp-config.php outside the WordPress directory, so the file is not part of the export. The EngineScript importer needs wp-config.php in the export.', 'enginescript-site-exporter' );
	}

	if ( ! defined( 'WP_HOME' ) && ! defined( 'WP_SITEURL' ) ) {
		$warnings[] = __( 'Neither WP_HOME nor WP_SITEURL is defined in wp-config.php. The EngineScript importer reads the site address from one of them.', 'enginescript-site-exporter' );
	}

	return $warnings;
}

/**
 * Checks whether a path lies inside a directory that the web server may serve.
 *
 * The WordPress directory is not the only such place: the content directory
 * and the uploads directory can live outside it, and the document root can be
 * above it. Paths are compared after symbolic links are resolved.
 *
 * @since 2.1.1
 * @param string $path Path to check.
 * @return bool True when the path resolves inside a web-served directory.
 */
function sse_is_path_web_served( string $path ): bool {
	$upload_dir = wp_get_upload_dir();
	$server     = sse_normalize_array_value( $_SERVER );
	$roots      = [
		ABSPATH,
		defined( 'WP_CONTENT_DIR' ) ? sse_normalize_string_value( constant( 'WP_CONTENT_DIR' ) ) : '',
		sse_normalize_string_value( $upload_dir['basedir'] ),
		sanitize_text_field( sse_normalize_string_value( $server['DOCUMENT_ROOT'] ?? '' ) ),
	];

	foreach ( $roots as $root ) {
		// An empty value, or the filesystem root, says nothing about what is served.
		if ( '' === untrailingslashit( wp_normalize_path( $root ) ) ) {
			continue;
		}

		if ( sse_is_path_within_directory( $path, $root ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Sets up export directories and returns path information.
 *
 * @since 1.0.0
 * @return array{export_dir: string, export_dir_name: string}|WP_Error Array of paths on success, WP_Error on failure.
 */
function sse_setup_export_directories(): array|WP_Error {
	$export_base_dir = sse_get_export_directory_path();
	if ( is_wp_error( $export_base_dir ) ) {
		return $export_base_dir;
	}

	if ( sse_is_path_web_served( dirname( $export_base_dir ) ) ) {
		sse_log( 'Private export base directory parent resolved inside a web-served directory: ' . $export_base_dir, 'security' );
		return new WP_Error( 'export_dir_public', __( 'The temporary directory is inside a web-served directory, so an export cannot be stored there safely. Define WP_TEMP_DIR in wp-config.php as a private, writable directory outside the web root, then try again.', 'enginescript-site-exporter' ) );
	}

	$base_dir_result = sse_prepare_export_base_directory( $export_base_dir );
	if ( is_wp_error( $base_dir_result ) ) {
		return $base_dir_result;
	}

	if ( sse_is_path_web_served( $export_base_dir ) ) {
		sse_log( 'Private export base directory resolved inside a web-served directory: ' . $export_base_dir, 'security' );
		return new WP_Error( 'export_dir_public', __( 'The export directory is inside a web-served directory. Define WP_TEMP_DIR in wp-config.php as a private, writable directory outside the web root, then try again.', 'enginescript-site-exporter' ) );
	}

	$export_dir = sse_create_private_export_directory( $export_base_dir );
	if ( is_wp_error( $export_dir ) ) {
		return $export_dir;
	}

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		sse_delete_directory_tree( $export_dir );
		return $filesystem;
	}

	if ( ! $filesystem->is_writable( $export_dir ) ) {
		sse_log( 'Export directory is not writable: ' . $export_dir, 'error' );
		sse_delete_directory_tree( $export_dir );
		return new WP_Error( 'export_dir_not_writable', __( 'The export directory is not writable. Please adjust filesystem permissions.', 'enginescript-site-exporter' ) );
	}

	sse_create_index_file( $export_base_dir );

	return [
		'export_dir'      => $export_dir,
		'export_dir_name' => wp_basename( $export_dir ),
	];
}

/**
 * Creates or verifies the private export base directory.
 *
 * @since 2.1.1
 * @param string $export_base_dir Export base directory path.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_prepare_export_base_directory( string $export_base_dir ): true|WP_Error {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return $filesystem;
	}

	if ( is_link( $export_base_dir ) ) {
		sse_log( 'Rejected symlinked export base directory: ' . $export_base_dir, 'security' );
		return new WP_Error( 'export_dir_symlink', __( 'The export directory is a symbolic link and cannot be used safely.', 'enginescript-site-exporter' ) );
	}

	if ( ! $filesystem->exists( $export_base_dir ) ) {
		if ( ! $filesystem->mkdir( $export_base_dir, SSE_PRIVATE_DIR_MODE ) ) {
			sse_log( 'Failed to create export base directory at path: ' . $export_base_dir, 'error' );
			return new WP_Error( 'export_dir_creation_failed', __( 'Could not create the export directory. Please verify filesystem permissions.', 'enginescript-site-exporter' ) );
		}
	}

	clearstatcache( true, $export_base_dir );

	if ( ! $filesystem->is_dir( $export_base_dir ) || is_link( $export_base_dir ) ) {
		sse_log( 'Rejected unsafe export base directory: ' . $export_base_dir, 'security' );
		return new WP_Error( 'export_dir_unsafe', __( 'The export directory exists but is not a safe private directory.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_directory( $export_base_dir ) ) {
		sse_log( 'Failed to enforce private permissions on export base directory: ' . $export_base_dir, 'security' );
		return new WP_Error( 'export_dir_permissions_failed', __( 'Could not secure the export directory permissions.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Creates a random private directory for a single export.
 *
 * @since 2.1.1
 * @param string $export_base_dir Private export base directory.
 * @return string|WP_Error Private per-export directory path on success, WP_Error on failure.
 */
function sse_create_private_export_directory( string $export_base_dir ): string|WP_Error {
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return $filesystem;
	}

	for ( $attempt = 0; $attempt < 10; ++$attempt ) {
		$export_dir_name = sse_generate_private_export_directory_name();
		$export_dir      = trailingslashit( $export_base_dir ) . $export_dir_name;

		if ( $filesystem->exists( $export_dir ) || is_link( $export_dir ) ) {
			sse_log( 'Rejected pre-existing private export directory candidate: ' . $export_dir, 'security' );
			continue;
		}

		if ( ! $filesystem->mkdir( $export_dir, SSE_PRIVATE_DIR_MODE ) ) {
			continue;
		}

		clearstatcache( true, $export_dir );

		if ( ! $filesystem->is_dir( $export_dir ) || is_link( $export_dir ) || ! sse_is_path_within_directory( $export_dir, $export_base_dir ) ) {
			sse_log( 'Rejected unsafe private export directory after creation: ' . $export_dir, 'security' );
			if ( $filesystem->is_dir( $export_dir ) && ! is_link( $export_dir ) ) {
				$filesystem->delete( $export_dir, false, 'd' );
			}
			return new WP_Error( 'private_export_dir_unsafe', __( 'Could not create a safe private export directory.', 'enginescript-site-exporter' ) );
		}

		if ( ! sse_chmod_private_directory( $export_dir ) ) {
			sse_log( 'Failed to enforce private permissions on export directory: ' . $export_dir, 'security' );
			$filesystem->delete( $export_dir, false, 'd' );
			return new WP_Error( 'private_export_dir_permissions_failed', __( 'Could not secure the private export directory permissions.', 'enginescript-site-exporter' ) );
		}

		return $export_dir;
	}

	return new WP_Error( 'private_export_dir_creation_failed', __( 'Could not create a private export directory. Please verify filesystem permissions.', 'enginescript-site-exporter' ) );
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
	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return;
	}

	if ( ! $filesystem->is_writable( $export_dir ) ) {
		sse_log( 'Failed to write protection files or directory not writable: ' . $export_dir, 'error' );
		return;
	}

	// Create index.php to prevent directory listing.
	$index_file_path = trailingslashit( $export_dir ) . 'index.php';
	if ( ! $filesystem->exists( $index_file_path ) ) {
		$filesystem->put_contents(
			$index_file_path,
			'<?php // Silence is golden.',
			SSE_PRIVATE_FILE_MODE
		);
	}
	if ( $filesystem->exists( $index_file_path ) ) {
		sse_chmod_private_file( $index_file_path );
	}

	// Create .htaccess to deny direct HTTP access (Apache).
	$htaccess_path = trailingslashit( $export_dir ) . '.htaccess';
	if ( ! $filesystem->exists( $htaccess_path ) ) {
		$htaccess_content  = "# Deny direct access to export files.\n";
		$htaccess_content .= "# For Nginx, add a location block to deny access to this directory.\n";
		$htaccess_content .= "<IfModule mod_authz_core.c>\n";
		$htaccess_content .= "\tRequire all denied\n";
		$htaccess_content .= "</IfModule>\n";
		$htaccess_content .= "<IfModule !mod_authz_core.c>\n";
		$htaccess_content .= "\tOrder deny,allow\n";
		$htaccess_content .= "\tDeny from all\n";
		$htaccess_content .= "</IfModule>\n";

		$filesystem->put_contents(
			$htaccess_path,
			$htaccess_content,
			SSE_PRIVATE_FILE_MODE
		);
	}
	if ( $filesystem->exists( $htaccess_path ) ) {
		sse_chmod_private_file( $htaccess_path );
	}
}

/**
 * Exports the database and returns file information.
 *
 * @since 1.0.0
 * @param string $export_dir      The directory to save the database dump.
 * @param string $site_identifier Sanitized site identifier.
 * @param string $timestamp       Export timestamp.
 * @return array{filename: string, filepath: string}|WP_Error Array with file info on success, WP_Error on failure.
 */
function sse_export_database( string $export_dir, string $site_identifier, string $timestamp ): array|WP_Error {
	$db_filename = "{$site_identifier}_db_{$timestamp}.sql";
	$db_filepath = trailingslashit( $export_dir ) . $db_filename;

	// Enhanced WP-CLI path validation.
	$wp_cli_path = sse_get_safe_wp_cli_path();
	if ( is_wp_error( $wp_cli_path ) ) {
		return $wp_cli_path;
	}

	// Only append --allow-root if we are actually running as root (hardening).
	$identity = sse_get_executable_identity( $wp_cli_path );
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}

	// Packages are code that WP-CLI would load from the home directory; a database dump needs none.
	$command = [ $wp_cli_path, 'db', 'export', $db_filepath, '--path=' . ABSPATH, '--skip-packages' ];
	if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
		$command[] = '--allow-root';
	}

	$process_result = sse_run_wp_cli_process( $command, $identity, $db_filepath );
	if ( is_wp_error( $process_result ) ) {
		sse_cleanup_files( [ $db_filepath ] );
		return $process_result;
	}

	if ( $process_result['timed_out'] || 0 !== $process_result['exit_code'] || ! sse_filesystem_file_has_content( $db_filepath ) ) {
		sse_cleanup_files( [ $db_filepath ] );
		$safe_output = sse_sanitize_wp_cli_output( $process_result['output'] );
		sse_log( '' !== $safe_output ? 'WP-CLI database export failed: ' . $safe_output : 'WP-CLI database export failed without diagnostic output.', 'error' );
		return new WP_Error( 'db_export_failed', __( 'WP-CLI could not create the database export.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_chmod_private_file( $db_filepath ) ) {
		sse_cleanup_files( [ $db_filepath ] );
		return new WP_Error( 'db_export_permissions_failed', __( 'Could not secure the permissions of the database export file.', 'enginescript-site-exporter' ) );
	}

	sse_log( 'Database export successful.', 'info' );
	return [
		'filename' => $db_filename,
		'filepath' => $db_filepath,
	];
}
