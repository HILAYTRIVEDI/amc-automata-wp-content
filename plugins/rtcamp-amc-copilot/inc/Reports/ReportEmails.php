<?php
/**
 * Report email content: "draft ready" and "report published".
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\Capabilities;
use AMC\Core\Settings;
use WP_Post;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ReportEmails
 *
 * Lane D1 owns the CONTENT of the two report emails; lane A2 owns the
 * transport (throttling is not applied to these, they are one per report).
 *
 *   draft_ready( $post_id )   to the engineers on the recipient list
 *   published( $post_id )     to the `report_publish_recipients` setting
 *                             PLUS client administrators (setting
 *                             `notify_client_on_publish`, on by default)
 *
 * Both return `{ subject, text, html, recipients }`. Subjects start with
 * `[AMC][site name]`; the HTML part is plain paragraphs and a link (no custom
 * styling, per the plan). Filters `amc_report_draft_ready_email` and
 * `amc_report_published_email` receive the array and the post ID.
 *
 * Integration with A2: this class listens to `amc_report_draft_ready` and
 * `amc_report_published` (both fire with the post ID). When
 * `AMC\Notify\Mailer::send_report_draft_ready( $post_id )` /
 * `::send_report_published( $post_id )` exist and are callable statically, it
 * calls them; A2's Mailer should build the message from draft_ready() and
 * published() here. When they do not exist nothing is sent, and A2 can hook
 * the same two actions itself.
 *
 * The "published" email goes out once per report: on its FIRST publish. The
 * meta key `_amc_published_email_sent` (a Unix timestamp) is added as a lock
 * before sending and removed again only when nothing could be sent (nobody to
 * send to, kill switch, mail failure), so a later re-publish can still try.
 * Updates to a published report never send (ReportPublishing only fires
 * `amc_report_published` on the transition into `publish`), and unpublishing
 * and re-publishing a report that was already emailed does not send again.
 *
 * @since 0.1.0
 */
final class ReportEmails implements Registrable {

	/**
	 * Mailer class lane A2 provides.
	 */
	public const MAILER = 'AMC\\Notify\\Mailer';

