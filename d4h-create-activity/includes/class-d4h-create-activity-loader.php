<?php
/**
 * Loader: wires plugin components.
 *
 * @package D4H_Create_Activity
 */

namespace D4H_Create_Activity;

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

	/**
	 * Register admin and plugin updater.
	 */
	public function init(): void {
		$admin = new Admin( $this->config );
		$admin->register_hooks();
		Plugin_Updater::register_update_filter( $this->config );
	}
}
