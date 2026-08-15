/**
 * Kodepos Indonesia — admin cascading selects.
 *
 * Drives three classic (jQuery) admin screens with one generic "group"
 * implementation: the order edit billing/shipping panels, the user profile
 * address sections and the WooCommerce Store Address settings.
 *
 * For Indonesian addresses the plain city / district / sub-district text
 * inputs are swapped for <select>es (same id + name, so the form posts the
 * same data); switching the country away from Indonesia restores the
 * original inputs.
 */
( function ( $ ) {
	'use strict';

	if ( ! window.KodeposCascade ) {
		return;
	}

	var K = window.KodeposCascade;
	var cfg = K.config;

	function byId( id ) {
		return id ? document.getElementById( id ) : null;
	}

	/**
	 * One cascading address group (e.g. order billing).
	 *
	 * @param {Object} opts
	 * @param {Function} opts.getCountry  Returns the current country code.
	 * @param {Function} opts.getState    Returns the current province code.
	 * @param {Function} opts.bindRegion  bindRegion(handler) — call handler when country/state change.
	 * @param {string} opts.cityId       DOM id of the city input.
	 * @param {string} opts.districtId   DOM id of the district input.
	 * @param {string} opts.subDistrictId DOM id of the sub-district input.
	 * @param {string} opts.postcodeId   DOM id of the postcode input.
	 * @param {Function} [opts.onActivated] Called after activate() sets up selects.
	 */
	function Group( opts ) {
		this.opts = opts;
		this.active = false;
		this.originals = {};
		this.selects = {};
		this.lastState = null;

		var self = this;

		opts.bindRegion( function () {
			self.refresh();
		} );

		this.refresh();
	}

	Group.prototype.refresh = function () {
		var country = this.opts.getCountry();

		if ( country !== 'ID' ) {
			this.deactivate();
			return;
		}

		if ( ! this.active ) {
			this.activate();
			if ( typeof this.opts.onActivated === 'function' ) {
				this.opts.onActivated( this );
			}
		}

		var state = this.opts.getState();
		if ( state !== this.lastState ) {
			// Only the very first restore (lastState still unset) reproduces
			// already-saved data; any later call here is a real province
			// change the user made and must keep WordPress's unsaved-changes
			// warning intact.
			var isInitialLoad = ( null === this.lastState );
			this.lastState = state;
			var self = this;
			var chain = this.loadCities( true );
			if ( chain && typeof chain.then === 'function' ) {
				chain.then( function () {
					// Restoring already-saved values fires 'change' events (to
					// drive select2's UI and our own cascade), which WordPress
					// admin's own "unsaved changes" tracking can't tell apart
					// from a real edit. Clear the resulting nav warning — this
					// prefill isn't a change the user made, so leaving the
					// page right after load shouldn't prompt them.
					if ( isInitialLoad && window.onbeforeunload ) {
						window.onbeforeunload = null;
					}
					if ( typeof self.opts.onPrefillComplete === 'function' ) {
						self.opts.onPrefillComplete( self );
					}
				} );
			}
		}
	};

	Group.prototype.activate = function () {
		var fields = { city: 'cityId', district: 'districtId', subDistrict: 'subDistrictId' };
		var self = this;
		var ok = false;

		Object.keys( fields ).forEach( function ( key ) {
			var input = byId( self.opts[ fields[ key ] ] );
			if ( ! input ) {
				return;
			}

			ok = true;

			if ( input.tagName === 'SELECT' ) {
				self.selects[ key ] = input;
				return;
			}

			var select = document.createElement( 'select' );
			select.id = input.id + '_kodepos';
			select.className = ( input.className || '' ) + ' kodepos-select';
			select.setAttribute( 'data-kodepos-initial', input.value );
			K.populateSelect( select, [] );

			input.style.display = 'none';
			input.parentNode.insertBefore( select, input );
			
			self.originals[ key ] = input;
			self.selects[ key ] = select;

			$( select ).on( 'change', function () {
				input.value = select.value;
				self.onChange( key );
			} );

			if ( $.fn.selectWoo ) {
				// Settings inputs are fixed at 400px (WooCommerce admin CSS);
				// order/profile fields are fluid.
				$( select ).selectWoo( { width: cfg.screen === 'settings' ? '400px' : '100%' } );
			}
		} );

		this.active = ok;
	};

	Group.prototype.deactivate = function () {
		var self = this;

		if ( ! this.active ) {
			return;
		}

		Object.keys( this.selects ).forEach( function ( key ) {
			var select = self.selects[ key ];
			var original = self.originals[ key ];

			if ( ! original || ! select.parentNode ) {
				return;
			}

			if ( $.fn.selectWoo && $( select ).data( 'select2' ) ) {
				$( select ).selectWoo( 'destroy' );
			}

			original.value = select.value || original.value;
			original.style.display = '';
			select.parentNode.removeChild( select );
		} );

		this.selects = {};
		this.originals = {};
		this.active = false;
		this.lastState = null;
	};

	Group.prototype.setSelect = function ( key, items, selectedName ) {
		var select = this.selects[ key ];
		if ( ! select ) {
			return;
		}
		this._isProgrammatic = true;
		K.populateSelect( select, items, selectedName );
		$( select ).trigger( 'change' );
		this._isProgrammatic = false;
	};

	Group.prototype.value = function ( key ) {
		return this.selects[ key ] ? this.selects[ key ].value : '';
	};

	Group.prototype.initialValue = function ( key ) {
		var select = this.selects[ key ];
		return select ? select.getAttribute( 'data-kodepos-initial' ) || '' : '';
	};

	Group.prototype.setPostcode = function ( value ) {
		var input = byId( this.opts.postcodeId );
		if ( input && value ) {
			input.value = value;
			$( input ).trigger( 'change' );
		}
	};

	/**
	 * Load cities for the current province; on first load, chain-prefill the
	 * saved city → district → sub-district values.
	 */
	Group.prototype.loadCities = function ( prefill ) {
		var self = this;
		var state = this.opts.getState();

		this.setSelect( 'district', [] );
		this.setSelect( 'subDistrict', [] );

		if ( ! state || ! this.selects.city ) {
			this.setSelect( 'city', [] );
			return null;
		}

		K.setLoading( this.selects.city );

		return K.fetchList( 'cities-by-state', { wp_state_code: state } ).then( function ( cities ) {
			var saved = prefill ? self.initialValue( 'city' ) : '';
			self.setSelect( 'city', cities, saved );

			var city = K.findByName( cities, saved );
			if ( ! city ) {
				return null;
			}

			return K.fetchList( 'districts', { city_code: city.code } ).then( function ( districts ) {
				var savedDistrict = self.initialValue( 'district' );
				self.setSelect( 'district', districts, savedDistrict );

				var district = K.findByName( districts, savedDistrict );
				if ( ! district ) {
					return null;
				}

				return K.fetchList( 'sub-districts', { district_code: district.code } ).then( function ( subs ) {
					self.setSelect( 'subDistrict', subs, self.initialValue( 'subDistrict' ) );
				} );
			} );
		} );
	};

	Group.prototype.onChange = function ( key ) {
		if ( this._isProgrammatic ) {
			return;
		}
		
		var self = this;

		if ( key === 'city' ) {
			var cityCode = K.selectedCode( this.selects.city );
			this.setSelect( 'subDistrict', [] );

			if ( ! cityCode ) {
				this.setSelect( 'district', [] );
				return;
			}

			K.setLoading( this.selects.district );
			K.fetchList( 'districts', { city_code: cityCode } ).then( function ( items ) {
				self.setSelect( 'district', items );
			} );
			return;
		}

		if ( key === 'district' ) {
			var districtCode = K.selectedCode( this.selects.district );

			if ( ! districtCode ) {
				this.setSelect( 'subDistrict', [] );
				return;
			}

			K.setLoading( this.selects.subDistrict );
			K.fetchList( 'sub-districts', { district_code: districtCode } ).then( function ( items ) {
				self.setSelect( 'subDistrict', items );
			} );
			return;
		}

		if ( key === 'subDistrict' ) {
			var subCode = K.selectedCode( this.selects.subDistrict );

			if ( ! subCode ) {
				return;
			}

			K.fetchList( 'postal-codes', { sub_district_code: subCode } ).then( function ( items ) {
				if ( items.length ) {
					self.setPostcode( items[ 0 ].postal_code || items[ 0 ].name );
				}
			} );
		}
	};

	/* ------------------------------------------------------------------ */
	/* Screen wiring                                                       */
	/* ------------------------------------------------------------------ */

	function bindDelegated( ids, handler ) {
		ids.forEach( function ( id ) {
			// Delegated so it survives WooCommerce swapping the state field
			// between input and select2 when the country changes.
			$( document.body ).on( 'change', '[id="' + id + '"]', function () {
				// Let WooCommerce's own country/state scripts finish first.
				window.setTimeout( handler, 50 );
			} );
		} );
	}

	function valueOf( id ) {
		var el = byId( id );
		return el ? el.value : '';
	}

	function initOrderScreen() {
		[ '_billing', '_shipping' ].forEach( function ( prefix ) {
			if ( ! byId( prefix + '_city' ) ) {
				return;
			}

			var group = new Group( {
				getCountry: function () {
					return valueOf( prefix + '_country' );
				},
				getState: function () {
					return valueOf( prefix + '_state' );
				},
				bindRegion: function ( handler ) {
					bindDelegated( [ prefix + '_country', prefix + '_state' ], handler );
				},
				cityId: prefix + '_city',
				districtId: prefix + '_kodepos_district',
				subDistrictId: prefix + '_kodepos_sub_district',
				postcodeId: prefix + '_postcode',
			} );

			// The address panels start hidden; re-check when "Edit" is clicked.
			$( document.body ).on( 'click', 'a.edit_address', function () {
				window.setTimeout( function () {
					group.refresh();
				}, 100 );
			} );
		} );
	}

	function initProfileScreen() {
		[ 'billing', 'shipping' ].forEach( function ( prefix ) {
			if ( ! byId( prefix + '_city' ) ) {
				return;
			}

			new Group( {
				getCountry: function () {
					return valueOf( prefix + '_country' );
				},
				getState: function () {
					return valueOf( prefix + '_state' );
				},
				bindRegion: function ( handler ) {
					bindDelegated( [ prefix + '_country', prefix + '_state' ], handler );
				},
				cityId: prefix + '_city',
				districtId: '_wc_' + prefix + '/kodepos-indonesia/district',
				subDistrictId: '_wc_' + prefix + '/kodepos-indonesia/sub-district',
				postcodeId: prefix + '_postcode',
			} );
		} );
	}

	/**
	 * Strip every non-Indonesian entry from the store Country/State select so
	 * the shop location can only be an Indonesian province.
	 */
	function lockCountryToIndonesia() {
		var country = byId( 'woocommerce_default_country' );
		if ( ! country ) {
			return;
		}

		Array.prototype.slice.call( country.querySelectorAll( 'option' ) ).forEach( function ( option ) {
			if ( option.value.indexOf( 'ID' ) !== 0 ) {
				option.parentNode.removeChild( option );
			}
		} );

		Array.prototype.slice.call( country.querySelectorAll( 'optgroup' ) ).forEach( function ( group ) {
			if ( ! group.querySelector( 'option' ) ) {
				group.parentNode.removeChild( group );
			}
		} );

		// Previously saved non-Indonesian location: move it to the first province.
		if ( country.value.indexOf( 'ID' ) !== 0 ) {
			var first = country.querySelector( 'option' );
			if ( first ) {
				country.value = first.value;
			}
		}
		// NOTE: Do NOT trigger 'change' here — the Group is already handling
		// the initial load via refresh() called in its constructor.
	}

	function initSettingsScreen() {
		if ( ! byId( 'woocommerce_store_city' ) || ! byId( 'woocommerce_default_country' ) ) {
			return;
		}

		// ── Parse existing Address Line 2 to recover District & Sub-District ──
		// Format stored: "Sub-District Name, District Name"
		var addr2 = byId( 'woocommerce_store_address_2' );
		var initialDistrict    = '';
		var initialSubDistrict = '';
		if ( addr2 && addr2.value ) {
			var addrParts = addr2.value.split( ',' );
			if ( addrParts.length >= 2 ) {
				initialSubDistrict = addrParts[ 0 ].trim();
				initialDistrict    = addrParts[ 1 ].trim();
			}
		}

		// ── Inject hidden <input>s for District & Sub-District ─────────────
		// These carry the current saved values so activate() reads them as
		// data-kodepos-initial for the restore chain.
		var cityRow = byId( 'woocommerce_store_city' ).closest( 'tr' );

		var districtRow = document.createElement( 'tr' );
		districtRow.innerHTML = '<th scope="row" class="titledesc"><label for="woocommerce_store_district_kodepos">Kecamatan</label></th>' +
			'<td class="forminp forminp-text"><input type="hidden" id="woocommerce_store_district" name="woocommerce_store_district"></td>';
		cityRow.parentNode.insertBefore( districtRow, cityRow.nextSibling );
		byId( 'woocommerce_store_district' ).value = initialDistrict;

		var subDistrictRow = document.createElement( 'tr' );
		subDistrictRow.innerHTML = '<th scope="row" class="titledesc"><label for="woocommerce_store_sub_district_kodepos">Kelurahan / Desa</label></th>' +
			'<td class="forminp forminp-text"><input type="hidden" id="woocommerce_store_sub_district" name="woocommerce_store_sub_district"></td>';
		districtRow.parentNode.insertBefore( subDistrictRow, districtRow.nextSibling );
		byId( 'woocommerce_store_sub_district' ).value = initialSubDistrict;

		// ── Lock dropdown to Indonesian provinces and handle select2 ────────
		var countryEl = byId( 'woocommerce_default_country' );
		var savedVal  = countryEl ? countryEl.value : '';

		lockCountryToIndonesia();

		// select2 (selectWoo) may already be initialized by WooCommerce's
		// wc-enhanced-select.js. After we removed DOM options, we must
		// destroy and re-create select2 so it picks up the filtered list.
		if ( countryEl && $.fn.selectWoo ) {
			var $c = $( countryEl );
			if ( $c.data( 'select2' ) ) {
				$c.selectWoo( 'destroy' );
			}
			countryEl.value = savedVal; // restore the pre-selected value
			$c.selectWoo();
		}

		// ── The store location select holds "COUNTRY:STATE" (e.g. "ID:51") ─
		function parseLocation() {
			var raw   = valueOf( 'woocommerce_default_country' );
			var parts = raw.split( ':' );
			return { country: parts[ 0 ] || '', state: parts[ 1 ] || '' };
		}

		// ── Build the cascading group ──────────────────────────────────────
		var group = new Group( {
			getCountry: function () {
				return parseLocation().country;
			},
			getState: function () {
				return parseLocation().state;
			},
			bindRegion: function ( handler ) {
				bindDelegated( [ 'woocommerce_default_country' ], handler );
			},
			cityId:        'woocommerce_store_city',
			districtId:    'woocommerce_store_district',
			subDistrictId: 'woocommerce_store_sub_district',
			postcodeId:    'woocommerce_store_postcode',
			// Called after activate() has built the <select>s
			onActivated: function ( g ) {
				attachSyncAndToggle( g );
			},
			// Called once the city → district → sub-district restore chain
			// settles — backfills Address Line 2 if it's blank (e.g. the
			// dropdowns hold restored values but the text field was cleared).
			onPrefillComplete: function ( g ) {
				fillAddress2IfEmpty( g );
			},
		} );

		function fillAddress2IfEmpty( g ) {
			if ( ! addr2 || addr2.value.trim() !== '' ) {
				return;
			}

			var d  = g.value( 'district' );
			var sd = g.value( 'subDistrict' );
			var combined = [];
			if ( sd ) combined.push( sd );
			if ( d )  combined.push( d );

			if ( combined.length ) {
				addr2.value = combined.join( ', ' );
			}
		}

		// ── Sync Address Line 2 and toggle row visibility ──────────────────
		// Must run AFTER activate() so group.selects is populated.
		function attachSyncAndToggle( g ) {
			function syncAndToggle() {
				// Write "Sub-District, District" into Address Line 2 ONLY on user interaction
				if ( addr2 && !g._isProgrammatic ) {
					var d  = g.value( 'district' );
					var sd = g.value( 'subDistrict' );
					var combined = [];
					if ( sd ) combined.push( sd );
					if ( d )  combined.push( d );
					addr2.value = combined.join( ', ' );
				}

				// Show/hide District row based on city selection
				var cityVal     = g.value( 'city' );
				var districtVal = g.value( 'district' );

				if ( g.selects.district ) {
					var dRow = g.selects.district.closest( 'tr' );
					if ( dRow ) dRow.style.display = cityVal ? '' : 'none';
				}
				if ( g.selects.subDistrict ) {
					var sdRow = g.selects.subDistrict.closest( 'tr' );
					if ( sdRow ) sdRow.style.display = districtVal ? '' : 'none';
				}
			}

			// Attach to the kodepos <select>s (not the hidden inputs)
			if ( g.selects.city )        $( g.selects.city ).on( 'change', syncAndToggle );
			if ( g.selects.district )    $( g.selects.district ).on( 'change', syncAndToggle );
			if ( g.selects.subDistrict ) $( g.selects.subDistrict ).on( 'change', syncAndToggle );

			syncAndToggle(); // apply initial visibility
		}

		// If the group is already active when constructed (country was ID),
		// onActivated was already called. If not (shouldn't happen here since
		// we locked to Indonesia), attach after a short wait.
		if ( ! group.active ) {
			window.setTimeout( function () {
				attachSyncAndToggle( group );
			}, 200 );
		}
	}

	$( function () {
		switch ( cfg.screen ) {
			case 'order':
				initOrderScreen();
				break;
			case 'profile':
				initProfileScreen();
				break;
			case 'settings':
				initSettingsScreen();
				break;
		}
	} );
} )( window.jQuery );
