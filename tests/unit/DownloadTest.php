<?php
/**
 * Tests for downloading and deleting an export: the request checks, the rate limit, path and
 * identity validation, opening the exact file, and deletion.
 *
 * Streaming the file ends the PHP process, so it is not run here.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Covers includes/download.php up to the point where the file is streamed.
 */
final class DownloadTest extends SseTestCase {

	private const ARCHIVE = 'example.com_enginescript_site_export_20260101_120000.zip';

	/**
	 * Export base directory.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Private export directory name holding the archive.
	 *
	 * @var string
	 */
	private string $directory_name;

	/**
	 * Create an export base with one private directory and one archive.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sse_test']['temp_dir'] = $this->make_temporary_directory() . '/';
		$GLOBALS['wp_filesystem']        = new WP_Filesystem_Direct();
		$this->base                      = esse_get_export_directory_path();
		$this->directory_name            = esse_generate_private_export_directory_name();
		mkdir( $this->base . '/' . $this->directory_name, 0700, true );
		file_put_contents( $this->archive_path(), str_repeat( 'z', 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$_GET = array();
	}

	/**
	 * Clear the request.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * Path of the test archive.
	 *
	 * @return string Path.
	 */
	private function archive_path(): string {
		return $this->base . '/' . $this->directory_name . '/' . self::ARCHIVE;
	}

