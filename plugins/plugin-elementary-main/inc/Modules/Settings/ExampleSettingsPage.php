<?php
/**
 * Example Settings Page: Plugin Options.
 *
 * Demonstrates a clean, declarative settings page built on AbstractSettingsPage
 * and the WordPress Settings API:
 *
 *   - get_fields() is the single source of truth for every option on the
 *     page: option name, type, default, sanitize callback, REST exposure,
 *     plus the UI metadata (label, description).
 *   - register_settings() registers the options via the parent, then attaches
 *     a section + one field per entry via the Settings API.
 *   - render_field() is a generic input renderer — adding another field is a
 *     one-entry change in get_fields(), no HTML to touch.
 *
 * Copy this class as a starting point for any backend-driven settings page.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Modules\Settings;

use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractSettingsPage;

/**
 * Class ExampleSettingsPage
 */
final class ExampleSettingsPage extends AbstractSettingsPage {

	/**
	 * Option key prefix — every option name on this page starts with it.
	 */
	private const PREFIX = 'amc_automata_features_features_';

	/**
	 * Settings section ID. Sections group fields visually on the page.
	 */
	private const SECTION = 'amc_automata_features_features_main_section';

	/**
	 * {@inheritDoc}
	 */
	public static function get_slug(): string {
		return 'amc-automata-features-features';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_page_title(): string {
		return __( 'AMC Automata Features Features', 'amc-automata-features-features' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_menu_title(): string {
		return __( 'AMC Automata Features Features', 'amc-automata-features-features' );
	}

	/**
	 * Single source of truth for every field on this page.
	 *
	 * Combines register_setting() args (type, default, sanitize_callback,
	 * show_in_rest) with UI metadata (label, description) consumed by the
	 * Settings API field registration in register_settings().
	 *
	 * @return array<string, array{
	 *   label: string,
	 *   description?: string,
	 *   type: string,
	 *   default: mixed,
	 *   sanitize_callback?: callable|string,
	 *   show_in_rest?: bool|array<string, mixed>,
	 * }>
	 */
	protected function get_fields(): array {
		return [
			self::PREFIX . 'example_text' => [
				'label'             => __( 'Example Text', 'amc-automata-features-features' ),
				'description'       => __( 'A demo text field saved as a WordPress option.', 'amc-automata-features-features' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Derives the register_setting() args from get_fields() by stripping
	 * the UI-only keys.
	 */
	protected function get_settings(): array {
		$settings = [];

		foreach ( $this->get_fields() as $name => $field ) {
			unset( $field['label'], $field['description'] );
			$settings[ $name ] = $field;
		}

		return $settings;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Lets the parent register the options, then registers a settings
	 * section and one field per option for the UI.
	 */
	public function register_settings(): void {
		parent::register_settings();

		if ( ! is_admin() ) {
			return;
		}

		add_settings_section(
			self::SECTION,
			__( 'Plugin Options', 'amc-automata-features-features' ),
			[ $this, 'render_section' ],
			self::get_slug()
		);

		foreach ( $this->get_fields() as $name => $field ) {
			add_settings_field(
				$name,
				$field['label'],
				[ $this, 'render_field' ],
				self::get_slug(),
				self::SECTION,
				[
					'name'        => $name,
					'default'     => $field['default'] ?? '',
					'description' => $field['description'] ?? '',
					'label_for'   => $name,
				]
			);
		}
	}

	/**
	 * Optional section blurb shown above the fields.
	 */
	public function render_section(): void {
		echo '<p>' . esc_html__( 'Plugin-wide options exposed to the editor and front-end.', 'amc-automata-features-features' ) . '</p>';
	}

	/**
	 * Render a single text input. To support additional input types
	 * (checkbox, select, …), branch on a `type` arg here.
	 *
	 * @param array<string, mixed> $args Field args passed via add_settings_field().
	 */
	public function render_field( array $args ): void {
		$name  = $args['name'];
		$value = get_option( $name, $args['default'] );

		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( $name ),
			esc_attr( (string) $value )
		);

		if ( '' !== $args['description'] ) {
			printf( ' <p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->get_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::get_slug() );
				do_settings_sections( self::get_slug() );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
