jQuery( function ( $ ) {
	const DEBOUNCE_MS = 600;
	let timer = null;

	const b2b = window.vatrightB2b || { mode: 'off', countries: [], eu: [] };
	const required = function ( country ) {
		switch ( b2b.mode ) {
			case 'everywhere':
				return true;
			case 'eu':
				return b2b.eu.indexOf( country ) !== -1;
			case 'countries':
				return b2b.countries.indexOf( country ) !== -1;
			default:
				return false;
		}
	};

	const markRequired = function () {
		const row = $( '#billing_vatright_number_field' );
		const label = row.find( 'label' ).first();
		const isRequired = required( String( $( '#billing_country' ).val() || '' ).toUpperCase() );
		if ( isRequired === ( label.find( 'abbr.required' ).length > 0 ) ) {
			row.toggleClass( 'validate-required', isRequired );
			return;
		}
		label.find( '.optional, abbr.required' ).remove();
		label.append(
			isRequired
				? $( '<abbr class="required">*</abbr>' ).attr( 'title', b2b.required )
				: $( '<span class="optional"></span>' ).text( '(' + b2b.optional + ')' )
		);
		row.toggleClass( 'validate-required', isRequired );
	};

	$( document.body ).on( 'country_to_state_changed change', '#billing_country', markRequired );
	markRequired();

	$( document.body ).on( 'input', '#billing_vatright_number', function () {
		clearTimeout( timer );
		timer = setTimeout( function () {
			$( document.body ).trigger( 'update_checkout' );
		}, DEBOUNCE_MS );
	} );

	$( document.body ).on( 'change', 'input[name="vatright_self_certify"]', function () {
		$( document.body ).trigger( 'update_checkout' );
	} );
} );
