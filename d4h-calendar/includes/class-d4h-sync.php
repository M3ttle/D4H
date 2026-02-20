<?php
/**
 * Sync: orchestration – run full sync from D4H API to local DB.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Sync {

	/** @var array<string, mixed> */
	private $config;

	/** @var API_Client */
	private $api;

	/** @var Repository */
	private $repository;

	/**
	 * @param array<string, mixed> $config
	 * @param API_Client           $api
	 * @param Repository           $repository
	 */
	public function __construct( array $config, API_Client $api, Repository $repository ) {
		$this->config    = $config;
		$this->api       = $api;
		$this->repository = $repository;
	}

	/**
	 * Run full sync: fetch events and exercises, store, update last_updated option.
	 *
	 * @return true|\WP_Error
	 */
	public function run_full_sync() {
		$context   = get_option( $this->config['option_context'] ?? 'd4h_calendar_api_org', '' );
		$context_id = get_option( $this->config['option_context_id'] ?? 'd4h_calendar_api_org_id', '' );

		if ( empty( $context ) || empty( $context_id ) ) {
			$whoami = $this->api->whoami();
			if ( is_wp_error( $whoami ) ) {
				return $whoami;
			}
			if ( is_array( $whoami ) ) {
				$context    = $context ?: ( $whoami['context'] ?? $whoami['contextType'] ?? '' );
				$context_id = $context_id ?: ( $whoami['id'] ?? $whoami['contextId'] ?? '' );
			}
		}

		if ( empty( $context ) || empty( $context_id ) ) {
			return new \WP_Error( 'd4h_no_context', __( 'Could not determine API context. Set context and context ID in D4H Calendar settings.', 'd4h-calendar' ) );
		}

		$events = $this->api->get_events( $context, $context_id );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$exercises = $this->api->get_exercises( $context, $context_id );
		if ( is_wp_error( $exercises ) ) {
			return $exercises;
		}

		$items = $this->normalize_activities( $events, 'event' );
		$items = array_merge( $items, $this->normalize_activities( $exercises, 'exercise' ) );

		$result = $this->repository->replace_activities( $items );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( $this->config['option_last_updated'] ?? 'd4h_calendar_last_updated', time(), false );
		return true;
	}

	/**
	 * Normalize API items to storage format.
	 *
	 * @param array<int, array<string, mixed>> $raw
	 * @param string                           $resource_type 'event' or 'exercise'
	 * @return array<int, array{id: string, resource_type: string, starts_at: string, ends_at?: string|null, payload: array}>
	 */
	private function normalize_activities( array $raw, string $resource_type ): array {
		$items = array();
		foreach ( $raw as $raw_item ) {
			$id    = isset( $raw_item['id'] ) ? (string) $raw_item['id'] : '';
			$start = isset( $raw_item['startsAt'] ) ? $raw_item['startsAt'] : ( $raw_item['starts_at'] ?? '' );
			$end   = isset( $raw_item['endsAt'] ) ? $raw_item['endsAt'] : ( $raw_item['ends_at'] ?? null );
			if ( $id === '' || $start === '' ) {
				continue;
			}
			$items[] = array(
				'id'            => $id,
				'resource_type' => $resource_type,
				'starts_at'     => is_numeric( $start ) ? gmdate( 'Y-m-d H:i:s', $start ) : $start,
				'ends_at'       => $end !== null && $end !== ''
					? ( is_numeric( $end ) ? gmdate( 'Y-m-d H:i:s', (int) $end ) : $end )
					: null,
				'payload'       => $raw_item,
			);
		}
		return $items;
	}
}
