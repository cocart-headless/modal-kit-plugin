<?php
/**
 * Plugin updates from the package server.
 *
 * @package ModalKit
 */

namespace ModalKit\Updates;

use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Updates for Modal Kit and the plugins that build on it, from the package
 * server (packages.cocartheadless.com), never WordPress.org. Each plugin
 * registers itself once with `Updater::register()` and gets its own instance;
 * the WordPress hooks are added once and handed to the instance whose plugin
 * they are about.
 *
 * The plugins carry `Update URI: https://modalkitwp.com/`, so WordPress.org is
 * never asked about them; WordPress asks through `update_plugins_modalkitwp.com`.
 *
 * A plugin's update endpoint (`plugin-info/{slug}.json`) says which version is
 * out and, when the plugin may have it, includes the download links: always
 * for a free plugin, and with a valid license key for a paid one. A plugin
 * that registers a `licensed` callback gets no packages while it returns
 * false, whatever the endpoint says. Download links expire after a day or
 * two, so a fresh one is fetched when installing.
 *
 * Also here: release channels, pausing updates, rolling back to an earlier
 * version, translation updates, signed packages, automatic updates and a
 * notice after a plugin changed version.
 */
final class Updater {

	/**
	 * The package server.
	 */
	const SERVER = 'https://packages.cocartheadless.com';

	/**
	 * Hosts update packages can come from, always over HTTPS.
	 */
	const PACKAGE_HOSTS = array( 'packages.cocartheadless.com' );

	/**
	 * Hex Ed25519 public key that every update package must be signed with.
	 * A package whose signature doesn't match isn't installed.
	 */
	const PUBLIC_KEY = 'b08c1c415329f8ffa0f866cce83915392177d93c5034a2186c905d261ca13ee8';

	/**
	 * Release channels, most stable first.
	 */
	const CHANNELS = array( 'stable', 'rc', 'beta', 'nightly' );

	/**
	 * The host in the plugins' `Update URI` header.
	 */
	const UPDATE_HOST = 'modalkitwp.com';

	/**
	 * Registered plugins, by slug.
	 *
	 * @var array<string, Updater>
	 */
	private static $products = array();

	/**
	 * Whether the WordPress hooks were added.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Plugin slug: its folder, and its name on the package server.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Main plugin file, as a full path.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Plugin name, for messages and "View details".
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Plugin home page.
	 *
	 * @var string
	 */
	private $homepage;

	/**
	 * Prefix for the plugin's options, transients, hooks and error codes.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Returns whether the plugin may have packages, or null when it always may.
	 *
	 * @var callable|null
	 */
	private $licensed;

	/**
	 * Returns the license key to send, or ''.
	 *
	 * @var callable|null
	 */
	private $key;

	/**
	 * Returns extra query arguments for the update endpoint.
	 *
	 * @var callable|null
	 */
	private $query;

	/**
	 * Runs when someone presses "Check again" on Dashboard → Updates.
	 *
	 * @var callable|null
	 */
	private $on_check_again;

	/**
	 * Set while rolling back, so `package()` keeps the older package.
	 *
	 * @var bool
	 */
	private $rolling_back = false;

	/**
	 * Whether "Check again" was already handled in this request.
	 *
	 * @var bool
	 */
	private $checked_again = false;

	/**
	 * Use `Updater::register()`.
	 *
	 * @param array<string, mixed> $args See `register()`.
	 */
	private function __construct( $args ) {
		$this->slug           = (string) $args['slug'];
		$this->file           = (string) $args['file'];
		$this->version        = (string) $args['version'];
		$this->name           = (string) $args['name'];
		$this->homepage       = (string) ( $args['homepage'] ?? 'https://modalkitwp.com/' );
		$this->prefix         = (string) $args['prefix'];
		$this->licensed       = is_callable( $args['licensed'] ?? null ) ? $args['licensed'] : null;
		$this->key            = is_callable( $args['key'] ?? null ) ? $args['key'] : null;
		$this->query          = is_callable( $args['query'] ?? null ) ? $args['query'] : null;
		$this->on_check_again = is_callable( $args['on_check_again'] ?? null ) ? $args['on_check_again'] : null;
	}

