/**
 * Colors a custom accent can come from: theme palettes give hex, rgb(),
 * hsl() or oklch(), and the accent is saved as hex.
 */
import { describe, expect, it } from 'vitest';

import { parseColor, toHex } from '../../src/styles/contrast';

describe( 'toHex', () => {
	it( 'keeps hex, lowercased and expanded', () => {
		expect( toHex( '#E2E0EA' ) ).toBe( '#e2e0ea' );
		expect( toHex( '#abc' ) ).toBe( '#aabbcc' );
	} );

	it( 'reads rgb() and hsl(), with commas or spaces', () => {
		expect( toHex( 'rgb(255, 0, 0)' ) ).toBe( '#ff0000' );
		expect( toHex( 'hsl(120 100% 25%)' ) ).toBe( '#008000' );
		expect( toHex( 'hsl(120, 100%, 25%)' ) ).toBe( '#008000' );
	} );

	it( 'reads oklch() and oklab(), with a number or a percentage', () => {
		expect( toHex( 'oklch(0.6 0.13 150)' ) ).toBe( '#3b9555' );
		expect( toHex( 'oklch(60% 0.13 150)' ) ).toBe( '#3b9555' );
		expect( toHex( 'oklch(1 0 0)' ) ).toBe( '#ffffff' );
		expect( toHex( 'oklch(0 0 0)' ) ).toBe( '#000000' );
		expect( toHex( 'oklab(0.5 0.1 -0.1)' ) ).toBe( '#81459a' );
	} );

	it( 'ignores alpha and clips colors outside sRGB', () => {
		expect( toHex( 'oklch(0.7 0.1 30 / 50%)' ) ).toBe( '#d58679' );
		expect(
			parseColor( 'oklch(0.9 0.4 140)' ).every(
				( v ) => v >= 0 && v <= 255
			)
		).toBe( true );
	} );

	it( 'gives nothing for what it can’t read', () => {
		expect( toHex( 'var(--x)' ) ).toBe( '' );
		expect( toHex( '' ) ).toBe( '' );
		expect( toHex( undefined ) ).toBe( '' );
	} );
} );
