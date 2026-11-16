import { chromium } from 'playwright';
const { chromium } = pw;
const dir = process.argv[ 2 ];
const browser = await chromium.launch();
const jobs = [
	[ 'icon.svg', 'icon-128x128.png', 128, 128 ],
	[ 'icon.svg', 'icon-256x256.png', 256, 256 ],
	[ 'banner.svg', 'banner-772x250.png', 772, 250 ],
	[ 'banner.svg', 'banner-1544x500.png', 1544, 500 ],
];
for ( const [ src, out, w, h ] of jobs ) {
	const page = await browser.newPage( { viewport: { width: w, height: h }, deviceScaleFactor: 1 } );
	const svg = readFileSync( `${ dir }/${ src }`, 'utf8' ).replace( '<svg ', `<svg width="${ w }" height="${ h }" ` );
	await page.setContent( `<html><body style="margin:0">${ svg }</body></html>` );
	await page.screenshot( { path: `${ dir }/${ out }`, clip: { x: 0, y: 0, width: w, height: h } } );
	console.log( out );
}
await browser.close();
