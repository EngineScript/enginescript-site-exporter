<?php
/**
 * Tests for building the export bundle: the compressed database, the manifest, tar entries, and
 * the combined ZIP.
 *
 * The files archive walks ABSPATH, which the suite fixes to a directory that does not exist, so a
 * full build here stops at that step; the other steps run on their own against real files.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Covers the bundle-building functions in includes/archive.php.
 */
final class ArchiveTest extends SseTestCase {

	private const OWNER = '6f1c3a0e-8b2d-4c5e-9f1a-2b3c4d5e6f70';

	/**
	 * Private export directory for the test.
	 *
	 * @var string
	 */
	private string $export_dir;

	/**
	 * Prepare an export directory, a live lease, and the resource budget.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sse_test']['temp_dir'] = $this->make_temporary_directory() . '/';
		$GLOBALS['wp_filesystem']        = new WP_Filesystem_Direct();
		$GLOBALS['wpdb']                 = new wpdb();
		$this->export_dir                = esse_get_export_directory_path() . '/' . esse_generate_private_export_directory_name();
		mkdir( $this->export_dir, 0700, true );

		$now   = time();
		$lease = array(
			'owner'           => self::OWNER,
			'started_at'      => $now,
			'heartbeat_at'    => $now,
			'expires_at'      => $now + 3600,
			'export_dir_name' => basename( $this->export_dir ),
		);
		$GLOBALS['wpdb']->exec( 'INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)', array( 'sse_export_lease', serialize( $lease ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test fixture.
		esse_set_current_export_lease( $lease );
		esse_initialize_export_resource_budget( 0 );
	}

	/**
	 * Clear the lease.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		esse_clear_current_export_lease();
		parent::tearDown();
	}

	/**
	 * Bundle paths for the test's export directory, with the staging directories created.
	 *
	 * @return array<string, string> Bundle paths.
	 */
	private function bundle_paths(): array {
		$paths = esse_prepare_engine_script_bundle_paths( array( 'export_dir' => $this->export_dir ), 'example.com', '20260101_120000' );
		$this->assertTrue( esse_create_bundle_staging_directories( $paths ) );

		return $paths;
	}

	/**
	 * Write a file and return its path.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 * @return string Path.
	 */
	private function write( string $path, string $content ): string {
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $path;
	}

	public function test_requirements_need_a_64_bit_runtime(): void {
		$this->assertTrue( esse_validate_archive_requirements() );
		$this->assertTrue( esse_validate_export_integer_size( 8 ) );
		$this->assertSame( 'unsupported_integer_size', esse_validate_export_integer_size( 4 )->get_error_code() );
	}

	public function test_bundle_paths_follow_the_engine_script_layout(): void {
		$paths = esse_prepare_engine_script_bundle_paths( array( 'export_dir' => '/x' ), 'example.com', '20260101_120000' );

		$this->assertSame( '/x/staging-20260101_120000', $paths['staging_dir'] );
		$this->assertSame( 'example.com_db_20260101_120000.sql.gz', $paths['database_gz_filename'] );
		$this->assertSame( 'example.com_files_20260101_120000.tar.gz', $paths['files_archive_filename'] );
		$this->assertSame( '/x/example.com_enginescript_site_export_20260101_120000.zip', $paths['combined_zip_path'] );
	}

	public function test_database_dump_is_compressed_into_a_private_gzip_file(): void {
		$paths = $this->bundle_paths();
		$dump  = $this->write( $this->export_dir . '/dump.sql', str_repeat( "INSERT INTO t VALUES (1);\n", 200 ) );

		$this->assertTrue( esse_create_compressed_database_file( $dump, $paths['database_path'] ) );

		$this->assertSame( file_get_contents( $dump ), gzdecode( (string) file_get_contents( $paths['database_path'] ) ) );
		$this->assertTrue( esse_has_private_mode( $paths['database_path'] ) );
	}

	public function test_a_missing_dump_is_reported_without_its_path(): void {
		$paths = $this->bundle_paths();

		$error = esse_create_compressed_database_file( $this->export_dir . '/missing.sql', $paths['database_path'] );

		$this->assertSame( 'db_compress_source_failed', $error->get_error_code() );
		$this->assertStringNotContainsString( $this->export_dir, $error->get_error_message() );
	}

	public function test_compression_needs_the_resource_budget(): void {
		unset( $GLOBALS['esse_export_resource_budget'] );

		$this->assertSame( 'export_budget_uninitialized', esse_create_compressed_database_file( 'a', 'b' )->get_error_code() );
	}

