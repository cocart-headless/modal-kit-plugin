/**
 * Ed25519 signatures for update packages, which the updater checks before
 * installing (Updater::verify_download()).
 *
 *   node bin/sign-package.mjs --keygen       Prints a new key pair.
 *   node bin/sign-package.mjs <file.zip>     Prints the signature and writes <file.zip>.sig.
 *                                            Reads MODAL_KIT_SIGNING_KEY.
 *
 * The signature covers the zip's SHA-384 hash (raw bytes), as WordPress core
 * signs its own packages, and is base64, as the update server sends it in
 * `signatures`. Signing refuses a private key that doesn't match
 * Updater::PUBLIC_KEY, so a release can't go out signed with the wrong key.
 */
import {
	createHash,
	createPrivateKey,
	createPublicKey,
	generateKeyPairSync,
	sign,
	verify,
} from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { ROOT } from './lib.mjs';

/* eslint-disable no-console */

const fromUrl = ( value ) => Buffer.from( value, 'base64url' );
const arg = process.argv[ 2 ];

if ( arg === '--keygen' ) {
	const { privateKey } = generateKeyPairSync( 'ed25519' );
	const { d, x } = privateKey.export( { format: 'jwk' } );

	console.log(
		`Public key (Updater::PUBLIC_KEY):  ${ fromUrl( x ).toString( 'hex' ) }`
	);
	console.log(
		`Private key (MODAL_KIT_SIGNING_KEY): ${ fromUrl( d ).toString( 'hex' ) }`
	);
	process.exit( 0 );
}

const secret = ( process.env.MODAL_KIT_SIGNING_KEY ?? '' ).trim();

if ( ! arg || ! /^[0-9a-f]{64}$/.test( secret ) ) {
	console.error(
		'Usage: MODAL_KIT_SIGNING_KEY=<hex> node bin/sign-package.mjs <file.zip>, or --keygen'
	);
	process.exit( 1 );
}

const shipped =
	readFileSync( join( ROOT, 'includes/Updates/Updater.php' ), 'utf8' ).match(
		/const PUBLIC_KEY = '([0-9a-f]*)';/
	)?.[ 1 ] ?? '';

if ( shipped.length !== 64 ) {
	console.error(
		'Updater::PUBLIC_KEY is empty: set it first, or sites won’t check this signature.'
	);
	process.exit( 1 );
}

// Node takes raw Ed25519 keys only in these wrappers (PKCS8 and SPKI).
const privateKey = createPrivateKey( {
	format: 'der',
	type: 'pkcs8',
	key: Buffer.concat( [
		Buffer.from( '302e020100300506032b657004220420', 'hex' ),
		Buffer.from( secret, 'hex' ),
	] ),
} );
const signingPublic = fromUrl(
	createPublicKey( privateKey ).export( { format: 'jwk' } ).x
).toString( 'hex' );

// Also accepts the key named in MODAL_KIT_PREVIOUS_PUBLIC_KEY, which sites on
// the previous release still trust.
const previous = ( process.env.MODAL_KIT_PREVIOUS_PUBLIC_KEY ?? '' ).trim();
const trusted =
	signingPublic === shipped ||
	( previous !== '' && signingPublic === previous );

if ( ! trusted ) {
	console.error(
		'MODAL_KIT_SIGNING_KEY doesn’t match Updater::PUBLIC_KEY (or MODAL_KIT_PREVIOUS_PUBLIC_KEY during a key change): sites would refuse this package.'
	);
	process.exit( 1 );
}

if ( signingPublic !== shipped ) {
	console.error(
		'Signed with the previous key, for the release that changes it. Replace the MODAL_KIT_SIGNING_KEY secret next.'
	);
}

const digest = createHash( 'sha384' ).update( readFileSync( arg ) ).digest();
const signature = sign( null, digest, privateKey );

if (
	! verify(
		null,
		digest,
		createPublicKey( {
			format: 'der',
			type: 'spki',
			key: Buffer.concat( [
				Buffer.from( '302a300506032b6570032100', 'hex' ),
				Buffer.from( signingPublic, 'hex' ),
			] ),
		} ),
		signature
	)
) {
	console.error( 'The signature didn’t verify.' );
	process.exit( 1 );
}

writeFileSync( `${ arg }.sig`, signature.toString( 'base64' ) );
console.log( signature.toString( 'base64' ) );
console.error( `Wrote ${ relative( process.cwd(), `${ arg }.sig` ) }` );
