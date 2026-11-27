/**
 * Builds the Playground blueprints for the demo sites, which the package
 * server serves at /playground/modal-kit and /playground/modal-kit-pro. The
 * server installs the latest releases itself (Modal Kit, then Pro), right
 * after the blueprint's last installPlugin step, so the blueprints have no
 * install step for them.
 *
 * The demo content is bin/demos.php, the same script `npm run demos` runs on
 * the dev site: every starter on a page of its own that explains when it
 * opens, an "All at once" page and a welcome page, which becomes the front
 * page. bin/demo-site.php then tidies the site: no sample plugins or
 * content, and a footer with links for the demo. The Pro demo adds
 * WooCommerce, for the store starters.
 *
 *   npm run blueprint                write blueprints/modal-kit.json and
 *                                    blueprints/modal-kit-pro.json in the
 *                                    package server's checkout
 *                                    (PACKAGE_PROVIDER_PATH in .env)
 *   npm run blueprint -- --server=<folder>
 *                                    write them somewhere else
 *   npm run blueprint -- --local     also write a test bundle in
 *                                    artifacts/blueprint/ that installs the
 *                                    local zip (npm run plugin-zip first)
 *   npm run blueprint -- --local --pro
 *                                    the same for the Pro demo, with Pro's
 *                                    zip from ../modal-kit-pro/releases/
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
	rmSync,
	writeFileSync,
} from 'fs';
import { dirname, join } from 'path';
import { fileURLToPath } from 'url';
import { releaseZip } from './lib.mjs';

const ROOT = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const LOCAL = join( ROOT, 'artifacts/blueprint' );
const ZIP = releaseZip( 'modal-kit' );
const PRO = join( ROOT, '../modal-kit-pro' );

if ( existsSync( join( ROOT, '.env' ) ) ) {
	process.loadEnvFile( join( ROOT, '.env' ) );
}

const read = ( file ) =>
	readFileSync( join( ROOT, file ), 'utf8' ).replace( /\r\n/g, '\n' );

// A PHP file run after WordPress has loaded, as the demo site.
const script = ( before, file, after = [] ) =>
	[
		'<?php',
		"require '/wordpress/wp-load.php';",
		"define( 'MODAL_KIT_DEMO_SITE', true );",
		...before,
		'?>',
		read( file ).trimEnd(),
		'',
		...after,
		'',
	].join( '\n' );

/**
 * The steps that build the demo content.
 *
 * @param {boolean} pro Whether it's the Pro demo.
 * @return {Object[]} Blueprint steps.
 */
const content = ( pro ) => [
	...( pro
		? [
				{
					step: 'runPHP',
					code: [
						'<?php',
						"require '/wordpress/wp-load.php';",
						'// A store that is open, with its pages, and no setup wizard.',
						"update_option( 'woocommerce_coming_soon', 'no' );",
						"update_option( 'woocommerce_store_pages_only', 'no' );",
						"update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );",
						"update_option( 'woocommerce_task_list_hidden', 'yes' );",
						"update_option( 'woocommerce_show_marketplace_suggestions', 'no' );",
						"if ( class_exists( 'WC_Install' ) ) { WC_Install::create_pages(); }",
						'',
					].join( '\n' ),
				},
			]
		: [] ),
	{
		step: 'writeFile',
		path: '/wordpress/wp-content/mu-plugins/modal-kit-demos.php',
		data: read( 'bin/demos-mu-plugin.php' ),
	},
	{
		step: 'runPHP',
		code: script(
			[
				'// The demo opens on its pages, not the first-run setup.',
				"delete_transient( 'modal_kit_wizard_redirect' );",
				"update_option( 'modal_kit_wizard_done', time(), false );",
				'// Every modal opens every time, so visitors can go from demo to demo:',
				'// no auto-open limit, no cool-down, and nothing remembered.',
				'update_option(',
				"\t'modal_kit_general',",
				"\tarray_merge( (array) get_option( 'modal_kit_general', array() ), array( 'autoOpenLimit' => 0, 'cooldown' => 0, 'remember' => 'none' ) )",
				');',
			],
			'bin/demos.php',
			[
				`update_option( 'blogname', '${
					pro ? 'Modal Kit Pro demo' : 'Modal Kit demo'
				}' );`,
				"update_option( 'blogdescription', 'Modals made of blocks' );",
				"update_option( 'show_on_front', 'page' );",
				"update_option( 'page_on_front', $index );",
			]
		),
	},
	{ step: 'runPHP', code: script( [], 'bin/demo-site.php' ) },
];

