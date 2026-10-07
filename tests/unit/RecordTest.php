<?php
/**
 * Tests for the stored activity, error, and security records.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * The records are shown to administrators, so what is kept and what is removed matters.
 */
final class RecordTest extends SseTestCase {

	/**
	 * Build a well-formed record.
	 *
	 * @param array<string,mixed> $changes Fields to replace.
	 * @return array<string,mixed>
	 */
	private function record( array $changes = array() ): array {
		return $changes + array(
			'time'    => 1000,
			'level'   => 'error',
			'message' => 'Something failed.',
			'user_id' => 7,
		);
	}

	/**
	 * A stored value that is not a list of records yields nothing.
	 *
	 * @return void
	 */
	public function test_a_malformed_stored_value_yields_no_records(): void {
		foreach ( array( false, null, 'text', 12, new stdClass() ) as $stored ) {
			$this->assertSame( array(), sse_get_retained_stored_logs( $stored, 0 ) );
		}
	}

	/**
	 * A record with a missing or mistyped field is dropped; the others are kept.
	 *
	 * @return void
	 */
	public function test_malformed_records_are_dropped(): void {
		$stored = array(
			'not a record',
			$this->record( array( 'message' => 'kept' ) ),
			array_diff_key( $this->record(), array( 'user_id' => 0 ) ),
			$this->record( array( 'time' => 'yesterday' ) ),
			$this->record( array( 'level' => 5 ) ),
			$this->record( array( 'message' => array( 'x' ) ) ),
			$this->record( array( 'user_id' => 'admin' ) ),
			$this->record( array( 'message' => 'kept too' ) ),
		);

		$this->assertSame( array( 'kept', 'kept too' ), array_column( sse_get_retained_stored_logs( $stored, 0 ), 'message' ) );
	}

	/**
	 * Records older than the cutoff are dropped; one at the cutoff is kept.
	 *
	 * @return void
	 */
	public function test_records_older_than_the_cutoff_are_dropped(): void {
		$stored = array(
			$this->record( array( 'time' => 499, 'message' => 'old' ) ),
			$this->record( array( 'time' => 500, 'message' => 'at the cutoff' ) ),
			$this->record( array( 'time' => 501, 'message' => 'new' ) ),
		);

		$this->assertSame( array( 'at the cutoff', 'new' ), array_column( sse_get_retained_stored_logs( $stored, 500 ), 'message' ) );
	}

	/**
	 * At most the 20 newest records are kept.
	 *
	 * @return void
	 */
	public function test_only_the_last_twenty_records_are_kept(): void {
		$stored = array();
		for ( $index = 1; $index <= 25; $index++ ) {
			$stored[] = $this->record( array( 'message' => 'record ' . $index ) );
		}

		$retained = sse_get_retained_stored_logs( $stored, 0 );

		$this->assertCount( 20, $retained );
		$this->assertSame( 'record 6', $retained[0]['message'] );
		$this->assertSame( 'record 25', $retained[19]['message'] );
	}

	/**
	 * Kept records have fixed types, a key-like level, and no path in the message.
	 *
	 * @return void
	 */
	public function test_kept_records_are_normalized(): void {
		$retained = sse_get_retained_stored_logs(
			array(
				$this->record(
					array(
						'time'    => '1200',
						'level'   => 'Security!',
						'message' => 'Refused ' . ABSPATH . 'wp-config.php',
						'user_id' => '3',
					)
				),
			),
			0
		);

		$this->assertSame( 1200, $retained[0]['time'] );
		$this->assertSame( 'security', $retained[0]['level'] );
		$this->assertSame( 3, $retained[0]['user_id'] );
		$this->assertStringNotContainsString( ABSPATH, $retained[0]['message'] );
		$this->assertSame( array( 'time', 'level', 'message', 'user_id' ), array_keys( $retained[0] ), 'Fields of older versions, such as an IP address, are not carried over.' );
	}

	/**
	 * A stored message holds no server path, no markup, and at most 1,000 characters.
	 *
	 * @return void
	 */
	public function test_stored_messages_hold_no_paths_and_no_markup(): void {
		$GLOBALS['sse_test']['temp_dir'] = '/srv/private-tmp/';

		$message = sse_sanitize_stored_log_message(
			'Failed for ' . ABSPATH . 'wp-content/uploads/a.zip, /srv/private-tmp/exports/b.zip, /home/user/c.zip and C:\\Users\\me\\d.zip <script>alert(1)</script>'
		);

		foreach ( array( ABSPATH, '/srv/private-tmp', '/home/user', 'C:\\Users', 'wp-content', 'a.zip', 'd.zip', '<script>' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $message );
		}
		$this->assertStringContainsString( '[path]', $message );
		$this->assertStringStartsWith( 'Failed for', $message );

		$this->assertSame( 1000, strlen( sse_sanitize_stored_log_message( str_repeat( 'word ', 400 ) ) ) );
	}

