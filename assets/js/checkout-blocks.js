/**
 * Kodepos Indonesia — block checkout integration.
 *
 * The native Province/State field is left untouched — it's WooCommerce's own
 * Indonesia state list (AC, JK, KB…), which keeps tax rates, shipping zones
 * and any existing saved addresses referencing those codes working. For
 * Indonesian addresses the native City / Postcode inputs and the two
 * additional text fields (Kecamatan / Kelurahan) are hidden and a stack of
 * four cascading selects is rendered right after the native Province field:
 *
 *   [native Province] → Select City → Select District → Select Sub District →
 *   Select Postcode
 *
 * Cities are looked up from the native province code via the REST proxy's
 * cities-by-state route (wp_state_code=JK etc.), not a numeric province
 * code. Values are written back through the canonical channels so React
 * state stays authoritative: the `wc/store/cart` data store for city/postcode
 * and the React value setter on the hidden inputs for the additional fields.
 * A MutationObserver plus store subscription re-attach the UI whenever the
 * checkout re-renders.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! window.wp.data || ! window.KodeposCascade ) {
		window.console && console.warn( '[Kodepos] wp.data or cascade core missing — cascade disabled.' );
		return;
	}

	var K = window.KodeposCascade;
	var cfg = K.config;
	var i18n = cfg.i18n || {};
	var FIELD_DISTRICT = ( cfg.fields && cfg.fields.district ) || 'kodepos-indonesia/district';
	var FIELD_SUB_DISTRICT = ( cfg.fields && cfg.fields.subDistrict ) || 'kodepos-indonesia/sub-district';

	var CART_STORE = 'wc/store/cart';
	var NATIVE_FIELDS = [ 'city', 'postcode', FIELD_DISTRICT, FIELD_SUB_DISTRICT ];

	var sections = {
		shipping: { container: null, els: null, lastState: null, lastCountry: null },
		billing: { container: null, els: null, lastState: null, lastCountry: null },
	};

	/* ------------------------------------------------------------------ */
	/* Store access                                                        */
	/* ------------------------------------------------------------------ */

	function getAddress( sectionKey ) {
		var store = wp.data.select( CART_STORE );
		if ( ! store || ! store.getCustomerData ) {
			return null;
		}
		var data = store.getCustomerData();
		if ( ! data ) {
			return null;
		}
		return sectionKey === 'shipping' ? data.shippingAddress : data.billingAddress;
	}

	function patchAddress( sectionKey, patch ) {
		var dispatcher = wp.data.dispatch( CART_STORE );
		if ( ! dispatcher ) {
			return;
		}
		if ( sectionKey === 'shipping' && dispatcher.setShippingAddress ) {
			dispatcher.setShippingAddress( patch );
		} else if ( sectionKey === 'billing' && dispatcher.setBillingAddress ) {
			dispatcher.setBillingAddress( patch );
		}
	}

	/* ------------------------------------------------------------------ */
	/* DOM helpers                                                         */
	/* ------------------------------------------------------------------ */

	function nativeInput( sectionKey, field ) {
		return (
			document.getElementById( sectionKey + '-' + field ) ||
			document.querySelector( '[id="' + sectionKey + '-' + field + '"]' )
		);
	}

	function fieldWrapper( input ) {
		if ( ! input ) {
			return null;
		}
		return (
			input.closest( '.wc-block-components-text-input' ) ||
			input.closest( '.wc-block-components-combobox' ) ||
			input.closest( '.wc-block-components-country-input' ) ||
			input.closest( '.wc-block-components-state-input' ) ||
			input.parentElement
		);
	}

	/**
	 * Update a React-controlled input through its native value setter so the
	 * component state (and therefore the store) picks the change up.
	 */
	function setReactInputValue( input, value ) {
		if ( ! input || input.value === value ) {
			return;
		}
		var setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function writeAdditionalField( sectionKey, fieldId, value ) {
		var input = nativeInput( sectionKey, fieldId );
		if ( input ) {
			setReactInputValue( input, value );
		} else {
			var patch = {};
			patch[ fieldId ] = value;
			patchAddress( sectionKey, patch );
		}
	}

	function readAdditionalField( sectionKey, fieldId ) {
		var address = getAddress( sectionKey );
		if ( address && address[ fieldId ] ) {
			return address[ fieldId ];
		}
		var input = nativeInput( sectionKey, fieldId );
		return input ? input.value : '';
	}

	/* ------------------------------------------------------------------ */
	/* UI construction                                                     */
	/* ------------------------------------------------------------------ */

	function makeSelect( sectionKey, slug, placeholder ) {
		var row = document.createElement( 'div' );
		row.className = 'kodepos-field kodepos-field--' + slug;

		var select = document.createElement( 'select' );
		select.id = 'kodepos-' + sectionKey + '-' + slug;
		select.className = 'kodepos-select is-empty';
		select.setAttribute( 'data-placeholder', placeholder );
		select.setAttribute( 'aria-label', placeholder );
		K.populateSelect( select, [] );

		select.addEventListener( 'change', function () {
			select.classList.toggle( 'is-empty', ! select.value );
		} );

		row.appendChild( select );

		return { row: row, select: select };
	}

	function hideNativeFields( sectionKey ) {
		NATIVE_FIELDS.forEach( function ( field ) {
			var wrapper = fieldWrapper( nativeInput( sectionKey, field ) );
			if ( wrapper ) {
				wrapper.classList.add( 'kodepos-hidden' );
			}
		} );
	}

	function showNativeFields( sectionKey ) {
		NATIVE_FIELDS.forEach( function ( field ) {
			var wrapper = fieldWrapper( nativeInput( sectionKey, field ) );
			if ( wrapper ) {
				wrapper.classList.remove( 'kodepos-hidden' );
			}
		} );
	}

	function teardown( sectionKey ) {
		var section = sections[ sectionKey ];

		if ( section.container && section.container.parentNode ) {
			section.container.parentNode.removeChild( section.container );
		}
		section.container = null;
		section.els = null;
		section.lastState = null;

		showNativeFields( sectionKey );
	}

	function buildUi( sectionKey, cityInput ) {
		var section = sections[ sectionKey ];

		var container = document.createElement( 'div' );
		container.className = 'kodepos-cascade';
		container.setAttribute( 'data-kodepos-section', sectionKey );

		var city = makeSelect( sectionKey, 'city', i18n.selectCity || 'Select City' );
		var district = makeSelect( sectionKey, 'district', i18n.selectDistrict || 'Select District' );
		var subDistrict = makeSelect( sectionKey, 'sub-district', i18n.selectSubDistrict || 'Select Sub District' );
		var postal = makeSelect( sectionKey, 'postal', i18n.selectPostcode || 'Select Postcode' );

		container.appendChild( city.row );
		container.appendChild( district.row );
		container.appendChild( subDistrict.row );
		container.appendChild( postal.row );

		// Insert right after the native Province/State field so the visual
		// order is Province → City → District → Sub-district → Postcode,
		// even though City's own (hidden) native slot sits earlier in the DOM.
		var stateWrapper = fieldWrapper( nativeInput( sectionKey, 'state' ) );
		var anchor = stateWrapper || fieldWrapper( cityInput );
		anchor.parentNode.insertBefore( container, anchor.nextSibling );

		section.container = container;
		section.els = {
			city: city.select,
			district: district.select,
			subDistrict: subDistrict.select,
			postal: postal.select,
		};

		city.select.addEventListener( 'change', function () {
			onCityChange( sectionKey );
		} );
		district.select.addEventListener( 'change', function () {
			onDistrictChange( sectionKey );
		} );
		subDistrict.select.addEventListener( 'change', function () {
			onSubDistrictChange( sectionKey );
		} );
		postal.select.addEventListener( 'change', function () {
			var value = section.els.postal.value;
			if ( value ) {
				patchAddress( sectionKey, { postcode: value } );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Cascade logic                                                       */
	/* ------------------------------------------------------------------ */

	function resetSelect( sectionKey, key ) {
		var section = sections[ sectionKey ];
		if ( section.els && section.els[ key ] ) {
			K.populateSelect( section.els[ key ], [] );
		}
	}

	function onCityChange( sectionKey ) {
		var section = sections[ sectionKey ];
		var name = section.els.city.value;
		var code = K.selectedCode( section.els.city );

		patchAddress( sectionKey, { city: name, postcode: '' } );
		writeAdditionalField( sectionKey, FIELD_DISTRICT, '' );
		writeAdditionalField( sectionKey, FIELD_SUB_DISTRICT, '' );
		resetSelect( sectionKey, 'subDistrict' );
		resetSelect( sectionKey, 'postal' );

		if ( ! code ) {
			resetSelect( sectionKey, 'district' );
			return;
		}

		K.setLoading( section.els.district );
		K.fetchList( 'districts', { city_code: code } ).then( function ( items ) {
			K.populateSelect( section.els.district, items );
		} ).catch( logError );
	}

	function onDistrictChange( sectionKey ) {
		var section = sections[ sectionKey ];
		var name = section.els.district.value;
		var code = K.selectedCode( section.els.district );

		writeAdditionalField( sectionKey, FIELD_DISTRICT, name );
		writeAdditionalField( sectionKey, FIELD_SUB_DISTRICT, '' );
		resetSelect( sectionKey, 'postal' );

		if ( ! code ) {
			resetSelect( sectionKey, 'subDistrict' );
			return;
		}

		K.setLoading( section.els.subDistrict );
		K.fetchList( 'sub-districts', { district_code: code } ).then( function ( items ) {
			K.populateSelect( section.els.subDistrict, items );
		} ).catch( logError );
	}

	function onSubDistrictChange( sectionKey ) {
		var section = sections[ sectionKey ];
		var name = section.els.subDistrict.value;
		var code = K.selectedCode( section.els.subDistrict );

		writeAdditionalField( sectionKey, FIELD_SUB_DISTRICT, name );
		resetSelect( sectionKey, 'postal' );

		if ( ! code ) {
			return;
		}

		K.setLoading( section.els.postal );
		K.fetchList( 'postal-codes', { sub_district_code: code } ).then( function ( items ) {
			var codes = items.map( function ( item ) {
				var postal = item.postal_code || item.name;
				return { code: item.code, name: postal };
			} );

			K.populateSelect( section.els.postal, codes );

			// A single postal code needs no decision — pick it.
			if ( codes.length === 1 ) {
				section.els.postal.value = codes[ 0 ].name;
				section.els.postal.classList.remove( 'is-empty' );
				patchAddress( sectionKey, { postcode: codes[ 0 ].name } );
			}
		} ).catch( logError );
	}

	/**
	 * Populate the cascade from the address currently in the store — used on
	 * first paint (prefill) and whenever the native Province/State changes.
	 */
	function syncFromAddress( sectionKey, prefill ) {
		var section = sections[ sectionKey ];
		var address = getAddress( sectionKey );

		if ( ! section.els || ! address ) {
			return;
		}

		section.lastState = address.state || '';

		if ( ! address.state ) {
			resetSelect( sectionKey, 'city' );
			resetSelect( sectionKey, 'district' );
			resetSelect( sectionKey, 'subDistrict' );
			resetSelect( sectionKey, 'postal' );
			return;
		}

		K.setLoading( section.els.city );
		K.fetchList( 'cities-by-state', { wp_state_code: address.state } ).then( function ( cities ) {
			K.populateSelect( section.els.city, cities, prefill ? address.city : '' );

			var city = prefill ? K.findByName( cities, address.city ) : null;
			if ( ! city ) {
				resetSelect( sectionKey, 'district' );
				resetSelect( sectionKey, 'subDistrict' );
				resetSelect( sectionKey, 'postal' );
				return;
			}

			return K.fetchList( 'districts', { city_code: city.code } ).then( function ( districts ) {
				var savedDistrict = readAdditionalField( sectionKey, FIELD_DISTRICT );
				K.populateSelect( section.els.district, districts, savedDistrict );

				var district = K.findByName( districts, savedDistrict );
				if ( ! district ) {
					resetSelect( sectionKey, 'subDistrict' );
					resetSelect( sectionKey, 'postal' );
					return;
				}

				return K.fetchList( 'sub-districts', { district_code: district.code } ).then( function ( subs ) {
					var savedSub = readAdditionalField( sectionKey, FIELD_SUB_DISTRICT );
					K.populateSelect( section.els.subDistrict, subs, savedSub );

					if ( address.postcode ) {
						K.populateSelect(
							section.els.postal,
							[ { code: address.postcode, name: address.postcode } ],
							address.postcode
						);
					}
				} );
			} );
		} ).catch( logError );
	}

	function logError( error ) {
		window.console && console.warn( '[Kodepos] ' + ( error && error.message ? error.message : error ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lifecycle                                                           */
	/* ------------------------------------------------------------------ */

	function enhance( sectionKey ) {
		var section = sections[ sectionKey ];
		var cityInput = nativeInput( sectionKey, 'city' );

		// Form not rendered (yet) — e.g. billing collapsed behind "use
		// shipping address for billing".
		if ( ! cityInput ) {
			section.container = null;
			section.els = null;
			return;
		}

		var address = getAddress( sectionKey );
		if ( ! address ) {
			return; // Store not ready yet; a later tick will retry.
		}

		if ( address.country !== 'ID' ) {
			if ( section.container ) {
				teardown( sectionKey );
			}
			section.lastCountry = address.country;
			return;
		}

		section.lastCountry = 'ID';

		// Keep the native inputs hidden even after React re-renders.
		hideNativeFields( sectionKey );

		if ( section.container && section.container.isConnected ) {
			return;
		}

		buildUi( sectionKey, cityInput );
		syncFromAddress( sectionKey, true );
	}

	var scheduled = false;

	function scheduleEnhance() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			enhance( 'shipping' );
			enhance( 'billing' );
		} );
	}

	// React to store changes made outside our selects (native Province/State
	// change, country switch, saved address selection, "use shipping as
	// billing" toggles…).
	wp.data.subscribe( function () {
		Object.keys( sections ).forEach( function ( sectionKey ) {
			var section = sections[ sectionKey ];
			if ( ! section.els || ! section.container || ! section.container.isConnected ) {
				return;
			}
			var address = getAddress( sectionKey );
			if ( ! address ) {
				return;
			}
			if ( address.country !== 'ID' ) {
				teardown( sectionKey );
				return;
			}
			var state = address.state || '';
			if ( state !== section.lastState ) {
				section.lastState = state;
				syncFromAddress( sectionKey, false );
			}
		} );
		scheduleEnhance();
	} );

	function start() {
		var root =
			document.querySelector( '.wp-block-woocommerce-checkout' ) ||
			document.querySelector( '.wc-block-checkout' ) ||
			document.body;

		var observer = new MutationObserver( scheduleEnhance );
		observer.observe( root, { childList: true, subtree: true } );

		scheduleEnhance();

		// Belt and braces: the checkout hydrates asynchronously, so retry for
		// the first ten seconds even if no mutation/store event fires.
		var attempts = 0;
		var timer = window.setInterval( function () {
			attempts += 1;
			scheduleEnhance();
			if ( attempts >= 20 || ( sections.shipping.container && sections.shipping.container.isConnected ) ) {
				window.clearInterval( timer );
			}
		}, 500 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
