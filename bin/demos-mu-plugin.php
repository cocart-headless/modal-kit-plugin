<?php
/**
 * Plugin Name: Modal Kit demos
 * Description: Lifts the automatic-open limit and the cool-down (Settings → General) on demo pages marked for it, and gives demo forms a destination that sends nothing. Installed on the local dev site by `npm run demos`.
 * Author: CoCart Headless, LLC
 *
 * @package ModalKit
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'script_module_data_@wordpress/interactivity',
	static function ( $data ) {
		if ( isset( $data['state']['modal-kit'] ) && is_singular() && get_post_meta( get_queried_object_id(), '_modal_kit_demo_unlimited', true ) ) {
			$data['state']['modal-kit']['autoOpenLimit'] = 0;
			$data['state']['modal-kit']['cooldown']      = 0;
		}

		return $data;
	},
	20
);

// Demo forms send to "Demo": the site takes the answers through the usual
// route (bot checks, the Submissions log, the after-submit message) and
// sends them nowhere.
add_filter(
	'modal_kit_pro_providers',
	static function ( $providers ) {
		if ( ! class_exists( '\ModalKit\Pro\Providers\Provider' ) ) {
			return $providers;
		}

		$providers[] = new class() extends \ModalKit\Pro\Providers\Provider {
			/**
			 * Provider id.
			 *
			 * @return string
			 */
			public function id() {
				return 'demo';
			}

			/**
			 * Name shown in wp-admin.
			 *
			 * @return string
			 */
			public function label() {
				return 'Demo (sends nothing)';
			}

			/**
			 * Surveys have no email field.
			 *
			 * @return bool
			 */
			public function needs_email() {
				return false;
			}

			/**
			 * Only the site takes the answers.
			 *
			 * @param array<string, mixed> $form Form block attributes.
			 * @return array<string, mixed>
			 */
			public function public_config( $form ) {
				unset( $form );

				return array( 'transport' => 'relay' );
			}

			/**
			 * Accepts the answers and keeps nothing.
			 *
			 * @param array<string, mixed>  $form   Form block attributes.
			 * @param array<string, string> $fields Fields.
			 * @param string[]              $tags   Tags.
			 * @return true
			 */
			public function send( $form, $fields, $tags ) {
				unset( $form, $fields, $tags );
				$this->sent = 'Demo: not sent anywhere';

				return true;
			}
		};

		return $providers;
	}
);
