<?php
/**
 * Base class for every `amc/v1` controller.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Rest;

use AMC\Checks\Registry;
use AMC\Core\Capabilities;
use WP_REST_Response;
use WP_REST_Server;
use rtCamp\WPPrimitives\Contracts\Abstracts\AbstractRESTController;

/**
 * Class AbstractController
 *
 * Conventions for every route (the console relies on them):
 *
 * - Namespace `amc/v1`. Errors are WP_Error with a `status`.
 * - Permissions: manage() = `amc_manage`; view_reports() = `amc_manage` OR
 *   `amc_view_reports`; manage_access() = `amc_manage` + `manage_options`.
 *   Never `__return_true`.
 * - Times in responses are ISO 8601 UTC strings ('2026-11-13T09:00:00Z') or null.
 * - Lists return a JSON array and the headers X-WP-Total / X-WP-TotalPages.
 * - A handler whose logic belongs to another lane returns either correctly
 *   shaped placeholder data with the header `X-AMC-Stub: <lane>` (reads),
 *   or not_implemented( '<lane>' ) = HTTP 501 'amc_not_implemented' (writes).
 *   The owning lane replaces the handler body; it never changes the route,
 *   method, args or response shape without updating docs/CONTRACTS.md.
 *
 * @since 0.1.0
 */
