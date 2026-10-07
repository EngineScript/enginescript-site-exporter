<?php
/**
 * Tests for the TAR format functions in includes/tar.php.
 *
 * @package EngineScript_Site_Exporter
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The files archive is written byte by byte by the plugin, so its layout is checked byte by byte.
 */
final class TarFormatTest extends SseTestCase {

	/**
	 * Read one field of a header.
	 *
	 * @param string $header Header block.
	 * @param int    $offset Field offset.
	 * @param int    $length Field length.
	 * @return string
	 */
	private function field( string $header, int $offset, int $length ): string {
		return substr( $header, $offset, $length );
	}

	/**
	 * An octal field is zero-padded and ends in a NUL byte.
	 *
	 * @return void
	 */
	public function test_octal_field_is_zero_padded_and_nul_terminated(): void {
		$this->assertSame( "0000644\0", sse_tar_format_octal_field( 0644, 8 ) );
		$this->assertSame( "00000000000\0", sse_tar_format_octal_field( 0, 12 ) );
		$this->assertSame( "0000000\0", sse_tar_format_octal_field( -5, 8 ), 'A negative value is written as zero.' );
	}

	/**
	 * A value that does not fit keeps its low digits and never grows the field.
	 *
	 * @return void
	 */
	public function test_octal_field_never_exceeds_its_length(): void {
		$field = sse_tar_format_octal_field( PHP_INT_MAX, 8 );

		$this->assertSame( 8, strlen( $field ) );
		$this->assertSame( "7777777\0", $field );
	}

	/**
	 * Sizes up to 8 GiB minus one byte are octal; larger sizes use the base-256 form.
	 *
	 * @return void
	 */
	public function test_size_field_switches_to_base_256_above_the_octal_limit(): void {
		$limit = 077777777777;

		$this->assertSame( "77777777777\0", sse_tar_format_size_field( $limit ) );

		$large = sse_tar_format_size_field( $limit + 1 );
		$this->assertSame( 12, strlen( $large ) );
		$this->assertSame( "\x80", $large[0], 'The first byte marks the base-256 form.' );
		$this->assertSame( $limit + 1, unpack( 'J', substr( $large, 4 ) )[1] );
	}

	/**
	 * Every header field sits at its offset in a 512-byte block.
	 *
	 * @return void
	 */
	public function test_header_layout(): void {
		$header = sse_tar_build_header( 'wp-content/index.php', 28, 0100644, 1700000000, '0' );

		$this->assertSame( 512, strlen( $header ) );
		$this->assertSame( 'wp-content/index.php', rtrim( $this->field( $header, 0, 100 ), "\0" ) );
		$this->assertSame( "0000644\0", $this->field( $header, 100, 8 ), 'Only the permission bits of the mode are written.' );
		$this->assertSame( "0000000\0", $this->field( $header, 108, 8 ), 'The owner is not recorded.' );
		$this->assertSame( "0000000\0", $this->field( $header, 116, 8 ), 'The group is not recorded.' );
		$this->assertSame( "00000000034\0", $this->field( $header, 124, 12 ) );
		$this->assertSame( 1700000000, octdec( rtrim( $this->field( $header, 136, 12 ), "\0" ) ) );
		$this->assertSame( '0', $this->field( $header, 156, 1 ) );
		$this->assertSame( str_repeat( "\0", 100 ), $this->field( $header, 157, 100 ), 'No link name is written.' );
		$this->assertSame( "ustar  \0", $this->field( $header, 257, 8 ) );
		$this->assertSame( str_repeat( "\0", 247 ), $this->field( $header, 265, 247 ) );
	}

	/**
	 * The checksum is the byte sum of the header with the checksum field read as spaces.
	 *
	 * @return void
	 */
	public function test_header_checksum_matches_the_header_bytes(): void {
		$header = sse_tar_build_header( 'readme.html', 7425, 0644, 1234567890, '0' );
		$field  = $this->field( $header, 148, 8 );

		$expected = array_sum( array_map( 'ord', str_split( substr_replace( $header, '        ', 148, 8 ) ) ) );

		$this->assertMatchesRegularExpression( '/\A[0-7]{6}\0 \z/', $field );
		$this->assertSame( $expected, octdec( substr( $field, 0, 6 ) ) );
	}

