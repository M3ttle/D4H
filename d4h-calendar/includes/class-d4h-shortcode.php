<?php
/**
 * Shortcode: [d4h_calendar] – public calendar with FullCalendar.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	/** @var array<string, mixed> */
	private $config;

	/** @var bool */
	private static $assets_enqueued = false;

	/** FullCalendar CDN version */
	private const FC_VERSION = '6.1.20';

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function register_hooks(): void {
		$name = $this->config['shortcode_name'] ?? 'd4h_calendar';
		add_shortcode( $name, array( $this, 'render' ) );
	}

	private function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$base  = rest_url( $this->config['rest_namespace'] ?? 'd4h-calendar/v1' );
		$route = $this->config['rest_activities_route'] ?? 'activities';
		$url   = $base . '/' . $route;

		$fc_url = 'https://cdn.jsdelivr.net/npm/fullcalendar@' . self::FC_VERSION . '/index.global.min.js';

		wp_enqueue_script(
			'fullcalendar',
			$fc_url,
			array(),
			self::FC_VERSION,
			true
		);

		$default_view = $this->config['calendar_default_view'] ?? 'dayGridMonth';
		$init_url     = plugin_dir_url( D4H_CALENDAR_PLUGIN_FILE ) . 'assets/calendar.js';

		wp_enqueue_script(
			'd4h-calendar-front',
			$init_url,
			array( 'fullcalendar' ),
			D4H_CALENDAR_VERSION,
			true
		);
		wp_localize_script( 'd4h-calendar-front', 'd4hCalendar', array(
			'restUrl'     => $url,
			'defaultView' => $default_view,
		) );
	}

	/**
	 * Shortcode callback: render wrapper div and enqueue assets.
	 *
	 * @param array<string, string> $atts
	 * @return string
	 */
	public function render( array $atts = array() ): string {
		$atts = shortcode_atts( array(), $atts, $this->config['shortcode_name'] ?? 'd4h_calendar' );
		$this->enqueue_assets();
		return '<div class="d4h-calendar"></div>';
	}
}
