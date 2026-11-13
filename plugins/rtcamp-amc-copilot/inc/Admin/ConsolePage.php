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
 * Owner: lane C. Registers the top-level menu (capability `amc_manage`, so it
 * is visible only to engineers) and prints the React mount point
 * `#amc-console-root`. The app itself is enqueued by ConsoleAssets, on this
 * screen only, and routes the 12 screens client-side
 * (`?page=amc-copilot&screen=updates`).
 *
 * @since 0.1.0
 */
final class ConsolePage extends AbstractAdminPage {

	/**
	 * Menu slug.
	 */
	public const SLUG = 'amc-copilot';

	/**
	 * Id of the element the React app mounts into.
	 */
	public const ROOT_ID = 'amc-console-root';

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * Hook suffix WordPress gives this page (`toplevel_page_amc-copilot`), as
	 * passed to `admin_enqueue_scripts`.
	 *
	 * @return string Hook suffix.
	 */
	public static function hook_suffix(): string {
		return 'toplevel_page_' . self::SLUG;
	}

	/**
	 * URL of the console, optionally on a screen.
	 *
	 * @param string $screen Screen slug, e.g. `updates`. Empty for the Overview.
	 *
	 * @return string Admin URL.
	 */
	public static function url( string $screen = '' ): string {
		$args = [ 'page' => self::SLUG ];

		if ( '' !== $screen ) {
			$args['screen'] = $screen;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
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
	 * Render the mount point. The text inside it is replaced when the app
	 * loads, and stays visible when scripts are off or the build is missing.
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>">
				<h1 class="wp-heading-inline"><?php echo esc_html( $this->get_page_title() ); ?></h1>
				<p>
					<?php
					if ( ConsoleAssets::is_built() ) {
						esc_html_e( 'Loading the console…', 'rtcamp-amc-copilot' );
					} else {
						esc_html_e( 'The console has not been built yet. Run "npm run build:prod" in the plugin folder.', 'rtcamp-amc-copilot' );
					}
					?>
				</p>
				<noscript>
					<p><?php esc_html_e( 'The AMC Copilot console needs JavaScript.', 'rtcamp-amc-copilot' ); ?></p>
				</noscript>
			</div>
		</div>
		<?php
	}
}
