<?php
/**
 * Tests for the export lease: the one stored row that lets a single export run at a time.
 *
 * The lease queries run against the SQLite wpdb double, so they create, compare, replace, and
 * delete real option and network-option rows.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Covers lease validation, the single-site and network repositories, acquisition under the lock,
 * reclaiming unusable rows, renewal, the directory update, and release.
 */
final class LeaseTest extends SseTestCase {

	private const OWNER = '6f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f70';

	/**
	 * The SQLite database double for the test.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Start every test with an empty database double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->database  = new wpdb();
		$GLOBALS['wpdb'] = $this->database;
		esse_clear_current_export_lease();
	}

	/**
	 * Clear the current lease.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		esse_clear_current_export_lease();
		parent::tearDown();
	}

	/**
	 * Build a canonical lease.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array{owner:string,started_at:int,heartbeat_at:int,expires_at:int,export_dir_name:string} Lease.
	 */
	private function lease( array $changes = array() ): array {
		$now = time();

		return array_merge(
			array(
				'owner'           => self::OWNER,
				'started_at'      => $now - 10,
				'heartbeat_at'    => $now - 10,
				'expires_at'      => $now + 1000,
				'export_dir_name' => '',
			),
			$changes
		);
	}

	/**
	 * Store a raw lease row in the options table.
	 *
	 * @param mixed $value Value; arrays are serialized.
	 * @return void
	 */
	private function store_row( mixed $value ): void {
		$this->database->exec(
			'INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)',
			array( 'sse_export_lease', is_array( $value ) ? serialize( $value ) : $value ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test fixture.
		);
	}

	/**
	 * Store a raw lease row in the network options table.
	 *
	 * @param mixed $value   Value; arrays are serialized, null stays NULL.
	 * @param int   $network Network ID.
	 * @return void
	 */
	private function store_network_row( mixed $value, int $network = 1 ): void {
		$this->database->exec(
			'INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (?, ?, ?)',
			array( $network, 'sse_export_lease', is_array( $value ) ? serialize( $value ) : $value ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test fixture.
		);
	}

	/**
	 * Return the stored lease values of the options table.
	 *
	 * @return array<int, string> Raw values.
	 */
	private function stored_values(): array {
		return array_column( $this->database->rows( "SELECT option_value FROM wp_options WHERE option_name = 'sse_export_lease'" ), 'option_value' );
	}

	public function test_normalize_accepts_a_canonical_lease_and_drops_extra_fields(): void {
		$lease = $this->lease( array( 'export_dir_name' => esse_generate_private_export_directory_name() ) );

		$this->assertSame( $lease, esse_normalize_export_lease( $lease + array( 'extra' => 1 ) ) );
	}

	public function test_normalize_rejects_every_malformed_lease(): void {
		$cases = array(
			'not an array'          => 'lease',
			'missing field'         => array_diff_key( $this->lease(), array( 'expires_at' => 0 ) ),
			'owner not a UUID'      => $this->lease( array( 'owner' => 'someone' ) ),
			'owner not version 4'   => $this->lease( array( 'owner' => '6f1c3a0e-8b2d-1c5e-9f1a-2b3c4d5e6f70' ) ),
			'directory not string'  => $this->lease( array( 'export_dir_name' => 5 ) ),
			'start not integer'     => $this->lease( array( 'started_at' => '5' ) ),
			'negative start'        => $this->lease( array( 'started_at' => -1 ) ),
			'heartbeat before start' => $this->lease( array( 'heartbeat_at' => time() - 100 ) ),
			'expiry not after beat' => $this->lease( array( 'expires_at' => time() - 10 ) ),
			'unsafe directory name' => $this->lease( array( 'export_dir_name' => '../etc' ) ),
		);

		foreach ( $cases as $label => $lease ) {
			$this->assertNull( esse_normalize_export_lease( $lease ), $label );
		}
	}

	public function test_single_site_repository_uses_the_options_table(): void {
		$repository = esse_get_export_lease_repository();

		$this->assertSame( 'wp_options', $repository['table'] );
		$this->assertSame( 'option_name', $repository['key_column'] );
		$this->assertSame( '', $repository['scope_column'] );
	}

	public function test_network_repository_uses_the_current_network_rows(): void {
		$GLOBALS['sse_test']['multisite']  = true;
		$GLOBALS['sse_test']['network_id'] = 3;

		$repository = esse_get_export_lease_repository();

		$this->assertSame( 'wp_sitemeta', $repository['table'] );
		$this->assertSame( 3, $repository['scope_id'] );
		$this->assertSame( '3:sse_export_lease', $repository['cache_key'] );
	}

	public function test_repository_is_unavailable_without_the_database_or_a_valid_network(): void {
		unset( $GLOBALS['wpdb'] );
		$this->assertSame( 'export_lease_storage_unavailable', esse_get_export_lease_repository()->get_error_code() );

		$GLOBALS['wpdb']                   = $this->database;
		$GLOBALS['sse_test']['multisite']  = true;
		$GLOBALS['sse_test']['network_id'] = 0;
		$this->assertSame( 'export_lease_storage_unavailable', esse_get_export_lease_repository()->get_error_code() );

		$GLOBALS['sse_test']['network_id'] = 1;
		$this->database->sitemeta          = '';
		$this->assertSame( 'export_lease_storage_unavailable', esse_get_export_lease_repository()->get_error_code() );
	}

	public function test_acquire_stores_one_lease_and_releases_the_lock(): void {
		$GLOBALS['sse_test']['uuids'] = array( self::OWNER );

		$lease = esse_acquire_export_lease();

		$this->assertSame( self::OWNER, $lease['owner'] );
		$this->assertSame( $lease, esse_get_stored_export_lease() );
		$this->assertCount( 1, $this->database->released_locks );
		$this->assertStringStartsWith( 'sse:', $this->database->released_locks[0] );
		$this->assertContains( 'options:sse_export_lease', $GLOBALS['sse_test']['cache_deletes'] );
	}

	public function test_acquire_refuses_while_another_export_holds_an_unexpired_lease(): void {
		$this->store_row( $this->lease() );

		$this->assertSame( 'export_lease_conflict', esse_acquire_export_lease()->get_error_code() );
		$this->assertCount( 1, $this->stored_values() );
	}

	public function test_acquire_reclaims_an_expired_lease(): void {
		$this->store_row( $this->lease( array( 'started_at' => 1, 'heartbeat_at' => 1, 'expires_at' => 2 ) ) );

		$lease = esse_acquire_export_lease();

		$this->assertIsArray( $lease );
		$this->assertSame( array( serialize( $lease ) ), $this->stored_values() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Comparing the stored row.
	}

	public function test_acquire_removes_an_invalid_row_and_logs_it(): void {
		$this->store_row( 'O:8:"stdClass":0:{}' );

		$this->assertIsArray( esse_acquire_export_lease() );
		$this->assertCount( 1, $this->stored_values() );
		$this->assertStringContainsString( 'Removed an invalid stored export lease.', serialize( $GLOBALS['sse_test']['options'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the stored log.
	}

	public function test_acquire_fails_when_the_lock_is_busy(): void {
		$this->database->lock_result = 0;

		$this->assertSame( 'export_lease_lock_unavailable', esse_acquire_export_lease()->get_error_code() );
		$this->assertSame( array(), $this->stored_values() );
	}

	public function test_acquire_fails_when_the_lease_rows_cannot_be_read(): void {
		$this->database->fail_pattern = '/^SELECT `option_value`/';

		$this->assertSame( 'export_lease_storage_unavailable', esse_acquire_export_lease()->get_error_code() );
		$this->assertCount( 1, $this->database->released_locks );
	}

	public function test_acquire_fails_when_the_lifetime_would_overflow(): void {
		add_filter( 'sse_max_export_seconds', static fn (): int => PHP_INT_MAX );

		$this->assertSame( 'export_lease_storage_unavailable', esse_acquire_export_lease()->get_error_code() );
	}

	public function test_network_acquire_stores_the_lease_as_a_network_option(): void {
		$GLOBALS['sse_test']['multisite'] = true;

		$lease = esse_acquire_export_lease();

		$rows = $this->database->rows( 'SELECT site_id, meta_value FROM wp_sitemeta' );
		$this->assertCount( 1, $rows );
		$this->assertSame( serialize( $lease ), $rows[0]['meta_value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Comparing the stored row.
		$this->assertContains( 'site-options:1:sse_export_lease', $GLOBALS['sse_test']['cache_deletes'] );
	}

	public function test_network_acquire_removes_null_and_duplicate_invalid_rows_but_keeps_live_ones(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$this->store_network_row( null );
		$this->store_network_row( 'corrupt' );
		$this->store_network_row( 'corrupt' );
		$this->store_network_row( $this->lease() );
		$this->store_network_row( $this->lease(), 2 );

		$this->assertSame( 'export_lease_conflict', esse_acquire_export_lease()->get_error_code() );

		$this->assertSame( 1, (int) $this->database->get_var( 'SELECT COUNT(*) FROM wp_sitemeta WHERE site_id = 1' ) );
		$this->assertSame( 1, (int) $this->database->get_var( 'SELECT COUNT(*) FROM wp_sitemeta WHERE site_id = 2' ) );
	}

	public function test_stored_lease_reports_ambiguous_and_invalid_rows(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$this->store_network_row( $this->lease() );
		$this->store_network_row( $this->lease() );
		$this->assertSame( 'export_lease_ambiguous', esse_get_stored_export_lease()->get_error_code() );

		$GLOBALS['sse_test']['multisite'] = false;
		$this->store_row( 'not serialized' );
		$this->assertSame( 'export_lease_invalid', esse_get_stored_export_lease()->get_error_code() );
	}

	public function test_decode_ignores_a_null_column_and_a_serialized_object(): void {
		$this->assertNull( esse_decode_export_lease_row( null ) );
		$this->assertNull( esse_decode_export_lease_row( 'O:8:"stdClass":0:{}' ) );
	}

	public function test_deleting_a_null_value_on_a_single_site_deletes_nothing(): void {
		$this->assertSame( 0, esse_delete_export_lease_rows_by_value( null ) );
	}

	public function test_renew_without_a_current_lease_reports_the_lock_lost(): void {
		$this->assertSame( 'export_lease_lost', esse_renew_current_export_lease()->get_error_code() );
	}

	public function test_renew_before_the_interval_changes_nothing(): void {
		$lease = $this->lease( array( 'heartbeat_at' => time() ) );
		$this->store_row( $lease );
		esse_set_current_export_lease( $lease );
		$queries = count( $this->database->queries );

		$this->assertTrue( esse_renew_current_export_lease() );
		$this->assertCount( $queries, $this->database->queries );
	}

	public function test_forced_renewal_moves_the_heartbeat_and_expiry(): void {
		$lease = $this->lease();
		$this->store_row( $lease );
		esse_set_current_export_lease( $lease );

		$this->assertTrue( esse_renew_current_export_lease( true ) );

		$current = esse_get_current_export_lease();
		$this->assertGreaterThan( $lease['heartbeat_at'], $current['heartbeat_at'] );
		$this->assertSame( $current, esse_get_stored_export_lease() );
	}

	public function test_renewal_of_an_unchanged_lease_checks_the_stored_row(): void {
		$now      = time();
		$lifetime = esse_get_export_lease_lifetime();
		$lease    = $this->lease( array( 'started_at' => $now, 'heartbeat_at' => $now, 'expires_at' => $now + $lifetime ) );
		esse_set_current_export_lease( $lease );

		$this->assertSame( 'export_lease_lost', esse_renew_current_export_lease( true )->get_error_code() );

		$this->store_row( $lease );
		$this->assertTrue( esse_renew_current_export_lease( true ) );
	}

	public function test_renewal_of_an_expired_lease_stops_the_export(): void {
		esse_set_current_export_lease( $this->lease( array( 'started_at' => 1, 'heartbeat_at' => 1, 'expires_at' => 2 ) ) );

		$this->assertSame( 'export_lease_expired', esse_renew_current_export_lease()->get_error_code() );
	}

	public function test_renewal_fails_when_another_owner_replaced_the_row(): void {
		$this->store_row( $this->lease( array( 'owner' => '1f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f71' ) ) );
		esse_set_current_export_lease( $this->lease() );

		$this->assertSame( 'export_lease_lost', esse_renew_current_export_lease( true )->get_error_code() );
	}

	public function test_renewal_fails_when_the_lifetime_would_overflow(): void {
		esse_set_current_export_lease( $this->lease() );
		add_filter( 'sse_export_recovery_grace_seconds', static fn (): int => PHP_INT_MAX );

		$this->assertSame( 'export_lease_lost', esse_renew_current_export_lease( true )->get_error_code() );
	}

	public function test_network_renewal_updates_the_network_row(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$lease                            = $this->lease();
		$this->store_network_row( $lease );
		esse_set_current_export_lease( $lease );

		$this->assertTrue( esse_renew_current_export_lease( true ) );
		$this->assertSame( esse_get_current_export_lease(), esse_get_stored_export_lease() );
	}

	public function test_directory_update_records_a_valid_name_only(): void {
		$lease = $this->lease();
		$this->store_row( $lease );
		$name = esse_generate_private_export_directory_name();

		$this->assertSame( 'export_lease_lost', esse_update_export_lease_directory( $lease, '../outside' )->get_error_code() );

		$updated = esse_update_export_lease_directory( $lease, $name );
		$this->assertSame( $name, $updated['export_dir_name'] );
		$this->assertSame( $updated, esse_get_current_export_lease() );
		$this->assertSame( $updated, esse_get_stored_export_lease() );
	}

	public function test_directory_update_fails_without_the_database(): void {
		$lease = $this->lease();
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'export_lease_lost', esse_update_export_lease_directory( $lease, esse_generate_private_export_directory_name() )->get_error_code() );
	}

	public function test_release_deletes_only_the_owned_lease(): void {
		$other = $this->lease( array( 'owner' => '1f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f71' ) );
		$this->store_row( $other );

		$this->assertFalse( esse_release_export_lease( $this->lease() ) );
		$this->assertCount( 1, $this->stored_values() );

		$this->database->exec( "DELETE FROM wp_options WHERE option_name = 'sse_export_lease'" );
		$lease = $this->lease();
		$this->store_row( $lease );
		$this->assertTrue( esse_release_export_lease( $lease ) );
		$this->assertSame( array(), $this->stored_values() );
	}

	public function test_release_fails_quietly_when_the_delete_query_fails(): void {
		$lease = $this->lease();
		$this->store_row( $lease );
		$this->database->fail_pattern = '/^DELETE/';

		$this->assertFalse( esse_release_export_lease( $lease ) );
	}

	public function test_releasing_the_lock_is_skipped_for_an_empty_name_or_missing_database(): void {
		esse_release_export_lease_repository_lock( '' );
		unset( $GLOBALS['wpdb'] );
		esse_release_export_lease_repository_lock( 'sse:abc' );

		$this->assertSame( array(), $this->database->released_locks );
	}
}