	/**
	 * Changing any input changes the checksum.
	 *
	 * @return void
	 */
	public function test_header_checksum_follows_the_content(): void {
		$first  = sse_tar_build_header( 'a.txt', 1, 0644, 0, '0' );
		$second = sse_tar_build_header( 'b.txt', 1, 0644, 0, '0' );

		$this->assertNotSame( $this->field( $first, 148, 8 ), $this->field( $second, 148, 8 ) );
	}

	/**
	 * The type flag is one byte; a directory is "5" and an empty type is a regular file.
	 *
	 * @return void
	 */
	public function test_header_type_flag(): void {
		$this->assertSame( '5', $this->field( sse_tar_build_header( 'dir/', 0, 0755, 0, '5' ), 156, 1 ) );
		$this->assertSame( 'L', $this->field( sse_tar_build_header( 'x', 0, 0644, 0, 'L' ), 156, 1 ) );
		$this->assertSame( '0', $this->field( sse_tar_build_header( 'x', 0, 0644, 0, '' ), 156, 1 ) );
		$this->assertSame( '5', $this->field( sse_tar_build_header( 'x', 0, 0644, 0, '57' ), 156, 1 ) );
	}

	/**
	 * A modification time outside the field's range is clamped.
	 *
	 * @return void
	 */
	public function test_header_modification_time_is_clamped(): void {
		$this->assertSame( "00000000000\0", $this->field( sse_tar_build_header( 'x', 0, 0644, -10, '0' ), 136, 12 ) );
		$this->assertSame( "77777777777\0", $this->field( sse_tar_build_header( 'x', 0, 0644, PHP_INT_MAX, '0' ), 136, 12 ) );
	}

	/**
	 * The name field holds the first 100 bytes of a longer name.
	 *
	 * @return void
	 */
	public function test_header_name_field_holds_at_most_100_bytes(): void {
		$name   = str_repeat( 'a', 60 ) . '/' . str_repeat( 'b', 60 );
		$header = sse_tar_build_header( $name, 0, 0644, 0, '0' );

		$this->assertSame( substr( $name, 0, 100 ), $this->field( $header, 0, 100 ) );
	}

	/**
	 * Padding completes the last 512-byte block and is empty on a boundary.
	 *
	 * @param int $size     Content size.
	 * @param int $expected Expected padding length.
	 * @return void
	 */
	#[DataProvider( 'padding_cases' )]
	public function test_padding_completes_the_block( int $size, int $expected ): void {
		$padding = sse_tar_get_padding( $size );

		$this->assertSame( $expected, strlen( $padding ) );
		$this->assertSame( str_repeat( "\0", $expected ), $padding );
		$this->assertSame( 0, ( max( 0, $size ) + $expected ) % 512 );
	}

	/**
	 * Sizes and the padding each needs.
	 *
	 * @return array<string,array{int,int}>
	 */
	public static function padding_cases(): array {
		return array(
			'empty'            => array( 0, 0 ),
			'one byte'         => array( 1, 511 ),
			'one short'        => array( 511, 1 ),
			'exactly a block'  => array( 512, 0 ),
			'one over'         => array( 513, 511 ),
			'several blocks'   => array( 4096, 0 ),
			'negative is zero' => array( -1, 0 ),
		);
	}

	/**
	 * An archive ends with two empty blocks.
	 *
	 * @return void
	 */
	public function test_end_of_archive_is_two_empty_blocks(): void {
		$this->assertSame( str_repeat( "\0", 1024 ), sse_tar_get_end_of_archive() );
	}

	/**
	 * A path of up to 100 bytes needs one header and no long-name record.
	 *
	 * @return void
	 */
	public function test_entry_header_for_a_short_path_is_one_block(): void {
		$path = str_repeat( 'p', 100 );

		$this->assertSame( sse_tar_build_header( $path, 5, 0644, 9, '0' ), sse_tar_build_entry_header( $path, 5, 0644, 9, '0' ) );
	}

	/**
	 * A path over 100 bytes is preceded by a GNU long-name record that holds the whole path.
	 *
	 * @return void
	 */
	public function test_entry_header_for_a_long_path_carries_the_whole_path(): void {
		$path  = 'wp-content/uploads/' . str_repeat( 'n', 150 ) . '.jpg';
		$entry = sse_tar_build_entry_header( $path, 3, 0644, 0, '0' );

		$this->assertSame( 0, strlen( $entry ) % 512 );
		$this->assertSame( 1536, strlen( $entry ), 'Long-name header, one block of name, and the entry header.' );

		$long_header = substr( $entry, 0, 512 );
		$this->assertSame( '././@LongLink', rtrim( $this->field( $long_header, 0, 100 ), "\0" ) );
		$this->assertSame( 'L', $this->field( $long_header, 156, 1 ) );
		$this->assertSame( strlen( $path ) + 1, octdec( rtrim( $this->field( $long_header, 124, 12 ), "\0" ) ), 'The record size counts the closing NUL byte.' );
		$this->assertSame( $path . "\0", substr( $entry, 512, strlen( $path ) + 1 ) );

		$this->assertSame( sse_tar_build_header( $path, 3, 0644, 0, '0' ), substr( $entry, 1024 ) );
	}

