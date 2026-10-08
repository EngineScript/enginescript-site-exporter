<?php
/**
 * Tests for the exclusion rules and the path checks.
 *
 * @package EngineScript_Site_Exporter
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What goes into an archive, and which paths a download may touch.
 */
final class PathRuleTest extends SseTestCase {

	/**
	 * Paths that are left out of the files archive.
	 *
	 * @param string $relative_path Path relative to the WordPress directory.
	 * @return void
	 */
	#[DataProvider( 'excluded_paths' )]
	public function test_excluded_paths( string $relative_path ): void {
		$this->assertTrue( sse_should_exclude_file( $relative_path ) );
	}

	/**
	 * Excluded paths.
	 *
	 * @return array<string,array{string}>
	 */
	public static function excluded_paths(): array {
		return array(
			'cache content'            => array( 'wp-content/cache/page.html' ),
			'nested cache content'     => array( 'wp-content/cache/a/b/c.html' ),
			'upgrade content'          => array( 'wp-content/upgrade/plugin.zip' ),
			'temp content'             => array( 'wp-content/temp/x' ),
			'git directory at the top' => array( '.git' ),
			'git directory in plugin'  => array( 'wp-content/plugins/sample/.git' ),
			'svn directory'            => array( 'wp-content/themes/t/.svn' ),
			'hg directory'             => array( '.hg' ),
			'macOS metadata'           => array( 'wp-content/uploads/.DS_Store' ),
			'metadata in lower case'   => array( 'wp-content/uploads/.ds_store' ),
			'htaccess'                 => array( '.htaccess' ),
			'nested htaccess'          => array( 'wp-content/uploads/.htaccess' ),
			'user ini'                 => array( '.user.ini' ),
			'git in upper case'        => array( 'wp-content/.GIT' ),
		);
	}

	/**
	 * Paths that stay in the files archive.
	 *
	 * @param string $relative_path Path relative to the WordPress directory.
	 * @return void
	 */
	#[DataProvider( 'kept_paths' )]
	public function test_kept_paths( string $relative_path ): void {
		$this->assertFalse( sse_should_exclude_file( $relative_path ) );
	}

	/**
	 * Kept paths.
	 *
	 * @return array<string,array{string}>
	 */
	public static function kept_paths(): array {
		return array(
			'the cache directory itself'  => array( 'wp-content/cache' ),
			'the upgrade directory'       => array( 'wp-content/upgrade' ),
			'a cache directory elsewhere' => array( 'wp-content/uploads/cache/x.jpg' ),
			'a name that starts alike'    => array( 'wp-content/cachefile.txt' ),
			'wp-config'                   => array( 'wp-config.php' ),
			'gitignore'                   => array( '.gitignore' ),
			'gitattributes'               => array( 'wp-content/plugins/p/.gitattributes' ),
			'a file ending in git'        => array( 'docs/legit' ),
			'a file named like a suffix'  => array( 'notes.git' ),
			'htaccess backup'             => array( '.htaccess.bak' ),
			'an ordinary upload'          => array( 'wp-content/uploads/2026/10/photo.jpg' ),
			'a file named 0'              => array( '0' ),
			'user ini with another name'  => array( 'php.user.ini.txt' ),
		);
	}

	/**
	 * A path with a parent step, a current-directory step, or a backslash is refused.
	 *
	 * @return void
	 */
	public function test_traversal_check(): void {
		$this->assertTrue( sse_check_path_traversal( '/tmp/exports/export-1/site.zip' ) );

		foreach ( array( '/tmp/exports/../secret.zip', '../site.zip', '/tmp/./site.zip', '/tmp/exports\\site.zip', 'C:\\exports\\site.zip', '/tmp/a..b/site.zip' ) as $path ) {
			$this->assertFalse( sse_check_path_traversal( $path ), $path );
		}
	}

	/**
	 * Only a ZIP file may be served, whatever the case of its extension.
	 *
	 * @return void
	 */
	public function test_only_the_zip_extension_is_allowed(): void {
		$this->assertTrue( sse_validate_file_extension( '/tmp/site.zip' ) );
		$this->assertTrue( sse_validate_file_extension( '/tmp/site.ZIP' ) );

		foreach ( array( '/tmp/site.zip.php', '/tmp/site.php', '/tmp/site', '/tmp/site.tar.gz', '/tmp/.zip/readme', '/tmp/site.zip ' ) as $path ) {
			$this->assertFalse( sse_validate_file_extension( $path ), $path );
		}
	}

	/**
	 * A refused extension is recorded as a security event.
	 *
	 * @return void
	 */
	public function test_a_refused_extension_is_recorded(): void {
		sse_validate_file_extension( '/tmp/site.php' );

		$records = get_option( 'sse_error_logs' );
		$this->assertCount( 1, $records );
		$this->assertSame( 'security', $records[0]['level'] );
		$this->assertStringContainsString( 'php', $records[0]['message'] );
	}