abstract class AbstractController extends AbstractRESTController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'amc/v1';

	/**
	 * Lane that owns this controller's handlers (docs only).
	 */
	public const OWNER = 'W0';

	/**
	 * Register one route. Thin wrapper so every controller looks the same.
	 *
	 * @param string               $path       Route path after amc/v1, e.g. '/runs/(?P<id>\d+)'.
	 * @param string               $methods    WP_REST_Server::READABLE etc.
	 * @param callable             $callback   Handler.
	 * @param callable             $permission Permission callback.
	 * @param array<string, mixed> $args       Argument schema.
	 */
	protected function route( string $path, string $methods, callable $callback, callable $permission, array $args = [] ): void {
		register_rest_route(
			$this->namespace,
			$path,
			[
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => $args,
			]
		);
	}

	/**
	 * Permission: engineer.
	 */
	public function manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	/**
	 * Permission: engineer or client report reader.
	 */
	public function view_reports(): bool {
		return current_user_can( Capabilities::MANAGE ) || current_user_can( Capabilities::VIEW_REPORTS );
	}

	/**
	 * Permission: may grant/revoke engineer access.
	 */
	public function manage_access(): bool {
		return Capabilities::can_manage_access( get_current_user_id() );
	}

	/**
	 * 501 for handlers another lane will implement.
	 *
	 * @param string $lane Owning lane, e.g. 'D1'.
	 */
	protected function not_implemented( string $lane ): \WP_Error {
		return new \WP_Error(
			'amc_not_implemented',
			/* translators: %s: Lane id. */
			sprintf( __( 'Not implemented yet (owner: lane %s).', 'rtcamp-amc-copilot' ), $lane ),
			[
				'status' => 501,
				'owner'  => $lane,
			]
		);
	}

	/**
	 * Placeholder response with correctly shaped data.
	 *
	 * @param mixed  $data Data.
	 * @param string $lane Owning lane.
	 */
	protected function stub( mixed $data, string $lane ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'X-AMC-Stub', $lane );

		return $response;
	}

	/**
	 * List response with pagination headers.
	 *
	 * @param list<mixed> $items    Items.
	 * @param int         $total    Total rows.
	 * @param int         $per_page Page size.
	 */
	protected function paginated( array $items, int $total, int $per_page ): WP_REST_Response {
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );

		return $response;
	}

	/**
	 * Standard page / per_page args.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function pagination_args(): array {
		return [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 50,
				'minimum' => 1,
				'maximum' => 500,
			],
		];
	}

	/**
	 * DB datetime (UTC) to ISO 8601, or null.
	 *
	 * @param mixed $value 'Y-m-d H:i:s' or null.
	 */
	public static function iso( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || str_starts_with( $value, '0000' ) ) {
			return null;
		}

		return str_replace( ' ', 'T', $value ) . 'Z';
	}

	/**
	 * ISO 8601 / date string from a request to a DB datetime (UTC), or null.
	 *
	 * @param mixed $value Request value.
	 */
	public static function to_db_time( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$time = strtotime( $value );

		return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Run row -> REST shape `Run`.
	 *
	 * @param array<string, mixed> $row       RunsRepository row.
	 * @param bool                 $with_data Include `data`.
	 *
	 * @return array<string, mixed>
	 */
	public static function format_run( array $row, bool $with_data = false ): array {
		$check = ( new Registry() )->get( (string) $row['check_id'] );

		$run = [
			'id'          => (int) $row['id'],
			'check_id'    => (string) $row['check_id'],
			'check_label' => null !== $check ? $check->label() : (string) $row['check_id'],
			'group'       => null !== $check ? $check->group()->value : null,
			'group_label' => null !== $check ? $check->group()->label() : null,
			'trigger'     => (string) $row['trigger_type'],
			'status'      => (string) $row['status'],
			'summary'     => (string) $row['summary'],
			'user_id'     => (int) $row['user_id'],
			'created_at'  => static::iso( $row['created_at'] ),
			'started_at'  => static::iso( $row['started_at'] ),
			'finished_at' => static::iso( $row['finished_at'] ),
			'duration_ms' => (int) $row['duration_ms'],
		];

		if ( $with_data ) {
			$run['data'] = (array) $row['data'];
		}

		return $run;
	}

	/**
	 * Finding row -> REST shape `Finding`.
	 *
	 * @param array<string, mixed> $row FindingsRepository row.
	 *
	 * @return array<string, mixed>
	 */
	public static function format_finding( array $row ): array {
		$check = ( new Registry() )->get( (string) $row['check_id'] );

		return [
			'id'          => (int) $row['id'],
			'check_id'    => (string) $row['check_id'],
			'group'       => null !== $check ? $check->group()->value : null,
			'group_label' => null !== $check ? $check->group()->label() : null,
			'dedupe_key'  => (string) $row['dedupe_key'],
			'severity'    => (string) $row['severity'],
			'title'       => (string) $row['title'],
			'data'        => (array) $row['data'],
			'status'      => (string) $row['status'],
			'note'        => null === $row['note'] ? null : (string) $row['note'],
			'run_id'      => (int) $row['run_id'],
			'first_seen'  => static::iso( $row['first_seen'] ),
			'last_seen'   => static::iso( $row['last_seen'] ),
			'resolved_at' => static::iso( $row['resolved_at'] ),
			'alerted_at'  => static::iso( $row['alerted_at'] ),
		];
	}

	/**
	 * Latest finished run of each check id: { check_id: { run: Run, data: {...} } | null }.
	 *
	 * Used by the screen endpoints (updates, security, performance,
	 * inventory). The `data` shape is owned by each check (see its docblock).
	 *
	 * @param list<string> $check_ids Check ids.
	 *
	 * @return array<string, array{run: array<string, mixed>, data: array<string, mixed>}|null>
	 */
	protected function latest_results( array $check_ids ): array {
		$latest = ( new \AMC\Storage\RunsRepository() )->latest_per_check();
		$out    = [];

		foreach ( $check_ids as $id ) {
			$out[ $id ] = isset( $latest[ $id ] )
				? [
					'run'  => static::format_run( $latest[ $id ] ),
					'data' => (array) $latest[ $id ]['data'],
				]
				: null;
		}

		return $out;
	}

	/**
	 * Shorthand for a readable route.
	 */
	protected static function readable(): string {
		return WP_REST_Server::READABLE;
	}

	/**
	 * Shorthand for a creatable route.
	 */
	protected static function creatable(): string {
		return WP_REST_Server::CREATABLE;
	}

	/**
	 * Shorthand for a deletable route.
	 */
	protected static function deletable(): string {
		return WP_REST_Server::DELETABLE;
	}
}
