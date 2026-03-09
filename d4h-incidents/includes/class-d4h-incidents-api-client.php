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
	 * Get incidents (paginated). Uses v3/team/{id}/incidents with date filters.
	 *
	 * @param string               $context    'team' or 'organisation'
	 * @param string               $context_id Context ID (e.g. 434)
	 * @param array<string, mixed> $args       Optional: after, before, page, size
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_incidents( string $context, string $context_id, array $args = array() ) {
		$path = sprintf( '/v3/%s/%s/incidents', $context, $context_id );
		$query = array();
		if ( ! empty( $args['after'] ) || ! empty( $args['starts_after'] ) ) {
			$query['after'] = $args['after'] ?? $args['starts_after'];
		}
		if ( ! empty( $args['before'] ) ) {
			$query['before'] = $args['before'];
		}
		$query['page'] = $args['page'] ?? 0;
		$query['size'] = $args['size'] ?? 100;
		return $this->fetch_paginated( $path, array_merge( $args, $query ) );
	}

	/**
	 * Get tags for the context. Returns array of tag objects (id, name, etc.).
	 * Reuses same endpoint as D4H Calendar for consistent tag list.
	 *
	 * @param string               $context    'team' or 'organisation'
	 * @param string               $context_id Context ID
	 * @param array<string, mixed> $args       Optional: page, size
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_tags( string $context, string $context_id, array $args = array() ) {
		$path = sprintf( '/v3/%s/%s/tags', $context, $context_id );
		$tags = $this->fetch_paginated( $path, $args );
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}
		if ( ! empty( $tags ) ) {
			return $tags;
		}
		$url = $this->base_url . $path . '?' . http_build_query( array_filter( array_merge( $args, array( 'page' => 0, 'size' => 500 ) ) ) );
		$response = $this->request( $url );
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return is_wp_error( $response ) ? $response : array();
		}
		if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
			return $response['results'];
		}
		if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
			return $response['data'];
		}
		if ( isset( $response['content'] ) && is_array( $response['content'] ) ) {
			return $response['content'];
		}
		if ( isset( $response['tags'] ) && is_array( $response['tags'] ) ) {
			return $response['tags'];
		}
		return array();
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
	 * Get team attendance for incidents in a date range.
	 * Uses /v3/{context}/{context_id}/attendance with activity_resource_type=Incident.
	 *
	 * @param string $context    'team' or 'organisation'
	 * @param string $context_id Context ID (e.g. 434)
	 * @param string $ends_after ISO8601 datetime (e.g. 2026-02-21T00:01:00.000Z)
	 * @param string $ends_before ISO8601 datetime (e.g. 2026-03-09T00:01:00.000Z)
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_team_attendance( string $context, string $context_id, string $ends_after, string $ends_before ) {
		$path  = sprintf( '/v3/%s/%s/attendance', $context, $context_id );
		$query = array(
			'order'                  => 'desc',
			'status'                 => 'ATTENDING',
			'activity_resource_type' => 'Incident',
			'ends_after'             => $ends_after,
			'ends_before'            => $ends_before,
			'size'                   => 250,
		);
		return $this->fetch_paginated( $path, $query );
	}

	/**
	 * Get a single member by ID.
	 * GET /v3/{context}/{context_id}/members/{member_id}
	 *
	 * @param string $context    'team' or 'organisation'
	 * @param string $context_id Context ID (e.g. 434)
	 * @param int    $member_id  Member ID
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_member( string $context, string $context_id, int $member_id ) {
		$path  = sprintf( '/v3/%s/%s/members/%d', $context, $context_id, $member_id );
		$url   = $this->base_url . $path;
		return $this->request( $url );
	}

	/**
	 * Get activity attendance for an incident (participants).
	 * Uses the path from config 'attendance_path' if set, otherwise the default.
	 * Set config 'attendance_path' to override the path if D4H uses a different endpoint.
	 *
	 * @param string               $context     'team' or 'organisation'
	 * @param string               $context_id  Context ID
	 * @param string               $resource_id Incident or activity ID
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function get_incident_attendance( string $context, string $context_id, string $resource_id ) {
		$template = $this->config['attendance_path'] ?? null;
		if ( is_string( $template ) && $template !== '' ) {
			$path = str_replace(
				array( '{context}', '{context_id}', '{id}' ),
				array( $context, $context_id, $resource_id ),
				$template
			);
		} else {
			$path = sprintf( '/v3/%s/%s/incidents/%s/activity-attendance', $context, $context_id, $resource_id );
		}
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

			$total    = isset( $response['totalSize'] ) ? (int) $response['totalSize'] : ( isset( $response['total'] ) ? (int) $response['total'] : 0 );
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
