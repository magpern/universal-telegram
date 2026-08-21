/**
 * Dependency-free visitor/browser event tracking client (M04 plan §4.4,
 * §4.6, docs/adr/0019). No third-party library, no build step. Collects
 * only the bounded, PII-free fields documented in the M04 plan; never
 * reads cookies, local/session storage contents beyond its own visit_ref
 * key, query strings, form values, or raw error text. Every network call is wrapped in try/catch and never blocks
 * rendering, navigation, or checkout.
 */
( function ( global ) {
	'use strict';

	var STORAGE_KEY = 'ut_visit_ref';
	var MAX_ERRORS_PER_SESSION = 5;
	var ENDPOINT_PATH = '/wp-json/universal-telegram/v1/visitor-events';

	/**
	 * Generates a 16-byte random hex string via crypto.getRandomValues().
	 *
	 * @return {string}
	 */
	function randomHex16() {
		var bytes = new Uint8Array( 16 );
		global.crypto.getRandomValues( bytes );
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	/**
	 * Generates a UUIDv4 string.
	 *
	 * @return {string}
	 */
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

	/**
	 * Tracker instance factory — a plain object, no class needed. Kept as a
	 * factory (rather than one shared module-level singleton) so the
	 * behavioural test suite can construct fresh, isolated instances.
	 *
	 * @return {object}
	 */
	function createTracker() {
		var config       = null;
		var visitRef     = null;
		var lastPath     = null;
		var errorCount   = 0;
		var queue        = [];

		/**
		 * Reads (or creates) this tab session's visit_ref. Stable across
		 * reloads because sessionStorage survives them by design; cleared
		 * only when the tab/browser session ends (M04 plan §4.3).
		 *
		 * @return {{ref: string, isNew: boolean}}
		 */
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
				// sessionStorage unavailable (private mode, etc.) — the
				// tracker degrades to a single in-memory visit_ref rather
				// than throwing.
			}
			return { ref: fresh, isNew: true };
		}

		/**
		 * Whether collection is currently permitted: the master switch,
		 * at least one family toggle already enforced server-side, and
		 * the client-side consent suppression gate (M04 plan §4.3 — this
		 * is a suppression mechanism only, never a server-verifiable
		 * guarantee).
		 *
		 * @return {boolean}
		 */
		function consentGranted() {
			if ( ! config || ! config.enabled ) {
				return false;
			}
			if ( 'disabled' === config.consentMode ) {
				return true;
			}
			return true === global.universalTelegramConsent;
		}

		/**
		 * Enqueues one event. No-op (nothing enqueued, nothing sent) when
		 * consent is not granted — the tracker never contacts the endpoint
		 * at all in that case.
		 *
		 * @param {string} shortType Short event-type code (e.g. "pv").
		 * @param {object} data      Flat, allow-listed field object.
		 */
		function trackEvent( shortType, data ) {
			if ( ! consentGranted() ) {
				return;
			}
			queue.push( { uuid: uuid4(), type: shortType, data: data || {} } );
		}

		/**
		 * Records a same-tab navigation: emits one "nv" event pairing the
		 * previously known path with the new one, then updates the
		 * tracked path (M04 plan §4.2).
		 *
		 * @param {string} toPath The new path.
		 */
		function onNavigate( toPath ) {
			if ( null !== lastPath && lastPath !== toPath ) {
				trackEvent( 'nv', { from_path: lastPath, to_path: toPath } );
			}
			lastPath = toPath;
		}

		/**
		 * Records one client-side error, capped at MAX_ERRORS_PER_SESSION
		 * per tab session — a flat volume bound, not deduplication
		 * (M04 plan §4.5). No location, message, or stack data is ever
		 * read or transmitted.
		 *
		 * @param {string} category One of "runtime"|"promise_rejection"|"resource_load".
		 */
		function onError( category ) {
			if ( errorCount >= MAX_ERRORS_PER_SESSION ) {
				return;
			}
			errorCount++;
			trackEvent( 'je', { error_category: category } );
		}

		/**
		 * Sends every currently queued event as one batch. On failure, the
		 * queue is left intact — a retry reuses the same event objects,
		 * and therefore the same client-generated uuid, rather than
		 * regenerating one (M04 plan §4.5's idempotency-key reuse
		 * guarantee).
		 *
		 * @return {Promise<boolean>} Resolves true if the batch was handed off successfully.
		 */
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

		/**
		 * Initializes the tracker: resolves visit_ref, records the initial
		 * page view (and session_started if this is a new tab session),
		 * and wires the beacon-on-unload delivery strategy.
		 *
		 * @param {object} trackerConfig Static, cache-safe config injected by TrackerAssets.
		 */
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
	// Exposed for the dependency-free behavioural test suite only
	// (tests/js/visitor-tracker.test.mjs); not used by the tracker itself.
	global.__UT_VISITOR_TRACKER_FACTORY__ = createTracker;

	if ( global.UniversalTelegramVisitorConfig ) {
		global.UniversalTelegramVisitorTracker.init( global.UniversalTelegramVisitorConfig );
	}
} )( typeof window !== 'undefined' ? window : globalThis );
