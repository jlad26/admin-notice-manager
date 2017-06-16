(function( $ ) {
	'use strict';

	/**
	 * Javascript for Admin Notice Manager
	 */
	
	// On document ready
	$( function() {

		var redirectUrl = '';
		var dismissEvent = '';
		
		// Send ajax
		function ajaxDismiss( dismissElement ) {
			var container = dismissElement.closest( '.notice' );
			var noticeID = container.find('.anm-notice-id').val();
			var data = {
				action		:	container.find( '.anm-id' ).val() + '_dismiss_admin_notice',
				noticeID	:	noticeID
			};
			data['nonce-anm-' + noticeID] = container.find( '#nonce-anm-' + noticeID ).val();
			if ( dismissEvent.length ) {
				data['anm-event'] = dismissEvent;
			}
			$.ajax({
				url:		ajaxurl,
				type:		'post',
				data:		data
			});
		}
		
		// Send ajax on click of dismiss icon
		$( 'body' ).on( 'click', '.notice-manager-ajax .notice-dismiss', function() {
			ajaxDismiss( $(this) );
		});
		
		// On click of dismiss element, set redirect url or event and trigger dismiss
		$( 'body' ).on( 'click', '.anm-dismiss', function() {
			if ( 'anm-redirect' == $(this).attr('name') ) {
				redirectUrl = $(this).val();
			} else if ( 'anm-event' == $(this).attr('name') ) {
				dismissEvent = $(this).val();
			}
			$(this).closest( '.notice.is-dismissible' ).find('.notice-dismiss').click();
		});
		
		// Prevent form submit and redirect if url has been set
		$( 'body' ).on( 'submit', '.anm-form', function(evt) {
			evt.preventDefault();
			if ( redirectUrl.length > 0 ) {
				window.location.href = redirectUrl;
			}
			return false;
		});
		
	});

})( jQuery );
