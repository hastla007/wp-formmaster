import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = 'http://127.0.0.1:8890';
const OUT = process.argv[ 2 ];
mkdirSync( OUT, { recursive: true } );
const browser = await chromium.launch();
const ctx = await browser.newContext( { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 } );
const page = await ctx.newPage();
await page.goto( `${ BASE }/wp-login.php?redirect_to=${ encodeURIComponent( BASE + '/wp-admin/admin.php?page=form-captain' ) }` );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'admin-password' );
await Promise.all( [ page.waitForURL( /page=form-captain/ ), page.click( '#wp-submit' ) ] );

async function shot( n, url, wait ) {
	await page.goto( BASE + url );
	if ( wait ) await page.waitForSelector( wait );
	await page.waitForTimeout( 600 );
	await page.screenshot( { path: `${ OUT }/screenshot-${ n }.png` } );
	console.log( 'ok', n, url );
}
await shot( 1, '/wp-admin/admin.php?page=form-captain' );
await shot( 2, '/wp-admin/admin.php?page=form-captain-forms' );
await page.goto( BASE + '/wp-admin/admin.php?page=form-captain-edit&form=1' );
await page.waitForSelector( '.fc-field-list' );
// select the message field so the settings panel shows field options
const item = page.locator( '.fc-field-list li' ).nth( 3 );
if ( await item.count() ) await item.click().catch( () => {} );
await page.waitForTimeout( 600 );
await page.screenshot( { path: `${ OUT }/screenshot-3.png` } );
console.log( 'ok', 3 );
const v = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
const vp = await v.newPage();
await vp.goto( BASE + '/contact/' );
await vp.waitForSelector( '.fc-form-wrap' );
await vp.waitForTimeout( 600 );
await vp.screenshot( { path: `${ OUT }/screenshot-4.png` } );
console.log( 'ok', 4 );
await shot( 5, '/wp-admin/admin.php?page=form-captain-entries' );
await shot( 6, '/wp-admin/admin.php?page=form-captain-entries&view=1' );
await shot( 7, '/wp-admin/admin.php?page=form-captain-settings&tab=privacy' );
await shot( 8, '/wp-admin/admin.php?page=form-captain-settings&tab=status' );
await browser.close();