	public function test_manifest_records_the_bundle_and_the_stored_site_addresses(): void {
		$paths                                     = $this->bundle_paths();
		$GLOBALS['sse_test']['options']['home']    = 'http://example.com';
		$GLOBALS['sse_test']['options']['siteurl'] = "http://example.com/wp\nevil=1";

		$this->assertTrue( esse_write_engine_script_manifest( $paths, 'example.com' ) );

		$manifest = (string) file_get_contents( $paths['manifest_path'] );
		$this->assertStringStartsWith( "format=enginescript-site-archive\nversion=1\nsite=example.com\n", $manifest );
		$this->assertStringContainsString( "database_path=database/example.com_db_20260101_120000.sql.gz\n", $manifest );
		$this->assertStringContainsString( "files_archive_path=files/example.com_files_20260101_120000.tar.gz\n", $manifest );
		$this->assertStringContainsString( "home_url=http://example.com\n", $manifest );
		$this->assertStringContainsString( "site_url=http://example.com/wpevil=1\n", $manifest, 'A line break in a value cannot start a new key.' );
		$this->assertStringContainsString( "wp_config_in_archive=no\n", $manifest );
		$this->assertTrue( esse_has_private_mode( $paths['manifest_path'] ) );
	}

	public function test_combined_zip_holds_the_manifest_database_and_files_archive(): void {
		$paths = $this->bundle_paths();
		$this->write( $paths['manifest_path'], "format=enginescript-site-archive\n" );
		$this->write( $paths['database_path'], (string) gzencode( 'SQL' ) );
		$this->write( $paths['files_archive_path'], (string) gzencode( 'TAR' ) );

		$this->assertTrue( esse_create_combined_engine_script_zip( $paths ) );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $paths['combined_zip_path'] ) );
		$names = array();
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$names[] = $zip->getNameIndex( $index );
		}
		$zip->close();

		$this->assertSame(
			array( 'database/', 'files/', 'manifest.txt', 'database/example.com_db_20260101_120000.sql.gz', 'files/example.com_files_20260101_120000.tar.gz' ),
			$names
		);
		$this->assertTrue( esse_has_private_mode( $paths['combined_zip_path'] ) );
	}

	public function test_combined_zip_fails_when_an_entry_is_missing(): void {
		$paths = $this->bundle_paths();
		$this->write( $paths['manifest_path'], 'manifest' );

		$this->assertInstanceOf( WP_Error::class, esse_create_combined_engine_script_zip( $paths ) );
		$this->assertFileDoesNotExist( $paths['combined_zip_path'] );
	}

	public function test_full_build_stops_at_the_files_step_and_removes_its_staging_directory(): void {
		$dump = $this->write( $this->export_dir . '/dump.sql', 'SQL' );

		$result = esse_create_site_archive(
			array( 'export_dir' => $this->export_dir ),
			array( 'filepath' => $dump ),
			'example.com',
			'20260101_120000'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertDirectoryDoesNotExist( $this->export_dir . '/staging-20260101_120000' );
	}

	public function test_full_build_stops_when_the_lease_was_lost(): void {
		esse_clear_current_export_lease();

		$result = esse_create_site_archive( array( 'export_dir' => $this->export_dir ), array( 'filepath' => 'x' ), 'example.com', '20260101_120000' );

		$this->assertSame( 'export_lease_lost', $result->get_error_code() );
	}

	public function test_a_file_is_written_as_a_tar_entry(): void {
		$archive = $this->export_dir . '/files.tar.gz';
		$source  = $this->write( $this->export_dir . '/readme.txt', 'Hello' );
		$handle  = gzopen( $archive, 'wb6' );

		$this->assertTrue( esse_write_tar_file_entry( $handle, $source, 'wp-content/readme.txt', $archive ) );
		gzclose( $handle );

		$tar = (string) gzdecode( (string) file_get_contents( $archive ) );
		$this->assertSame( 'wp-content/readme.txt', rtrim( substr( $tar, 0, 100 ), "\0" ) );
		$this->assertSame( 'Hello', substr( $tar, 512, 5 ) );
		$this->assertSame( 0, strlen( $tar ) % 512 );
	}

	public function test_a_file_that_cannot_be_opened_is_skipped_without_stopping_the_export(): void {
		$archive = $this->export_dir . '/files.tar.gz';
		$handle  = gzopen( $archive, 'wb6' );

		$this->assertTrue( esse_write_tar_file_entry( $handle, $this->export_dir . '/missing.txt', 'missing.txt', $archive ) );
		gzclose( $handle );

		$this->assertSame( '', gzdecode( (string) file_get_contents( $archive ) ), 'Nothing is written for it.' );
	}

	public function test_projected_sizes_grow_with_the_input(): void {
		$small = esse_get_projected_gzip_bytes( 10 );
		$large = esse_get_projected_gzip_bytes( 10000 );

		$this->assertIsInt( $small );
		$this->assertGreaterThan( $small, $large );
		$this->assertGreaterThan( 1000, esse_get_projected_tar_entry_bytes( 1000, 'a.txt' ) );
	}
}
