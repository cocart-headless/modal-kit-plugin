// @vitest-environment jsdom

/**
 * Room for bars: the edge a bar sits on, and the room each edge needs.
 */
import { afterEach, describe, expect, it } from 'vitest';

import {
	edgeOf,
	releaseBarSpace,
	reserveBarSpace,
	roomFor,
} from '../../src/runtime/bar-space';

describe( 'bar space', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		document.documentElement.className = '';
		document.documentElement.removeAttribute( 'style' );
	} );

	it( 'reads the edge from where the panel is', () => {
		window.innerHeight = 800;
		expect( edgeOf( { top: 0, height: 78 } ) ).toBe( 'top' );
		expect( edgeOf( { top: 32, height: 78 } ) ).toBe( 'top' );
		expect( edgeOf( { top: 670, height: 130 } ) ).toBe( 'bottom' );
	} );

	it( 'gives each edge the tallest open bar, rounded up', () => {
		expect( roomFor( [] ) ).toEqual( { top: 0, bottom: 0 } );
		expect(
			roomFor( [
				{ edge: 'top', height: 77.4 },
				{ edge: 'top', height: 40 },
				{ edge: 'bottom', height: 130 },
			] )
		).toEqual( { top: 78, bottom: 130 } );
	} );

	it( 'pads the page while a bar shows, and stops when it closes', () => {
		window.innerHeight = 800;
		document.body.innerHTML =
			'<dialog class="modal-kit modal-kit--bar"><div class="modal-kit__panel"></div></dialog>';
		const dialog = document.querySelector( 'dialog' );
		const panel = dialog.querySelector( '.modal-kit__panel' );
		panel.getBoundingClientRect = () => ( { top: 0, height: 78 } );

		const root = document.documentElement;
		reserveBarSpace( dialog );
		expect( root.classList.contains( 'modal-kit-bar-top' ) ).toBe( true );
		expect( root.style.getPropertyValue( '--modal-kit-bar-top' ) ).toBe(
			'78px'
		);
		expect( root.classList.contains( 'modal-kit-bar-bottom' ) ).toBe(
			false
		);

		releaseBarSpace( dialog );
		expect( root.classList.contains( 'modal-kit-bar-top' ) ).toBe( false );
		expect( root.style.getPropertyValue( '--modal-kit-bar-top' ) ).toBe(
			''
		);
	} );
} );
