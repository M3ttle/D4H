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

		$repo  = ltrim( $repo, '/' );
		$url   = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$token = $this->get_github_token();

		$request_args = array(
			'timeout'    => 15,
			'user-agent' => 'D4H-Calendar-WordPress-Plugin/' . $this->current_version,
		);
		if ( $token !== '' ) {
			$request_args['headers'] = array(
				'Authorization' => 'token ' . $token,
			);
		}

		$response = wp_remote_get( $url, $request_args );

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
	 * Returns the GitHub token for API auth. Prefers wp-config constant over admin option.
	 *
	 * @return string
	 */
	private function get_github_token(): string {
		if ( defined( 'D4H_CALENDAR_GITHUB_TOKEN' ) && is_string( D4H_CALENDAR_GITHUB_TOKEN ) ) {
			return trim( D4H_CALENDAR_GITHUB_TOKEN );
		}
		$option_key = $this->config['option_github_token'] ?? 'd4h_calendar_github_token';
		$token      = get_option( $option_key, '' );
		return is_string( $token ) ? trim( $token ) : '';
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
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

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

	/**
	 * Registers a filter to inject our update into site_transient_update_plugins
	 * when a newer version is available on GitHub. Uses the standard Plugins → Updates flow.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function register_update_filter( array $config ): void {
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'filter_pre_download' ), 10, 3 );
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update_plugins_github' ), 10, 4 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_pre_set_update_plugins' ), 10, 1 );

		add_filter( 'site_transient_update_plugins', function ( $value ) use ( $config ) {
			$repo = $config['update_github_repo'] ?? '';
			if ( $repo === '' ) {
				return $value;
			}

			$plugin_file     = plugin_basename( D4H_CALENDAR_PLUGIN_FILE );
			$updater         = new self( $config, $plugin_file, D4H_CALENDAR_VERSION );
			$check           = $updater->check_update();
			if ( ! $check['available'] || empty( $check['package'] ) ) {
				return $value;
			}

			$slug     = 'd4h-calendar';
			$info_url = 'https://github.com/' . ltrim( $repo, '/' ) . '/releases';

			if ( ! is_object( $value ) ) {
				$value = new \stdClass();
				$value->response = array();
			}
			if ( ! isset( $value->response ) || ! is_array( $value->response ) ) {
				$value->response = array();
			}

			$value->response[ $plugin_file ] = (object) array(
				'id'          => 'd4h-calendar/d4h-calendar.php',
				'slug'        => $slug,
				'plugin'      => $plugin_file,
				'new_version' => $check['latest'],
				'url'         => $info_url,
				'package'     => $check['package'],
			);

			return $value;
		} );
	}

	/**
	 * Injects our update when the transient is saved (e.g. after wp_update_plugins).
	 * Ensures our plugin appears even if update_plugins_github.com is not invoked.
	 *
	 * @param object $value Update plugins transient value.
	 * @return object
	 */
	public static function filter_pre_set_update_plugins( $value ) {
		if ( $value === null ) {
			$value = new \stdClass();
		}
		$config = d4h_calendar_get_config();
		$repo   = $config['update_github_repo'] ?? '';
		if ( $repo === '' ) {
			return $value;
		}
		$plugin_file = plugin_basename( D4H_CALENDAR_PLUGIN_FILE );
		$updater     = new self( $config, $plugin_file, D4H_CALENDAR_VERSION );
		$check       = $updater->check_update();
		if ( ! $check['available'] || empty( $check['package'] ) ) {
			return $value;
		}
		if ( ! is_object( $value ) ) {
			$value = new \stdClass();
		}
		if ( ! isset( $value->response ) || ! is_array( $value->response ) ) {
			$value->response = array();
		}
		$value->response[ $plugin_file ] = (object) array(
			'id'          => 'd4h-calendar/d4h-calendar.php',
			'slug'        => 'd4h-calendar',
			'plugin'      => $plugin_file,
			'new_version' => $check['latest'],
			'url'         => 'https://github.com/' . ltrim( $repo, '/' ) . '/releases',
			'package'     => $check['package'],
		);
		return $value;
	}

	/**
	 * Filter for update_plugins_github.com (Update URI hostname). Provides update info
	 * so the plugin appears in Plugins → Updates and Dashboard → Updates.
	 *
	 * @param array|false $update      Update data or false.
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin file path (e.g. d4h-calendar/d4h-calendar.php).
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public static function filter_update_plugins_github( $update, $plugin_data, $plugin_file, $locales ) {
		if ( $plugin_file !== 'd4h-calendar/d4h-calendar.php' ) {
			return $update;
		}
		$config = d4h_calendar_get_config();
		$repo   = $config['update_github_repo'] ?? '';
		if ( $repo === '' ) {
			return $update;
		}
		$updater = new self( $config, $plugin_file, D4H_CALENDAR_VERSION );
		$check   = $updater->check_update();
		if ( ! $check['available'] || empty( $check['package'] ) ) {
			return false;
		}
		return array(
			'slug'    => 'd4h-calendar',
			'plugin'  => $plugin_file,
			'version' => $check['latest'],
			'url'     => 'https://github.com/' . ltrim( $repo, '/' ) . '/releases',
			'package' => $check['package'],
		);
	}

	/**
	 * Handles download of GitHub release assets so WordPress gets a valid ZIP.
	 * GitHub may return HTML instead of the file when the User-Agent is missing or generic.
	 *
	 * @param bool|string $reply    False to use default download, or file path to use instead.
	 * @param string      $package  Package URL.
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @return bool|string
	 */
	public static function filter_pre_download( $reply, $package, $upgrader ) {
		if ( strpos( (string) $package, 'github.com' ) === false || strpos( (string) $package, '/releases/download/' ) === false ) {
			return $reply;
		}

		$response = wp_remote_get( $package, array(
			'timeout'     => 60,
			'redirection' => 5,
			'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ) . '; D4H-Calendar-Plugin',
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new \WP_Error( 'download_failed', sprintf( __( 'Download failed with HTTP %d.', 'd4h-calendar' ), $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new \WP_Error( 'download_failed', __( 'Empty response from GitHub.', 'd4h-calendar' ) );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( $content_type && strpos( strtolower( $content_type ), 'text/html' ) !== false ) {
			return new \WP_Error( 'download_failed', __( 'GitHub returned HTML instead of ZIP. Check release asset URL.', 'd4h-calendar' ) );
		}

		$tmp = wp_tempnam( 'd4h-calendar-' );
		if ( ! $tmp ) {
			return new \WP_Error( 'download_failed', __( 'Could not create temp file.', 'd4h-calendar' ) );
		}
		if ( file_put_contents( $tmp, $body ) === false ) {
			@unlink( $tmp );
			return new \WP_Error( 'download_failed', __( 'Could not write to temp file.', 'd4h-calendar' ) );
		}

		return $tmp;
	}
}
