<?php
/**
 * Render the exporter page for the admin script tests.
 *
 * Prints one JSON object. `html` is what sse_exporter_page_html() prints for
 * an administrator when no export exists. `archive_actions` is what
 * sse_render_export_archive_actions() prints for one archive: the download
 * link and the delete form of one row of the archive list.
 *
 * The two are rendered apart because listing a real archive needs the
 * WordPress filesystem object, the database, and an export directory. The
 * test helper puts the second inside the first where the list would be.
 *
 * WordPress is not loaded; tests/bootstrap.php defines what the page calls.
 *
 * @package EngineScript_Site_Exporter
 */

require dirname( __DIR__ ) . '/bootstrap.php';

$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct();

// A directory that does not exist, so the page lists no archive.
$GLOBALS['sse_test']['temp_dir'] = '/sse-admin-script-tests-no-such-directory/';

ob_start();
sse_exporter_page_html();
$sse_test_page = (string) ob_get_clean();

ob_start();
sse_render_export_archive_actions(
	'example.test_enginescript_site_export_20260101_120000.zip',
	'export-20260101_120000-0123456789abcdef0123456789abcdef'
);
$sse_test_actions = (string) ob_get_clean();

echo json_encode(
	array(
		'html'            => $sse_test_page,
		'archive_actions' => $sse_test_actions,
	),
	JSON_THROW_ON_ERROR
);
