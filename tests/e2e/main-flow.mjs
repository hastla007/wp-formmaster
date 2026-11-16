/**
 * Browser E2E for the main flow (P6-13, P3-16, P6-06 basics):
 * create a form from a template, publish it, embed it on a page, submit it with and
 * without JavaScript, manage the entry and export it as CSV.
 *
 * Requires a running site: `bin/e2e-site.sh start`. Run: `npm run e2e`.
 * Env: E2E_URL (http://127.0.0.1:8889), E2E_USER (admin), E2E_PASS (admin-password),
 * E2E_BROWSERS (chromium[,firefox,webkit]).
 */
import { createRequire } from 'node:module';
const require = createRequire( import.meta.url );
// Resolves a project-local playwright or a globally provided one (NODE_PATH).
const { chromium, firefox, webkit } = require( 'playwright' );
import assert from 'node:assert/strict';

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8889';
const USER = process.env.E2E_USER || 'admin';
const PASS = process.env.E2E_PASS || 'admin-password';
const engines = { chromium, firefox, webkit };
const browsers = ( process.env.E2E_BROWSERS || 'chromium' ).split( ',' ).map( ( b ) => b.trim() ).filter( Boolean );

const results = [];
async function step( name, fn ) {
	const started = Date.now();
	try {
		await fn();
		results.push( { name, ok: true, ms: Date.now() - started } );
		console.log( `  ✔ ${ name }` );
	} catch ( e ) {
		results.push( { name, ok: false, error: e } );
		console.log( `  ✘ ${ name }\n    ${ String( e && e.message ).split( '\n' ).join( '\n    ' ) }` );
		throw e;
	}
}

async function login( page ) {
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );
}

async function fillContact( page, name ) {
	await page.getByLabel( 'Name', { exact: false } ).first().fill( name );
	await page.getByLabel( 'Email address', { exact: false } ).fill( 'e2e@example.org' );
	await page.getByLabel( 'Subject', { exact: false } ).fill( '=HYPERLINK("http://example.invalid")' );
	await page.getByLabel( 'Message', { exact: false } ).fill( 'Hallo aus dem E2E-Test.\nZweite Zeile =1+1' );
	await page.locator( 'input[type="checkbox"]' ).last().check();
}

