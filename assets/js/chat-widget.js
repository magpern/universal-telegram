/**
 * M06 chat widget — vanilla JS, no build step, no framework dependency
 * (docs/plans/m06-chat-widget-plan-v1.md). Internally split into three
 * responsibilities for testability: state (sessionStorage), client
 * (REST fetch/poll), ui (DOM/a11y) — wired together at the bottom.
 *
 * Factories are exposed on window for the dependency-free Node `vm`-based
 * test harness (tests/js/, run via bin/docker/test-js.sh), matching
 * visitor-tracker.js's own precedent.
 */
( function () {
	'use strict';

	var STORAGE_PENDING_START = 'utChatPendingStart';
	var STORAGE_CONVERSATION  = 'utChatConversation';

	/**
	 * State module (M06 plan §2): sessionStorage read/write/clear only.
	 * No message text and no message cursor are ever persisted here — the
	 * cursor is derived in memory via a hydration poll (see the client
	 * module, WP4).
	 */
	function createState() {
		function readJSON( key ) {
			try {
				var raw = window.sessionStorage.getItem( key );
				if ( ! raw ) {
					return null;
				}
				var parsed = JSON.parse( raw );
				return ( parsed && typeof parsed === 'object' ) ? parsed : null;
			} catch ( error ) {
				return null;
			}
		}

		function writeJSON( key, value ) {
			try {
				window.sessionStorage.setItem( key, JSON.stringify( value ) );
			} catch ( error ) {
				// sessionStorage unavailable (private browsing, quota,
				// disabled) — fail locally; the widget simply cannot
				// persist state across a reload, never throws.
			}
		}

		function remove( key ) {
			try {
				window.sessionStorage.removeItem( key );
			} catch ( error ) {
				// See writeJSON — same non-throwing contract.
			}
		}

		return {
			getPendingStart: function () {
				var value = readJSON( STORAGE_PENDING_START );
				if (
					! value ||
					typeof value.idempotencyKey !== 'string' ||
					'' === value.idempotencyKey ||
					typeof value.secret !== 'string' ||
					'' === value.secret
				) {
					return null;
				}
				return { idempotencyKey: value.idempotencyKey, secret: value.secret };
			},

			setPendingStart: function ( idempotencyKey, secret ) {
				writeJSON( STORAGE_PENDING_START, { idempotencyKey: idempotencyKey, secret: secret } );
			},

			clearPendingStart: function () {
				remove( STORAGE_PENDING_START );
			},

			getConversation: function () {
				var value = readJSON( STORAGE_CONVERSATION );
				if (
					! value ||
					typeof value.uuid !== 'string' ||
					'' === value.uuid ||
					typeof value.secret !== 'string' ||
					'' === value.secret ||
					typeof value.startedAt !== 'number'
				) {
					return null;
				}
				return { uuid: value.uuid, secret: value.secret, startedAt: value.startedAt };
			},

			setConversation: function ( uuid, secret ) {
				writeJSON( STORAGE_CONVERSATION, { uuid: uuid, secret: secret, startedAt: Date.now() } );
				remove( STORAGE_PENDING_START );
			},

			clearConversation: function () {
				remove( STORAGE_CONVERSATION );
			},

			clearAll: function () {
				remove( STORAGE_PENDING_START );
				remove( STORAGE_CONVERSATION );
			},
		};
	}

	window.__UT_CHAT_WIDGET_STATE_FACTORY__ = createState;

	// -- REST client module (M06 plan §3, WP4) -------------------------

	var POLL_INTERVAL_MS      = 3000;
	var POLL_BACKOFF_CAP_MS   = 30000;
	var MAX_TEXT_CHARS        = 4096;

	function randomHex( byteLength ) {
		var bytes = new Uint8Array( byteLength );
		window.crypto.getRandomValues( bytes );
		var out = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			out += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	function generateSecret() {
		return randomHex( 32 );
	}

	function generateUuid() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		var bytes = new Uint8Array( 16 );
		window.crypto.getRandomValues( bytes );
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
	 * Builds the REST client bound to one state module instance and the
	 * static {restUrl} config from the JSON data island. Cursor and
	 * dedup-set are held only in this closure — never persisted — so a
	 * reload always re-derives them via the hydration poll (M06 plan §2).
	 */
	function createClient( state, config ) {
		var listeners      = {};
		var cursor         = null; // null until the first poll (hydration or same-page) resolves.
		var renderedIds    = {};
		var pollAbort      = null;
		var pollTimer      = null;
		var pollBackoffMs  = POLL_INTERVAL_MS;
		var pollingEnabled = false;
		var visibilityBound = false;

		function on( name, handler ) {
			listeners[ name ] = listeners[ name ] || [];
			listeners[ name ].push( handler );
		}

		function emit( name, payload ) {
			( listeners[ name ] || [] ).forEach( function ( handler ) {
				handler( payload );
			} );
		}

		function endpoint( path ) {
			return config.restUrl + path;
		}

		function isVisible() {
			return ! window.document || window.document.visibilityState === 'visible';
		}

		// -- start -------------------------------------------------------

		function requestStart( idempotencyKey, secret, allowRetry ) {
			return window.fetch(
				endpoint( '/conversations' ),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Idempotency-Key': idempotencyKey,
						'X-Universal-Telegram-Conversation-Secret': secret,
					},
					body: '{}',
				}
			).then(
				function ( response ) {
					if ( 200 === response.status ) {
						return response.json().then( function ( data ) {
							state.setConversation( data.conversation_uuid, secret );
							return { uuid: data.conversation_uuid, secret: secret };
						} );
					}
					if ( 503 === response.status ) {
						state.clearPendingStart();
						emit( 'state', { status: 'unavailable' } );
						throw { terminal: true };
					}
					if ( 400 === response.status ) {
						state.clearPendingStart();
						emit( 'state', { status: 'transient-failure' } );
						throw { terminal: true };
					}
					if ( allowRetry ) {
						return requestStart( idempotencyKey, secret, false );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				},
				function () {
					if ( allowRetry ) {
						return requestStart( idempotencyKey, secret, false );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				}
			);
		}

		function ensureStarted() {
			var existing = state.getConversation();
			if ( existing ) {
				return Promise.resolve( existing );
			}

			var pending = state.getPendingStart();
			var idempotencyKey;
			var secret;

			if ( pending ) {
				idempotencyKey = pending.idempotencyKey;
				secret = pending.secret;
			} else {
				idempotencyKey = generateUuid();
				secret = generateSecret();
				state.setPendingStart( idempotencyKey, secret );
			}

			emit( 'state', { status: 'sending' } );
			return requestStart( idempotencyKey, secret, true );
		}

		// -- send ----------------------------------------------------------

		function requestSend( conversation, text, idempotencyKey, allowRetry ) {
			return window.fetch(
				endpoint( '/conversations/' + conversation.uuid + '/messages' ),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + conversation.secret,
						'Idempotency-Key': idempotencyKey,
					},
					body: JSON.stringify( { text: text } ),
				}
			).then(
				function ( response ) {
					if ( 200 === response.status ) {
						return true;
					}
					if ( 404 === response.status ) {
						state.clearAll();
						stopPolling();
						emit( 'state', { status: 'ended' } );
						throw { terminal: true };
					}
					if ( 400 === response.status ) {
						emit( 'state', { status: 'transient-failure' } );
						throw { terminal: true };
					}
					if ( allowRetry ) {
						return requestSend( conversation, text, idempotencyKey, false );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				},
				function () {
					if ( allowRetry ) {
						return requestSend( conversation, text, idempotencyKey, false );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				}
			);
		}

		function sendMessage( text ) {
			if ( 'string' !== typeof text || '' === text || text.length > MAX_TEXT_CHARS ) {
				emit( 'state', { status: 'transient-failure' } );
				return Promise.reject( { terminal: true } );
			}

			var idempotencyKey = generateUuid();

			return ensureStarted().then( function ( conversation ) {
				emit( 'state', { status: 'sending' } );
				return requestSend( conversation, text, idempotencyKey, true ).then( function () {
					emit( 'state', { status: 'active' } );
					startPolling();
					return true;
				} );
			} );
		}

		// -- poll ------------------------------------------------------------

		function scheduleNextPoll( delayMs ) {
			if ( ! pollingEnabled ) {
				return;
			}
			pollTimer = window.setTimeout( function () {
				pollTimer = null;
				runPoll();
			}, delayMs );
		}

		function runPoll() {
			var conversation = state.getConversation();
			if ( ! pollingEnabled || ! conversation ) {
				return;
			}
			if ( ! isVisible() ) {
				return;
			}

			if ( pollAbort ) {
				pollAbort.abort();
			}
			pollAbort = ( 'undefined' !== typeof window.AbortController ) ? new window.AbortController() : null;

			var sinceId = null === cursor ? 0 : cursor;

			window.fetch(
				endpoint( '/conversations/' + conversation.uuid + '?since_id=' + sinceId ),
				{
					method: 'GET',
					headers: { Authorization: 'Bearer ' + conversation.secret },
					signal: pollAbort ? pollAbort.signal : undefined,
				}
			).then(
				function ( response ) {
					if ( 404 === response.status ) {
						state.clearAll();
						stopPolling();
						emit( 'state', { status: 'ended' } );
						return;
					}
					if ( 200 !== response.status ) {
						pollBackoffMs = Math.min( pollBackoffMs * 2, POLL_BACKOFF_CAP_MS );
						scheduleNextPoll( pollBackoffMs );
						return;
					}
					return response.json().then( function ( data ) {
						pollBackoffMs = POLL_INTERVAL_MS;

						( data.messages || [] ).forEach( function ( message ) {
							if ( renderedIds[ message.id ] ) {
								return;
							}
							renderedIds[ message.id ] = true;
							if ( null === cursor || message.id > cursor ) {
								cursor = message.id;
							}
							emit( 'message', message );
						} );

						if ( null === cursor ) {
							cursor = 0;
						}

						if ( 'resolved' === data.status || 'archived' === data.status ) {
							state.clearAll();
							stopPolling();
							emit( 'state', { status: 'ended' } );
							return;
						}

						scheduleNextPoll( pollBackoffMs );
					} );
				},
				function () {
					pollBackoffMs = Math.min( pollBackoffMs * 2, POLL_BACKOFF_CAP_MS );
					scheduleNextPoll( pollBackoffMs );
				}
			);
		}

		function onVisibilityChange() {
			if ( isVisible() ) {
				runPoll();
			}
		}

		function startPolling() {
			if ( pollingEnabled ) {
				return;
			}
			pollingEnabled = true;
			pollBackoffMs = POLL_INTERVAL_MS;

			if ( ! visibilityBound && window.document && typeof window.document.addEventListener === 'function' ) {
				window.document.addEventListener( 'visibilitychange', onVisibilityChange );
				visibilityBound = true;
			}

			runPoll();
		}

		function stopPolling() {
			pollingEnabled = false;
			if ( pollTimer ) {
				window.clearTimeout( pollTimer );
				pollTimer = null;
			}
			if ( pollAbort ) {
				pollAbort.abort();
				pollAbort = null;
			}
		}

		function endConversation() {
			stopPolling();
			state.clearAll();
			cursor = null;
			renderedIds = {};
			emit( 'state', { status: 'ended' } );
		}

		function open() {
			var conversation = state.getConversation();
			if ( conversation ) {
				emit( 'state', { status: 'active' } );
				startPolling();
			} else {
				emit( 'state', { status: 'idle' } );
			}
		}

		return {
			on: on,
			open: open,
			sendMessage: sendMessage,
			endConversation: endConversation,
			stopPolling: stopPolling,
		};
	}

	window.__UT_CHAT_WIDGET_CLIENT_FACTORY__ = createClient;
} )();
