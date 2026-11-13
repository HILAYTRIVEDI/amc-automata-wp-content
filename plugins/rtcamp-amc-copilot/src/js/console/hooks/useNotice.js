/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';

/**
 * Inline, dismissible notice for action feedback.
 *
 * @return {{node: Element|null, success: (message: string) => void, error: (message: string) => void}} Notice helpers.
 */
export default function useNotice() {
	const [ notice, setNotice ] = useState( null );
	const success = useCallback(
		( message ) => setNotice( { status: 'success', message } ),
		[]
	);
	const error = useCallback(
		( message ) => setNotice( { status: 'error', message } ),
		[]
	);
	const node = notice ? (
		<Notice
			className="amc-notice"
			status={ notice.status }
			onRemove={ () => setNotice( null ) }
		>
			{ notice.message }
		</Notice>
	) : null;
	return { node, success, error };
}
