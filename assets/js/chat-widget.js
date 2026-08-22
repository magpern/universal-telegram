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
	var MIN_DISPLAY_NAME_CHARS = 1;
	var MAX_DISPLAY_NAME_CHARS = 80;

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

	/**
	 * Client-side defense-in-depth mirror of ConversationDisplay::
	 * is_valid_display_name() (M06.3, ADR-0024) — the server remains
	 * authoritative; this only avoids an obviously-invalid round trip.
	 *
	 * @param {string} trimmedValue
	 * @return {boolean}
	 */
	function isValidDisplayName( trimmedValue ) {
		return 'string' === typeof trimmedValue &&
			trimmedValue.length >= MIN_DISPLAY_NAME_CHARS &&
			trimmedValue.length <= MAX_DISPLAY_NAME_CHARS;
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
							emit( 'displayNameRequired', ! ( data && false === data.display_name_required ) );
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
					if ( 429 === response.status ) {
						// Retryable, never a reason to mint a fresh
						// idempotency key/secret pair or to clear the
						// pending start — the same pair is reused verbatim
						// on the visitor's next attempt (M06.2 corrective
						// plan v2 §3.5, ADR-0023 amendment).
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

			emit( 'state', { status: 'sending' } );
			return requestStart( idempotencyKey, secret, true );
		}

		// -- send ----------------------------------------------------------

		function requestSend( conversation, text, idempotencyKey, allowRetry, displayName ) {
			var body = { text: text };
			if ( displayName ) {
				body.display_name = displayName;
			}

			return window.fetch(
				endpoint( '/conversations/' + conversation.uuid + '/messages' ),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'Authorization': 'Bearer ' + conversation.secret,
						'Idempotency-Key': idempotencyKey,
					},
					body: JSON.stringify( body ),
				}
			).then(
				function ( response ) {
					if ( 200 === response.status ) {
						// The server's response contract (M06.2 corrective
						// plan v2 §3.7, ADR-0023 amendment) carries an
						// optional `delivery` field: 'pending' means the
						// message is durably stored and will be delivered,
						// but not yet confirmed sent to Telegram — a
						// distinct, truthful UI state, never a false
						// "sent".
						return response.json().then(
							function ( data ) {
								return { pending: data && 'pending' === data.delivery };
							},
							function () {
								return { pending: false };
							}
						);
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
						return requestSend( conversation, text, idempotencyKey, false, displayName );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				},
				function () {
					if ( allowRetry ) {
						return requestSend( conversation, text, idempotencyKey, false, displayName );
					}
					emit( 'state', { status: 'transient-failure' } );
					throw { terminal: true };
				}
			);
		}

		function sendMessage( text, displayName ) {
			if ( 'string' !== typeof text || '' === text || text.length > MAX_TEXT_CHARS ) {
				emit( 'state', { status: 'transient-failure' } );
				return Promise.reject( { terminal: true } );
			}

			var idempotencyKey = generateUuid();

			return ensureStarted().then( function ( conversation ) {
				emit( 'state', { status: 'sending' } );
				return requestSend( conversation, text, idempotencyKey, true, displayName ).then( function ( result ) {
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

						// Reload-safe required-name contract (M06.3, ADR-0024):
						// display_name_required is never the name itself, only
						// whether one is still needed — a page reload re-derives
						// whether to show the required-name step from this poll
						// response, never from client-side state alone.
						emit( 'displayNameRequired', ! ( data && false === data.display_name_required ) );

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
			emit( 'displayNameRequired', true );
		}

		function open() {
			var conversation = state.getConversation();
			if ( conversation ) {
				emit( 'state', { status: 'active' } );
				startPolling();
			} else {
				emit( 'displayNameRequired', true );
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

	// -- UI module (M06 plan §5, WP5) -----------------------------------

	var UI_ANNOUNCEMENTS = {
		idle: '',
		sending: 'Sending…',
		active: '',
		pending: 'Message received — delivery in progress.',
		'rate-limited': 'Too many attempts. Please wait a moment and try again.',
		unavailable: 'Chat is currently unavailable.',
		'transient-failure': 'Something went wrong. Please try again.',
		ended: 'This conversation has ended.',
	};

	/**
	 * Pure mapping from a client "state" event status to the seven-state
	 * model's UI-facing facts (M06 plan §6): the live-region announcement
	 * and whether the composer should accept input. No DOM access, so
	 * this is unit-testable without a real document (docs/plans/
	 * m06-chat-widget-plan-v1.md §9 — no jsdom/browser runner here).
	 *
	 * @param {string} status One of the seven-state model's values.
	 * @return {{status: string, announce: string, inputDisabled: boolean}}
	 */
	function describeUiState( status ) {
		var known = Object.prototype.hasOwnProperty.call( UI_ANNOUNCEMENTS, status ) ? status : 'idle';
		return {
			status: known,
			announce: UI_ANNOUNCEMENTS[ known ],
			inputDisabled: 'sending' === known || 'ended' === known || 'unavailable' === known,
		};
	}

	/**
	 * Builds and wires the widget's DOM. Not unit-tested beyond
	 * describeUiState() above — real focus/keyboard/ARIA/viewport
	 * behavior is covered by the one-time manual checklist (M06 plan §9),
	 * since this repository has no jsdom/browser test runner.
	 *
	 * @param {object} client A REST client instance (see createClient()).
	 * @param {object} config The static config data island (M06.3 adds
	 *                        geometry/motionDefault/labelVisitor/labelOperator).
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

		var endButton = doc.createElement( 'button' );
		endButton.type = 'button';
		endButton.className = 'ut-chat-widget__end';
		endButton.textContent = 'End conversation';

		var header = doc.createElement( 'div' );
		header.className = 'ut-chat-widget__header';
		header.appendChild( heading );
		header.appendChild( endButton );
		header.appendChild( closeButton );

		var log = doc.createElement( 'div' );
		log.className = 'ut-chat-widget__log';
		log.setAttribute( 'role', 'log' );
		log.setAttribute( 'aria-live', 'polite' );

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

		// Required visitor display-name step (M06.3, ADR-0024): shown
		// instead of the composer until a name is known to be stored
		// (display_name_required === false) or the visitor has just
		// supplied one in this tab. The disclosure sentence states plainly
		// that the name is shared with the support team in Telegram.
		var nameForm = doc.createElement( 'form' );
		nameForm.className = 'ut-chat-widget__name-form';

		var nameDisclosure = doc.createElement( 'p' );
		nameDisclosure.className = 'ut-chat-widget__name-disclosure';
		nameDisclosure.textContent = 'Enter your name to start. It will be shared with the support team in Telegram.';

		var nameInput = doc.createElement( 'input' );
		nameInput.type = 'text';
		nameInput.className = 'ut-chat-widget__input ut-chat-widget__name-input';
		nameInput.setAttribute( 'aria-label', 'Your name' );
		nameInput.setAttribute( 'maxlength', String( MAX_DISPLAY_NAME_CHARS ) );

		var nameError = doc.createElement( 'p' );
		nameError.className = 'ut-chat-widget__name-error';
		nameError.setAttribute( 'role', 'alert' );

		var nameSubmit = doc.createElement( 'button' );
		nameSubmit.type = 'submit';
		nameSubmit.className = 'ut-chat-widget__send';
		nameSubmit.textContent = 'Start chat';

		nameForm.appendChild( nameDisclosure );
		nameForm.appendChild( nameInput );
		nameForm.appendChild( nameError );
		nameForm.appendChild( nameSubmit );

		panel.appendChild( header );
		panel.appendChild( log );
		panel.appendChild( statusRegion );
		panel.appendChild( nameForm );
		panel.appendChild( form );

		root.appendChild( toggleButton );
		root.appendChild( panel );

		var renderedMessageIds  = {};
		var pendingVisitorTexts = []; // Reconciles an optimistic local bubble with its eventual polled echo (same text, visitor direction) so it is never rendered twice.
		var pendingDisplayName  = null; // Set once the name form is submitted; sent with the next send only, then cleared.
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

		function showNameForm() {
			nameForm.hidden = false;
			form.hidden = true;
		}

		function showComposer() {
			nameForm.hidden = true;
			form.hidden = false;
		}

		// Default to the name step until the client tells us otherwise —
		// a brand-new conversation always requires a name (M06.3, ADR-0024).
		showNameForm();

		client.on( 'displayNameRequired', function ( required ) {
			if ( required ) {
				showNameForm();
			} else {
				showComposer();
			}
		} );

		nameForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var trimmed = ( nameInput.value || '' ).trim();

			if ( ! isValidDisplayName( trimmed ) ) {
				nameError.textContent = 'Please enter your name (1-80 characters).';
				return;
			}

			nameError.textContent = '';
			pendingDisplayName = trimmed;
			showComposer();
			input.focus();
		} );

		function appendMessage( message ) {
			if ( renderedMessageIds[ message.id ] ) {
				return;
			}
			renderedMessageIds[ message.id ] = true;

			if ( 'visitor' === message.direction && pendingVisitorTexts.length && pendingVisitorTexts[ 0 ] === message.text ) {
				pendingVisitorTexts.shift();
				return;
			}

			if ( message.created_at ) {
				maybeInsertDateSeparator( message.created_at );
			}

			var isOperator = 'operator' === message.direction;

			var row = doc.createElement( 'div' );
			row.className = 'ut-chat-widget__message ut-chat-widget__message--' +
				( isOperator ? 'operator' : 'visitor' );
			row.setAttribute( 'data-delivery', message.delivery_state || 'sent' );

			var textNode = doc.createElement( 'div' );
			// Text-only rendering (M06 plan §4): textContent only, never
			// innerHTML, for visitor- or Telegram-origin text alike.
			textNode.textContent = message.text;
			row.appendChild( textNode );

			if ( message.created_at ) {
				var meta = doc.createElement( 'span' );
				meta.className = 'ut-chat-widget__message-meta';
				meta.textContent = ( isOperator ? labelOperator : labelVisitor ) + ' · ' + formatLocalTime( message.created_at );
				row.appendChild( meta );
			}

			log.appendChild( row );
		}

		function setStatus( status ) {
			var described = describeUiState( status );
			statusRegion.textContent = described.announce;
			statusRegion.className = 'ut-chat-widget__status ut-chat-widget__status--' + described.status;
			input.disabled = described.inputDisabled;
			sendButton.disabled = described.inputDisabled;

			if ( 'ended' === described.status && ! statusRegion.querySelector( '.ut-chat-widget__restart' ) ) {
				var restart = doc.createElement( 'button' );
				restart.type = 'button';
				restart.className = 'ut-chat-widget__restart';
				restart.textContent = 'Start a new conversation';
				restart.addEventListener( 'click', function () {
					renderedMessageIds = {};
					pendingVisitorTexts = [];
					lastRenderedDateKey = null;
					pendingDisplayName = null;
					log.textContent = '';
					statusRegion.textContent = '';
					statusRegion.className = 'ut-chat-widget__status';
					input.disabled = false;
					sendButton.disabled = false;
					showNameForm();
				} );
				statusRegion.appendChild( restart );
			}
		}

		var lastFocused = null;

		function focusableElements() {
			var all = panel.querySelectorAll( 'button, textarea, input, [href], [tabindex]:not([tabindex="-1"])' );
			var visible = [];
			for ( var i = 0; i < all.length; i++ ) {
				// Excludes controls inside the currently-hidden name-form or
				// composer (the `hidden` attribute alone does not stop
				// querySelectorAll from matching descendants) so Tab never
				// traps focus on an invisible field.
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
			( nameForm.hidden ? input : nameInput ).focus();
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

		// "End conversation" (M06 plan §6): local client-state clearing
		// only. No M05 endpoint is called to end/archive/revoke -- none
		// exists for this. The server-side conversation is unaffected and
		// resolves/archives on its own per M05's existing lifecycle.
		endButton.addEventListener( 'click', function () {
			client.endConversation();
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var text = input.value;
			if ( ! text ) {
				return;
			}

			pendingVisitorTexts.push( text );
			appendMessage( {
				id: 'local-' + Date.now() + '-' + pendingVisitorTexts.length,
				direction: 'visitor',
				text: text,
				created_at: new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ),
				delivery_state: 'queued',
			} );
			input.value = '';

			var displayNameForThisSend = pendingDisplayName;
			pendingDisplayName = null;

			client.sendMessage( text, displayNameForThisSend ).catch( function () {
				// Failure is already surfaced via the 'state' event above.
			} );
		} );

		client.on( 'message', appendMessage );
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

	// -- Bootstrap / lifecycle wiring (M06 plan WP6) --------------------

	/**
	 * Reads the static config data island (M06 plan §4). Returns null if
	 * absent or malformed -- e.g. the widget's own assets were not
	 * enqueued for this request at all.
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
	 * sessionStorage (reload/re-entry) -- polling only ever runs while
	 * the panel is open (M06 plan §3), and opening is always the
	 * visitor's own action, never automatic (privacy-minimal, M06 plan §6).
	 */
	function boot() {
		var config = readConfig();
		if ( ! config || ! window.document || ! window.document.body ) {
			return;
		}

		var state  = createState();
		var client = createClient( state, config );
		var widget = buildWidget( client, config );

		window.document.body.appendChild( widget.root );
	}

	if ( window.document ) {
		if ( 'loading' === window.document.readyState ) {
			window.document.addEventListener( 'DOMContentLoaded', boot );
		} else {
			boot();
		}
	}
} )();
