/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getSecurity } from '../api';
import { CheckPanels } from '../components/CheckPanel';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import { SCREEN_CHECKS } from '../constants';
import useResource from '../hooks/useResource';

/**
 * Security screen (`GET /security`): core checksums, configuration checks,
 * administrators and changes, and SSL details.
 *
 * @return {Element} Screen.
 */
export default function Security() {
	const { data, error, isLoading, reload } = useResource( getSecurity );

	return (
		<ScreenState
			title={ __( 'Security', 'rtcamp-amc-copilot' ) }
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.security }
					label={ __( 'Run check', 'rtcamp-amc-copilot' ) }
					onComplete={ reload }
				/>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
		>
			{ data && (
				<CheckPanels
					ids={ SCREEN_CHECKS.security }
					checks={ data.checks }
				/>
			) }
		</ScreenState>
	);
}
