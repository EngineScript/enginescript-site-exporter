<?php
/**
 * Export workflow: request handling, validation, directory setup, database export.
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

	$value = @unserialize( trim( $raw_value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- The row was written by add_option(); classes are disallowed, and a corrupt row must fail validation quietly.

	return sse_normalize_export_lease( $value );
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
		$file_size = @filesize( $generated_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize,WordPress.PHP.NoSilencedErrors.Discouraged -- Tracked outputs may not exist yet or may be removed between live checks.
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

	$free_bytes = disk_free_space( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_disk_free_space -- Native free-space reporting is required for the local direct filesystem.
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
function sse_validate_export_request(): int|false { // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$post_action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification happens below
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
 * Finds a safe path to the WP-CLI executable.
 *
 * @since 2.0.0
 * @return string|WP_Error The path to WP-CLI on success, or a WP_Error on failure.
 */
function sse_get_safe_wp_cli_path(): string|WP_Error {
	$trusted_system_paths = [
		'/usr/local/bin/wp',
		'/usr/bin/wp',
	];

	$refusal = null;
	foreach ( $trusted_system_paths as $path ) {
		$validation = sse_validate_wp_cli_executable_path( $path );
		if ( ! is_wp_error( $validation ) ) {
			return $validation;
		}

		// Remember the first executable that exists but fails a safety check.
		if ( null === $refusal && 'wp_cli_not_executable' !== $validation->get_error_code() ) {
			$refusal = $validation;
		}
	}

	$configured_path = sse_get_configured_wp_cli_path();
	if ( '' !== $configured_path ) {
		return sse_validate_wp_cli_executable_path( $configured_path );
	}

	if ( null !== $refusal ) {
		return $refusal;
	}

	return new WP_Error( 'wp_cli_not_found', __( 'The WP-CLI executable was not found in a trusted system location. Install WP-CLI at /usr/local/bin/wp or /usr/bin/wp, or explicitly configure a trusted executable with SSE_WP_CLI_PATH or the sse_wp_cli_path filter.', 'enginescript-site-exporter' ) );
}

/**
 * Gets an explicitly configured WP-CLI path.
 *
 * @since 2.1.1
 * @return string Configured path, or empty string.
 */
function sse_get_configured_wp_cli_path(): string {
	$configured_path = '';
	if ( defined( 'SSE_WP_CLI_PATH' ) && is_string( SSE_WP_CLI_PATH ) ) {
		$configured_path = sse_normalize_string_value( SSE_WP_CLI_PATH );
	}

	/**
	 * Filters the explicit WP-CLI executable path.
	 *
	 * @since 2.1.1
	 *
	 * @param string $configured_path Explicit WP-CLI path, or empty string.
	 */
	return trim( sse_normalize_string_value( apply_filters( 'sse_wp_cli_path', $configured_path ) ) );
}

/**
 * Validates a WP-CLI executable path, ownership, and mode.
 *
 * @since 2.1.1
 * @param string $path Candidate executable path.
 * @return string|WP_Error Resolved executable path on success, WP_Error on failure.
 */
