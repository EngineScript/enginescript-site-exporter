<?php
/**
 * Tests for request validation, limits, and the counts shown after an export.
 *
 * @package EngineScript_Site_Exporter
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Values that arrive from a request, a filter, or a stored notice.
 */
final class RequestAndLimitTest extends SseTestCase {

	/**
	 * The value helpers accept one type and fall back for every other.
	 *
	 * @return void
	 */
	public function test_value_helpers(): void {
		$this->assertSame( array( 1 ), sse_normalize_array_value( array( 1 ) ) );
		$this->assertSame( array(), sse_normalize_array_value( 'text' ) );
		$this->assertSame( array(), sse_normalize_array_value( null ) );

		$this->assertSame( 'text', sse_normalize_string_value( 'text', 'fallback' ) );
		$this->assertSame( 'fallback', sse_normalize_string_value( 12, 'fallback' ) );
		$this->assertSame( '', sse_normalize_string_value( null ) );

		$this->assertSame( 12, sse_normalize_nonnegative_integer( '12' ) );
		$this->assertSame( 0, sse_normalize_nonnegative_integer( 0 ) );
		$this->assertSame( 3, sse_normalize_nonnegative_integer( 3.9 ) );
		$this->assertFalse( sse_normalize_nonnegative_integer( -1 ) );
		$this->assertFalse( sse_normalize_nonnegative_integer( 'twelve' ) );
		$this->assertFalse( sse_normalize_nonnegative_integer( null ) );
		$this->assertFalse( sse_normalize_nonnegative_integer( array( 1 ) ) );
	}

	/**
	 * The form offers no limit, 100 MiB, 500 MiB, and 1 GiB.
	 *
	 * @return void
	 */
	public function test_offered_file_sizes(): void {
		$this->assertSame( array( 0, 104857600, 524288000, 1073741824 ), array_keys( sse_get_export_file_size_options() ) );

		foreach ( sse_get_export_file_size_options() as $label ) {
			$this->assertNotSame( '', $label );
		}
	}

	/**
	 * A request without the export action is not an export request.
	 *
	 * @return void
	 */
	public function test_a_request_without_the_export_action_is_ignored(): void {
		$this->assertFalse( sse_validate_export_request() );

		foreach ( array( 'sse_delete_export', '', array( 'sse_export_site' ) ) as $action ) {
			$_POST = array(
				'action'            => $action,
				'sse_max_file_size' => '104857600',
			);
			$this->assertFalse( sse_validate_export_request() );
		}
	}

	/**
	 * A size the form offers is returned as an integer; any other value means no limit.
	 *
	 * @param mixed $requested Value in the request.
	 * @param int   $expected  Size the export uses.
	 * @return void
	 */
	#[DataProvider( 'requested_sizes' )]
	public function test_requested_size( mixed $requested, int $expected ): void {
		$_POST = array( 'action' => 'sse_export_site' );
		if ( null !== $requested ) {
			$_POST['sse_max_file_size'] = $requested;
		}

		$this->assertSame( $expected, sse_validate_export_request() );
	}

	/**
	 * Request values and the size that results.
	 *
	 * @return array<string,array{mixed,int}>
	 */
	public static function requested_sizes(): array {
		return array(
			'no limit'             => array( '0', 0 ),
			'100 MiB'              => array( '104857600', 104857600 ),
			'500 MiB'              => array( '524288000', 524288000 ),
			'1 GiB'                => array( '1073741824', 1073741824 ),
			'with spaces'          => array( ' 104857600 ', 104857600 ),
			'missing'              => array( null, 0 ),
			'not offered'          => array( '99', 0 ),
			'scientific notation'  => array( '1e9', 0 ),
			'decimal'              => array( '104857600.0', 0 ),
			'negative'             => array( '-104857600', 0 ),
			'22 digits'            => array( '1048576001048576001048', 0 ),
			'text'                 => array( 'unlimited', 0 ),
			'an array'             => array( array( '104857600' ), 0 ),
			'leading zero'         => array( '0104857600', 0 ),
		);
	}

	/**
	 * A failed nonce check ends an export request before anything else happens.
	 *
	 * @return void
	 */
	public function test_an_export_request_with_a_bad_nonce_is_refused(): void {
		$GLOBALS['sse_test']['valid_nonce'] = false;
		$_POST                             = array( 'action' => 'sse_export_site' );

		$refusal = $this->expect_die( 'sse_validate_export_request' );

		$this->assertSame( 403, $refusal->status );
	}

	/**
	 * A user without the capability is refused with status 403.
	 *
	 * @return void
	 */
	public function test_an_export_request_without_the_capability_is_refused(): void {
		$GLOBALS['sse_test']['capabilities'] = array( 'edit_posts' );
		$_POST                              = array( 'action' => 'sse_export_site' );

		$refusal = $this->expect_die( 'sse_validate_export_request' );

		$this->assertSame( 403, $refusal->status );
		$this->assertSame( 'You do not have permission to perform this action.', $refusal->getMessage() );
	}

	/**
	 * A limit filter may set any positive whole number; everything else leaves the default.
	 *
	 * @param mixed $filtered Value the filter returns.
	 * @param int   $expected Limit that results.
	 * @return void
	 */
	#[DataProvider( 'filtered_limits' )]
	public function test_resource_limit_filter( mixed $filtered, int $expected ): void {
		add_filter(
			'sse_max_export_entries',
			static function () use ( $filtered ) {
				return $filtered;
			}
		);

		$this->assertSame( $expected, sse_get_export_resource_limit( 'sse_max_export_entries', 250000 ) );
	}