async function run( engineName ) {
	console.log( `\n[${ engineName }]` );
	const browser = await engines[ engineName ].launch();
	const admin = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	const page = await admin.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const stamp = Date.now().toString( 36 );
	let pageUrl = '';
	let formTitle = '';

	try {
		await step( 'Admin-Login', () => login( page ) );

		await step( 'Formular aus Vorlage „Kontakt“ anlegen', async () => {
			await page.goto( `${ BASE }/wp-admin/admin.php?page=form-captain-forms` );
			await Promise.all( [ page.waitForURL( /page=form-captain-edit/ ), page.locator( '.page-title-action', { hasText: 'Add new form' } ).click() ] );
			const create = page.locator( 'a[href*="template=contact"]' ).first();
			await Promise.all( [ page.waitForURL( /page=form-captain-edit/ ), create.click() ] );
			await page.waitForSelector( '.fc-field-list' );
			assert.ok( await page.locator( '.fc-field-item' ).count() >= 4, 'template fields in canvas' );
		} );

		await step( 'Formular veröffentlichen', async () => {
			await page.getByRole( 'button', { name: /^Publish/ } ).click();
			await page.locator( '#form-captain-editor' ).getByText( 'Published. Changes are now live.' ).waitFor( { timeout: 15000 } );
			assert.ok( ( await page.locator( '#a11y-speak-polite' ).innerText() ).includes( 'Published' ), 'publish announced to screen readers' );
		} );

		const form = await page.evaluate( async () => {
			const forms = await window.wp.apiFetch( { path: '/form-captain/v1/admin/forms?status=published' } );
			const list = Array.isArray( forms ) ? forms : forms.items || [];
			return list.sort( ( a, b ) => b.id - a.id )[ 0 ];
		} );
		assert.ok( form && form.public_id, 'published form returned by admin REST' );
		formTitle = form.title;

		await step( 'Seite mit Shortcode anlegen', async () => {
			const created = await page.evaluate( async ( { publicId, stamp } ) => {
				return window.wp.apiFetch( {
					path: '/wp/v2/pages',
					method: 'POST',
					data: { title: 'Kontakt E2E ' + stamp, status: 'publish', content: '<p>Schreiben Sie uns.</p>[form_captain id="' + publicId + '"]' },
				} );
			}, { publicId: form.public_id, stamp } );
			pageUrl = created.link;
			assert.ok( pageUrl, 'page link' );
		} );

		const visitor = await browser.newContext();
		const vpage = await visitor.newPage();
		vpage.on( 'pageerror', ( e ) => errors.push( e.message ) );

		await step( 'Frontend: barrierefreies Markup', async () => {
			await vpage.goto( pageUrl );
			const form = vpage.locator( 'form.fc-form, .fc-form-wrap form' ).first();
			await form.waitFor();
			// Every visible input has an accessible label.
			const unlabeled = await vpage.evaluate( () => [ ...document.querySelectorAll( '.fc-form-wrap input, .fc-form-wrap textarea, .fc-form-wrap select' ) ]
				.filter( ( el ) => el.type !== 'hidden' && el.offsetParent !== null && el.tabIndex !== -1 )
				.filter( ( el ) => ! ( el.labels && el.labels.length ) && ! el.getAttribute( 'aria-label' ) && ! el.getAttribute( 'aria-labelledby' ) )
				.map( ( el ) => el.name ) );
			assert.deepEqual( unlabeled, [], 'all inputs labelled' );
			const required = await vpage.locator( '[aria-required="true"], [required]' ).count();
			assert.ok( required >= 3, 'required fields marked' );
			// Assets only on pages with a form.
			const scripts = await vpage.evaluate( () => [ ...document.scripts ].map( ( s ) => s.src ).filter( ( s ) => s.includes( 'form-captain' ) ) );
			assert.ok( scripts.length >= 1, 'frontend script loaded on form page' );
		} );

		await step( 'Frontend: Pflichtfeldfehler mit Fokus und Ansage', async () => {
			await vpage.locator( '.fc-submit' ).first().click();
			await vpage.waitForSelector( '[aria-invalid="true"]' );
			const focused = await vpage.evaluate( () => document.activeElement && ( document.activeElement.getAttribute( 'role' ) || document.activeElement.tagName ) );
			assert.ok( focused, 'focus moved after error' );
		} );

		await step( 'Frontend: Einsendung mit JavaScript', async () => {
			await vpage.reload();
			await fillContact( vpage, 'E2E JS ' + stamp );
			await vpage.waitForTimeout( 3500 ); // minimum fill-in time
			await vpage.locator( '.fc-submit' ).first().click();
			await vpage.locator( '.fc-status' ).getByText( 'Thank you!' ).waitFor( { timeout: 15000 } );
		} );

		await step( 'Frontend: Einsendung ohne JavaScript (Progressive Enhancement)', async () => {
			const nojs = await browser.newContext( { javaScriptEnabled: false } );
			const p = await nojs.newPage();
			await p.goto( pageUrl );
			await fillContact( p, 'E2E NoJS ' + stamp );
			await p.waitForTimeout( 3500 );
			await Promise.all( [ p.waitForLoadState( 'load' ), p.locator( '.fc-submit' ).first().click() ] );
			await p.locator( '.fc-status' ).getByText( 'Thank you!' ).waitFor( { timeout: 15000 } );
			await nojs.close();
		} );

		await step( 'Seite ohne Formular lädt keine Form-Captain-Assets', async () => {
			await vpage.goto( BASE + '/' );
			const scripts = await vpage.evaluate( () => [ ...document.querySelectorAll( 'script[src],link[rel=stylesheet]' ) ].map( ( s ) => s.src || s.href ).filter( ( s ) => s.includes( 'form-captain' ) ) );
			assert.deepEqual( scripts, [] );
		} );
		await visitor.close();

		await step( 'Einträge verwalten: Liste, Detail, Status', async () => {
			await page.goto( `${ BASE }/wp-admin/admin.php?page=form-captain-entries` );
			await page.getByText( 'E2E JS ' + stamp ).first().waitFor();
			await page.getByText( 'E2E NoJS ' + stamp ).first().waitFor();
			const row = page.locator( 'tr', { hasText: 'E2E JS ' + stamp } ).first();
			const link = row.locator( 'a[href*="view="]' ).first();
			await Promise.all( [ page.waitForURL( /view=/ ), link.click() ] );
			await page.getByText( 'Hallo aus dem E2E-Test.' ).first().waitFor();
		} );

		await step( 'CSV-Export mit Formelschutz', async () => {
			await page.goto( `${ BASE }/wp-admin/admin.php?page=form-captain-entries&export=1&form_id=${ form.id }` );
			const [ download ] = await Promise.all( [
				page.waitForEvent( 'download' ),
				page.locator( 'form[action*="admin-post.php"] [type="submit"]' ).first().click(),
			] );
			const path = await download.path();
			const fs = await import( 'node:fs' );
			const csv = fs.readFileSync( path, 'utf8' );
			assert.ok( csv.includes( 'E2E JS ' + stamp ), 'entry in CSV' );
			assert.ok( csv.includes( "'=HYPERLINK" ), 'formula cell prefixed with apostrophe' );
			assert.ok( ! /(^|[,;])"?=HYPERLINK/m.test( csv ), 'no raw formula cell' );
		} );

		assert.deepEqual( errors, [], 'no JavaScript errors: ' + errors.join( ' | ' ) );
		console.log( `  form: ${ formTitle }` );
	} finally {
		await browser.close();
	}
}

let failed = false;
for ( const b of browsers ) {
	try {
		await run( b );
	} catch ( e ) {
		failed = true;
		if ( ! results.some( ( r ) => r.error === e ) ) {
			console.log( `  ✘ ${ b } konnte nicht gestartet werden: ${ String( e.message ).split( '\n' )[ 0 ] }` );
		}
	}
}
const ok = results.filter( ( r ) => r.ok ).length;
console.log( `\n${ ok }/${ results.length } steps passed` );
process.exit( failed ? 1 : 0 );
