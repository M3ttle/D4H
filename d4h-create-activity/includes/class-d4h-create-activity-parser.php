<?php
/**
 * Parses pasted spreadsheet rows and validates activity fields.
 *
 * @package D4H_Create_Activity
 */

namespace D4H_Create_Activity;

defined( 'ABSPATH' ) || exit;

final class Parser {

	/** @var array<string, mixed> */
	private $config;

	/** @var array<int, string> Tag id => name from Core */
	private $tags_map;

	/**
	 * @param array<string, mixed> $config
	 * @param array<int|string, string> $tags_map
	 */
	public function __construct( array $config, array $tags_map ) {
		$this->config = $config;
		$normalized   = array();
		foreach ( $tags_map as $id => $name ) {
			$tag_id = (int) $id;
			if ( $tag_id > 0 && is_string( $name ) && $name !== '' ) {
				$normalized[ $tag_id ] = $name;
			}
		}
		$this->tags_map = $normalized;
	}

	/**
	 * Parse pasted text into validated activity rows.
	 *
	 * @param string $raw_paste Tab- or semicolon-separated rows.
	 * @return array{rows: array<int, array<string, mixed>>, all_valid: bool, error?: string}
	 */
	public function parse_paste( string $raw_paste ): array {
		$raw_paste = str_replace( array( "\r\n", "\r" ), "\n", $raw_paste );
		$lines     = explode( "\n", $raw_paste );
		$max_rows  = (int) ( $this->config['max_rows'] ?? 50 );

		$rows = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$cells = $this->split_row( $line );
			if ( $this->is_header_row( $cells ) ) {
				continue;
			}
			$rows[] = $this->build_row( $cells, count( $rows ) );
			if ( count( $rows ) > $max_rows ) {
				return array(
					'rows'      => array(),
					'all_valid' => false,
					'error'     => sprintf(
						/* translators: %d: max rows allowed */
						__( 'Too many rows. Maximum is %d activities per batch.', 'd4h-create-activity' ),
						$max_rows
					),
				);
			}
		}

		if ( empty( $rows ) ) {
			return array(
				'rows'      => array(),
				'all_valid' => false,
				'error'     => __( 'No activity rows found. Paste one row per activity.', 'd4h-create-activity' ),
			);
		}

		$all_valid = true;
		foreach ( $rows as $row ) {
			if ( empty( $row['valid'] ) ) {
				$all_valid = false;
				break;
			}
		}

