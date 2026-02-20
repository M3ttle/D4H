<?php
/**
 * Admin: menu, settings page, and (later) API credentials form and AJAX handlers.
 *
 * @package D4H_Calendar
 */

namespace D4H_Calendar;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Registers the admin menu and page.
	 */
	public function add_menu_page(): void {
		$capability = $this->config['admin_capability'] ?? 'manage_options';
		$slug       = $this->config['admin_menu_slug'] ?? 'd4h-calendar';
		$page_title = $this->config['admin_page_title'] ?? 'D4H Calendar';
		$menu_title = $this->config['admin_menu_title'] ?? 'D4H Calendar';

		add_options_page(
			$page_title,
			$menu_title,
			$capability,
			$slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the admin page (minimal in Step 1; credentials and sync controls in Step 3).
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->config['admin_page_title'] ?? 'D4H Calendar' ); ?></h1>
			<p><?php esc_html_e( 'Sync and calendar settings will appear here.', 'd4h-calendar' ); ?></p>
		</div>
		<?php
	}
}
