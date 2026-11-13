<?php
/**
 * Shared helpers for the Notify tests.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Notify;

use AMC\Checks\Finding;
use AMC\Checks\Severity;
use AMC\Core\Capabilities;
use AMC\Core\Settings;
use AMC\Notify\Throttle;
use AMC\Runner\ProcessedFindings;
use AMC\Storage\FindingsRepository;
use AMC\Tests\TestCase;

/**
 * Class NotifyTestCase
 */
abstract class NotifyTestCase extends TestCase {

	/**
	 * Findings repository.
	 *
	 * @var FindingsRepository
	 */
	protected FindingsRepository $findings;

	public function set_up(): void {
		parent::set_up();

		$this->findings = new FindingsRepository();
		delete_option( Throttle::OPTION );
		Settings::update( [ 'recipients' => [ 'engineer@example.com' ] ] );
		reset_phpmailer_instance();
	}

	/**
	 * Insert a finding row like the runner does.
	 *
	 * @param string               $check_id Check id.
	 * @param string               $key      Dedupe key.
	 * @param Severity             $severity Severity.
	 * @param array<string, mixed> $data     Finding data.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	protected function make_finding( string $check_id, string $key = 'k', Severity $severity = Severity::HIGH, array $data = [] ): array {
		$id = $this->findings->insert_finding( new Finding( $key, $severity, 'Title ' . $key, $data ), $check_id, 1 );

		return (array) $this->findings->find( $id );
	}

	/**
	 * Payload with the given rows as NEW.
	 *
	 * @param array<string, mixed> ...$rows Finding rows.
	 */
	protected function processed( array ...$rows ): ProcessedFindings {
		return new ProcessedFindings( 1, (string) $rows[0]['check_id'], get_current_blog_id(), 'cron', array_values( $rows ) );
	}

	/**
	 * Number of emails sent so far.
	 */
	protected function sent_count(): int {
		$mailer = tests_retrieve_phpmailer_instance();

		return $mailer ? count( $mailer->mock_sent ) : 0;
	}

	/**
	 * Raw MIME of one sent email.
	 *
	 * @param int $index Index.
	 */
	protected function sent( int $index = 0 ): object {
		return (object) tests_retrieve_phpmailer_instance()->mock_sent[ $index ];
	}

	/**
	 * Decoded MIME body of one sent email.
	 *
	 * @param int $index Index.
	 */
	protected function mime( int $index = 0 ): string {
		$body = (string) $this->sent( $index )->body;

		return false !== stripos( $body, 'quoted-printable' ) ? quoted_printable_decode( $body ) : $body;
	}

	/**
	 * Create an administrator, optionally an engineer.
	 *
	 * @param string $email    Address.
	 * @param bool   $engineer Whether to grant amc_manage.
	 */
	protected function make_admin( string $email, bool $engineer = false ): int {
		$id = self::factory()->user->create(
			[
				'role'       => 'administrator',
				'user_email' => $email,
			]
		);

		if ( $engineer ) {
			Capabilities::grant( $id );
		}

		return $id;
	}
}
