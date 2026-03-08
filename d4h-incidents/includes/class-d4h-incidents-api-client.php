<?php
/**
 * D4H API Client for Incidents: fetches incidents and activity attendance.
 *
 * @package D4H_Incidents
 */

namespace D4H_Incidents;

defined( 'ABSPATH' ) || exit;

final class API_Client {

	/** @var array<string, mixed> */
	private $config;

	/** @var string */
	private $token;

	/** @var string */
	private $base_url;

	/**
	 * @param array<string, mixed> $config
	 * @param string               $token API bearer token
	 */
	public function __construct( array $config, string $token ) {
		$this->config   = $config;
		$this->token    = $token;
		$this->base_url = rtrim( (string) ( $config['api_base_url'] ?? '' ), '/' );
	}

	/**
	 * Get incidents (paginated). Supports date filters.
	 * Tries /incidents first; falls back to /activities with type=incident if 404.
	 *
	 * @param string               $context    'team' or 'organisation'
	 * @param string               $context_id Context ID
	 * @param array<string, mixed> $args       Optional: starts_after, ends_before, page, size
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_incidents( string $context, string $context_id, array $args = array() ) {
		$path = sprintf( '/v3/%s/%s/incidents', $context, $context_id );
		$result = $this->fetch_paginated( $path, $args );
		if ( is_wp_error( $result ) && $result->get_error_code() === 'd4h_api_error' ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] === 404 ) {
				$path   = sprintf( '/v3/%s/%s/activities', $context, $context_id );
				$args   = array_merge( $args, array( 'type' => 'incident' ) );
				$result = $this->fetch_paginated( $path, $args );
			}
		}
		return $result;
	}

	/**
	 * Get current user/context info.
	 *
	 * @return array{context?: string, id?: string}|null|\WP_Error
	 */
	public function whoami() {
		$path = $this->config['whoami_path'] ?? '/v3/whoami';
		$url  = $this->base_url . $path;
		$response = $this->request( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return is_array( $response ) ? $response : null;
	}

	/**
	 * Get activity attendance for an incident (participants).
	 *
	 * @param string               $context    'team' or 'organisation'
	 * @param string               $context_id Context ID
	 * @param string               $incident_id Incident ID
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_incident_attendance( string $context, string $context_id, string $incident_id ) {
		$path = sprintf( '/v3/%s/%s/incidents/%s/activity-attendance', $context, $context_id, $incident_id );
		return $this->fetch_paginated( $path, array() );
	}

	/**
	 * Fetch paginated endpoint, merging all results.
	 *
	 * @param string               $path
	 * @param array<string, mixed> $args
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function fetch_paginated( string $path, array $args = array() ) {
		$page   = 0;
		$size   = isset( $args['size'] ) ? (int) $args['size'] : 100;
		$merged = array();

		do {
			$query = array_merge( $args, array(
				'page' => $page,
				'size' => $size,
			) );
			$url = $this->base_url . $path . '?' . http_build_query( array_filter( $query ) );

			$response = $this->request( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$results = isset( $response['results'] ) && is_array( $response['results'] )
				? $response['results']
				: array();
			$merged = array_merge( $merged, $results );

			$total    = isset( $response['total'] ) ? (int) $response['total'] : 0;
			$page++;
			$has_more = count( $results ) === $size && ( count( $merged ) < $total || $total === 0 );

		} while ( $has_more );

		return $merged;
	}

	/**
	 * Perform GET request with Bearer token.
	 *
	 * @param string $url
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $url ) {
		$start = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
				'timeout' => 60,
			)
		);
		$duration = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			if ( class_exists( 'D4H_Core\Logger' ) ) {
				$parsed = wp_parse_url( $url );
				$path   = isset( $parsed['path'] ) ? $parsed['path'] : $url;
				\D4H_Core\Logger::log_api( $path, 0, $duration, 'incidents' );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( class_exists( 'D4H_Core\Logger' ) ) {
			$parsed = wp_parse_url( $url );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : $url;
			\D4H_Core\Logger::log_api( $path, (int) $code, $duration, 'incidents' );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'd4h_api_error',
				sprintf( 'API returned %d: %s', $code, substr( $body, 0, 200 ) ),
				array( 'status' => $code )
			);
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : array();
	}
}
