<?php
/**
 * Tests for the markup of the exporter page.
 *
 * @package EngineScript_Site_Exporter
 */

/**
 * What the page prints: the form, the notices, the archive controls, and the records.
 */
final class AdminPageTest extends SseTestCase {

	/**
	 * A well-formed archive name.
	 */
	private const ARCHIVE = 'example.com_enginescript_site_export_20260101_120000.zip';

	/**
	 * A well-formed private directory name.
	 */
	private const DIRECTORY = 'export-20260101_120000-0123456789abcdef0123456789abcdef';

	/**
	 * Give the page a filesystem object and a temporary directory with no exports in it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_filesystem']        = new WP_Filesystem_Direct();
		$GLOBALS['sse_test']['temp_dir'] = $this->make_temporary_directory();
	}

	/**
	 * Parse printed markup.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	private function parse( string $html ): DOMXPath {
		$document = new DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8"><div id="sse-test-root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $document );
	}

	/**
	 * Render the whole page.
	 *
	 * @return DOMXPath
	 */
	private function page(): DOMXPath {
		return $this->parse( $this->capture( 'sse_exporter_page_html' ) );
	}

	/**
	 * Get the text of every node an expression selects.
	 *
	 * @param DOMXPath $xpath      Parsed markup.
	 * @param string   $expression XPath expression.
	 * @return string[]
	 */
	private function texts( DOMXPath $xpath, string $expression ): array {
		$texts = array();
		foreach ( $xpath->query( $expression ) as $node ) {
			$texts[] = trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
		}

		return $texts;
	}

	/**
	 * A user without the capability does not get the page.
	 *
	 * @return void
	 */
	public function test_the_page_is_refused_without_the_capability(): void {
		$GLOBALS['sse_test']['capabilities'] = array( 'edit_posts' );

		$refusal = $this->expect_die( 'sse_exporter_page_html' );

		$this->assertSame( 403, $refusal->status );
		$this->assertSame( 'You do not have permission to view this page.', $refusal->getMessage() );
	}

	/**
	 * Without a temporary directory the page stops with an error instead of offering an export.
	 *
	 * @return void
	 */
	public function test_the_page_stops_without_a_temporary_directory(): void {
		$GLOBALS['sse_test']['temp_dir'] = '';

		$refusal = $this->expect_die( 'sse_exporter_page_html' );

		$this->assertSame( 500, $refusal->status );
		$this->assertSame( 'Could not determine a private temporary directory for exports.', $refusal->getMessage() );
	}

	/**
	 * The export form posts the action and the nonce that the handler checks.
	 *
	 * @return void
	 */
	public function test_export_form_fields(): void {
		$page = $this->page();

		$forms = $page->query( '//form[contains(concat(" ", normalize-space(@class), " "), " sse-export-form ")]' );
		$this->assertSame( 1, $forms->length );

		$form = $forms->item( 0 );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertNotSame( '', $form->getAttribute( 'data-sse-busy-text' ) );

		$this->assertSame( 'sse_export_site', $page->evaluate( 'string(.//input[@name="action"]/@value)', $form ) );
		$this->assertSame( wp_create_nonce( 'sse_export_action' ), $page->evaluate( 'string(.//input[@name="sse_export_nonce"]/@value)', $form ) );
		$this->assertSame( 1, $page->query( './/input[@type="submit"]', $form )->length );
		$this->assertSame( 'Export Site', $page->evaluate( 'string(.//input[@type="submit"]/@value)', $form ) );
	}

	/**
	 * The size select offers exactly the sizes the handler accepts, starting with no limit.
	 *
	 * @return void
	 */
	public function test_size_select_offers_the_accepted_sizes(): void {
		$page   = $this->page();
		$values = array();
		foreach ( $page->query( '//select[@name="sse_max_file_size"]/option' ) as $option ) {
			$values[] = $option->getAttribute( 'value' );
		}

		$this->assertSame( array_map( 'strval', array_keys( sse_get_export_file_size_options() ) ), $values );
		$this->assertSame( 0, $page->query( '//select[@name="sse_max_file_size"]/option[@selected]' )->length, 'The first option, no limit, is the default.' );
	}

	/**
	 * The select has a label and a description, and the status line is a live region inside the form.
	 *
	 * @return void
	 */
	public function test_form_is_labelled_for_assistive_technology(): void {
		$page = $this->page();

		$select_id = $page->evaluate( 'string(//select[@name="sse_max_file_size"]/@id)' );
		$this->assertNotSame( '', $select_id );
		$this->assertSame( 1, $page->query( '//label[@for="' . $select_id . '"]' )->length );

		$described_by = $page->evaluate( 'string(//select[@name="sse_max_file_size"]/@aria-describedby)' );
		$this->assertNotSame( '', $described_by );
		$this->assertSame( 1, $page->query( '//*[@id="' . $described_by . '"]' )->length, 'The description the select points to exists.' );

		$this->assertSame( 'presentation', $page->evaluate( 'string(//table[contains(@class, "sse-form-table")]/@role)' ) );
		$this->assertSame( 1, $page->query( '//form[contains(@class, "sse-export-form")]//p[@class="sse-export-status"][@role="status"]' )->length );
		$this->assertSame( '', $page->evaluate( 'string(//p[@class="sse-export-status"])' ), 'The status line starts empty.' );
	}