		return array(
			'rows'      => $rows,
			'all_valid' => $all_valid,
		);
	}

	/**
	 * Re-validate rows from the review table before sending to D4H.
	 *
	 * @param array<int, array<string, mixed>> $input_rows
	 * @return array{rows: array<int, array<string, mixed>>, all_valid: bool, error?: string}
	 */
	public function validate_rows( array $input_rows ): array {
		$max_rows = (int) ( $this->config['max_rows'] ?? 50 );
		if ( count( $input_rows ) > $max_rows ) {
			return array(
				'rows'      => array(),
				'all_valid' => false,
				'error'     => sprintf(
					__( 'Too many rows. Maximum is %d activities per batch.', 'd4h-create-activity' ),
					$max_rows
				),
			);
		}

		$rows = array();
		foreach ( $input_rows as $index => $input ) {
			if ( ! is_array( $input ) ) {
				continue;
			}
			$activity_type = isset( $input['activity_type'] ) ? (string) $input['activity_type'] : '';
			$title         = isset( $input['title'] ) ? (string) $input['title'] : '';
			$starts_raw    = isset( $input['starts_at'] ) ? (string) $input['starts_at'] : '';
			$ends_raw      = isset( $input['ends_at'] ) ? (string) $input['ends_at'] : '';
			$plan          = isset( $input['plan'] ) ? (string) $input['plan'] : '';
			$description   = isset( $input['description'] ) ? (string) $input['description'] : '';
			$tag_ids       = isset( $input['tag_ids'] ) && is_array( $input['tag_ids'] ) ? $input['tag_ids'] : array();

			$rows[] = $this->validate_fields(
				(int) $index,
				$activity_type,
				$title,
				$starts_raw,
				$ends_raw,
				$plan,
				$description,
				$tag_ids,
				array()
			);
		}

		if ( empty( $rows ) ) {
			return array(
				'rows'      => array(),
				'all_valid' => false,
				'error'     => __( 'No activities to send.', 'd4h-create-activity' ),
			);
		}

		$all_valid = true;
		foreach ( $rows as $row ) {
			if ( empty( $row['valid'] ) ) {
				$all_valid = false;
				break;
			}
		}

		return array(
			'rows'      => $rows,
			'all_valid' => $all_valid,
		);
	}

	/**
	 * Split one pasted line into cells (tabs preferred, then semicolon).
	 *
	 * @return array<int, string>
	 */
	private function split_row( string $line ): array {
		if ( strpos( $line, "\t" ) !== false ) {
			$parts = explode( "\t", $line );
		} else {
			$parts = str_getcsv( $line, ';' );
		}
		$cells = array();
		foreach ( $parts as $part ) {
			$cells[] = trim( (string) $part );
		}
		return $cells;
	}

	/**
	 * Detect a header row so it is skipped.
	 *
	 * @param array<int, string> $cells
	 */
	private function is_header_row( array $cells ): bool {
		if ( empty( $cells[0] ) ) {
			return false;
		}
		$first = strtolower( $cells[0] );
		return in_array(
			$first,
			array( 'type', 'activity type', 'activity', 'kind', 'exercise/event', 'exercise or event', 'name', 'title', 'reference', 'ref' ),
			true
		);
	}

	/**
	 * Build and validate one activity from spreadsheet cells.
	 *
	 * Columns: Type | Name | Start | End | Pre-plan | Description | Tags
	 *
	 * @param array<int, string> $cells
	 * @param int                $index
	 * @return array<string, mixed>
	 */
	private function build_row( array $cells, int $index ): array {
		$activity_type = $cells[0] ?? '';
		$title         = $cells[1] ?? '';
		$starts_raw    = $cells[2] ?? '';
		$ends_raw      = $cells[3] ?? '';
		$plan          = $cells[4] ?? '';
		$description   = $cells[5] ?? '';
		$tag_cells     = array_slice( $cells, 6 );
		$tag_names     = $this->split_tag_names( implode( ',', $tag_cells ) );

		return $this->validate_fields( $index, $activity_type, $title, $starts_raw, $ends_raw, $plan, $description, array(), $tag_names );
	}

	/**
	 * Map pasted type text to exercise or event.
	 */
	private function normalize_activity_type( string $raw ): string {
		$key = mb_strtolower( trim( $raw ) );
		if ( in_array( $key, array( 'exercise', 'exercises', 'æfing', 'æfingar' ), true ) ) {
			return 'exercise';
		}
		if ( in_array( $key, array( 'event', 'events', 'viðburður', 'viðburðir' ), true ) ) {
			return 'event';
		}
		return '';
	}

	/**
	 * Split tag names from one cell or several extra columns (comma, semicolon, or pipe).
	 *
	 * @return array<int, string>
	 */
	private function split_tag_names( string $tags_raw ): array {
		if ( trim( $tags_raw ) === '' ) {
			return array();
		}
		$parts = preg_split( '/\s*[,;|]\s*/', $tags_raw ) ?: array();
		$names = array();
		foreach ( $parts as $part ) {
			$name = trim( (string) $part );
			if ( $name !== '' ) {
				$names[] = $name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Validate one activity's fields and resolve tags.
	 *
	 * @param int                  $index
	 * @param string               $activity_type_raw Exercise or Event
	 * @param string               $title
	 * @param string               $starts_raw
	 * @param string               $ends_raw
	 * @param string               $plan
	 * @param string               $description
	 * @param array<int, mixed>    $tag_ids_input Explicit tag IDs (from review table).
	 * @param array<int, string>   $tag_names     Tag names from paste (when IDs not provided).
	 * @return array<string, mixed>
	 */
	private function validate_fields(
		int $index,
		string $activity_type_raw,
		string $title,
		string $starts_raw,
		string $ends_raw,
		string $plan,
		string $description,
		array $tag_ids_input,
		array $tag_names
	): array {
		$errors          = array();
		$title_max       = (int) ( $this->config['title_max_length'] ?? 100 );
		$text_max        = (int) ( $this->config['text_max_length'] ?? 65535 );
		$activity_type   = $this->normalize_activity_type( $activity_type_raw );
		$title           = trim( $title );
		$plan            = trim( $plan );
		$description     = trim( $description );
		$starts_raw      = trim( $starts_raw );
		$ends_raw        = trim( $ends_raw );

		if ( $activity_type === '' ) {
			$errors[] = __( 'Type must be Exercise or Event.', 'd4h-create-activity' );
		}

		if ( $title === '' ) {
			$errors[] = __( 'Name is required.', 'd4h-create-activity' );
		} elseif ( mb_strlen( $title ) > $title_max ) {
			$errors[] = sprintf(
				/* translators: %d: max title length */
				__( 'Name must be at most %d characters.', 'd4h-create-activity' ),
				$title_max
			);
		}

		$starts_at = $this->parse_datetime( $starts_raw );
		$ends_at   = $this->parse_datetime( $ends_raw );

		if ( $starts_raw === '' || $starts_at === null ) {
			$errors[] = __( 'Start date/time is missing or invalid.', 'd4h-create-activity' );
		}
		if ( $ends_raw === '' || $ends_at === null ) {
			$errors[] = __( 'End date/time is missing or invalid.', 'd4h-create-activity' );
		}
		if ( $starts_at !== null && $ends_at !== null && strcmp( $ends_at['iso'], $starts_at['iso'] ) <= 0 ) {
			$errors[] = __( 'End must be after start.', 'd4h-create-activity' );
		}

		$plan_html        = $this->sanitize_rich_text( $plan );
		$description_html = $this->sanitize_rich_text( $description );

		if ( mb_strlen( $plan_html ) > $text_max ) {
			$errors[] = __( 'Pre-plan text is too long.', 'd4h-create-activity' );
		}
		if ( mb_strlen( $description_html ) > $text_max ) {
			$errors[] = __( 'Description text is too long.', 'd4h-create-activity' );
		}

		$matched_tag_ids   = array();
		$matched_tag_names = array();
		$unmatched_tags    = array();
		$tag_errors        = array();

		if ( ! empty( $tag_ids_input ) ) {
			$allowed_ids = array_map( 'intval', array_keys( $this->tags_map ) );
			foreach ( $tag_ids_input as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0 && in_array( $id, $allowed_ids, true ) ) {
					$matched_tag_ids[]   = $id;
					$matched_tag_names[] = $this->tags_map[ $id ];
				} elseif ( $id > 0 ) {
					$unmatched_tags[] = (string) $id;
					$tag_errors[]     = sprintf(
						/* translators: %d: tag id */
						__( 'Unknown tag ID: %d.', 'd4h-create-activity' ),
						$id
					);
				}
			}
		} else {
			$name_to_id = array();
			foreach ( $this->tags_map as $id => $name ) {
				$name_to_id[ mb_strtolower( trim( (string) $name ) ) ] = (int) $id;
			}
			foreach ( $tag_names as $name ) {
				$key = mb_strtolower( $name );
				if ( isset( $name_to_id[ $key ] ) ) {
					$id                  = $name_to_id[ $key ];
					$matched_tag_ids[]   = $id;
					$matched_tag_names[] = $this->tags_map[ $id ];
				} else {
					$unmatched_tags[] = $name;
					$tag_errors[]     = sprintf(
						/* translators: %s: tag name */
						__( 'Unknown tag: %s.', 'd4h-create-activity' ),
						$name
					);
				}
			}
		}

		$matched_tag_ids   = array_values( array_unique( $matched_tag_ids ) );
		$matched_tag_names = array_values( array_unique( $matched_tag_names ) );
		$all_errors        = array_merge( $errors, $tag_errors );

		$attendance_label = $activity_type === 'event'
			? __( 'Full-Team Event', 'd4h-create-activity' )
			: __( 'Full-Team Exercise', 'd4h-create-activity' );

		return array(
			'index'            => $index,
			'activity_type'    => $activity_type,
			'activity_label'   => $activity_type === 'event'
				? __( 'Event', 'd4h-create-activity' )
				: __( 'Exercise', 'd4h-create-activity' ),
			'title'            => $title,
			'starts_at'        => $starts_at !== null ? $starts_at['display'] : $starts_raw,
			'ends_at'          => $ends_at !== null ? $ends_at['display'] : $ends_raw,
			'starts_at_iso'    => $starts_at !== null ? $starts_at['iso'] : '',
			'ends_at_iso'      => $ends_at !== null ? $ends_at['iso'] : '',
			'plan'             => $plan,
			'description'      => $description,
			'plan_html'        => $plan_html,
			'description_html' => $description_html,
			'tag_ids'          => $matched_tag_ids,
			'tag_names'        => $matched_tag_names,
			'unmatched_tags'   => $unmatched_tags,
			'attendance_type'  => $attendance_label,
			'valid'            => empty( $all_errors ),
			'errors'           => $all_errors,
			'field_errors'     => $errors,
			'tag_errors'       => $tag_errors,
		);
	}

	/**
	 * Parse a date/time string in WordPress timezone; return display + UTC ISO.
	 *
	 * @return array{display: string, iso: string}|null
	 */
	private function parse_datetime( string $raw ): ?array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return null;
		}

		$timezone = wp_timezone();
		$formats  = array(
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d\TH:i:s',
			'Y-m-d\TH:i',
			'd.m.Y H:i:s',
			'd.m.Y H:i',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'm/d/Y H:i:s',
			'm/d/Y H:i',
		);

		$date = null;
		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, $raw, $timezone );
			if ( $parsed instanceof \DateTimeImmutable ) {
				$errors = \DateTimeImmutable::getLastErrors();
				if ( is_array( $errors ) && ( ( $errors['warning_count'] ?? 0 ) > 0 || ( $errors['error_count'] ?? 0 ) > 0 ) ) {
					continue;
				}
				$date = $parsed;
				break;
			}
		}

		if ( $date === null ) {
			try {
				$date = new \DateTimeImmutable( $raw, $timezone );
			} catch ( \Exception $e ) {
				return null;
			}
		}

		$utc = $date->setTimezone( new \DateTimeZone( 'UTC' ) );

		return array(
			'display' => $date->format( 'Y-m-d H:i' ),
			'iso'     => $utc->format( 'Y-m-d\TH:i:s.000\Z' ),
		);
	}

	/**
	 * Prepare plan/description for D4H. Keep safe HTML; convert plain text line breaks.
	 */
	private function sanitize_rich_text( string $text ): string {
		if ( $text === '' ) {
			return '';
		}
		if ( ! preg_match( '/<[a-z][^>]*>/i', $text ) ) {
			return nl2br( esc_html( $text ), false );
		}
		return wp_kses_post( $text );
	}
}
