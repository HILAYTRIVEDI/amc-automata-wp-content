/**
 * Core admin box (`postbox`, as on the Dashboard and the post editor's meta
 * boxes): a titled header with optional actions on the right, and content.
 * Not draggable, so the handle is marked `is-non-sortable`.
 *
 * @param {Object}  props
 * @param {string}  props.title          Box title.
 * @param {number}  [props.headingLevel] Heading level of the title (default 3).
 * @param {Element} [props.actions]      Content on the right of the header (status, buttons).
 * @param {string}  [props.className]    Extra class.
 * @param {Element} props.children       Box content.
 * @return {Element} Box.
 */
export default function Postbox( {
	title,
	headingLevel = 3,
	actions,
	className,
	children,
} ) {
	const Heading = `h${ headingLevel }`;
	return (
		<div
			className={ `postbox amc-postbox${ className ? ` ${ className }` : '' }` }
		>
			<div className="postbox-header">
				<Heading className="hndle is-non-sortable">{ title }</Heading>
				{ actions && (
					<div className="handle-actions amc-postbox-actions">
						{ actions }
					</div>
				) }
			</div>
			<div className="inside">{ children }</div>
		</div>
	);
}