	/**
	 * Registers a plugin for updates, once. A second call for the same slug
	 * returns the first registration unchanged.
	 *
	 * Arguments: `slug` (the plugin's folder and its name on the package
	 * server), `file` (main plugin file, full path), `version`, `name`,
	 * `homepage`, and `prefix` (for its options, transients, hooks and error
	 * codes, e.g. `modal_kit_`). Optional callables: `licensed` returns whether
	 * the plugin may have packages (omit it for a free plugin), `key` returns
	 * the license key to send, `query` returns extra query arguments for the
	 * update endpoint, and `on_check_again` runs when someone presses "Check
	 * again" on Dashboard → Updates.
	 *
	 * @param array<string, mixed> $args Plugin details, as above.
	 * @return Updater
	 */
	public static function register( $args ) {
		$slug = (string) ( $args['slug'] ?? '' );

		if ( ! isset( self::$products[ $slug ] ) ) {
			self::$products[ $slug ] = new self( $args );
		}

		self::hook();

		return self::$products[ $slug ];
	}

	/**
	 * A registered plugin's updater.
	 *
	 * @param string $slug Plugin slug.
	 * @return Updater|null
	 */
	public static function get( $slug ) {
		return self::$products[ $slug ] ?? null;
	}

	/**
	 * Adds the WordPress hooks, once for every plugin.
	 *
	 * @return void
	 */
	private static function hook() {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_filter( 'update_plugins_' . self::UPDATE_HOST, array( __CLASS__, 'on_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'on_details' ), 20, 3 );
		add_filter( 'upgrader_package_options', array( __CLASS__, 'on_package' ) );
		// Last, so a file an earlier filter hands over is checked too.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'on_download' ), PHP_INT_MAX, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'on_auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgraded' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'on_admin_notices' ) );
	}

	/**
	 * Whether this site holds the update settings. WordPress only checks for
	 * and installs plugin updates from a network's main site.
	 *
	 * @return bool
	 */
	public static function manages_updates() {
		return ! is_multisite() || is_main_site();
	}

	/**
	 * `update_plugins_modalkitwp.com`: the registered plugin it is about answers.
	 *
	 * @param array<string, mixed>|false $update      Update data.
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin file.
	 * @param string[]                   $locales     Site locales.
	 * @return array<string, mixed>|false
	 */
	public static function on_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		foreach ( self::$products as $product ) {
			if ( $product->basename() === $plugin_file ) {
				return $product->update( $update, $plugin_data, $plugin_file, $locales );
			}
		}

		return $update;
	}

	/**
	 * `plugins_api`: "View details" for a registered plugin.
	 *
	 * @param false|object|array<string, mixed> $result Result.
	 * @param string                            $action API action.
	 * @param object                            $args   Arguments.
	 * @return false|object|array<string, mixed>
	 */
	public static function on_details( $result, $action, $args ) {
		$product = isset( $args->slug ) ? self::get( (string) $args->slug ) : null;

		return $product ? $product->details( $result, $action, $args ) : $result;
	}

	/**
	 * `upgrader_package_options`: a fresh download link at install time.
	 *
	 * @param array<string, mixed> $options Upgrader options.
	 * @return array<string, mixed>
	 */
	public static function on_package( $options ) {
		foreach ( self::$products as $product ) {
			$options = $product->package( $options );
		}

		return $options;
	}

	/**
	 * `upgrader_pre_download`: checks the signature of a registered plugin's package.
	 *
	 * @param mixed                $reply      Earlier filters' answer.
	 * @param string               $package    Package address.
	 * @param mixed                $upgrader   The upgrader.
	 * @param array<string, mixed> $hook_extra What is being upgraded.
	 * @return mixed The answer, a local file path, or WP_Error.
	 */
	public static function on_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		foreach ( self::$products as $product ) {
			$reply = $product->verify_download( $reply, $package, $upgrader, (array) $hook_extra );
		}

		return $reply;
	}

	/**
	 * `auto_update_plugin`: automatic updates for the registered plugins that turned them on.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update item.
	 * @return bool|null
	 */
	public static function on_auto_update( $update, $item ) {
		foreach ( self::$products as $product ) {
			$update = $product->auto_update( $update, $item );
		}

		return $update;
	}

	/**
	 * `upgrader_process_complete`: remembers the version each updated plugin came from.
	 *
	 * @param object               $upgrader Upgrader.
	 * @param array<string, mixed> $options  Options: `plugin` for one update, `plugins` for bulk.
	 * @return void
	 */
	public static function on_upgraded( $upgrader, $options ) {
		foreach ( self::$products as $product ) {
			$product->purge( $upgrader, $options );
		}
	}

	/**
	 * `admin_notices`: one notice for each plugin that just changed version.
	 *
	 * @return void
	 */
	public static function on_admin_notices() {
		foreach ( self::$products as $product ) {
			$product->just_updated_notice();
		}
	}

