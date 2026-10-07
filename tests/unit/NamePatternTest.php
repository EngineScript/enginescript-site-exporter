<?php
/**
 * Tests for archive names, private directory names, and the site identifier.
 *
 * @package EngineScript_Site_Exporter
 */

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Download, delete, and cleanup trust these names, so each rule is pinned down.
 */
final class NamePatternTest extends SseTestCase {

	/**
	 * A well-formed private directory name.
	 *
	 * @return string
	 */
	private function directory_name(): string {
		return 'export-20260101_120000-' . str_repeat( 'a1', 16 );
	}

	/**
	 * The archive name is the identifier, the marker, and the timestamp.
	 *
	 * @return void
	 */
	public function test_archive_filename_is_built_from_identifier_marker_and_timestamp(): void {
		$this->assertSame(
			'example.com_enginescript_site_export_20260101_120000.zip',
			sse_get_engine_script_archive_filename( 'example.com', '20260101_120000' )
		);
	}

	/**
	 * Names the plugin generates are accepted.
	 *
	 * @param string $filename File name.
	 * @return void
	 */
	#[DataProvider( 'accepted_archive_names' )]
	public function test_generated_archive_names_are_accepted( string $filename ): void {
		$this->assertTrue( sse_is_engine_script_archive_filename( $filename ) );
		$this->assertTrue( sse_validate_filename_format( $filename ) );
	}

	/**
	 * Accepted archive names.
	 *
	 * @return array<string,array{string}>
	 */
	public static function accepted_archive_names(): array {
		return array(
			'plain host'       => array( 'example.com_enginescript_site_export_20260101_120000.zip' ),
			'host with hyphen' => array( 'my-site.example.co.uk_enginescript_site_export_20261231_235959.zip' ),
			'punycode host'    => array( 'xn--bcher-kva.example_enginescript_site_export_20260101_000000.zip' ),
			'fallback name'    => array( 'wordpress-site_enginescript_site_export_20260101_000000.zip' ),
			'underscore'       => array( 'a_b_enginescript_site_export_20260101_000000.zip' ),
		);
	}

	/**
	 * Anything else is refused.
	 *
	 * @param string $filename File name.
	 * @return void
	 */
	#[DataProvider( 'refused_archive_names' )]
	public function test_other_archive_names_are_refused( string $filename ): void {
		$this->assertFalse( sse_is_engine_script_archive_filename( $filename ) );
	}

	/**
	 * Refused archive names.
	 *
	 * @return array<string,array{string}>
	 */
	public static function refused_archive_names(): array {
		$name = 'example.com_enginescript_site_export_20260101_120000';

		return array(
			'empty'                 => array( '' ),
			'no extension'          => array( $name ),
			'other extension'       => array( $name . '.tar' ),
			'double extension'      => array( $name . '.zip.php' ),
			'upper-case extension'  => array( $name . '.ZIP' ),
			'no identifier'         => array( '_enginescript_site_export_20260101_120000.zip' ),
			'other marker'          => array( 'example.com_site_export_20260101_120000.zip' ),
			'short date'            => array( 'example.com_enginescript_site_export_2026011_120000.zip' ),
			'letters in the time'   => array( 'example.com_enginescript_site_export_20260101_12000a.zip' ),
			'space'                 => array( 'example com_enginescript_site_export_20260101_120000.zip' ),
			'directory'             => array( 'dir/' . $name . '.zip' ),
			'parent directory'      => array( '../' . $name . '.zip' ),
			'null byte'             => array( $name . ".zip\0.txt" ),
			'trailing line break'   => array( $name . ".zip\n" ),
			'leading line break'    => array( "\n" . $name . '.zip' ),
			'markup'                => array( '<b>_enginescript_site_export_20260101_120000.zip' ),
			'text after the name'   => array( $name . '.zip.bak' ),
			'text before the name'  => array( 'x ' . $name . '.zip' ),
			'non-ASCII identifier'  => array( "b\u{00FC}cher_enginescript_site_export_20260101_120000.zip" ),
			'percent encoding'      => array( 'a%2Fb_enginescript_site_export_20260101_120000.zip' ),
			'full-width digits'     => array( "a_enginescript_site_export_\u{FF12}0260101_120000.zip" ),
		);
	}