	/**
	 * Filter results and the limit that results.
	 *
	 * @return array<string,array{mixed,int}>
	 */
	public static function filtered_limits(): array {
		return array(
			'a lower limit'    => array( 10, 10 ),
			'a higher limit'   => array( 900000, 900000 ),
			'a numeric string' => array( '5000', 5000 ),
			'zero'             => array( 0, 250000 ),
			'negative'         => array( -1, 250000 ),
			'text'             => array( 'none', 250000 ),
			'null'             => array( null, 250000 ),
			'false'            => array( false, 250000 ),
			'an array'         => array( array( 10 ), 250000 ),
		);
	}

	/**
	 * Without a filter the default applies, and the filter receives the default.
	 *
	 * @return void
	 */
	public function test_resource_limit_default(): void {
		$this->assertSame( 1800, sse_get_export_resource_limit( 'sse_max_export_seconds', 1800 ) );

		$received = null;
		add_filter(
			'sse_max_export_seconds',
			static function ( $value ) use ( &$received ) {
				$received = $value;
				return $value;
			}
		);

		$this->assertSame( 1800, sse_get_export_resource_limit( 'sse_max_export_seconds', 1800 ) );
		$this->assertSame( 1800, $received );
	}

	/**
	 * Stored counts are accepted only when all five are whole, non-negative numbers.
	 *
	 * @return void
	 */
	public function test_skipped_entry_counts_are_all_or_nothing(): void {
		$empty = sse_get_empty_skipped_export_entry_counts();
		$this->assertSame( array( 'unreadable', 'links', 'special', 'large', 'changed' ), array_keys( $empty ) );
		$this->assertSame( array( 0, 0, 0, 0, 0 ), array_values( $empty ) );

		$stored = array(
			'unreadable' => '2',
			'links'      => 1,
			'special'    => 0,
			'large'      => 4,
			'changed'    => 0,
			'extra'      => 9,
		);
		$this->assertSame(
			array(
				'unreadable' => 2,
				'links'      => 1,
				'special'    => 0,
				'large'      => 4,
				'changed'    => 0,
			),
			sse_normalize_skipped_export_entry_counts( $stored )
		);

		$this->assertNull( sse_normalize_skipped_export_entry_counts( null ) );
		$this->assertNull( sse_normalize_skipped_export_entry_counts( 'text' ) );
		$this->assertNull( sse_normalize_skipped_export_entry_counts( array_diff_key( $stored, array( 'large' => 0 ) ) ), 'A missing count.' );
		$this->assertNull( sse_normalize_skipped_export_entry_counts( array( 'links' => -1 ) + $stored ), 'A negative count.' );
		$this->assertNull( sse_normalize_skipped_export_entry_counts( array( 'links' => 'some' ) + $stored ), 'A count that is not a number.' );
	}

	/**
	 * With nothing skipped, or no counts at all, the notice adds no line.
	 *
	 * @return void
	 */
	public function test_no_notice_line_when_nothing_was_left_out(): void {
		$this->assertSame( array(), sse_get_skipped_entries_notice_lines( null ) );
		$this->assertSame( array(), sse_get_skipped_entries_notice_lines( sse_get_empty_skipped_export_entry_counts() ) );
	}

	/**
	 * Each kind of skipped entry is named once, in the singular or the plural.
	 *
	 * @return void
	 */
	public function test_notice_lines_name_what_was_left_out(): void {
		$counts = array( 'unreadable' => 1 ) + sse_get_empty_skipped_export_entry_counts();
		$this->assertSame( array( 'Left out of the export: 1 unreadable file or directory.' ), sse_get_skipped_entries_notice_lines( $counts ) );

		$counts = array(
			'unreadable' => 2,
			'links'      => 1,
			'special'    => 0,
			'large'      => 0,
			'changed'    => 0,
		);
		$this->assertSame( array( 'Left out of the export: 2 unreadable files or directories and 1 symbolic link.' ), sse_get_skipped_entries_notice_lines( $counts ) );

		$counts = array(
			'unreadable' => 1,
			'links'      => 2,
			'special'    => 1,
			'large'      => 1234,
			'changed'    => 0,
		);
		$this->assertSame(
			array( 'Left out of the export: 1 unreadable file or directory, 2 symbolic links, 1 special file such as a socket or named pipe, and 1,234 files over the size limit.' ),
			sse_get_skipped_entries_notice_lines( $counts )
		);
	}

	/**
	 * Files that changed while they were read get their own line, also when nothing was left out.
	 *
	 * @return void
	 */
	public function test_changed_files_get_their_own_line(): void {
		$counts = array( 'changed' => 1 ) + sse_get_empty_skipped_export_entry_counts();
		$this->assertSame( array( '1 file changed while it was read and may be incomplete in the export.' ), sse_get_skipped_entries_notice_lines( $counts ) );

		$counts = array(
			'unreadable' => 0,
			'links'      => 0,
			'special'    => 0,
			'large'      => 1,
			'changed'    => 3,
		);
		$this->assertSame(
			array(
				'Left out of the export: 1 file over the size limit.',
				'3 files changed while they were read and may be incomplete in the export.',
			),
			sse_get_skipped_entries_notice_lines( $counts )
		);
	}
}
