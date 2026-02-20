(function ($) {
	'use strict';

	// Auto-fill first empty recipient email with billing email.
	function maybeFillBillingEmail() {
		var billingEmail = $( '#billing_email' ).val();
		if ( ! billingEmail ) {
			return;
		}

		var $first = $( '#wgdp-classic-recipients input[type="email"]' ).first();
		if ( $first.length && ! $first.val() ) {
			$first.val( billingEmail ).trigger( 'input' );
		}
	}

	$( document.body ).on( 'change', '#billing_email', maybeFillBillingEmail );

	// Run once on page load for logged-in users with pre-filled billing email.
	$( maybeFillBillingEmail );

})( jQuery );
