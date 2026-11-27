/**
 * Builds the Playground blueprint for the free plugin's demo site, which the
 * package server serves at /playground/modal-kit. The server installs the
 * latest release first, so the blueprint has no install step of its own.
 *
 * The demo content is bin/demos.php, the same script `npm run demos` runs on
 * the dev site: every free starter on a page of its own that explains when
 * it opens, an "All at once" page and an index, which becomes the front page.
 *
 *   npm run blueprint                write blueprints/modal-kit.json in the
 *                                    package server's checkout
 *                                    (PACKAGE_PROVIDER_PATH in .env)
 *   npm run blueprint -- --server=<file>
 *                                    write it somewhere else
 *   npm run blueprint -- --local     also write a test bundle in
 *                                    artifacts/blueprint/ that installs the
 *                                    local zip (npm run plugin-zip first)
 *
 * Try the bundle with:
 *   `npx @wp-playground/cli server --blueprint=artifacts/blueprint/ --blueprint-may-read-adjacent-files`
 */
/* eslint-disable no-console */
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	readFileSync,
	writeFileSync,
} from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { releaseZip } from './lib.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const LOCAL = join( ROOT, 'artifacts/blueprint' );
const ZIP = releaseZip( 'modal-kit' );

if ( existsSync( join( ROOT, '.env' ) ) ) {
	process.loadEnvFile( join( ROOT, '.env' ) );
}

const read = ( file ) =>
	readFileSync( join( ROOT, file ), 'utf8' ).replace( /\r\n/g, '\n' );

// demos.php after WordPress has loaded, as the demo site rather than a local
// one, then its index as the front page.
const demo = [
	'<?php',
	"require '/wordpress/wp-load.php';",
	"define( 'MODAL_KIT_DEMO_SITE', true );",
	'// The demo opens on its pages, not the first-run setup.',
	"delete_transient( 'modal_kit_wizard_redirect' );",
	"update_option( 'modal_kit_wizard_done', time(), false );",
	'?>',
	read( 'bin/demos.php' ).trimEnd(),
	'',
	"update_option( 'blogname', 'Modal Kit demo' );",
	"update_option( 'blogdescription', 'Modals made of blocks' );",
	"update_option( 'show_on_front', 'page' );",
	"update_option( 'page_on_front', $index );",
	'',
].join( '\n' );

/**
 * The blueprint, installing the plugin from the given resource, or with no
 * install step when there's none.
 *
 * @param {Object|null} plugin Plugin resource.
 * @return {Object} Blueprint.
 */
export function blueprint( plugin ) {
	return {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		meta: {
			title: 'Modal Kit demo',
			author: 'CoCart Headless, LLC',
			description:
				'Every free Modal Kit starter on a page of its own, opening on its real trigger, with an index of them all.',
		},
		landingPage: '/',
		preferredVersions: { php: '8.3', wp: 'latest' },
		features: { networking: true },
		login: true,
		steps: [
			...( plugin
				? [
						{
							step: 'installPlugin',
							pluginData: plugin,
							options: { activate: true },
						},
					]
				: [] ),
			{
				step: 'writeFile',
				path: '/wordpress/wp-content/mu-plugins/modal-kit-demos.php',
				data: read( 'bin/demos-mu-plugin.php' ),
			},
			{ step: 'runPHP', code: demo },
		],
	};
}

const write = ( file, data ) => {
	mkdirSync( dirname( file ), { recursive: true } );
	writeFileSync( file, JSON.stringify( data, null, '\t' ) + '\n' );
	console.log( `Wrote ${ file }` );
};

const server =
	process.argv
		.find( ( arg ) => arg.startsWith( '--server=' ) )
		?.slice( '--server='.length ) ??
	( process.env.PACKAGE_PROVIDER_PATH &&
		join( process.env.PACKAGE_PROVIDER_PATH, 'blueprints/modal-kit.json' ) );

if ( ! server ) {
	console.error(
		'Set PACKAGE_PROVIDER_PATH in .env, or pass --server=<file>.'
	);
	process.exit( 1 );
}

write( server, blueprint( null ) );

if ( process.argv.includes( '--local' ) ) {
	if ( ! existsSync( ZIP ) ) {
		console.error( `No ${ ZIP }: run npm run plugin-zip first.` );
		process.exit( 1 );
	}

	mkdirSync( LOCAL, { recursive: true } );
	copyFileSync( ZIP, join( LOCAL, 'modal-kit.zip' ) );
	write(
		join( LOCAL, 'blueprint.json' ),
		blueprint( { resource: 'bundled', path: '/modal-kit.zip' } )
	);
}