	/**
	 * Post meta set once the "published" email has gone out.
	 */
	public const SENT_META = '_amc_published_email_sent';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'amc_report_draft_ready', [ $this, 'on_draft_ready' ] );
		add_action( 'amc_report_published', [ $this, 'on_published' ] );
	}

	/**
	 * Hand the "draft ready" email to the Mailer, when lane A2 has merged.
	 *
	 * @param int $post_id Report ID.
	 */
	public function on_draft_ready( int $post_id ): void {
		self::call_mailer( 'send_report_draft_ready', $post_id );
	}

	/**
	 * Hand the "report published" email to the Mailer, once per report.
	 *
	 * @param int $post_id Report ID.
	 */
	public function on_published( int $post_id ): void {
		// add_post_meta( unique ) is the lock: a second publish event for the
		// same report (or a concurrent request) cannot add it again.
		if ( ! add_post_meta( $post_id, self::SENT_META, time(), true ) ) {
			return;
		}

		if ( ! self::call_mailer( 'send_report_published', $post_id ) ) {
			delete_post_meta( $post_id, self::SENT_META );
		}
	}

	/**
	 * The "draft ready" email.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return array{subject: string, text: string, html: string, recipients: list<string>}
	 */
	public static function draft_ready( int $post_id ): array {
		$post = get_post( $post_id );
		$link = $post instanceof WP_Post ? Generator::edit_url( $post_id ) : '';

		$email = self::compose(
			$post_id,
			/* translators: %s: report ID, e.g. AMC-NWT-2026-10. */
			__( 'Draft report ready: %s', 'rtcamp-amc-copilot' ),
			__( 'A draft maintenance report has been generated and is ready for you to edit and publish.', 'rtcamp-amc-copilot' ),
			__( 'Edit the draft', 'rtcamp-amc-copilot' ),
			$link,
			Settings::recipients()
		);

		/**
		 * Filters the "draft report ready" email.
		 *
		 * @param array{subject: string, text: string, html: string, recipients: list<string>} $email   Email.
		 * @param int                                                                          $post_id Report ID.
		 */
		return (array) apply_filters( 'amc_report_draft_ready_email', $email, $post_id );
	}

	/**
	 * The "report published" email.
	 *
	 * @param int $post_id Report ID.
	 *
	 * @return array{subject: string, text: string, html: string, recipients: list<string>}
	 */
	public static function published( int $post_id ): array {
		$email = self::compose(
			$post_id,
			/* translators: %s: report ID, e.g. AMC-NWT-2026-10. */
			__( 'New maintenance report: %s', 'rtcamp-amc-copilot' ),
			__( 'A new website maintenance report has been published and is available under Maintenance Reports in your WordPress admin.', 'rtcamp-amc-copilot' ),
			__( 'Read the report', 'rtcamp-amc-copilot' ),
			ClientView::view_url( $post_id, false ),
			self::published_recipients()
		);

		/**
		 * Filters the "report published" email.
		 *
		 * @param array{subject: string, text: string, html: string, recipients: list<string>} $email   Email.
		 * @param int                                                                          $post_id Report ID.
		 */
		return (array) apply_filters( 'amc_report_published_email', $email, $post_id );
	}

	/**
	 * Everyone who gets the "published" email: the addresses in the
	 * `report_publish_recipients` setting (always), plus the client
	 * administrators when `notify_client_on_publish` is on. Deduplicated
	 * case-insensitively.
	 *
	 * @return list<string> Email addresses.
	 */
	public static function published_recipients(): array {
		$emails = [];

		foreach ( array_merge( (array) Settings::get_value( 'report_publish_recipients' ), self::client_recipients() ) as $email ) {
			$email = (string) $email;

			if ( is_email( $email ) ) {
				$emails[ strtolower( $email ) ] ??= $email;
			}
		}

		return array_values( $emails );
	}

	/**
	 * Client administrators who get the "published" email: administrators who can read reports and
	 * are not engineers, and only when the site setting is on.
	 *
	 * @return list<string> Email addresses.
	 */
	public static function client_recipients(): array {
		if ( ! (bool) Settings::get_value( 'notify_client_on_publish' ) ) {
			return [];
		}

		$emails = [];

		foreach ( get_users( [ 'role' => 'administrator' ] ) as $user ) {
			if ( $user->has_cap( Capabilities::VIEW_REPORTS ) && ! $user->has_cap( Capabilities::MANAGE ) && is_email( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Build one email.
	 *
	 * @param int          $post_id    Report ID.
	 * @param string       $subject    Subject format with one `%s` for the report ID.
	 * @param string       $intro      First sentence.
	 * @param string       $link_label Link text.
	 * @param string       $link       Link URL.
	 * @param list<string> $recipients Recipients.
	 *
	 * @return array{subject: string, text: string, html: string, recipients: list<string>}
	 */
	private static function compose( int $post_id, string $subject, string $intro, string $link_label, string $link, array $recipients ): array {
		$report_id = (string) get_post_meta( $post_id, '_amc_report_id', true );
		$title     = get_the_title( $post_id );
		$site      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$period    = self::period_line( $post_id );

		$text = $intro . "\n\n" . $title . "\n" . $period . "\n\n" . $link_label . ': ' . $link . "\n";

		$html = '<p>' . esc_html( $intro ) . '</p>'
			. '<p><strong>' . esc_html( $title ) . '</strong><br>' . esc_html( $period ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '">' . esc_html( $link_label ) . '</a></p>';

		return [
			'subject'    => sprintf( '[AMC][%s] %s', $site, sprintf( $subject, '' !== $report_id ? $report_id : $title ) ),
			'text'       => $text,
			'html'       => $html,
			'recipients' => $recipients,
		];
	}

	/**
	 * "Period: 1 Oct 2026 to 31 Oct 2026 (production)".
	 *
	 * @param int $post_id Report ID.
	 */
	private static function period_line( int $post_id ): string {
		$start       = (string) get_post_meta( $post_id, '_amc_period_start', true );
		$end         = (string) get_post_meta( $post_id, '_amc_period_end', true );
		$environment = (string) get_post_meta( $post_id, '_amc_environment', true );

		/* translators: 1: period start date, 2: period end date, 3: environment, e.g. production. */
		return sprintf( __( 'Period: %1$s to %2$s (%3$s)', 'rtcamp-amc-copilot' ), $start, $end, $environment );
	}

	/**
	 * Call a static Mailer method when lane A2 provides it.
	 *
	 * @param string $method  Method name.
	 * @param int    $post_id Report ID.
	 *
	 * @return bool Whether the Mailer reported a send.
	 */
	private static function call_mailer( string $method, int $post_id ): bool {
		$callable = [ self::MAILER, $method ];

		if ( class_exists( self::MAILER ) && is_callable( $callable ) ) {
			return (bool) $callable( $post_id );
		}

		return false;
	}
}
