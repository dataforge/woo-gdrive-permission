( function () {
	'use strict';

	var el              = wp.element.createElement;
	var useState        = wp.element.useState;
	var useEffect       = wp.element.useEffect;
	var useRef          = wp.element.useRef;
	var registerPlugin  = wp.plugins.registerPlugin;
	var dispatch        = wp.data.dispatch;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var getSetting      = wc.wcSettings.getSetting;

	var settings = getSetting( 'wgdp-checkout_data', {} );
	var qualifyingItems = settings.qualifyingItems || [];

	function WgdpRecipientFields() {
		if ( ! qualifyingItems.length ) {
			return null;
		}

		// Build initial state keyed by Woo cart item key.
		var initialState = {};
		qualifyingItems.forEach( function ( item ) {
			var key = item.itemKey;
			var arr = [];
			for ( var i = 0; i < item.quantity; i++ ) {
				arr.push( '' );
			}
			initialState[ key ] = arr;
		} );

		var state = useState( initialState );
		var recipients = state[0];
		var setRecipients = state[1];
		var prevQtyRef = useRef( {} );

		// Handle quantity changes from qualifying items.
		useEffect( function () {
			var prev = prevQtyRef.current;
			var updated = false;
			var newRecipients = Object.assign( {}, recipients );

			qualifyingItems.forEach( function ( item ) {
				var key = item.itemKey;
				var current = newRecipients[ key ] || [];
				var prevQty = prev[ key ] || current.length;
				var newQty = item.quantity;

				if ( newQty !== prevQty ) {
					if ( newQty > current.length ) {
						// Add empty slots.
						while ( current.length < newQty ) {
							current = current.concat( [ '' ] );
						}
					} else if ( newQty < current.length ) {
						// Truncate from end.
						current = current.slice( 0, newQty );
					}
					newRecipients[ key ] = current;
					updated = true;
				}
				prev[ key ] = newQty;
			} );

			prevQtyRef.current = prev;

			if ( updated ) {
				setRecipients( newRecipients );
			}
		}, [ qualifyingItems.map( function ( i ) { return i.quantity; } ).join( ',' ) ] );

		// Sync state to Store API on every change.
		useEffect( function () {
			dispatch( 'wc/store/checkout' ).setExtensionData( 'wgdp', 'recipients', recipients );
		}, [ JSON.stringify( recipients ) ] );

		function handleChange( key, index, value ) {
			setRecipients( function ( prev ) {
				var updated = Object.assign( {}, prev );
				var arr = ( updated[ key ] || [] ).slice();
				arr[ index ] = value;
				updated[ key ] = arr;
				return updated;
			} );
		}

		var fieldsets = qualifyingItems.map( function ( item ) {
			var key = item.itemKey;
			var emails = recipients[ key ] || [];

			var inputs = emails.map( function ( email, idx ) {
				return el( 'div', { key: idx, style: { marginBottom: '8px' } },
					el( 'label', {
						htmlFor: 'wgdp-recipient-' + key + '-' + idx,
						style: { display: 'block', fontSize: '13px', marginBottom: '4px', color: '#555' }
					}, 'Recipient ' + ( idx + 1 ) + ' email' ),
					el( 'input', {
						id: 'wgdp-recipient-' + key + '-' + idx,
						type: 'email',
						className: 'wc-block-components-text-input',
						value: email,
						onChange: function ( e ) {
							handleChange( key, idx, e.target.value );
						},
						placeholder: 'name@example.com',
						style: { width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '4px', fontSize: '14px' }
					} )
				);
			} );

			return el( 'fieldset', {
				key: key,
				style: { border: '1px solid #ddd', borderRadius: '4px', padding: '16px', marginBottom: '16px' }
			},
				el( 'legend', {
					style: { fontWeight: '600', fontSize: '14px', padding: '0 8px' }
				}, item.productName ),
				inputs
			);
		} );

		return el( ExperimentalOrderMeta, {},
			el( 'div', { className: 'wgdp-block-recipients' },
				el( 'h3', { style: { marginBottom: '12px' } }, 'Digital Access Recipients' ),
				el( 'p', { style: { fontSize: '13px', color: '#666', marginBottom: '16px' } },
					'Enter the Google account email for each recipient to grant access right away. If you skip this now, you will receive an email after purchase with a link to provide it later.'
				),
				fieldsets
			)
		);
	}

	registerPlugin( 'wgdp-checkout-block', {
		render: WgdpRecipientFields,
		scope: 'woocommerce-checkout',
	} );
} )();
