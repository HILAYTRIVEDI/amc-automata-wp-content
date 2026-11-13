<?php
/**
 * Creates and rebuilds draft maintenance reports.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\Settings;
use AMC\Helpers\Util;
use AMC\Runner\Lock;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;
use WP_Query;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class Generator
 *
 * Two steps, so a web request never has to wait for the slow one:
 *
 *   create()  Inserts the draft `amc_report`: every section block from
 *             ReportPostType::template(), the period and environment meta,
 *             `_amc_report_id`, and an EMPTY snapshot (build status `pending`).
 *             Fast: a few queries. Refuses a period that already has a report.
 *   build()   Builds the snapshot (SnapshotBuilder: stored data plus a fresh
 *             run of the inventory and detection checks) and stores it, with
 *             Saved fields filled in (build status `ready`). Slow: cron, CLI or
 *             the queued `amc_report_build` event only, never a REST request.
 *
 * generate() = create() + build(), for cron and WP-CLI. REST uses create() and
 * queue_build().
 *
 * Regenerate (regenerate()) replaces ONLY the snapshot meta. Engineer fields,
 * overrides, hidden flags and custom sections live in the post content, which
 * is never touched, so they are all kept.
 *
 * Idempotent: the report ID (`AMC-<code>-<period>`) is the key. A named lock
 * serialises creation and create() returns `amc_report_exists` when a report
 * with that ID is already there in any status except the trash, so a late or
 * doubled cron run never makes a second draft.
 *
 * Hooks fired:
 *   amc_report_draft_ready ( int $post_id )  After the FIRST successful build of a report.
 *
 * @since 0.1.0
 */
final class Generator implements Registrable {

	/**
	 * Single cron event that builds one report's snapshot. Argument: post ID.
	 */
	public const BUILD_HOOK = 'amc_report_build';

	/**
	 * Constructor.
	 *
	 * @param SnapshotBuilder|null $builder Snapshot builder.
	 * @param SiteFacts|null       $facts   WordPress reads.
	 * @param callable|null        $now     Returns the current DateTimeImmutable (test seam).
	 */
	public function __construct(
		private ?SnapshotBuilder $builder = null,
		private ?SiteFacts $facts = null,
		private mixed $now = null
	) {
		$this->facts   ??= new SiteFacts();
		$this->builder ??= new SnapshotBuilder( $this->facts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::BUILD_HOOK, [ $this, 'run_queued_build' ] );
	}

