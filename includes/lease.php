<?php
/**
 * Export lease: the stored row that lets one export run at a time.
 *
 * The lease is one option row, or one network option row, that names its owner.
 * It is created under a database lock, renewed and released by exact comparison,
 * and reclaimed once it has expired.
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct execution of this component.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Gets the database option name used for the export lease.
 *
 * @since 2.1.1
 * @return string Lease option name.
 */
function sse_get_export_lease_option_name(): string {
	return 'sse_export_lease';
}

/**
 * Narrows an export lease to its canonical persisted shape.
 *
 * @since 2.1.1
 * @param mixed $lease Untrusted lease value.
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|null Canonical lease or null.
 */
function sse_normalize_export_lease( mixed $lease ): ?array {
	if (
		! is_array( $lease )
		|| ! isset( $lease['owner'], $lease['started_at'], $lease['heartbeat_at'], $lease['expires_at'], $lease['export_dir_name'] )
		|| ! is_string( $lease['owner'] )
		|| ! wp_is_uuid( $lease['owner'], 4 )
		|| ! is_string( $lease['export_dir_name'] )
		|| ! is_int( $lease['started_at'] )
		|| ! is_int( $lease['heartbeat_at'] )
		|| ! is_int( $lease['expires_at'] )
	) {
		return null;
	}

	if (
		$lease['started_at'] < 0
		|| $lease['heartbeat_at'] < $lease['started_at']
		|| $lease['expires_at'] <= $lease['heartbeat_at']
		|| ( '' !== $lease['export_dir_name'] && ! sse_is_export_private_directory_name( $lease['export_dir_name'] ) )
	) {
		return null;
	}

	return [
		'owner'           => $lease['owner'],
		'started_at'      => $lease['started_at'],
		'heartbeat_at'    => $lease['heartbeat_at'],
		'expires_at'      => $lease['expires_at'],
		'export_dir_name' => $lease['export_dir_name'],
	];
}

/**
 * Describes the single-site or current-network lease repository.
 *
 * @since 2.1.1
 * @return array{table:string,key_column:string,value_column:string,scope_column:string,scope_id:int,cache_group:string,cache_key:string}|WP_Error Repository data.
 */
