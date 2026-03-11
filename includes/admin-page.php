<?php
/**
 * Admin page: menu registration, asset enqueueing, page rendering, notices.
 *
 * @package EngineScript_Site_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Adds the Site Exporter page to the WordPress admin menu.
 *
 * @since 1.0.0
 * @return void
 */
function sse_admin_menu(): void {
	add_management_page(
		__( 'EngineScript Site Exporter', 'enginescript-site-exporter' ), // Page title (escaped by WordPress core).
		__( 'Site Exporter', 'enginescript-site-exporter' ),               // Menu title (escaped by WordPress core).
		'manage_options', // Capability required.
		'enginescript-site-exporter',
		'sse_exporter_page_html'
	);
}

/**
 * Enqueues admin CSS and JS on the Site Exporter page only.
 *
 * @since 2.1.0
 * @param string $hook_suffix The current admin page hook suffix.
 * @return void
 */
function sse_enqueue_admin_assets( string $hook_suffix ): void {
	if ( 'tools_page_enginescript-site-exporter' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style(
		'sse-admin',
		plugin_dir_url( SSE_PLUGIN_FILE ) . 'css/admin.css',
		[],
		ES_SITE_EXPORTER_VERSION
	);

	wp_enqueue_script(
		'sse-admin',
		plugin_dir_url( SSE_PLUGIN_FILE ) . 'js/admin.js',
		[],
		ES_SITE_EXPORTER_VERSION,
		true
	);

	wp_localize_script(
		'sse-admin',
		'sseAdmin',
		[
			'confirmDelete' => __( 'Are you sure you want to delete this export file?', 'enginescript-site-exporter' ),
		]
	);
}

/**
 * Renders the exporter page HTML interface.
 *
 * @since 1.0.0
 * @return void
 */
function sse_exporter_page_html(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'enginescript-site-exporter' ), 403 );
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
		wp_die( esc_html__( 'Could not determine the WordPress upload directory.', 'enginescript-site-exporter' ) );
	}
	$export_dir_path = trailingslashit( $upload_dir['basedir'] ) . SSE_EXPORT_DIR_NAME;
	$display_path    = str_replace( ABSPATH, '', $export_dir_path );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php
		// Display deletion feedback notices from redirect. phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only parameter, no state change.
		if ( isset( $_GET['sse_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sse_notice_type = sanitize_key( $_GET['sse_notice'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'deleted' === $sse_notice_type ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Export file successfully deleted.', 'enginescript-site-exporter' ) . '</p></div>';
			} elseif ( 'delete_failed' === $sse_notice_type ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete export file.', 'enginescript-site-exporter' ) . '</p></div>';
			}
		}
		?>
		<p><?php esc_html_e( 'Click the button below to generate a zip archive containing your WordPress files and a database dump (.sql file).', 'enginescript-site-exporter' ); ?></p>
		<p><strong><?php esc_html_e( 'Warning:', 'enginescript-site-exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'enginescript-site-exporter' ); ?></p>
		<p class="sse-section-spacing">
			<?php
			// printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
			printf(
				// translators: %s: directory path.
				esc_html__( 'Exported .zip files will be saved in the following directory on the server: %s', 'enginescript-site-exporter' ),
				'<code>' . esc_html( $display_path ) . '</code>'
			);
			?>
		</p>
		<form method="post" action="" class="sse-section-spacing">
			<?php wp_nonce_field( 'sse_export_action', 'sse_export_nonce' ); ?>
			<input type="hidden" name="action" value="sse_export_site">
			
			<table class="form-table sse-form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="sse_max_file_size"><?php esc_html_e( 'Maximum File Size', 'enginescript-site-exporter' ); ?></label>
						</th>
						<td>
							<select name="sse_max_file_size" id="sse_max_file_size">
								<option value="0"><?php esc_html_e( 'No limit (include all files)', 'enginescript-site-exporter' ); ?></option>
								<option value="104857600"><?php esc_html_e( '100 MB', 'enginescript-site-exporter' ); ?></option>
								<option value="524288000"><?php esc_html_e( '500 MB', 'enginescript-site-exporter' ); ?></option>
								<option value="1073741824"><?php esc_html_e( '1 GB', 'enginescript-site-exporter' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Files larger than this size will be excluded from the export. Choose "No limit" to include all files regardless of size.', 'enginescript-site-exporter' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			
			<?php submit_button( __( 'Export Site', 'enginescript-site-exporter' ) ); ?>
		</form>
		<hr>
		<p>
			<?php esc_html_e( 'This plugin is part of the EngineScript project.', 'enginescript-site-exporter' ); ?>
			<a href="https://github.com/EngineScript/EngineScript" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Visit the EngineScript GitHub page', 'enginescript-site-exporter' ); ?>
			</a>
		</p>
		<p class="sse-warning-text">
			<?php esc_html_e( 'Important:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'The exported zip file is publicly accessible while it remains in the above directory. For security, you should remove the exported file from the server once you are finished downloading it.', 'enginescript-site-exporter' ); ?>
		</p>
		<p class="sse-warning-text">
			<?php esc_html_e( 'Security Notice:', 'enginescript-site-exporter' ); ?>
			<?php esc_html_e( 'For your protection, the exported zip file will be automatically deleted from the server 5 minutes after it is created.', 'enginescript-site-exporter' ); ?>
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
function sse_show_error_notice( string $message ): void {
	add_action(
		'admin_notices',
		function () use ( $message ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	);
	sse_log( 'Export error: ' . $message, 'error' );
}

/**
 * Shows a success notice to the user.
 *
 * @since 1.0.0
 * @param array $zip_result The zip file information.
 * @return void
 */
function sse_show_success_notice( array $zip_result ): void {
	add_action(
		'admin_notices',
		function () use ( $zip_result ) {
			$download_url = add_query_arg(
				[
					'sse_secure_download' => $zip_result['filename'],
					'sse_download_nonce'  => wp_create_nonce( 'sse_secure_download' ),
				],
				admin_url()
			);

			$delete_url = add_query_arg(
				[
					'sse_delete_export' => $zip_result['filename'],
					'sse_delete_nonce'  => wp_create_nonce( 'sse_delete_export' ),
				],
				admin_url()
			);

			$display_zip_path = str_replace( ABSPATH, '[wp-root]/', $zip_result['filepath'] );
			$display_zip_path = preg_replace( '|/+|', '/', $display_zip_path );
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php esc_html_e( 'Site export successfully created!', 'enginescript-site-exporter' ); ?>
					<a href="<?php echo esc_url( $download_url ); ?>" class="button sse-action-button">
						<?php esc_html_e( 'Download Export File', 'enginescript-site-exporter' ); ?>
					</a>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-secondary sse-action-button sse-confirm-delete">
						<?php esc_html_e( 'Delete Export File', 'enginescript-site-exporter' ); ?>
					</a>
				</p>
				<p><small>
					<?php
					printf(
						/* translators: %s: file path */
						esc_html__( 'File location: %s', 'enginescript-site-exporter' ),
						'<code title="' . esc_attr__( 'Path is relative to WordPress root directory', 'enginescript-site-exporter' ) . '">' .
						esc_html( $display_zip_path ) . '</code>'
					);
					?>
				</small></p>
			</div>
			<?php
		}
	);
	sse_log( 'Export successful. File saved to ' . $zip_result['filepath'], 'info' );
}