	/**
	 * Plugin slug.
	 *
	 * @return string
	 */
	public function slug() {
		return $this->slug;
	}

	/**
	 * Plugin name.
	 *
	 * @return string
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Installed version.
	 *
	 * @return string
	 */
	public function version() {
		return $this->version;
	}

	/**
	 * Plugin basename.
	 *
	 * @return string
	 */
	public function basename() {
		return plugin_basename( $this->file );
	}

	/**
	 * Update endpoint.
	 *
	 * @return string
	 */
	public function endpoint() {
		return self::SERVER . '/plugin-info/' . rawurlencode( $this->slug ) . '.json';
	}

	/**
	 * Name of one of the plugin's options or transients.
	 *
	 * @param string $name `update_info`, `auto_update`, `update_settings` or `just_updated`.
	 * @return string
	 */
	public function option( $name ) {
		return $this->prefix . $name;
	}

	/**
	 * Whether the plugin may have packages now.
	 *
	 * @return bool
	 */
	public function is_licensed() {
		return null === $this->licensed || (bool) call_user_func( $this->licensed );
	}

	/**
	 * Update settings.
	 *
	 * @return array{channel: string, paused_until: int, previous_version: string, updated_at: int}
	 */
	public function settings() {
		$settings = get_option( $this->option( 'update_settings' ), array() );
		$settings = array_merge(
			array(
				'channel'          => 'stable',
				'paused_until'     => 0,
				'previous_version' => '',
				'updated_at'       => 0,
			),
			is_array( $settings ) ? $settings : array()
		);

		return array(
			'channel'          => in_array( $settings['channel'], self::CHANNELS, true ) ? (string) $settings['channel'] : 'stable',
			'paused_until'     => (int) $settings['paused_until'],
			'previous_version' => (string) $settings['previous_version'],
			'updated_at'       => (int) $settings['updated_at'],
		);
	}

	/**
	 * Saves some update settings (not autoloaded).
	 *
	 * @param array<string, mixed> $changes Changes.
	 * @return void
	 */
	private function save_settings( $changes ) {
		update_option( $this->option( 'update_settings' ), array_merge( $this->settings(), $changes ), false );
	}

	/**
	 * Forgets cached update data, so the next check asks again.
	 *
	 * @return void
	 */
	public function flush() {
		delete_transient( $this->option( 'update_info' ) );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Release channels for the settings screens.
	 *
	 * @return array<int, array{value: string, label: string, description: string}>
	 */
	public static function channels() {
		return array(
			array(
				'value'       => 'stable',
				'label'       => __( 'Stable', 'modal-kit' ),
				'description' => __( 'Fully tested releases. Recommended for live sites.', 'modal-kit' ),
			),
			array(
				'value'       => 'rc',
				'label'       => __( 'Release candidate', 'modal-kit' ),
				'description' => __( 'The next stable version, shortly before it is released.', 'modal-kit' ),
			),
			array(
				'value'       => 'beta',
				'label'       => __( 'Beta', 'modal-kit' ),
				'description' => __( 'New features still being tested. May have bugs: best on a staging site.', 'modal-kit' ),
			),
			array(
				'value'       => 'nightly',
				'label'       => __( 'Nightly', 'modal-kit' ),
				'description' => __( 'The latest unreleased build. For trying changes before anyone else.', 'modal-kit' ),
			),
		);
	}

	/**
	 * The release channel in use.
	 *
	 * @return string
	 */
	public function channel() {
		return $this->settings()['channel'];
	}

	/**
	 * Changes the release channel.
	 *
	 * @param string $channel stable | rc | beta | nightly.
	 * @return true|WP_Error
	 */
	public function set_channel( $channel ) {
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return new WP_Error( $this->prefix . 'channel', __( 'Choose a release channel from the list.', 'modal-kit' ) );
		}

		if ( $this->channel() !== $channel ) {
			$this->save_settings( array( 'channel' => $channel ) );
			$this->flush();
		}

		return true;
	}

	/**
	 * When paused updates resume (a timestamp), or 0.
	 *
	 * @return int
	 */
	public function paused_until() {
		$until = $this->settings()['paused_until'];

		return $until > time() ? $until : 0;
	}