	/**
	 * No element ID is used twice on the page.
	 *
	 * @return void
	 */
	public function test_element_ids_are_unique(): void {
		$page = $this->page();
		$ids  = array();
		foreach ( $page->query( '//*[@id][not(@id="sse-test-root")]' ) as $element ) {
			$ids[] = $element->getAttribute( 'id' );
		}

		$this->assertNotSame( array(), $ids );
		$this->assertSame( array_unique( $ids ), $ids );
	}

	/**
	 * The link to the project opens a new tab safely and says so.
	 *
	 * @return void
	 */
	public function test_external_link_is_safe_and_announced(): void {
		$page  = $this->page();
		$links = $page->query( '//a[@target="_blank"]' );

		$this->assertSame( 1, $links->length );
		$this->assertSame( 'noopener noreferrer', $links->item( 0 )->getAttribute( 'rel' ) );
		$this->assertStringContainsString( '(opens in a new tab)', $links->item( 0 )->textContent );
	}

	/**
	 * With no archive the page says so and prints no archive table.
	 *
	 * @return void
	 */
	public function test_no_archives(): void {
		$page = $this->page();

		$this->assertSame( 0, $page->query( '//table[contains(@class, "sse-archive-table")]' )->length );
		$this->assertContains( 'No exports are available. A finished export is listed here until it is deleted.', $this->texts( $page, '//p' ) );
		$this->assertContains( 'No activity has been recorded.', $this->texts( $page, '//p' ) );
	}

	/**
	 * The page shows where exports are stored, and registers the uninstall cleanup.
	 *
	 * @return void
	 */
	public function test_the_page_names_the_export_directory(): void {
		$page = $this->page();

		$this->assertContains( wp_normalize_path( sse_get_export_directory_path() ), $this->texts( $page, '//code' ) );
		$this->assertSame( array( array( SSE_PLUGIN_FILE, 'sse_uninstall_plugin' ) ), $GLOBALS['sse_test']['uninstall'] );
	}

	/**
	 * A page that is not served over HTTPS carries a warning; one that is does not.
	 *
	 * @return void
	 */
	public function test_transport_warning(): void {
		$warning = 'This page is not served over HTTPS. A downloaded export travels unencrypted, and it contains the whole database and wp-config.php.';

		$this->assertNotContains( $warning, $this->texts( $this->page(), '//div[contains(@class, "notice-warning")]/p' ) );

		$GLOBALS['sse_test']['ssl'] = false;
		$this->assertContains( $warning, $this->texts( $this->page(), '//div[contains(@class, "notice-warning")]/p' ) );
	}

	/**
	 * The page warns when the importer's requirements are not met, and says the export still works.
	 *
	 * @return void
	 */
	public function test_import_requirement_warnings(): void {
		$warnings = sse_get_import_requirement_warnings();
		$this->assertCount( 2, $warnings, 'This suite has no wp-config.php and defines neither address constant.' );

		$shown = $this->texts( $this->page(), '//div[contains(@class, "notice-warning")]/p' );
		foreach ( $warnings as $warning ) {
			$this->assertContains( $warning, $shown );
		}
		$this->assertContains( 'You can still create the export, and it remains a complete backup. Importing it with EngineScript will stop until this is corrected.', $shown );
	}

	/**
	 * An error notice is shown once, as text.
	 *
	 * @return void
	 */
	public function test_error_notice_is_shown_once_as_text(): void {
		sse_set_exporter_notice(
			array(
				'type'    => 'error',
				'message' => 'Failed <img src=x onerror=alert(1)> & stopped',
			)
		);

		$html = $this->capture( 'sse_render_exporter_notices' );
		$page = $this->parse( $html );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertSame( array( 'Failed <img src=x onerror=alert(1)> & stopped' ), $this->texts( $page, '//div[contains(@class, "notice-error")]/p' ) );
		$this->assertSame( 0, $page->query( '//div[contains(@class, "notice-success")]' )->length );
		$this->assertSame( '', $this->capture( 'sse_render_exporter_notices' ), 'The notice is gone after it was shown.' );
	}

