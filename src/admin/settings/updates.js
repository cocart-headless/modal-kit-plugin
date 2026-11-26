/**
 * Settings → Updates: Modal Kit's own updates from the package server
 * (automatic updates, release channel, pause, roll back). Modal Kit Pro's
 * updates are on its License tab.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';

const PATH = '/modal-kit/v1/updates';

const PAUSE_WEEKS = [ 1, 2, 4, 8, 12 ];

function formatDate( seconds ) {
	return dateI18n( getSettings().formats.date, new Date( seconds * 1000 ) );
}

/**
 * Lists earlier versions and installs one after a confirmation.
 *
 * @param {Object}                                  props
 * @param {Object}                                  props.state    Update state.
 * @param {(next: Object, message: string) => void} props.onDone   Receives the new state.
 * @param {(message: string) => void}               props.onError  Receives an error message.
 * @param {boolean}                                 props.disabled Another request is running.
 */
function Rollback( { state, onDone, onError, disabled } ) {
	const [ versions, setVersions ] = useState( null );
	const [ version, setVersion ] = useState( '' );
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const load = async () => {
		setBusy( true );
		try {
			const result = await apiFetch( { path: `${ PATH }/rollback` } );
			setVersions( result.versions );
			setVersion( result.versions[ 0 ] ?? '' );
		} catch ( failure ) {
			onError( failure.message );
		} finally {
			setBusy( false );
		}
	};

	const rollback = async () => {
		setBusy( true );
		try {
			const next = await apiFetch( {
				path: `${ PATH }/rollback`,
				method: 'POST',
				data: { version },
			} );
			setConfirming( false );
			setVersions( null );
			onDone(
				next,
				sprintf(
					/* translators: %s: version. */
					__( 'Modal Kit was rolled back to %s.', 'modal-kit' ),
					version
				)
			);
		} catch ( failure ) {
			setConfirming( false );
			onError( failure.message );
		} finally {
			setBusy( false );
		}
	};

	if ( ! versions ) {
		return (
			<p>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ disabled || busy }
					onClick={ load }
					__next40pxDefaultSize
				>
					{ __( 'Roll back to an earlier version…', 'modal-kit' ) }
				</Button>
			</p>
		);
	}

	if ( ! versions.length ) {
		return (
			<p className="description">
				{ __(
					'There are no earlier versions to roll back to.',
					'modal-kit'
				) }
			</p>
		);
	}

	return (
		<div className="modal-kit-updates__row">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Earlier version', 'modal-kit' ) }
				value={ version }
				options={ versions.map( ( value ) => ( {
					value,
					label: value,
				} ) ) }
				onChange={ setVersion }
			/>
			<Button
				variant="secondary"
				disabled={ disabled || busy || ! version }
				onClick={ () => setConfirming( true ) }
				__next40pxDefaultSize
			>
				{ __( 'Roll back', 'modal-kit' ) }
			</Button>
			{ confirming && (
				<Modal
					title={ sprintf(
						/* translators: %s: version. */
						__( 'Roll back to %s?', 'modal-kit' ),
						version
					) }
					onRequestClose={ () => ! busy && setConfirming( false ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: 1: installed version, 2: earlier version. */
							__(
								'Modal Kit %1$s will be replaced with %2$s. Your modals and settings stay as they are.',
								'modal-kit'
							),
							state.version,
							version
						) }
					</p>
					<p>
						{ __(
							'Updates are paused for a week, so the site isn’t updated again straight away. You can resume them at any time.',
							'modal-kit'
						) }
					</p>
					<div className="modal-kit-updates__actions">
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () => setConfirming( false ) }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'modal-kit' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ rollback }
							__next40pxDefaultSize
						>
							{ sprintf(
								/* translators: %s: version. */
								__( 'Roll back to %s', 'modal-kit' ),
								version
							) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

export default function Updates( { notify } ) {
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ weeks, setWeeks ] = useState( '1' );

	useEffect( () => {
		apiFetch( { path: `${ PATH }?refresh=1` } )
			.then( setState )
			.catch( ( failure ) => setError( failure.message ) );
	}, [] );

	const call = async ( name, data, message ) => {
		setBusy( name );
		setError( '' );

		try {
			setState( await apiFetch( { path: PATH, method: 'POST', data } ) );
			if ( message ) {
				notify( 'success', message );
			}
		} catch ( failure ) {
			setError( failure.message );
		} finally {
			setBusy( '' );
		}
	};

	if ( ! state ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		) : (
			<Spinner />
		);
	}

	if ( state.subsite ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'On a network, Modal Kit’s updates are managed on the main site.',
					'modal-kit'
				) }
			</Notice>
		);
	}

	const locked = ! state.canChange || !! busy;
	const channel = state.channels.find(
		( item ) => item.value === state.channel
	);

	return (
		<div className="modal-kit-updates">
			<p>
				{ __(
					'Modal Kit’s updates come from our package server, packages.cocartheadless.com, not WordPress.org. They’re free and need no license key.',
					'modal-kit'
				) }
			</p>

			{ ! state.canChange && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Only people who can update plugins can change these settings.',
						'modal-kit'
					) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Install Modal Kit updates automatically',
					'modal-kit'
				) }
				checked={ state.autoUpdate }
				disabled={ ! state.canChange || ( !! busy && busy !== 'auto' ) }
				onChange={ ( autoUpdate ) => {
					// Show the new state now; the response confirms it.
					setState( { ...state, autoUpdate } );
					call( 'auto', { autoUpdate } );
				} }
			/>
			<p className="description">
				{ state.update
					? sprintf(
							/* translators: 1: installed version, 2: new version. */
							__(
								'Version %1$s installed · %2$s is available in Plugins.',
								'modal-kit'
							),
							state.version,
							state.update
						)
					: sprintf(
							/* translators: %s: installed version. */
							__(
								'Version %s installed · up to date',
								'modal-kit'
							),
							state.version
						) }
				{ state.previousVersion &&
					state.previousVersion !== state.version &&
					' · ' +
						sprintf(
							/* translators: 1: previous version, 2: date. */
							__( 'changed from %1$s on %2$s', 'modal-kit' ),
							state.previousVersion,
							formatDate( state.updatedAt )
						) }
			</p>
			{ state.offline && (
				<p className="description">
					{ __(
						'The update server couldn’t be reached on the last check. It’s tried again within the hour.',
						'modal-kit'
					) }
				</p>
			) }

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className="modal-kit-updates__channel"
				label={ __( 'Release channel', 'modal-kit' ) }
				value={ state.channel }
				options={ state.channels.map( ( { value, label } ) => ( {
					value,
					label,
				} ) ) }
				disabled={ locked }
				help={ channel?.description }
				onChange={ ( next ) =>
					call(
						'channel',
						{ channel: next },
						__( 'Release channel changed.', 'modal-kit' )
					)
				}
			/>

			{ state.pausedUntil ? (
				<div className="modal-kit-updates__row">
					<p>
						{ sprintf(
							/* translators: %s: date. */
							__( 'Updates are paused until %s.', 'modal-kit' ),
							formatDate( state.pausedUntil )
						) }
					</p>
					<Button
						variant="secondary"
						isBusy={ busy === 'pause' }
						disabled={ locked }
						onClick={ () =>
							call(
								'pause',
								{ pause: 0 },
								__( 'Updates resumed.', 'modal-kit' )
							)
						}
						__next40pxDefaultSize
					>
						{ __( 'Resume updates', 'modal-kit' ) }
					</Button>
				</div>
			) : (
				<div className="modal-kit-updates__row">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Pause updates for', 'modal-kit' ) }
						value={ weeks }
						disabled={ ! state.canChange }
						options={ PAUSE_WEEKS.map( ( count ) => ( {
							value: String( count ),
							label: sprintf(
								/* translators: %d: number of weeks. */
								_n( '%d week', '%d weeks', count, 'modal-kit' ),
								count
							),
						} ) ) }
						onChange={ setWeeks }
					/>
					<Button
						variant="secondary"
						isBusy={ busy === 'pause' }
						disabled={ locked }
						onClick={ () =>
							call(
								'pause',
								{ pause: Number( weeks ) },
								__( 'Updates paused.', 'modal-kit' )
							)
						}
						__next40pxDefaultSize
					>
						{ __( 'Pause updates', 'modal-kit' ) }
					</Button>
				</div>
			) }

			{ state.canRollback && (
				<Rollback
					state={ state }
					disabled={ !! busy }
					onError={ setError }
					onDone={ ( next, message ) => {
						setError( '' );
						setState( next );
						notify( 'success', message );
					} }
				/>
			) }
		</div>
	);
}
