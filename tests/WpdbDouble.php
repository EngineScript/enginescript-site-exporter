<?php
/**
 * A wpdb double backed by an in-memory SQLite database, and the option and cache functions that
 * write through it.
 *
 * The plugin's lease queries run unchanged against real options and sitemeta tables. Two MySQL
 * details are adapted: the BINARY keyword is dropped (SQLite already compares text byte by byte),
 * and GET_LOCK() and RELEASE_LOCK() are SQLite functions whose results a test can set.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal wpdb over SQLite.
	 */
	class wpdb {
		/**
		 * Options table name.
		 *
		 * @var string
		 */
		public $options = 'wp_options';

		/**
		 * Network options table name.
		 *
		 * @var string
		 */
		public $sitemeta = 'wp_sitemeta';

		/**
		 * Error message of the last query, or an empty string.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Every query run, in order.
		 *
		 * @var array<int, string>
		 */
		public $queries = array();

		/**
		 * What GET_LOCK() returns: 1 for acquired, 0 for timed out, null for an error.
		 *
		 * @var int|null
		 */
		public $lock_result = 1;

		/**
		 * Lock names released, in order.
		 *
		 * @var array<int, string>
		 */
		public $released_locks = array();

		/**
		 * Queries matching this pattern fail, as a broken connection would.
		 *
		 * @var string|null
		 */
		public $fail_pattern = null;

		/**
		 * SQLite connection.
		 *
		 * @var PDO
		 */
		private PDO $pdo;

		/**
		 * Create the tables WordPress keeps options in.
		 */
		public function __construct() {
			$options = array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION );
			// PHP 8.4 added Pdo\Sqlite, and 8.5 deprecates PDO::sqliteCreateFunction() in its favour.
			$this->pdo = class_exists( 'Pdo\Sqlite' ) ? new Pdo\Sqlite( 'sqlite::memory:', null, null, $options ) : new PDO( 'sqlite::memory:', null, null, $options );
			$this->pdo->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL UNIQUE, option_value TEXT NOT NULL, autoload TEXT NOT NULL DEFAULT \'yes\')' );
			$this->pdo->exec( 'CREATE TABLE wp_sitemeta (meta_id INTEGER PRIMARY KEY, site_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT)' );
			$this->create_function( 'GET_LOCK', fn ( string $name, int $timeout ): ?int => $this->lock_result, 2 );
			$this->create_function(
				'RELEASE_LOCK',
				function ( string $name ): int {
					$this->released_locks[] = $name;

					return 1;
				},
				1
			);
		}

		/**
		 * Register an SQL function with the API the running PHP version offers.
		 *
		 * @param string   $name     Function name.
		 * @param callable $callback Implementation.
		 * @param int      $arity    Number of arguments.
		 * @return void
		 */
		private function create_function( string $name, callable $callback, int $arity ): void {
			if ( $this->pdo instanceof Pdo\Sqlite ) {
				$this->pdo->createFunction( $name, $callback, $arity );

				return;
			}

			$this->pdo->sqliteCreateFunction( $name, $callback, $arity );
		}

		/**
		 * Fill placeholders as wpdb::prepare() does: %s quoted, %d integer, %i identifier.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string Query.
		 */
		public function prepare( string $query, mixed ...$args ): string {
			$index = 0;

			return (string) preg_replace_callback(
				'/%(%|s|d|i)/',
				function ( array $match ) use ( &$index, $args ): string {
					if ( '%' === $match[1] ) {
						return '%';
					}

					$value = $args[ $index++ ] ?? '';

					return match ( $match[1] ) {
						'd' => (string) (int) $value,
						'i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
						default => $this->pdo->quote( (string) $value ),
					};
				},
				$query
			);
		}

		/**
		 * Run a query.
		 *
		 * @param string $query Query.
		 * @return int|bool Rows changed for a write, true for a read, false on failure.
		 */
		public function query( string $query ): int|bool {
			$statement = $this->run( $query );
			if ( null === $statement ) {
				return false;
			}

			return preg_match( '/^\s*SELECT/i', $query ) ? true : $statement->rowCount();
		}

		/**
		 * Return the first column of every row.
		 *
		 * @param string $query Query.
		 * @return array<int, mixed> Column values.
		 */
		public function get_col( string $query ): array {
			$statement = $this->run( $query );

			return null === $statement ? array() : $statement->fetchAll( PDO::FETCH_COLUMN, 0 );
		}

		/**
		 * Return the first value of the first row.
		 *
		 * @param string $query Query.
		 * @return mixed Value, or null.
		 */
		public function get_var( string $query ): mixed {
			$statement = $this->run( $query );
			if ( null === $statement ) {
				return null;
			}

			$value = $statement->fetchColumn( 0 );

			return false === $value ? null : $value;
		}

		/**
		 * Run SQL directly, for a test's own setup.
		 *
		 * @param string $sql Statement.
		 * @param array  $params Bound values.
		 * @return void
		 */
		public function exec( string $sql, array $params = array() ): void {
			$this->pdo->prepare( $sql )->execute( $params );
		}

		/**
		 * Read rows directly, for a test's assertions.
		 *
		 * @param string $sql Query.
		 * @param array  $params Bound values.
		 * @return array<int, array<string, mixed>> Rows.
		 */
		public function rows( string $sql, array $params = array() ): array {
			$statement = $this->pdo->prepare( $sql );
			$statement->execute( $params );

			return $statement->fetchAll( PDO::FETCH_ASSOC );
		}

		/**
		 * Run a query after adapting it for SQLite, recording errors as wpdb does.
		 *
		 * @param string $query Query.
		 * @return PDOStatement|null Statement, or null on failure.
		 */
		private function run( string $query ): ?PDOStatement {
			$this->queries[]  = $query;
			$this->last_error = '';

			if ( null !== $this->fail_pattern && preg_match( $this->fail_pattern, $query ) ) {
				$this->last_error = 'Simulated database error';

				return null;
			}

			try {
				$statement = $this->pdo->query( str_replace( 'BINARY ', '', $query ) );
			} catch ( PDOException $error ) {
				$this->last_error = $error->getMessage();

				return null;
			}

			return false === $statement ? null : $statement;
		}
	}
}

