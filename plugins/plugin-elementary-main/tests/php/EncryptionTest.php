<?php
/**
 * Tests for the plugin Encryption service.
 *
 * @package AMC_Automata_Features\Features
 */

declare( strict_types = 1 );

namespace AMC_Automata_Features\Features\Tests;

use AMC_Automata_Features\Features\Core\Encryption;
use AMC_Automata_Features\Features\Helpers\Util;
use AMC_Automata_Features\Features\Main;
use rtCamp\WPPrimitives\Utils\Encryptor;

/**
 * Class EncryptionTest
 */
final class EncryptionTest extends TestCase {

	/**
	 * The class exists and extends the framework Encryptor.
	 */
	public function test_extends_framework_encryptor(): void {
		$this->assertInstanceOf( Encryptor::class, new Encryption() );
	}

	/**
	 * It is shareable, registered in Main, and resolvable from the container.
	 */
	public function test_registered_and_shared_in_main(): void {
		$this->assertContains( Encryption::class, Main::CLASSES );
		$this->assertInstanceOf( Encryption::class, Main::get_instance()->get_shared( Encryption::class ) );
	}

	/**
	 * A keyless framework Encryptor refuses to encrypt — the invariant the
	 * service falls through to when AMC_AUTOMATA_FEATURES_FEATURES_ENCRYPTION_KEY is missing.
	 */
	public function test_missing_key_throws(): void {
		$this->expectException( \RuntimeException::class );

		( new Encryptor() )->encrypt( 'no key configured' );
	}

	/**
	 * With the key constant defined, the shared encryptor roundtrips via
	 * Util::encryption()->encrypt() / ->decrypt().
	 */
	public function test_encrypt_decrypt_roundtrips_with_key_constant(): void {
		if ( ! defined( 'AMC_AUTOMATA_FEATURES_FEATURES_ENCRYPTION_KEY' ) ) {
			define( 'AMC_AUTOMATA_FEATURES_FEATURES_ENCRYPTION_KEY', str_repeat( 'k', 32 ) );
		}

		$encrypted = Util::encryption()->encrypt( 'sensitive-value' );

		$this->assertIsString( $encrypted );
		$this->assertNotSame( 'sensitive-value', $encrypted );
		$this->assertSame( 'sensitive-value', Util::encryption()->decrypt( $encrypted ) );
	}

	/**
	 * Tampered ciphertext fails authentication and returns false (no throw).
	 */
	public function test_decrypt_returns_false_for_tampered_value(): void {
		if ( ! defined( 'AMC_AUTOMATA_FEATURES_FEATURES_ENCRYPTION_KEY' ) ) {
			define( 'AMC_AUTOMATA_FEATURES_FEATURES_ENCRYPTION_KEY', str_repeat( 'k', 32 ) );
		}

		$encrypted = Util::encryption()->encrypt( 'secret' );
		$this->assertIsString( $encrypted );

		$decoded     = base64_decode( $encrypted, true );
		$decoded[20] = 'A' === $decoded[20] ? 'B' : 'A';

		$this->assertFalse( Util::encryption()->decrypt( base64_encode( $decoded ) ) );
	}
}
