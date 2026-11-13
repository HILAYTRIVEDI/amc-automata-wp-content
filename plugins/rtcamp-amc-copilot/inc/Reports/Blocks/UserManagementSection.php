<?php
/**
 * Report section block: User management.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports\Blocks;

/**
 * Class UserManagementSection
 *
 * @since 0.1.0
 */
final class UserManagementSection extends AbstractSectionBlock {

	/**
	 * {@inheritDoc}
	 */
	public static function section_key(): string {
		return 'user_management';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param SectionData $data Resolved values.
	 */
	protected function render_section( SectionData $data ): string {
		$rows = [];

		$rows[] = [ $data->label( 'total_users' ), $this->format_value( $data->value( 'total_users' ), $data->type( 'total_users' ) ) ];
		// users_per_role (list) rendered below if applicable.
		$users_per_role = $data->value( 'users_per_role' );
		if ( is_array( $users_per_role ) && [] !== $users_per_role ) {
			$rows[] = [ $data->label( 'users_per_role' ), esc_html( wp_json_encode( $users_per_role ) ) ];
		} else {
			$rows[] = [ $data->label( 'users_per_role' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// administrators (list) rendered below if applicable.
		$administrators = $data->value( 'administrators' );
		if ( is_array( $administrators ) && [] !== $administrators ) {
			$rows[] = [ $data->label( 'administrators' ), esc_html( wp_json_encode( $administrators ) ) ];
		} else {
			$rows[] = [ $data->label( 'administrators' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// users_added (list) rendered below if applicable.
		$users_added = $data->value( 'users_added' );
		if ( is_array( $users_added ) && [] !== $users_added ) {
			$rows[] = [ $data->label( 'users_added' ), esc_html( wp_json_encode( $users_added ) ) ];
		} else {
			$rows[] = [ $data->label( 'users_added' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}
		// users_removed (list) rendered below if applicable.
		$users_removed = $data->value( 'users_removed' );
		if ( is_array( $users_removed ) && [] !== $users_removed ) {
			$rows[] = [ $data->label( 'users_removed' ), esc_html( wp_json_encode( $users_removed ) ) ];
		} else {
			$rows[] = [ $data->label( 'users_removed' ), esc_html__( '—', 'rtcamp-amc-copilot' ) ];
		}

		return $this->key_value_table( $rows );
	}
}
