<?php
/**
 * PHP 8.2 function polyfills for PHP 8.1.
 *
 * The plugin supports PHP 8.1 (locked decision), but rtcamp/wp-primitives'
 * Encryptor calls openssl_cipher_key_length(), which only exists from PHP 8.2.
 * Loaded through Composer's "files" autoload, so it is in place before any
 * vendor code runs. Remove when the PHP floor becomes 8.2.
 *
 * @package AMC
 */

declare( strict_types = 1 );

if ( ! function_exists( 'openssl_cipher_key_length' ) ) {

	/**
	 * Key length in bytes for an OpenSSL cipher.
	 *
	 * Covers the AES/ARIA/Camellia families (the key size is in the name) and
	 * ChaCha20. Returns false for ciphers it does not know, like PHP 8.2 does
	 * for unknown ciphers.
	 *
	 * @param string $cipher_algo Cipher name, e.g. 'aes-256-gcm'.
	 */
	function openssl_cipher_key_length( string $cipher_algo ): int|false { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Polyfill of a PHP core function; the name must match core.
		$cipher = strtolower( $cipher_algo );

		if ( ! in_array( $cipher, openssl_get_cipher_methods(), true ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^(?:id-)?(?:aes|aria|camellia)-?(128|192|256)/', $cipher, $matches ) ) {
			return intdiv( (int) $matches[1], 8 );
		}

		if ( str_starts_with( $cipher, 'chacha20' ) ) {
			return 32;
		}

		return false;
	}
}