	/**
	 * Return the stored activity and error log, serialized, for a substring search.
	 *
	 * @return string Log.
	 */
	private function stored_log(): string {
		return serialize( $GLOBALS['sse_test']['options'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the stored log.
	}

	public function test_download_request_needs_both_names(): void {
		$_GET = array( 'file' => self::ARCHIVE );

		$this->assertSame( 400, $this->expect_die( 'esse_handle_secure_download' )->status );
	}

	public function test_download_request_needs_a_valid_nonce_and_the_capability(): void {
		$_GET = array(
			'file'       => self::ARCHIVE,
			'export_dir' => $this->directory_name,
		);

		$GLOBALS['sse_test']['valid_nonce'] = false;
		$this->assertSame( 403, $this->expect_die( 'esse_handle_secure_download' )->status );

		$GLOBALS['sse_test']['valid_nonce']  = true;
		$GLOBALS['sse_test']['capabilities'] = array();
		$refusal                             = $this->expect_die( 'esse_handle_secure_download' );
		$this->assertSame( 403, $refusal->status );
		$this->assertSame( 'You do not have permission to download export files.', $refusal->getMessage() );
	}

	public function test_download_of_an_unknown_file_is_refused_and_recorded(): void {
		$_GET = array(
			'file'       => 'example.com_enginescript_site_export_20250101_120000.zip',
			'export_dir' => $this->directory_name,
		);

		$this->assertSame( 404, $this->expect_die( 'esse_handle_secure_download' )->status );
		$this->assertStringContainsString( 'Export download refused:', $this->stored_log() );
	}

	public function test_download_within_a_minute_of_the_last_one_is_refused(): void {
		$_GET = array(
			'file'       => self::ARCHIVE,
			'export_dir' => $this->directory_name,
		);
		esse_start_download_rate_limit();

		$this->assertFalse( esse_check_download_rate_limit() );
		$this->assertSame( 429, $this->expect_die( 'esse_handle_secure_download' )->status );
	}

	public function test_rate_limit_allows_a_download_after_a_minute(): void {
		$this->assertTrue( esse_check_download_rate_limit() );

		set_transient( 'sse_download_rate_limit_7', time() - 61, 60 );
		$this->assertTrue( esse_check_download_rate_limit() );
	}

	public function test_validated_archive_opens_with_its_identity_checked(): void {
		$file = esse_validate_export_file_for_download( self::ARCHIVE, $this->directory_name );

		$opened = esse_open_validated_export_download( $file );

		$this->assertIsResource( $opened['handle'] );
		$this->assertSame( self::ARCHIVE, $opened['filename'] );
		$this->assertSame( 64, $opened['filesize'] );
		fclose( $opened['handle'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test cleanup.
	}

	public function test_an_archive_that_changed_after_validation_is_not_opened(): void {
		$file = esse_validate_export_file_for_download( self::ARCHIVE, $this->directory_name );

		$this->assertSame( 'download_identity_changed', esse_open_validated_export_download( array_merge( $file, array( 'filesize' => 1 ) ) )->get_error_code() );

		// nosemgrep: php.lang.security.unlink-use.unlink-use -- a path the test created, or the test double's own argument
		unlink( $this->archive_path() );
		$this->assertSame( 'invalid_download_path', esse_open_validated_export_download( $file )->get_error_code() );
	}

	public function test_output_security_refuses_other_types_and_paths_outside_the_base(): void {
		$this->assertSame( 'invalid_download_type', esse_validate_file_output_security( $this->base . '/script.php' )->get_error_code() );

		$outside = $this->make_temporary_directory() . '/' . self::ARCHIVE;
		file_put_contents( $outside, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->assertSame( 'invalid_download_path', esse_validate_file_output_security( $outside )->get_error_code() );

		$this->assertSame( wp_normalize_path( (string) realpath( $this->archive_path() ) ), esse_validate_file_output_security( $this->archive_path() ) );
	}

	public function test_identity_comparison_needs_device_inode_and_size_to_match(): void {
		$identity = array(
			'filesize' => 10,
			'device'   => 1,
			'inode'    => 2,
		);

		$this->assertTrue( esse_download_file_identity_matches( $identity, $identity ) );
		$this->assertFalse( esse_download_file_identity_matches( $identity, array_merge( $identity, array( 'inode' => 3 ) ) ) );
	}

	public function test_download_headers_can_be_set_for_any_name(): void {
		$this->expectNotToPerformAssertions();

		esse_set_download_headers( 'a"b.zip', 10 );
		esse_set_download_headers( 'notes.txt', -5 );
	}

	public function test_deletion_removes_the_archive_and_redirects_with_a_success_notice(): void {
		$_POST = array(
			'file'       => self::ARCHIVE,
			'export_dir' => $this->directory_name,
		);

		$redirect = $this->expect_die( 'esse_handle_export_deletion' );

		$this->assertSame( 302, $redirect->status );
		$this->assertFileDoesNotExist( $this->archive_path() );
		$this->assertStringContainsString( 'Export file successfully deleted.', $this->stored_log() . serialize( $GLOBALS['sse_test']['transients'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the stored notice.
		$this->assertStringContainsString( 'Export deleted: ' . self::ARCHIVE, $this->stored_log() );
	}

	public function test_deletion_failure_redirects_with_an_error_notice(): void {
		$_POST = array(
			'file'       => self::ARCHIVE,
			'export_dir' => $this->directory_name,
		);
		$GLOBALS['sse_test']['fail_delete'] = true;

		$redirect = $this->expect_die( 'esse_handle_export_deletion' );

		$this->assertSame( 302, $redirect->status );
		$this->assertFileExists( $this->archive_path() );
		$this->assertStringContainsString( 'Could not delete the export file.', serialize( $GLOBALS['sse_test']['transients'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Searching the stored notice.
	}

	public function test_deletion_request_checks_names_capability_and_file(): void {
		$_POST = array( 'file' => self::ARCHIVE );
		$this->assertSame( 400, $this->expect_die( 'esse_handle_export_deletion' )->status );

		$_POST                               = array(
			'file'       => self::ARCHIVE,
			'export_dir' => $this->directory_name,
		);
		$GLOBALS['sse_test']['capabilities'] = array();
		$this->assertSame( 403, $this->expect_die( 'esse_handle_export_deletion' )->status );

		$GLOBALS['sse_test']['capabilities'] = array( 'manage_options' );
		$_POST['file']                       = 'bad.php';
		$this->assertSame( 404, $this->expect_die( 'esse_handle_export_deletion' )->status );
		$this->assertStringContainsString( 'Export deletion refused:', $this->stored_log() );
	}
}
