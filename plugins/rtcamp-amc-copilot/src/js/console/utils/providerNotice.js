/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Message for the Vulnerabilities screen about the CVE provider, or null when
 * it is configured, ready and error-free.
 *
 * The plugin ships a placeholder provider with the id `null`. When it is the
 * current provider, nothing is configured at all, which is different from a
 * real provider that is not ready yet.
 *
 * @param {{id: string, ready: boolean, last_error: string|null}} provider CVE provider state.
 * @return {string|null} Message.
 */
export const providerNotice = ( provider ) => {
	if ( ! provider ) {
		return null;
	}
	if ( provider.last_error ) {
		return sprintf(
			/* translators: %s: Error message from the CVE provider. */
			__(
				'The vulnerability provider reported an error: %s',
				'rtcamp-amc-copilot'
			),
			provider.last_error
		);
	}
	if ( 'null' === provider.id ) {
		return __(
			'No vulnerability provider is configured, so plugins, themes and core are not being checked for known vulnerabilities. An empty list here does not mean the site is safe.',
			'rtcamp-amc-copilot'
		);
	}
	if ( ! provider.ready ) {
		return __(
			'The CVE provider is not ready, so no vulnerability data is being matched.',
			'rtcamp-amc-copilot'
		);
	}
	return null;
};