	/**
	 * A path of 101 bytes is the first that needs the long-name record.
	 *
	 * @return void
	 */
	public function test_long_name_record_starts_at_101_bytes(): void {
		$this->assertSame( 512, strlen( sse_tar_build_entry_header( str_repeat( 'x', 100 ), 0, 0644, 0, '0' ) ) );
		$this->assertSame( 1536, strlen( sse_tar_build_entry_header( str_repeat( 'x', 101 ), 0, 0644, 0, '0' ) ) );
	}

	/**
	 * The projected size of an entry equals the bytes that are written for it.
	 *
	 * @param int    $size Content size.
	 * @param string $path Archive path.
	 * @return void
	 */
	#[DataProvider( 'entry_cases' )]
	public function test_projected_entry_bytes_equal_the_written_bytes( int $size, string $path ): void {
		$written = strlen( sse_tar_build_entry_header( $path, $size, 0644, 0, '0' ) ) + $size + strlen( sse_tar_get_padding( $size ) );

		$this->assertSame( $written, sse_tar_get_entry_bytes( $size, $path ) );
	}

	/**
	 * Sizes and paths on both sides of each boundary.
	 *
	 * @return array<string,array{int,string}>
	 */
	public static function entry_cases(): array {
		return array(
			'empty file'                 => array( 0, 'a' ),
			'one byte'                   => array( 1, 'a' ),
			'block boundary'             => array( 512, 'a' ),
			'over a block'               => array( 513, 'a' ),
			'name of 100 bytes'          => array( 10, str_repeat( 'n', 100 ) ),
			'name of 101 bytes'          => array( 10, str_repeat( 'n', 101 ) ),
			'name that fills a block'    => array( 10, str_repeat( 'n', 511 ) ),
			'name one over a block'      => array( 10, str_repeat( 'n', 512 ) ),
			'long name and large file'   => array( 1048577, str_repeat( 'n', 300 ) ),
			'directory with a long name' => array( 0, str_repeat( 'd', 180 ) . '/' ),
		);
	}

	/**
	 * Byte values are unsigned, also for bytes above 127.
	 *
	 * @return void
	 */
	public function test_byte_values_are_unsigned(): void {
		$this->assertSame( array( 0, 65, 128, 255 ), sse_tar_get_byte_values( "\x00A\x80\xFF" ) );
	}

	/**
	 * An archive built from these functions is read back by PHP's own TAR reader.
	 *
	 * @return void
	 */
	public function test_built_archive_is_read_by_phar(): void {
		if ( ! class_exists( PharData::class ) ) {
			$this->markTestSkipped( 'The Phar extension is not loaded.' );
		}

		$files = array(
			'readme.txt'            => "Hello\n",
			'wp-content/empty.txt'  => '',
			'wp-content/block.bin'  => str_repeat( 'x', 512 ),
			'wp-content/binary.bin' => "\x00\x01\xFE\xFF",
		);

		$archive = sse_tar_build_entry_header( 'wp-content/', 0, 0755, 1700000000, '5' );
		foreach ( $files as $name => $content ) {
			$archive .= sse_tar_build_entry_header( $name, strlen( $content ), 0644, 1700000000, '0' ) . $content . sse_tar_get_padding( strlen( $content ) );
		}
		$archive .= sse_tar_get_end_of_archive();

		$path = $this->make_temporary_directory() . DIRECTORY_SEPARATOR . 'built.tar';
		file_put_contents( $path, $archive );

		$reader = new PharData( $path );
		foreach ( $files as $name => $content ) {
			$this->assertTrue( isset( $reader[ $name ] ), $name . ' is in the archive.' );
			$this->assertSame( $content, $reader[ $name ]->getContent(), $name . ' has its content.' );
		}
		$this->assertTrue( $reader['wp-content']->isDir() );
		unset( $reader );
	}
}
