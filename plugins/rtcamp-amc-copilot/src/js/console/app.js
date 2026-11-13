/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Notice } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

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
import {
	navigate,
	screenUrl,
	useFocusHeadingOnClose,
	useLocation,
} from './utils/navigation';

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
	const firstScreen = useRef( true );
	useFocusHeadingOnClose( ! isFindingLink && !! finding );

	// Switching screens does not reload the page, so say where we are. Not on
	// the first render: the page load itself is announced by the browser.
	useEffect( () => {
		if ( firstScreen.current ) {
			firstScreen.current = false;
			return;
		}
		const label = screens.find( ( item ) => item.slug === current )?.label;
		if ( label ) {
			speak(
				sprintf(
					/* translators: %s: screen name, e.g. Updates. */
					__( '%s screen', 'rtcamp-amc-copilot' ),
					label
				),
				'polite'
			);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ current ] );

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
