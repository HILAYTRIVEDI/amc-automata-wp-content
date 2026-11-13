/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getChecks } from '../api';
import useResource from '../hooks/useResource';
import RunNowButton from './RunNowButton';

/**
 * "Run check" button for a screen. It queues only the checks that are
 * registered on this site, so a check from a lane that has not shipped yet
 * is never requested (that would be a 404 `amc_unknown_check`).
 *
 * @param {Object}                          props
 * @param {string[]}                        props.checkIds     Check ids the screen wants to run.
 * @param {string}                          [props.label]      Button text.
 * @param {string}                          [props.variant]    Button variant.
 * @param {(...args: unknown[]) => unknown} [props.onComplete] Called with the finished runs.
 * @return {Element} Button.
 */
export default function RunChecksButton( {
	checkIds,
	label = __( 'Run check', 'rtcamp-amc-copilot' ),
	variant = 'secondary',
	onComplete,
} ) {
	const { data, isLoading } = useResource( getChecks );

	const available = useMemo(
		() =>
			( data ?? [] )
				.filter( ( check ) => checkIds.includes( check.id ) )
				.map( ( check ) => check.id ),
		[ data, checkIds ]
	);

	if ( isLoading || ! available.length ) {
		return (
			<Button variant={ variant } disabled accessibleWhenDisabled>
				{ isLoading
					? label
					: __( 'Check not available yet', 'rtcamp-amc-copilot' ) }
			</Button>
		);
	}

	return (
		<RunNowButton
			checkIds={ available }
			label={ label }
			variant={ variant }
			onComplete={ onComplete }
		/>
	);
}
