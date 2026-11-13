/**
 * WordPress dependencies
 */
import { Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isNotImplemented } from '../api';

/**
 * Notice for a request that failed: a calm "not available yet" for routes
 * another lane has not built, an error otherwise.
 *
 * @param {Object}                          props
 * @param {Error}                           props.error     Request error.
 * @param {(...args: unknown[]) => unknown} [props.onRetry] Retry handler.
 * @return {Element} Notice.
 */
export function ErrorNotice( { error, onRetry } ) {
	if ( isNotImplemented( error ) ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ error.owner
					? sprintf(
							/* translators: %s: lane id, e.g. D1. */
							__(
								'This is not available yet. It is still being built (lane %s).',
								'rtcamp-amc-copilot'
							),
							error.owner
						)
					: __(
							'This is not available yet. It is still being built.',
							'rtcamp-amc-copilot'
						) }
			</Notice>
		);
	}

	return (
		<Notice
			status="error"
			isDismissible={ false }
			actions={
				onRetry
					? [
							{
								label: __( 'Retry', 'rtcamp-amc-copilot' ),
								onClick: onRetry,
							},
						]
					: []
			}
		>
			{ error.message ||
				__( 'The request failed.', 'rtcamp-amc-copilot' ) }
		</Notice>
	);
}

/**
 * Notice for a screen whose route still returns placeholder data
 * (`X-AMC-Stub: <lane>`).
 *
 * @param {Object} props
 * @param {string} props.lane Lane that owns the route.
 * @return {Element|null} Notice.
 */
export function StubNotice( { lane } ) {
	if ( ! lane ) {
		return null;
	}
	return (
		<Notice status="info" isDismissible={ false }>
			{ sprintf(
				/* translators: %s: lane id, e.g. B2. */
				__(
					'Some of this is placeholder data until lane %s ships it.',
					'rtcamp-amc-copilot'
				),
				lane
			) }
		</Notice>
	);
}

/**
 * Screen frame: heading, actions, and the loading and error states of the
 * screen's main request.
 *
 * @param {Object}                          props
 * @param {string}                          props.title     Heading.
 * @param {Element}                         [props.actions] Buttons shown next to the heading.
 * @param {boolean}                         props.isLoading Loading state.
 * @param {Error}                           [props.error]   Request error.
 * @param {(...args: unknown[]) => unknown} [props.onRetry] Retry handler for the error notice.
 * @param {boolean}                         props.hasData   Whether data has loaded.
 * @param {string}                          [props.stub]    Lane stubbing part of the data.
 * @param {Element}                         props.children  Screen content, rendered once data exists.
 * @return {Element} Frame.
 */
export default function ScreenState( {
	title,
	actions,
	isLoading,
	error,
	onRetry,
	hasData,
	stub,
	children,
} ) {
	return (
		<section className="amc-screen" aria-labelledby="amc-screen-title">
			<div className="amc-screen-head">
				<h2
					id="amc-screen-title"
					className="wp-heading-inline"
					tabIndex={ -1 }
				>
					{ title }
				</h2>
				<div className="amc-screen-actions">{ actions }</div>
			</div>
			{ error && <ErrorNotice error={ error } onRetry={ onRetry } /> }
			<StubNotice lane={ stub } />
			{ isLoading && ! hasData && ! error && (
				<p role="status">
					<Spinner />{ ' ' }
					<span className="screen-reader-text">
						{ __( 'Loading…', 'rtcamp-amc-copilot' ) }
					</span>
				</p>
			) }
			{ hasData && children }
		</section>
	);
}
