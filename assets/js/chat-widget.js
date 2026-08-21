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
} )();
