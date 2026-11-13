<?php
/**
 * The "AMC Copilot" top-level admin page (console mount point).
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Admin;

use AMC\Core\Capabilities;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAdminPage;

/**
 * Class ConsolePage
 *
 * Owner: lane C. Wave 0 registers the menu (visible only with amc_manage)
 * and prints the React mount point `#amc-console-root`. Lane C:
 *   1. builds the app from src/js/console/index.js (built to assets/build/js/console.js),
 *   2. enqueues it ONLY on this screen (admin_enqueue_scripts + $hook_suffix check)
 *      with Assets::register_script() and wp_enqueue_style( 'wp-components' ),
 *   3. routes the 12 screens client-side (e.g. ?page=amc-copilot&screen=updates).
 *
 * @since 0.1.0
 */
final class ConsolePage extends AbstractAdminPage {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'amc-copilot';

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_page_title(): string {
		return __( 'AMC Copilot', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_menu_title(): string {
		return __( 'AMC Copilot', 'rtcamp-amc-copilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_capability(): string {
		return Capabilities::MANAGE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_icon(): string {
		return 'dashicons-shield';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $this->get_page_title() ); ?></h1>
			<div id="amc-console-root">
				<p><?php esc_html_e( 'The console has not been built yet.', 'rtcamp-amc-copilot' ); ?></p>
			</div>
		</div>
		<?php
	}
}