	/**
	 * A notice belongs to the user it was stored for.
	 *
	 * @return void
	 */
	public function test_a_notice_is_shown_to_its_own_user_only(): void {
		sse_set_exporter_notice(
			array(
				'type'    => 'success',
				'message' => 'Export file successfully deleted.',
			)
		);

		$GLOBALS['sse_test']['user_id'] = 8;
		$this->assertSame( '', $this->capture( 'sse_render_exporter_notices' ) );

		$GLOBALS['sse_test']['user_id'] = 7;
		$this->assertSame( array( 'Export file successfully deleted.' ), $this->texts( $this->parse( $this->capture( 'sse_render_exporter_notices' ) ), '//div[contains(@class, "notice-success")]/p' ) );
	}

	/**
	 * A stored notice that is not an array, or has no usable fields, prints nothing harmful.
	 *
	 * @return void
	 */
	public function test_malformed_notices(): void {
		set_transient( sse_get_exporter_notice_key(), 'text' );
		$this->assertSame( '', $this->capture( 'sse_render_exporter_notices' ) );

		set_transient(
			sse_get_exporter_notice_key(),
			array(
				'type'    => array( 'error' ),
				'message' => array( '<b>x</b>' ),
			)
		);
		$page = $this->parse( $this->capture( 'sse_render_exporter_notices' ) );
		$this->assertSame( array( '' ), $this->texts( $page, '//div[contains(@class, "notice")]/p' ) );
	}

	/**
	 * The success notice names the archive and lists what was left out.
	 *
	 * @return void
	 */
	public function test_success_notice_names_the_archive(): void {
		sse_set_exporter_notice(
			array(
				'type'       => 'export_success',
				'zip_result' => array( 'filename' => self::ARCHIVE ),
				'skipped'    => array(
					'unreadable' => 1,
					'links'      => 0,
					'special'    => 0,
					'large'      => 2,
					'changed'    => 0,
				),
			)
		);

		$page = $this->parse( $this->capture( 'sse_render_exporter_notices' ) );

		$this->assertSame( array( self::ARCHIVE ), $this->texts( $page, '//div[contains(@class, "notice-success")]//code' ) );
		$this->assertContains( 'Left out of the export: 1 unreadable file or directory and 2 files over the size limit.', $this->texts( $page, '//div[contains(@class, "notice-success")]/p' ) );
		$this->assertSame( 0, $page->query( '//a | //form | //button' )->length, 'The notice holds no control; the list below it does.' );
	}

	/**
	 * A stored name that is not an archive name is not printed.
	 *
	 * @return void
	 */
	public function test_success_notice_does_not_print_an_unexpected_name(): void {
		sse_set_exporter_notice(
			array(
				'type'       => 'export_success',
				'zip_result' => array( 'filename' => '<script>alert(1)</script>.zip' ),
				'skipped'    => 'not counts',
			)
		);

		$html = $this->capture( 'sse_render_exporter_notices' );
		$page = $this->parse( $html );

		$this->assertStringNotContainsString( 'script', $html );
		$this->assertSame( 0, $page->query( '//code' )->length );
		$this->assertSame( 1, $page->query( '//div[contains(@class, "notice-success")]/p' )->length, 'Counts that cannot be read add no line.' );
	}

