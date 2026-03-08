<?php
/**
 * Loader: wires plugin components.
 *
 * @package D4H_Incidents
 */

namespace D4H_Incidents;

defined( 'ABSPATH' ) || exit;

final class Loader {

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function init(): void {
		$admin = new Admin( $this->config );
		$admin->register_hooks();
		Plugin_Updater::register_update_filter( $this->config );
	}
}