function sse_validate_wp_cli_executable_path( string $path ): string|WP_Error {
	if ( '' === $path || ! sse_is_absolute_path( $path ) ) {
		return new WP_Error( 'wp_cli_invalid_path', __( 'The configured WP-CLI path must be absolute.', 'enginescript-site-exporter' ) );
	}

	$resolved_path = realpath( $path );
	if ( false === $resolved_path ) {
		return new WP_Error( 'wp_cli_not_executable', __( 'The WP-CLI executable was not found or is not executable.', 'enginescript-site-exporter' ) );
	}

	$filesystem = sse_get_filesystem();
	if ( is_wp_error( $filesystem ) ) {
		return $filesystem;
	}

	if ( ! $filesystem->is_file( $resolved_path ) || ! is_executable( $resolved_path ) ) {
		return new WP_Error( 'wp_cli_not_executable', __( 'The WP-CLI executable was not found or is not executable.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_wp_cli_has_safe_mode( $resolved_path ) ) {
		return new WP_Error( 'wp_cli_unsafe_mode', __( 'The WP-CLI executable has unsafe writable permissions.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_wp_cli_has_safe_owner( $resolved_path ) ) {
		return new WP_Error( 'wp_cli_unsafe_owner', __( 'The ownership of the WP-CLI executable is not trusted.', 'enginescript-site-exporter' ) );
	}

	if ( ! sse_wp_cli_has_safe_parent_directories( $resolved_path ) ) {
		return new WP_Error( 'wp_cli_unsafe_parent', __( 'The WP-CLI executable is located below an untrusted writable directory.', 'enginescript-site-exporter' ) );
	}

	return $resolved_path;
}

/**
 * Checks that every parent of a WP-CLI executable has trusted permissions.
 *
 * @since 2.1.1
 * @param string $path Resolved executable path.
 * @return bool True when every parent directory is trusted.
 */
function sse_wp_cli_has_safe_parent_directories( string $path ): bool {
	$directory = dirname( $path );

	while ( true ) {
		$permissions = sse_get_filesystem_mode( $directory );
		$owner       = fileowner( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileowner -- Required to bind executable trust to native local ownership.

		if ( false === $permissions || false === $owner || 0 !== ( $permissions & 0022 ) ) {
			return false;
		}

		if ( 0 !== $owner && 0 !== ( $permissions & 0200 ) ) {
			return false;
		}

		$parent = dirname( $directory );
		if ( $parent === $directory ) {
			return true;
		}

		$directory = $parent;
	}
}

/**
 * Captures the native identity of a trusted executable.
 *
 * @since 2.1.1
 * @param string $path Executable path.
 * @return array{dev: int, ino: int}|WP_Error Device and inode identity.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_get_executable_identity( string $path ): array|WP_Error {
	clearstatcache( true, $path );
	$metadata = @stat( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stat,WordPress.PHP.NoSilencedErrors.Discouraged -- A replacement race must return a bounded identity error without leaking the local path.
	if ( false === $metadata || ! isset( $metadata['dev'], $metadata['ino'] ) ) {
		return new WP_Error( 'wp_cli_identity_failed', __( 'Could not verify the WP-CLI executable identity.', 'enginescript-site-exporter' ) );
	}

	return [
		'dev' => $metadata['dev'],
		'ino' => $metadata['ino'],
	];
}

/**
 * Gets a trusted command prefix that creates an owned POSIX process group.
 *
 * BusyBox selects the applet from its first argument when the trusted
 * `setsid` path resolves to the shared BusyBox executable.
 *
 * @since 2.1.1
 * @return array<int,string>|WP_Error Process-group launcher command prefix.
 * @phpstan-return non-empty-list<string>|WP_Error
 * @psalm-return non-empty-list<string>|WP_Error
 */
function sse_get_wp_cli_process_group_launcher(): array|WP_Error {
	foreach ( [ '/usr/bin/setsid', '/bin/setsid' ] as $launcher_path ) {
		$resolved_path = realpath( $launcher_path );
		if ( false === $resolved_path || ! sse_wp_cli_has_safe_parent_directories( $launcher_path ) ) {
			continue;
		}

		$validated_path = sse_validate_wp_cli_executable_path( $resolved_path );
		if ( is_wp_error( $validated_path ) ) {
			continue;
		}

		return 'busybox' === wp_basename( $validated_path )
			? [ $validated_path, 'setsid' ]
			: [ $validated_path ];
	}

	return new WP_Error( 'wp_cli_process_supervision_unavailable', __( 'Secure process execution is disabled on this server.', 'enginescript-site-exporter' ) );
}

/**
 * Checks whether any process remains in an owned WP-CLI process group.
 *
 * @since 2.1.1
 * @param int $process_group_id Positive owned process-group identifier.
 * @return bool True while the group exists or cannot be signaled safely.
 */
function sse_is_wp_cli_process_group_running( int $process_group_id ): bool {
	if ( $process_group_id <= 0 ) {
		return false;
	}

	if ( posix_kill( -$process_group_id, 0 ) ) {
		return true;
	}

	// EPERM means the group still exists but the caller cannot signal it.
	return 1 === posix_get_last_error();
}

/**
 * Drains process pipes while retaining only the newest diagnostic bytes.
 *
 * @since 2.1.1
 * @param resource[] $pipes  Process output pipes.
 * @param string     $output Previously captured output.
 * @return string Bounded diagnostic output.
 */
function sse_drain_process_output( array $pipes, string $output ): string {
	foreach ( $pipes as $pipe ) {
		$chunk = stream_get_contents( $pipe, 32768 );
		if ( is_string( $chunk ) && '' !== $chunk ) {
			$output = substr( $output . $chunk, -32768 );
		}
	}

	return $output;
}

/**
 * Gets the normalized status of a running WP-CLI process.
 *
 * @since 2.1.1
 * @param resource $process          Process resource.
 * @param int      $process_group_id Owned process-group identifier, or zero before assignment.
 * @return array{running:bool,exit_code:int,pid:int} Normalized status.
 */
function sse_get_wp_cli_process_status( $process, int $process_group_id = 0 ): array {
	$status = proc_get_status( $process );

	return [
		'running'   => $status['running'] || sse_is_wp_cli_process_group_running( $process_group_id ),
		'exit_code' => $status['exitcode'],
		'pid'       => $status['pid'],
	];
}

/**
 * Terminates a WP-CLI process through bounded graceful and forceful windows.
 *
 * The caller may invoke proc_close() only after this function returns a
 * stopped state. A process that cannot be proven stopped is never passed to
 * the blocking close operation.
 *
 * @since 2.1.1
 * @param resource   $process          Process resource.
 * @param int        $process_group_id Owned process-group identifier.
 * @param resource[] $pipes            Nonblocking diagnostic pipes.
 * @param string     $output           Previously captured diagnostics.
 * @return array{exit_code:int,output:string}|WP_Error Stopped result or error.
 */
function sse_terminate_wp_cli_process( $process, int $process_group_id, array $pipes, string $output ): array|WP_Error {
	$status    = sse_get_wp_cli_process_status( $process, $process_group_id );
	$exit_code = $status['exit_code'];
	if ( ! $status['running'] ) {
		return [
			'exit_code' => $exit_code,
			'output'    => $output,
		];
	}

	if ( ! posix_kill( -$process_group_id, 15 ) ) {
		proc_terminate( $process );
	}
	$grace_milliseconds = min( 10000, sse_get_export_resource_limit( 'sse_wp_cli_termination_grace_milliseconds', SSE_DEFAULT_PROCESS_TERMINATION_GRACE_MILLISECONDS ) );
	$grace_deadline     = sse_get_monotonic_time() + ( (float) $grace_milliseconds / 1000.0 );

	do {
		$output = sse_drain_process_output( $pipes, $output );
		$status = sse_get_wp_cli_process_status( $process, $process_group_id );
		if ( $status['exit_code'] >= 0 ) {
			$exit_code = $status['exit_code'];
		}
		if ( ! $status['running'] ) {
			return [
				'exit_code' => $exit_code,
				'output'    => $output,
			];
		}
		usleep( 100000 );
	} while ( sse_get_monotonic_time() < $grace_deadline );

	if ( ! posix_kill( -$process_group_id, 9 ) ) {
		proc_terminate( $process, 9 );
	}

	$force_milliseconds = min( 10000, sse_get_export_resource_limit( 'sse_wp_cli_force_grace_milliseconds', SSE_DEFAULT_PROCESS_FORCE_GRACE_MILLISECONDS ) );
	$force_deadline     = sse_get_monotonic_time() + ( (float) $force_milliseconds / 1000.0 );
	do {
		$output = sse_drain_process_output( $pipes, $output );
		$status = sse_get_wp_cli_process_status( $process, $process_group_id );
		if ( $status['exit_code'] >= 0 ) {
			$exit_code = $status['exit_code'];
		}
		if ( ! $status['running'] ) {
			return [
				'exit_code' => $exit_code,
				'output'    => $output,
			];
		}
		usleep( 100000 );
	} while ( sse_get_monotonic_time() < $force_deadline );

	return new WP_Error( 'wp_cli_termination_failed', __( 'The WP-CLI database export could not be stopped safely.', 'enginescript-site-exporter' ) );
}

/**
 * Builds the environment for the WP-CLI child process.
 *
 * The child receives this process's own environment, which is what it
 * inherited before, read variable by variable from the process and never from
 * the request: under PHP-FPM a plain getenv() also returns the request's
 * headers. WP-CLI's global configuration file is pointed at a name inside the
 * private export directory that is never created, so no configuration from the
 * home directory is read.
 *
 * @since 2.1.1
 * @param string $private_directory Private export directory of this export.
 * @return array<string,string> Environment for the child process.
 */
function sse_get_wp_cli_process_environment( string $private_directory ): array {
	$environment = [];
	foreach ( array_keys( getenv() ) as $name ) {
		$value = getenv( $name, true );
		if ( is_string( $value ) ) {
			$environment[ $name ] = $value;
		}
	}

	$environment['WP_CLI_CONFIG_PATH'] = trailingslashit( $private_directory ) . 'wp-cli-no-config.yml';

	return $environment;
}

/**
 * Starts a WP-CLI child and configures its pipes for bounded monitoring.
 *
 * The child starts in the filesystem root, so WP-CLI finds no project
 * configuration in a directory that someone else can write to.
 *
 * @since 2.1.1
 * @param array  $command           Command and arguments.
 * @param string $private_directory Private export directory of this export.
 * @phpstan-param non-empty-list<string> $command
 * @psalm-param non-empty-list<string> $command
 * @return array{process:resource,process_group_id:int,pipes:array<int,resource>}|WP_Error Open process data or error.
 */
function sse_open_wp_cli_process( array $command, string $private_directory ): array|WP_Error {
	$launcher = sse_get_wp_cli_process_group_launcher();
	if ( is_wp_error( $launcher ) ) {
		return $launcher;
	}

	$descriptors   = [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];
	$pipes         = [];
	$owned_command = array_merge( $launcher, $command );
	$process       = proc_open( $owned_command, $descriptors, $pipes, '/', sse_get_wp_cli_process_environment( $private_directory ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open,Generic.PHP.ForbiddenFunctions.Found -- Verified executables and shell-free argv run in an owned, time-bounded process group; WP_Filesystem cannot create or supervise processes.
	if ( ! is_resource( $process ) ) {
		return new WP_Error( 'wp_cli_start_failed', __( 'Could not start the WP-CLI database export.', 'enginescript-site-exporter' ) );
	}

	$status           = sse_get_wp_cli_process_status( $process );
	$process_group_id = $status['pid'];
	$group_deadline   = sse_get_monotonic_time() + 1.0;
	while ( $status['running'] && posix_getpgid( $process_group_id ) !== $process_group_id && sse_get_monotonic_time() < $group_deadline ) {
		usleep( 10000 );
		$status = sse_get_wp_cli_process_status( $process );
	}

	if ( $status['running'] && posix_getpgid( $process_group_id ) !== $process_group_id ) {
		proc_terminate( $process, 9 );
		foreach ( $pipes as $pipe ) {
			fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close owned proc_open pipes after failed supervision setup; these are not filesystem paths.
		}
		proc_close( $process );
		return new WP_Error( 'wp_cli_process_supervision_failed', __( 'The WP-CLI database export could not be stopped safely.', 'enginescript-site-exporter' ) );
	}

	if ( isset( $pipes[0] ) ) {
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close child stdin to signal EOF; WP_Filesystem has no process-pipe API.
		unset( $pipes[0] );
	}
	foreach ( $pipes as $pipe ) {
		stream_set_blocking( $pipe, false );
	}

	$pipes = array_values( $pipes );
	return [
		'process'          => $process,
		'process_group_id' => $process_group_id,
		'pipes'            => $pipes,
	];
}

/**
 * Monitors live WP-CLI output, resource limits, and the derived child timeout.
 *
 * @since 2.1.1
 * @param resource   $process          Process resource.
 * @param int        $process_group_id Owned process-group identifier.
 * @param resource[] $pipes            Nonblocking diagnostic pipes.
 * @param string     $output_path      Database output path to monitor.
 * @param float      $timeout          Derived child timeout in seconds.
 * @return array{exit_code:int,output:string,timed_out:bool,process_error:WP_Error|null,process_is_stopped:bool} Monitor result.
 */
function sse_monitor_wp_cli_process( $process, int $process_group_id, array $pipes, string $output_path, float $timeout ): array {
	$started_at = sse_get_monotonic_time();
	$output     = '';
	$exit_code  = -1;

	while ( true ) {
		$output = sse_drain_process_output( $pipes, $output );
		$status = sse_get_wp_cli_process_status( $process, $process_group_id );
		if ( $status['exit_code'] >= 0 ) {
			$exit_code = $status['exit_code'];
		}
		if ( ! $status['running'] ) {
			return [
				'exit_code'          => $exit_code,
				'output'             => $output,
				'timed_out'          => false,
				'process_error'      => null,
				'process_is_stopped' => true,
			];
		}

		$budget_check = sse_record_generated_export_file( $output_path );
		if ( is_wp_error( $budget_check ) ) {
			return [
				'exit_code'          => -1,
				'output'             => $output,
				'timed_out'          => false,
				'process_error'      => $budget_check,
				'process_is_stopped' => false,
			];
		}

		if ( sse_get_monotonic_time() - $started_at >= $timeout ) {
			return [
				'exit_code'          => -1,
				'output'             => $output,
				'timed_out'          => true,
				'process_error'      => null,
				'process_is_stopped' => false,
			];
		}

		usleep( 100000 );
	}
}

/**
 * Closes every process pipe owned by the caller.
 *
 * @since 2.1.1
 * @param resource[] $pipes Process pipes.
 * @return void
 */
function sse_close_wp_cli_process_pipes( array $pipes ): void {
	foreach ( $pipes as $pipe ) {
		fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release owned child-process streams; WP_Filesystem cannot close process pipes.
	}
}

/**
 * Validates process support, executable identity, and the derived timeout.
 *
 * @since 2.1.1
 * @param array                  $command           Command and arguments.
 * @param array{dev:int,ino:int} $expected_identity Previously verified executable identity.
 * @param string                 $output_path       Database output path to monitor.
 * @phpstan-param non-empty-list<string> $command
 * @psalm-param non-empty-list<string> $command
 * @return float|WP_Error Derived timeout in seconds or error.
 */
function sse_prepare_wp_cli_process( array $command, array $expected_identity, string $output_path ): float|WP_Error {
	if (
		'/' !== DIRECTORY_SEPARATOR
		|| ! function_exists( 'proc_open' )
		|| ! function_exists( 'proc_get_status' )
		|| ! function_exists( 'proc_terminate' )
		|| ! function_exists( 'proc_close' )
		|| ! function_exists( 'posix_getpgid' )
		|| ! function_exists( 'posix_kill' )
		|| ! function_exists( 'posix_get_last_error' )
		|| is_wp_error( sse_get_wp_cli_process_group_launcher() )
	) {
		return new WP_Error( 'proc_open_disabled', __( 'Secure process execution is disabled on this server.', 'enginescript-site-exporter' ) );
	}

	$current_identity = sse_get_executable_identity( $command[0] );
	if ( is_wp_error( $current_identity ) || $current_identity !== $expected_identity ) {
		return new WP_Error( 'wp_cli_identity_changed', __( 'WP-CLI changed after validation, so the export was stopped.', 'enginescript-site-exporter' ) );
	}

	$budget_check = sse_record_generated_export_file( $output_path );
	if ( is_wp_error( $budget_check ) ) {
		return $budget_check;
	}

	$remaining_seconds = sse_get_remaining_export_seconds();
	if ( is_wp_error( $remaining_seconds ) ) {
		return $remaining_seconds;
	}

	$configured_timeout = sse_get_export_resource_limit( 'sse_wp_cli_timeout', SSE_DEFAULT_MAX_EXPORT_SECONDS );
	return min( (float) $configured_timeout, $remaining_seconds );
}

/**
 * Runs WP-CLI without invoking a shell and captures bounded diagnostics.
 *
 * @since 2.1.1
 * @param array                  $command           Command and arguments.
 * @param array{dev:int,ino:int} $expected_identity Previously verified executable identity.
 * @param string                 $output_path       Database output path to monitor.
 * @phpstan-param non-empty-list<string> $command
 * @psalm-param non-empty-list<string> $command
 * @return array{exit_code:int,output:string,timed_out:bool}|WP_Error Process result.
 */
function sse_run_wp_cli_process( array $command, array $expected_identity, string $output_path ): array|WP_Error {
	$timeout = sse_prepare_wp_cli_process( $command, $expected_identity, $output_path );
	if ( is_wp_error( $timeout ) ) {
		return $timeout;
	}

	$opened_process = sse_open_wp_cli_process( $command, dirname( $output_path ) );
	if ( is_wp_error( $opened_process ) ) {
		return $opened_process;
	}

	$process          = $opened_process['process'];
	$process_group_id = $opened_process['process_group_id'];
	$pipes            = $opened_process['pipes'];
	$monitor          = sse_monitor_wp_cli_process( $process, $process_group_id, $pipes, $output_path, $timeout );
	$output           = $monitor['output'];
	$exit_code        = $monitor['exit_code'];

	if ( ! $monitor['process_is_stopped'] ) {
		$termination = sse_terminate_wp_cli_process( $process, $process_group_id, $pipes, $output );
		if ( is_wp_error( $termination ) ) {
			sse_close_wp_cli_process_pipes( $pipes );
			return $termination;
		}

		$output    = $termination['output'];
		$exit_code = $termination['exit_code'];
	}

	$output = sse_drain_process_output( $pipes, $output );
	sse_close_wp_cli_process_pipes( $pipes );

	$close_code = proc_close( $process );
	if ( $exit_code < 0 ) {
		$exit_code = $close_code;
	}

	if ( $monitor['process_error'] instanceof WP_Error ) {
		return $monitor['process_error'];
	}

	return [
		'exit_code' => $exit_code,
		'output'    => $output,
		'timed_out' => $monitor['timed_out'],
	];
}

/**
 * Redacts and bounds WP-CLI diagnostic output for logging.
 *
 * @since 2.1.1
 * @param string $output Raw process output.
 * @return string Safe diagnostic summary.
 */
function sse_sanitize_wp_cli_output( string $output ): string {
	$output_lines = preg_split( '/\r?\n/', $output );
	if ( false === $output_lines ) {
		return '';
	}

	$output_lines = array_slice( $output_lines, 0, 5 );
	$output_lines = array_map(
		static function ( string $line ): string {
			$line_without_paths = preg_replace( '#(/|[A-Za-z]:\\\\)[^\s]+#', '[path]', $line );
			$line_without_paths = is_string( $line_without_paths ) ? $line_without_paths : $line;
			$collapsed_line     = preg_replace( '/\s+/', ' ', $line_without_paths );

			return trim( is_string( $collapsed_line ) ? $collapsed_line : $line_without_paths );
		},
		$output_lines
	);

	return sanitize_text_field( implode( ' | ', $output_lines ) );
}

/**
 * Checks whether a path is absolute.
 *
 * @since 2.1.1
 * @param string $path Path to check.
 * @return bool True when the path is absolute.
 */
function sse_is_absolute_path( string $path ): bool {
	return str_starts_with( $path, '/' );
}

/**
 * Checks whether a WP-CLI executable rejects group/public writes.
 *
 * @since 2.1.1
 * @param string $path Resolved executable path.
 * @return bool True when mode is not group/public writable.
 */
function sse_wp_cli_has_safe_mode( string $path ): bool {
	$permissions = sse_get_filesystem_mode( $path );
	if ( false === $permissions ) {
		return false;
	}

	return 0 === ( $permissions & 0022 );
}

/**
 * Checks whether a WP-CLI executable has trusted ownership.
 *
 * Root-owned executables may be owner-writable. Non-root executables must not be
 * owner-writable. That rule catches an executable that was left writable by
 * mistake. It does not bind the file's owner, who can restore the write bit,
 * so an executable owned by the web server's user is only as safe as that user.
 *
 * @since 2.1.1
 * @param string $path Resolved executable path.
 * @return bool True when ownership and owner-write bits are acceptable.
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
function sse_wp_cli_has_safe_owner( string $path ): bool {
	clearstatcache( true, $path );
	$owner       = @fileowner( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileowner,WordPress.PHP.NoSilencedErrors.Discouraged -- Native numeric UID is required because WP_Filesystem_Direct::owner() reports root UID 0 as failure; replacement races fail closed.
	$permissions = sse_get_filesystem_mode( $path );

	if ( false === $owner || false === $permissions ) {
		return false;
	}

	if ( 0 === $owner ) {
		return true;
	}

	return 0 === ( $permissions & 0200 );
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
