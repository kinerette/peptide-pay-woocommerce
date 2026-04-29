<?php
/**
 * Self-hosted plugin update checker.
 *
 * Polls https://peptide-pay.com/downloads/peptide-pay-woocommerce.json once
 * per WP cron cycle (~12h, cached as a transient) and surfaces the standard
 * "new version available" notice on WP admin → Plugins so merchants can
 * update with one click. Hooks the same WP filters that wordpress.org plugins
 * hook, so the update flow looks identical to a hosted plugin.
 *
 * @package Peptide_Pay
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Peptide_Pay_Update_Checker {

	const METADATA_URL = 'https://peptide-pay.com/downloads/peptide-pay-woocommerce.json';
	const CACHE_KEY    = 'peptide_pay_remote_metadata';
	const CACHE_TTL    = HOUR_IN_SECONDS; // 1h — fast enough to surface releases without spamming the JSON.
	const PLUGIN_SLUG  = 'peptide-pay-woocommerce';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache_after_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'reconcile_active_plugins_path' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * Self-heal `active_plugins` after a Peptide-Pay update if the plugin
	 * was registered under a stale path (older folder name, manual rename,
	 * or hosting-provider extraction quirk that shifted the folder). If
	 * we don't fix this, WP shows "Plugin file does not exist" on the
	 * next admin pageload and silently deactivates us.
	 *
	 * Strategy: scan `active_plugins` for any entry that contains
	 * "peptide-pay" but doesn't match the current canonical basename.
	 * Replace it with the canonical path.
	 */
	public static function reconcile_active_plugins_path( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		if ( ! defined( 'PEPTIDE_PAY_FILE' ) ) {
			return;
		}
		$canonical = plugin_basename( PEPTIDE_PAY_FILE );
		$active    = (array) get_option( 'active_plugins', array() );
		$dirty     = false;
		foreach ( $active as $i => $p ) {
			if ( ! is_string( $p ) ) {
				continue;
			}
			if ( false !== strpos( $p, 'peptide-pay' ) && $p !== $canonical ) {
				$active[ $i ] = $canonical;
				$dirty        = true;
			}
		}
		if ( $dirty ) {
			$active = array_values( array_unique( $active ) );
			update_option( 'active_plugins', $active );
		}
	}

	/**
	 * Manual force-check: visit any admin URL with ?peptide_pay_force_check=1
	 * to flush both our metadata cache and WP's update_plugins transient.
	 * The next page load runs `inject_update()` against fresh remote data.
	 * Useful after shipping a release — saves waiting on the 1h TTL.
	 */
	public static function maybe_force_check() {
		if ( ! is_admin() ) {
			return;
		}
		if ( empty( $_GET['peptide_pay_force_check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-success is-dismissible"><p>Peptide-Pay update check forced — reload the Plugins page to see the result.</p></div>';
		} );
	}

	/**
	 * Fetch and transient-cache the remote metadata JSON.
	 *
	 * @return array|null
	 */
	private static function fetch_metadata() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}
		$resp = wp_remote_get(
			self::METADATA_URL,
			array(
				'timeout'    => 10,
				'user-agent' => 'peptide-pay-woocommerce/' . PEPTIDE_PAY_VERSION,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return null;
		}
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Inject our update into the plugin-update transient so the standard
	 * WP UI ("update available") shows up.
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::fetch_metadata();
		if ( ! $info ) {
			return $transient;
		}

		$basename       = plugin_basename( PEPTIDE_PAY_FILE );
		$local_version  = PEPTIDE_PAY_VERSION;
		$remote_version = (string) $info['version'];

		// `id` must match the plugin basename WP uses internally to look up
		// the plugin after an update — i.e. `<folder>/<main-file.php>`.
		// Earlier releases passed `<slug>/<slug>` here, which resolved to
		// `peptide-pay-woocommerce/peptide-pay-woocommerce` — a path that
		// doesn't exist (the main file is `peptide-pay.php`). On the
		// post-update activation pass, WP's validate_plugin() called
		// file_exists() on that wrong path → "Plugin file does not
		// exist". Use $basename, which IS the real path.
		$item = (object) array(
			'id'           => $basename,
			'slug'         => self::PLUGIN_SLUG,
			'plugin'       => $basename,
			'new_version'  => $remote_version,
			'url'          => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://peptide-pay.com',
			'package'      => isset( $info['download_url'] ) ? (string) $info['download_url'] : '',
			'tested'       => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'requires'     => isset( $info['requires'] ) ? (string) $info['requires'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '',
			'icons'        => array( 'default' => 'https://peptide-pay.com/icon.svg' ),
			'banners'      => array(),
		);

		if ( version_compare( $local_version, $remote_version, '<' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $basename ] = $item;
		} else {
			// Same or newer locally — push to no_update so the row in WP
			// admin shows "✓ Up to date" instead of nothing.
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $basename ] = $item;
		}
		return $transient;
	}

	/**
	 * Power the "View details" modal in WP admin → Plugins.
	 */
	public static function plugin_information( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}
		if ( empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $res;
		}
		$info = self::fetch_metadata();
		if ( ! $info ) {
			return $res;
		}
		$sections = isset( $info['sections'] ) && is_array( $info['sections'] ) ? $info['sections'] : array();
		return (object) array(
			'name'          => isset( $info['name'] ) ? (string) $info['name'] : 'Peptide-Pay for WooCommerce',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => (string) $info['version'],
			'author'        => isset( $info['author'] ) ? (string) $info['author'] : '<a href="https://peptide-pay.com">Peptide-Pay</a>',
			'requires'      => isset( $info['requires'] ) ? (string) $info['requires'] : '6.0',
			'requires_php'  => isset( $info['requires_php'] ) ? (string) $info['requires_php'] : '7.4',
			'tested'        => isset( $info['tested'] ) ? (string) $info['tested'] : '',
			'last_updated'  => isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '',
			'homepage'      => isset( $info['homepage'] ) ? (string) $info['homepage'] : 'https://peptide-pay.com',
			'download_link' => isset( $info['download_url'] ) ? (string) $info['download_url'] : '',
			'sections'      => $sections,
			'banners'       => array(),
		);
	}

	/**
	 * Flush the cached metadata after any plugin update completes — so the
	 * very next admin pageload sees the right post-upgrade state without
	 * waiting for the 12h TTL.
	 */
	public static function flush_cache_after_update( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}
		delete_transient( self::CACHE_KEY );
	}
}
