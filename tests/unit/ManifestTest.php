<?php
/**
 * Tests for the informational lines of manifest.txt.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * EngineScript's importer reads the site address from home_url, so it must be the stored address.
 */
final class ManifestTest extends SseTestCase {

	/**
	 * The addresses are the stored home and siteurl options, unchanged.
	 *
	 * @return void
	 */
	public function test_addresses_are_the_stored_options(): void {
		update_option( 'home', 'http://www.example.com/blog' );
		update_option( 'siteurl', 'http://www.example.com/blog/wp' );

		$this->assertSame(
			"home_url=http://www.example.com/blog\nsite_url=http://www.example.com/blog/wp\ntable_prefix=\nwp_config_in_archive=yes\n",
			esse_get_engine_script_manifest_site_lines( true )
		);
	}

	/**
	 * A line break in a stored value cannot start a new manifest line.
	 *
	 * @return void
	 */
	public function test_control_characters_are_removed(): void {
		update_option( 'home', "https://example.com\r\nsite_url=https://attacker.example" );
		update_option( 'siteurl', 'https://example.com' );

		$lines = explode( "\n", rtrim( esse_get_engine_script_manifest_site_lines( false ), "\n" ) );

		$this->assertSame( 'home_url=https://example.comsite_url=https://attacker.example', $lines[0] );
		$this->assertSame( 'site_url=https://example.com', $lines[1] );
		$this->assertSame( 'wp_config_in_archive=no', $lines[3] );
		$this->assertCount( 4, $lines );
	}

	/**
	 * Missing options give empty values rather than an error.
	 *
	 * @return void
	 */
	public function test_missing_options_give_empty_values(): void {
		$this->assertStringStartsWith( "home_url=\nsite_url=\n", esse_get_engine_script_manifest_site_lines( true ) );
	}
}
