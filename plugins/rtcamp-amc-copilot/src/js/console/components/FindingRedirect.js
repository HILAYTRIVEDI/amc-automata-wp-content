/**
 * WordPress dependencies
 */
import { Spinner } from '@wordpress/components';
import { useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getFinding } from '../api';
import useResource from '../hooks/useResource';
import { screenForFinding } from '../utils/findingScreen';
import { navigate } from '../utils/navigation';
import { ErrorNotice } from './ScreenState';

/**
 * Handles the alert-email deep link
 * `?page=amc-copilot&screen=findings&finding=<id>`. There is no "findings"
 * screen, so it looks the finding up (`GET /findings/{id}`), switches to the
 * screen of its check group and leaves `finding=<id>` in the URL, which opens
 * the finding modal there.
 *
 * @param {Object} props
 * @param {string} props.findingId Finding id from the URL.
 * @return {Element} Loading or error state while redirecting.
 */
export default function FindingRedirect( { findingId } ) {
	const id = Number.parseInt( findingId, 10 );
	const fetcher = useCallback(
		() =>
			Number.isFinite( id )
				? getFinding( id )
				: Promise.reject(
						new Error(
							__(
								'This link has no finding id.',
								'rtcamp-amc-copilot'
							)
						)
					),
		[ id ]
	);
	const { data, error } = useResource( fetcher );

	useEffect( () => {
		if ( data ) {
			navigate(
				screenForFinding( data ),
				{ finding: data.id },
				{ replace: true }
			);
		}
	}, [ data ] );

	if ( error ) {
		return <ErrorNotice error={ error } />;
	}

	return (
		<p>
			<Spinner />{ ' ' }
			<span className="screen-reader-text">
				{ __( 'Opening the finding…', 'rtcamp-amc-copilot' ) }
			</span>
		</p>
	);
}
