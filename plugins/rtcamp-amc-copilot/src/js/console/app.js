/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isMockMode } from './api';
import FindingModal from './components/FindingModal';
import FindingRedirect from './components/FindingRedirect';
import { getScreens } from './constants';
import Changes from './screens/Changes';
import Errors from './screens/Errors';
import Inventory from './screens/Inventory';
import Overview from './screens/Overview';
import Performance from './screens/Performance';
import Reports from './screens/Reports';
import Runs from './screens/Runs';
import Security from './screens/Security';
import Settings from './screens/Settings';
import Smoke from './screens/Smoke';
import Updates from './screens/Updates';
import Vulnerabilities from './screens/Vulnerabilities';
import { navigate, screenUrl, useLocation } from './utils/navigation';

const COMPONENTS = {
	overview: Overview,
	updates: Updates,
	vulnerabilities: Vulnerabilities,
	errors: Errors,
	smoke: Smoke,
	security: Security,
	performance: Performance,
	inventory: Inventory,
	changes: Changes,
	runs: Runs,
	reports: Reports,
	settings: Settings,
};

/**
 * Console shell: heading, screen navigation and the active screen. The screen
 * comes from the `screen` query arg (`?page=amc-copilot&screen=updates`).
 *
 * Two deep links are handled here:
 * - `screen=findings&finding=<id>` (alert emails) redirects to the screen of
 *   the finding's check group;
 * - `finding=<id>` on any screen opens that finding with the run behind it.
 *
 * @return {Element} App.
 */
export default function App() {
	const { screen, finding } = useLocation();
	const screens = getScreens();
	const isFindingLink = 'findings' === screen;
	const current = COMPONENTS[ screen ] ? screen : 'overview';
	const Screen = COMPONENTS[ current ];

	return (
		<div className="amc-console">
			<h1 className="wp-heading-inline">
				{ __( 'AMC Copilot', 'rtcamp-amc-copilot' ) }
			</h1>
			<hr className="wp-header-end" />
			{ isMockMode() && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Showing sample data. The REST API is not connected.',
						'rtcamp-amc-copilot'
					) }
				</Notice>
			) }
			<nav
				className="nav-tab-wrapper"
				aria-label={ __( 'AMC Copilot screens', 'rtcamp-amc-copilot' ) }
			>
				{ screens.map( ( item ) => (
					<a
						key={ item.slug }
						className={ `nav-tab${ ! isFindingLink && item.slug === current ? ' nav-tab-active' : '' }` }
						href={ screenUrl( item.slug ) }
						aria-current={
							! isFindingLink && item.slug === current
								? 'page'
								: undefined
						}
						onClick={ ( event ) => {
							if (
								event.metaKey ||
								event.ctrlKey ||
								event.shiftKey
							) {
								return;
							}
							event.preventDefault();
							navigate( item.slug );
						} }
					>
						{ item.label }
					</a>
				) ) }
			</nav>
			{ isFindingLink ? (
				<FindingRedirect findingId={ finding } />
			) : (
				<Screen key={ current } />
			) }
			{ ! isFindingLink && finding && (
				<FindingModal
					key={ finding }
					findingId={ Number.parseInt( finding, 10 ) }
					onClose={ () => navigate( current ) }
				/>
			) }
		</div>
	);
}
