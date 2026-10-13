<?php
/**
 * Tests for what the plugin registers with WordPress.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * Hooks, menus, assets, and the capability rule.
 */
final class PluginWiringTest extends SseTestCase {

	/**
	 * Loading the plugin file registers the start-up hook and the two lifecycle callbacks.
	 *
	 * @return void
	 */
	public function test_loading_the_plugin_registers_its_entry_points(): void {
		$loaded = $GLOBALS['sse_test_load_time'];

		$this->assertContains( 'esse_init_plugin', $loaded['hooks']['plugins_loaded'][10] );
		$this->assertSame( array( array( ESSE_PLUGIN_FILE, 'esse_activate_plugin' ) ), $loaded['activation'] );
		$this->assertSame( array( array( ESSE_PLUGIN_FILE, 'esse_deactivate_plugin' ) ), $loaded['deactivation'] );
		$this->assertSame( array(), $loaded['uninstall'], 'The uninstall callback is registered on activation, not on every load.' );
		$this->assertSame( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'enginescript-site-exporter.php', ESSE_PLUGIN_FILE );
	}

	/**
	 * Start-up connects every route and every scheduled event to a function that exists.
	 *
	 * @return void
	 */
	public function test_start_up_registers_every_handler(): void {
		esse_init_plugin();

		$expected = array(
			'admin_menu'                     => 'esse_admin_menu',
			'network_admin_menu'             => 'esse_network_admin_menu',
			'admin_enqueue_scripts'          => 'esse_enqueue_admin_assets',
			'admin_post_sse_export_site'     => 'esse_handle_export',
			'admin_post_sse_secure_download' => 'esse_handle_secure_download',
			'admin_post_sse_delete_export'   => 'esse_handle_export_deletion',
			'sse_delete_export_file'         => 'esse_delete_export_file_handler',
			'sse_bulk_cleanup_exports'       => 'esse_bulk_cleanup_exports_handler',
			'sse_recover_expired_export'     => 'esse_recover_expired_export_handler',
			'sse_export_housekeeping'        => 'esse_export_housekeeping_handler',
		);

		foreach ( $expected as $hook => $callback ) {
			$this->assertSame( 10, sse_test_hook_priority( $hook, $callback ), $hook );
			$this->assertTrue( function_exists( $callback ), $callback . ' exists.' );
		}

		$registered = array_keys( $GLOBALS['sse_test']['hooks'] );
		$listed     = array_keys( $expected );
		sort( $registered );
		sort( $listed );
		$this->assertSame( $listed, $registered, 'No hook is registered beyond the ones listed here.' );
	}

	/**
	 * No request reaches a handler without a login: no "nopriv" route is registered.
	 *
	 * @return void
	 */
	public function test_no_route_is_open_to_logged_out_visitors(): void {
		esse_init_plugin();

		foreach ( array_keys( $GLOBALS['sse_test']['hooks'] ) as $hook ) {
			$this->assertStringNotContainsString( 'nopriv', $hook );
		}
	}

