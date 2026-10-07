<?php
/**
 * Base class for the unit suite.
 *
 * @package EngineScript_Site_Exporter
 */

use PHPUnit\Framework\TestCase;

/**
 * Starts every test from a clean set of recorded calls and stored values.
 */
abstract class SseTestCase extends TestCase {

	/**
	 * Temporary directories this test created.
	 *
	 * @var string[]
	 */
	private array $temporary_directories = array();

	/**
	 * Reset the stand-ins for WordPress.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		sse_test_reset();
		unset( $GLOBALS['sse_exporter_page_hook_suffix'] );
	}

	/**
	 * Remove what the test created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->temporary_directories as $directory ) {
			$this->remove_directory( $directory );
		}

		$this->temporary_directories = array();
		sse_test_reset();
		parent::tearDown();
	}

	/**
	 * Create an empty directory that is removed after the test.
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	protected function make_temporary_directory(): string {
		$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sse-unit-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700, true );
		$this->temporary_directories[] = $directory;

		return $directory;
	}

	/**
	 * Capture what a callback prints.
	 *
	 * @param callable $callback Callback.
	 * @return string
	 */
	protected function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * Run a callback that must end the request, and return what ended it.
	 *
	 * @param callable $callback Callback.
	 * @return Sse_Test_Die_Exception
	 */
	protected function expect_die( callable $callback ): Sse_Test_Die_Exception {
		try {
			$this->capture( $callback );
		} catch ( Sse_Test_Die_Exception $refusal ) {
			return $refusal;
		}

		$this->fail( 'The request was not ended.' );
	}

	/**
	 * Remove a directory and what it holds.
	 *
	 * @param string $directory Directory.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		foreach ( (array) scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->remove_directory( $path );
				continue;
			}

			unlink( $path );
		}

		rmdir( $directory );
	}
}
