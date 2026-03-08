<?php
/**
 * Plugin self-update: fetch latest release from GitHub and upgrade if newer.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Plugin_Updater {

	/** @var array<string, mixed> */
	private $config;

	/** @var string */
	private $plugin_file;

	/** @var string */
	private $current_version;

	/**
	 * @param array<string, mixed> $config
	 * @param string               $plugin_file Plugin basename (e.g. d4h-calendar/d4h-calendar.php).
	 * @param string               $current_version
	 */
	public function __construct( array $config, string $plugin_file, string $current_version ) {
		$this->config          = $config;
		$this->plugin_file     = $plugin_file;
		$this->current_version = $current_version;
	}

	/**
	 * Fetches latest release info from GitHub. Returns null on failure.
	 *
	 * @return array{version: string, package: string, url: string}|null
	 */
	public function fetch_latest_release(): ?array {
		$repo = $this->config['update_github_repo'] ?? '';
		if ( $repo === '' ) {
			return null;
		}

		$repo = ltrim( $repo, '/' );
		$url  = 'https://api.github.com/repos/' . $repo . '/releases/latest';

		$response = wp_remote_get( $url, array(
			'timeout'    => 15,
			'user-agent' => 'D4H-Calendar-WordPress-Plugin/' . $this->current_version,
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'v' ) : '';
		$package = $this->get_package_url( $data );
		$info_url = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';

		if ( $version === '' || $package === '' ) {
			return null;
		}

		return array(
			'version' => $version,
			'package' => $package,
			'url'     => $info_url,
		);
	}

	/**
	 * Returns the zip download URL from release assets. Prefers asset named d4h-calendar.zip.
	 *
	 * @param array<string, mixed> $release_data
	 * @return string
	 */
	private function get_package_url( array $release_data ): string {
		$assets = isset( $release_data['assets'] ) && is_array( $release_data['assets'] ) ? $release_data['assets'] : array();
		$slug   = 'd4h-calendar';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( $url === '' ) {
				continue;
			}
			if ( $name === $slug . '.zip' || substr( strtolower( $name ), -4 ) === '.zip' ) {
				return $url;
			}
		}

		return isset( $assets[0]['browser_download_url'] ) ? (string) $assets[0]['browser_download_url'] : '';
	}

	/**
	 * Checks if an update is available (remote version > current).
	 *
	 * @return array{available: bool, current: string, latest: string|null, package: string|null, url: string|null}
	 */
	public function check_update(): array {
		$latest = $this->fetch_latest_release();
		if ( $latest === null ) {
			return array(
				'available' => false,
				'current'   => $this->current_version,
				'latest'    => null,
				'package'   => null,
				'url'       => null,
			);
		}

		$available = version_compare( $this->current_version, $latest['version'], '<' );
		return array(
			'available' => $available,
			'current'   => $this->current_version,
			'latest'    => $latest['version'],
			'package'   => $available ? $latest['package'] : null,
			'url'       => $latest['url'],
		);
	}

	/**
	 * Runs the plugin upgrade using WordPress Plugin_Upgrader. Returns WP_Error on failure.
	 *
	 * @param string $package_url URL of the plugin zip.
	 * @return true|\WP_Error
	 */
	public function do_upgrade( string $package_url ) {
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $this->plugin_file, array( 'package' => $package_url ) );

		if ( $result === false || is_wp_error( $result ) ) {
			$error = is_wp_error( $result ) ? $result : new \WP_Error( 'upgrade_failed', __( 'Plugin upgrade failed.', 'd4h-calendar' ) );
			if ( $upgrader->skin->get_errors()->has_errors() ) {
				$error = new \WP_Error( 'upgrade_failed', $upgrader->skin->get_errors()->get_error_message() );
			}
			return $error;
		}

		return true;
	}
}
