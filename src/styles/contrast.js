/**
 * WCAG 2 contrast, shared by the style build, its unit test and the custom
 * accent warning in the editor and Settings.
 */

/**
 * Parses #rgb, #rrggbb (alpha digits are ignored), rgb()/rgba(), hsl()/hsla(),
 * oklab() or oklch(). Colors outside sRGB are clipped to it.
 *
 * @param {string} color CSS color.
 * @return {number[]|null} [r, g, b] from 0 to 255, or null.
 */
export function parseColor( color ) {
	const value = String( color ?? '' ).trim();
	let match = value.match( /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i );

	if ( match ) {
		const hex =
			match[ 1 ].length <= 4
				? [ ...match[ 1 ].slice( 0, 3 ) ]
						.map( ( c ) => c + c )
						.join( '' )
				: match[ 1 ].slice( 0, 6 );

		return [ 0, 2, 4 ].map( ( i ) =>
			parseInt( hex.slice( i, i + 2 ), 16 )
		);
	}

	match = value.match( /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i );

	if ( match ) {
		return match.slice( 1, 4 ).map( Number );
	}

	match = value.match(
		/^(hsla?|oklab|oklch)\(\s*([-\d.]+%?)(?:deg)?[\s,]+([-\d.]+%?)[\s,]+([-\d.]+%?)(?:deg)?\s*(?:[,/][^)]*)?\)$/i
	);

	if ( ! match ) {
		return null;
	}

	const [ , fn, x, y, z ] = match;
	const num = ( part, percent = 1 ) =>
		part.endsWith( '%' )
			? ( parseFloat( part ) / 100 ) * percent
			: parseFloat( part );

	if ( fn.toLowerCase().startsWith( 'hsl' ) ) {
		return hslToRgb( num( x ), num( y, 1 ) * 100, num( z, 1 ) * 100 );
	}

	const lightness = num( x );
	let a = num( y, 0.4 );
	let b = num( z, 0.4 );

	if ( fn.toLowerCase() === 'oklch' ) {
		const hue = ( b * Math.PI ) / 180;
		[ a, b ] = [ a * Math.cos( hue ), a * Math.sin( hue ) ];
	}

	return oklabToRgb( lightness, a, b );
}

/**
 * HSL to sRGB.
 *
 * @param {number} h Hue in degrees.
 * @param {number} s Saturation, 0 to 100 (a bare number is a percentage).
 * @param {number} l Lightness, 0 to 100.
 * @return {number[]} [r, g, b] from 0 to 255.
 */
function hslToRgb( h, s, l ) {
	// "50%" was read as 0.5: turn fractions back into percentages.
	const sat = ( s <= 1 ? s * 100 : s ) / 100;
	const light = ( l <= 1 ? l * 100 : l ) / 100;
	const k = ( n ) => ( n + h / 30 ) % 12;
	const a = sat * Math.min( light, 1 - light );
	const f = ( n ) =>
		light - a * Math.max( -1, Math.min( k( n ) - 3, 9 - k( n ), 1 ) );

	return [ f( 0 ), f( 8 ), f( 4 ) ].map( ( v ) => v * 255 );
}

/**
 * OKLab to sRGB, clipped.
 *
 * @param {number} lightness L, 0 to 1.
 * @param {number} a         a.
 * @param {number} b         b.
 * @return {number[]} [r, g, b] from 0 to 255.
 */
function oklabToRgb( lightness, a, b ) {
	const l = ( lightness + 0.3963377774 * a + 0.2158037573 * b ) ** 3;
	const m = ( lightness - 0.1055613458 * a - 0.0638541728 * b ) ** 3;
	const s = ( lightness - 0.0894841775 * a - 1.291485548 * b ) ** 3;
	const linear = [
		4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
		-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
		-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
	];

	return linear.map( ( v ) => {
		const c = Math.min( 1, Math.max( 0, v ) );
		const srgb =
			c <= 0.0031308 ? 12.92 * c : 1.055 * c ** ( 1 / 2.4 ) - 0.055;

		return srgb * 255;
	} );
}

/**
 * A color as #rrggbb, which is what a modal's accent is saved as.
 *
 * @param {string} color CSS color (see parseColor).
 * @return {string} Lowercase hex, or '' when it can't be read.
 */
export function toHex( color ) {
	const rgb = parseColor( color );

	return rgb
		? '#' +
				rgb
					.map( ( v ) =>
						Math.round( Math.min( 255, Math.max( 0, v ) ) )
							.toString( 16 )
							.padStart( 2, '0' )
					)
					.join( '' )
		: '';
}

/**
 * Relative luminance.
 *
 * @param {string} color CSS color.
 * @return {number} 0 (black) to 1 (white).
 */
export function luminance( color ) {
	const rgb = parseColor( color ) ?? [ 0, 0, 0 ];
	const [ r, g, b ] = rgb.map( ( channel ) => {
		const v = channel / 255;

		return v <= 0.03928
			? v / 12.92
			: Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
	} );

	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * Contrast ratio between two colors.
 *
 * @param {string} a CSS color.
 * @param {string} b CSS color.
 * @return {number} 1 to 21.
 */
export function contrast( a, b ) {
	const x = luminance( a );
	const y = luminance( b );

	return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
}

/**
 * Light or dark text for a background, whichever contrasts more.
 *
 * @param {string} background CSS color.
 * @param {string} light      Light text color.
 * @param {string} dark       Dark text color.
 * @return {string} light or dark.
 */
export function textOn( background, light = '#FFFFFF', dark = '#17151F' ) {
	return contrast( light, background ) >= contrast( dark, background )
		? light
		: dark;
}

/**
 * The accent's contrast problems, if any: button text on the accent, and the
 * accent as text (links, eyebrows) on the panel.
 *
 * @param {string} accent  Accent color (hex).
 * @param {string} surface Panel color (hex).
 * @return {number|null} The lowest ratio when it's below 4.5:1, else null.
 */
export function accentProblem( accent, surface ) {
	if ( ! /^#[0-9a-f]{3,8}$/i.test( accent ?? '' ) ) {
		return null;
	}

	const lowest = Math.min(
		contrast( textOn( accent, '#fff', '#17151f' ), accent ),
		contrast( accent, surface )
	);

	return lowest < 4.5 ? lowest : null;
}
