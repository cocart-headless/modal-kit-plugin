/**
 * Captures a screenshot of every feature, editor panel, settings tab and
 * setup step into .wordpress-org/screenshots/ from the Laragon test site:
 * `npm run screenshots`. Every shot is the whole 1920×1080 window, never
 * cropped or scaled. It runs with only Modal Kit active, creates sample
 * modals from the starter templates and removes them afterwards, and writes
 * README.md listing what each shot shows.
 */
/* eslint-disable no-console */
const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const BASE = 'http://localhost:8082';
const OUT = path.join( __dirname, '..', '.wordpress-org', 'screenshots' );
const VIEW = { width: 1920, height: 1080 };

const shots = [];
let count = 0;

/**
 * Saves the window as the next numbered shot.
 *
 * @param {import('playwright').Page} page    Page.
 * @param {string}                    name    File name part.
 * @param {string}                    caption What it shows.
 */
async function shoot( page, name, caption ) {
	count++;
	const file = `${ String( count ).padStart( 2, '0' ) }-${ name }.png`;
	await page.screenshot( { path: path.join( OUT, file ) } );
	shots.push( [ file, caption ] );
	console.log( file );
}

async function rest( page, pathname, options = {} ) {
	return page.evaluate(
		async ( request ) => {
			const nonce = await (
				await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' )
			).text();
			const res = await fetch( '/wp-json' + request.pathname, {
				method: request.options.method ?? 'GET',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: request.options.data
					? JSON.stringify( request.options.data )
					: undefined,
			} );
			return res.json();
		},
		{ pathname, options }
	);
}

/**
 * Sets attributes on the outer Modal block comment of pattern content.
 *
 * @param {string} content Pattern content.
 * @param {Object} changes Attributes to set.
 * @return {string} Content.
 */
function withAttributes( content, changes ) {
	return content.replace(
		/<!-- wp:modal-kit\/modal( \{.*?\})? -->/,
		( all, json ) => {
			const attrs = { ...( json ? JSON.parse( json ) : {} ), ...changes };
			return `<!-- wp:modal-kit/modal ${ JSON.stringify( attrs ) } -->`;
		}
	);
}

const HIDE_SCROLLBARS =
	'::-webkit-scrollbar{display:none}html{scrollbar-width:none}';

async function openFront( page, slug ) {
	await page.goto( `${ BASE }/?modal=${ slug }` );
	await page.addStyleTag( { content: HIDE_SCROLLBARS } );
	await page
		.locator( `#modal-${ slug }[open]` )
		.waitFor( { timeout: 15000 } );
	await page.waitForTimeout( 1200 );
	// Non-blocking layouts let other page-load modals open too: close them
	// so each shot shows one modal.
	const others = await page
		.locator( `dialog[open]:not(#modal-${ slug }) .modal-kit__close` )
		.all();
	for ( const close of others ) {
		await close.click();
	}
	if ( others.length ) {
		await page.waitForTimeout( 600 );
	}
}