	/**
	 * Pauses updates for some weeks, or resumes them (0).
	 *
	 * @param int $weeks 0 to 52.
	 * @return true|WP_Error
	 */
	public function pause( $weeks ) {
		$weeks = (int) $weeks;

		if ( $weeks < 0 || $weeks > 52 ) {
			return new WP_Error( $this->prefix . 'pause', __( 'Updates can be paused for up to 52 weeks.', 'modal-kit' ) );
		}

		$this->save_settings( array( 'paused_until' => $weeks ? time() + $weeks * WEEK_IN_SECONDS : 0 ) );
		$this->flush();

		return true;
	}

	/**
	 * Update info from the endpoint, cached (12 hours; 1 hour after errors).
	 *
	 * @param bool $force Skip the cache.
	 * @return object|null
	 */
	public function info( $force = false ) {
		$cached = get_transient( $this->option( 'update_info' ) );

		if ( ! $force && false !== $cached ) {
			return is_object( $cached ) ? $cached : null;
		}

		$key   = $this->key ? (string) call_user_func( $this->key ) : '';
		$extra = $this->query ? (array) call_user_func( $this->query ) : array();
		$url   = add_query_arg(
			array_filter(
				array_merge(
					array_map( 'strval', $extra ),
					array(
						'version' => $this->version,
						'channel' => $this->channel(),
						'site'    => home_url( '/' ),
						'locale'  => get_locale(),
					)
				)
			),
			$this->endpoint()
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => wp_doing_cron() ? 30 : 15,
				// In a header: URLs end up in server and proxy logs.
				'headers' => array( 'Accept' => 'application/json' ) + ( '' !== $key ? array( 'X-License-Key' => $key ) : array() ),
			)
		);
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$info     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ) );

		if ( 200 !== $code || ! is_object( $info ) || empty( $info->version ) ) {
			set_transient( $this->option( 'update_info' ), 'error', HOUR_IN_SECONDS );
			return null;
		}

		// No packages while the plugin may not have them, whatever the endpoint says.
		if ( ! $this->is_licensed() ) {
			$info->download_link = '';
			$info->versions      = array();
			$info->translations  = array();
		}

		$info = self::only_allowed_packages( $info );

		set_transient( $this->option( 'update_info' ), $info, 12 * HOUR_IN_SECONDS );

		return $info;
	}

	/**
	 * Whether the last check couldn't reach the update server.
	 *
	 * @return bool
	 */
	public function offline() {
		return 'error' === get_transient( $this->option( 'update_info' ) );
	}

	/**
	 * Newer version available, or ''.
	 *
	 * @return string
	 */
	public function available() {
		$info = get_transient( $this->option( 'update_info' ) );

		return is_object( $info ) && ! empty( $info->version ) && version_compare( (string) $info->version, $this->version, '>' ) ? (string) $info->version : '';
	}

	/**
	 * Whether someone pressed "Check again" on Dashboard → Updates, so the
	 * cached update info is skipped. Once per request.
	 *
	 * @return bool
	 */
	private function checking_again() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's "Check again" link has no nonce; it only asks again.
		if ( $this->checked_again || ! is_admin() || 'update-core.php' !== ( $GLOBALS['pagenow'] ?? '' ) || empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}

		$this->checked_again = true;

		return true;
	}

	/**
	 * Answers WordPress's update check for this plugin.
	 *
	 * @param array<string, mixed>|false $update      Update data.
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin file.
	 * @param string[]                   $locales     Site locales.
	 * @return array<string, mixed>|false
	 */
	public function update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( $this->basename() !== $plugin_file || $this->paused_until() ) {
			return $update;
		}

		$force = $this->checking_again();

		if ( $force && $this->on_check_again ) {
			call_user_func( $this->on_check_again );
		}

		$info = $this->info( $force );

		if ( ! $info ) {
			return $update;
		}

		return array(
			'id'           => self::UPDATE_HOST . '/' . $this->slug,
			'slug'         => $this->slug,
			'plugin'       => $plugin_file,
			'version'      => (string) $info->version,
			'new_version'  => (string) $info->version,
			'url'          => $this->homepage,
			'package'      => (string) ( $info->download_link ?? '' ),
			'tested'       => (string) ( $info->tested ?? '' ),
			'requires'     => (string) ( $info->requires ?? '' ),
			'requires_php' => (string) ( $info->requires_php ?? '' ),
			'icons'        => isset( $info->icons ) ? (array) $info->icons : array(),
			'translations' => $this->translations( $info, (array) $locales ),
		);
	}

	/**
	 * Whether a package address can be trusted: HTTPS, on an allowed host.
	 *
	 * @param string $url Package address.
	 * @return bool
	 */
	public static function is_allowed_package( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && in_array( $host, self::PACKAGE_HOSTS, true );
	}

	/**
	 * Drops every package whose address isn't allowed.
	 *
	 * @param object $info Update info.
	 * @return object
	 */
	private static function only_allowed_packages( $info ) {
		if ( ! empty( $info->download_link ) && ! self::is_allowed_package( (string) $info->download_link ) ) {
			$info->download_link = '';
		}

		if ( ! empty( $info->versions ) ) {
			$info->versions = array_filter( (array) $info->versions, array( __CLASS__, 'is_allowed_package' ) );
		}

		if ( ! empty( $info->translations ) ) {
			$info->translations = array_values(
				array_filter(
					(array) $info->translations,
					static function ( $pack ) {
						return self::is_allowed_package( (string) ( ( (array) $pack )['package'] ?? '' ) );
					}
				)
			);
		}

		return $info;
	}

	/**
	 * Whether a file carries a valid signature.
	 *
	 * Packages are signed over the file's SHA-384 hash (raw bytes), as
	 * WordPress core signs its own (verify_file_signature()). The hash is
	 * read in chunks, so a large package doesn't have to fit in memory.
	 *
	 * @param string $file      File path.
	 * @param string $signature Base64 signature.
	 * @param string $key       Hex public key.
	 * @return bool
	 */
	public static function signature_ok( $file, $signature, $key ) {
		$raw_signature = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Not obfuscation: a signature.
		$raw_key       = ctype_xdigit( $key ) ? hex2bin( $key ) : false;
		$hash          = is_readable( $file ) ? hash_file( 'sha384', $file, true ) : false;

		if ( false === $raw_signature || false === $raw_key || false === $hash || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw_signature ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw_key ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $raw_signature, $hash, $raw_key );
		} catch ( \Exception $exception ) {
			return false;
		}
	}

	/**
	 * Downloads and checks this plugin's packages before WordPress installs
	 * them.
	 *
	 * @param mixed                $reply      Earlier filters' answer.
	 * @param string               $package    Package address.
	 * @param mixed                $upgrader   The upgrader.
	 * @param array<string, mixed> $hook_extra What is being upgraded.
	 * @return mixed The answer, a local file path, or WP_Error.
	 */
	public function verify_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		return $this->verify_download_with( $reply, $package, self::PUBLIC_KEY, (array) $hook_extra, $upgrader );
	}

	/**
	 * The check behind `verify_download()`, for a given key. The hook always
	 * passes `PUBLIC_KEY`, which isn't filterable; the tests pass their own.
	 *
	 * A package is this plugin's when the upgrade is for the plugin or its
	 * language packs, whatever its address, or when its address is in the
	 * update info. Going by the upgrade, not only the address, means every
	 * package for this plugin is checked, even one whose address isn't listed.
	 *
	 * @param mixed                $reply      Earlier filters' answer.
	 * @param string               $package    Package address.
	 * @param string               $key        Hex public key, or '' while signing is off.
	 * @param array<string, mixed> $hook_extra What is being upgraded.
	 * @param mixed                $upgrader   The upgrader, whose screen shows the progress.
	 * @return mixed The answer, a local file path, or WP_Error.
	 */
	public function verify_download_with( $reply, $package, $key, $hook_extra = array(), $upgrader = null ) {
		if ( '' === $key || is_wp_error( $reply ) || ! is_string( $package ) ) {
			return $reply;
		}

		$ours = $this->is_ours( $package, null, $hook_extra );
		$info = $ours || $this->is_cached_package( $package ) ? $this->info() : null;

		if ( ! $ours && ! $this->is_ours( $package, $info, $hook_extra ) ) {
			return $reply;
		}

		$signatures = $info && isset( $info->signatures ) ? (array) $info->signatures : array();

		if ( ! $info ) {
			return new WP_Error(
				$this->prefix . 'no_signature',
				/* translators: %s: plugin name. */
				sprintf( __( 'Couldn’t get this %s package’s signature from the update server, so it wasn’t installed. Try again later.', 'modal-kit' ), $this->name )
			);
		}

		if ( empty( $signatures[ $package ] ) ) {
			return new WP_Error(
				$this->prefix . 'unsigned',
				/* translators: %s: plugin name. */
				sprintf( __( 'This %s package isn’t signed, so it wasn’t installed.', 'modal-kit' ), $this->name )
			);
		}

		$skin = $upgrader instanceof \WP_Upgrader && $upgrader->skin instanceof \WP_Upgrader_Skin ? $upgrader->skin : null;

		// An earlier filter may have fetched the file already: check that one.
		if ( is_string( $reply ) && '' !== $reply ) {
			$file = $reply;
		} else {
			// Returning the file skips core's own "Downloading…" line, so say it here.
			if ( $skin ) {
				$skin->feedback( 'downloading_package', $package );
			}

			$file = download_url( $package );
		}

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! self::signature_ok( $file, (string) $signatures[ $package ], $key ) ) {
			wp_delete_file( $file );

			return new WP_Error(
				$this->prefix . 'bad_signature',
				/* translators: %s: plugin name. */
				sprintf( __( 'This %s package failed its signature check, so it wasn’t installed.', 'modal-kit' ), $this->name )
			);
		}

		if ( $skin ) {
			$skin->feedback(
				sprintf(
					/* translators: %s: the public key, in base64. */
					__( 'Verified the package signature with the signing key %s.', 'modal-kit' ),
					'<span class="code pre">%s</span>'
				),
				// Base64, as WordPress writes its own signing keys (wp_trusted_keys()).
				base64_encode( (string) hex2bin( $key ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Not obfuscation: a public key.
			);
		}

		return $file;
	}

	/**
	 * Whether a package address is in the cached update info, without asking
	 * the server. Keeps other plugins' downloads from triggering a request.
	 *
	 * @param string $package Package address.
	 * @return bool
	 */
	private function is_cached_package( $package ) {
		$cached = get_transient( $this->option( 'update_info' ) );

		if ( ! is_object( $cached ) ) {
			return false;
		}

		return in_array( $package, self::packages_of( $cached ), true ) || isset( ( (array) ( $cached->signatures ?? array() ) )[ $package ] );
	}

	/**
	 * Whether a download is this plugin's: an update or rollback of it, one of
	 * its language packs, or an address in the update info.
	 *
	 * @param string               $package    Package address.
	 * @param object|null          $info       Update info.
	 * @param array<string, mixed> $hook_extra What is being upgraded.
	 * @return bool
	 */
	private function is_ours( $package, $info, $hook_extra ) {
		if ( $this->rolling_back || $this->basename() === ( $hook_extra['plugin'] ?? '' ) ) {
			return true;
		}

		$language = $hook_extra['language_update'] ?? null;

		if ( is_object( $language ) && 'plugin' === ( $language->type ?? '' ) && isset( $language->slug ) && $this->slug === $language->slug ) {
			return true;
		}

		if ( ! $info ) {
			return false;
		}

		return in_array( $package, self::packages_of( $info ), true ) || isset( ( (array) ( $info->signatures ?? array() ) )[ $package ] );
	}

	/**
	 * Every package address in the update info.
	 *
	 * @param object $info Update info.
	 * @return string[]
	 */
	private static function packages_of( $info ) {
		$packages = array_merge( array( (string) ( $info->download_link ?? '' ) ), array_values( array_map( 'strval', (array) ( $info->versions ?? array() ) ) ) );

		foreach ( (array) ( $info->translations ?? array() ) as $pack ) {
			$packages[] = (string) ( ( (array) $pack )['package'] ?? '' );
		}

		return array_values( array_filter( $packages ) );
	}

	/**
	 * Language packs from the update info that this site needs: for one of
	 * its locales, and newer than the one installed. WordPress installs them
	 * with its own translation updates.
	 *
	 * Each pack in the info: { language, version, updated, package }.
	 *
	 * @param object   $info    Update info.
	 * @param string[] $locales Site locales.
	 * @return array<int, array<string, mixed>>
	 */
	public function translations( $info, $locales ) {
		if ( empty( $info->translations ) || ! $locales ) {
			return array();
		}

		$installed = wp_get_installed_translations( 'plugins' );
		$installed = $installed[ $this->slug ] ?? array();
		$packs     = array();

		foreach ( (array) $info->translations as $pack ) {
			$pack = (array) $pack;

			if ( empty( $pack['language'] ) || empty( $pack['package'] ) || empty( $pack['updated'] ) || ! in_array( $pack['language'], $locales, true ) ) {
				continue;
			}

			$current = (string) ( $installed[ $pack['language'] ]['PO-Revision-Date'] ?? '' );

			if ( '' !== $current && strtotime( (string) $pack['updated'] ) <= strtotime( $current ) ) {
				continue;
			}

			$packs[] = array(
				'language'   => (string) $pack['language'],
				'version'    => (string) ( $pack['version'] ?? $this->version ),
				'updated'    => (string) $pack['updated'],
				'package'    => (string) $pack['package'],
				'autoupdate' => $this->auto_updates(),
			);
		}

		return $packs;
	}

	/**
	 * Whether this user may roll back here.
	 *
	 * @return bool
	 */
	public function can_roll_back() {
		return current_user_can( 'update_plugins' ) && wp_is_file_mod_allowed( $this->prefix . 'rollback' ) && self::manages_updates();
	}

	/**
	 * Earlier versions this site can roll back to, newest first. The info
	 * lists them as { version: download link }.
	 *
	 * @param bool $force Skip the cache.
	 * @return string[]
	 */
	public function rollback_versions( $force = false ) {
		$info = $this->is_licensed() ? $this->info( $force ) : null;

		if ( ! $info || empty( $info->versions ) ) {
			return array();
		}

		$installed = $this->version;
		$versions  = array_filter(
			array_map( 'strval', array_keys( (array) $info->versions ) ),
			static function ( $version ) use ( $installed ) {
				return version_compare( $version, $installed, '<' );
			}
		);

		usort( $versions, 'version_compare' );

		return array_reverse( $versions );
	}

	/**
	 * Installs an earlier version.
	 *
	 * @param string $version Version to install.
	 * @return true|WP_Error
	 */
	public function rollback( $version ) {
		// Download links may be short-lived: ask for fresh ones.
		$info     = $this->is_licensed() ? $this->info( true ) : null;
		$versions = $info && ! empty( $info->versions ) ? (array) $info->versions : array();

		if ( ! in_array( $version, $this->rollback_versions(), true ) || empty( $versions[ $version ] ) ) {
			return new WP_Error( $this->prefix . 'rollback_version', __( 'That version isn’t available to roll back to.', 'modal-kit' ) );
		}

		$package = (string) $versions[ $version ];

		/**
		 * Short-circuits a rollback (tests, or hosts that manage plugin files).
		 * The hook name starts with the plugin's prefix: `modal_kit_pre_rollback`
		 * for Modal Kit.
		 *
		 * @param null|true|WP_Error $result  Null to roll back here.
		 * @param string             $package Package URL.
		 * @param string             $version Version.
		 */
		$result = apply_filters( $this->prefix . 'pre_rollback', null, $package, $version ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Each plugin's own prefix, such as modal_kit_.

		if ( null === $result ) {
			$result = $this->install( $package, $version );
		}

		if ( true === $result ) {
			$this->recorded( $this->version );

			// So the newer version isn't installed again straight away.
			if ( ! $this->paused_until() ) {
				$this->pause( 1 );
			}
		}

		return $result;
	}

	/**
	 * Installs a package over the plugin the way the Plugins screen installs
	 * updates (core's bulk upgrader): maintenance mode while the files are
	 * swapped, and the plugin stays active.
	 *
	 * @param string $package Package URL.
	 * @param string $version Version.
	 * @return true|WP_Error
	 */
	private function install( $package, $version ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$file    = $this->basename();
		$current = get_site_transient( 'update_plugins' );
		$current = is_object( $current ) ? $current : new stdClass();

		// The upgrader installs what the update list offers.
		$current->response          = isset( $current->response ) ? (array) $current->response : array();
		$current->response[ $file ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $file,
			'new_version' => $version,
			'package'     => $package,
		);
		set_site_transient( 'update_plugins', $current );

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		$this->rolling_back = true;
		$results            = $upgrader->bulk_upgrade( array( $file ) );
		$this->rolling_back = false;

		wp_clean_plugins_cache();

		if ( is_array( $results ) && ! empty( $results[ $file ] ) && ! is_wp_error( $results[ $file ] ) ) {
			return true;
		}

		$errors = $skin->get_error_messages();

		return new WP_Error(
			$this->prefix . 'rollback_failed',
			'' !== $errors ? $errors : __( 'Couldn’t install that version. Check the file permissions and try again.', 'modal-kit' )
		);
	}

	/**
	 * "View details" in the Plugins screen.
	 *
	 * @param false|object|array<string, mixed> $result Result.
	 * @param string                            $action API action.
	 * @param object                            $args   Arguments.
	 * @return false|object|array<string, mixed>
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->slug !== $args->slug ) {
			return $result;
		}

		$info = $this->info();

		if ( ! $info ) {
			return $result;
		}

		$details                = new stdClass();
		$details->name          = $this->name;
		$details->slug          = $this->slug;
		$details->version       = (string) $info->version;
		$details->author        = '<a href="https://modalkitwp.com/">Sébastien Dumont</a>';
		$details->homepage      = $this->homepage;
		$details->requires      = (string) ( $info->requires ?? '' );
		$details->requires_php  = (string) ( $info->requires_php ?? '' );
		$details->tested        = (string) ( $info->tested ?? '' );
		$details->last_updated  = (string) ( $info->last_updated ?? '' );
		$details->download_link = (string) ( $info->download_link ?? '' );
		$details->sections      = isset( $info->sections ) ? array_map( 'wp_kses_post', (array) $info->sections ) : array();
		$details->banners       = isset( $info->banners ) ? (array) $info->banners : array();

		return $details;
	}

	/**
	 * Download links may be short-lived: fetch a fresh one at install time.
	 *
	 * @param array<string, mixed> $options Upgrader options.
	 * @return array<string, mixed>
	 */
	public function package( $options ) {
		if ( $this->rolling_back || ( $options['hook_extra']['plugin'] ?? '' ) !== $this->basename() ) {
			return $options;
		}

		$info = $this->info( true );

		if ( $info && ! empty( $info->download_link ) ) {
			$options['package'] = (string) $info->download_link;
		}

		return $options;
	}

	/**
	 * Whether this plugin's updates install automatically.
	 *
	 * @return bool
	 */
	public function auto_updates() {
		return (bool) get_option( $this->option( 'auto_update' ), false );
	}

	/**
	 * Turns automatic updates on or off.
	 *
	 * @param bool $on On.
	 * @return void
	 */
	public function set_auto_updates( $on ) {
		update_option( $this->option( 'auto_update' ), $on ? 1 : 0 );
	}

	/**
	 * Automatic updates for this plugin, when on and it may have packages.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update item.
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $this->basename() === $item->plugin && $this->auto_updates() && $this->is_licensed() ) {
			return true;
		}

		return $update;
	}

	/**
	 * After the plugin was updated or rolled back: clears the cache and
	 * remembers the version it came from. This still runs the old code, so
	 * the version is the previous one.
	 *
	 * @param object               $upgrader Upgrader.
	 * @param array<string, mixed> $options  Options: `plugin` for one update, `plugins` for bulk.
	 * @return void
	 */
	public function purge( $upgrader, $options ) {
		unset( $upgrader );

		$plugins = (array) ( $options['plugins'] ?? array() );

		if ( isset( $options['plugin'] ) ) {
			$plugins[] = $options['plugin'];
		}

		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) && in_array( $this->basename(), $plugins, true ) ) {
			delete_transient( $this->option( 'update_info' ) );
			$this->recorded( $this->version );
		}
	}

	/**
	 * Records the version the plugin changed from, for the settings screen
	 * and the notice that follows.
	 *
	 * @param string $previous Previous version.
	 * @return void
	 */
	private function recorded( $previous ) {
		$this->save_settings(
			array(
				'previous_version' => $previous,
				'updated_at'       => time(),
			)
		);
		set_transient( $this->option( 'just_updated' ), $previous, DAY_IN_SECONDS );
	}

	/**
	 * One notice after the plugin changed version, for people who can update plugins.
	 *
	 * @return void
	 */
	public function just_updated_notice() {
		$previous = get_transient( $this->option( 'just_updated' ) );

		if ( false === $previous || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		delete_transient( $this->option( 'just_updated' ) );

		if ( $this->version === $previous ) {
			return;
		}

		add_thickbox();

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s" class="thickbox open-plugin-details-modal">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: plugin name, 2: new version, 3: previous version. */
					__( '%1$s is now on version %2$s (was %3$s).', 'modal-kit' ),
					$this->name,
					$this->version,
					(string) $previous
				)
			),
			esc_url( self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $this->slug . '&section=changelog&TB_iframe=true&width=600&height=550' ) ),
			esc_html__( 'What’s new', 'modal-kit' )
		);
	}

	/**
	 * State for a settings screen's update controls.
	 *
	 * @return array<string, mixed>
	 */
	public function describe() {
		$settings = $this->settings();

		return array(
			'name'            => $this->name,
			'version'         => $this->version,
			'update'          => $this->available(),
			'offline'         => $this->offline(),
			'autoUpdate'      => $this->auto_updates(),
			'channel'         => $settings['channel'],
			'channels'        => self::channels(),
			'pausedUntil'     => $this->paused_until(),
			'previousVersion' => $settings['previous_version'],
			'updatedAt'       => $settings['updated_at'],
			'canRollback'     => $this->can_roll_back(),
		);
	}
}
