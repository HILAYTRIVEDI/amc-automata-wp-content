/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Load a resource through an `api/` function.
 *
 * @param {(...args: unknown[]) => unknown} fetcher Async function returning the data. Wrap it in
 *                                                  `useCallback` when it depends on props or state.
 * @return {{data: unknown, error: Error|null, isLoading: boolean, reload: () => Promise<void>, setData: (data: unknown) => void}} State.
 */
export default function useResource( fetcher ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const latest = useRef( 0 );

	const reload = useCallback( () => {
		const ticket = ++latest.current;
		setIsLoading( true );
		return fetcher()
			.then( ( result ) => {
				if ( ticket === latest.current ) {
					setData( result );
					setError( null );
				}
			} )
			.catch( ( err ) => {
				if ( ticket === latest.current ) {
					setError( err );
				}
			} )
			.finally( () => {
				if ( ticket === latest.current ) {
					setIsLoading( false );
				}
			} );
	}, [ fetcher ] );

	useEffect( () => {
		reload();
		const counter = latest;
		return () => {
			// Invalidate in-flight requests on unmount or when the fetcher changes.
			counter.current++;
		};
	}, [ reload ] );

	return { data, error, isLoading, reload, setData };
}
