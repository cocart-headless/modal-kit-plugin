<?php
/**
 * Modal Kit's own updates: registration and the Updates tab's routes.
 *
 * @package ModalKit
 */

namespace ModalKit\Updates;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Modal Kit with the updater (no license: every site gets its
 * packages), and serves Settings → Updates:
 *
 * - GET  /modal-kit/v1/updates (`?refresh=1` asks the server again)
 * - POST /modal-kit/v1/updates { autoUpdate | channel | pause }
 * - GET  /modal-kit/v1/updates/rollback, POST { version }
 *
 * Changes are only made on the site WordPress checks updates from (on a
 * network, the main site), by users who can update plugins.
 */
final class Routes {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'modal-kit/v1';

	/**
	 * Registers Modal Kit for updates.
	 *
	 * @return Updater
	 */
	public static function updater() {
		return Updater::register(
			array(
				'slug'     => 'modal-kit',
				'file'     => MODAL_KIT_FILE,
				'version'  => MODAL_KIT_VERSION,
				'name'     => 'Modal Kit',
				'homepage' => 'https://modalkitwp.com/',
				'prefix'   => 'modal_kit_',
			)
		);
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		self::updater();
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * State for the Updates tab.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe() {
		if ( ! Updater::manages_updates() ) {
			return array(
				'subsite' => true,
				'version' => MODAL_KIT_VERSION,
			);
		}

		return self::updater()->describe() + array(
			'canChange' => current_user_can( 'update_plugins' ),
		);
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function routes() {
		$change = static function () {
			return current_user_can( 'update_plugins' ) && Updater::manages_updates();
		};

		register_rest_route(
			self::NAMESPACE,
			'/updates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'refresh' => array( 'type' => 'boolean' ),
					),
					'callback'            => static function ( WP_REST_Request $request ) {
						if ( $request->get_param( 'refresh' ) && Updater::manages_updates() && current_user_can( 'update_plugins' ) ) {
							self::updater()->info( true );
						}

						return rest_ensure_response( self::describe() );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $change,
					'args'                => array(
						'autoUpdate' => array( 'type' => 'boolean' ),
						'channel'    => array(
							'type' => 'string',
							'enum' => Updater::CHANNELS,
						),
						'pause'      => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 52,
						),
					),
					'callback'            => static function ( WP_REST_Request $request ) {
						$updater = self::updater();

						if ( null !== $request->get_param( 'autoUpdate' ) ) {
							$updater->set_auto_updates( (bool) $request->get_param( 'autoUpdate' ) );
						}

						$changes = array();

						if ( null !== $request->get_param( 'channel' ) ) {
							$changes[] = $updater->set_channel( sanitize_key( (string) $request->get_param( 'channel' ) ) );
						}

						if ( null !== $request->get_param( 'pause' ) ) {
							$changes[] = $updater->pause( (int) $request->get_param( 'pause' ) );
						}

						foreach ( $changes as $result ) {
							if ( is_wp_error( $result ) ) {
								return self::bad_request( $result );
							}
						}

						return rest_ensure_response( self::describe() );
					},
				),
			)
		);

		$roll_back = static function () {
			return self::updater()->can_roll_back();
		};

		register_rest_route(
			self::NAMESPACE,
			'/updates/rollback',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $roll_back,
					'callback'            => static function () {
						return rest_ensure_response( array( 'versions' => self::updater()->rollback_versions( true ) ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $roll_back,
					'args'                => array(
						'version' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
					'callback'            => static function ( WP_REST_Request $request ) {
						$result = self::updater()->rollback( (string) $request->get_param( 'version' ) );

						if ( is_wp_error( $result ) ) {
							return self::bad_request( $result );
						}

						return rest_ensure_response( self::describe() );
					},
				),
			)
		);
	}

	/**
	 * An error answered with 400.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function bad_request( $error ) {
		$error->add_data( array( 'status' => 400 ) );

		return $error;
	}
}
