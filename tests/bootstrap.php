<?php
/**
 * Bootstrap for the unit suite and for the admin script tests.
 *
 * WordPress is not loaded. The functions and classes below stand in for the
 * parts of WordPress that the plugin calls in the code these suites run. They
 * keep their state in $GLOBALS['sse_test'], which sse_test_reset() clears.
 *
 * Code whose behavior depends on a real database, a real WordPress filesystem
 * object, scheduled events, or a child process is not tested here. The suite
 * that the compatibility workflow generates runs that code on real WordPress.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/var/www/html/' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Thrown where WordPress would end the request with wp_die().
 */
class Sse_Test_Die_Exception extends RuntimeException {

	/**
	 * HTTP status passed to wp_die().
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Constructor.
	 *
	 * @param string $message Message passed to wp_die().
	 * @param int    $status  HTTP status passed to wp_die().
	 */
	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/**
 * Minimal WP_Error.
 */
class WP_Error {

	/**
	 * Error code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Get the error code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->code;
	}

	/**
	 * Get the error message.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * Minimal direct filesystem transport, backed by the real filesystem.
 */
class WP_Filesystem_Direct {

	/**
	 * Modes set through chmod(), reported back by getchmod() on systems without POSIX modes.
	 *
	 * @var array<string, int>
	 */
	public static array $modes = array();

	/**
	 * Whether a path exists.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function exists( $path ) {
		return file_exists( $path );
	}

	/**
	 * Whether a path is a directory.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_dir( $path ) {
		return is_dir( $path );
	}

	/**
	 * Whether a path is a regular file.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_file( $path ) {
		return is_file( $path );
	}

	/**
	 * Whether a path is readable.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_readable( $path ) {
		return is_readable( $path );
	}

	/**
	 * Whether a path is writable.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function is_writable( $path ) {
		return is_writable( $path );
	}

	/**
	 * Modification time.
	 *
	 * @param string $path Path.
	 * @return int|false
	 */
	public function mtime( $path ) {
		return @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors WordPress.
	}

	/**
	 * File size.
	 *
	 * @param string $path Path.
	 * @return int|false
	 */
	public function size( $path ) {
		return @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors WordPress.
	}