	/**
	 * Resolve a period type and optional dates into a Period.
	 *
	 * - month, quarter, year: the period that contains `$start`, or the most
	 *   recently completed one when `$start` is empty. `$end` is ignored.
	 * - custom: both dates (Y-m-d, site time) are required.
	 *
	 * @param string      $type  month | quarter | year | custom.
	 * @param string|null $start Y-m-d or null.
	 * @param string|null $end   Y-m-d or null.
	 */
	public function resolve_period( string $type, ?string $start = null, ?string $end = null ): Period|\WP_Error {
		$tz       = $this->tz();
		$contract = $this->contract_start();

		try {
			if ( Period::TYPE_CUSTOM === $type ) {
				$from = self::parse_date( $start, $tz );
				$to   = self::parse_date( $end, $tz );

				if ( null === $from || null === $to ) {
					return new \WP_Error( 'amc_invalid_period', __( 'A custom report needs a start and an end date (YYYY-MM-DD).', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
				}

				return Periods::custom( $from, $to, $tz );
			}

			$now = $this->now();

			if ( null !== $start && '' !== $start ) {
				$from = self::parse_date( $start, $tz );

				if ( null === $from ) {
					return new \WP_Error( 'amc_invalid_period', __( 'The start date must be YYYY-MM-DD.', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
				}

				$steps = [
					Period::TYPE_MONTH   => '+1 month',
					Period::TYPE_QUARTER => '+3 months',
					Period::TYPE_YEAR    => '+12 months',
				];

				if ( ! isset( $steps[ $type ] ) ) {
					return new \WP_Error( 'amc_invalid_period', __( 'Unknown report period.', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
				}

				$now = $from->modify( $steps[ $type ] );
			}

			return Periods::previous( $type, $now, $contract, $tz );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'amc_invalid_period', $e->getMessage(), [ 'status' => 400 ] );
		}
	}

	/**
	 * The stable report ID for a period on this site, e.g. `AMC-NWT-2026-10`.
	 *
	 * @param Period $period Period.
	 */
	public function report_id( Period $period ): string {
		return $period->report_id( $this->site_code() );
	}

	/**
	 * Short site code used in report IDs.
	 *
	 * A multi-word name gives its initials ("North West Trust" is NWT), a
	 * single word its first five characters. Filter `amc_report_site_code`.
	 */
	public function site_code(): string {
		$name  = $this->facts->site_name();
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? [] : $words;
		$code  = '';

		if ( count( $words ) > 1 ) {
			foreach ( array_slice( $words, 0, 6 ) as $word ) {
				$code .= mb_substr( $word, 0, 1 );
			}
		} elseif ( [] !== $words ) {
			$code = mb_substr( $words[0], 0, 5 );
		}

		$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]+/', '', remove_accents( $code ) ) );

		if ( '' === $code ) {
			$host = wp_parse_url( $this->facts->home_url(), PHP_URL_HOST );
			$code = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]+/', '', is_string( $host ) ? $host : '' ) );
			$code = '' !== $code ? substr( $code, 0, 5 ) : 'SITE';
		}

		/**
		 * Filters the site code used in report IDs (letters and digits only).
		 *
		 * @param string $code Site code.
		 */
		$filtered = (string) apply_filters( 'amc_report_site_code', $code );
		$filtered = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]+/', '', $filtered ) );

		return '' !== $filtered ? $filtered : $code;
	}

	/**
	 * The report with this ID, in any status except the trash.
	 *
	 * @param string $report_id Report ID.
	 *
	 * @return int|null Post ID.
	 */
	public function find_existing( string $report_id ): ?int {
		$query = new WP_Query(
			[
				'post_type'              => ReportPostType::SLUG,
				'post_status'            => [ 'draft', 'pending', 'future', 'publish', 'private' ],
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One row, admin/cron only.
					[
						'key'   => '_amc_report_id',
						'value' => $report_id,
					],
				],
			]
		);

		$ids = array_map( 'intval', $query->posts );

		return [] === $ids ? null : $ids[0];
	}

	/**
	 * Create the draft report (no snapshot yet).
	 *
	 * @param Period $period    Period.
	 * @param int    $author_id Author (0 = the default engineer).
	 *
	 * @return int|\WP_Error Post ID. `amc_report_exists` (409, `existing_id` in the data) when the period already has a report.
	 */
	public function create( Period $period, int $author_id = 0 ): int|\WP_Error {
		$report_id = $this->report_id( $period );
		$lock      = Lock::named( 'report-create:' . $report_id, 120 );

		if ( ! $lock->acquire() ) {
			return new \WP_Error( 'amc_locked', __( 'This report is being created right now.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
		}

		try {
			$existing = $this->find_existing( $report_id );

			if ( null !== $existing ) {
				return new \WP_Error(
					'amc_report_exists',
					/* translators: %s: report ID. */
					sprintf( __( 'A report for this period already exists (%s).', 'rtcamp-amc-copilot' ), $report_id ),
					[
						'status'      => 409,
						'existing_id' => $existing,
					]
				);
			}

			return $this->insert( $period, $report_id, $author_id );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Build the snapshot of a report and store it. Replaces ONLY the snapshot.
	 *
	 * @param int    $post_id Report ID.
	 * @param string $trigger Runner trigger for the fresh checks: 'cron' | 'cli' | 'manual'.
	 */
	public function build( int $post_id, string $trigger = 'cron' ): true|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ReportPostType::SLUG !== $post->post_type ) {
			return new \WP_Error( 'amc_not_found', __( 'Report not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		$period = $this->period_of( $post_id );

		if ( null === $period ) {
			return new \WP_Error( 'amc_invalid_period', __( 'The report has no valid period.', 'rtcamp-amc-copilot' ), [ 'status' => 400 ] );
		}

		$lock = Lock::named( 'report-build:' . $post_id );

		if ( ! $lock->acquire() ) {
			return new \WP_Error( 'amc_locked', __( 'This report is already being built.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
		}

		try {
			$first = '' === (string) get_post_meta( $post_id, '_amc_snapshot_taken_at', true );

			$snapshot = $this->builder->build( $period, (int) $post->post_author, $trigger );

			$this->store_snapshot( $post_id, $snapshot );

			if ( $first ) {
				/**
				 * Fires once, after the first snapshot of a new draft report is stored.
				 *
				 * Lane A2 sends the "draft ready" email from this (and
				 * ReportEmails::draft_ready() supplies the content).
				 *
				 * @param int $post_id Report ID.
				 */
				do_action( 'amc_report_draft_ready', $post_id );
			}

			return true;
		} catch ( \Throwable $e ) {
			update_post_meta( $post_id, '_amc_build_status', ReportPostType::BUILD_FAILED );
			Util::logger()->error(
				'Report snapshot failed',
				[
					'post_id' => $post_id,
					'error'   => $e->getMessage(),
				]
			);

			return new \WP_Error( 'amc_build_failed', $e->getMessage(), [ 'status' => 500 ] );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Create and build in this process (cron and WP-CLI).
	 *
	 * @param Period $period    Period.
	 * @param int    $author_id Author (0 = the default engineer).
	 * @param string $trigger   'cron' | 'cli' | 'manual'.
	 *
	 * @return int|\WP_Error Post ID.
	 */
	public function generate( Period $period, int $author_id = 0, string $trigger = 'cron' ): int|\WP_Error {
		$post_id = $this->create( $period, $author_id );

		if ( $post_id instanceof \WP_Error ) {
			return $post_id;
		}

		$built = $this->build( $post_id, $trigger );

		return $built instanceof \WP_Error ? $built : $post_id;
	}

	/**
	 * "Regenerate data": rebuild the snapshot of a DRAFT report, keep everything else.
	 *
	 * @param int    $post_id Report ID.
	 * @param string $trigger 'cron' | 'cli' | 'manual'.
	 *
	 * @return true|\WP_Error `amc_report_published` (409) for a published report.
	 */
	public function regenerate( int $post_id, string $trigger = 'manual' ): true|\WP_Error {
		$guard = $this->guard_regenerable( $post_id );

		return $guard instanceof \WP_Error ? $guard : $this->build( $post_id, $trigger );
	}

	/**
	 * Mark a draft pending and queue its snapshot build (REST: never inline).
	 *
	 * @param int $post_id Report ID.
	 */
	public function queue_build( int $post_id ): void {
		update_post_meta( $post_id, '_amc_build_status', ReportPostType::BUILD_PENDING );

		if ( false === wp_next_scheduled( self::BUILD_HOOK, [ $post_id ] ) ) {
			wp_schedule_single_event( time(), self::BUILD_HOOK, [ $post_id ] );
		}
	}

	/**
	 * Check that a report may be regenerated.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return WP_Post|\WP_Error The post.
	 */
	public function guard_regenerable( int $post_id ): WP_Post|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ReportPostType::SLUG !== $post->post_type ) {
			return new \WP_Error( 'amc_not_found', __( 'Report not found.', 'rtcamp-amc-copilot' ), [ 'status' => 404 ] );
		}

		if ( 'publish' === $post->post_status ) {
			return new \WP_Error( 'amc_report_published', __( 'A published report cannot be regenerated. Switch it back to draft first.', 'rtcamp-amc-copilot' ), [ 'status' => 409 ] );
		}

		return $post;
	}

	/**
	 * Cron callback: build one queued report.
	 *
	 * @param int $post_id Report ID.
	 */
	public function run_queued_build( int $post_id ): void {
		if ( \AMC\Core\KillSwitch::is_active() ) {
			return;
		}

		$this->build( (int) $post_id, 'cron' );
	}

	/**
	 * The editor URL of a report, independent of who is logged in.
	 *
	 * `get_edit_post_link()` returns nothing without a current user, which is the
	 * case in cron, where the "draft ready" email link is built.
	 *
	 * @param int $post_id Report ID.
	 */
	public static function edit_url( int $post_id ): string {
		return admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	}

	/**
	 * Post content: every section block from the template, locked.
	 */
	public static function content(): string {
		$blocks = [];

		foreach ( ReportPostType::template() as [ $name, $attributes ] ) {
			$blocks[] = get_comment_delimited_block_content( $name, $attributes, '' );
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * The contract start date as a calendar date at midnight site time, or null.
	 */
	public function contract_start(): ?DateTimeImmutable {
		return self::parse_date( (string) Settings::get_value( 'contract_start' ), $this->tz() );
	}

	/**
	 * Site timezone.
	 */
	public function tz(): DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Now, as an instant.
	 */
	public function now(): DateTimeImmutable {
		if ( is_callable( $this->now ) ) {
			$value = ( $this->now )();

			if ( $value instanceof DateTimeImmutable ) {
				return $value;
			}
		}

		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Insert the draft post and its meta.
	 *
	 * @param Period $period    Period.
	 * @param string $report_id Report ID.
	 * @param int    $author_id Author.
	 */
	private function insert( Period $period, string $report_id, int $author_id ): int|\WP_Error {
		$author   = $author_id > 0 ? $author_id : $this->facts->default_author();
		$snapshot = SnapshotSchema::empty_snapshot();

		$snapshot['period'] = [
			'type'  => $period->type(),
			'start' => $period->start()->format( 'Y-m-d' ),
			'end'   => $period->end()->format( 'Y-m-d' ),
		];
		$snapshot['site']   = [
			'url'         => $this->facts->home_url(),
			'name'        => $this->facts->site_name(),
			'blog_id'     => $this->facts->blog_id(),
			'environment' => Settings::environment(),
		];

		$post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => ReportPostType::SLUG,
					'post_status'  => 'draft',
					'post_author'  => $author,
					/* translators: %s: period label, e.g. October 2026. */
					'post_title'   => sprintf( __( 'Website Maintenance Report: %s', 'rtcamp-amc-copilot' ), $period->label() ),
					'post_content' => self::content(),
				]
			),
			true
		);

		if ( $post_id instanceof \WP_Error ) {
			return $post_id;
		}

		$meta = [
			'_amc_period_type'  => $period->type(),
			'_amc_period_start' => $period->start()->format( 'Y-m-d' ),
			'_amc_period_end'   => $period->end()->format( 'Y-m-d' ),
			'_amc_environment'  => Settings::environment(),
			'_amc_report_id'    => $report_id,
			'_amc_build_status' => ReportPostType::BUILD_PENDING,
			'_amc_snapshot'     => wp_json_encode( $snapshot ),
		];

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( (string) $value ) );
		}

		return $post_id;
	}

	/**
	 * Store a built snapshot on a report.
	 *
	 * @param int                  $post_id  Report ID.
	 * @param array<string, mixed> $snapshot Snapshot.
	 */
	private function store_snapshot( int $post_id, array $snapshot ): void {
		update_post_meta( $post_id, '_amc_snapshot', wp_slash( (string) wp_json_encode( $snapshot ) ) );
		update_post_meta( $post_id, '_amc_snapshot_taken_at', (string) ( $snapshot['taken_at'] ?? '' ) );
		update_post_meta( $post_id, '_amc_build_status', ReportPostType::BUILD_READY );
	}

	/**
	 * Rebuild a Period from a report's meta.
	 *
	 * @param int $post_id Report ID.
	 */
	private function period_of( int $post_id ): ?Period {
		$type  = (string) get_post_meta( $post_id, '_amc_period_type', true );
		$start = self::parse_date( (string) get_post_meta( $post_id, '_amc_period_start', true ), $this->tz() );
		$end   = self::parse_date( (string) get_post_meta( $post_id, '_amc_period_end', true ), $this->tz() );

		if ( null === $start || null === $end || $start > $end ) {
			return null;
		}

		try {
			return new Period( $type, $start, $end->setTime( 23, 59, 59 ), sprintf( '%s – %s', $start->format( 'j M Y' ), $end->format( 'j M Y' ) ) );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * A 'Y-m-d' string as midnight in a timezone, or null when invalid.
	 *
	 * @param string|null  $value Date.
	 * @param DateTimeZone $tz    Timezone.
	 */
	private static function parse_date( ?string $value, DateTimeZone $tz ): ?DateTimeImmutable {
		if ( null === $value || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return new DateTimeImmutable( $value . ' 00:00:00', $tz );
	}
}
