<?php
/**
 * WP-CLI process: finding a trusted executable and supervising the child process.
 *
 * The database export runs WP-CLI without a shell, in its own process group, with a
 * time limit and bounded output.
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct execution of this component.
if ( ! defined( 'ABSPATH' ) ) {
	return;
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
		$owner       = fileowner( $directory );

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
	$metadata = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A replacement race must return a bounded identity error without leaking the local path.
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
	$owner       = @fileowner( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Native numeric UID is required because WP_Filesystem_Direct::owner() reports root UID 0 as failure; replacement races fail closed.
	$permissions = sse_get_filesystem_mode( $path );

	if ( false === $owner || false === $permissions ) {
		return false;
	}

	if ( 0 === $owner ) {
		return true;
	}

	return 0 === ( $permissions & 0200 );
}