	/**
	 * Permissions as an octal string, as WordPress returns them.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function getchmod( $path ) {
		// Windows keeps no POSIX permissions, so the mode a test set is reported back, as on Linux.
		if ( isset( self::$modes[ $path ] ) && file_exists( $path ) ) {
			return substr( decoct( self::$modes[ $path ] ), -3 );
		}

		return substr( decoct( (int) @fileperms( $path ) ), -3 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors WordPress.
	}

	/**
	 * Change permissions.
	 *
	 * @param string    $path Path.
	 * @param int|false $mode Mode.
	 * @return bool
	 */
	public function chmod( $path, $mode = false ) {
		if ( false === $mode || ! @chmod( $path, $mode ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors WordPress.
			return false;
		}

		self::$modes[ $path ] = $mode;
		return true;
	}

	/**
	 * Write a file.
	 *
	 * @param string    $path     Path.
	 * @param string    $contents Contents.
	 * @param int|false $mode     Mode.
	 * @return bool
	 */
	public function put_contents( $path, $contents, $mode = false ) {
		if ( false === @file_put_contents( $path, $contents ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Mirrors WordPress.
			return false;
		}

		return false === $mode || chmod( $path, $mode );
	}

	/**
	 * Create a directory.
	 *
	 * @param string    $path  Path.
	 * @param int|false $chmod Mode.
	 * @return bool
	 */
	public function mkdir( $path, $chmod = false ) {
		return @mkdir( $path, false === $chmod ? 0755 : $chmod ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Mirrors WordPress.
	}

	/**
	 * Remove an empty directory.
	 *
	 * @param string $path      Path.
	 * @param bool   $recursive Whether to remove contents.
	 * @return bool
	 */
	public function rmdir( $path, $recursive = false ) {
		return $this->delete( $path, $recursive, 'd' );
	}

	/**
	 * Delete a file or directory, as WordPress does.
	 *
	 * @param string       $file      Path.
	 * @param bool         $recursive Whether to delete contents.
	 * @param string|false $type      'f', 'd', or false to detect.
	 * @return bool
	 */
	public function delete( $file, $recursive = false, $type = false ) {
		if ( 'f' === $type || is_file( $file ) || is_link( $file ) ) {
			return @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Mirrors WordPress.
		}

		if ( ! $recursive ) {
			return @rmdir( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Mirrors WordPress.
		}

		foreach ( (array) $this->dirlist( $file, true ) as $entry ) {
			if ( ! $this->delete( trailingslashit( $file ) . $entry['name'], true, $entry['type'] ) ) {
				return false;
			}
		}

		return @rmdir( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Mirrors WordPress.
	}

	/**
	 * List a directory as WordPress does: entries keyed by name, type 'f' or 'd'.
	 *
	 * @param string $path           Directory.
	 * @param bool   $include_hidden Whether to include dotfiles.
	 * @param bool   $recursive      Whether to list subdirectories.
	 * @return array<string, array<string, mixed>>|false
	 */
	public function dirlist( $path, $include_hidden = true, $recursive = false ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$entries = array();
		foreach ( (array) scandir( $path ) as $name ) {
			if ( '.' === $name || '..' === $name || ( ! $include_hidden && '.' === $name[0] ) ) {
				continue;
			}

			$full             = trailingslashit( $path ) . $name;
			$entry            = array(
				'name'        => $name,
				'size'        => is_file( $full ) ? filesize( $full ) : 0,
				'lastmodunix' => (int) @filemtime( $full ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Mirrors WordPress.
				'type'        => is_dir( $full ) ? 'd' : 'f',
			);
			$entry['files']   = 'd' === $entry['type'] && $recursive ? $this->dirlist( $full, $include_hidden, true ) : array();
			$entries[ $name ] = $entry;
		}

		return $entries;
	}
}

/**
 * Clear every recorded call and every stored value.
 *
 * @return void
 */
function sse_test_reset(): void {
	$GLOBALS['sse_test'] = array(
		'hooks'           => array(),
		'activation'      => array(),
		'deactivation'    => array(),
		'uninstall'       => array(),
		'options'         => array(),
		'transients'      => array(),
		'pages'           => array(),
		'styles'          => array(),
		'scripts'         => array(),
		'capabilities'    => array( 'manage_options' ),
		'multisite'       => false,
		'main_site'       => true,
		'super_admin'     => false,
		'user_id'         => 7,
		'ssl'             => true,
		'temp_dir'        => '/tmp/',
		'valid_nonce'     => true,
		'page_hook'       => 'tools_page_enginescript-site-exporter',
		'events'          => array(),
		'recurring_event' => array(),
	);

	unset( $GLOBALS['wp_filesystem'], $GLOBALS['wpdb'], $GLOBALS['esse_export_resource_budget'] );
	WP_Filesystem_Direct::$modes = array();
	$_POST = array();
}

// --- Hooks ----------------------------------------------------------------------

/**
 * Record a hook callback.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @return true
 */
function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['sse_test']['hooks'][ $hook ][ $priority ][] = $callback;
	return true;
}

/**
 * Record a filter callback.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @return true
 */
function add_filter( $hook, $callback, $priority = 10 ) {
	return add_action( $hook, $callback, $priority );
}

/**
 * Run the recorded filter callbacks in priority order.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value to filter.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	$callbacks = $GLOBALS['sse_test']['hooks'][ $hook ] ?? array();
	ksort( $callbacks );

	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) {
			$value = $callback( $value );
		}
	}

	return $value;
}

/**
 * Find the priority a callback was registered with.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @return int|false
 */
function sse_test_hook_priority( string $hook, $callback ) {
	foreach ( $GLOBALS['sse_test']['hooks'][ $hook ] ?? array() as $priority => $group ) {
		if ( in_array( $callback, $group, true ) ) {
			return (int) $priority;
		}
	}

	return false;
}

/**
 * Record an activation callback.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {
	$GLOBALS['sse_test']['activation'][] = array( $file, $callback );
}

/**
 * Record a deactivation callback.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['sse_test']['deactivation'][] = array( $file, $callback );
}

/**
 * Record an uninstall callback.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_uninstall_hook( $file, $callback ) {
	$GLOBALS['sse_test']['uninstall'][] = array( $file, $callback );
}

// --- Text -----------------------------------------------------------------------

/**
 * Return text unchanged; no translations are loaded.
 *
 * @param string $text Text.
 * @return string
 */
function __( $text ) {
	return $text;
}

/**
 * Choose the singular or the plural form.
 *
 * @param string $single Singular form.
 * @param string $plural Plural form.
 * @param int    $number Number.
 * @return string
 */
function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Format a number with thousands separators.
 *
 * @param int|float $number Number.
 * @return string
 */
function number_format_i18n( $number ) {
	return number_format( (float) $number );
}

/**
 * Format a string; %l joins a list the way WordPress does in English.
 *
 * @param string $pattern Pattern.
 * @param mixed  ...$args Arguments.
 * @return string
 */
function wp_sprintf( $pattern, ...$args ) {
	if ( str_contains( $pattern, '%l' ) ) {
		$items = array_values( (array) array_shift( $args ) );
		$count = count( $items );
		$list  = (string) ( $items[0] ?? '' );

		if ( 2 === $count ) {
			$list = $items[0] . ' and ' . $items[1];
		} elseif ( $count > 2 ) {
			$list = implode( ', ', array_slice( $items, 0, -1 ) ) . ', and ' . $items[ $count - 1 ];
		}

		$pattern = str_replace( '%l', str_replace( '%', '%%', $list ), $pattern );
	}

	return vsprintf( $pattern, $args );
}

/**
 * Escape text for HTML.
 *
 * @param mixed $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape text for an attribute. Like WordPress, existing entities are kept.
 *
 * @param mixed $text Text.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
}

/**
 * Escape a URL for output.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ) {
	return str_replace( array( '&', "'", '"', '<', '>' ), array( '&#038;', '&#039;', '%22', '%3C', '%3E' ), (string) $url );
}

/**
 * Return escaped text.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html__( $text ) {
	return esc_html( $text );
}

/**
 * Print escaped text.
 *
 * @param string $text Text.
 * @return void
 */
function esc_html_e( $text ) {
	echo esc_html( $text );
}

/**
 * Print attribute-escaped text.
 *
 * @param string $text Text.
 * @return void
 */
function esc_attr_e( $text ) {
	echo esc_attr( $text );
}

/**
 * Print an admin notice, with the markup of core's wp_get_admin_notice().
 *
 * Core also passes the result through wp_kses_post(); the plugin's messages
 * are already escaped, so that step changes nothing here.
 *
 * @param string               $message Escaped message.
 * @param array<string, mixed> $args    Notice arguments.
 * @return void
 */
function wp_admin_notice( $message, $args = array() ) {
	$args    = array_merge(
		array(
			'type'               => '',
			'additional_classes' => array(),
			'paragraph_wrap'     => true,
		),
		$args
	);
	$classes = array_merge( array( 'notice' ), '' === $args['type'] ? array() : array( 'notice-' . $args['type'] ), $args['additional_classes'] );

	echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . ( $args['paragraph_wrap'] ? '<p>' . $message . '</p>' : $message ) . '</div>';
}

/**
 * Reduce a value to a lower-case key.
 *
 * @param mixed $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return is_scalar( $key ) ? (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) : '';
}

/**
 * Reduce a value to one line of plain text.
 *
 * @param mixed $text Text.
 * @return string
 */
function sanitize_text_field( $text ) {
	$text = is_scalar( $text ) ? (string) $text : '';
	$text = (string) preg_replace( '/<[^>]*>/', '', $text );
	$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );

	return trim( $text );
}

/**
 * Convert a value to a non-negative integer.
 *
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Remove the slashes WordPress adds to request data.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

// --- Paths ----------------------------------------------------------------------

/**
 * Remove trailing slashes.
 *
 * @param string $value Path.
 * @return string
 */
function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

/**
 * End a path with exactly one slash.
 *
 * @param string $value Path.
 * @return string
 */
function trailingslashit( $value ) {
	return untrailingslashit( $value ) . '/';
}

/**
 * Parse a URL, or one of its parts.
 *
 * @param string $url       URL.
 * @param int    $component PHP_URL_* constant, or -1 for every part.
 * @return mixed
 */
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url().
}

/**
 * Normalize a path the way WordPress does: forward slashes, no doubled
 * slashes, and an upper-case drive letter.
 *
 * @param string $path Path.
 * @return string
 */
function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = (string) preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	return $path;
}

/**
 * Get the last component of a path.
 *
 * @param string $path Path.
 * @return string
 */
function wp_basename( $path ) {
	return basename( (string) $path );
}

/**
 * Get the temporary directory the test configured.
 *
 * @return string
 */
function get_temp_dir() {
	return $GLOBALS['sse_test']['temp_dir'];
}

/**
 * Hash a value with a fixed test salt.
 *
 * @param string $data Data.
 * @return string
 */
function wp_hash( $data ) {
	return hash_hmac( 'sha256', (string) $data, 'sse-test-salt' );
}

// --- Site, user, and request state ----------------------------------------------

/**
 * Whether the installation is a network.
 *
 * @return bool
 */
function is_multisite() {
	return $GLOBALS['sse_test']['multisite'];
}

/**
 * Whether the current site is the main site.
 *
 * @return bool
 */
function is_main_site() {
	return $GLOBALS['sse_test']['main_site'];
}

/**
 * Whether the current user is a super admin.
 *
 * @return bool
 */
function is_super_admin() {
	return $GLOBALS['sse_test']['super_admin'];
}

/**
 * Whether the current user has a capability.
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return in_array( $capability, $GLOBALS['sse_test']['capabilities'], true );
}

/**
 * Get the current user's ID.
 *
 * @return int
 */
function get_current_user_id() {
	return $GLOBALS['sse_test']['user_id'];
}

/**
 * Get a user. No users exist in this suite.
 *
 * @return false
 */
function get_userdata() {
	return false;
}

/**
 * Whether the request uses HTTPS.
 *
 * @return bool
 */
function is_ssl() {
	return $GLOBALS['sse_test']['ssl'];
}

/**
 * Whether a value is a WP_Error.
 *
 * @param mixed $thing Value.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * End the request.
 *
 * @param string $message Message.
 * @param string $title   Title.
 * @param array  $args    Arguments; `response` is the HTTP status.
 * @return never
 * @throws Sse_Test_Die_Exception Always.
 */
function wp_die( $message = '', $title = '', $args = array() ) {
	unset( $title );
	throw new Sse_Test_Die_Exception( html_entity_decode( (string) $message, ENT_QUOTES, 'UTF-8' ), (int) ( $args['response'] ?? 500 ) );
}

/**
 * End the request with a redirect, as wp_safe_redirect() followed by exit does: throws, so the
 * test sees the target (status 302).
 *
 * @param string $location Redirect target.
 * @return never
 * @throws Sse_Test_Die_Exception Always.
 */
function wp_safe_redirect( $location ) {
	throw new Sse_Test_Die_Exception( (string) $location, 302 );
}

/**
 * Check the request's nonce; a failed check ends the request with status 403.
 *
 * @return int
 * @throws Sse_Test_Die_Exception When the test marked the nonce as invalid.
 */
function check_admin_referer() {
	if ( ! $GLOBALS['sse_test']['valid_nonce'] ) {
		throw new Sse_Test_Die_Exception( 'The link you followed has expired.', 403 );
	}

	return 1;
}

// --- Stored values --------------------------------------------------------------

/**
 * Get an option.
 *
 * @param string $name          Option name.
 * @param mixed  $default_value Value when the option does not exist.
 * @return mixed
 */
function get_option( $name, $default_value = false ) {
	return $GLOBALS['sse_test']['options'][ $name ] ?? $default_value;
}

/**
 * Store an option.
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 * @return true
 */
function update_option( $name, $value ) {
	$GLOBALS['sse_test']['options'][ $name ] = $value;
	return true;
}

/**
 * Get a transient.
 *
 * @param string $name Transient name.
 * @return mixed
 */
function get_transient( $name ) {
	return $GLOBALS['sse_test']['transients'][ $name ] ?? false;
}

/**
 * Store a transient.
 *
 * @param string $name  Transient name.
 * @param mixed  $value Value.
 * @return true
 */
function set_transient( $name, $value ) {
	$GLOBALS['sse_test']['transients'][ $name ] = $value;
	return true;
}

/**
 * Delete a transient.
 *
 * @param string $name Transient name.
 * @return true
 */
function delete_transient( $name ) {
	unset( $GLOBALS['sse_test']['transients'][ $name ] );
	return true;
}

// --- Scheduled events -----------------------------------------------------------

/**
 * Get the time of a recorded recurring event.
 *
 * @param string $hook Hook name.
 * @return int|false
 */
function wp_next_scheduled( $hook, $args = array() ) {
	$single = $GLOBALS['sse_test']['single_events'][ $hook . '|' . serialize( $args ) ] ?? null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Event key, as WordPress builds it.
	if ( null !== $single ) {
		return $single['time'];
	}

	return $GLOBALS['sse_test']['recurring_event'][ $hook ]['time'] ?? false;
}

/**
 * Record a recurring event.
 *
 * @param int    $timestamp  First run.
 * @param string $recurrence Recurrence name.
 * @param string $hook       Hook name.
 * @return true
 */
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['sse_test']['recurring_event'][ $hook ] = array(
		'time'       => (int) $timestamp,
		'recurrence' => $recurrence,
	);
	return true;
}

/**
 * Remove a recorded recurring event.
 *
 * @param string $hook Hook name.
 * @return int
 */
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$key     = $hook . '|' . serialize( $args ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Event key, as WordPress builds it.
	$removed = isset( $GLOBALS['sse_test']['recurring_event'][ $hook ] ) || isset( $GLOBALS['sse_test']['single_events'][ $key ] ) ? 1 : 0;
	unset( $GLOBALS['sse_test']['recurring_event'][ $hook ], $GLOBALS['sse_test']['single_events'][ $key ] );
	return $removed;
}

/**
 * Record a single event, or return the error a test set in schedule_error.
 *
 * @param int    $timestamp Run time.
 * @param string $hook      Hook name.
 * @param array  $args      Arguments.
 * @param bool   $wp_error  Whether to return a WP_Error on failure.
 * @return true|WP_Error
 */
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	if ( ! empty( $GLOBALS['sse_test']['schedule_error'] ) ) {
		return new WP_Error( 'could_not_set', 'The cron event list could not be saved.' );
	}

