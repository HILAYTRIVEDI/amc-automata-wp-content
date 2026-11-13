<?php
/**
 * Ability: the latest result of one check.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Abilities;

use AMC\Checks\Check;
use AMC\Core\Capabilities;
use AMC\Rest\AbstractController;
use AMC\Storage\FindingsRepository;
use AMC\Storage\RunsRepository;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractAbility;

/**
 * Class CheckAbility
 *
 * `amc-copilot/<check-id>`: read-only. Returns the latest finished run of the
 * check (as the REST `Run` shape plus its `data`) and the findings that are
 * still unresolved. It does not run the check.
 *
 * Output: { check: { id, label, group, schedule }, run: Run&{data}|null, findings: list<Finding> }
 *
 * @since 0.1.0
 */
final class CheckAbility extends AbstractAbility {

	/**
	 * The check.
	 *
	 * @var Check
	 */
	private Check $check;

	/**
	 * Constructor.
	 *
	 * @param Check $check Check to expose.
	 */
	public function __construct( Check $check ) {
		$this->check = $check;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return self::name_for( $this->check->id() );
	}

	/**
	 * Ability name for a check id.
	 *
	 * @param string $check_id Check id, e.g. `updates.core`.
	 */
	public static function name_for( string $check_id ): string {
		return AbilitiesRegistrar::CATEGORY . '/' . trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $check_id ) ), '-' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return $this->check->label();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return sprintf(
			/* translators: 1: Check label, 2: Check id. */
			__( 'Returns the latest stored result and the open findings of the "%1$s" check (%2$s). It does not run the check.', 'rtcamp-amc-copilot' ),
			$this->check->label(),
			$this->check->id()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function category(): string {
		return AbilitiesRegistrar::CATEGORY;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return [];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'check'    => [
					'type'       => 'object',
					'properties' => [
						'id'       => [ 'type' => 'string' ],
						'label'    => [ 'type' => 'string' ],
						'group'    => [ 'type' => 'string' ],
						'schedule' => [ 'type' => 'string' ],
					],
				],
				'run'      => [
					'type'        => [ 'object', 'null' ],
					'description' => 'The latest finished run, with its data.',
				],
				'findings' => [
					'type'        => 'array',
					'description' => 'Findings of this check that are not resolved.',
					'items'       => [ 'type' => 'object' ],
				],
			],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input Unused.
	 */
	public function execute( mixed $input ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by AbstractAbility.
		$run      = ( new RunsRepository() )->latest( $this->check->id() );
		$findings = ( new FindingsRepository() )->unresolved_for_check( $this->check->id() );

		return [
			'check'    => [
				'id'       => $this->check->id(),
				'label'    => $this->check->label(),
				'group'    => $this->check->group()->value,
				'schedule' => $this->check->schedule()->value,
			],
			'run'      => null === $run ? null : AbstractController::format_run( $run, true ),
			'findings' => array_map( static fn ( array $row ): array => AbstractController::format_finding( $row ), array_values( $findings ) ),
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input Unused.
	 */
	protected function permission( mixed $input = null ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Required by AbstractAbility.
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return ReadOnlyMeta::get();
	}
}
