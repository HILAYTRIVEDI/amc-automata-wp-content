/**
 * WordPress dependencies
 */
import { useId } from '@wordpress/element';
import { Icon, chevronDown, chevronRight } from '@wordpress/icons';

/**
 * Core admin box (`postbox`, as on the Dashboard and the post editor's meta
 * boxes): a titled header with optional actions on the right, and content.
 * Not draggable, so the handle is marked `is-non-sortable`.
 *
 * With `collapsible`, the title is a disclosure button (`aria-expanded`)
 * that shows or hides the content, and `subtitle` stays visible in the
 * header so a closed box still says what it holds.
 *
 * @param {Object}                    props
 * @param {string}                    props.title          Box title.
 * @param {number}                    [props.headingLevel] Heading level of the title (default 3).
 * @param {Element}                   [props.actions]      Content on the right of the header (status, buttons).
 * @param {string}                    [props.className]    Extra class.
 * @param {boolean}                   [props.collapsible]  Whether the content can be hidden.
 * @param {boolean}                   [props.isOpen]       Whether the content is shown (collapsible boxes).
 * @param {(isOpen: boolean) => void} [props.onToggle]     Called with the new state.
 * @param {Element}                   [props.subtitle]     One line under the title, always visible.
 * @param {Element}                   props.children       Box content.
 * @return {Element} Box.
 */
export default function Postbox( {
	title,
	headingLevel = 3,
	actions,
	className,
	collapsible = false,
	isOpen = true,
	onToggle,
	subtitle,
	children,
} ) {
	const Heading = `h${ headingLevel }`;
	const id = `amc-postbox-${ useId().replace( /[^a-zA-Z0-9_-]/g, '' ) }`;
	const open = ! collapsible || isOpen;
	const classes = [
		'postbox',
		'amc-postbox',
		collapsible ? 'is-collapsible' : '',
		open ? '' : 'is-closed',
		className ?? '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className={ classes }>
			<div className="postbox-header">
				<div className="amc-postbox-heading">
					<Heading className="hndle is-non-sortable">
						{ collapsible ? (
							<button
								type="button"
								className="amc-postbox-toggle"
								aria-expanded={ open }
								aria-controls={ `${ id }-content` }
								onClick={ () => onToggle?.( ! open ) }
							>
								<Icon
									icon={ open ? chevronDown : chevronRight }
									size={ 20 }
								/>
								<span>{ title }</span>
							</button>
						) : (
							title
						) }
					</Heading>
					{ subtitle && (
						<p className="amc-postbox-subtitle">{ subtitle }</p>
					) }
				</div>
				{ actions && (
					<div className="handle-actions amc-postbox-actions">
						{ actions }
					</div>
				) }
			</div>
			<div className="inside" id={ `${ id }-content` } hidden={ ! open }>
				{ open && children }
			</div>
		</div>
	);
}
