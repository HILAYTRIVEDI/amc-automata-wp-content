/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getInventory } from '../api';
import { CheckPanels } from '../components/CheckPanel';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import { SCREEN_CHECKS } from '../constants';
import useResource from '../hooks/useResource';

const TABS = () => [
	{
		name: 'plugins',
		title: __( 'Plugins', 'rtcamp-amc-copilot' ),
		ids: [ 'inventory.plugins' ],
	},
	{
		name: 'themes',
		title: __( 'Themes', 'rtcamp-amc-copilot' ),
		ids: [ 'inventory.themes' ],
	},
	{
		name: 'mu',
		title: __( 'Must-use and drop-ins', 'rtcamp-amc-copilot' ),
		ids: [ 'inventory.mu_dropins' ],
	},
	{
		name: 'editor',
		title: __( 'Editor', 'rtcamp-amc-copilot' ),
		ids: [ 'inventory.editor' ],
	},
	{
		name: 'hosting',
		title: __( 'Hosting', 'rtcamp-amc-copilot' ),
		ids: [ 'hosting.platform' ],
	},
	{
		name: 'detections',
		title: __( 'Detections', 'rtcamp-amc-copilot' ),
		ids: [
			'detect.cdn',
			'detect.forms',
			'detect.mailer',
			'detect.analytics',
			'detect.accessibility',
		],
	},
];

/**
 * Inventory screen (`GET /inventory`): plugins, themes, must-use plugins and
 * drop-ins, editor type, hosting and detections. Every table exports to CSV.
 *
 * @return {Element} Screen.
 */
export default function Inventory() {
	const { data, error, isLoading, reload } = useResource( getInventory );
	const tabs = TABS();

	return (
		<ScreenState
			title={ __( 'Inventory', 'rtcamp-amc-copilot' ) }
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.inventory }
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
				<TabPanel className="amc-tabs" tabs={ tabs }>
					{ ( current ) => (
						<CheckPanels
							ids={
								tabs.find(
									( tab ) => tab.name === current.name
								).ids
							}
							checks={ data.checks }
						/>
					) }
				</TabPanel>
			) }
		</ScreenState>
	);
}
