/**
 * Compare a run's data with the previous result of the same check, so the
 * Run detail page can show what changed between two runs.
 *
 * Works on dot paths down to `maxDepth`; deeper values, and lists, are
 * compared as a whole and reported with a short description ("3 items").
 */

const isPlainObject = ( value ) =>
	null !== value && 'object' === typeof value && ! Array.isArray( value );

/**
 * Short description of a value for the diff table.
 *
 * @param {unknown} value Value.
 * @return {string} Description.
 */
export const describeValue = ( value ) => {
	if ( undefined === value ) {
		return '—';
	}
	if ( null === value ) {
		return 'null';
	}
	if ( Array.isArray( value ) ) {
		return 1 === value.length ? '1 item' : `${ value.length } items`;
	}
	if ( isPlainObject( value ) ) {
		const keys = Object.keys( value ).length;
		return 1 === keys ? '1 field' : `${ keys } fields`;
	}
	return String( value );
};

/**
 * Changed values between two data objects.
 *
 * @param {Object|null|undefined} before     Previous run's data.
 * @param {Object|null|undefined} after      This run's data.
 * @param {number}                [maxDepth] Object depth to walk (default 2).
 * @return {Array<{path: string, kind: 'added'|'removed'|'changed', before: unknown, after: unknown}>} Changes, in key order.
 */
export const diffRunData = ( before, after, maxDepth = 2 ) => {
	const changes = [];

	const walk = ( a, b, path, depth ) => {
		const keys = [
			...new Set( [
				...Object.keys( a ?? {} ),
				...Object.keys( b ?? {} ),
			] ),
		];

		for ( const key of keys ) {
			const at = path ? `${ path }.${ key }` : key;
			const inA = !! a && Object.prototype.hasOwnProperty.call( a, key );
			const inB = !! b && Object.prototype.hasOwnProperty.call( b, key );
			const va = inA ? a[ key ] : undefined;
			const vb = inB ? b[ key ] : undefined;

			if ( ! inA ) {
				changes.push( {
					path: at,
					kind: 'added',
					before: va,
					after: vb,
				} );
				continue;
			}
			if ( ! inB ) {
				changes.push( {
					path: at,
					kind: 'removed',
					before: va,
					after: vb,
				} );
				continue;
			}
			if (
				isPlainObject( va ) &&
				isPlainObject( vb ) &&
				depth < maxDepth
			) {
				walk( va, vb, at, depth + 1 );
				continue;
			}
			if ( JSON.stringify( va ) !== JSON.stringify( vb ) ) {
				changes.push( {
					path: at,
					kind: 'changed',
					before: va,
					after: vb,
				} );
			}
		}
	};

	walk( before ?? {}, after ?? {}, '', 1 );

	return changes;
};