	/**
	 * A resolved path must lie below the base directory, not merely start with its name.
	 *
	 * @return void
	 */
	public function test_path_within_base(): void {
		$this->assertTrue( sse_check_path_within_base( '/tmp/exports/export-1/site.zip', '/tmp/exports' ) );
		$this->assertTrue( sse_check_path_within_base( '/tmp/exports/export-1/site.zip', '/tmp/exports/' ) );
		$this->assertTrue( sse_check_path_within_base( 'C:\\tmp\\exports\\export-1\\site.zip', 'C:/tmp/exports' ), 'Both separators are read alike.' );

		$this->assertFalse( sse_check_path_within_base( '/tmp/exports-other/site.zip', '/tmp/exports' ), 'A sibling whose name starts alike is outside.' );
		$this->assertFalse( sse_check_path_within_base( '/tmp/site.zip', '/tmp/exports' ) );
		$this->assertFalse( sse_check_path_within_base( '/etc/passwd', '/tmp/exports' ) );
		$this->assertFalse( sse_check_path_within_base( false, '/tmp/exports' ), 'A path that could not be resolved is outside.' );
	}

	/**
	 * Containment is decided on resolved paths of things that exist.
	 *
	 * @return void
	 */
	public function test_path_within_directory_uses_real_paths(): void {
		$base    = $this->make_temporary_directory();
		$inside  = $base . DIRECTORY_SEPARATOR . 'inside';
		$sibling = $base . '-sibling';
		mkdir( $inside );
		mkdir( $sibling );
		touch( $inside . DIRECTORY_SEPARATOR . 'file.zip' );

		try {
			$this->assertTrue( sse_is_path_within_directory( $inside, $base ) );
			$this->assertTrue( sse_is_path_within_directory( $inside . DIRECTORY_SEPARATOR . 'file.zip', $base ) );
			$this->assertTrue( sse_is_path_within_directory( $base, $base ), 'A directory contains itself.' );
			$this->assertTrue( sse_is_path_within_directory( $inside . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'inside', $base ), 'The path is resolved first.' );

			$this->assertFalse( sse_is_path_within_directory( $sibling, $base ), 'A sibling whose name starts alike is outside.' );
			$this->assertFalse( sse_is_path_within_directory( $inside . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..', $base ) );
			$this->assertFalse( sse_is_path_within_directory( $base . DIRECTORY_SEPARATOR . 'missing', $base ), 'Something that does not exist is not inside.' );
			$this->assertFalse( sse_is_path_within_directory( $inside, $base . DIRECTORY_SEPARATOR . 'missing' ) );
		} finally {
			rmdir( $sibling );
		}
	}

	/**
	 * A symbolic link that leads out of the directory is outside it.
	 *
	 * @return void
	 */
	public function test_a_link_that_leads_outside_is_outside(): void {
		$base    = $this->make_temporary_directory();
		$outside = $this->make_temporary_directory();
		$link    = $base . DIRECTORY_SEPARATOR . 'link';

		// Creating a link needs a privilege that Windows does not give every account.
		if ( ! @symlink( $outside, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A refused link is the skip condition.
			$this->markTestSkipped( 'This account cannot create symbolic links.' );
		}

		// The link is removed with its directory when the test ends.
		$this->assertFalse( sse_is_path_within_directory( $link, $base ) );
	}

	/**
	 * Only a regular file with content has an identity that a download can check.
	 *
	 * @return void
	 */
	public function test_native_file_identity(): void {
		$regular = array(
			'mode' => 0100600,
			'size' => 2048,
			'dev'  => 66305,
			'ino'  => 1234,
		);

		$this->assertSame(
			array(
				'filesize' => 2048,
				'device'   => 66305,
				'inode'    => 1234,
			),
			sse_normalize_native_file_identity( $regular )
		);

		$this->assertNull( sse_normalize_native_file_identity( false ) );
		$this->assertNull( sse_normalize_native_file_identity( array() ) );
		$this->assertNull( sse_normalize_native_file_identity( array( 'mode' => 0040700 ) + $regular ), 'A directory.' );
		$this->assertNull( sse_normalize_native_file_identity( array( 'mode' => 0120777 ) + $regular ), 'A symbolic link.' );
		$this->assertNull( sse_normalize_native_file_identity( array( 'mode' => 0010600 ) + $regular ), 'A named pipe.' );
		$this->assertNull( sse_normalize_native_file_identity( array( 'size' => 0 ) + $regular ), 'An empty file.' );
		$this->assertNull( sse_normalize_native_file_identity( array( 'size' => '2048' ) + $regular ), 'A size that is not an integer.' );
		$this->assertNull( sse_normalize_native_file_identity( array_diff_key( $regular, array( 'ino' => 0 ) ) ), 'No inode.' );
	}
}
