<?php
/**
 * Admin page: menu registration, asset enqueueing, page rendering, notices.
 *
 * @package EngineScript_Site_Exporter
 */

// Prevent direct execution of this component.
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Records the exact hook suffix returned for the current exporter page.
 *
 * @since 2.1.1
 * @param string|false $hook_suffix Registered page hook, or false on failure.
 * @return void
 */
function esse_set_exporter_page_hook_suffix( string|false $hook_suffix ): void {
	if ( false === $hook_suffix || '' === $hook_suffix ) {
		unset( $GLOBALS['esse_exporter_page_hook_suffix'] );
		return;
	}

	$GLOBALS['esse_exporter_page_hook_suffix'] = $hook_suffix;
}

/**
 * Gets the exact registered hook suffix for this request.
 *
 * @since 2.1.1
 * @return string Registered page hook, or an empty string when unavailable.
 */
function esse_get_exporter_page_hook_suffix(): string {
	return esse_normalize_string_value( $GLOBALS['esse_exporter_page_hook_suffix'] ?? '' );
}

/**
 * Adds the Site Exporter page beneath Tools on a single-site installation.
 *
 * @since 1.0.0
 * @return void
 */
function esse_admin_menu(): void {
	if ( is_multisite() ) {
		return;
	}

	$page_hook = add_management_page(
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ), // Page title (escaped by WordPress core).
		__( 'Site Exporter', 'enginescript-site-exporter' ),               // Menu title (escaped by WordPress core).
		esse_get_exporter_menu_capability(), // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Returns the core capability 'manage_options'; this menu is not added on multisite.
		'enginescript-site-exporter',
		'esse_exporter_page_html'
	);
	esse_set_exporter_page_hook_suffix( $page_hook );
}

/**
 * Adds one network-level Site Exporter page beneath Network Settings.
 *
 * @since 2.1.1
 * @return void
 */
function esse_network_admin_menu(): void {
	if ( ! is_multisite() ) {
		return;
	}

	$page_hook = add_submenu_page(
		'settings.php',
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ),
		__( 'Site Exporter', 'enginescript-site-exporter' ),
		esse_get_exporter_menu_capability(), // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Returns the core capability 'manage_network_options' on multisite.
		'enginescript-site-exporter',
		'esse_exporter_page_html'
	);
	esse_set_exporter_page_hook_suffix( $page_hook );
}

/**
 * Enqueues admin CSS and JS on the Site Exporter page only.
 *
 * @since 2.0.0
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function esse_enqueue_admin_assets( string $hook_suffix ): void {
	$exporter_page_hook = esse_get_exporter_page_hook_suffix();
	if ( '' === $exporter_page_hook || $exporter_page_hook !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'sse-admin',
		plugin_dir_url( ESSE_PLUGIN_FILE ) . 'css/admin.css',
		[],
		ESSE_VERSION
	);

	wp_enqueue_script(
		'sse-admin',
		plugin_dir_url( ESSE_PLUGIN_FILE ) . 'js/admin.js',
		[],
		ESSE_VERSION,
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);
}

/**
 * Gets the current user's exporter notice transient key.
 *
 * @since 2.0.0
 * @return string Notice transient key.
 */
function esse_get_exporter_notice_key(): string {
	return 'sse_exporter_notice_' . get_current_user_id();
}

/**
 * Stores an exporter notice for display after admin-post redirects.
 *
 * @since 2.0.0
 * @param array<string, mixed> $notice Notice data.
 * @return void
 */
function esse_set_exporter_notice( array $notice ): void {
	set_transient( esse_get_exporter_notice_key(), $notice, 10 * MINUTE_IN_SECONDS );
}

/**
 * Renders any pending exporter notice.
 *
 * @since 2.0.0
 * @return void
 */