	/**
	 * Text that only looks a little like a path is left alone.
	 *
	 * @return void
	 */
	public function test_ordinary_text_is_not_treated_as_a_path(): void {
		$message = 'Export created: example.com_enginescript_site_export_20260101_120000.zip (3 of 4, 50%)';

		$this->assertSame( $message, sse_sanitize_stored_log_message( $message ) );
	}

	/**
	 * A log line cannot be split by control characters, and never names a private directory.
	 *
	 * @return void
	 */
	public function test_log_lines_are_single_lines_without_private_directory_names(): void {
		$directory = 'export-20260101_120000-' . str_repeat( 'f', 32 );
		$prepared  = sse_prepare_log_message( "first\r\n[01-Jan-2026] forged\ttab\0null " . $directory . '/site.zip' );

		$this->assertSame( 'first [01-Jan-2026] forged tab null export-[private]/site.zip', $prepared );
		$this->assertDoesNotMatchRegularExpression( '/[\x00-\x1F\x7F]/', $prepared );
	}

	/**
	 * Informational messages are not stored; errors and security events are, with the user.
	 *
	 * @return void
	 */
	public function test_only_errors_and_security_events_are_stored_by_the_logger(): void {
		sse_log( 'Scheduled deletion successful.', 'info' );
		sse_log( 'Careful.', 'warning' );
		$this->assertFalse( get_option( 'sse_error_logs' ) );

		sse_log( 'The export failed.', 'error' );
		sse_log( 'A request was refused.', 'security' );

		$records = get_option( 'sse_error_logs' );
		$this->assertSame( array( 'error', 'security' ), array_column( $records, 'level' ) );
		$this->assertSame( array( 7, 7 ), array_column( $records, 'user_id' ) );
		$this->assertSame( 'The export failed.', $records[0]['message'] );
		$this->assertEqualsWithDelta( time(), $records[0]['time'], 5 );
	}

	/**
	 * A fault that repeats is stored once; a different one, or one by another user, is stored again.
	 *
	 * @return void
	 */
	public function test_a_repeated_error_is_stored_once(): void {
		sse_log( 'The export failed.', 'error' );
		sse_log( 'The export failed.', 'error' );
		$this->assertCount( 1, get_option( 'sse_error_logs' ) );

		sse_log( 'Another failure.', 'error' );
		$this->assertCount( 2, get_option( 'sse_error_logs' ) );

		$GLOBALS['sse_test']['user_id'] = 8;
		sse_log( 'Another failure.', 'error' );
		$this->assertCount( 3, get_option( 'sse_error_logs' ) );

		sse_log( 'Another failure.', 'security' );
		$this->assertCount( 4, get_option( 'sse_error_logs' ), 'The same text at another level is another record.' );
	}

	/**
	 * A repeated error is stored again once the earlier record is an hour old.
	 *
	 * @return void
	 */
	public function test_a_repeated_error_is_stored_again_after_an_hour(): void {
		update_option( 'sse_error_logs', array( $this->record( array( 'time' => time() - HOUR_IN_SECONDS - 1, 'message' => 'The export failed.' ) ) ) );

		sse_log( 'The export failed.', 'error' );

		$this->assertCount( 2, get_option( 'sse_error_logs' ) );
	}

	/**
	 * Every export, download, and deletion is recorded, also when it repeats.
	 *
	 * @return void
	 */
	public function test_activity_is_always_recorded(): void {
		sse_record_activity( 'Export downloaded: site.zip' );
		sse_record_activity( 'Export downloaded: site.zip' );

		$records = get_option( 'sse_error_logs' );
		$this->assertCount( 2, $records );
		$this->assertSame( array( 'activity', 'activity' ), array_column( $records, 'level' ) );
	}

	/**
	 * The store never grows past 20 records.
	 *
	 * @return void
	 */
	public function test_the_store_is_capped_at_twenty_records(): void {
		for ( $index = 1; $index <= 23; $index++ ) {
			sse_record_activity( 'Export created: ' . $index );
		}

		$records = get_option( 'sse_error_logs' );
		$this->assertCount( 20, $records );
		$this->assertSame( 'Export created: 4', $records[0]['message'] );
		$this->assertSame( 'Export created: 23', $records[19]['message'] );
	}

	/**
	 * Pruning removes records older than seven days and says how many.
	 *
	 * @return void
	 */
	public function test_pruning_removes_records_older_than_seven_days(): void {
		update_option(
			'sse_error_logs',
			array(
				$this->record( array( 'time' => time() - ( 8 * DAY_IN_SECONDS ), 'message' => 'old' ) ),
				$this->record( array( 'time' => time() - ( 6 * DAY_IN_SECONDS ), 'message' => 'recent' ) ),
				'not a record',
			)
		);

		$this->assertSame( 2, sse_prune_expired_stored_logs() );
		$this->assertSame( array( 'recent' ), array_column( get_option( 'sse_error_logs' ), 'message' ) );
		$this->assertSame( 0, sse_prune_expired_stored_logs(), 'A second run finds nothing to remove.' );
	}
}
