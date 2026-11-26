<?php
/**
 * Tests for the portable parts of the WP-CLI runner: finding and validating the executable path,
 * the child environment, and the output that reaches the log.
 *
 * Launching WP-CLI needs Linux (setsid and POSIX ownership checks), so that part is checked on the
 * server, not here.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Covers the helpers in includes/process.php that do not start a process.
 */
final class ProcessHelperTest extends SseTestCase {

	/**
	 * Use the real-filesystem double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();
	}

	public function test_configured_path_comes_from_the_filter_trimmed(): void {
		$this->assertSame( '', esse_get_configured_wp_cli_path() );

		add_filter( 'sse_wp_cli_path', static fn (): string => '  /opt/wp-cli/wp  ' );
		$this->assertSame( '/opt/wp-cli/wp', esse_get_configured_wp_cli_path() );
	}

	public function test_executable_path_must_be_absolute_and_present(): void {
		$this->assertSame( 'wp_cli_invalid_path', esse_validate_wp_cli_executable_path( '' )->get_error_code() );
		$this->assertSame( 'wp_cli_invalid_path', esse_validate_wp_cli_executable_path( 'bin/wp' )->get_error_code() );
		$this->assertSame( 'wp_cli_not_executable', esse_validate_wp_cli_executable_path( '/no/such/wp-cli-' . bin2hex( random_bytes( 4 ) ) )->get_error_code() );
	}

	public function test_configured_path_is_validated_when_no_trusted_location_holds_wp_cli(): void {
		if ( is_file( '/usr/local/bin/wp' ) || is_file( '/usr/bin/wp' ) ) {
			// A trusted location holds WP-CLI on this machine: it is used, or refused with a reason.
			$result = esse_get_safe_wp_cli_path();
			$this->assertTrue( is_string( $result ) || in_array( $result->get_error_code(), array( 'wp_cli_unsafe_mode', 'wp_cli_unsafe_owner', 'wp_cli_unsafe_parent' ), true ) );

			return;
		}

		$this->assertSame( 'wp_cli_not_found', esse_get_safe_wp_cli_path()->get_error_code() );

		add_filter( 'sse_wp_cli_path', static fn (): string => 'relative/wp' );
		$this->assertSame( 'wp_cli_invalid_path', esse_get_safe_wp_cli_path()->get_error_code() );
	}

	public function test_child_environment_keeps_the_parent_variables_and_isolates_wp_cli_config(): void {
		putenv( 'SSE_TEST_ENVIRONMENT_VALUE=kept' );

		$environment = esse_get_wp_cli_process_environment( '/private/export' );

		putenv( 'SSE_TEST_ENVIRONMENT_VALUE' );
		$this->assertSame( 'kept', $environment['SSE_TEST_ENVIRONMENT_VALUE'] );
		$this->assertSame( '/private/export/wp-cli-no-config.yml', $environment['WP_CLI_CONFIG_PATH'] );
	}

	public function test_output_reaching_the_log_has_paths_removed_and_at_most_five_lines(): void {
		$output = "Error: could not open /var/www/html/wp-config.php\n  second   line\nC:\\sites\\x.sql failed\n4\n5\n6";

		$this->assertSame(
			'Error: could not open [path] | second line | [path] failed | 4 | 5',
			esse_sanitize_wp_cli_output( $output )
		);
	}

	public function test_absolute_paths_start_with_a_slash(): void {
		$this->assertTrue( esse_is_absolute_path( '/usr/bin/wp' ) );
		$this->assertFalse( esse_is_absolute_path( 'usr/bin/wp' ) );
	}
}
