<?php
/**
 * Tests for cleanup: scheduling, removing exports and directories, recovery of an interrupted
 * export, deactivation, and uninstall.
 *
 * Each test works in a real temporary directory, which stands in for WordPress' temp directory,
 * with the real-filesystem WP_Filesystem_Direct double and the SQLite wpdb double.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Covers the cleanup and recovery functions in includes/cleanup.php.
 */
final class CleanupTest extends SseTestCase {

	private const OWNER = '6f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f70';

	private const ARCHIVE = 'example.com_enginescript_site_export_20260101_120000.zip';

	/**
	 * Export base directory of this test.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * SQLite database double.
	 *
	 * @var wpdb
	 */
	private wpdb $database;

	/**
	 * Point WordPress' temp directory at a fresh directory and create the export base.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sse_test']['temp_dir'] = $this->make_temporary_directory() . '/';
		$GLOBALS['wp_filesystem']        = new WP_Filesystem_Direct();
		$this->database                  = new wpdb();
		$GLOBALS['wpdb']                 = $this->database;
		$this->base                      = esse_get_export_directory_path();
		mkdir( $this->base );
	}

	/**
	 * Create a private export directory, optionally with files, and set its age.
	 *
	 * @param array<int, string> $files Files to create in it.
	 * @param int                $age   Seconds since its last change.
	 * @return string Directory path.
	 */
	private function private_directory( array $files = array(), int $age = 0 ): string {
		$directory = $this->base . '/' . esse_generate_private_export_directory_name();
		mkdir( $directory );
		foreach ( $files as $file ) {
			file_put_contents( $directory . '/' . $file, 'data' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			touch( $directory . '/' . $file, time() - $age );
		}

		touch( $directory, time() - $age );
		usleep( 1000 );

		return $directory;
	}

	/**
	 * Store a lease row.
	 *
	 * @param array<string, mixed> $changes Fields to change.
	 * @return array<string, mixed> The lease.
	 */
	private function store_lease( array $changes = array() ): array {
		$now   = time();
		$lease = array_merge(
			array(
				'owner'           => self::OWNER,
				'started_at'      => $now - 10,
				'heartbeat_at'    => $now - 10,
				'expires_at'      => $now + 1000,
				'export_dir_name' => '',
			),
			$changes
		);
		$this->database->exec( 'INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)', array( 'sse_export_lease', serialize( $lease ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test fixture.

		return $lease;
	}

	/**
	 * Return the messages stored in the plugin's error log option.
	 *
	 * @return string Every stored log entry, serialized, for a substring search.
	 */
	private function stored_log(): string {
		return serialize( $GLOBALS['sse_test']['options']['sse_error_logs'] ?? array() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the stored log.
	}

	public function test_export_file_deletion_is_scheduled_once_and_can_be_removed(): void {
		esse_schedule_export_cleanup( '/tmp/a.zip' );
		$first = wp_next_scheduled( 'sse_delete_export_file', array( '/tmp/a.zip' ) );
		esse_schedule_export_cleanup( '/tmp/a.zip' );

		$this->assertGreaterThan( time(), $first );
		$this->assertSame( $first, wp_next_scheduled( 'sse_delete_export_file', array( '/tmp/a.zip' ) ) );

		esse_unschedule_export_cleanup( '/tmp/a.zip' );
		$this->assertFalse( wp_next_scheduled( 'sse_delete_export_file', array( '/tmp/a.zip' ) ) );
	}

	public function test_scheduling_failures_are_logged(): void {
		$GLOBALS['sse_test']['schedule_error'] = true;

		esse_schedule_export_cleanup( '/tmp/a.zip' );
		esse_schedule_bulk_cleanup();
		$recovery = esse_schedule_export_recovery( $this->store_lease() );

		$this->assertStringContainsString( 'Failed to schedule export file deletion (could_not_set)', $this->stored_log() );
		$this->assertStringContainsString( 'Failed to schedule bulk export cleanup', $this->stored_log() );
		$this->assertSame( 'export_recovery_schedule_failed', $recovery->get_error_code() );
	}

	public function test_bulk_cleanup_and_recovery_are_scheduled_once(): void {
		esse_schedule_bulk_cleanup();
		$bulk = wp_next_scheduled( 'sse_bulk_cleanup_exports' );
		esse_schedule_bulk_cleanup();
		$this->assertSame( $bulk, wp_next_scheduled( 'sse_bulk_cleanup_exports' ) );

		$lease = $this->store_lease();
		$this->assertTrue( esse_schedule_export_recovery( $lease ) );
		$this->assertSame( $lease['expires_at'] + 1, wp_next_scheduled( 'sse_recover_expired_export', array( self::OWNER ) ) );
		$GLOBALS['sse_test']['schedule_error'] = true;
		$this->assertTrue( esse_schedule_export_recovery( $lease ), 'An existing event is kept.' );
	}

	public function test_housekeeping_scheduling_failure_is_logged(): void {
		$GLOBALS['sse_test']['schedule_error'] = true;
		$GLOBALS['sse_test']['recurring_event'] = array();

		// The recurring double cannot fail, so the main-site path only schedules.
		esse_schedule_export_housekeeping();

		$this->assertArrayHasKey( 'sse_export_housekeeping', $GLOBALS['sse_test']['recurring_event'] );
	}

	public function test_base_directory_is_removed_only_when_it_holds_just_its_protection_files(): void {
		file_put_contents( $this->base . '/index.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->base . '/stray.txt', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertFalse( esse_remove_export_base_directory( $this->base ) );

		unlink( $this->base . '/stray.txt' );
		file_put_contents( $this->base . '/.htaccess', 'Deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertTrue( esse_remove_export_base_directory( $this->base ) );
		$this->assertDirectoryDoesNotExist( $this->base );

		$this->assertFalse( esse_remove_export_base_directory( $this->base ), 'A missing base is not removed again.' );
	}

	public function test_all_private_export_directories_are_deleted_and_other_entries_kept(): void {
		$this->private_directory( array( self::ARCHIVE ) );
		$this->private_directory();
		mkdir( $this->base . '/not-ours' );

		$this->assertSame( 2, esse_delete_all_export_directories( $this->base ) );
		$this->assertSame( array( 'not-ours' ), array_values( array_diff( scandir( $this->base ), array( '.', '..' ) ) ) );
		$this->assertSame( 0, esse_delete_all_export_directories( $this->base . '/missing' ) );
	}

	public function test_runtime_state_removal_unschedules_events_and_deletes_the_lease_and_exports(): void {
		$this->store_lease();
		$this->private_directory( array( self::ARCHIVE ) );
		esse_schedule_bulk_cleanup();

		esse_remove_plugin_runtime_state();

		$this->assertSame( esse_get_scheduled_hook_names(), $GLOBALS['sse_test']['unscheduled'] );
		$this->assertFalse( wp_next_scheduled( 'sse_bulk_cleanup_exports' ) );
		$this->assertNull( esse_get_stored_export_lease() );
		$this->assertSame( 0, esse_delete_all_export_directories( $this->base ) );
	}

	public function test_network_runtime_state_removal_deletes_the_network_lease(): void {
		$GLOBALS['sse_test']['multisite'] = true;

		esse_remove_plugin_runtime_state();

		$this->assertSame( array( '1:sse_export_lease' ), $GLOBALS['sse_test']['deleted_network_options'] );
	}

	public function test_uninstall_callback_is_registered_on_the_main_site_only(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$GLOBALS['sse_test']['main_site'] = false;
		esse_register_uninstall_callback();
		$this->assertSame( array(), $GLOBALS['sse_test']['uninstall'] );

		$GLOBALS['sse_test']['main_site'] = true;
		esse_register_uninstall_callback();
		$this->assertNotSame( array(), $GLOBALS['sse_test']['uninstall'] );
	}

	public function test_uninstall_removes_logs_exports_and_the_base_directory(): void {
		$GLOBALS['sse_test']['options']['sse_error_logs'] = array( 'entry' );
		$this->private_directory( array( self::ARCHIVE ) );

		esse_uninstall_plugin();

		$this->assertArrayNotHasKey( 'sse_error_logs', $GLOBALS['sse_test']['options'] );
		$this->assertDirectoryDoesNotExist( $this->base );
	}

	public function test_network_uninstall_visits_every_numeric_site_in_pages(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$GLOBALS['sse_test']['sites']     = array_merge( range( 1, 150 ), array( 'bad' ) );

		esse_uninstall_plugin();

		$this->assertSame( range( 1, 150 ), $GLOBALS['sse_test']['switched_to'] );
		$this->assertSame( 150, $GLOBALS['sse_test']['restored'] );
	}

	public function test_housekeeping_removes_stale_directories_but_keeps_fresh_and_active_ones(): void {
		$stale  = $this->private_directory( array( self::ARCHIVE ), 100000 );
		$fresh  = $this->private_directory();
		$active = $this->private_directory( array(), 100000 );
		$this->store_lease( array( 'export_dir_name' => basename( $active ) ) );

		esse_export_housekeeping_handler();

		$this->assertDirectoryDoesNotExist( $stale );
		$this->assertDirectoryExists( $fresh );
		$this->assertDirectoryExists( $active );
	}

	public function test_stale_cleanup_stops_when_the_lease_cannot_be_read(): void {
		$stale                        = $this->private_directory( array(), 100000 );
		$this->database->fail_pattern = '/^SELECT/';

		$this->assertSame( 0, esse_cleanup_stale_export_directories() );
		$this->assertDirectoryExists( $stale );
		$this->assertStringContainsString( 'Could not verify active export state during stale cleanup.', $this->stored_log() );
	}

	public function test_stale_cleanup_skips_a_missing_base(): void {
		rmdir( $this->base );

		$this->assertSame( 0, esse_cleanup_stale_export_directories() );
	}

	public function test_recovery_ignores_an_invalid_owner(): void {
		esse_recover_expired_export_handler( 42 );

		$this->assertStringContainsString( 'Skipped export recovery with an invalid owner identifier.', $this->stored_log() );
	}

	public function test_recovery_logs_when_the_lease_cannot_be_read(): void {
		$this->database->fail_pattern = '/^SELECT/';

		esse_recover_expired_export_handler( self::OWNER );

		$this->assertStringContainsString( 'Could not verify export state during scheduled recovery.', $this->stored_log() );
	}

	public function test_recovery_without_a_lease_or_for_another_owner_only_removes_stale_directories(): void {
		$stale = $this->private_directory( array(), 100000 );
		esse_recover_expired_export_handler( self::OWNER );
		$this->assertDirectoryDoesNotExist( $stale );

		$this->store_lease( array( 'owner' => '1f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f71' ) );
		esse_recover_expired_export_handler( self::OWNER );
		$this->assertNotNull( esse_get_stored_export_lease(), "Another owner's lease stays." );
	}

	public function test_recovery_of_a_live_lease_schedules_itself_again(): void {
		$lease = $this->store_lease();

		esse_recover_expired_export_handler( self::OWNER );

		$this->assertSame( $lease['expires_at'] + 1, wp_next_scheduled( 'sse_recover_expired_export', array( self::OWNER ) ) );

		$GLOBALS['sse_test']['single_events'] = array();
		$GLOBALS['sse_test']['schedule_error'] = true;
		esse_recover_expired_export_handler( self::OWNER );
		$this->assertStringContainsString( 'Could not reschedule recovery for a renewed export lease.', $this->stored_log() );
	}

	public function test_recovery_of_an_expired_lease_deletes_it_and_its_directory(): void {
		$directory = $this->private_directory( array( self::ARCHIVE ) );
		$this->store_lease(
			array(
				'started_at'      => 1,
				'heartbeat_at'    => 1,
				'expires_at'      => 2,
				'export_dir_name' => basename( $directory ),
			)
		);

		esse_recover_expired_export_handler( self::OWNER );

		$this->assertNull( esse_get_stored_export_lease() );
		$this->assertDirectoryDoesNotExist( $directory );
	}

	public function test_recovery_logs_when_the_expired_directory_is_unsafe(): void {
		$name = esse_generate_private_export_directory_name();
		file_put_contents( $this->base . '/' . $name, 'not a directory' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->store_lease(
			array(
				'started_at'      => 1,
				'heartbeat_at'    => 1,
				'expires_at'      => 2,
				'export_dir_name' => $name,
			)
		);

		esse_recover_expired_export_handler( self::OWNER );

		$this->assertStringContainsString( 'Skipped unsafe expired export directory.', $this->stored_log() );
		$this->assertStringContainsString( 'Failed to remove an expired export directory during scheduled recovery.', $this->stored_log() );
	}

	public function test_expired_lease_directory_without_a_name_or_on_disk_counts_as_removed(): void {
		$this->assertTrue( esse_cleanup_expired_lease_directory( array( 'export_dir_name' => '' ) ) );
		$this->assertTrue( esse_cleanup_expired_lease_directory( array( 'export_dir_name' => esse_generate_private_export_directory_name() ) ) );
	}

	public function test_bulk_cleanup_deletes_old_archives_only_and_removes_emptied_directories(): void {
		$old      = $this->private_directory( array( self::ARCHIVE ), 1000 );
		$new      = $this->private_directory( array( self::ARCHIVE ) );
		$other    = $this->private_directory( array( 'notes.txt' ), 1000 );
		$invalid  = $this->private_directory( array( 'bad name.zip' ), 1000 );
		$active   = $this->private_directory( array( self::ARCHIVE ), 1000 );
		$this->store_lease( array( 'export_dir_name' => basename( $active ) ) );

		esse_bulk_cleanup_exports_handler();

		$this->assertDirectoryDoesNotExist( $old );
		$this->assertFileExists( $new . '/' . self::ARCHIVE );
		$this->assertFileExists( $other . '/notes.txt' );
		$this->assertFileExists( $invalid . '/bad name.zip' );
		$this->assertFileExists( $active . '/' . self::ARCHIVE );
	}

	public function test_bulk_cleanup_stops_when_the_lease_cannot_be_read(): void {
		$old                          = $this->private_directory( array( self::ARCHIVE ), 1000 );
		$this->database->fail_pattern = '/^SELECT/';

		$this->assertSame( array(), esse_get_export_files_for_bulk_cleanup( $this->base ) );
		$this->assertFileExists( $old . '/' . self::ARCHIVE );
		$this->assertSame( array(), esse_get_export_files_from_private_directory( $this->base . '/missing' ) );
	}

	public function test_scheduled_deletion_removes_a_valid_archive_and_its_emptied_directory(): void {
		$directory = $this->private_directory( array( self::ARCHIVE ) );

		esse_delete_export_file_handler( $directory . '/' . self::ARCHIVE );

		$this->assertDirectoryDoesNotExist( $directory );
	}

	public function test_scheduled_deletion_refuses_invalid_arguments_and_locations(): void {
		esse_delete_export_file_handler( array( 'not a path' ) );
		$this->assertStringContainsString( 'Skipped scheduled deletion with an invalid file argument.', $this->stored_log() );

		esse_delete_export_file_handler( $this->base . '/script.php' );
		$this->assertFileDoesNotExist( $this->base . '/script.php' );

		esse_delete_export_file_handler( $this->base . '/' . self::ARCHIVE );

		file_put_contents( $this->base . '/' . self::ARCHIVE, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		esse_delete_export_file_handler( $this->base . '/' . self::ARCHIVE );
		$this->assertFileExists( $this->base . '/' . self::ARCHIVE, 'An archive outside a private directory stays.' );
	}

	public function test_cleanup_files_deletes_only_files_inside_the_export_base(): void {
		$directory = $this->private_directory( array( self::ARCHIVE, 'notes.txt' ) );
		$outside   = $this->make_temporary_directory() . '/outside.txt';
		file_put_contents( $outside, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		esse_cleanup_files( array( $directory . '/' . self::ARCHIVE, $outside ) );

		$this->assertFileDoesNotExist( $directory . '/' . self::ARCHIVE );
		$this->assertFileExists( $directory . '/notes.txt' );
		$this->assertFileExists( $outside );
	}
}
