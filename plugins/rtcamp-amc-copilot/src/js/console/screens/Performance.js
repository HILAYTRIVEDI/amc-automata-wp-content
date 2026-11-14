/**
 * WordPress dependencies
 */
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getPerformance } from '../api';
import { CheckPanels } from '../components/CheckPanel';
import DataTable from '../components/DataTable';
import Postbox from '../components/Postbox';
import RunChecksButton from '../components/RunChecksButton';
import ScreenState from '../components/ScreenState';
import Sparkline from '../components/Sparkline';
import { SCREEN_CHECKS } from '../constants';
import useResource from '../hooks/useResource';
import { dateField, numberField, textField } from '../utils/fields';
import { formatMs } from '../utils/format';

/**
 * Performance screen (`GET /performance`): response time trend, database and
 * autoload size, cache status and slow queries by caller.
 *
 * @return {Element} Screen.
 */
export default function Performance() {
	const { data, error, isLoading, reload } = useResource( getPerformance );

	const trendFields = useMemo(
		() => [
			textField( 'date', __( 'Date', 'rtcamp-amc-copilot' ) ),
			numberField(
				'avg_ms',
				__( 'Average response', 'rtcamp-amc-copilot' ),
				{
					render: ( { item } ) => (
						<span>{ formatMs( item.avg_ms ) }</span>
					),
				}
			),
			numberField(
				'url_count',
				__( 'URLs measured', 'rtcamp-amc-copilot' )
			),
		],
		[]
	);

	const queryFields = useMemo(
		() => [
			textField( 'caller', __( 'Caller', 'rtcamp-amc-copilot' ) ),
			textField(
				'sql_sample',
				__( 'Query sample', 'rtcamp-amc-copilot' )
			),
			numberField( 'count', __( 'Samples', 'rtcamp-amc-copilot' ) ),
			numberField( 'avg_ms', __( 'Average', 'rtcamp-amc-copilot' ), {
				type: 'number',
				render: ( { item } ) => (
					<span>{ formatMs( item.avg_ms ) }</span>
				),
			} ),
			numberField( 'max_ms', __( 'Max', 'rtcamp-amc-copilot' ), {
				type: 'number',
				render: ( { item } ) => (
					<span>{ formatMs( item.max_ms ) }</span>
				),
			} ),
			dateField( 'last_seen', __( 'Last seen', 'rtcamp-amc-copilot' ) ),
		],
		[]
	);

	return (
		<ScreenState
			screen="performance"
			actions={
				<RunChecksButton
					checkIds={ SCREEN_CHECKS.performance }
					variant="primary"
					label={ __( 'Run check', 'rtcamp-amc-copilot' ) }
					onComplete={ reload }
				/>
			}
			isLoading={ isLoading }
			error={ error }
			onRetry={ reload }
			hasData={ !! data }
			stub={ data?.stub }
		>
			{ data && (
				<>
					<Postbox
						className="amc-check-panel"
						title={ __(
							'Response time trend',
							'rtcamp-amc-copilot'
						) }
					>
						{ data.response_trend.length ? (
							<>
								<p className="amc-trend">
									<Sparkline
										values={ data.response_trend.map(
											( point ) => point.avg_ms
										) }
										width={ 320 }
										height={ 56 }
										unit=" ms"
										label={ __(
											'Average response time per day',
											'rtcamp-amc-copilot'
										) }
									/>
								</p>
								<DataTable
									data={ data.response_trend }
									fields={ trendFields }
									getItemId={ ( item ) => item.date }
									exportName="amc-performance-trend"
									label={ __(
										'Response time by day',
										'rtcamp-amc-copilot'
									) }
									initialView={ { perPage: 7 } }
								/>
							</>
						) : (
							<p>
								{ __(
									'No response time data yet.',
									'rtcamp-amc-copilot'
								) }
							</p>
						) }
					</Postbox>

					<CheckPanels
						ids={ SCREEN_CHECKS.performance.filter(
							( id ) => 'perf.slow_queries' !== id
						) }
						checks={ data.checks }
					/>

					<Postbox
						title={ __(
							'Slow queries by caller',
							'rtcamp-amc-copilot'
						) }
					>
						<p className="description">
							{ __(
								'Sampled on 1 in 100 requests; only queries over 100 ms are kept.',
								'rtcamp-amc-copilot'
							) }
						</p>
						<DataTable
							data={ data.slow_queries }
							fields={ queryFields }
							getItemId={ ( item ) => item.signature }
							exportName="amc-performance-slow-queries"
							label={ __(
								'Slow queries by caller',
								'rtcamp-amc-copilot'
							) }
							emptyText={ __(
								'No slow queries sampled.',
								'rtcamp-amc-copilot'
							) }
						/>
					</Postbox>
				</>
			) }
		</ScreenState>
	);
}
