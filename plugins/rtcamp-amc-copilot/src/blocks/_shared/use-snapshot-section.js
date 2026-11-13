/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

/**
 * Values of one snapshot section for the report being edited.
 *
 * Reads the `_amc_snapshot` post meta (JSON string, see
 * docs/report-snapshot.md) of the current post from the editor store, so
 * there is no extra REST request.
 *
 * @param {string} section Section key, e.g. 'overview'.
 * @param {Object} context Block context ({ postId, postType }).
 * @return {Object} field => value (empty object when there is no snapshot).
 */
export default function useSnapshotSection( section, context = {} ) {
	const { postId, postType } = context;

	const raw = useSelect(
		( select ) => {
			if ( ! postId || ! postType ) {
				return '';
			}
			const record = select( 'core' ).getEditedEntityRecord(
				'postType',
				postType,
				postId
			);
			return record?.meta?._amc_snapshot ?? '';
		},
		[ postId, postType ]
	);

	return useMemo( () => {
		if ( ! raw ) {
			return {};
		}
		try {
			return JSON.parse( raw )?.sections?.[ section ] ?? {};
		} catch {
			return {};
		}
	}, [ raw, section ] );
}