	/**
	 * The delete form carries the confirmation text, both names, and a nonce bound to both names.
	 *
	 * @return void
	 */
	public function test_archive_delete_form(): void {
		$page = $this->parse(
			$this->capture(
				static function (): void {
					sse_render_export_archive_actions( self::ARCHIVE, self::DIRECTORY );
				}
			)
		);

		$forms = $page->query( '//form[contains(concat(" ", normalize-space(@class), " "), " sse-confirm-delete ")]' );
		$this->assertSame( 1, $forms->length );

		$form = $forms->item( 0 );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertSame( 'https://example.test/wp-admin/admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertSame( 'Are you sure you want to delete this export file?', $form->getAttribute( 'data-sse-confirm-message' ) );

		$fields = array();
		foreach ( $page->query( './/input[@type="hidden"]', $form ) as $input ) {
			$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}
		$this->assertSame(
			array(
				'action'     => 'sse_delete_export',
				'file'       => self::ARCHIVE,
				'export_dir' => self::DIRECTORY,
				'_wpnonce'   => wp_create_nonce( 'sse_delete_export_' . self::ARCHIVE . '_' . self::DIRECTORY ),
			),
			$fields
		);

		$this->assertSame( 'Delete ' . self::ARCHIVE, $page->evaluate( 'string(.//button[@type="submit"]/@aria-label)', $form ) );
	}

	/**
	 * The download link names the archive and its directory, and its nonce is bound to both.
	 *
	 * @return void
	 */
	public function test_archive_download_link(): void {
		$page = $this->parse(
			$this->capture(
				static function (): void {
					sse_render_export_archive_actions( self::ARCHIVE, self::DIRECTORY );
				}
			)
		);

		$links = $page->query( '//a' );
		$this->assertSame( 1, $links->length );
		$this->assertSame( 'Download ' . self::ARCHIVE, $links->item( 0 )->getAttribute( 'aria-label' ) );

		$url = $links->item( 0 )->getAttribute( 'href' );
		$this->assertStringStartsWith( 'https://example.test/wp-admin/admin-post.php?', $url );

		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'action'     => 'sse_secure_download',
				'file'       => self::ARCHIVE,
				'export_dir' => self::DIRECTORY,
				'_wpnonce'   => wp_create_nonce( 'sse_secure_download_' . self::ARCHIVE . '_' . self::DIRECTORY ),
			),
			$query
		);
		$this->assertNotSame(
			wp_create_nonce( 'sse_secure_download_' . self::ARCHIVE . '_' . self::DIRECTORY ),
			wp_create_nonce( 'sse_delete_export_' . self::ARCHIVE . '_' . self::DIRECTORY ),
			'A download nonce is not a delete nonce.'
		);
	}

	/**
	 * Names are escaped where the controls print them.
	 *
	 * @return void
	 */
	public function test_archive_controls_escape_their_names(): void {
		$html = $this->capture(
			static function (): void {
				sse_render_export_archive_actions( 'a"><script>alert(1)</script>.zip', 'dir"><b>' );
			}
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertSame( 'a"><script>alert(1)</script>.zip', $this->parse( $html )->evaluate( 'string(//input[@name="file"]/@value)' ) );
	}

	/**
	 * Stored records are listed newest first, as text, with a name for the user and for the type.
	 *
	 * @return void
	 */
	public function test_activity_records(): void {
		update_option(
			'sse_error_logs',
			array(
				array(
					'time'    => time() - 300,
					'level'   => 'activity',
					'message' => 'Export created: ' . self::ARCHIVE,
					'user_id' => 7,
				),
				array(
					'time'    => time() - 200,
					'level'   => 'security',
					'message' => 'Refused <img src=x onerror=alert(1)> & "quoted"',
					'user_id' => 0,
				),
				array(
					'time'    => time() - 100,
					'level'   => 'error',
					'message' => 'The export failed.',
					'user_id' => 7,
				),
			)
		);

		$html = $this->capture( 'sse_render_activity_records' );
		$page = $this->parse( $html );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'Refused &amp; &quot;quoted&quot;', $html, 'Stored text is escaped where it is printed.' );
		$this->assertSame(
			array( 'The export failed.', 'Refused & "quoted"', 'Export created: ' . self::ARCHIVE ),
			$this->texts( $page, '//table[contains(@class, "sse-activity-table")]/tbody/tr/td[4]' ),
			'Newest first; markup in a stored message was removed when it was read.'
		);
		$this->assertSame( array( 'Error', 'Security', 'Activity' ), $this->texts( $page, '//table[contains(@class, "sse-activity-table")]/tbody/tr/td[3]' ) );
		$this->assertSame( array( 'User 7 (no longer exists)', 'System', 'User 7 (no longer exists)' ), $this->texts( $page, '//table[contains(@class, "sse-activity-table")]/tbody/tr/td[2]' ) );
		$this->assertSame( 4, $page->query( '//table[contains(@class, "sse-activity-table")]/thead/tr/th[@scope="col"]' )->length );
	}

	/**
	 * Records older than seven days are not shown.
	 *
	 * @return void
	 */
	public function test_old_activity_records_are_not_shown(): void {
		update_option(
			'sse_error_logs',
			array(
				array(
					'time'    => time() - ( 8 * DAY_IN_SECONDS ),
					'level'   => 'activity',
					'message' => 'Export created long ago.',
					'user_id' => 7,
				),
			)
		);

		$page = $this->parse( $this->capture( 'sse_render_activity_records' ) );

		$this->assertSame( 0, $page->query( '//table' )->length );
		$this->assertContains( 'No activity has been recorded.', $this->texts( $page, '//p' ) );
	}

	/**
	 * A record type the plugin does not know is shown as it is stored.
	 *
	 * @return void
	 */
	public function test_record_type_labels(): void {
		$this->assertSame( 'Activity', sse_get_activity_record_type_label( 'activity' ) );
		$this->assertSame( 'Error', sse_get_activity_record_type_label( 'error' ) );
		$this->assertSame( 'Security', sse_get_activity_record_type_label( 'security' ) );
		$this->assertSame( 'warning', sse_get_activity_record_type_label( 'warning' ) );
		$this->assertSame( 'System', sse_get_activity_record_user_label( 0 ) );
		$this->assertSame( 'System', sse_get_activity_record_user_label( -3 ) );
	}
}