async function editModal( page, id ) {
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ id }&action=edit` );
	await page.waitForFunction(
		() =>
			window.wp?.data?.select( 'core/block-editor' )?.getBlockOrder()
				.length > 0,
		null,
		{ timeout: 30000 }
	);
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core', 'welcomeGuide', false );
		preferences.set( 'core', 'distractionFree', false );
		preferences.set( 'core/edit-post', 'fullscreenMode', false );
	} );
	await page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.locator( '.modal-kit.is-editor-preview' )
		.waitFor( { timeout: 30000 } );
	const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
	if ( ! ( await sidebar.isVisible().catch( () => false ) ) ) {
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
	}
	return sidebar;
}

/**
 * Opens a sidebar panel (they remember their state per user) and scrolls
 * it to the top of the sidebar.
 *
 * @param {import('playwright').Locator} sidebar Sidebar.
 * @param {RegExp|string}                name    Panel button name.
 */
async function openPanel( sidebar, name ) {
	const button = sidebar.getByRole( 'button', { name } ).first();
	await button.scrollIntoViewIfNeeded();
	if ( ( await button.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await button.click();
	}
	await button.evaluate( ( el ) => el.scrollIntoView( { block: 'start' } ) );
	await sidebar.page().waitForTimeout( 500 );
}

async function closePanels( sidebar ) {
	// One at a time: closing a panel changes which are open.
	const open = sidebar.locator(
		'.components-panel__body-toggle[aria-expanded="true"]'
	);
	for ( let i = 0; i < 20 && ( await open.count() ); i++ ) {
		await open.first().click();
	}
}

// Puts the site back as it was, even when a shot fails.
const cleanup = [];

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );
	for ( const file of fs.readdirSync( OUT ) ) {
		if ( file.endsWith( '.png' ) ) {
			fs.unlinkSync( path.join( OUT, file ) );
		}
	}

	const browser = await chromium.launch();
	// Runs last, so the script ends even when a shot fails.
	cleanup.push( () => browser.close() );
	const context = await browser.newContext( { viewport: VIEW } );
	// A step that can't find what it waits for fails instead of hanging.
	context.setDefaultTimeout( 30000 );
	const page = await context.newPage();
	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
	await page.evaluate( () => window.localStorage.clear() );

	// Only Modal Kit: other plugins on the test site would show in the admin
	// bar and menu.
	const others = (
		await rest( page, '/wp/v2/plugins?status=active&_fields=plugin' )
	)
		.map( ( p ) => p.plugin )
		.filter( ( plugin ) => ! plugin.startsWith( 'modal-kit/' ) );
	const setPlugins = ( status ) =>
		Promise.all(
			others.map( ( plugin ) =>
				rest( page, `/wp/v2/plugins/${ plugin }`, {
					method: 'POST',
					data: { status },
				} )
			)
		);
	await setPlugins( 'inactive' );
	cleanup.push( () => setPlugins( 'active' ) );

	// Update counts are the test site's, not the product's.
	await context.addInitScript( () =>
		document.addEventListener( 'DOMContentLoaded', () => {
			const style = document.createElement( 'style' );
			style.textContent =
				'#wp-admin-bar-updates, #adminmenu .update-plugins, #adminmenu .awaiting-mod { display: none !important; }';
			document.head.append( style );
		} )
	);

	// The theme's header lists every published page: keep only Sample Page.
	const hidden = (
		await rest(
			page,
			'/wp/v2/pages?status=publish&per_page=100&_fields=id,slug'
		)
	)
		.filter( ( p ) => p.slug !== 'sample-page' )
		.map( ( p ) => p.id );
	const setStatus = ( status ) =>
		Promise.all(
			hidden.map( ( id ) =>
				rest( page, `/wp/v2/pages/${ id }`, {
					method: 'POST',
					data: { status },
				} )
			)
		);
	await setStatus( 'draft' );
	cleanup.push( () => setStatus( 'publish' ) );

	// Sample modals from the starter templates.
	const patterns = await rest( page, '/wp/v2/block-patterns/patterns' );
	const content = ( name ) =>
		patterns.find( ( p ) => p.name === `modal-kit/${ name }` ).content;
	const created = {};
	cleanup.push( async () => {
		for ( const id of Object.values( created ) ) {
			await rest( page, `/wp/v2/mk_modal/${ id }?force=true`, {
				method: 'DELETE',
			} );
		}
	} );
	const sample = await rest( page, '/wp/v2/pages?search=Sample&_fields=id' );
	const rules = {
		showOn: {
			mode: 'include',
			include: [
				'/',
				...( sample[ 0 ] ? [ `post:${ sample[ 0 ].id }` ] : [] ),
			],
			exclude: [],
			postTypes: [],
		},
		frequency: { mode: 'days', days: 7, stopAfterCta: true },
		devices: [ 'desktop', 'tablet', 'mobile' ],
		schedule: { start: '', end: '' },
		visitors: { hideLoggedIn: false, minPageViews: 0 },
	};
	const youtube = ( markup ) =>
		markup.replace(
			/<!-- wp:video[\s\S]*?<!-- \/wp:video -->/,
			'<!-- wp:embed {"url":"https://www.youtube.com/watch?v=aqz-KE-bpKQ","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} --><figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">\nhttps://www.youtube.com/watch?v=aqz-KE-bpKQ\n</div></figure><!-- /wp:embed -->'
		);

	// [ slug, title, markup, triggers, caption ]
	const MODALS = [
		[
			'monthly-letter',
			'Monthly letter',
			content( 'monthly-letter' ),
			[ { type: 'scroll', percent: 40 } ],
			'Centered popup in the theme’s own look (the default style).',
		],
		[
			'discount-code',
			'Discount code',
			content( 'discount-code' ),
			[ { type: 'scroll', percent: 50 }, { type: 'exit' } ],
			'Split layout: picture beside the offer.',
		],
		[
			'product-drop',
			'Product drop',
			content( 'product-drop' ),
			[ { type: 'time', delay: 30 } ],
			'Corner slide-in; the page stays usable behind it.',
		],
		[
			'shipping-notice',
			'Shipping notice',
			content( 'shipping-notice' ),
			[ { type: 'load', delay: 0 } ],
			'Bar across the top of the page.',
		],
		[
			'event-announcement',
			'Event announcement',
			content( 'event-announcement' ),
			[ { type: 'url', value: 'utm_campaign=workshops' } ],
			'Drawer from the side of the screen.',
		],
		[
			'parting-gift',
			'Parting gift',
			content( 'parting-gift' ),
			[ { type: 'exit', touch: 'scroll-up' } ],
			'Full-screen takeover, opened on exit intent.',
		],
		[
			'members-evening',
			'Members evening',
			withAttributes( content( 'monthly-letter' ), {
				modalLayout: 'sheet',
			} ),
			[ { type: 'inactivity', delay: 20 } ],
			'Bottom sheet.',
		],
		[
			'inline-letter',
			'Inline letter',
			withAttributes( content( 'monthly-letter' ), {
				modalLayout: 'inline',
			} ),
			[ { type: 'load', delay: 0 } ],
			'Inline panel in the page flow.',
		],
		[
			'video-tour',
			'Video tour',
			youtube( content( 'video-tour' ) ),
			[ { type: 'element', anchor: 'tour' } ],
			'Video lightbox with the click-to-load screen: nothing loads from YouTube until the visitor presses play.',
		],
		[
			'style-fieldnote',
			'Fieldnote style',
			withAttributes( content( 'monthly-letter' ), {
				className: 'is-style-fieldnote',
			} ),
			[],
			'The Fieldnote style.',
		],
		[
			'style-harbor',
			'Harbor style',
			withAttributes( content( 'monthly-letter' ), {
				className: 'is-style-harbor',
			} ),
			[],
			'The Harbor style.',
		],
		[
			'style-night',
			'Night style',
			withAttributes( content( 'monthly-letter' ), {
				className: 'is-style-night',
			} ),
			[],
			'The Night style.',
		],
	];
	for ( const [ slug, title, markup, triggers ] of MODALS ) {
		const post = await rest( page, '/wp/v2/mk_modal', {
			method: 'POST',
			data: {
				slug,
				title,
				status: 'publish',
				content: markup,
				meta: {
					_modal_kit_triggers: triggers,
					...( slug === 'discount-code'
						? { _modal_kit_rules: rules }
						: {} ),
				},
			},
		} );
		if ( ! post.id ) {
			throw new Error( `Couldn't create ${ slug }: ${ post.message }` );
		}
		created[ slug ] = post.id;
	}

	// Front end, as a visitor (no admin bar).
	const guest = await browser.newPage( { viewport: VIEW } );
	guest.setDefaultTimeout( 30000 );
	for ( const [ slug, , , , caption ] of MODALS ) {
		await openFront( guest, slug );
		await shoot( guest, `front-${ slug }`, caption );
	}

	// The editor, over the homepage.
	await context.addInitScript( () =>
		window.localStorage.setItem( 'modal-kit:editor-backdrop', 'site' )
	);
	let sidebar = await editModal( page, created[ 'monthly-letter' ] );
	await page
		.frameLocator( 'iframe[name="editor-canvas"]' )
		.locator( '.modal-kit.has-homepage' )
		.waitFor( { timeout: 30000 } );
	await page.evaluate( () => {
		const { select, dispatch } = window.wp.data;
		dispatch( 'core/block-editor' ).selectBlock(
			select( 'core/block-editor' ).getBlockOrder()[ 0 ]
		);
	} );
	await sidebar.getByRole( 'tab', { name: 'Block' } ).click();
	await closePanels( sidebar );
	await openPanel( sidebar, 'Layout' );
	await page.waitForTimeout( 800 );
	await shoot(
		page,
		'editor-layout',
		'The editor shows the modal over your homepage, as visitors see it. Block settings: Layout.'
	);
	for ( const [ panel, name, caption ] of [
		[ 'Size', 'editor-size', 'Block settings: Size.' ],
		[ 'Animation', 'editor-animation', 'Block settings: Animation.' ],
		[
			'Closing',
			'editor-closing',
			'Block settings: how the modal closes.',
		],
	] ) {
		if ( await sidebar.getByRole( 'button', { name: panel } ).count() ) {
			await openPanel( sidebar, panel );
			await shoot( page, name, caption );
		}
	}

	// Styles tab: the style picker.
	await sidebar.getByRole( 'tab', { name: 'Styles' } ).click();
	await sidebar.getByRole( 'radiogroup', { name: 'Style' } ).waitFor();
	await page.waitForTimeout( 800 );
	await shoot(
		page,
		'editor-styles',
		'Style picker: the theme’s look or a fixed style, previewed on hover.'
	);
	// The backdrop behind the modal is a panel of the Styles tab.
	for ( const [ panel, name, caption ] of [
		[
			'Overlay',
			'editor-overlay',
			'Block styles: the overlay behind the modal.',
		],
		[
			'Backdrop',
			'editor-backdrop',
			'Block styles: the backdrop behind the modal.',
		],
	] ) {
		if ( await sidebar.getByRole( 'button', { name: panel } ).count() ) {
			await openPanel( sidebar, panel );
			await shoot( page, name, caption );
		}
	}

	// Modal tab: triggers, display rules and accessibility.
	sidebar = await editModal( page, created[ 'discount-code' ] );
	await sidebar.getByRole( 'tab', { name: 'Modal' } ).click();
	await closePanels( sidebar );
	await openPanel( sidebar, /^Triggers/ );
	await shoot(
		page,
		'editor-triggers',
		'Triggers: page load, scroll, time, exit intent, inactivity, links and more.'
	);
	await openPanel( sidebar, 'Display rules' );
	const pages = sidebar.getByRole( 'combobox', {
		name: 'Pages',
		exact: true,
	} );
	await pages.click();
	await pages.pressSequentially( 'Hello', { delay: 80 } );
	await page
		.getByRole( 'listbox' )
		.getByRole( 'option' )
		.first()
		.waitFor( { timeout: 15000 } )
		.catch( () => {} );
	await page.waitForTimeout( 600 );
	await shoot(
		page,
		'editor-display-rules',
		'Display rules: pages, frequency, devices, schedule and visitors.'
	);
	await page.keyboard.press( 'Escape' );
	const accessibility = sidebar.getByRole( 'button', {
		name: /^Accessibility/,
	} );
	if ( await accessibility.count() ) {
		await openPanel( sidebar, /^Accessibility/ );
		await shoot(
			page,
			'editor-accessibility',
			'Accessibility settings for the modal.'
		);
	}

	// Button actions.
	await page.evaluate( () => {
		const { select, dispatch } = window.wp.data;
		const find = ( ids ) => {
			for ( const id of ids ) {
				if (
					select( 'core/block-editor' ).getBlockName( id ) ===
					'core/button'
				) {
					return id;
				}
				const inner = find(
					select( 'core/block-editor' ).getBlockOrder( id )
				);
				if ( inner ) {
					return inner;
				}
			}
			return null;
		};
		dispatch( 'core/block-editor' ).selectBlock(
			find( select( 'core/block-editor' ).getBlockOrder() )
		);
	} );
	await sidebar
		.getByRole( 'tab', { name: 'Settings' } )
		.click()
		.catch( () => sidebar.getByRole( 'tab', { name: 'Block' } ).click() );
	const actions = page.locator( '.modal-kit-action-panel' );
	await actions.waitFor( { timeout: 15000 } );
	await actions.evaluate( ( el ) => el.scrollIntoView( { block: 'start' } ) );
	await page.waitForTimeout( 500 );
	await shoot(
		page,
		'editor-button-actions',
		'Button actions: open a link, close the modal or copy text.'
	);

	// Video panel on the video modal.
	sidebar = await editModal( page, created[ 'video-tour' ] );
	await sidebar.getByRole( 'tab', { name: 'Modal' } ).click();
	const videoPanel = sidebar.getByRole( 'button', { name: /^Video/ } );
	if ( await videoPanel.count() ) {
		await closePanels( sidebar );
		await openPanel( sidebar, /^Video/ );
		await shoot( page, 'editor-video', 'Video lightbox settings.' );
	}

	// Any link can open a modal: the link search lists them.
	await page.goto( `${ BASE }/wp-admin/post-new.php` );
	await page.waitForFunction(
		() => window.wp?.data?.select( 'core/block-editor' ),
		null,
		{ timeout: 30000 }
	);
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core', 'welcomeGuide', false );
		preferences.set( 'core/edit-post', 'fullscreenMode', false );
		const { createBlock } = window.wp.blocks;
		window.wp.data.dispatch( 'core/block-editor' ).resetBlocks( [
			createBlock( 'core/paragraph', {
				content: 'Get our monthly letter',
			} ),
		] );
	} );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.locator( 'p.wp-block-paragraph' ).first().click();
	await page.keyboard.press( 'Control+A' );
	await page.keyboard.press( 'Control+K' );
	const linkBox = page.locator(
		'.block-editor-link-control__search-input input'
	);
	await linkBox.waitFor();
	await linkBox.fill( 'Monthly' );
	await page
		.getByRole( 'option', { name: /Monthly letter/ } )
		.first()
		.waitFor( { timeout: 15000 } )
		.catch( () => {} );
	await page.waitForTimeout( 600 );
	await shoot(
		page,
		'editor-link-search',
		'Any link can open a modal: search for it in the link box.'
	);

	// Starting layouts.
	await page.goto( `${ BASE }/wp-admin/post-new.php?post_type=mk_modal` );
	await page
		.getByRole( 'dialog', { name: 'Choose a starting layout' } )
		.waitFor( { timeout: 30000 } );
	await page.waitForTimeout( 3000 );
	await shoot(
		page,
		'editor-starting-layouts',
		'Starter templates for a new modal.'
	);

	// The modal list.
	await page.goto( `${ BASE }/wp-admin/edit.php?post_type=mk_modal` );
	const milestone = page
		.locator(
			'.modal-kit-milestone .notice-dismiss, .modal-kit-milestone button'
		)
		.first();
	if ( await milestone.count() ) {
		await milestone.click();
		await page.waitForTimeout( 400 );
	}
	await page.waitForTimeout( 800 );
	await shoot(
		page,
		'admin-modals',
		'All modals, with their layout, triggers and status.'
	);

	// Settings, every tab.
	for ( const [ tab, caption ] of [
		[ 'general', 'Settings → General.' ],
		[ 'display', 'Settings → Display, with a live preview.' ],
		[ 'accessibility', 'Settings → Accessibility.' ],
		[ 'styles', 'Settings → Styles.' ],
		[ 'tools', 'Settings → Tools: export, import and reset.' ],
		[
			'updates',
			'Settings → Updates: automatic updates, release channel, pause and roll back.',
		],
		[ 'pro', 'Settings → Pro: what Modal Kit Pro adds.' ],
	] ) {
		await page.goto(
			`${ BASE }/wp-admin/edit.php?post_type=mk_modal&page=modal-kit-settings#${ tab }`
		);
		await page.waitForLoadState( 'networkidle' );
		if ( tab === 'display' ) {
			await page.locator( '.modal-kit-preview iframe' ).waitFor();
		}
		await page.waitForTimeout( tab === 'display' ? 3000 : 1200 );
		await shoot( page, `settings-${ tab }`, caption );
	}

	// The first-run setup. Its Behaviour step saves settings: the request is
	// answered here so the test site keeps its own.
	await page.route(
		/\/wp-json\/wp\/v2\/settings|rest_route=%2Fwp%2Fv2%2Fsettings/,
		( route ) =>
			route.request().method() === 'GET'
				? route.continue()
				: route.fulfill( { status: 200, json: {} } )
	);
	await page.goto( `${ BASE }/wp-admin/admin.php?page=modal-kit-setup` );
	await page.locator( '.modal-kit-wizard' ).waitFor();
	const steps = [
		[ 'welcome', 'Setup: what you’ll use modals for.' ],
		[ 'look', 'Setup: pick a look, previewed in your theme.' ],
		[ 'behaviour', 'Setup: how often modals may show.' ],
		[ 'first-modal', 'Setup: start from a template.' ],
	];
	for ( const [ index, [ name, caption ] ] of steps.entries() ) {
		await page.waitForTimeout( 1200 );
		if ( name === 'first-modal' ) {
			const first = page
				.locator( '.modal-kit-wizard__body' )
				.getByRole( 'radio' )
				.first();
			if ( await first.count() ) {
				await first.click();
				await page.waitForTimeout( 400 );
			}
		}
		await shoot( page, `setup-${ name }`, caption );
		if ( index < steps.length - 1 ) {
			await page.getByRole( 'button', { name: 'Continue' } ).click();
		}
	}
	await page.unroute( /settings/ );

	fs.writeFileSync(
		path.join( OUT, 'README.md' ),
		[
			'# Modal Kit screenshots',
			'',
			'Every shot is the whole 1920×1080 window, made with `npm run screenshots` on the test site with only Modal Kit active.',
			'',
			'| File | Shows |',
			'|---|---|',
			...shots.map(
				( [ file, caption ] ) =>
					`| [${ file }](${ file }) | ${ caption } |`
			),
			'',
		].join( '\n' )
	);
} )()
	.catch( ( error ) => {
		console.error( error );
		process.exitCode = 1;
	} )
	.finally( async () => {
		for ( const step of cleanup.reverse() ) {
			await step().catch( () => {} );
		}
	} );