	$GLOBALS['sse_test']['single_events'][ $hook . '|' . serialize( $args ) ] = array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Event key, as WordPress builds it.
		'time' => (int) $timestamp,
		'hook' => $hook,
		'args' => $args,
	);
	return true;
}

/**
 * Remove every event of a hook.
 *
 * @param string $hook Hook name.
 * @return int Number removed.
 */
function wp_unschedule_hook( $hook ) {
	$removed = 0;
	foreach ( array_keys( $GLOBALS['sse_test']['single_events'] ?? array() ) as $key ) {
		if ( str_starts_with( $key, $hook . '|' ) ) {
			unset( $GLOBALS['sse_test']['single_events'][ $key ] );
			++$removed;
		}
	}

	if ( isset( $GLOBALS['sse_test']['recurring_event'][ $hook ] ) ) {
		unset( $GLOBALS['sse_test']['recurring_event'][ $hook ] );
		++$removed;
	}

	$GLOBALS['sse_test']['unscheduled'][] = $hook;
	return $removed;
}

/**
 * Delete an option from the option store, and from the wpdb double when one is set.
 *
 * @param string $name Option name.
 * @return bool True.
 */
function delete_option( $name ) {
	unset( $GLOBALS['sse_test']['options'][ $name ] );
	$GLOBALS['sse_test']['deleted_options'][] = $name;
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
		$GLOBALS['wpdb']->exec( 'DELETE FROM wp_options WHERE option_name = ?', array( $name ) );
	}
	return true;
}

