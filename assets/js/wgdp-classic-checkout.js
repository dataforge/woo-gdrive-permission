(function ($) {
	'use strict';

	// Auto-fill the recipient email with the billing email, but only when there
	// is exactly one recipient field. On a multi-product gift cart there is no
	// reliable way to know which product the billing email is meant for, so
	// pre-filling the first field in DOM order would put it against the wrong
	// product. Leave those for the buyer to fill explicitly.
	function maybeFillBillingEmail() {
		var billingEmail = $( '#billing_email' ).val();
		if ( ! billingEmail ) {
			return;
		}

		var $fields = $( '#wgdp-classic-recipients input[type="email"]' );
		if ( 1 !== $fields.length ) {
			return;
		}

		var $first = $fields.first();
		if ( ! $first.val() ) {
			$first.val( billingEmail ).trigger( 'input' );
		}
	}

	$( document.body ).on( 'change', '#billing_email', maybeFillBillingEmail );

	// Run once on page load for logged-in users with pre-filled billing email.
	$( maybeFillBillingEmail );

})( jQuery );
