<?php
/**
 * Plugin self-update from GitHub releases.
 *
 * @package D4H_Incidents
 */

namespace D4H_Incidents;

defined( 'ABSPATH' ) || exit;

final class Plugin_Updater {

	/** @var array<string, mixed> */
	private $config;

	/** @var string */
	private $plugin_file;

	/** @var string */
	private $current_version;

	public function __construct( array $config, string $plugin_file, string $current_version ) {
		$this->config          = $config;
		$this->plugin_file     = $plugin_file;
		$this->current_version = $current_version;
	}

	public function fetch_latest_release(): ?array {
		$repo = $this->config['update_github_repo'] ?? '';
		if ( $repo === '' ) {
			return null;
		}
		$repo  = ltrim( $repo, '/' );
		$url   = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$token = function_exists( 'd4h_core_get_github_token' ) ? d4h_core_get_github_token() : ( defined( 'D4H_INCIDENTS_GITHUB_TOKEN' ) ? D4H_INCIDENTS_GITHUB_TOKEN : '' );
		$token = is_string( $token ) ? trim( $token ) : '';

		$request_args = array(
			'timeout'    => 15,
			'user-agent' => 'D4H-Incidents-WordPress-Plugin/' . $this->current_version,
		);
		if ( $token !== '' ) {
			$request_args['headers'] = array( 'Authorization' => 'token ' . $token );
		}

		$response = wp_remote_get( $url, $request_args );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'v' ) : '';
		$package = $this->get_package_url( $data );
		$info_url = isset( $data['html_url'] ) ? (string) $data['html_url'] : '';

		if ( $version === '' || $package === '' ) {
			return null;
		}

		return array( 'version' => $version, 'package' => $package, 'url' => $info_url );
	}

	private function get_package_url( array $release_data ): string {
		$assets = isset( $release_data['assets'] ) && is_array( $release_data['assets'] ) ? $release_data['assets'] : array();
		$slug   = 'd4h-incidents';
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			if ( $url !== '' && ( $name === $slug . '.zip' || strpos( strtolower( $name ), 'incidents' ) !== false ) ) {
				return $url;
			}
		}
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}
		return '';
	}

	public static function register_update_filter( array $config ): void {
		add_filter( 'site_transient_update_plugins', function ( $value ) use ( $config ) {
			$repo = $config['update_github_repo'] ?? '';
			if ( $repo === '' ) {
				return $value;
			}
			$plugin_file = plugin_basename( D4H_INCIDENTS_PLUGIN_FILE );
			$updater     = new self( $config, $plugin_file, D4H_INCIDENTS_VERSION );
			$latest      = $updater->fetch_latest_release();
			if ( $latest === null || version_compare( D4H_INCIDENTS_VERSION, $latest['version'], '>=' ) ) {
				return $value;
			}
			if ( ! is_object( $value ) ) {
				$value = new \stdClass();
			}
			if ( ! isset( $value->response ) ) {
				$value->response = array();
			}
			$value->response[ $plugin_file ] = (object) array(
				'id'          => 'd4h-incidents/d4h-incidents.php',
				'slug'        => 'd4h-incidents',
				'plugin'      => $plugin_file,
				'new_version' => $latest['version'],
				'url'         => 'https://github.com/' . ltrim( $repo, '/' ) . '/releases',
				'package'     => $latest['package'],
			);
			return $value;
		}, 10, 1 );
	}
}