	/**
	 * The file name check names what is wrong.
	 *
	 * @return void
	 */
	public function test_filename_format_errors_are_specific(): void {
		$empty = sse_validate_filename_format( '' );
		$this->assertInstanceOf( WP_Error::class, $empty );
		$this->assertSame( 'invalid_request', $empty->get_error_code() );

		foreach ( array( 'a/b_enginescript_site_export_20260101_120000.zip', 'a\\b_enginescript_site_export_20260101_120000.zip' ) as $with_separator ) {
			$error = sse_validate_filename_format( $with_separator );
			$this->assertInstanceOf( WP_Error::class, $error );
			$this->assertSame( 'invalid_filename', $error->get_error_code() );
		}

		$format = sse_validate_filename_format( 'backup.zip' );
		$this->assertInstanceOf( WP_Error::class, $format );
		$this->assertSame( 'invalid_format', $format->get_error_code() );
	}

	/**
	 * The private directory pattern is anchored and carries the configured prefix.
	 *
	 * @return void
	 */
	public function test_private_directory_names_the_plugin_generates_are_accepted(): void {
		$this->assertTrue( sse_is_export_private_directory_name( $this->directory_name() ) );
		$this->assertTrue( sse_validate_export_directory_name_format( $this->directory_name() ) );
		$this->assertStringStartsWith( '/^' . preg_quote( SSE_EXPORT_PRIVATE_DIR_PREFIX, '/' ), sse_get_export_private_directory_name_pattern() );
	}

	/**
	 * Other directory names are refused.
	 *
	 * @param string $name Directory name.
	 * @return void
	 */
	#[DataProvider( 'refused_directory_names' )]
	public function test_other_private_directory_names_are_refused( string $name ): void {
		$this->assertFalse( sse_is_export_private_directory_name( $name ) );

		$error = sse_validate_export_directory_name_format( $name );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_export_directory', $error->get_error_code() );
	}

	/**
	 * Refused directory names.
	 *
	 * @return array<string,array{string}>
	 */
	public static function refused_directory_names(): array {
		$random = str_repeat( 'a1', 16 );

		return array(
			'empty'                => array( '' ),
			'no prefix'            => array( '20260101_120000-' . $random ),
			'other prefix'         => array( 'backup-20260101_120000-' . $random ),
			'short random part'    => array( 'export-20260101_120000-' . substr( $random, 1 ) ),
			'long random part'     => array( 'export-20260101_120000-' . $random . 'a' ),
			'upper-case hex'       => array( 'export-20260101_120000-' . strtoupper( $random ) ),
			'not hex'              => array( 'export-20260101_120000-' . str_repeat( 'g', 32 ) ),
			'short date'           => array( 'export-2026011_120000-' . $random ),
			'parent directory'     => array( '../export-20260101_120000-' . $random ),
			'subdirectory'         => array( 'export-20260101_120000-' . $random . '/x' ),
			'backslash'            => array( 'export-20260101_120000-' . $random . '\\x' ),
			'text before'          => array( 'x export-20260101_120000-' . $random ),
			'leading line break'   => array( "\nexport-20260101_120000-" . $random ),
			'null byte'            => array( 'export-20260101_120000-' . $random . "\0" ),
			'trailing line break'  => array( 'export-20260101_120000-' . $random . "\n" ),
			'trailing return'      => array( 'export-20260101_120000-' . $random . "\r\n" ),
			'two line breaks'      => array( 'export-20260101_120000-' . $random . "\n\n" ),
			'the base directory'   => array( 'enginescript-site-exporter-exports' ),
			'current directory'    => array( '.' ),
		);
	}

