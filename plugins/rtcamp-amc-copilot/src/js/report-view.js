/**
 * Report View Client Script.
 *
 * Handles client-side actions on the single report page (ClientView):
 * - Download PDF via browser print dialog.
 * - Send maintenance report to client with recipient preview and confirmation modal.
 *
 * @package
 */

document.addEventListener( 'DOMContentLoaded', () => {
	// 1. Download PDF button handler.
	document.addEventListener( 'click', ( e ) => {
		const target = e.target;
		if ( ! target || ! ( target instanceof window.Element ) ) {
			return;
		}

		const btn = target.closest( '[data-amc-download]' );
		if ( ! btn ) {
			return;
		}

		const originalTitle = document.title;
		const downloadName = btn.getAttribute( 'data-amc-download' );

		if ( downloadName ) {
			document.title = downloadName;
		}

		window.print();

		setTimeout( () => {
			document.title = originalTitle;
		}, 1000 );
	} );

	// 2. Send to client button handler.
	const sendBtn = document.getElementById( 'amc-send-to-client' );
	if ( ! sendBtn || typeof window.amcReportViewData === 'undefined' ) {
		return;
	}

	const data = window.amcReportViewData;
	const i18n = data.i18n || {};

	// Helper to create and show notice.
	const showNotice = ( message, isError = false ) => {
		const existing = document.querySelector( '.amc-report-send-notice' );
		if ( existing ) {
			existing.remove();
		}

		const notice = document.createElement( 'div' );
		notice.className = `notice ${ isError ? 'notice-error' : 'notice-success' } is-dismissible amc-report-send-notice amc-no-print`;
		notice.style.margin = '12px 0';
		notice.innerHTML = `<p>${ message }</p>`;

		const toolbar = document.querySelector( '.amc-report-toolbar' );
		if ( toolbar && toolbar.parentNode ) {
			toolbar.parentNode.insertBefore( notice, toolbar.nextSibling );
		}
	};

	sendBtn.addEventListener( 'click', () => {
		const isPublished = sendBtn.getAttribute( 'data-is-published' ) === '1';

		if ( ! isPublished ) {
			// eslint-disable-next-line no-alert
			window.alert(
				i18n.errorNotice ||
					'Only published reports can be sent to clients. Please publish the report first.'
			);
			return;
		}

		const recipients = Array.isArray( data.recipients )
			? data.recipients
			: [];

		// Build modal overlay.
		const overlay = document.createElement( 'div' );
		overlay.className = 'amc-view-modal-overlay';
		overlay.style.cssText =
			'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;';

		const modal = document.createElement( 'div' );
		modal.className = 'amc-view-modal-content';
		modal.style.cssText =
			'background:#fff;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.2);max-width:480px;width:100%;padding:24px;box-sizing:border-box;position:relative;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;color:#1e293b;';

		const recipientListHtml =
			recipients.length > 0
				? `<ul style="margin:8px 0 16px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;max-height:160px;overflow-y:auto;list-style:disc;list-style-position:inside;font-size:13px;line-height:1.6;">${ recipients.map( ( r ) => `<li style="color:#0f172a;"><strong>${ r }</strong></li>` ).join( '' ) }</ul>`
				: `<p style="color:#dc2626;font-size:13px;background:#fef2f2;padding:8px 12px;border:1px solid #fecaca;border-radius:6px;margin:8px 0 16px;">${ i18n.noRecipients }</p>`;

		modal.innerHTML = `
			<h2 style="margin:0 0 12px;font-size:18px;font-weight:600;color:#0f172a;">${ i18n.confirmTitle }</h2>
			<p style="margin:0 0 8px;font-size:13px;color:#475569;line-height:1.5;">${ i18n.confirmBody }</p>
			${ recipientListHtml }
			<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
				<button type="button" class="button button-secondary" id="amc-modal-cancel">${ i18n.cancel }</button>
				<button type="button" class="button button-primary" id="amc-modal-confirm" ${ recipients.length === 0 ? 'disabled' : '' }>${ i18n.sendNow }</button>
			</div>
		`;

		overlay.appendChild( modal );
		document.body.appendChild( overlay );

		const closeModal = () => {
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
		};

		const cancelBtn = modal.querySelector( '#amc-modal-cancel' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeModal );
		}

		overlay.addEventListener( 'click', ( ev ) => {
			if ( ev.target === overlay ) {
				closeModal();
			}
		} );

		const confirmBtn = modal.querySelector( '#amc-modal-confirm' );
		if ( confirmBtn ) {
			confirmBtn.addEventListener( 'click', async () => {
				confirmBtn.setAttribute( 'disabled', 'disabled' );
				confirmBtn.textContent = i18n.sending;

				try {
					const res = await fetch(
						`${ data.restUrl }/reports/${ data.reportId }/send-client`,
						{
							method: 'POST',
							headers: {
								'X-WP-Nonce': data.nonce,
								'Content-Type': 'application/json',
							},
						}
					);

					const json = await res.json();

					if ( ! res.ok || ! json.success ) {
						throw new Error( json.message || i18n.errorNotice );
					}

					closeModal();
					showNotice( json.message || i18n.successNotice );

					// Update button label.
					sendBtn.textContent = i18n.resend;

					// Update sent status badge.
					const badge = document.getElementById(
						'amc-email-sent-status'
					);
					if ( badge ) {
						badge.style.display = 'inline-flex';
						badge.style.fontSize = '12px';
						badge.style.color = '#15803d';
						badge.style.fontWeight = '600';
						badge.style.alignItems = 'center';
						badge.style.gap = '5px';
						badge.style.padding = '3px 8px';
						badge.style.background = '#dcfce7';
						badge.style.borderRadius = '9999px';
						badge.style.border = '1px solid #86efac';
						badge.innerHTML = `<span style="width:6px;height:6px;border-radius:50%;background:#16a34a;display:inline-block;"></span> ${ i18n.sentJustNow }`;
					}
				} catch ( err ) {
					// eslint-disable-next-line no-alert
					window.alert( err.message || i18n.errorNotice );
					confirmBtn.removeAttribute( 'disabled' );
					confirmBtn.textContent = i18n.sendNow;
				}
			} );
		}
	} );
} );