/**
 * A demo's blueprint, installing the given plugin resources first, or with
 * no install step for Modal Kit's own plugins when there are none.
 *
 * @param {boolean}  pro     Whether it's the Pro demo.
 * @param {Object[]} plugins Plugin resources, in install order.
 * @return {Object} Blueprint.
 */
export function blueprint( pro, plugins = [] ) {
	const install = ( pluginData ) => ( {
		step: 'installPlugin',
		pluginData,
		options: { activate: true },
	} );

	return {
		$schema: 'https://playground.wordpress.net/blueprint-schema.json',
		meta: {
			title: pro ? 'Modal Kit Pro demo' : 'Modal Kit demo',
			author: 'CoCart Headless, LLC',
			description: pro
				? 'Every Modal Kit starter and Pro feature on a page of its own, opening on its real trigger, with a WooCommerce store for the store starters.'
				: 'Every free Modal Kit starter on a page of its own, opening on its real trigger, with an index of them all.',
		},
		landingPage: '/',
		preferredVersions: { php: '8.3', wp: 'latest' },
		features: { networking: true },
		login: true,
		steps: [
			// No theme or plugin file editor in the demo.
			{
				step: 'defineWpConfigConsts',
				consts: { DISALLOW_FILE_EDIT: true },
			},
			...( pro
				? [
						install( {
							resource: 'wordpress.org/plugins',
							slug: 'woocommerce',
						} ),
					]
				: [] ),
			...plugins.map( install ),
			...content( pro ),
		],
	};
}

const write = ( file, data ) => {
	mkdirSync( dirname( file ), { recursive: true } );
	writeFileSync( file, JSON.stringify( data, null, '\t' ) + '\n' );
	console.log( `Wrote ${ file }` );
};

const folder =
	process.argv
		.find( ( arg ) => arg.startsWith( '--server=' ) )
		?.slice( '--server='.length ) ??
	( process.env.PACKAGE_PROVIDER_PATH &&
		join( process.env.PACKAGE_PROVIDER_PATH, 'blueprints' ) );

if ( ! folder ) {
	console.error(
		'Set PACKAGE_PROVIDER_PATH in .env, or pass --server=<folder>.'
	);
	process.exit( 1 );
}

write( join( folder, 'modal-kit.json' ), blueprint( false ) );
write( join( folder, 'modal-kit-pro.json' ), blueprint( true ) );

if ( process.argv.includes( '--local' ) ) {
	const pro = process.argv.includes( '--pro' );
	const proZip =
		pro &&
		join(
			PRO,
			'releases',
			`modal-kit-pro-${
				JSON.parse(
					readFileSync( join( PRO, 'package.json' ), 'utf8' )
				).version
			}.zip`
		);

	for ( const zip of [ ZIP, proZip ].filter( Boolean ) ) {
		if ( ! existsSync( zip ) ) {
			console.error( `No ${ zip }: build its zip first.` );
			process.exit( 1 );
		}
	}

	rmSync( LOCAL, { recursive: true, force: true } );
	mkdirSync( LOCAL, { recursive: true } );
	copyFileSync( ZIP, join( LOCAL, 'modal-kit.zip' ) );

	const plugins = [ { resource: 'bundled', path: '/modal-kit.zip' } ];

	if ( pro ) {
		copyFileSync( proZip, join( LOCAL, 'modal-kit-pro.zip' ) );
		plugins.push( { resource: 'bundled', path: '/modal-kit-pro.zip' } );
	}

	write( join( LOCAL, 'blueprint.json' ), blueprint( pro, plugins ) );
}