/**
 * Delete a network option from the wpdb double.
 *
 * @param int    $network_id Network ID.
 * @param string $name       Option name.
 * @return bool True.
 */
function delete_network_option( $network_id, $name ) {
	$GLOBALS['sse_test']['deleted_network_options'][] = $network_id . ':' . $name;
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
		$GLOBALS['wpdb']->exec( 'DELETE FROM wp_sitemeta WHERE site_id = ? AND meta_key = ?', array( $network_id, $name ) );
	}
	return true;
}

/**
 * Return a page of the site IDs a test set in sites.
 *
 * @param array $args Query arguments: number and offset.
 * @return array<int, int|string>
 */
function get_sites( $args = array() ) {
	return array_slice( $GLOBALS['sse_test']['sites'] ?? array(), (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) );
}

/**
 * Record a switch to a site.
 *
 * @param int $site_id Site ID.
 * @return true
 */
function switch_to_blog( $site_id ) {
	$GLOBALS['sse_test']['switched_to'][] = (int) $site_id;
	return true;
}

/**
 * Record a switch back.
 *
 * @return true
 */
function restore_current_blog() {
	$GLOBALS['sse_test']['restored'] = ( $GLOBALS['sse_test']['restored'] ?? 0 ) + 1;
	return true;
}

/**
 * Create a directory and its parents, as WordPress does.
 *
 * @param string $target Directory.
 * @return bool Created or already present.
 */
