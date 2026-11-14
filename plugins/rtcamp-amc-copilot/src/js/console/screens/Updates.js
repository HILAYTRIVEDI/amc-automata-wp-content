/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getUpdates } from '../api';
import { CheckPanels } from '../components/CheckPanel';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import { SCREEN_CHECKS } from '../constants';
import useResource from '../hooks/useResource';

/**
 * Updates screen (`GET /updates`): core, plugin, theme and PHP updates, and
 * the major-upgrade readiness matrix (`updates.readiness`). View-only;
 * updates are applied elsewhere (Phase 2). Result data is rendered
 * generically (see `CheckData`), so new fields from lane B1 appear without
 * console changes.
 *
 * @return {Element} Screen.
 */
export default function Updates() {
	const { data, error, isLoading, reload } = useResource( getUpdates );

	return (
		<ScreenState
			screen="updates"
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.updates }
					variant="primary"
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
					ids={ SCREEN_CHECKS.updates }
					checks={ data.checks }
				/>
			) }
		</ScreenState>
	);
}
