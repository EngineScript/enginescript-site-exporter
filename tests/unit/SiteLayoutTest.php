<?php
/**
 * Tests for the site layout check.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * EngineScript only hosts sites at the root of their domain, so other sites are not exported.
 */
final class SiteLayoutTest extends SseTestCase {

	/**
	 * Sites at the root of their domain can be exported, with or without www.
	 *
	 * @return void
	 */
	public function test_root_sites_can_be_exported(): void {
		$addresses = array(
			array( 'https://example.com', 'https://example.com' ),
			array( 'https://www.example.com/', 'https://www.example.com/' ),
			array( 'http://example.com:8080', 'http://example.com:8080' ),
		);

		foreach ( $addresses as list( $home, $siteurl ) ) {
			update_option( 'home', $home );
			update_option( 'siteurl', $siteurl );
			$this->assertNull( esse_get_site_layout_error(), "{$home} with {$siteurl}" );
		}
	}

	/**
	 * Sites in a subfolder, and sites with WordPress in its own folder, are not exported.
	 *
	 * @return void
	 */
	public function test_subfolder_sites_are_not_exported(): void {
		$addresses = array(
			array( 'https://example.com/blog', 'https://example.com/blog' ),
			array( 'https://example.com', 'https://example.com/wp' ),
			array( 'https://example.com/shop/', 'https://example.com/shop/wp' ),
		);

		foreach ( $addresses as list( $home, $siteurl ) ) {
			update_option( 'home', $home );
			update_option( 'siteurl', $siteurl );
			$error = esse_get_site_layout_error();

			$this->assertIsString( $error, "{$home} with {$siteurl}" );
			$this->assertStringContainsString( "Site Address: {$home}. WordPress Address: {$siteurl}.", $error );
		}
	}
}
