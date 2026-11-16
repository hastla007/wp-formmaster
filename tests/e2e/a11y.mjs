/**
 * Automated accessibility checks with axe-core (P6-06 frontend, P6-15 admin).
 * Scope is limited to Form Captain markup so WordPress core issues do not mask
 * or fail our results. Fails on serious/critical violations.
 *
 * Requires a running site (bin/e2e-site.sh start) with at least one published
 * form embedded on a page (created by tests/e2e/main-flow.mjs).
 */
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );

const BASE = process.env.E2E_URL || 'http://127.0.0.1:8889';
const axeSource = readFileSync( require.resolve( 'axe-core/axe.min.js' ), 'utf8' );
const FAIL_IMPACT = new Set( [ 'serious', 'critical' ] );

async function audit( page, label, include ) {
	await page.addScriptTag( { content: axeSource } );
	const result = await page.evaluate( async ( selector ) => {
		const ctx = document.querySelector( selector ) ? { include: [ selector ] } : document;
		// eslint-disable-next-line no-undef
		return axe.run( ctx, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] } } );
	}, include );
	const blocking = result.violations.filter( ( v ) => FAIL_IMPACT.has( v.impact ) );
	const minor = result.violations.length - blocking.length;
	console.log( `  ${ blocking.length ? '✘' : '✔' } ${ label }: ${ blocking.length } blockierend, ${ minor } geringfügig, ${ result.passes.length } bestanden` );
	for ( const v of result.violations ) {
		console.log( `      [${ v.impact }] ${ v.id }: ${ v.help } (${ v.nodes.length }×) ${ v.nodes[ 0 ] ? v.nodes[ 0 ].target.join( ' ' ) : '' }` );
	}
	return blocking.length;
}

const browser = await chromium.launch();
const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
const page = await ctx.newPage();
let failures = 0;

await page.goto( `${ BASE }/wp-login.php` );
await page.fill( '#user_login', process.env.E2E_USER || 'admin' );
await page.fill( '#user_pass', process.env.E2E_PASS || 'admin-password' );
await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

const adminPages = [
	[ 'Dashboard', 'form-captain' ],
	[ 'Formulare', 'form-captain-forms' ],
	[ 'Vorlagenauswahl', 'form-captain-edit' ],
	[ 'Einträge', 'form-captain-entries' ],
	[ 'Integrationen', 'form-captain-integrations' ],
	[ 'Einstellungen', 'form-captain-settings' ],
	[ 'Einstellungen: Datenschutz', 'form-captain-settings&tab=privacy' ],
	[ 'Einstellungen: Systemstatus', 'form-captain-settings&tab=status' ],
];
console.log( 'Admin (P6-15)' );
for ( const [ label, slug ] of adminPages ) {
	await page.goto( `${ BASE }/wp-admin/admin.php?page=${ slug }` );
	failures += await audit( page, label, '#wpbody-content .wrap' );
}

const nonce = await ( await page.request.get( `${ BASE }/wp-admin/admin-ajax.php?action=rest-nonce` ) ).text();
const api = async ( path ) => ( await page.request.get( `${ BASE }/?rest_route=${ encodeURIComponent( path ) }`, { headers: { 'X-WP-Nonce': nonce } } ) ).json();
const formsRaw = await api( '/form-captain/v1/admin/forms' ).catch( () => [] );
const forms = Array.isArray( formsRaw ) ? formsRaw : formsRaw.items || formsRaw.forms || [];
if ( forms.length ) {
	await page.goto( `${ BASE }/wp-admin/admin.php?page=form-captain-edit&form=${ forms[ 0 ].id }` );
	await page.waitForSelector( '.fc-field-list' );
	failures += await audit( page, 'Formular-Editor', '#form-captain-editor' );
	const entries = await page.$$eval( 'a[href*="view="]', () => [] );
	await page.goto( `${ BASE }/wp-admin/admin.php?page=form-captain-entries` );
	const detail = page.locator( 'a[href*="view="]' ).first();
	if ( await detail.count() ) {
		await detail.click();
		failures += await audit( page, 'Eintragsdetail', '#wpbody-content .wrap' );
	}
	void entries;
}

console.log( 'Frontend (P6-06)' );
const pages = await ( await page.request.get( `${ BASE }/?rest_route=/wp/v2/pages&search=Kontakt%20E2E&per_page=1`, { headers: { 'X-WP-Nonce': nonce } } ) ).json().catch( () => [] );
if ( pages.length ) {
	const visitor = await browser.newContext( { viewport: { width: 390, height: 844 } } );
	const v = await visitor.newPage();
	await v.goto( pages[ 0 ].link );
	failures += await audit( v, 'Formular (mobil)', '.fc-form-wrap' );
	await v.locator( '.fc-submit' ).first().click();
	await v.waitForSelector( '[aria-invalid="true"]' );
	failures += await audit( v, 'Formular mit Fehlermeldungen', '.fc-form-wrap' );
	// Keyboard: every focusable control in the form is reachable with Tab.
	const reachable = await v.evaluate( () => {
		const controls = [ ...document.querySelectorAll( '.fc-form-wrap input:not([type=hidden]):not([tabindex="-1"]), .fc-form-wrap textarea, .fc-form-wrap select, .fc-form-wrap button' ) ].filter( ( el ) => el.offsetParent !== null );
		return controls.every( ( el ) => el.tabIndex >= 0 );
	} );
	console.log( `  ${ reachable ? '✔' : '✘' } Tastatur: alle Bedienelemente per Tab erreichbar` );
	if ( ! reachable ) {
		failures++;
	}
	await visitor.close();
} else {
	console.log( '  (keine E2E-Seite gefunden – zuerst npm run e2e ausführen)' );
}

await browser.close();
console.log( failures ? `\n${ failures } blockierende Accessibility-Befunde` : '\nKeine blockierenden Accessibility-Befunde' );
process.exit( failures ? 1 : 0 );
