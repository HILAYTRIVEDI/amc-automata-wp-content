<?php
/**
 * Plugin encryption service.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Core;

use rtCamp\WPPrimitives\Contracts\Interfaces\Shareable;
use rtCamp\WPPrimitives\Utils\Encryptor;

/**
 * Class - Encryption
 *
 * The plugin's shared Encryptor. Extends the framework Encryptor and sources the
 * key via the key() seam from the AMC_COPILOT_ENCRYPTION_KEY constant;
 * define it in wp-config.php before using encryption. There is deliberately no
 * salt fallback: an auth salt should not double as an encryption key, and
 * rotating WordPress salts must not invalidate encrypted data. Shared through
 * the container; access it via Helpers\Util::encryption(), e.g.
 * Util::encryption()->encrypt( $value ) / Util::encryption()->decrypt( $value ).
 */
final class Encryption extends Encryptor implements Shareable {

	/**
	 * Resolve the plugin's encryption key.
	 *
	 * @return string The encryption key.
	 *
	 * @throws \RuntimeException If AMC_COPILOT_ENCRYPTION_KEY is not defined or is empty.
	 */
	protected function key(): string {
		if ( defined( 'AMC_COPILOT_ENCRYPTION_KEY' ) && '' !== AMC_COPILOT_ENCRYPTION_KEY ) {
			return (string) AMC_COPILOT_ENCRYPTION_KEY;
		}

		return parent::key();
	}
}
