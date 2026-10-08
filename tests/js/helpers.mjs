/**
 * Helpers for the admin script tests.
 *
 * The page markup comes from the plugin's own PHP (render-admin-page.php), and
 * the real js/admin.js runs inside jsdom. confirm() and the console are
 * replaced by recorders.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { JSDOM, VirtualConsole } from 'jsdom';

const renderScript = fileURLToPath( new URL( './render-admin-page.php', import.meta.url ) );

/** Markup rendered by PHP: the page with no archive, and the controls of one archive. */
export const page = JSON.parse(
	execFileSync( 'php', [ '-d', 'display_errors=stderr', renderScript ], { encoding: 'utf8' } )
);

/** The script under test. */
export const source = readFileSync( new URL( '../../js/admin.js', import.meta.url ), 'utf8' );

/**
 * Load the exporter page and run the admin script in it.
 *
 * @param {Object} options - Options.
 * @param {number} options.archives - How many archives the page lists.
 * @param {boolean} options.confirmResult - What confirm() answers.
 * @param {string|null} options.html - Markup to use in place of the rendered page.
 * @returns {Promise<Object>} The window, its document, and the recorders.
 */
export async function loadPage( { archives = 0, confirmResult = true, html = null } = {} ) {
	const warnings = [];
	const errors = [];
	const confirmations = [];
	const virtualConsole = new VirtualConsole();

	virtualConsole.on( 'warn', ( ...parts ) => warnings.push( parts.join( ' ' ) ) );
	virtualConsole.on( 'error', ( ...parts ) => errors.push( parts.join( ' ' ) ) );
	virtualConsole.on( 'jsdomError', ( error ) => errors.push( String( error ) ) );

	// jsdom wraps the rendered markup in html, head, and body elements.
	const dom = new JSDOM( html ?? page.html, {
		runScripts: 'outside-only',
		url: 'https://example.test/wp-admin/tools.php?page=enginescript-site-exporter',
		virtualConsole,
	} );
	const { window } = dom;
	const { document } = window;

	if ( archives > 0 ) {
		// The archive list comes before the export form; each row holds one set of controls.
		const list = document.createElement( 'div' );

		list.className = 'sse-test-archive-list';
		list.append( JSDOM.fragment( page.archive_actions.repeat( archives ) ) );
		document.querySelector( '.wrap' ).insertBefore( list, document.querySelector( '.sse-export-form' ) );
	}

	window.confirm = ( text ) => {
		confirmations.push( text );
		return confirmResult;
	};

	await new Promise( ( resolve ) => {
		if ( document.readyState === 'complete' ) {
			resolve();
		} else {
			window.addEventListener( 'load', resolve, { once: true } );
		}
	} );

	new vm.Script( source, { filename: 'js/admin.js' } ).runInContext( dom.getInternalVMContext() );

	const exportForm = document.querySelector( '.sse-export-form' );

	return {
		window,
		document,
		warnings,
		errors,
		confirmations,
		exportForm,
		exportButton: exportForm?.querySelector( '[type="submit"]' ) ?? null,
		status: exportForm?.querySelector( '.sse-export-status' ) ?? null,
		deleteForms: Array.from( document.querySelectorAll( '.sse-confirm-delete' ) ),
		close: () => window.close(),
	};
}

/**
 * Submit a form the way a click on its submit button would.
 *
 * @param {Object} view - Value returned by loadPage().
 * @param {HTMLFormElement} form - Form.
 * @returns {Event} The submit event; defaultPrevented tells whether the form would be sent.
 */
export function submit( view, form ) {
	const event = new view.window.Event( 'submit', { bubbles: true, cancelable: true } );

	form.dispatchEvent( event );

	return event;
}

/**
 * Show the page again, as the browser does after a load or when it restores the page from its back-forward cache.
 *
 * @param {Object} view - Value returned by loadPage().
 * @param {boolean} persisted - Whether the page came from the back-forward cache.
 * @returns {void}
 */
export function showPage( view, persisted ) {
	const event = new view.window.Event( 'pageshow' );

	Object.defineProperty( event, 'persisted', { value: persisted } );
	view.window.dispatchEvent( event );
}
