<?php
/**
 * D4H API Client: HTTP calls to D4H Team Manager API. No DB writes.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

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
	 * @param string               $token API bearer token (from options).
	 */
	public function __construct( array $config, string $token ) {
		$this->config  = $config;
		$this->token   = $token;
		$this->base_url = rtrim( (string) ( $config['api_base_url'] ?? '' ), '/' );
	}

	/**
	 * Get current user/context info. Used when context not stored.
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
	 * Get events (paginated). Loops until no more pages.
	 *
	 * @param string               $context    e.g. 'team' or 'organisation'.
	 * @param string               $context_id Context ID.
	 * @param array<string, mixed> $args       Optional: starts_after, ends_before, page, size.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_events( string $context, string $context_id, array $args = array() ) {
		$path = sprintf( '/v3/%s/%s/events', $context, $context_id );
		return $this->fetch_paginated( $path, $args );
	}

	/**
	 * Get exercises (paginated). Loops until no more pages.
	 *
	 * @param string               $context    e.g. 'team' or 'organisation'.
	 * @param string               $context_id Context ID.
	 * @param array<string, mixed> $args       Optional: starts_after, ends_before, page, size.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_exercises( string $context, string $context_id, array $args = array() ) {
		$path = sprintf( '/v3/%s/%s/exercises', $context, $context_id );
		return $this->fetch_paginated( $path, $args );
	}

	/**
	 * Fetch a paginated endpoint, merging all results.
	 *
	 * @param string               $path
	 * @param array<string, mixed> $args
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function fetch_paginated( string $path, array $args = array() ) {
		$page    = 0;
		$size    = isset( $args['size'] ) ? (int) $args['size'] : 100;
		$merged  = array();

		do {
			$query = array_merge( $args, array(
				'page' => $page,
				'size' => $size,
			) );
			$url   = $this->base_url . $path . '?' . http_build_query( array_filter( $query ) );

			$response = $this->request( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$results = isset( $response['results'] ) && is_array( $response['results'] )
				? $response['results']
				: array();
			$merged  = array_merge( $merged, $results );

			$total = isset( $response['total'] ) ? (int) $response['total'] : 0;
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
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'd4h_api_error',
				sprintf( 'API returned %d: %s', $code, $body ),
				array( 'status' => $code )
			);
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : array();
	}
}
