<?php
/**
 * D4H API client: create exercises and attach tags.
 *
 * @package D4H_Create_Activity
 */

namespace D4H_Create_Activity;

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
	 * Create one exercise or event (Full-Team attendance).
	 *
	 * @param string               $context    'team' or 'organisation'
	 * @param string               $context_id Context ID
	 * @param array<string, mixed> $activity   Validated activity fields
	 * @return array<string, mixed>|\WP_Error Created activity payload
	 */
	public function create_activity( string $context, string $context_id, array $activity ) {
		$kind = ( ( $activity['activity_type'] ?? '' ) === 'event' ) ? 'events' : 'exercises';
		$path = sprintf( '/v3/%s/%s/%s', $context, $context_id, $kind );
		$body = array(
			'referenceDescription' => (string) ( $activity['title'] ?? '' ),
			'startsAt'             => (string) ( $activity['starts_at_iso'] ?? '' ),
			'endsAt'               => (string) ( $activity['ends_at_iso'] ?? '' ),
			'plan'                 => (string) ( $activity['plan_html'] ?? '' ),
			'description'          => (string) ( $activity['description_html'] ?? '' ),
			'fullTeam'             => true,
		);

		return $this->request( 'POST', $path, $body );
	}

	/**
	 * Set tags on an exercise or event. Endpoint is team-scoped.
	 *
	 * @param string     $team_id       Team ID
	 * @param int|string $activity_id   Created activity ID
	 * @param array<int> $tag_ids       Whitelisted tag IDs
	 * @param string     $activity_type 'exercise' or 'event'
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_activity_tags( string $team_id, $activity_id, array $tag_ids, string $activity_type ) {
		$kind = ( $activity_type === 'event' ) ? 'events' : 'exercises';
		$path = sprintf( '/v3/team/%s/%s/%s/tags', $team_id, $kind, $activity_id );
		$body = array(
			'tagIds' => array_values( array_map( 'intval', $tag_ids ) ),
		);
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * Perform an HTTP request with Bearer token and Core logging.
	 *
	 * @param string                    $method GET|POST|...
	 * @param string                    $path   API path starting with /v3
	 * @param array<string, mixed>|null $body   JSON body for POST
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$url     = $this->base_url . $path;
		$headers = array(
			'Authorization' => 'Bearer ' . $this->token,
			'Accept'        => 'application/json',
		);
		$args    = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 60,
		);
		if ( $body !== null ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$start    = microtime( true );
		$response = wp_remote_request( $url, $args );
		$duration = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			if ( class_exists( '\D4H_Core\Logger' ) ) {
				\D4H_Core\Logger::log_api( $path, 0, $duration, 'create-activity' );
			}
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( class_exists( '\D4H_Core\Logger' ) ) {
			\D4H_Core\Logger::log_api( $path, $code, $duration, 'create-activity' );
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = $this->extract_error_message( $raw, $code );
			return new \WP_Error(
				'd4h_api_error',
				$message,
				array( 'status' => $code, 'body' => $raw )
			);
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a short error message from an API error body.
	 */
	private function extract_error_message( string $raw, int $code ): string {
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'error', 'detail', 'title' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					return sprintf( 'API %d: %s', $code, $data[ $key ] );
				}
			}
			if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$parts = array();
				foreach ( $data['errors'] as $item ) {
					if ( is_string( $item ) ) {
						$parts[] = $item;
					} elseif ( is_array( $item ) && isset( $item['message'] ) ) {
						$parts[] = (string) $item['message'];
					}
				}
				if ( ! empty( $parts ) ) {
					return sprintf( 'API %d: %s', $code, implode( '; ', $parts ) );
				}
			}
		}
		$snippet = trim( wp_strip_all_tags( $raw ) );
		if ( strlen( $snippet ) > 200 ) {
			$snippet = substr( $snippet, 0, 200 ) . '…';
		}
		return $snippet !== ''
			? sprintf( 'API %d: %s', $code, $snippet )
			: sprintf( 'API returned %d.', $code );
	}
}