function sse_get_export_lease_repository(): array|WP_Error {
	$database = sse_get_wordpress_database();
	if ( null === $database ) {
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$option_name = sse_get_export_lease_option_name();
	if ( ! is_multisite() ) {
		return [
			'table'        => $database->options,
			'key_column'   => 'option_name',
			'value_column' => 'option_value',
			'scope_column' => '',
			'scope_id'     => 0,
			'cache_group'  => 'options',
			'cache_key'    => $option_name,
		];
	}

	$network_id = get_current_network_id();
	if ( $network_id <= 0 || ! is_string( $database->sitemeta ) || '' === $database->sitemeta ) {
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	return [
		'table'        => $database->sitemeta,
		'key_column'   => 'meta_key',
		'value_column' => 'meta_value',
		'scope_column' => 'site_id',
		'scope_id'     => $network_id,
		'cache_group'  => 'site-options',
		'cache_key'    => $network_id . ':' . $option_name,
	];
}

/**
 * Invalidates WordPress' matching cache entry after an exact lease mutation.
 *
 * @since 2.1.1
 * @param array $repository Lease repository data.
 * @psalm-param array{cache_group:string,cache_key:string,...} $repository
 * @return void
 */
function sse_invalidate_export_lease_cache( array $repository ): void {
	wp_cache_delete( $repository['cache_key'], $repository['cache_group'] );
}

/**
 * Reads the raw persisted lease rows without trusting a potentially stale cache.
 *
 * A healthy installation has no row or one row. More can exist only on a
 * network, where the sitemeta table has no unique key, so a bounded number of
 * rows is read.
 *
 * @since 2.1.1
 * @return array<int,string|null>|WP_Error Raw stored values, or a storage error.
 */
function sse_get_stored_export_lease_rows(): array|WP_Error {
	$repository = sse_get_export_lease_repository();
	$database   = sse_get_wordpress_database();
	if ( is_wp_error( $repository ) || null === $database ) {
		return is_wp_error( $repository ) ? $repository : new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	if ( '' === $repository['scope_column'] ) {
		$query = sse_normalize_string_value(
			$database->prepare(
				'SELECT %i FROM %i WHERE %i = %s LIMIT 50',
				$repository['value_column'],
				$repository['table'],
				$repository['key_column'],
				sse_get_export_lease_option_name()
			)
		);
	} else {
		$query = sse_normalize_string_value(
			$database->prepare(
				'SELECT %i FROM %i WHERE %i = %d AND %i = %s LIMIT 50',
				$repository['value_column'],
				$repository['table'],
				$repository['scope_column'],
				$repository['scope_id'],
				$repository['key_column'],
				sse_get_export_lease_option_name()
			)
		);
	}

	if ( '' === $query ) {
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$rows = $database->get_col( $query );
	if ( '' !== $database->last_error ) {
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$raw_values = [];
	foreach ( $rows as $row ) {
		$raw_values[] = is_string( $row ) ? $row : null;
	}

	return $raw_values;
}

/**
 * Decodes one raw lease row without instantiating any class.
 *
 * The lease is always a plain array, so a row that names a class is treated
 * like any other row that fails validation.
 *
 * @since 2.1.1
 * @param string|null $raw_value Raw stored value, or null for a NULL column.
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|null Canonical lease, or null when invalid.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_decode_export_lease_row( ?string $raw_value ): ?array {
	if ( null === $raw_value || ! is_serialized( $raw_value ) ) {
		return null;
	}

	return sse_normalize_export_lease( @unserialize( trim( $raw_value ), [ 'allowed_classes' => false ] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- The row was written by add_option(); classes are disallowed, and a corrupt row must fail validation quietly.
}

/**
 * Reads exactly one persisted lease without trusting a potentially stale cache.
 *
 * @since 2.1.1
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|WP_Error|null Lease, error, or null when absent.
 */
function sse_get_stored_export_lease(): array|WP_Error|null {
	$rows = sse_get_stored_export_lease_rows();
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	if ( [] === $rows ) {
		return null;
	}

	if ( 1 !== count( $rows ) ) {
		return new WP_Error( 'export_lease_ambiguous', __( 'The export state is inconsistent, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$lease = sse_decode_export_lease_row( $rows[0] );
	if ( null === $lease ) {
		return new WP_Error( 'export_lease_invalid', __( 'The export state is invalid, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	return $lease;
}

/**
 * Inserts a lease through the matching native WordPress option API.
 *
 * Callers hold the repository lock: add_option() reads and then upserts, and
 * the sitemeta table does not enforce a unique network-option key, so neither
 * insert is atomic on its own.
 *
 * @since 2.1.1
 * @param array $lease Lease data.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return bool True when inserted.
 * @phpstan-impure
 */
function sse_add_export_lease_option( array $lease ): bool {
	$repository = sse_get_export_lease_repository();
	if ( is_wp_error( $repository ) ) {
		return false;
	}

	sse_invalidate_export_lease_cache( $repository );
	if ( is_multisite() ) {
		return add_network_option( $repository['scope_id'], sse_get_export_lease_option_name(), $lease );
	}

	return add_option( sse_get_export_lease_option_name(), $lease, '', false );
}

/**
 * Acquires a short database mutex for atomic lease creation.
 *
 * @since 2.1.1
 * @return string|WP_Error Lock name on success.
 */
function sse_acquire_export_lease_repository_lock(): string|WP_Error {
	$repository = sse_get_export_lease_repository();
	$database   = sse_get_wordpress_database();
	if ( is_wp_error( $repository ) || null === $database ) {
		return is_wp_error( $repository ) ? $repository : new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$lock_name = 'sse:' . substr( hash( 'sha256', $repository['table'] . ':' . $repository['scope_id'] . ':' . sse_get_export_lease_option_name() ), 0, 48 );
	$query     = sse_normalize_string_value( $database->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
	if ( '' === $query || '1' !== (string) $database->get_var( $query ) ) {
		return new WP_Error( 'export_lease_lock_unavailable', __( 'The export state is busy or unavailable. Please try again.', 'enginescript-site-exporter' ) );
	}

	return $lock_name;
}

/**
 * Releases the short database mutex used during lease creation.
 *
 * @since 2.1.1
 * @param string $lock_name Acquired lock name.
 * @return void
 */
function sse_release_export_lease_repository_lock( string $lock_name ): void {
	if ( '' === $lock_name ) {
		return;
	}

	$database = sse_get_wordpress_database();
	if ( null === $database ) {
		return;
	}

	$query = sse_normalize_string_value( $database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	if ( '' !== $query ) {
		$database->get_var( $query );
	}
}

/**
 * Replaces an exact owned lease value in the matching repository.
 *
 * @since 2.1.1
 * @param array $expected_lease Current exact lease value.
 * @param array $updated_lease  Replacement exact lease value.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $expected_lease
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $updated_lease
 * @return true|WP_Error True when exactly one row was replaced.
 */
function sse_compare_and_update_export_lease( array $expected_lease, array $updated_lease ): true|WP_Error {
	$repository = sse_get_export_lease_repository();
	$database   = sse_get_wordpress_database();
	if ( is_wp_error( $repository ) || null === $database ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	if ( '' === $repository['scope_column'] ) {
		$query = sse_normalize_string_value(
			$database->prepare(
				'UPDATE %i SET %i = %s WHERE %i = %s AND BINARY %i = BINARY %s',
				$repository['table'],
				$repository['value_column'],
				maybe_serialize( $updated_lease ),
				$repository['key_column'],
				sse_get_export_lease_option_name(),
				$repository['value_column'],
				maybe_serialize( $expected_lease )
			)
		);
	} else {
		$query = sse_normalize_string_value(
			$database->prepare(
				'UPDATE %i SET %i = %s WHERE %i = %d AND %i = %s AND BINARY %i = BINARY %s',
				$repository['table'],
				$repository['value_column'],
				maybe_serialize( $updated_lease ),
				$repository['scope_column'],
				$repository['scope_id'],
				$repository['key_column'],
				sse_get_export_lease_option_name(),
				$repository['value_column'],
				maybe_serialize( $expected_lease )
			)
		);
	}

	if ( '' === $query || 1 !== $database->query( $query ) ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	sse_invalidate_export_lease_cache( $repository );
	$stored_lease = sse_get_stored_export_lease();
	if ( is_wp_error( $stored_lease ) || $stored_lease !== $updated_lease ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	return true;
}

/**
 * Deletes the lease rows whose stored value matches byte for byte.
 *
 * @since 2.1.1
 * @param string|null $raw_value Exact stored value, or null for a NULL column.
 * @return int|false Number of rows deleted, or false when the query failed.
 */
function sse_delete_export_lease_rows_by_value( ?string $raw_value ): int|false {
	$repository = sse_get_export_lease_repository();
	$database   = sse_get_wordpress_database();
	if ( is_wp_error( $repository ) || null === $database ) {
		return false;
	}

	if ( null === $raw_value ) {
		// Only the network table allows a NULL value.
		if ( '' === $repository['scope_column'] ) {
			return 0;
		}

		$query = sse_normalize_string_value(
			$database->prepare(
				'DELETE FROM %i WHERE %i = %d AND %i = %s AND %i IS NULL',
				$repository['table'],
				$repository['scope_column'],
				$repository['scope_id'],
				$repository['key_column'],
				sse_get_export_lease_option_name(),
				$repository['value_column']
			)
		);
	} elseif ( '' === $repository['scope_column'] ) {
		$query = sse_normalize_string_value(
			$database->prepare(
				'DELETE FROM %i WHERE %i = %s AND BINARY %i = BINARY %s',
				$repository['table'],
				$repository['key_column'],
				sse_get_export_lease_option_name(),
				$repository['value_column'],
				$raw_value
			)
		);
	} else {
		$query = sse_normalize_string_value(
			$database->prepare(
				'DELETE FROM %i WHERE %i = %d AND %i = %s AND BINARY %i = BINARY %s',
				$repository['table'],
				$repository['scope_column'],
				$repository['scope_id'],
				$repository['key_column'],
				sse_get_export_lease_option_name(),
				$repository['value_column'],
				$raw_value
			)
		);
	}

	$deleted = '' === $query ? false : $database->query( $query );
	if ( ! is_int( $deleted ) ) {
		return false;
	}

	if ( $deleted > 0 ) {
		sse_invalidate_export_lease_cache( $repository );
	}

	return $deleted;
}

/**
 * Deletes an exact owned lease without removing a replacement owner's row.
 *
 * @since 2.1.1
 * @param array $expected_lease Exact lease value to delete.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $expected_lease
 * @return bool True when exactly one row was deleted.
 */
function sse_compare_and_delete_export_lease( array $expected_lease ): bool {
	$raw_value = sse_normalize_string_value( maybe_serialize( $expected_lease ) );

	return '' !== $raw_value && 1 === sse_delete_export_lease_rows_by_value( $raw_value );
}

/**
 * Gets the lease lifetime from the overall work limit plus recovery grace.
 *
 * @since 2.1.1
 * @return int Lease lifetime in seconds.
 */
function sse_get_export_lease_lifetime(): int {
	$max_seconds = sse_get_export_resource_limit( 'sse_max_export_seconds', SSE_DEFAULT_MAX_EXPORT_SECONDS );
	$grace       = sse_get_export_resource_limit( 'sse_export_recovery_grace_seconds', SSE_DEFAULT_EXPORT_RECOVERY_GRACE_SECONDS );

	return $max_seconds > PHP_INT_MAX - $grace ? PHP_INT_MAX : $max_seconds + $grace;
}

/**
 * Acquires one installation-wide export lease atomically.
 *
 * @since 2.1.1
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|WP_Error Lease data on success.
 */
function sse_acquire_export_lease(): array|WP_Error {
	$now      = time();
	$lifetime = sse_get_export_lease_lifetime();
	$owner    = sse_normalize_string_value( wp_generate_uuid4() );
	if ( ! wp_is_uuid( $owner, 4 ) || $lifetime >= PHP_INT_MAX - $now ) {
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	$lease = [
		'owner'           => $owner,
		'started_at'      => $now,
		'heartbeat_at'    => $now,
		'expires_at'      => $now + $lifetime,
		'export_dir_name' => '',
	];

	$lock_name = sse_acquire_export_lease_repository_lock();
	if ( is_wp_error( $lock_name ) ) {
		return $lock_name;
	}

	try {
		return sse_insert_export_lease_under_lock( $lease, $now );
	} finally {
		sse_release_export_lease_repository_lock( $lock_name );
	}
}

/**
 * Removes lease rows that cannot belong to a running export.
 *
 * A row that fails validation is never owned by a live request: the owner
 * normalizes its own copy before every renewal and stops when that fails. A
 * valid row that has expired is reclaimed as before. A valid, unexpired row is
 * never removed, also when it is one of several.
 *
 * @since 2.1.1
 * @param int $now Current wall-clock timestamp.
 * @return int|WP_Error Number of rows left in place, or a storage error.
 */
function sse_remove_unusable_export_lease_rows( int $now ): int|WP_Error {
	$rows = sse_get_stored_export_lease_rows();
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	$remaining = 0;
	$seen      = [];
	foreach ( $rows as $raw_value ) {
		// One delete removes every row with the same stored value.
		$seen_key = null === $raw_value ? 'null' : 'value:' . $raw_value;
		if ( isset( $seen[ $seen_key ] ) ) {
			continue;
		}
		$seen[ $seen_key ] = true;

		$lease = sse_decode_export_lease_row( $raw_value );
		if ( null !== $lease && $lease['expires_at'] > $now ) {
			++$remaining;
			continue;
		}

		$deleted = sse_delete_export_lease_rows_by_value( $raw_value );
		if ( false === $deleted || 0 === $deleted ) {
			++$remaining;
			continue;
		}

		if ( null === $lease ) {
			sse_log( 'Removed an invalid stored export lease.', 'security' );
		}
	}

	return $remaining;
}

/**
 * Clears unusable rows and inserts one new lease while holding the mutex.
 *
 * @since 2.1.1
 * @param array $lease Canonical lease to insert.
 * @param int   $now   Current wall-clock timestamp.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|WP_Error Inserted lease or error.
 */
function sse_insert_export_lease_under_lock( array $lease, int $now ): array|WP_Error {
	$remaining = sse_remove_unusable_export_lease_rows( $now );
	if ( is_wp_error( $remaining ) ) {
		return $remaining;
	}

	if ( $remaining > 0 || ! sse_add_export_lease_option( $lease ) ) {
		return new WP_Error( 'export_lease_conflict', __( 'An export process is already running. Please wait for it to complete before starting a new one.', 'enginescript-site-exporter' ) );
	}

	$stored_lease = sse_get_stored_export_lease();
	if ( is_wp_error( $stored_lease ) || $stored_lease !== $lease ) {
		sse_compare_and_delete_export_lease( $lease );
		return new WP_Error( 'export_lease_storage_unavailable', __( 'The export state could not be verified, so the export was not started.', 'enginescript-site-exporter' ) );
	}

	return $lease;
}

/**
 * Stores the current request's exact owned lease.
 *
 * @since 2.1.1
 * @param array $lease Canonical lease.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return void
 */
function sse_set_current_export_lease( array $lease ): void {
	$GLOBALS['sse_current_export_lease'] = $lease;
}

/**
 * Gets the current request's exact owned lease.
 *
 * @since 2.1.1
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|null Canonical lease or null.
 */
function sse_get_current_export_lease(): ?array {
	return sse_normalize_export_lease( $GLOBALS['sse_current_export_lease'] ?? null );
}

/**
 * Clears the current request's lease value.
 *
 * @since 2.1.1
 * @return void
 */
function sse_clear_current_export_lease(): void {
	unset( $GLOBALS['sse_current_export_lease'] );
}

/**
 * Renews and verifies the current exact lease when due.
 *
 * @since 2.1.1
 * @param bool $force Whether to renew even before the heartbeat interval.
 * @return true|WP_Error True while the current owner remains live.
 */
function sse_renew_current_export_lease( bool $force = false ): true|WP_Error {
	$lease = sse_get_current_export_lease();
	if ( null === $lease ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	$now              = time();
	$maximum_interval = max( 1, intdiv( sse_get_export_resource_limit( 'sse_max_export_seconds', SSE_DEFAULT_MAX_EXPORT_SECONDS ), 4 ) );
	$renewal_interval = min( 60, $maximum_interval );
	if ( ! $force && $lease['expires_at'] > $now && $now - $lease['heartbeat_at'] < $renewal_interval ) {
		return true;
	}

	if ( $lease['expires_at'] <= $now ) {
		return new WP_Error( 'export_lease_expired', __( 'The lock of the export expired, so the export was stopped.', 'enginescript-site-exporter' ) );
	}

	// A backward clock step must not produce a heartbeat earlier than the last one.
	$heartbeat = max( $now, $lease['heartbeat_at'] );
	$lifetime  = sse_get_export_lease_lifetime();
	if ( $lifetime >= PHP_INT_MAX - $heartbeat ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	$updated_lease                 = $lease;
	$updated_lease['heartbeat_at'] = $heartbeat;
	$updated_lease['expires_at']   = $heartbeat + $lifetime;

	if ( $updated_lease === $lease ) {
		$stored_lease = sse_get_stored_export_lease();
		return $stored_lease === $lease ? true : new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	$renewed = sse_compare_and_update_export_lease( $lease, $updated_lease );
	if ( is_wp_error( $renewed ) ) {
		return $renewed;
	}

	sse_set_current_export_lease( $updated_lease );
	return true;
}

/**
 * Records the generated directory on an owned export lease.
 *
 * @since 2.1.1
 * @param array  $lease           Current lease data.
 * @param string $export_dir_name Generated private directory name.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string}|WP_Error Updated lease data.
 */
function sse_update_export_lease_directory( array $lease, string $export_dir_name ): array|WP_Error {
	if ( ! sse_is_export_private_directory_name( $export_dir_name ) ) {
		return new WP_Error( 'export_lease_lost', __( 'The export lost its lock, so it was stopped.', 'enginescript-site-exporter' ) );
	}

	$updated_lease                    = $lease;
	$updated_lease['export_dir_name'] = $export_dir_name;
	$updated                          = sse_compare_and_update_export_lease( $lease, $updated_lease );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	sse_set_current_export_lease( $updated_lease );
	return $updated_lease;
}

/**
 * Releases an export lease without deleting a newer owner's lease.
 *
 * @since 2.1.1
 * @param array $lease Current lease data.
 * @psalm-param array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} $lease
 * @return bool True when the owned lease was released.
 */
function sse_release_export_lease( array $lease ): bool {
	return sse_compare_and_delete_export_lease( $lease );
}
