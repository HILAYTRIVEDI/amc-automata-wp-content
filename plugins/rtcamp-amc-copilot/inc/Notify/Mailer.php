<?php
/**
 * Every email AMC Copilot sends goes through here.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Notify;

use AMC\Admin\ConsolePage;
use AMC\Checks\Registry;
use AMC\Checks\Severity;
use AMC\Core\KillSwitch;
use AMC\Core\Settings;
use AMC\Reports\ReportEmails;
use AMC\Reports\ReportPostType;
use WP_Post;

/**
 * Class Mailer
 *
 * Sends with `wp_mail()`, so the site's mailer plugin or host mail is used.
 * Each mail is multipart/alternative: the HTML body goes to wp_mail() and the
 * plain-text part is attached as `AltBody` in a `phpmailer_init` callback that
 * is added for the duration of one send only, so other plugins' mail is never
 * touched. Subjects start with `[AMC][Site name]`. Nothing is sent while the
 * kill switch is on, or when there is nobody to send to.
 *
 * Public API for the reports lane (D1), who own the content:
 *
 *   Mailer::send_report_draft_ready( int $post_id ): bool   // to the engineers
 *   Mailer::send_report_published( int $post_id ): bool     // report recipients + client administrators
 *
 * The "published" email is built by AMC\Reports\ReportEmails::published()
 * (see send_report_published()). The "draft ready" email defaults to a short
 * message and lets D1 replace it with the filter `amc_report_email`:
 *
 *   apply_filters( 'amc_report_email', array $content, string $event, WP_Post $report )
 *
 *   $event   'draft_ready'
 *   $content array{
 *       subject: string,          // without the [AMC][Site] prefix, which is always added
 *       heading: string,
 *       paragraphs: list<string>, // plain text, escaped on output
 *       action_url: string,
 *       action_label: string
 *   }
 *
 * `send_report_published()` sends one mail per recipient so clients do not
 * see each other's addresses. Its recipients come from
 * ReportEmails::published_recipients(), filterable with
 * `amc_report_published_recipients`.
 *
 * @since 0.1.0
 */
final class Mailer {

	/**
	 * Plain-text part waiting to be attached by phpmailer_init (own sends only).
	 *
	 * @var string|null
	 */
	private static ?string $alt_body = null;

	/**
	 * Send an email to a list of addresses.
	 *
	 * @param list<string>         $to      Recipients (one mail, all in To).
	 * @param string               $subject Subject WITHOUT the [AMC][Site] prefix.
	 * @param array<string, mixed> $email   Content for EmailRenderer.
	 *
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send( array $to, string $subject, array $email ): bool {
		$parts = EmailRenderer::render( $email );

		return self::send_parts( $to, self::subject( $subject ), $parts['html'], $parts['text'] );
	}

	/**
	 * Send pre-rendered parts: the HTML body plus a plain-text alternative.
	 *
	 * @param list<string> $to      Recipients (one mail, all in To).
	 * @param string       $subject Full subject, already prefixed with [AMC][Site].
	 * @param string       $html    HTML part.
	 * @param string       $text    Plain-text part.
	 *
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send_parts( array $to, string $subject, string $html, string $text ): bool {
		$to = array_values( array_filter( $to, 'is_email' ) );

		if ( [] === $to || '' === $html || KillSwitch::is_active() ) {
			return false;
		}

		self::$alt_body = $text;
		add_action( 'phpmailer_init', [ self::class, 'attach_plain_text' ] );

		try {
			return wp_mail( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A handful of alerts per hour (throttled), or one mail per report recipient; not bulk mail.
				$to,
				$subject,
				$html,
				[ 'Content-Type: text/html; charset=UTF-8' ]
			);
		} finally {
			remove_action( 'phpmailer_init', [ self::class, 'attach_plain_text' ] );
			self::$alt_body = null;
		}
	}

	/**
	 * `phpmailer_init` callback: the plain-text part of the mail being sent.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public static function attach_plain_text( $phpmailer ): void {
		if ( null !== self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		}
	}

	/**
	 * Full subject: `[AMC][Site name] text`.
	 *
	 * @param string $text Subject text.
	 */
	public static function subject( string $text ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		return sprintf( '[AMC][%s] %s', $site, $text );
	}

	/**
	 * Immediate alert for one run's findings (one email, however many findings).
	 *
	 * @param list<array<string, mixed>> $findings FindingsRepository rows.
	 * @param list<string>|null          $to       Recipients (default: Settings::recipients()).
	 *
	 * @return bool Whether it was sent.
	 */
	public static function send_immediate( array $findings, ?array $to = null ): bool {
		if ( [] === $findings ) {
			return false;
		}

		$first = $findings[0];

		if ( 1 === count( $findings ) ) {
			$subject = sprintf( '%s: %s', self::severity_label( $first ), (string) $first['title'] );
			$heading = __( 'New alert', 'rtcamp-amc-copilot' );
		} else {
			/* translators: %d: Number of findings. */
			$subject = sprintf( _n( '%d new alert', '%d new alerts', count( $findings ), 'rtcamp-amc-copilot' ), count( $findings ) );
			$heading = $subject;
		}

		return self::send(
			$to ?? Settings::recipients(),
			$subject,
			[
				'heading'      => $heading,
				'intro'        => __( 'AMC Copilot found something that should not wait for the daily digest.', 'rtcamp-amc-copilot' ),
				'sections'     => [
					[
						'title' => __( 'Findings', 'rtcamp-amc-copilot' ),
						'items' => array_map( [ self::class, 'item' ], $findings ),
					],
				],
				'action_url'   => self::console_url(),
				'action_label' => __( 'Open the AMC Copilot console', 'rtcamp-amc-copilot' ),
			]
		);
	}

