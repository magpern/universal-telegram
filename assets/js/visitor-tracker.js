/**
 * Dependency-free visitor/browser event tracking client (M04 plan §4.4,
 * §4.6, docs/adr/0019). No third-party library, no build step. Never
 * reads cookies, local/session storage beyond its own visit_ref key,
 * query strings, form values, or raw error text. Every network call is
 * wrapped in try/catch and never blocks rendering, navigation, or checkout.
 */
( function ( global ) {
	'use strict';

	var STORAGE_KEY = 'ut_visit_ref';
	var MAX_ERRORS_PER_SESSION = 5;
	var ENDPOINT_PATH = '/wp-json/universal-telegram/v1/visitor-events';

	// A 16-byte random hex string via crypto.getRandomValues().
	function randomHex16() {
		var bytes = new Uint8Array( 16 );
		global.crypto.getRandomValues( bytes );
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	// A UUIDv4 string.
	function uuid4() {
		if ( global.crypto && typeof global.crypto.randomUUID === 'function' ) {
			return global.crypto.randomUUID();
		}
		var bytes = new Uint8Array( 16 );
		global.crypto.getRandomValues( bytes );
		bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
		bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
		var hex = [];
		for ( var i = 0; i < bytes.length; i++ ) {
			hex.push( ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 ) );
		}
		return (
			hex.slice( 0, 4 ).join( '' ) + '-' +
			hex.slice( 4, 6 ).join( '' ) + '-' +
			hex.slice( 6, 8 ).join( '' ) + '-' +
			hex.slice( 8, 10 ).join( '' ) + '-' +
			hex.slice( 10, 16 ).join( '' )
		);
	}

	// A factory, not a singleton, so the behavioural test suite can
	// construct fresh, isolated instances.
	function createTracker() {
		var config     = null;
		var visitRef   = null;
		var lastPath   = null;
		var errorCount = 0;
		var queue      = [];

		// Stable across reloads (sessionStorage survives them); cleared
		// only when the tab/browser session ends (M04 plan §4.3).
		function getOrCreateVisitRef() {
			var existing = null;
			try {
				existing = global.sessionStorage.getItem( STORAGE_KEY );
			} catch ( e ) {
				existing = null;
			}

			if ( existing ) {
				return { ref: existing, isNew: false };
			}

			var fresh = randomHex16();
			try {
				global.sessionStorage.setItem( STORAGE_KEY, fresh );
			} catch ( e ) {
				// sessionStorage unavailable (private mode) — degrade to
				// an in-memory-only visit_ref rather than throwing.
			}
			return { ref: fresh, isNew: true };
		}

		// Client-side suppression only (M04 plan §4.3) — never a
		// server-verifiable guarantee.
		function consentGranted() {
			if ( ! config || ! config.enabled ) {
				return false;
			}
			if ( 'disabled' === config.consentMode ) {
				return true;
			}
			return true === global.universalTelegramConsent;
		}

		// No-op when consent is not granted — the endpoint is never
		// contacted at all in that case.
		function trackEvent( shortType, data ) {
			if ( ! consentGranted() ) {
				return;
			}
			queue.push( { uuid: uuid4(), type: shortType, data: data || {} } );
		}

		// One "nv" event per same-tab transition (M04 plan §4.2).
		function onNavigate( toPath ) {
			if ( null !== lastPath && lastPath !== toPath ) {
				trackEvent( 'nv', { from_path: lastPath, to_path: toPath } );
			}
			lastPath = toPath;
		}

		// Capped at MAX_ERRORS_PER_SESSION — a flat volume bound, not
		// deduplication (M04 plan §4.5). No location/message/stack ever read.
		function onError( category ) {
			if ( errorCount >= MAX_ERRORS_PER_SESSION ) {
				return;
			}
			errorCount++;
			trackEvent( 'je', { error_category: category } );
		}

		// On failure the queue is left intact, so a retry reuses the same
		// uuid rather than regenerating one (M04 plan §4.5).
		function flush() {
			if ( 0 === queue.length || ! config ) {
				return Promise.resolve( true );
			}

			var body = JSON.stringify( { v: 1, visit: visitRef, events: queue } );
			var url  = config.endpoint || ENDPOINT_PATH;

			try {
				if ( global.navigator && typeof global.navigator.sendBeacon === 'function' ) {
					var sent = global.navigator.sendBeacon( url, body );
					if ( sent ) {
						queue = [];
					}
					return Promise.resolve( sent );
				}

				if ( typeof global.fetch === 'function' ) {
					return global.fetch( url, { method: 'POST', keepalive: true, body: body } )
						.then(
							function () {
								queue = [];
								return true;
							},
							function () {
								return false;
							}
						);
				}
			} catch ( e ) {
				return Promise.resolve( false );
			}

			return Promise.resolve( false );
		}

		// Classic (shortcode-rendered) add-to-cart markup only — no
		// block-checkout API is used or inferred (M04 plan §4.6). Fails
		// silently if a theme replaces even this markup.
		function bindClassicAddToCart() {
			if ( ! global.document || typeof global.document.addEventListener !== 'function' ) {
				return;
			}

			global.document.addEventListener( 'click', function ( event ) {
				var target = event && event.target ? event.target : null;
				var matchEl = target && typeof target.closest === 'function'
					? target.closest( '.single_add_to_cart_button, .add_to_cart_button' )
					: null;

				if ( ! matchEl ) {
					return;
				}

				var raw = matchEl.getAttribute ? matchEl.getAttribute( 'data-product_id' ) : null;
				var productId = raw ? parseInt( raw, 10 ) : NaN;

				if ( ! isNaN( productId ) && productId > 0 ) {
					trackEvent( 'ac', { product_id: productId } );
					flush();
				}
			} );
		}

		// Resolves visit_ref, records the initial page view (and
		// session_started for a new tab session), and wires beacon-on-unload.
		function init( trackerConfig ) {
			config = trackerConfig || {};

			if ( ! config.enabled ) {
				return;
			}

			var visit = getOrCreateVisitRef();
			visitRef  = visit.ref;

			if ( visit.isNew ) {
				trackEvent( 'ss', {} );
			}

			lastPath = config.initialPath || null;
			if ( lastPath ) {
				trackEvent( 'pv', { path: config.initialPath, page_type: config.initialPageType || 'other' } );
			}

			if ( config.commerce ) {
				if ( config.productId ) {
					trackEvent( 'pd', { product_id: config.productId } );
				}
				if ( config.isCheckout ) {
					trackEvent( 'cs', {} );
				}
				bindClassicAddToCart();
			}

			var deliver = function () {
				flush();
			};

			if ( global.document && typeof global.document.addEventListener === 'function' ) {
				global.document.addEventListener( 'visibilitychange', function () {
					if ( 'hidden' === global.document.visibilityState ) {
						deliver();
					}
				} );
			}
			if ( global.addEventListener ) {
				global.addEventListener( 'pagehide', deliver );
			}
		}

		return {
			init: init,
			onNavigate: onNavigate,
			onError: onError,
			trackEvent: trackEvent,
			flush: flush,
			getQueue: function () {
				return queue.slice();
			},
		};
	}

	global.UniversalTelegramVisitorTracker = global.UniversalTelegramVisitorTracker || createTracker();
	// Exposed only for tests/js/visitor-tracker.test.mjs.
	global.__UT_VISITOR_TRACKER_FACTORY__ = createTracker;

	if ( global.UniversalTelegramVisitorConfig ) {
		global.UniversalTelegramVisitorTracker.init( global.UniversalTelegramVisitorConfig );
	}
} )( typeof window !== 'undefined' ? window : globalThis );