function esse_render_exporter_notices(): void {
	$notice = esse_normalize_array_value( get_transient( esse_get_exporter_notice_key() ) );
	if ( [] === $notice ) {
		return;
	}

	delete_transient( esse_get_exporter_notice_key() );

	$type    = isset( $notice['type'] ) && is_string( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
	$message = isset( $notice['message'] ) && is_string( $notice['message'] ) ? $notice['message'] : '';

	if ( 'export_success' === $type ) {
		esse_render_export_success_notice( $notice );
		return;
	}

	$notice_class = 'error' === $type ? 'notice-error' : 'notice-success';
	?>
	<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
		<p><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * Renders the notice for a finished export.
 *
 * The notice only reports. The download and delete controls are in the list of
 * available exports, which stays on the page for as long as the archive exists.
 *
 * @since 2.0.0
 * @param array<array-key,mixed> $notice Stored notice data.
 * @return void
 */
function esse_render_export_success_notice( array $notice ): void {
	$zip_result = esse_normalize_array_value( $notice['zip_result'] ?? [] );
	$filename   = isset( $zip_result['filename'] ) && is_string( $zip_result['filename'] ) && esse_is_engine_script_archive_filename( $zip_result['filename'] ) ? $zip_result['filename'] : '';
	$skipped    = esse_normalize_skipped_export_entry_counts( $notice['skipped'] ?? null );
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php esc_html_e( 'Site export successfully created!', 'enginescript-site-exporter' ); ?>
			<?php if ( '' !== $filename ) : ?>
				<code><?php echo esc_html( $filename ); ?></code>
			<?php endif; ?>
			<?php esc_html_e( 'Download it from the list of available exports on this page.', 'enginescript-site-exporter' ); ?>
		</p>
		<?php foreach ( esse_get_skipped_entries_notice_lines( $skipped ) as $line ) : ?>
			<p><?php echo esc_html( $line ); ?></p>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Describes what the files archive left out, for the success notice.
 *
 * @since 2.1.1
 * @param array|null $skipped Counters keyed by reason, or null when unknown.
 * @psalm-param array{unreadable:int,links:int,special:int,large:int,changed:int}|null $skipped
 * @return string[] Sentences to show; empty when nothing was left out.
 */
function esse_get_skipped_entries_notice_lines( ?array $skipped ): array {
	if ( null === $skipped ) {
		return [];
	}

	$left_out = [];
	if ( $skipped['unreadable'] > 0 ) {
		/* translators: %s: number of files or directories */
		$left_out[] = sprintf( _n( '%s unreadable file or directory', '%s unreadable files or directories', $skipped['unreadable'], 'enginescript-site-exporter' ), number_format_i18n( $skipped['unreadable'] ) );
	}
	if ( $skipped['links'] > 0 ) {
		/* translators: %s: number of symbolic links */
		$left_out[] = sprintf( _n( '%s symbolic link', '%s symbolic links', $skipped['links'], 'enginescript-site-exporter' ), number_format_i18n( $skipped['links'] ) );
	}
	if ( $skipped['special'] > 0 ) {
		/* translators: %s: number of special files */
		$left_out[] = sprintf( _n( '%s special file such as a socket or named pipe', '%s special files such as sockets or named pipes', $skipped['special'], 'enginescript-site-exporter' ), number_format_i18n( $skipped['special'] ) );
	}
	if ( $skipped['large'] > 0 ) {
		/* translators: %s: number of files */
		$left_out[] = sprintf( _n( '%s file over the size limit', '%s files over the size limit', $skipped['large'], 'enginescript-site-exporter' ), number_format_i18n( $skipped['large'] ) );
	}

	$lines = [];
	if ( [] !== $left_out ) {
		/* translators: %l: list of counted entries, for example "2 symbolic links and 1 file over the size limit" */
		$lines[] = wp_sprintf( __( 'Left out of the export: %l.', 'enginescript-site-exporter' ), $left_out );
	}
	if ( $skipped['changed'] > 0 ) {
		/* translators: %s: number of files */
		$lines[] = sprintf( _n( '%s file changed while it was read and may be incomplete in the export.', '%s files changed while they were read and may be incomplete in the export.', $skipped['changed'], 'enginescript-site-exporter' ), number_format_i18n( $skipped['changed'] ) );
	}

	return $lines;
}

/**
 * Gets the finished export archives that the page can offer.
 *
 * Every archive is validated again here, the directory of a running export is
 * left out, and the newest twenty are returned.
 *
 * @since 2.1.1
 * @return array<int,array{filename:string,export_dir_name:string,size:int,modified:int}> Archives, newest first.
 */
function esse_get_listable_export_archives(): array {
	$export_dir = esse_get_export_directory_path();
	$filesystem = esse_get_filesystem();
	if ( is_wp_error( $export_dir ) || is_wp_error( $filesystem ) || ! $filesystem->is_dir( $export_dir ) ) {
		return [];
	}

	$archives = [];
	foreach ( esse_get_export_files_for_bulk_cleanup( $export_dir ) as $file_path ) {
		$filename        = wp_basename( $file_path );
		$export_dir_name = wp_basename( dirname( $file_path ) );
		$validation      = esse_validate_basic_export_file( $filename, $export_dir_name );
		if ( is_wp_error( $validation ) || wp_normalize_path( $validation['filepath'] ) !== wp_normalize_path( $file_path ) ) {
			continue;
		}

		clearstatcache( true, $file_path );
		$size       = esse_normalize_nonnegative_integer( $filesystem->size( $file_path ) );
		$modified   = esse_normalize_nonnegative_integer( $filesystem->mtime( $file_path ) );
		$archives[] = [
			'filename'        => $filename,
			'export_dir_name' => $export_dir_name,
			'size'            => false === $size ? 0 : $size,
			'modified'        => false === $modified ? 0 : $modified,
		];
	}

	usort(
		$archives,
		static function ( array $first, array $second ): int {
			return $second['modified'] <=> $first['modified'];
		}
	);

	return array_slice( $archives, 0, 20 );
}

/**
 * Renders the download link and the delete form for one archive.
 *
 * @since 2.1.1
 * @param string $filename        Validated archive file name.
 * @param string $export_dir_name Validated private directory name.
 * @return void
 */
function esse_render_export_archive_actions( string $filename, string $export_dir_name ): void {
	$download_url = wp_nonce_url(
		add_query_arg(
			[
				'action'     => 'sse_secure_download',
				'file'       => $filename,
				'export_dir' => $export_dir_name,
			],
			admin_url( 'admin-post.php' )
		),
		'sse_secure_download_' . $filename . '_' . $export_dir_name
	);
	$delete_nonce = esse_normalize_string_value( wp_create_nonce( 'sse_delete_export_' . $filename . '_' . $export_dir_name ) );
	/* translators: %s: archive file name */
	$download_label = sprintf( __( 'Download %s', 'enginescript-site-exporter' ), $filename );
	/* translators: %s: archive file name */
	$delete_label = sprintf( __( 'Delete %s', 'enginescript-site-exporter' ), $filename );
	?>
	<div class="sse-archive-actions">
		<a href="<?php echo esc_url( $download_url ); ?>" class="button" aria-label="<?php echo esc_attr( $download_label ); ?>">
			<?php esc_html_e( 'Download Export File', 'enginescript-site-exporter' ); ?>
		</a>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="sse-confirm-delete"
			data-sse-confirm-message="<?php esc_attr_e( 'Are you sure you want to delete this export file?', 'enginescript-site-exporter' ); ?>"
		>
			<input type="hidden" name="action" value="sse_delete_export">
			<input type="hidden" name="file" value="<?php echo esc_attr( $filename ); ?>">
			<input type="hidden" name="export_dir" value="<?php echo esc_attr( $export_dir_name ); ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>">
			<button type="submit" class="button button-secondary" aria-label="<?php echo esc_attr( $delete_label ); ?>">
				<?php esc_html_e( 'Delete Export File', 'enginescript-site-exporter' ); ?>
			</button>
		</form>
	</div>
	<?php
}

/**
 * Renders the list of export archives that exist now.
 *
 * @since 2.1.1
 * @return void
 */
function esse_render_export_archive_list(): void {
	$archives    = esse_get_listable_export_archives();
	$date_format = esse_normalize_string_value( get_option( 'date_format' ), 'Y-m-d' ) . ' ' . esse_normalize_string_value( get_option( 'time_format' ), 'H:i' );
	?>
	<h2><?php esc_html_e( 'Available exports', 'enginescript-site-exporter' ); ?></h2>
	<?php if ( [] === $archives ) : ?>
		<p><?php esc_html_e( 'No exports are available. A finished export is listed here until it is deleted.', 'enginescript-site-exporter' ); ?></p>
	<?php else : ?>
		<table class="widefat striped sse-archive-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Exports that can be downloaded or deleted', 'enginescript-site-exporter' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'File', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Created', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'enginescript-site-exporter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $archives as $archive ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( $archive['filename'] ); ?></code></th>
						<td><?php echo esc_html( esse_normalize_string_value( size_format( $archive['size'] ), (string) $archive['size'] . ' B' ) ); ?></td>
						<td><?php echo esc_html( esse_normalize_string_value( wp_date( $date_format, $archive['modified'] ) ) ); ?></td>
						<td><?php esse_render_export_archive_actions( $archive['filename'], $archive['export_dir_name'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * Gets the display name for the user of a stored record.
 *
 * @since 2.1.1
 * @param int $user_id User ID stored with the record; zero for a scheduled run.
 * @return string Name to show.
 */
function esse_get_activity_record_user_label( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return __( 'System', 'enginescript-site-exporter' );
	}

	$user = get_userdata( $user_id );
	if ( $user instanceof WP_User ) {
		return $user->display_name;
	}

	/* translators: %d: user ID */
	return sprintf( __( 'User %d (no longer exists)', 'enginescript-site-exporter' ), $user_id );
}

/**
 * Gets the label for the type of a stored record.
 *
 * @since 2.1.1
 * @param string $level Stored record level.
 * @return string Label to show.
 */
function esse_get_activity_record_type_label( string $level ): string {
	$labels = [
		'activity' => __( 'Activity', 'enginescript-site-exporter' ),
		'error'    => __( 'Error', 'enginescript-site-exporter' ),
		'security' => __( 'Security', 'enginescript-site-exporter' ),
	];

	return $labels[ $level ] ?? $level;
}

/**
 * Renders the stored records: exports, downloads, deletions, errors, and security events.
 *
 * @since 2.1.1
 * @return void
 */
function esse_render_activity_records(): void {
	$records     = array_reverse( esse_get_retained_stored_logs( get_option( 'sse_error_logs', [] ), time() - ( 7 * DAY_IN_SECONDS ) ) );
	$date_format = esse_normalize_string_value( get_option( 'date_format' ), 'Y-m-d' ) . ' ' . esse_normalize_string_value( get_option( 'time_format' ), 'H:i' );
	?>
	<h2><?php esc_html_e( 'Recent activity', 'enginescript-site-exporter' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Up to 20 exports, downloads, deletions, errors, and security events from the last seven days, newest first.', 'enginescript-site-exporter' ); ?></p>
	<?php if ( [] === $records ) : ?>
		<p><?php esc_html_e( 'No activity has been recorded.', 'enginescript-site-exporter' ); ?></p>
	<?php else : ?>
		<table class="widefat striped sse-activity-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Recent exporter activity', 'enginescript-site-exporter' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'enginescript-site-exporter' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'enginescript-site-exporter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<tr>
						<td><?php echo esc_html( esse_normalize_string_value( wp_date( $date_format, $record['time'] ) ) ); ?></td>
						<td><?php echo esc_html( esse_get_activity_record_user_label( $record['user_id'] ) ); ?></td>
						<td><?php echo esc_html( esse_get_activity_record_type_label( $record['level'] ) ); ?></td>
						<td><?php echo esc_html( $record['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * Renders a warning when the page is not served over HTTPS.
 *
 * @since 2.1.1
 * @return void
 */
function esse_render_transport_warning(): void {
	if ( is_ssl() ) {
		return;
	}
	?>
	<div class="notice notice-warning inline">
		<p><?php esc_html_e( 'This page is not served over HTTPS. A downloaded export travels unencrypted, and it contains the whole database and wp-config.php.', 'enginescript-site-exporter' ); ?></p>
	</div>
	<?php
}

/**
 * Renders the warnings about what EngineScript's importer requires.
 *
 * The warnings stay on the page before and after an export, so they are seen
 * both when deciding to export and next to the finished archive.
 *
 * @since 2.1.1
 * @return void
 */
function esse_render_import_requirement_warnings(): void {
	$warnings = esse_get_import_requirement_warnings();
	if ( [] === $warnings ) {
		return;
	}
	?>
	<div class="notice notice-warning inline">
		<?php foreach ( $warnings as $warning ) : ?>
			<p><?php echo esc_html( $warning ); ?></p>
		<?php endforeach; ?>
		<p><?php esc_html_e( 'You can still create the export, and it remains a complete backup. Importing it with EngineScript will stop until this is corrected.', 'enginescript-site-exporter' ); ?></p>
	</div>
	<?php
}

/**
 * Renders the exporter page HTML interface.
 *
 * @since 1.0.0
 * @return void
 */
function esse_exporter_page_html(): void {
	if ( ! esse_current_user_can_export_site() ) {
		esse_wp_die( __( 'You do not have permission to view this page.', 'enginescript-site-exporter' ), 403 );
	}

	// An installation that was updated, not activated, registers its uninstall cleanup here.
	esse_register_uninstall_callback();

	$export_dir_path = esse_get_export_directory_path();
	if ( is_wp_error( $export_dir_path ) ) {
		esse_wp_die( $export_dir_path->get_error_message() );
	}
	$display_path = wp_normalize_path( $export_dir_path );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php esse_render_exporter_notices(); ?>
		<?php esse_render_transport_warning(); ?>
		<?php esse_render_import_requirement_warnings(); ?>
		<?php esse_render_export_archive_list(); ?>
		<h2><?php esc_html_e( 'Create an export', 'enginescript-site-exporter' ); ?></h2>
		<p><?php esc_html_e( 'Click the button below to generate an EngineScript-compatible site archive containing your WordPress files and the database.', 'enginescript-site-exporter' ); ?></p>
		<p><strong><?php esc_html_e( 'Warning:', 'enginescript-site-exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'enginescript-site-exporter' ); ?></p>
		<p class="sse-section-spacing">
			<?php
			// printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
			printf(
				// translators: %s: directory path.
				esc_html__( 'Exports are stored in this private server directory: %s', 'enginescript-site-exporter' ),
				'<code>' . esc_html( $display_path ) . '</code>'
			);
			?>
		</p>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="sse-section-spacing sse-export-form"
			data-sse-busy-text="<?php esc_attr_e( 'The export is running. This can take several minutes. Keep this page open; it reloads when the export has finished.', 'enginescript-site-exporter' ); ?>"
		>
			<?php wp_nonce_field( 'sse_export_action', 'sse_export_nonce' ); ?>
			<input type="hidden" name="action" value="sse_export_site">

			<table class="form-table sse-form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sse_max_file_size"><?php esc_html_e( 'Maximum File Size', 'enginescript-site-exporter' ); ?></label>
						</th>
						<td>
							<select name="sse_max_file_size" id="sse_max_file_size" aria-describedby="sse-max-file-size-description">
								<?php foreach ( esse_get_export_file_size_options() as $size_bytes => $size_label ) : ?>
									<option value="<?php echo esc_attr( (string) $size_bytes ); ?>"><?php echo esc_html( $size_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="sse-max-file-size-description">
								<?php esc_html_e( 'Files larger than this size are excluded. "No per-file limit" removes this size filter, but other exclusions and overall export limits still apply.', 'enginescript-site-exporter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Export Site', 'enginescript-site-exporter' ) ); ?>
			<p class="sse-export-status" role="status"></p>
		</form>
		<?php esse_render_activity_records(); ?>
		<hr>
		<p>
			<?php esc_html_e( 'This plugin is part of the EngineScript project.', 'enginescript-site-exporter' ); ?>
			<a href="https://github.com/EngineScript/EngineScript" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Visit the EngineScript GitHub page', 'enginescript-site-exporter' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'enginescript-site-exporter' ); ?></span>
			</a>
		</p>
		<p class="sse-warning-text">
			<?php esc_html_e( 'Security Notice:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'An export contains the whole site: its files and its database, including password hashes and wp-config.php. Only administrators who can open this page can create or download one. Each export is scheduled for deletion 5 minutes after it is created. Deletion can be delayed if WordPress cron does not run, so download the export promptly and delete it when you are finished.', 'enginescript-site-exporter' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Shows an error notice to the user.
 *
 * @since 1.0.0
 * @param string $message The error message to display.
 * @return void
 */
function esse_show_error_notice( string $message ): void {
	esse_set_exporter_notice(
		[
			'type'    => 'error',
			'message' => $message,
		]
	);
	esse_log( 'Export error: ' . $message, 'error' );
}

/**
 * Stores the notice and the record for a finished export.
 *
 * @since 1.0.0
 * @param array{filename: string, filepath: string} $zip_result The ZIP file information.
 * @return void
 */
function esse_show_success_notice( array $zip_result ): void {
	$skipped = esse_get_skipped_export_entry_counts();
	esse_set_exporter_notice(
		[
			'type'       => 'export_success',
			'zip_result' => $zip_result,
			'skipped'    => $skipped,
		]
	);
	esse_record_activity( 'Export created: ' . $zip_result['filename'] );
	esse_log( 'Export successful. File saved to ' . $zip_result['filepath'], 'info' );

	if ( array_sum( $skipped ) > 0 ) {
		esse_log(
			sprintf(
				'Entries left out of the files archive: %1$d unreadable, %2$d symbolic links, %3$d special files, %4$d over the size limit. Files that ended early while read: %5$d.',
				$skipped['unreadable'],
				$skipped['links'],
				$skipped['special'],
				$skipped['large'],
				$skipped['changed']
			),
			'warning'
		);
	}
}