	/**
	 * Every scheduled event the plugin knows of has a handler, so deactivation clears all of them.
	 *
	 * @return void
	 */
	public function test_every_scheduled_hook_name_has_a_handler(): void {
		esse_init_plugin();

		$names = esse_get_scheduled_hook_names();
		$this->assertCount( 4, $names );
		foreach ( $names as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['sse_test']['hooks'], $hook );
		}
	}

	/**
	 * Start-up schedules the daily housekeeping once.
	 *
	 * @return void
	 */
	public function test_housekeeping_is_scheduled_once(): void {
		esse_init_plugin();

		$event = $GLOBALS['sse_test']['recurring_event']['sse_export_housekeeping'];
		$this->assertSame( 'daily', $event['recurrence'] );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $event['time'], 5 );

		$GLOBALS['sse_test']['recurring_event']['sse_export_housekeeping']['time'] = 123;
		esse_schedule_export_housekeeping();
		$this->assertSame( 123, $GLOBALS['sse_test']['recurring_event']['sse_export_housekeeping']['time'], 'An existing event is left as it is.' );
	}

	/**
	 * On a network only the main site runs the housekeeping; another site removes its own event.
	 *
	 * @return void
	 */
	public function test_housekeeping_belongs_to_the_main_site_of_a_network(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$GLOBALS['sse_test']['main_site'] = false;

		esse_schedule_export_housekeeping();
		$this->assertArrayNotHasKey( 'sse_export_housekeeping', $GLOBALS['sse_test']['recurring_event'] );

		wp_schedule_event( 500, 'daily', 'sse_export_housekeeping' );
		esse_schedule_export_housekeeping();
		$this->assertArrayNotHasKey( 'sse_export_housekeeping', $GLOBALS['sse_test']['recurring_event'], 'An event left by an earlier version is removed.' );

		$GLOBALS['sse_test']['main_site'] = true;
		esse_schedule_export_housekeeping();
		$this->assertArrayHasKey( 'sse_export_housekeeping', $GLOBALS['sse_test']['recurring_event'] );
	}

	/**
	 * On a single site the page is under Tools, for users who can manage options.
	 *
	 * @return void
	 */
	public function test_single_site_menu(): void {
		esse_network_admin_menu();
		$this->assertSame( array(), $GLOBALS['sse_test']['pages'], 'The network menu adds nothing on a single site.' );

		esse_admin_menu();

		$this->assertCount( 1, $GLOBALS['sse_test']['pages'] );
		$page = $GLOBALS['sse_test']['pages'][0];
		$this->assertSame( 'tools.php', $page['parent_slug'] );
		$this->assertSame( 'manage_options', $page['capability'] );
		$this->assertSame( 'enginescript-site-exporter', $page['menu_slug'] );
		$this->assertSame( 'esse_exporter_page_html', $page['callback'] );
		$this->assertSame( 'tools_page_enginescript-site-exporter', esse_get_exporter_page_hook_suffix() );
	}

	/**
	 * On a network the page is under Settings in Network Admin, for users who can manage the network.
	 *
	 * @return void
	 */
	public function test_network_menu(): void {
		$GLOBALS['sse_test']['multisite'] = true;
		$GLOBALS['sse_test']['page_hook'] = 'settings_page_enginescript-site-exporter-network';

		esse_admin_menu();
		$this->assertSame( array(), $GLOBALS['sse_test']['pages'], 'No site of a network gets the page in its own dashboard.' );

		esse_network_admin_menu();

		$this->assertCount( 1, $GLOBALS['sse_test']['pages'] );
		$page = $GLOBALS['sse_test']['pages'][0];
		$this->assertSame( 'settings.php', $page['parent_slug'] );
		$this->assertSame( 'manage_network_options', $page['capability'] );
		$this->assertSame( 'esse_exporter_page_html', $page['callback'] );
		$this->assertSame( 'settings_page_enginescript-site-exporter-network', esse_get_exporter_page_hook_suffix() );
	}

	/**
	 * When WordPress does not add the page, no page hook is remembered.
	 *
	 * @return void
	 */
	public function test_a_page_that_was_not_added_leaves_no_hook(): void {
		esse_admin_menu();
		$this->assertNotSame( '', esse_get_exporter_page_hook_suffix() );

		$GLOBALS['sse_test']['page_hook'] = false;
		esse_admin_menu();
		$this->assertSame( '', esse_get_exporter_page_hook_suffix() );
	}

	/**
	 * The stylesheet and the script load on the exporter page and on no other.
	 *
	 * @return void
	 */
	public function test_assets_load_on_the_exporter_page_only(): void {
		esse_enqueue_admin_assets( 'tools_page_enginescript-site-exporter' );
		$this->assertSame( array(), $GLOBALS['sse_test']['scripts'], 'Before the page is registered nothing loads.' );

		esse_enqueue_admin_assets( '' );
		$this->assertSame( array(), $GLOBALS['sse_test']['scripts'], 'An empty page hook never matches.' );

		esse_admin_menu();
		foreach ( array( 'index.php', 'tools.php', 'plugins.php', 'tools_page_other-plugin', 'tools_page_enginescript-site-exporter-2' ) as $other_page ) {
			esse_enqueue_admin_assets( $other_page );
		}
		$this->assertSame( array(), $GLOBALS['sse_test']['scripts'] );
		$this->assertSame( array(), $GLOBALS['sse_test']['styles'] );

		esse_enqueue_admin_assets( 'tools_page_enginescript-site-exporter' );

		$script = $GLOBALS['sse_test']['scripts']['sse-admin'];
		$this->assertSame( plugin_dir_url( ESSE_PLUGIN_FILE ) . 'js/admin.js', $script['src'] );
		$this->assertSame( ESSE_VERSION, $script['version'] );
		$this->assertSame( array(), $script['dependencies'], 'The script needs no other script.' );
		$this->assertTrue( $script['args']['in_footer'] );
		$this->assertSame( 'defer', $script['args']['strategy'] );

		$style = $GLOBALS['sse_test']['styles']['sse-admin'];
		$this->assertSame( plugin_dir_url( ESSE_PLUGIN_FILE ) . 'css/admin.css', $style['src'] );
		$this->assertSame( ESSE_VERSION, $style['version'] );
	}

	/**
	 * The files the page loads exist where the URLs point.
	 *
	 * @return void
	 */
	public function test_the_enqueued_files_exist(): void {
		$this->assertFileExists( dirname( ESSE_PLUGIN_FILE ) . '/js/admin.js' );
		$this->assertFileExists( dirname( ESSE_PLUGIN_FILE ) . '/css/admin.css' );
	}

	/**
	 * On a single site, managing options is what allows an export.
	 *
	 * @return void
	 */
	public function test_single_site_capability(): void {
		$this->assertSame( 'manage_options', esse_get_exporter_menu_capability() );
		$this->assertTrue( esse_current_user_can_export_site() );

		$GLOBALS['sse_test']['capabilities'] = array( 'edit_posts', 'manage_network_options' );
		$this->assertFalse( esse_current_user_can_export_site() );
	}

	/**
	 * On a network, a site administrator is not enough: an export holds every site.
	 *
	 * @return void
	 */
	public function test_network_capability(): void {
		$GLOBALS['sse_test']['multisite'] = true;

		$this->assertSame( 'manage_network_options', esse_get_exporter_menu_capability() );
		$this->assertFalse( esse_current_user_can_export_site(), 'manage_options alone.' );

		$GLOBALS['sse_test']['capabilities'] = array( 'manage_network_options' );
		$this->assertTrue( esse_current_user_can_export_site() );

		$GLOBALS['sse_test']['capabilities'] = array();
		$GLOBALS['sse_test']['super_admin']  = true;
		$this->assertTrue( esse_current_user_can_export_site() );
	}

	/**
	 * Activation registers the uninstall callback, except on a site of a network that is not the main one.
	 *
	 * @return void
	 */
	public function test_activation_registers_the_uninstall_callback(): void {
		esse_activate_plugin();
		$this->assertSame( array( array( ESSE_PLUGIN_FILE, 'esse_uninstall_plugin' ) ), $GLOBALS['sse_test']['uninstall'] );
		$this->assertTrue( function_exists( 'esse_uninstall_plugin' ) );

		sse_test_reset();
		$GLOBALS['sse_test']['multisite'] = true;
		$GLOBALS['sse_test']['main_site'] = false;
		esse_activate_plugin( true );
		$this->assertSame( array(), $GLOBALS['sse_test']['uninstall'] );
	}

	/**
	 * The export directory is below the temporary directory, with a suffix that belongs to this installation.
	 *
	 * @return void
	 */
	public function test_export_directory_path(): void {
		$GLOBALS['sse_test']['temp_dir'] = '/srv/tmp';

		$path = esse_get_export_directory_path();
		$this->assertMatchesRegularExpression( '#\A/srv/tmp/enginescript-site-exporter-exports-[a-f0-9]{16}\z#', $path );
		$this->assertSame( $path, esse_get_export_directory_path(), 'The path is stable.' );

		$GLOBALS['sse_test']['temp_dir'] = '';
		$error                           = esse_get_export_directory_path();
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'temp_dir_unavailable', $error->get_error_code() );
	}
}
