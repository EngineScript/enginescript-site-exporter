/**
 * EngineScript Site Exporter — Admin Scripts
 *
 * Enqueued only on the Site Exporter admin page (tools_page_enginescript-site-exporter).
 *
 * @package EngineScript_Site_Exporter
 * @since   2.1.0
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var deleteButtons = document.querySelectorAll( '.sse-confirm-delete' );

		deleteButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				/* global sseAdmin */
				var message = ( typeof sseAdmin !== 'undefined' && sseAdmin.confirmDelete )
					? sseAdmin.confirmDelete
					: 'Are you sure you want to delete this export file?';

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		} );
	} );
} )();
