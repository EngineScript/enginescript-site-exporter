/*
 * EngineScript Site Exporter — Admin Scripts
 *
 * Enqueued only on the registered single-site or Network Admin exporter page.
 *
 * Package: EngineScript_Site_Exporter
 * Since:   2.0.0
 */

( () => {
	// biome-ignore lint/suspicious/noRedundantUseStrict: WordPress loads this file as a classic script, not a module, so the directive is needed.
	'use strict';

	function handleDeleteSubmit( event ) {
		const confirmMessage = event.currentTarget?.dataset?.sseConfirmMessage;

		if ( ! confirmMessage ) {
			console.warn(
				'Site Exporter delete confirmation form is missing data-sse-confirm-message.'
			);
			event.preventDefault();
			return;
		}

		if ( ! globalThis.confirm( confirmMessage ) ) {
			event.preventDefault();
		}
	}

	// An export can run for minutes. Send it once, and say that it is running.
	function handleExportSubmit( event ) {
		const form = event.currentTarget;

		if ( form.dataset.sseSubmitted === 'true' ) {
			event.preventDefault();
			return;
		}

		form.dataset.sseSubmitted = 'true';

		const button = form.querySelector( '[type="submit"]' );
		if ( button ) {
			button.disabled = true;
		}

		const status = form.querySelector( '.sse-export-status' );
		if ( status ) {
			status.textContent = form.dataset.sseBusyText || '';
		}
	}

	// A page restored from the back-forward cache keeps its disabled button; make the form usable again.
	function resetExportForms( event ) {
		if ( ! event.persisted ) {
			return;
		}

		document.querySelectorAll( '.sse-export-form' ).forEach( ( form ) => {
			delete form.dataset.sseSubmitted;

			const button = form.querySelector( '[type="submit"]' );
			if ( button ) {
				button.disabled = false;
			}

			const status = form.querySelector( '.sse-export-status' );
			if ( status ) {
				status.textContent = '';
			}
		} );
	}

	document.querySelectorAll( '.sse-confirm-delete' ).forEach( ( form ) => {
		form.addEventListener( 'submit', handleDeleteSubmit );
	} );

	document.querySelectorAll( '.sse-export-form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', handleExportSubmit );
	} );

	globalThis.addEventListener( 'pageshow', resetExportForms );
} )();
