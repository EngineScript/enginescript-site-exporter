<?php
/**
 * TAR format helpers: header, long-name, padding, and end-of-archive records.
 *
 * These functions only build strings. They read no file and call no WordPress
 * API, so the format can be checked on its own. The archive follows GNU tar
 * conventions: a long-name record for a path over 100 bytes, and a binary size
 * field for a file of 8 GiB or more.
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct execution of this component.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Formats a number as a NUL-terminated octal TAR header field.
 *
 * @since 2.1.1
 * @param int $value  Non-negative value.
 * @param int $length Field length in bytes, including the terminator.
 * @return string Field of exactly the given length.
 */
function sse_tar_format_octal_field( int $value, int $length ): string {
	$digits = $length - 1;

	return substr( str_pad( decoct( max( 0, $value ) ), $digits, '0', STR_PAD_LEFT ), -$digits ) . "\0";
}

/**
 * Formats the 12-byte TAR size field.
 *
 * Eleven octal digits hold sizes below 8 GiB. A larger size uses the GNU
 * binary form: a marker byte followed by the size as a big-endian integer.
 *
 * @since 2.1.1
 * @param int $size Entry size in bytes.
 * @return string Field of exactly 12 bytes.
 */
function sse_tar_format_size_field( int $size ): string {
	$size = max( 0, $size );
	if ( $size <= 077777777777 ) {
		return sse_tar_format_octal_field( $size, 12 );
	}

	return "\x80\0\0\0" . pack( 'J', $size );
}

/**
 * Builds one 512-byte TAR header.
 *
 * The name field holds at most 100 bytes. Callers store a longer path with
 * sse_tar_build_long_name_record() first; readers then ignore this field.
 *
 * @since 2.1.1
 * @param string $name  Archive path. A directory path ends in a slash.
 * @param int    $size  Content size in bytes.
 * @param int    $mode  Permission bits.
 * @param int    $mtime Modification time as a Unix timestamp.
 * @param string $type  One-byte type flag: "0" file, "5" directory, "L" long name.
 * @return string Header of exactly 512 bytes.
 */
function sse_tar_build_header( string $name, int $size, int $mode, int $mtime, string $type ): string {
	$header = str_pad( substr( $name, 0, 100 ), 100, "\0" )
		. sse_tar_format_octal_field( $mode & 0777, 8 )
		. sse_tar_format_octal_field( 0, 8 )
		. sse_tar_format_octal_field( 0, 8 )
		. sse_tar_format_size_field( $size )
		. sse_tar_format_octal_field( min( max( 0, $mtime ), 077777777777 ), 12 )
		. '        '
		. substr( $type . '0', 0, 1 )
		. str_repeat( "\0", 100 )
		. "ustar  \0"
		. str_repeat( "\0", 247 );

	// The checksum is the byte sum of the header with its own field read as eight spaces.
	$checksum = array_sum( sse_tar_get_byte_values( $header ) );

	return substr_replace( $header, sse_tar_format_octal_field( $checksum, 7 ) . ' ', 148, 8 );
}

/**
 * Gets the unsigned value of every byte in a string.
 *
 * @since 2.1.1
 * @param string $bytes Input bytes.
 * @return array<int,int> Byte values.
 */
function sse_tar_get_byte_values( string $bytes ): array {
	return array_map( 'ord', str_split( $bytes ) );
}

/**
 * Builds the GNU long-name record that precedes an entry with a long path.
 *
 * @since 2.1.1
 * @param string $path Full archive path of the entry that follows.
 * @return string Header and padded content, a multiple of 512 bytes.
 */
function sse_tar_build_long_name_record( string $path ): string {
	$content = $path . "\0";

	return sse_tar_build_header( '././@LongLink', strlen( $content ), 0644, 0, 'L' )
		. $content
		. sse_tar_get_padding( strlen( $content ) );
}

/**
 * Builds everything that precedes an entry's content.
 *
 * @since 2.1.1
 * @param string $path  Archive path. A directory path ends in a slash.
 * @param int    $size  Content size in bytes.
 * @param int    $mode  Permission bits.
 * @param int    $mtime Modification time as a Unix timestamp.
 * @param string $type  One-byte type flag: "0" file, "5" directory.
 * @return string One header, preceded by a long-name record when the path needs one.
 */
function sse_tar_build_entry_header( string $path, int $size, int $mode, int $mtime, string $type ): string {
	$long_name = strlen( $path ) > 100 ? sse_tar_build_long_name_record( $path ) : '';

	return $long_name . sse_tar_build_header( $path, $size, $mode, $mtime, $type );
}

/**
 * Gets the NUL bytes that pad content to the next 512-byte boundary.
 *
 * @since 2.1.1
 * @param int $size Content size in bytes.
 * @return string Between 0 and 511 NUL bytes.
 */
function sse_tar_get_padding( int $size ): string {
	$remainder = max( 0, $size ) % 512;

	return 0 === $remainder ? '' : str_repeat( "\0", 512 - $remainder );
}

/**
 * Gets the two empty blocks that end a TAR archive.
 *
 * @since 2.1.1
 * @return string 1,024 NUL bytes.
 */
function sse_tar_get_end_of_archive(): string {
	return str_repeat( "\0", 1024 );
}

/**
 * Gets the exact number of TAR bytes one entry occupies.
 *
 * @since 2.1.1
 * @param int    $size Content size in bytes.
 * @param string $path Archive path, with the trailing slash for a directory.
 * @return int Bytes written for the headers, the content, and its padding.
 */
function sse_tar_get_entry_bytes( int $size, string $path ): int {
	$size       = max( 0, $size );
	$path_bytes = strlen( $path );
	$bytes      = 512 + $size + ( ( 512 - ( $size % 512 ) ) % 512 );
	if ( $path_bytes > 100 ) {
		$name_bytes = $path_bytes + 1;
		$bytes     += 512 + $name_bytes + ( ( 512 - ( $name_bytes % 512 ) ) % 512 );
	}

	return $bytes;
}
