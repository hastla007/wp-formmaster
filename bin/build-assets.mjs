#!/usr/bin/env node
/**
 * Reproducible asset build (P1-13): assets/src/*.{js,css} → assets/build/*.{js,css} + manifest.json.
 * `--check` fails if the committed build differs from a fresh build (used in CI).
 */
import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const src = join( root, 'assets/src' );
const out = join( root, 'assets/build' );
const check = process.argv.includes( '--check' );

mkdirSync( out, { recursive: true } );
const files = readdirSync( src ).filter( ( f ) => /\.(js|css)$/.test( f ) ).sort();
const manifest = {};
let dirty = false;

for ( const file of files ) {
	const result = await build( {
		entryPoints: [ join( src, file ) ],
		bundle: false,
		minify: true,
		target: file.endsWith( '.js' ) ? 'es2019' : 'chrome80',
		legalComments: 'none',
		write: false,
		charset: 'utf8',
		logLevel: 'error',
	} );
	const code = result.outputFiles[ 0 ].text;
	const dest = join( out, file );
	manifest[ file ] = createHash( 'sha256' ).update( code ).digest( 'hex' ).slice( 0, 10 );
	if ( check ) {
		if ( ! existsSync( dest ) || readFileSync( dest, 'utf8' ) !== code ) {
			console.error( `Out of date: assets/build/${ file }` );
			dirty = true;
		}
	} else {
		writeFileSync( dest, code );
	}
}

const manifestJson = JSON.stringify( manifest, null, 2 ) + '\n';
const manifestPath = join( out, 'manifest.json' );
if ( check ) {
	if ( ! existsSync( manifestPath ) || readFileSync( manifestPath, 'utf8' ) !== manifestJson ) {
		console.error( 'Out of date: assets/build/manifest.json' );
		dirty = true;
	}
	if ( dirty ) {
		console.error( 'Run `npm run build` and commit the result.' );
		process.exit( 1 );
	}
	console.log( 'Build is up to date.' );
} else {
	writeFileSync( manifestPath, manifestJson );
	console.log( `Built ${ files.length } assets.` );
}