	/**
	 * Report draft is ready: email the engineers.
	 *
	 * @param int $post_id `amc_report` post ID.
	 *
	 * @return bool Whether it was sent.
	 */
	public static function send_report_draft_ready( int $post_id ): bool {
		$report = self::report( $post_id );

		if ( null === $report ) {
			return false;
		}

		$content = self::report_content(
			'draft_ready',
			$report,
			[
				/* translators: %s: Report title. */
				'subject'      => sprintf( __( 'Report draft ready: %s', 'rtcamp-amc-copilot' ), $report->post_title ),
				'heading'      => __( 'A report draft is ready for review', 'rtcamp-amc-copilot' ),
				'paragraphs'   => [
					/* translators: %s: Report title. */
					sprintf( __( 'The draft of "%s" has been generated. Review it, add your notes and publish it when it is ready.', 'rtcamp-amc-copilot' ), $report->post_title ),
				],
				'action_url'   => (string) get_edit_post_link( $report->ID, 'raw' ),
				'action_label' => __( 'Review the draft', 'rtcamp-amc-copilot' ),
			]
		);

		return self::send( Settings::recipients(), (string) $content['subject'], $content );
	}

	/**
	 * Report published: email everyone on ReportEmails::published_recipients()
	 * (the `report_publish_recipients` setting plus, when
	 * `notify_client_on_publish` is on, the client administrators).
	 *
	 * The subject, plain-text and HTML parts come from ReportEmails::published()
	 * (filter `amc_report_published_email`), which links to the client's
	 * read-only view and names the report and its period. One mail per
	 * recipient, so nobody sees anybody else's address.
	 *
	 * @param int $post_id `amc_report` post ID.
	 *
	 * @return bool Whether at least one email was sent.
	 */
	public static function send_report_published( int $post_id ): bool {
		$report = self::report( $post_id );

		if ( null === $report ) {
			return false;
		}

		$email = ReportEmails::published( $report->ID );

		/**
		 * Filters who gets the "report published" email.
		 *
		 * @param list<string> $emails Addresses.
		 * @param WP_Post      $report The `amc_report` post.
		 */
		$recipients = apply_filters( 'amc_report_published_recipients', (array) ( $email['recipients'] ?? [] ), $report );
		$recipients = is_array( $recipients ) ? array_values( array_unique( array_filter( array_map( 'strval', $recipients ), 'is_email' ) ) ) : [];

		$sent = false;
		foreach ( $recipients as $address ) {
			$sent = self::send_parts( [ $address ], (string) ( $email['subject'] ?? '' ), (string) ( $email['html'] ?? '' ), (string) ( $email['text'] ?? '' ) ) || $sent;
		}

		return $sent;
	}

	/**
	 * Console URL of one finding.
	 *
	 * @param array<string, mixed> $finding FindingsRepository row.
	 */
	public static function finding_url( array $finding ): string {
		$url = add_query_arg(
			[
				'page'    => ConsolePage::SLUG,
				'screen'  => 'findings',
				'finding' => (int) ( $finding['id'] ?? 0 ),
			],
			admin_url( 'admin.php' )
		);

		/**
		 * Filters the console URL linked from an email for one finding.
		 *
		 * @param string               $url     URL.
		 * @param array<string, mixed> $finding FindingsRepository row.
		 */
		return (string) apply_filters( 'amc_notify_finding_url', $url, $finding );
	}

	/**
	 * Console home URL.
	 */
	public static function console_url(): string {
		return add_query_arg( 'page', ConsolePage::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * One finding as an email list item.
	 *
	 * @param array<string, mixed> $finding FindingsRepository row.
	 *
	 * @return array{title: string, severity: string, check: string, url: string}
	 */
	public static function item( array $finding ): array {
		$check = ( new Registry() )->get( (string) $finding['check_id'] );

		return [
			'title'    => (string) $finding['title'],
			'severity' => self::severity_label( $finding ),
			'check'    => null !== $check ? $check->label() : (string) $finding['check_id'],
			'url'      => self::finding_url( $finding ),
		];
	}

	/**
	 * Translated severity label of a finding row.
	 *
	 * @param array<string, mixed> $finding FindingsRepository row.
	 */
	private static function severity_label( array $finding ): string {
		return ( Severity::tryFrom( (string) ( $finding['severity'] ?? '' ) ) ?? Severity::INFO )->label();
	}

	/**
	 * Load an `amc_report` post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function report( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );

		return $post instanceof WP_Post && ReportPostType::SLUG === $post->post_type ? $post : null;
	}

	/**
	 * Apply the `amc_report_email` filter to the default content.
	 *
	 * @param string               $event   'draft_ready' (the 'published' email comes from ReportEmails).
	 * @param WP_Post              $report  Report.
	 * @param array<string, mixed> $content Default content.
	 *
	 * @return array<string, mixed>
	 */
	private static function report_content( string $event, WP_Post $report, array $content ): array {
		/**
		 * Filters the content of a report email (owned by the reports lane).
		 *
		 * @param array<string, mixed> $content Keys: subject, heading, paragraphs, action_url, action_label.
		 * @param string               $event   'draft_ready' (the 'published' email comes from ReportEmails).
		 * @param WP_Post              $report  The `amc_report` post.
		 */
		$filtered = apply_filters( 'amc_report_email', $content, $event, $report );

		return is_array( $filtered ) ? array_merge( $content, $filtered ) : $content;
	}
}
