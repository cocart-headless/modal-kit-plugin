<?php
/**
 * Updates from the package server, for Modal Kit and the plugins that
 * register with its updater.
 *
 * @package ModalKit
 */

use ModalKit\Updates\Routes;
use ModalKit\Updates\Updater;

/**
 * @covers \ModalKit\Updates\Updater
 * @covers \ModalKit\Updates\Routes
 */
class UpdatesTest extends WP_UnitTestCase {

	/**
	 * Requests the stand-in server answered: [ url, headers ].
	 *
	 * @var array<int, array{0: string, 1: array<string, string>}>
	 */
	private $requests = array();

	/**
	 * What the stand-in update endpoint answers.
	 *
	 * @var array<string, mixed>|null
	 */
	private $answer = null;

	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->answer   = array(
			'version'       => '9.9.9',
			'download_link' => 'https://packages.cocartheadless.com/modal-kit-9.9.9.zip',
			'versions'      => array(
				'0.0.9' => 'https://packages.cocartheadless.com/modal-kit-0.0.9.zip',
				'0.0.8' => 'https://packages.cocartheadless.com/modal-kit-0.0.8.zip',
				'9.9.9' => 'https://packages.cocartheadless.com/modal-kit-9.9.9.zip',
			),
			'translations'  => array(
				array(
					'language' => 'de_DE',
					'version'  => '9.9.9',
					'updated'  => '2030-01-01 00:00:00',
					'package'  => 'https://packages.cocartheadless.com/translations/modal-kit-de_DE.zip',
				),
			),
		);