function wp_mkdir_p( $target ) {
	return is_dir( $target ) || @mkdir( $target, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Mirrors WordPress.
}

/**
 * Delete a file, as WordPress does.
 *
 * @param string $file File path.
 * @return bool Deleted or not.
 */
function wp_delete_file( $file ) {
	return @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Mirrors WordPress.
}

/**
 * Delete a file when it is inside a directory, as WordPress does.
 *
 * @param string $file      File path.
 * @param string $directory Directory it must be in.
 * @return bool Deleted or not.
 */
function wp_delete_file_from_directory( $file, $directory ) {
	if ( ! empty( $GLOBALS['sse_test']['fail_delete'] ) ) {
		return false;
	}

	$real_file      = realpath( $file );
	$real_directory = realpath( $directory );
	if ( false === $real_file || false === $real_directory || ! str_starts_with( $real_file, rtrim( $real_directory, '/' . chr( 92 ) ) . DIRECTORY_SEPARATOR ) ) {
		return false;
	}

	return @unlink( $real_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Mirrors WordPress.
}

// --- Admin screens and assets ---------------------------------------------------

/**
 * Record a Tools submenu page.
 *
 * @param string   $page_title Page title.
 * @param string   $menu_title Menu title.
 * @param string   $capability Capability.
 * @param string   $menu_slug  Menu slug.
 * @param callable $callback   Page callback.
 * @return string
 */
function add_management_page( $page_title, $menu_title, $capability, $menu_slug, $callback ) {
	return add_submenu_page( 'tools.php', $page_title, $menu_title, $capability, $menu_slug, $callback );
}

/**
 * Record a submenu page.
 *
 * @param string   $parent_slug Parent menu.
 * @param string   $page_title  Page title.
 * @param string   $menu_title  Menu title.
 * @param string   $capability  Capability.
 * @param string   $menu_slug   Menu slug.
 * @param callable $callback    Page callback.
 * @return string
 */
function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) {
	$GLOBALS['sse_test']['pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return $GLOBALS['sse_test']['page_hook'];
}

/**
 * Record an enqueued stylesheet.
 *
 * @param string $handle       Handle.
 * @param string $src          URL.
 * @param array  $dependencies Dependencies.
 * @param string $version      Version.
 * @return void
 */
function wp_enqueue_style( $handle, $src = '', $dependencies = array(), $version = false ) {
	$GLOBALS['sse_test']['styles'][ $handle ] = compact( 'src', 'dependencies', 'version' );
}

/**
 * Record an enqueued script.
 *
 * @param string $handle       Handle.
 * @param string $src          URL.
 * @param array  $dependencies Dependencies.
 * @param string $version      Version.
 * @param array  $args         Loading arguments.
 * @return void
 */
function wp_enqueue_script( $handle, $src = '', $dependencies = array(), $version = false, $args = array() ) {
	$GLOBALS['sse_test']['scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'args' );
}

/**
 * Get the URL of a plugin's directory.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/';
}

/**
 * Get an admin URL.
 *
 * @param string $path Path below wp-admin.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * Add query arguments to a URL.
 *
 * @param array  $arguments Arguments.
 * @param string $url       URL.
 * @return string
 */
function add_query_arg( $arguments, $url ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $arguments );
}

/**
 * Create a nonce for an action.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( $action ) {
	return substr( hash( 'sha256', 'nonce|' . $action ), 0, 10 );
}

/**
 * Add a nonce to a URL.
 *
 * @param string $url    URL.
 * @param string $action Action.
 * @return string
 */
function wp_nonce_url( $url, $action ) {
	return add_query_arg( array( '_wpnonce' => wp_create_nonce( $action ) ), $url );
}

/**
 * Print the nonce and referer fields of a form.
 *
 * @param string $action Action.
 * @param string $name   Field name.
 * @return void
 */
function wp_nonce_field( $action, $name ) {
	printf(
		'<input type="hidden" id="%1$s" name="%1$s" value="%2$s" /><input type="hidden" name="_wp_http_referer" value="/wp-admin/tools.php?page=enginescript-site-exporter" />',
		esc_attr( $name ),
		esc_attr( wp_create_nonce( $action ) )
	);
}

/**
 * Print a submit button with the markup WordPress uses.
 *
 * @param string $text Button text.
 * @return void
 */
function submit_button( $text ) {
	printf( '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="%s"  /></p>', esc_attr( $text ) );
}

/**
 * Get the title of the current admin page.
 *
 * @return string
 */
function get_admin_page_title() {
	return 'EngineScript Site Exporter';
}

/**
 * Format a number of bytes.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function size_format( $bytes ) {
	$units = array(
		'GB' => 1073741824,
		'MB' => 1048576,
		'KB' => 1024,
	);

	foreach ( $units as $unit => $size ) {
		if ( $bytes >= $size ) {
			return round( $bytes / $size ) . ' ' . $unit;
		}
	}

	return $bytes . ' B';
}

/**
 * Format a time in UTC.
 *
 * @param string $format    Date format.
 * @param int    $timestamp Time.
 * @return string
 */
function wp_date( $format, $timestamp ) {
	return gmdate( $format, (int) $timestamp );
}

sse_test_reset();

// The SQLite wpdb double and the option functions that write through it.
require_once __DIR__ . '/WpdbDouble.php';

require_once dirname( __DIR__ ) . '/enginescript-site-exporter.php';

// The plugin registered its load-time hooks above; keep them for the tests that read them.
$GLOBALS['sse_test_load_time'] = $GLOBALS['sse_test'];

// The admin script tests load this file without PHPUnit, to render the page.
if ( class_exists( \PHPUnit\Framework\TestCase::class ) ) {
	require_once __DIR__ . '/SseTestCase.php';
}
