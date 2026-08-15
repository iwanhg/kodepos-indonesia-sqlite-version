/**
 * Kodepos Indonesia — shared cascade engine.
 *
 * Small helper used by both the block checkout script and the admin script:
 * fetches lists from the REST proxy (with in-memory caching) and offers
 * helpers to (re)build <select> elements and match saved values by name.
 */
( function () {
	'use strict';

	var cfg = window.kodeposIndonesia || {};
	var promiseCache = {};

	function restUrl( endpoint, params ) {
		var base = ( cfg.restUrl || '' ).replace( /\/$/, '' ) + '/' + endpoint;
		var query = [];

		Object.keys( params || {} ).forEach( function ( key ) {
			query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
		} );

		// Cache-buster: ties the browser HTTP cache to the plugin version.
		if ( cfg.version ) {
			query.push( 'v=' + encodeURIComponent( cfg.version ) );
		}

		// The REST base may already carry ?rest_route=… on plain-permalink sites.
		var sep = base.indexOf( '?' ) === -1 ? '?' : '&';

		return query.length ? base + sep + query.join( '&' ) : base;
	}

	/**
	 * Fetch a normalized item list ({code, name, postal_code?}) from the proxy.
	 * Identical requests share one promise.
	 */
	function fetchList( endpoint, params ) {
		var key = restUrl( endpoint, params );

		if ( promiseCache[ key ] ) {
			return promiseCache[ key ];
		}

		var headers = { Accept: 'application/json' };
		if ( cfg.nonce ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}

		promiseCache[ key ] = window
			.fetch( key, { headers: headers, credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Kodepos proxy HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( data ) {
				return ( data && data.items ) || [];
			} );

		// Do not cache failures so a temporary outage can recover.
		promiseCache[ key ].catch( function () {
			delete promiseCache[ key ];
		} );

		return promiseCache[ key ];
	}

	/**
	 * Replace a select's options. Items are {code, name}. The option value is
	 * the human-readable name; the code travels in data-code.
	 */
	function populateSelect( select, items, selectedName, placeholder ) {
		placeholder = placeholder || select.getAttribute( 'data-placeholder' ) || ( cfg.i18n && cfg.i18n.select ) || '—';

		select.innerHTML = '';

		var empty = document.createElement( 'option' );
		empty.value = '';
		empty.textContent = placeholder;
		select.appendChild( empty );

		items.forEach( function ( item ) {
			var option = document.createElement( 'option' );
			option.value = item.name;
			option.textContent = item.name;
			option.setAttribute( 'data-code', item.code );
			if ( item.postal_code ) {
				option.setAttribute( 'data-postal', item.postal_code );
			}
			select.appendChild( option );
		} );

		if ( selectedName ) {
			var match = findByName( items, selectedName );
			if ( match ) {
				select.value = match.name;
			}
		}

		select.disabled = items.length === 0;
		select.classList.toggle( 'is-empty', ! select.value );
	}

	function setLoading( select ) {
		var loading = ( cfg.i18n && cfg.i18n.loading ) || '…';
		select.innerHTML = '';
		var option = document.createElement( 'option' );
		option.value = '';
		option.textContent = loading;
		select.appendChild( option );
		select.disabled = true;
		select.classList.add( 'is-empty' );
	}

	function findByName( items, name ) {
		var needle = String( name || '' ).trim().toLowerCase();
		if ( ! needle ) {
			return null;
		}
		for ( var i = 0; i < items.length; i++ ) {
			if ( String( items[ i ].name ).trim().toLowerCase() === needle ) {
				return items[ i ];
			}
		}
		return null;
	}

	function findByCode( items, code ) {
		var needle = String( code || '' ).trim();
		if ( ! needle ) {
			return null;
		}
		for ( var i = 0; i < items.length; i++ ) {
			if ( String( items[ i ].code ).trim() === needle ) {
				return items[ i ];
			}
		}
		return null;
	}

	function selectedCode( select ) {
		var option = select.options[ select.selectedIndex ];
		return option ? option.getAttribute( 'data-code' ) : null;
	}

	window.KodeposCascade = {
		fetchList: fetchList,
		populateSelect: populateSelect,
		setLoading: setLoading,
		findByName: findByName,
		findByCode: findByCode,
		selectedCode: selectedCode,
		config: cfg,
	};
} )();