if ( ! function_exists( 'is_serialized' ) ) {
	/**
	 * Whether a value is a serialized string (the checks WordPress makes, simplified).
	 *
	 * @param mixed $data Value.
	 * @return bool Serialized or not.
	 */
	function is_serialized( $data ): bool {
		if ( ! is_string( $data ) ) {
			return false;
		}

		$data = trim( $data );

		return 'N;' === $data || (bool) preg_match( '/^([adObis]):/', $data );
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * Serialize an array or object, as WordPress does.
	 *
	 * @param mixed $data Value.
	 * @return mixed Serialized string, or the value unchanged.
	 */
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirrors WordPress.
		}

		return is_serialized( $data ) ? serialize( $data ) : $data; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirrors WordPress.
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Add an option row through the wpdb double; false when the option exists.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param string $unused   Deprecated argument.
	 * @param bool   $autoload Autoload flag.
	 * @return bool Added or not.
	 */
	function add_option( $name, $value = '', $unused = '', $autoload = true ): bool {
		$database = $GLOBALS['wpdb'] ?? null;
		if ( ! $database instanceof wpdb ) {
			return false;
		}

		if ( array() !== $database->rows( 'SELECT 1 FROM wp_options WHERE option_name = ?', array( $name ) ) ) {
			return false;
		}

		$database->exec(
			'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?, ?, ?)',
			array( $name, (string) maybe_serialize( $value ), $autoload ? 'yes' : 'no' )
		);

		return true;
	}
}

if ( ! function_exists( 'add_network_option' ) ) {
	/**
	 * Add a network option row through the wpdb double; false when it exists.
	 *
	 * @param int    $network_id Network ID.
	 * @param string $name       Option name.
	 * @param mixed  $value      Value.
	 * @return bool Added or not.
	 */
	function add_network_option( $network_id, $name, $value ): bool {
		$database = $GLOBALS['wpdb'] ?? null;
		if ( ! $database instanceof wpdb ) {
			return false;
		}

		if ( array() !== $database->rows( 'SELECT 1 FROM wp_sitemeta WHERE site_id = ? AND meta_key = ?', array( $network_id, $name ) ) ) {
			return false;
		}

		$database->exec(
			'INSERT INTO wp_sitemeta (site_id, meta_key, meta_value) VALUES (?, ?, ?)',
			array( $network_id, $name, (string) maybe_serialize( $value ) )
		);

		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Record a cache deletion.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return bool True.
	 */
	function wp_cache_delete( $key, $group = '' ): bool {
		$GLOBALS['sse_test']['cache_deletes'][] = $group . ':' . $key;

		return true;
	}
}

if ( ! function_exists( 'get_current_network_id' ) ) {
	/**
	 * Return the current network ID that a test sets (1 by default).
	 *
	 * @return int Network ID.
	 */
	function get_current_network_id(): int {
		return (int) ( $GLOBALS['sse_test']['network_id'] ?? 1 );
	}
}

if ( ! function_exists( 'wp_is_uuid' ) ) {
	/**
	 * Whether a string is a UUID (version 4 when asked), as WordPress checks.
	 *
	 * @param mixed    $uuid    Value.
	 * @param int|null $version Version.
	 * @return bool UUID or not.
	 */
	function wp_is_uuid( $uuid, $version = null ): bool {
		if ( ! is_string( $uuid ) ) {
			return false;
		}

		$pattern = 4 === $version
			? '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'
			: '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

		return (bool) preg_match( $pattern, $uuid );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Return the next UUID a test queued, or a random version 4 UUID.
	 *
	 * @return string UUID.
	 */
	function wp_generate_uuid4(): string {
		if ( ! empty( $GLOBALS['sse_test']['uuids'] ) ) {
			return array_shift( $GLOBALS['sse_test']['uuids'] );
		}

		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
		$bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
