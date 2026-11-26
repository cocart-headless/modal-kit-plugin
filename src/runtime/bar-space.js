/**
 * Room for bars: a top or bottom bar that leaves the page usable pushes the
 * page by its own height while it shows, so it never covers what's under it.
 * The page gets padding at that edge (`--modal-kit-bar-top` /
 * `--modal-kit-bar-bottom` on <html>, used in the modal block's style), kept
 * in step with the bar's height as it wraps or the window changes size.
 */

const bars = new Map();

/**
 * Which edge a bar sits on: the one nearer its panel's middle. On phones a
 * top bar can move to the bottom (Settings → Display), so this is measured.
 *
 * @param {DOMRect} rect Panel box.
 * @return {string} top | bottom.
 */
export function edgeOf( rect ) {
	return rect.top + rect.height / 2 < window.innerHeight / 2
		? 'top'
		: 'bottom';
}

/**
 * The room each edge needs: the tallest open bar there.
 *
 * @param {Array<{edge: string, height: number}>} sizes Open bars.
 * @return {{top: number, bottom: number}} Pixels.
 */
export function roomFor( sizes ) {
	return sizes.reduce(
		( room, { edge, height } ) => ( {
			...room,
			[ edge ]: Math.max( room[ edge ], Math.ceil( height ) ),
		} ),
		{ top: 0, bottom: 0 }
	);
}

function update() {
	const root = document.documentElement;
	const sizes = [];

	for ( const panel of bars.values() ) {
		const rect = panel.getBoundingClientRect();
		sizes.push( { edge: edgeOf( rect ), height: rect.height } );
	}

	const room = roomFor( sizes );

	for ( const edge of [ 'top', 'bottom' ] ) {
		if ( room[ edge ] ) {
			root.style.setProperty(
				`--modal-kit-bar-${ edge }`,
				`${ room[ edge ] }px`
			);
			root.classList.add( `modal-kit-bar-${ edge }` );
		} else {
			root.style.removeProperty( `--modal-kit-bar-${ edge }` );
			root.classList.remove( `modal-kit-bar-${ edge }` );
		}
	}
}

let observer = null;

/**
 * Makes room for an open bar.
 *
 * @param {HTMLDialogElement} dialog Bar.
 */
export function reserveBarSpace( dialog ) {
	const panel = dialog.querySelector( ':scope > .modal-kit__panel' );

	if ( ! panel ) {
		return;
	}

	bars.set( dialog, panel );

	if ( ! observer && 'ResizeObserver' in window ) {
		observer = new window.ResizeObserver( update );
		window.addEventListener( 'resize', update );
	}

	observer?.observe( panel );
	update();
}

/**
 * Gives a closed bar's room back.
 *
 * @param {HTMLDialogElement} dialog Bar.
 */
export function releaseBarSpace( dialog ) {
	const panel = bars.get( dialog );

	if ( ! panel ) {
		return;
	}

	observer?.unobserve( panel );
	bars.delete( dialog );
	update();
}
