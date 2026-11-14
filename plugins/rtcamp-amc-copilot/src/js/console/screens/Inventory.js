/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
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
	const tabsRef = useRef( null );
	const hasData = !! data;

	// TabPanel has no prop for the tab list's name; without one a screen reader
	// announces an unnamed "tab list".
	useEffect( () => {
		tabsRef.current
			?.querySelector( '[role="tablist"]' )
			?.setAttribute(
				'aria-label',
				__( 'Inventory sections', 'rtcamp-amc-copilot' )
			);
	}, [ hasData ] );

	return (
		<ScreenState
			screen="inventory"
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.inventory }
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
				<TabPanel className="amc-tabs" tabs={ tabs } ref={ tabsRef }>
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