		add_filter( 'pre_http_request', array( $this, 'serve' ), 10, 3 );
		rest_get_server();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'serve' ) );

		foreach ( array( 'modal_kit_', 'mk_test_addon_' ) as $prefix ) {
			delete_option( $prefix . 'update_settings' );
			delete_option( $prefix . 'auto_update' );
			delete_transient( $prefix . 'update_info' );
			delete_transient( $prefix . 'just_updated' );
		}

		remove_all_filters( 'modal_kit_pre_rollback' );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The stand-in package server.
	 *
	 * @param false|array $pre  Earlier answer.
	 * @param array       $args Request arguments.
	 * @param string      $url  Address.
	 * @return false|array
	 */
	public function serve( $pre, $args, $url ) {
		if ( 'packages.cocartheadless.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}

		$this->requests[] = array( $url, (array) ( $args['headers'] ?? array() ) );

		return array(
			'headers'  => array(),
			'cookies'  => array(),
			'response' => array( 'code' => null === $this->answer ? 502 : 200 ),
			'body'     => (string) wp_json_encode( $this->answer ),
		);
	}

	/**
	 * Modal Kit's updater.
	 *
	 * @return Updater
	 */
	private function updater() {
		return Routes::updater();
	}

	/**
	 * A paid add-on registered with the updater, licensed or not.
	 *
	 * @param bool $licensed Whether its license is active.
	 * @return Updater
	 */
	private function add_on( &$licensed ) {
		return Updater::register(
			array(
				'slug'     => 'mk-test-addon',
				'file'     => WP_PLUGIN_DIR . '/mk-test-addon/mk-test-addon.php',
				'version'  => '1.0.0',
				'name'     => 'Test add-on',
				'prefix'   => 'mk_test_addon_',
				'licensed' => static function () use ( &$licensed ) {
					return $licensed;
				},
				'key'      => static function () {
					return 'MODALKIT-KEY';
				},
				'query'    => static function () {
					return array( 'activation_id' => 'act-1' );
				},
			)
		);
	}

	/**
	 * Logs in a new user with a role.
	 *
	 * @param string $role Role.
	 * @return void
	 */
	private function log_in( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Parameters.
	 * @return WP_REST_Response
	 */
	private function rest( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, '/modal-kit/v1' . $route );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_modal_kit_updates_from_the_package_server_without_a_key() {
		$updater = $this->updater();
		$file    = $updater->basename();

		$this->assertSame( 'https://packages.cocartheadless.com/plugin-info/modal-kit.json', $updater->endpoint() );
		$this->assertSame( 'https://modalkitwp.com/', get_file_data( MODAL_KIT_FILE, array( 'uri' => 'Update URI' ) )['uri'], 'WordPress.org is never asked about Modal Kit.' );

		$update = Updater::on_update( false, array(), $file, array( 'de_DE' ) );

		$this->assertSame( '9.9.9', $update['new_version'] );
		$this->assertSame( 'https://packages.cocartheadless.com/modal-kit-9.9.9.zip', $update['package'] );
		$this->assertSame( 'modal-kit', $update['slug'] );
		$this->assertCount( 1, $update['translations'] );

		$this->assertCount( 1, $this->requests );
		$this->assertStringStartsWith( 'https://packages.cocartheadless.com/plugin-info/modal-kit.json?', $this->requests[0][0] );
		$this->assertStringContainsString( 'version=' . rawurlencode( MODAL_KIT_VERSION ), $this->requests[0][0] );
		$this->assertStringContainsString( 'channel=stable', $this->requests[0][0] );
		$this->assertArrayNotHasKey( 'X-License-Key', $this->requests[0][1], 'No license for the free plugin.' );

		Updater::on_update( false, array(), $file );
		$this->assertCount( 1, $this->requests, 'Cached.' );
		$this->assertSame( '9.9.9', $updater->available() );

		$this->assertFalse( Updater::on_update( false, array(), 'other/other.php' ), 'Other plugins are left alone.' );

		$details = Updater::on_details( false, 'plugin_information', (object) array( 'slug' => 'modal-kit' ) );
		$this->assertSame( 'Modal Kit', $details->name );
		$this->assertSame( '9.9.9', $details->version );
		$this->assertFalse( Updater::on_details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
	}

	public function test_an_unreachable_server_is_remembered_for_an_hour() {
		$this->answer = null;

		$this->assertNull( $this->updater()->info( true ) );
		$this->assertTrue( $this->updater()->offline() );
		$this->assertFalse( Updater::on_update( false, array(), $this->updater()->basename() ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_paid_add_on_gets_packages_only_while_licensed() {
		$licensed = false;
		$add_on   = $this->add_on( $licensed );

		$info = $add_on->info( true );
		$this->assertSame( '9.9.9', $info->version, 'It still says which version is out.' );
		$this->assertSame( '', $info->download_link );
		$this->assertSame( array(), $info->versions );
		$this->assertSame( array(), $add_on->rollback_versions() );
		$this->assertSame( 'MODALKIT-KEY', $this->requests[0][1]['X-License-Key'], 'The key goes in a header, not the address.' );
		$this->assertStringNotContainsString( 'MODALKIT-KEY', $this->requests[0][0] );
		$this->assertStringContainsString( 'activation_id=act-1', $this->requests[0][0] );
		$this->assertStringStartsWith( 'https://packages.cocartheadless.com/plugin-info/mk-test-addon.json?', $this->requests[0][0] );

		$item = (object) array( 'plugin' => $add_on->basename() );
		$add_on->set_auto_updates( true );
		$this->assertNull( $add_on->auto_update( null, $item ), 'Not while unlicensed.' );

		$licensed = true;
		$info     = $add_on->info( true );
		$this->assertSame( 'https://packages.cocartheadless.com/modal-kit-9.9.9.zip', $info->download_link );
		$this->assertTrue( $add_on->auto_update( null, $item ) );

		// Its settings are its own.
		$this->assertTrue( $add_on->set_channel( 'beta' ) );
		$this->assertSame( 'stable', $this->updater()->channel() );
		$this->assertFalse( $this->updater()->auto_updates() );

		$this->assertSame( $add_on, Updater::get( 'mk-test-addon' ) );
		$this->assertSame( $add_on, $this->add_on( $licensed ), 'Registered once.' );
	}

	public function test_packages_need_https_on_an_allowed_host() {
		$this->assertTrue( Updater::is_allowed_package( 'https://packages.cocartheadless.com/modal-kit.zip' ) );
		$this->assertFalse( Updater::is_allowed_package( 'http://packages.cocartheadless.com/modal-kit.zip' ) );
		$this->assertFalse( Updater::is_allowed_package( 'https://evil.example/modal-kit.zip' ) );
		$this->assertFalse( Updater::is_allowed_package( 'https://packages.cocartheadless.com.evil.example/modal-kit.zip' ) );
		$this->assertFalse( Updater::is_allowed_package( 'https://cdn.packages.cocartheadless.com/modal-kit.zip' ), 'Only the listed hosts, not their subdomains.' );
		$this->assertFalse( Updater::is_allowed_package( 'https://modalkitwp.com/modal-kit.zip' ), 'Not the site: packages only come from the package server.' );

		$this->answer['download_link'] = 'https://evil.example/modal-kit.zip';
		$this->answer['versions']      = array(
			'0.0.9' => 'http://packages.cocartheadless.com/old.zip',
			'0.0.8' => 'https://packages.cocartheadless.com/ok.zip',
		);
		$this->answer['translations']  = array(
			array(
				'language' => 'de_DE',
				'updated'  => '2030-01-01',
				'package'  => 'https://evil.example/de.zip',
			),
		);

		$info = $this->updater()->info( true );

		$this->assertSame( '', $info->download_link );
		$this->assertSame( array( '0.0.8' => 'https://packages.cocartheadless.com/ok.zip' ), (array) $info->versions );
		$this->assertSame( array(), $info->translations );
	}

	public function test_the_shipped_public_key_is_a_valid_ed25519_key() {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Updater::PUBLIC_KEY, 'Updater::PUBLIC_KEY must be set before release, or packages go unchecked.' );
	}

	public function test_a_package_must_carry_a_good_signature() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		remove_filter( 'pre_http_request', array( $this, 'serve' ) );

		$pair   = sodium_crypto_sign_keypair();
		$public = bin2hex( sodium_crypto_sign_publickey( $pair ) );
		$secret = sodium_crypto_sign_secretkey( $pair );
		$zip    = 'the package';
		// Signed over the SHA-384 hash, as bin/sign-package.mjs signs.
		$good = base64_encode( sodium_crypto_sign_detached( hash( 'sha384', $zip, true ), $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		// Signed over the whole file: not accepted.
		$whole   = base64_encode( sodium_crypto_sign_detached( $zip, $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$bad     = base64_encode( sodium_crypto_sign_detached( 'something else', $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$bad_sum = base64_encode( sodium_crypto_sign_detached( hash( 'sha384', 'something else', true ), $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$url     = 'https://packages.cocartheadless.com/modal-kit.zip';
		$updater = $this->updater();
		$ours    = array( 'plugin' => $updater->basename() );

		$file = wp_tempnam( 'signed' );
		file_put_contents( $file, $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertTrue( Updater::signature_ok( $file, $good, $public ) );
		$this->assertFalse( Updater::signature_ok( $file, $whole, $public ), 'Only a signature over the SHA-384 hash is accepted.' );
		$this->assertFalse( Updater::signature_ok( $file, $bad, $public ) );
		$this->assertFalse( Updater::signature_ok( $file, $bad_sum, $public ) );
		$this->assertFalse( Updater::signature_ok( $file, 'not base64!', $public ) );
		$this->assertFalse( Updater::signature_ok( $file, $good, 'nothex' ) );
		wp_delete_file( $file );

		$serve = static function ( $pre, $args ) use ( $zip ) {
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			return array(
				'headers'  => array(),
				'cookies'  => array(),
				'response' => array( 'code' => 200 ),
				'body'     => '',
				'filename' => $args['filename'] ?? '',
			);
		};
		add_filter( 'pre_http_request', $serve, 10, 2 );

		$info = static function ( $signatures ) use ( $url ) {
			set_transient(
				'modal_kit_update_info',
				(object) array(
					'version'       => '9.9.9',
					'download_link' => $url,
					'signatures'    => $signatures,
				)
			);
		};

		$info( array() );
		$this->assertFalse( $updater->verify_download_with( false, $url, '' ), 'No key: WordPress downloads it as before.' );
		$this->assertSame( 'modal_kit_unsigned', $updater->verify_download_with( false, $url, $public )->get_error_code() );
		$this->assertFalse( $updater->verify_download_with( false, 'https://example.org/other-plugin.zip', $public ), 'Other plugins are left alone.' );

		$info( array( $url => $bad ) );
		$this->assertSame( 'modal_kit_bad_signature', $updater->verify_download_with( false, $url, $public )->get_error_code() );

		$info( array( $url => $good ) );
		$path = $updater->verify_download_with( false, $url, $public, $ours );
		$this->assertIsString( $path );
		$this->assertSame( $zip, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $path );

		// The hook checks with the shipped key, which didn't sign this.
		$this->assertSame( 'modal_kit_bad_signature', Updater::on_download( false, $url, null, $ours )->get_error_code() );

		// Its own upgrade and language packs are checked whatever the address.
		$unlisted = 'https://packages.cocartheadless.com/old.zip';
		$this->assertSame( 'modal_kit_unsigned', $updater->verify_download_with( false, $unlisted, $public, $ours )->get_error_code() );
		$this->assertFalse( $updater->verify_download_with( false, $unlisted, $public, array( 'plugin' => 'other/other.php' ) ), 'Other plugins’ upgrades are left alone.' );
		$language = array(
			'language_update_type' => 'plugin',
			'language_update'      => (object) array(
				'type' => 'plugin',
				'slug' => 'modal-kit',
			),
		);
		$this->assertSame( 'modal_kit_unsigned', $updater->verify_download_with( false, $unlisted, $public, $language )->get_error_code() );

		// A file an earlier filter fetched is checked, not trusted.
		$handed = wp_tempnam( 'handed' );
		file_put_contents( $handed, 'swapped' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 'modal_kit_bad_signature', $updater->verify_download_with( $handed, $url, $public, $ours )->get_error_code() );

		// No update info (the server didn't answer): fail closed.
		set_transient( 'modal_kit_update_info', 'error', HOUR_IN_SECONDS );
		$this->assertSame( 'modal_kit_no_signature', $updater->verify_download_with( false, $url, $public, $ours )->get_error_code() );
		$this->assertFalse( $updater->verify_download_with( false, 'https://example.org/other-plugin.zip', $public ), 'Other plugins are left alone.' );

		remove_filter( 'pre_http_request', $serve );
	}

	public function test_channels_pausing_and_automatic_updates_need_no_license() {
		$updater = $this->updater();
		$file    = $updater->basename();

		$this->assertSame( 'stable', $updater->channel() );
		$this->assertTrue( $updater->set_channel( 'beta' ) );
		$this->assertSame( 'beta', $updater->channel() );
		$this->assertSame( 'modal_kit_channel', $updater->set_channel( 'alpha' )->get_error_code() );

		$updater->info( true );
		$this->assertStringContainsString( 'channel=beta', end( $this->requests )[0] );

		$this->assertTrue( $updater->pause( 2 ) );
		$this->assertEqualsWithDelta( time() + 2 * WEEK_IN_SECONDS, $updater->paused_until(), 5 );
		$this->assertFalse( Updater::on_update( false, array(), $file ), 'No updates while paused.' );
		$this->assertWPError( $updater->pause( 53 ) );
		$this->assertTrue( $updater->pause( 0 ) );
		$this->assertSame( 0, $updater->paused_until() );
		$this->assertSame( '9.9.9', Updater::on_update( false, array(), $file )['version'] );

		$item = (object) array( 'plugin' => $file );
		$this->assertNull( Updater::on_auto_update( null, $item ) );
		$updater->set_auto_updates( true );
		$this->assertTrue( Updater::on_auto_update( null, $item ) );
		$this->assertNull( Updater::on_auto_update( null, (object) array( 'plugin' => 'other/other.php' ) ) );
	}

	public function test_rolling_back_installs_an_earlier_version_and_pauses_updates() {
		$updater   = $this->updater();
		$installed = array();

		$this->assertSame( array( '0.0.9', '0.0.8' ), $updater->rollback_versions( true ) );
		$this->assertSame( 'modal_kit_rollback_version', $updater->rollback( '9.9.9' )->get_error_code() );

		add_filter(
			'modal_kit_pre_rollback',
			static function ( $result, $package, $version ) use ( &$installed ) {
				$installed = array( $package, $version );
				return true;
			},
			10,
			3
		);

		$this->assertTrue( $updater->rollback( '0.0.9' ) );
		$this->assertSame( array( 'https://packages.cocartheadless.com/modal-kit-0.0.9.zip', '0.0.9' ), $installed );
		$this->assertSame( MODAL_KIT_VERSION, $updater->settings()['previous_version'] );
		$this->assertGreaterThan( time(), $updater->paused_until(), 'Paused, so the newer version isn’t reinstalled straight away.' );
	}

	public function test_an_update_is_remembered_and_announced_once() {
		$updater = $this->updater();

		Updater::on_upgraded(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'other/other.php' ),
			)
		);
		$this->assertFalse( get_transient( 'modal_kit_just_updated' ) );

		Updater::on_upgraded(
			null,
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => $updater->basename(),
			)
		);
		$this->assertSame( MODAL_KIT_VERSION, get_transient( 'modal_kit_just_updated' ) );

		set_transient( 'modal_kit_just_updated', '0.0.9' );
		$this->log_in( 'administrator' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		ob_start();
		$updater->just_updated_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Modal Kit is now on version ' . MODAL_KIT_VERSION . ' (was 0.0.9).', $notice );

		ob_start();
		$updater->just_updated_notice();
		$this->assertSame( '', ob_get_clean(), 'Once.' );
	}

	public function test_the_updates_tab_routes() {
		$this->log_in( 'editor' );
		$this->assertSame( 403, $this->rest( 'GET', '/updates' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', '/updates', array( 'channel' => 'beta' ) )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/updates/rollback' )->get_status() );

		$this->log_in( 'administrator' );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		$state = $this->rest( 'GET', '/updates', array( 'refresh' => true ) )->get_data();
		$this->assertSame( MODAL_KIT_VERSION, $state['version'] );
		$this->assertSame( '9.9.9', $state['update'] );
		$this->assertSame( 'stable', $state['channel'] );
		$this->assertCount( 4, $state['channels'] );
		$this->assertTrue( $state['canChange'] );

		$state = $this->rest(
			'POST',
			'/updates',
			array(
				'channel'    => 'rc',
				'pause'      => 4,
				'autoUpdate' => true,
			)
		)->get_data();
		$this->assertSame( 'rc', $state['channel'] );
		$this->assertTrue( $state['autoUpdate'] );
		$this->assertGreaterThan( time(), $state['pausedUntil'] );

		$this->assertSame( 400, $this->rest( 'POST', '/updates', array( 'channel' => 'alpha' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/updates', array( 'pause' => 60 ) )->get_status() );

		$this->assertSame( array( '0.0.9', '0.0.8' ), $this->rest( 'GET', '/updates/rollback' )->get_data()['versions'] );
		$this->assertSame( 400, $this->rest( 'POST', '/updates/rollback', array( 'version' => '9.9.9' ) )->get_status() );

		add_filter( 'modal_kit_pre_rollback', '__return_true' );
		$this->assertSame( 200, $this->rest( 'POST', '/updates/rollback', array( 'version' => '0.0.8' ) )->get_status() );
	}
}
