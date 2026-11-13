<?php
/**
 * Tests for the PHP 8.2 polyfills.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Tests\Compat;

use AMC\Tests\TestCase;

/**
 * Class Php82PolyfillsTest
 */
final class Php82PolyfillsTest extends TestCase {

	public function test_cipher_key_length_for_gcm_ciphers(): void {
		$this->assertSame( 16, openssl_cipher_key_length( 'aes-128-gcm' ) );
		$this->assertSame( 24, openssl_cipher_key_length( 'aes-192-gcm' ) );
		$this->assertSame( 32, openssl_cipher_key_length( 'aes-256-gcm' ) );
		$this->assertSame( 32, openssl_cipher_key_length( 'AES-256-GCM' ) );
	}

	public function test_unknown_cipher_is_false(): void {
		// PHP 8.2+ also emits a warning for unknown ciphers; silence it so both paths compare equal.
		$this->assertFalse( @openssl_cipher_key_length( 'not-a-cipher' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core warns on unknown ciphers.
	}
}
