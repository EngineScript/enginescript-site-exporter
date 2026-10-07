/**
 * Tests for js/admin.js.
 *
 * Run with `npm test`. See helpers.mjs for how the page is built.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { loadPage, page, showPage, source, submit } from './helpers.mjs';

let view = null;

afterEach( () => {
	view?.close();
	view = null;
} );

test( 'every selector the script uses exists in the rendered page', async () => {
	const selectors = Array.from(
		source.matchAll( /\b(document|form)\.querySelector(?:All)?\(\s*'([^']+)'/g ),
		( match ) => ( { scope: match[ 1 ], selector: match[ 2 ] } )
	);

	assert.ok( selectors.length >= 4, 'The pattern found the selectors the script uses.' );
	assert.equal(
		( source.match( /querySelector(?:All)?\(/g ) || [] ).length,
		selectors.length,
		'Every selector in the script is a plain string that this test can read.'
	);

	view = await loadPage( { archives: 1 } );

	for ( const { scope, selector } of selectors ) {
		// A selector the script applies to a form is looked up inside the export form.
		const root = scope === 'form' ? view.exportForm : view.document;

		assert.ok( root.querySelector( selector ), `${ selector } is in the ${ scope === 'form' ? 'export form' : 'page' }.` );
	}
} );

test( 'every data attribute the script reads is printed by PHP', async () => {
	const used = new Set( Array.from( source.matchAll( /\bdataset\??\.(sse[A-Za-z]+)/g ), ( match ) => match[ 1 ] ) );
	const written = new Set( Array.from( source.matchAll( /\bdataset\.(sse[A-Za-z]+)\s*=[^=]/g ), ( match ) => match[ 1 ] ) );
	const read = [ ...used ].filter( ( key ) => ! written.has( key ) );

	assert.deepEqual( read.sort(), [ 'sseBusyText', 'sseConfirmMessage' ], 'The attributes the script reads and does not set itself.' );

	view = await loadPage( { archives: 1 } );

	assert.ok( view.exportForm.dataset.sseBusyText.length > 20, 'The export form carries its busy text.' );
	assert.ok( view.deleteForms[ 0 ].dataset.sseConfirmMessage.length > 20, 'The delete form carries its confirmation text.' );
	assert.equal( view.exportForm.dataset.sseSubmitted, undefined, 'PHP does not print the marker the script sets.' );
} );

test( 'the script loads without an error or a warning', async () => {
	view = await loadPage( { archives: 2 } );

	assert.deepEqual( view.errors, [] );
	assert.deepEqual( view.warnings, [] );
} );

test( 'before a submission the button is usable and the status line is empty', async () => {
	view = await loadPage();

	assert.equal( view.exportButton.disabled, false );
	assert.equal( view.status.textContent, '' );
	assert.equal( view.status.getAttribute( 'role' ), 'status', 'The status line is announced when it changes.' );
} );

test( 'the first submission is sent, disables the button, and says that the export is running', async () => {
	view = await loadPage();

	const event = submit( view, view.exportForm );

	assert.equal( event.defaultPrevented, false, 'The form is sent.' );
	assert.equal( view.exportButton.disabled, true );
	assert.equal( view.status.textContent, view.exportForm.dataset.sseBusyText );
	assert.match( view.status.textContent, /export is running/i );
} );

test( 'a second submission is stopped and changes nothing', async () => {
	view = await loadPage();

	submit( view, view.exportForm );
	const statusAfterFirst = view.status.textContent;
	const second = submit( view, view.exportForm );
	const third = submit( view, view.exportForm );

	assert.equal( second.defaultPrevented, true );
	assert.equal( third.defaultPrevented, true );
	assert.equal( view.exportButton.disabled, true );
	assert.equal( view.status.textContent, statusAfterFirst );
} );

test( 'the fields the server needs are still sent after the button is disabled', async () => {
	view = await loadPage();

	submit( view, view.exportForm );

	const sent = Object.fromEntries( new view.window.FormData( view.exportForm ).entries() );

	assert.equal( sent.action, 'sse_export_site' );
	assert.ok( sent.sse_export_nonce.length > 0 );
	assert.equal( sent.sse_max_file_size, '0' );
} );

test( 'the size the user chose is the size that is sent', async () => {
	view = await loadPage();

	const select = view.document.querySelector( '#sse_max_file_size' );

	select.value = '104857600';
	submit( view, view.exportForm );

	assert.equal( new view.window.FormData( view.exportForm ).get( 'sse_max_file_size' ), '104857600' );
} );

test( 'the busy text is shown as text, never as markup', async () => {
	view = await loadPage();
	view.exportForm.dataset.sseBusyText = '<img src=x onerror="window.injected = true"> running';

	submit( view, view.exportForm );

	assert.equal( view.status.textContent, '<img src=x onerror="window.injected = true"> running' );
	assert.equal( view.status.children.length, 0 );
	assert.equal( view.window.injected, undefined );
} );

test( 'a form without busy text still stops a second submission', async () => {
	view = await loadPage();
	delete view.exportForm.dataset.sseBusyText;

	const first = submit( view, view.exportForm );
	const second = submit( view, view.exportForm );

	assert.equal( first.defaultPrevented, false );
	assert.equal( second.defaultPrevented, true );
	assert.equal( view.status.textContent, '' );
} );

test( 'a page restored from the back-forward cache gets a usable form again', async () => {
	view = await loadPage();

	submit( view, view.exportForm );
	showPage( view, true );

	assert.equal( view.exportButton.disabled, false );
	assert.equal( view.status.textContent, '' );
	assert.equal( view.exportForm.dataset.sseSubmitted, undefined );

	const next = submit( view, view.exportForm );

	assert.equal( next.defaultPrevented, false, 'A new export can be started.' );
	assert.equal( view.exportButton.disabled, true );
} );

test( 'an ordinary page load does not re-enable a running export', async () => {
	view = await loadPage();

	submit( view, view.exportForm );
	showPage( view, false );

	assert.equal( view.exportButton.disabled, true );
	assert.equal( view.status.textContent, view.exportForm.dataset.sseBusyText );
	assert.equal( submit( view, view.exportForm ).defaultPrevented, true );
} );

test( 'deleting asks first, with the text PHP provides, and is sent when confirmed', async () => {
	view = await loadPage( { archives: 1, confirmResult: true } );

	const event = submit( view, view.deleteForms[ 0 ] );

	assert.deepEqual( view.confirmations, [ 'Are you sure you want to delete this export file?' ] );
	assert.equal( event.defaultPrevented, false );
} );

test( 'declining the confirmation deletes nothing', async () => {
	view = await loadPage( { archives: 1, confirmResult: false } );

	const event = submit( view, view.deleteForms[ 0 ] );

	assert.equal( view.confirmations.length, 1 );
	assert.equal( event.defaultPrevented, true );
} );

test( 'every listed archive asks before it is deleted', async () => {
	view = await loadPage( { archives: 3, confirmResult: false } );

	assert.equal( view.deleteForms.length, 3 );

	for ( const form of view.deleteForms ) {
		assert.equal( submit( view, form ).defaultPrevented, true );
	}

	assert.equal( view.confirmations.length, 3 );
} );

test( 'a delete form without its confirmation text is not sent, and the console says why', async () => {
	view = await loadPage( { archives: 1, confirmResult: true } );
	view.deleteForms[ 0 ].removeAttribute( 'data-sse-confirm-message' );

	const event = submit( view, view.deleteForms[ 0 ] );

	assert.equal( event.defaultPrevented, true );
	assert.deepEqual( view.confirmations, [], 'Nothing is asked with an empty question.' );
	assert.equal( view.warnings.length, 1 );
	assert.match( view.warnings[ 0 ], /data-sse-confirm-message/ );
} );

test( 'an empty confirmation text is treated as missing', async () => {
	view = await loadPage( { archives: 1, confirmResult: true } );
	view.deleteForms[ 0 ].dataset.sseConfirmMessage = '';

	assert.equal( submit( view, view.deleteForms[ 0 ] ).defaultPrevented, true );
	assert.deepEqual( view.confirmations, [] );
} );

test( 'deleting does not touch the export form, and exporting does not ask for confirmation', async () => {
	view = await loadPage( { archives: 1, confirmResult: true } );

	submit( view, view.deleteForms[ 0 ] );

	assert.equal( view.exportButton.disabled, false );
	assert.equal( view.status.textContent, '' );

	submit( view, view.exportForm );

	assert.equal( view.confirmations.length, 1, 'Only the deletion asked.' );
} );

test( 'a form that is not the exporter\'s is left alone', async () => {
	view = await loadPage();

	const other = view.document.createElement( 'form' );

	other.innerHTML = '<input type="submit" value="Save">';
	view.document.body.append( other );

	const first = submit( view, other );
	const second = submit( view, other );

	assert.equal( first.defaultPrevented, false );
	assert.equal( second.defaultPrevented, false );
	assert.equal( other.querySelector( '[type="submit"]' ).disabled, false );
	assert.deepEqual( view.confirmations, [] );
} );

test( 'on a page without the exporter\'s forms the script does nothing and reports nothing', async () => {
	view = await loadPage( { html: '<div class="wrap"><h1>Tools</h1></div>' } );

	showPage( view, true );

	assert.deepEqual( view.errors, [] );
	assert.deepEqual( view.warnings, [] );
} );

test( 'the rendered page has one export form and no archive controls when no export exists', () => {
	assert.equal( ( page.html.match( /class="[^"]*\bsse-export-form\b/g ) || [] ).length, 1 );
	assert.equal( page.html.includes( 'sse-confirm-delete' ), false );
	assert.equal( ( page.archive_actions.match( /class="[^"]*\bsse-confirm-delete\b/g ) || [] ).length, 1 );
} );
