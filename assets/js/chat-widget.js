/**
 * M06 chat widget — vanilla JS, no build step, no framework dependency.
 * M06.3.1 (ADR-0025) rewrite: authenticated-only access, server-derived
 * identity (no name form), invisible start-then-message at Send time, and
 * cross-session resume via GET /conversations/mine. Internally split into
 * three responsibilities for testability: state (sessionStorage), client
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
	 * State module: sessionStorage read/write/clear only. No message text
	 * and no message cursor are ever persisted here — the cursor is derived
	 * in memory via a hydration poll (see the client module).
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

	// -- REST client module ----------------------------------------------

	var POLL_INTERVAL_MS    = 3000;
	var POLL_BACKOFF_CAP_MS = 30000;
	var MAX_TEXT_CHARS      = 4096;

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
	 * static {restUrl, loggedIn, nonce, ...} config from the JSON data
	 * island. Cursor and dedup-set are held only in this closure — never
	 * persisted — so a reload always re-derives them via the hydration poll.
	 */
	function createClient( state, config ) {
		var listeners       = {};
		var cursor          = null; // null until the first poll (hydration or same-page) resolves.
		var renderedIds     = {};
		var pollAbort       = null;
		var pollTimer       = null;
		var pollBackoffMs   = POLL_INTERVAL_MS;
		var pollingEnabled  = false;
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

		function authHeaders( extra ) {
			var headers = { 'X-WP-Nonce': config.nonce || '' };
			for ( var key in extra ) {
				if ( Object.prototype.hasOwnProperty.call( extra, key ) ) {
					headers[ key ] = extra[ key ];
				}
			}
			return headers;
		}

		function handleSignedOut() {
			state.clearAll();
			stopPolling(); // eslint-disable-line no-use-before-define
			emit( 'state', { status: 'signed-out' } );
		}

		// -- resume ("mine") -----------------------------------------------

		function requestMine() {
			return window.fetch(
				endpoint( '/conversations/mine' ),
				{ method: 'GET', credentials: 'same-origin', headers: authHeaders( {} ) }
			).then(
				function ( response ) {
					if ( 401 === response.status ) {
						handleSignedOut();
						return null;
					}
					if ( 200 !== response.status ) {
						return null;
					}
					return response.json().then(
						function ( data ) {
							if ( data && data.conversation_uuid && data.secret ) {
								return { uuid: data.conversation_uuid, secret: data.secret };
							}
							return null;
						},
						function () {
							return null;
						}
					);
				},
				function () {
					return null;
				}
			);
		}

		// -- start (invisible, only ever called from sendMessage) ----------

		function requestStart( idempotencyKey, secret, allowRetry ) {
			return window.fetch(
				endpoint( '/conversations' ),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: authHeaders( {
						'Content-Type': 'application/json',
						'Idempotency-Key': idempotencyKey,
						'X-Universal-Telegram-Conversation-Secret': secret,
					} ),
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
					if ( 401 === response.status ) {
						handleSignedOut();
						throw { terminal: true };
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
					if ( 429 === response.status ) {
						// Retryable, never a reason to mint a fresh
						// idempotency key/secret pair or to clear the
						// pending start — the same pair is reused verbatim
						// on the visitor's next attempt.
						emit( 'state', { status: 'rate-limited' } );
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

			return requestStart( idempotencyKey, secret, true );
		}

		// -- send (the only conversation-creating user action) -------------

		function requestSend( conversation, text, idempotencyKey, allowRetry ) {
			return window.fetch(
				endpoint( '/conversations/' + conversation.uuid + '/messages' ),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: authHeaders( {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + conversation.secret,
						'Idempotency-Key': idempotencyKey,
					} ),
					body: JSON.stringify( { text: text } ),
				}
			).then(
				function ( response ) {
					if ( 200 === response.status ) {
						// The server's response contract carries an optional
						// `delivery` field: 'pending' means the message is
						// durably stored and will be delivered, but not yet
						// confirmed sent to Telegram — a distinct, truthful
						// UI state, never a false "sent".
						return response.json().then(
							function ( data ) {
								return { pending: data && 'pending' === data.delivery };
							},
							function () {
								return { pending: false };
							}
						);
					}
					if ( 401 === response.status ) {
						handleSignedOut();
						throw { terminal: true };
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
					if ( 429 === response.status ) {
						emit( 'state', { status: 'rate-limited' } );
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
			// M06.3.1 addendum: an anonymous send is permitted only when the
			// site owner has explicitly enabled it; a logged-in visitor
			// always uses the authenticated flow above, regardless of this
			// setting.
			if ( ! config.loggedIn && ! config.anonymousChatAllowed ) {
				handleSignedOut();
				return Promise.reject( { terminal: true } );
			}
			if ( 'string' !== typeof text || '' === text || text.length > MAX_TEXT_CHARS ) {
				emit( 'state', { status: 'transient-failure' } );
				return Promise.reject( { terminal: true } );
			}

			var idempotencyKey = generateUuid();

			// Invisible start-then-message (M06.3.1, ADR-0025): opening the
			// panel never creates a conversation; ensureStarted() only ever
			// runs as part of this Send action, and only when no conversation
			// is already cached (a fresh browser/tab, or one that already
			// resumed via requestMine()).
			return ensureStarted().then( function ( conversation ) {
				emit( 'state', { status: 'sending' } );
				return requestSend( conversation, text, idempotencyKey, true ).then( function ( result ) {
					emit( 'state', { status: result.pending ? 'pending' : 'active' } );
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
					credentials: 'same-origin',
					headers: authHeaders( { Authorization: 'Bearer ' + conversation.secret } ),
					signal: pollAbort ? pollAbort.signal : undefined,
				}
			).then(
				function ( response ) {
					if ( 401 === response.status ) {
						handleSignedOut();
						return;
					}
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

		/**
		 * Called on panel open. Never creates a conversation (M06.3.1,
		 * ADR-0025): a logged-out visitor sees only the sign-in state; an
		 * authenticated visitor with a cached conversation resumes polling
		 * immediately; one with none cached checks GET /conversations/mine
		 * for a resumable one (a different browser/session), and otherwise
		 * lands on an enabled, empty composer — no row exists yet until the
		 * first actual Send.
		 */
		function open() {
			if ( ! config.loggedIn ) {
				if ( ! config.anonymousChatAllowed ) {
					emit( 'state', { status: 'signed-out' } );
					return;
				}

				// Anonymous chat has no cross-session resume — GET
				// /conversations/mine remains authenticated-only always
				// (M06.3.1 addendum) — so an anonymous visitor either
				// resumes this tab's own cached conversation or lands on an
				// empty composer; nothing is looked up server-side here.
				var anonymous_conversation = state.getConversation();
				if ( anonymous_conversation ) {
					emit( 'state', { status: 'active' } );
					startPolling();
				} else {
					emit( 'state', { status: 'idle' } );
				}
				return;
			}

			var conversation = state.getConversation();
			if ( conversation ) {
				emit( 'state', { status: 'active' } );
				startPolling();
				return;
			}

			emit( 'state', { status: 'checking' } );
			requestMine().then( function ( found ) {
				if ( found ) {
					state.setConversation( found.uuid, found.secret );
					emit( 'state', { status: 'active' } );
					startPolling();
				} else {
					emit( 'state', { status: 'idle' } );
				}
			} );
		}

		return {
			on: on,
			open: open,
			sendMessage: sendMessage,
			stopPolling: stopPolling,
		};
	}

	window.__UT_CHAT_WIDGET_CLIENT_FACTORY__ = createClient;

	// -- UI module ---------------------------------------------------------

	var UI_ANNOUNCEMENTS = {
		idle: '',
		checking: '',
		active: '',
		sending: 'Sending…',
		pending: 'Message received — delivery in progress.',
		'rate-limited': 'Too many attempts. Please wait a moment and try again.',
		unavailable: 'Chat is currently unavailable.',
		'transient-failure': 'Something went wrong. Please try again.',
		ended: 'This conversation has ended.',
		'signed-out': 'Please sign in to chat.',
	};

	/**
	 * Pure mapping from a client "state" event status to the UI-facing
	 * facts: the live-region announcement and whether the composer should
	 * accept input. No DOM access, so this is unit-testable without a real
	 * document (docs/plans/ m06-chat-widget-plan-v1.md §9 — no jsdom/browser
	 * runner here).
	 *
	 * @param {string} status
	 * @return {{status: string, announce: string, inputDisabled: boolean}}
	 */
	function describeUiState( status ) {
		var known = Object.prototype.hasOwnProperty.call( UI_ANNOUNCEMENTS, status ) ? status : 'idle';
		return {
			status: known,
			announce: UI_ANNOUNCEMENTS[ known ],
			inputDisabled: 'sending' === known || 'ended' === known || 'unavailable' === known || 'signed-out' === known || 'checking' === known,
		};
	}

	/**
	 * Builds and wires the widget's DOM. Not unit-tested beyond
	 * describeUiState() above — real focus/keyboard/ARIA/viewport
	 * behavior is covered by the one-time manual checklist, since this
	 * repository has no jsdom/browser test runner.
	 *
	 * @param {object} client A REST client instance (see createClient()).
	 * @param {object} config The static config data island (loggedIn, nonce,
	 *                        loginUrl, registerUrl, geometry, motionDefault,
	 *                        labelVisitor, labelOperator).
	 * @return {{root: Node, open: Function, close: Function}}
	 */
	function buildWidget( client, config ) {
		var doc = window.document;
		config = config || {};

		var labelVisitor  = config.labelVisitor || 'You';
		var labelOperator = config.labelOperator || 'Support';

		var root = doc.createElement( 'div' );
		root.className = 'ut-chat-widget';
		root.setAttribute( 'data-geometry', 'square' === config.geometry ? 'square' : 'round' );
		root.setAttribute( 'data-motion', 'reduced' === config.motionDefault ? 'reduced' : 'standard' );

		var toggleButton = doc.createElement( 'button' );
		toggleButton.type = 'button';
		toggleButton.className = 'ut-chat-widget__toggle';
		toggleButton.setAttribute( 'aria-expanded', 'false' );
		toggleButton.textContent = 'Open chat';

		var headingId = 'ut-chat-widget-heading';

		var panel = doc.createElement( 'div' );
		panel.className = 'ut-chat-widget__panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-modal', 'true' );
		panel.setAttribute( 'aria-labelledby', headingId );
		panel.hidden = true;

		var heading = doc.createElement( 'h2' );
		heading.id = headingId;
		heading.className = 'ut-chat-widget__heading';
		heading.textContent = 'Chat';

		var closeButton = doc.createElement( 'button' );
		closeButton.type = 'button';
		closeButton.className = 'ut-chat-widget__close';
		closeButton.textContent = 'Close';
		closeButton.setAttribute( 'aria-label', 'Close chat' );

		var header = doc.createElement( 'div' );
		header.className = 'ut-chat-widget__header';
		header.appendChild( heading );
		header.appendChild( closeButton );

		// Logged-out state (M06.3.1, ADR-0025): sign-in (+ create-account,
		// only when the site currently allows registration) links only —
		// no name field, history, composer, or any conversation control.
		var signin = doc.createElement( 'div' );
		signin.className = 'ut-chat-widget__signin';
		signin.hidden = true;

		var signinMessage = doc.createElement( 'p' );
		signinMessage.textContent = 'Sign in to chat with our support team.';
		signin.appendChild( signinMessage );

		var signinLink = doc.createElement( 'a' );
		signinLink.className = 'ut-chat-widget__signin-link';
		signinLink.textContent = 'Sign in to chat';
		signinLink.href = config.loginUrl || '#';
		signin.appendChild( signinLink );

		if ( config.registerUrl ) {
			var registerLink = doc.createElement( 'a' );
			registerLink.className = 'ut-chat-widget__signin-link ut-chat-widget__signin-link--secondary';
			registerLink.textContent = 'Create account';
			registerLink.href = config.registerUrl;
			signin.appendChild( registerLink );
		}

		var log = doc.createElement( 'div' );
		log.className = 'ut-chat-widget__log';
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );

		var newMessagesButton = doc.createElement( 'button' );
		newMessagesButton.type = 'button';
		newMessagesButton.className = 'ut-chat-widget__new-messages';
		newMessagesButton.textContent = 'New messages';
		newMessagesButton.hidden = true;

		var statusRegion = doc.createElement( 'div' );
		statusRegion.className = 'ut-chat-widget__status';
		statusRegion.setAttribute( 'role', 'status' );
		statusRegion.setAttribute( 'aria-live', 'polite' );

		var form = doc.createElement( 'form' );
		form.className = 'ut-chat-widget__form';

		var input = doc.createElement( 'textarea' );
		input.className = 'ut-chat-widget__input';
		input.setAttribute( 'aria-label', 'Message' );

		var sendButton = doc.createElement( 'button' );
		sendButton.type = 'submit';
		sendButton.className = 'ut-chat-widget__send';
		sendButton.textContent = 'Send';

		form.appendChild( input );
		form.appendChild( sendButton );

		panel.appendChild( header );
		panel.appendChild( signin );
		panel.appendChild( log );
		panel.appendChild( newMessagesButton );
		panel.appendChild( statusRegion );
		panel.appendChild( form );

		root.appendChild( toggleButton );
		root.appendChild( panel );

		var renderedMessageIds  = {};
		var pendingVisitorTexts = []; // Reconciles an optimistic local bubble with its eventual polled echo (same text, visitor direction) so it is never rendered twice.
		var lastRenderedDateKey = null;

		function localDateKey( date ) {
			return date.getFullYear() + '-' + date.getMonth() + '-' + date.getDate();
		}

		function formatLocalTime( isoLikeUtc ) {
			try {
				var date = new Date( String( isoLikeUtc ).replace( ' ', 'T' ) + 'Z' );
				if ( isNaN( date.getTime() ) ) {
					return '';
				}
				return date.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
			} catch ( error ) {
				return '';
			}
		}

		function maybeInsertDateSeparator( isoLikeUtc ) {
			var date = new Date( String( isoLikeUtc ).replace( ' ', 'T' ) + 'Z' );
			if ( isNaN( date.getTime() ) ) {
				return;
			}
			var key = localDateKey( date );
			if ( key === lastRenderedDateKey ) {
				return;
			}
			lastRenderedDateKey = key;

			var separator = doc.createElement( 'div' );
			separator.className = 'ut-chat-widget__date-separator';
			separator.textContent = date.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
			log.appendChild( separator );
		}

		// Sets both the native `hidden` attribute and an explicit inline
		// `display` style. The inline style is defense-in-depth against a
		// host theme's own global CSS (observed in the field: a rule with
		// enough specificity to defeat the bare `[hidden]` selector) —
		// otherwise a stale/half-hidden section can render alongside the
		// intended one, showing e.g. the sign-in prompt next to an already-
		// populated composer and message log.
		function setVisible( element, visible ) {
			element.hidden = ! visible;
			element.style.display = visible ? '' : 'none';
		}

		function showSignedOut() {
			setVisible( signin, true );
			setVisible( log, false );
			setVisible( newMessagesButton, false );
			setVisible( statusRegion, false );
			setVisible( form, false );
		}

		function showChat() {
			setVisible( signin, false );
			setVisible( log, true );
			setVisible( statusRegion, true );
			setVisible( form, true );
		}

		// M06.3.1 addendum: an anonymous-allowed logged-out visitor also gets
		// the composer immediately, not the sign-in view.
		( config.loggedIn || config.anonymousChatAllowed ? showChat : showSignedOut )();

		// Accessible "New messages" affordance (M06.3.1, ADR-0025): the log
		// always keeps the newest message visible UNLESS the visitor has
		// intentionally scrolled up, in which case a new message never
		// yanks the view back down — this button does that instead.
		function isScrolledToBottom() {
			return log.scrollHeight - log.scrollTop - log.clientHeight < 24;
		}

		newMessagesButton.addEventListener( 'click', function () {
			log.scrollTop = log.scrollHeight;
			setVisible( newMessagesButton, false );
		} );

		function appendMessage( message, forceScroll ) {
			if ( renderedMessageIds[ message.id ] ) {
				return;
			}
			renderedMessageIds[ message.id ] = true;

			if ( 'visitor' === message.direction && pendingVisitorTexts.length && pendingVisitorTexts[ 0 ] === message.text ) {
				pendingVisitorTexts.shift();
				return;
			}

			var wasAtBottom = forceScroll || isScrolledToBottom();

			if ( message.created_at ) {
				maybeInsertDateSeparator( message.created_at );
			}

			var isOperator = 'operator' === message.direction;

			var row = doc.createElement( 'div' );
			row.className = 'ut-chat-widget__message ut-chat-widget__message--' +
				( isOperator ? 'operator' : 'visitor' );
			row.setAttribute( 'data-delivery', message.delivery_state || 'sent' );

			var textNode = doc.createElement( 'div' );
			// Text-only rendering: textContent only, never innerHTML, for
			// visitor- or Telegram-origin text alike.
			textNode.textContent = message.text;
			row.appendChild( textNode );

			if ( message.created_at ) {
				var meta = doc.createElement( 'span' );
				meta.className = 'ut-chat-widget__message-meta';
				meta.textContent = ( isOperator ? labelOperator : labelVisitor ) + ' · ' + formatLocalTime( message.created_at );
				row.appendChild( meta );
			}

			log.appendChild( row );

			if ( wasAtBottom ) {
				log.scrollTop = log.scrollHeight;
				setVisible( newMessagesButton, false );
			} else {
				setVisible( newMessagesButton, true );
			}
		}

		function setStatus( status ) {
			var described = describeUiState( status );
			statusRegion.textContent = described.announce;
			statusRegion.className = 'ut-chat-widget__status ut-chat-widget__status--' + described.status;
			input.disabled = described.inputDisabled;
			sendButton.disabled = described.inputDisabled;

			if ( 'signed-out' === described.status ) {
				showSignedOut();
			}

			// 'idle' means: authenticated, no conversation yet — the visitor
			// has never sent a message in this one. A personalized greeting
			// there is friendlier than a bare, empty textarea; every later
			// state (an existing conversation, or a genuinely anonymous
			// visitor) keeps the generic placeholder.
			if ( 'idle' === described.status && config.loggedIn && config.firstName ) {
				input.placeholder = 'What’s on your mind, ' + config.firstName + '?';
			} else if ( 'idle' === described.status ) {
				input.placeholder = 'What’s on your mind?';
			}

			if ( 'ended' === described.status && ! statusRegion.querySelector( '.ut-chat-widget__restart' ) ) {
				var restart = doc.createElement( 'button' );
				restart.type = 'button';
				restart.className = 'ut-chat-widget__restart';
				restart.textContent = 'Start a new conversation';
				restart.addEventListener( 'click', function () {
					renderedMessageIds = {};
					pendingVisitorTexts = [];
					lastRenderedDateKey = null;
					log.textContent = '';
					setVisible( newMessagesButton, false );
					statusRegion.textContent = '';
					statusRegion.className = 'ut-chat-widget__status';
					input.disabled = false;
					sendButton.disabled = false;
				} );
				statusRegion.appendChild( restart );
			}
		}

		var lastFocused = null;

		function focusableElements() {
			var all = panel.querySelectorAll( 'button, textarea, input, [href], [tabindex]:not([tabindex="-1"])' );
			var visible = [];
			for ( var i = 0; i < all.length; i++ ) {
				// Excludes controls inside a currently-hidden section (the
				// `hidden` attribute alone does not stop querySelectorAll
				// from matching descendants) so Tab never traps focus on an
				// invisible field.
				if ( null !== all[ i ].offsetParent || 'BODY' === all[ i ].tagName ) {
					visible.push( all[ i ] );
				}
			}
			return visible;
		}

		function trapFocus( event ) {
			if ( 'Tab' !== event.key ) {
				return;
			}
			var focusable = focusableElements();
			if ( 0 === focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && doc.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && doc.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}

		function onPanelKeydown( event ) {
			if ( 'Escape' === event.key ) {
				closePanel(); // eslint-disable-line no-use-before-define
				return;
			}
			trapFocus( event );
		}

		function openPanel() {
			panel.hidden = false;
			toggleButton.setAttribute( 'aria-expanded', 'true' );
			lastFocused = doc.activeElement;
			( config.loggedIn || config.anonymousChatAllowed ? input : signinLink ).focus();
			panel.addEventListener( 'keydown', onPanelKeydown );
			client.open();
		}

		function closePanel() {
			panel.hidden = true;
			toggleButton.setAttribute( 'aria-expanded', 'false' );
			panel.removeEventListener( 'keydown', onPanelKeydown );
			client.stopPolling();
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			} else {
				toggleButton.focus();
			}
		}

		toggleButton.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				openPanel();
			} else {
				closePanel();
			}
		} );

		closeButton.addEventListener( 'click', closePanel );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var text = input.value;
			if ( ! text ) {
				return;
			}

			pendingVisitorTexts.push( text );
			appendMessage(
				{
					id: 'local-' + Date.now() + '-' + pendingVisitorTexts.length,
					direction: 'visitor',
					text: text,
					created_at: new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ),
					delivery_state: 'queued',
				},
				true
			);
			input.value = '';

			client.sendMessage( text ).catch( function () {
				// Failure is already surfaced via the 'state' event above.
			} );
		} );

		client.on( 'message', function ( message ) {
			appendMessage( message, false );
		} );
		client.on( 'state', function ( event ) {
			setStatus( event.status );
		} );

		return {
			root: root,
			open: openPanel,
			close: closePanel,
		};
	}

	window.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__ = describeUiState;
	window.__UT_CHAT_WIDGET_UI_FACTORY__ = buildWidget;

	// -- Bootstrap / lifecycle wiring --------------------------------------

	/**
	 * Reads the static config data island. Returns null if absent or
	 * malformed -- e.g. the widget's own assets were not enqueued for this
	 * request at all.
	 *
	 * @return {object|null}
	 */
	function readConfig() {
		if ( ! window.document || typeof window.document.getElementById !== 'function' ) {
			return null;
		}
		var element = window.document.getElementById( 'ut-chat-widget-config' );
		if ( ! element ) {
			return null;
		}
		try {
			var parsed = JSON.parse( element.textContent );
			return ( parsed && typeof parsed === 'object' ) ? parsed : null;
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Mounts the widget. Deliberately does not open the panel or start
	 * polling on load, even when a conversation already exists in
	 * sessionStorage (reload/re-entry) -- polling only ever runs while the
	 * panel is open, and opening is always the visitor's own action, never
	 * automatic (privacy-minimal).
	 */
	var activeWidget = null;

	/**
	 * Supported cross-plugin entry: opens the chat panel when the widget is
	 * mounted on this page. No-op when assets/config are absent.
	 */
	function openChatFromExternal() {
		if ( activeWidget && typeof activeWidget.open === 'function' ) {
			activeWidget.open();
		}
	}

	function boot() {
		var config = readConfig();
		if ( ! config || ! window.document || ! window.document.body ) {
			return;
		}

		var state  = createState();
		var client = createClient( state, config );
		var widget = buildWidget( client, config );

		activeWidget = widget;
		window.document.body.appendChild( widget.root );

		window.UniversalTelegramChat = {
			open: openChatFromExternal,
		};

		window.document.addEventListener( 'universal-telegram:open-chat', openChatFromExternal );
	}

	if ( window.document ) {
		if ( 'loading' === window.document.readyState ) {
			window.document.addEventListener( 'DOMContentLoaded', boot );
		} else {
			boot();
		}
	}
} )();
