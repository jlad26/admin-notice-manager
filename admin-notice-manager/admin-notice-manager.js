(function( $ ) {
	'use strict';

	/**
	 * Javascript for Admin Notice Manager
	 */
	
	// On document ready
	$( function() {

		var redirectUrl = '';
		var dismissEvent = '';
		var redirectNewTab = false;
		
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
		
		// Dismiss notice
		function dismissNotice( dismissElement ) {
			var container = dismissElement.closest( '.notice' );
			container.fadeTo( 100, 0, function() {
				container.slideUp( 100, function() {
					container.remove();
				});
			});
		}
		
		// Send ajax on click of dismiss icon
		$( 'body' ).on( 'click', '.notice-manager-ajax .notice-dismiss', function() {
			ajaxDismiss( $(this) );
		});
		
		// On click of dismiss element, set redirect url or event and trigger ajax dismiss
		$( 'body' ).on( 'click', '.anm-dismiss', function() {
			if ( 'anm-redirect' == $(this).attr('name') ) {
				redirectUrl = $(this).val();
				if ( $(this).data( 'newtab' ) ) {
					redirectNewTab = true;
				}
			} else if ( 'anm-event' == $(this).attr('name') ) {
				dismissEvent = $(this).val();
			}
			ajaxDismiss( $(this) );
			dismissNotice( $(this) );
		});
		
		// Prevent form submit and redirect if url has been set
		$( 'body' ).on( 'submit', '.anm-form', function(evt) {
			evt.preventDefault();
			if ( redirectUrl.length > 0 ) {
				if ( redirectNewTab ) {
					window.open( redirectUrl, '_blank' );
				} else {
					window.location.href = redirectUrl;
				}
			}
			return false;
		});
		
	});

})( jQuery );
