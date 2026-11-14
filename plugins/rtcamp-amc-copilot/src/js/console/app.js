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
import {
	getScreen,
	getSections,
	sectionFor,
	STANDALONE_SCREENS,
} from './constants';
import Changes from './screens/Changes';
import Errors from './screens/Errors';
import Inventory from './screens/Inventory';
import Overview from './screens/Overview';
import Performance from './screens/Performance';
import Reports from './screens/Reports';
import Runs from './screens/Runs';
import Security from './screens/Security';
import Settings from './screens/Settings';
import Setup from './screens/Setup';
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
	setup: Setup,
};

/**
 * A navigation link to a screen that switches screens without reloading
 * the page (modified clicks still open a new tab).
 *
 * @param {Object}  props
 * @param {string}  props.screen    Screen slug.
 * @param {string}  props.className Link class.
 * @param {string}  [props.current] `aria-current` value when this link is the current one.
 * @param {Element} props.children  Link text.
 * @return {Element} Link.
 */
function ScreenLink( { screen, className, current, children } ) {
	return (
		<a
			className={ className }
			href={ screenUrl( screen ) }
			aria-current={ current }
			onClick={ ( event ) => {
				if ( event.metaKey || event.ctrlKey || event.shiftKey ) {
					return;
				}
				event.preventDefault();
				navigate( screen );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * Console shell: page heading, section tabs (core `nav-tab-wrapper`), the
 * section's screens as core `subsubsub` links when it has more than one, and
 * the active screen. The screen comes from the `screen` query arg
 * (`?page=amc-copilot&screen=updates`), so existing links keep working.
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
	const isFindingLink = 'findings' === screen;
	const current = COMPONENTS[ screen ] ? screen : 'overview';
	const Screen = COMPONENTS[ current ];
	const section = sectionFor( current );
	// The setup wizard is a focused flow: no section tabs.
	const showNav = ! STANDALONE_SCREENS.includes( current );
	const firstScreen = useRef( true );
	useFocusHeadingOnClose( ! isFindingLink && !! finding );

	// Switching screens does not reload the page, so say where we are. Not on
	// the first render: the page load itself is announced by the browser.
	useEffect( () => {
		if ( firstScreen.current ) {
			firstScreen.current = false;
			return;
		}
		const label = getScreen( current )?.label;
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
			{ showNav && (
				<nav
					className="nav-tab-wrapper amc-nav"
					aria-label={ __(
						'AMC Copilot screens',
						'rtcamp-amc-copilot'
					) }
				>
					{ getSections().map( ( item ) => {
						const active =
							! isFindingLink && item.slug === section.slug;
						let ariaCurrent;
						if ( active ) {
							// The tab is the page itself only when the section has one screen.
							ariaCurrent =
								1 === item.screens.length ? 'page' : 'true';
						}
						return (
							<ScreenLink
								key={ item.slug }
								screen={ item.screens[ 0 ] }
								className={ `nav-tab${ active ? ' nav-tab-active' : '' }` }
								current={ ariaCurrent }
							>
								{ item.label }
							</ScreenLink>
						);
					} ) }
				</nav>
			) }
			{ showNav && ! isFindingLink && section.screens.length > 1 && (
				<nav
					className="amc-subnav"
					aria-label={ sprintf(
						/* translators: %s: section name, e.g. Checks. */
						__( '%s screens', 'rtcamp-amc-copilot' ),
						section.label
					) }
				>
					<ul className="subsubsub">
						{ section.screens.map( ( slug, index ) => (
							<li key={ slug }>
								<ScreenLink
									screen={ slug }
									className={
										slug === current ? 'current' : undefined
									}
									current={
										slug === current ? 'page' : undefined
									}
								>
									{ getScreen( slug ).label }
								</ScreenLink>
								{ index < section.screens.length - 1 && (
									<span aria-hidden="true">
										{ ' |\u00a0' }
									</span>
								) }
							</li>
						) ) }
					</ul>
					<div className="clear" />
				</nav>
			) }
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