	/**
	 * A generated directory name passes the plugin's own check and does not repeat.
	 *
	 * @return void
	 */
	public function test_generated_private_directory_names_are_valid_and_differ(): void {
		$first  = sse_generate_private_export_directory_name();
		$second = sse_generate_private_export_directory_name();

		$this->assertTrue( sse_is_export_private_directory_name( $first ) );
		$this->assertTrue( sse_is_export_private_directory_name( $second ) );
		$this->assertNotSame( $first, $second );
	}

	/**
	 * The site identifier keeps a plain host, and reduces anything else to letters, digits, dots, and hyphens.
	 *
	 * @param string $host     Site host.
	 * @param string $expected Expected identifier.
	 * @return void
	 */
	#[DataProvider( 'site_identifier_cases' )]
	public function test_site_identifier( string $host, string $expected ): void {
		$this->assertSame( $expected, sse_build_export_site_identifier( $host ) );
	}

	/**
	 * Hosts and their identifiers.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function site_identifier_cases(): array {
		return array(
			'plain host'            => array( 'www.example.com', 'www.example.com' ),
			'short middle label'    => array( 'www.acme.test', 'www.acme.test' ),
			'upper case'            => array( 'Example.COM', 'example.com' ),
			'underscore'            => array( 'a_b.example', 'a-b.example' ),
			'port and space'        => array( 'exa mple.com:8080', 'exa-mple.com-8080' ),
			'repeated dots'         => array( 'a..b', 'a.b' ),
			'leading and trailing'  => array( '-x-', 'x' ),
			'only dots'             => array( '...', 'wordpress-site' ),
			'empty'                 => array( '', 'wordpress-site' ),
			'path characters'       => array( '../../etc/passwd', 'etc-passwd' ),
			'markup'                => array( '<script>', 'script' ),
		);
	}

	/**
	 * A host with non-ASCII letters becomes its ASCII form when the intl extension is there.
	 *
	 * @return void
	 */
	public function test_site_identifier_of_an_international_host(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'The intl extension is not loaded.' );
		}

		$this->assertSame( 'xn--bcher-kva.example', sse_build_export_site_identifier( "b\u{00FC}cher.example" ) );
	}

	/**
	 * The identifier is at most 100 bytes and never ends in a dot or a hyphen.
	 *
	 * @return void
	 */
	public function test_site_identifier_is_cut_to_100_bytes(): void {
		$identifier = sse_build_export_site_identifier( str_repeat( 'a', 98 ) . '.-' . str_repeat( 'b', 50 ) . '.com' );

		$this->assertSame( str_repeat( 'a', 98 ), $identifier, 'The cut left a dot and a hyphen at the end; both are removed.' );
		$this->assertLessThanOrEqual( 100, strlen( $identifier ) );
	}

	/**
	 * Whatever the host is, the archive name built from its identifier passes the archive name check.
	 *
	 * @param string $host Site host.
	 * @return void
	 */
	#[DataProvider( 'hostile_hosts' )]
	public function test_every_identifier_gives_a_valid_archive_name( string $host ): void {
		$filename = sse_get_engine_script_archive_filename( sse_build_export_site_identifier( $host ), '20260101_120000' );

		$this->assertTrue( sse_is_engine_script_archive_filename( $filename ), $filename );
	}

	/**
	 * Hosts that must not break the archive name.
	 *
	 * @return array<string,array{string}>
	 */
	public static function hostile_hosts(): array {
		return array(
			'empty'            => array( '' ),
			'plain'            => array( 'example.com' ),
			'underscores'      => array( '__' ),
			'marker in host'   => array( 'a_enginescript_site_export_20260101_120000.zip' ),
			'separators'       => array( '/\\:*?"<>|' ),
			'line break'       => array( "exam\nple.com" ),
			'null byte'        => array( "exam\0ple.com" ),
			'very long'        => array( str_repeat( 'long-label.', 40 ) . 'com' ),
			'non-ASCII'        => array( "\u{65E5}\u{672C}.example" ),
			'ip with port'     => array( '192.168.1.10:8443' ),
			'ipv6'             => array( '[2001:db8::1]' ),
		);
	}
}
