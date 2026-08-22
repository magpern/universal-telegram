// Dependency-free behavioural tests for the REST client module of
// assets/js/chat-widget.js (M06 plan §3, WP4). Zero npm dependencies,
// uses only Node's own built-in test runner and vm module, run via
// bin/docker/test-js.sh.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const WIDGET_SOURCE = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'chat-widget.js' ),
	'utf8'
);

function makeSessionStorage() {
	const store = new Map();
	return {
		getItem: ( key ) => ( store.has( key ) ? store.get( key ) : null ),
		setItem: ( key, value ) => {
			store.set( key, value );
		},
		removeItem: ( key ) => {
			store.delete( key );
		},
	};
}

function jsonResponse( status, body ) {
	return {
		status,
		json: () => Promise.resolve( body ),
	};
}

// A fake fetch: a queue of {match, respond} rules consumed in order,
// recording every call's url/init for request-construction assertions.
function makeFakeFetch() {
	const calls = [];
	const queue = [];

	function fetch( url, init ) {
		calls.push( { url, init } );
		if ( 0 === queue.length ) {
			return Promise.reject( new Error( 'no queued fetch response' ) );
		}
		const next = queue.shift();
		if ( next.reject ) {
			return Promise.reject( next.reject );
		}
		return Promise.resolve( next.response );
	}

	fetch.calls = calls;
	fetch.queueResponse = ( response ) => queue.push( { response } );
	fetch.queueRejection = ( error ) => queue.push( { reject: error || new Error( 'network error' ) } );

	return fetch;
}

// A fake setTimeout/clearTimeout letting tests fire the most recently
// scheduled callback synchronously, without waiting on real timers.
function makeFakeTimers() {
	let nextId = 1;
	const pending = new Map();

	return {
		setTimeout: ( fn, delay ) => {
			const id = nextId++;
			pending.set( id, { fn, delay } );
			return id;
		},
		clearTimeout: ( id ) => {
			pending.delete( id );
		},
		pendingCount: () => pending.size,
		lastDelay: () => {
			const last = Array.from( pending.values() ).pop();
			return last ? last.delay : null;
		},
		fireAll: async () => {
			const entries = Array.from( pending.entries() );
			pending.clear();
			for ( const [ , entry ] of entries ) {
				entry.fn();
				await Promise.resolve();
				await Promise.resolve();
			}
		},
	};
}

function makeSandbox( overrides ) {
	const timers = makeFakeTimers();
	const sandbox = {
		sessionStorage: makeSessionStorage(),
		crypto: {
			getRandomValues: ( arr ) => {
				for ( let i = 0; i < arr.length; i++ ) {
					arr[ i ] = Math.floor( Math.random() * 256 );
				}
				return arr;
			},
		},
		document: {
			visibilityState: 'visible',
			addEventListener: () => {},
		},
		setTimeout: timers.setTimeout,
		clearTimeout: timers.clearTimeout,
		console,
	};
	Object.assign( sandbox, overrides );
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( WIDGET_SOURCE, sandbox );
	return { sandbox, timers };
}

const CONFIG = { restUrl: 'https://example.test/wp-json/universal-telegram/v1' };

async function flush() {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
}

test( 'sendMessage on a fresh conversation constructs the start request with headers, not the URL, and an empty body', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	await client.sendMessage( 'hello' );

	const startCall = fetch.calls[ 0 ];
	assert.equal( startCall.url, CONFIG.restUrl + '/conversations' );
	assert.equal( startCall.init.body, '{}' );
	assert.match( startCall.init.headers[ 'Idempotency-Key' ], /^[0-9a-f-]{36}$/i );
	assert.match( startCall.init.headers[ 'X-Universal-Telegram-Conversation-Secret' ], /^[0-9a-f]{64}$/ );
	assert.equal( startCall.url.indexOf( 'Idempotency-Key' ), -1 );
	assert.equal( startCall.url.indexOf( startCall.init.headers[ 'X-Universal-Telegram-Conversation-Secret' ] ), -1 );
} );

test( 'sendMessage persists the conversation and attaches the bearer secret as an Authorization header on the message request', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	await client.sendMessage( 'hello' );

	const messageCall = fetch.calls[ 1 ];
	assert.match( messageCall.url, /\/conversations\/uuid-1\/messages$/ );
	assert.match( messageCall.init.headers.Authorization, /^Bearer [0-9a-f]{64}$/ );
	assert.equal( JSON.parse( messageCall.init.body ).text, 'hello' );

	const conversation = state.getConversation();
	assert.equal( conversation.uuid, 'uuid-1' );
} );

test( 'a start network error retries once with the identical idempotency key and secret', async () => {
	const fetch = makeFakeFetch();
	fetch.queueRejection();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	await client.sendMessage( 'hello' );

	const firstAttempt = fetch.calls[ 0 ];
	const retryAttempt = fetch.calls[ 1 ];
	assert.equal( firstAttempt.init.headers[ 'Idempotency-Key' ], retryAttempt.init.headers[ 'Idempotency-Key' ] );
	assert.equal(
		firstAttempt.init.headers[ 'X-Universal-Telegram-Conversation-Secret' ],
		retryAttempt.init.headers[ 'X-Universal-Telegram-Conversation-Secret' ]
	);
} );

test( 'a start network error that fails twice leaves the pending-start entry in place for a manual retry with the same key', async () => {
	const fetch = makeFakeFetch();
	fetch.queueRejection();
	fetch.queueRejection();

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	let sawTransientFailure = false;
	client.on( 'state', ( event ) => {
		if ( 'transient-failure' === event.status ) {
			sawTransientFailure = true;
		}
	} );

	await assert.rejects( client.sendMessage( 'hello' ) );
	assert.ok( sawTransientFailure );

	const pending = state.getPendingStart();
	assert.ok( pending );

	// A manual retry (another sendMessage call) reuses the identical key/secret.
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );
	await client.sendMessage( 'hello again' );

	const retryStartCall = fetch.calls[ 2 ];
	assert.equal( retryStartCall.init.headers[ 'Idempotency-Key' ], pending.idempotencyKey );
	assert.equal( retryStartCall.init.headers[ 'X-Universal-Telegram-Conversation-Secret' ], pending.secret );
} );

test( 'a 503 on start clears the pending-start entry and emits the unavailable state', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 503, { ok: false } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await assert.rejects( client.sendMessage( 'hello' ) );

	assert.ok( seen.includes( 'unavailable' ) );
	assert.equal( state.getPendingStart(), null );
} );

test( 'a 429 on start emits rate-limited (not transient-failure) and leaves the pending-start entry in place', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 429, { ok: false, reason: 'rate_limited' } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await assert.rejects( client.sendMessage( 'hello' ) );

	assert.ok( seen.includes( 'rate-limited' ) );
	assert.ok( ! seen.includes( 'transient-failure' ) );
	assert.ok( state.getPendingStart() );
} );

test( 'a 429 on message post emits rate-limited (not transient-failure) without ending the conversation', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 429, { ok: false, reason: 'rate_limited' } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await assert.rejects( client.sendMessage( 'hello' ) );

	assert.ok( seen.includes( 'rate-limited' ) );
	assert.ok( ! seen.includes( 'transient-failure' ) );
	assert.ok( ! seen.includes( 'ended' ) );
	assert.ok( state.getConversation() );
} );

test( 'a 200 response with delivery=pending emits the pending state, not active', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true, delivery: 'pending', reason: 'temporary_delivery_pending' } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await client.sendMessage( 'hello' );

	assert.ok( seen.includes( 'pending' ) );
	assert.ok( ! seen.includes( 'active' ) );
} );

test( 'a 200 response with delivery=delivered (or absent) emits the active state', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true, delivery: 'delivered' } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await client.sendMessage( 'hello' );

	assert.ok( seen.includes( 'active' ) );
	assert.ok( ! seen.includes( 'pending' ) );
} );

test( 'a 404 on message post is terminal: clears all state, stops polling, and emits ended', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 404, { ok: false } ) );

	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	await assert.rejects( client.sendMessage( 'hello' ) );

	assert.ok( seen.includes( 'ended' ) );
	assert.equal( state.getConversation(), null );
} );

test( 'hydration poll uses since_id=0 and renders history once via the message event', async () => {
	const fetch = makeFakeFetch();
	const { sandbox, timers } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse(
		jsonResponse( 200, {
			ok: true,
			status: 'open',
			messages: [
				{ id: 1, direction: 'visitor', text: 'hi', created_at: 'now' },
				{ id: 2, direction: 'operator', text: 'hello!', created_at: 'now' },
			],
		} )
	);

	const received = [];
	client.on( 'message', ( message ) => received.push( message ) );

	client.open();
	await flush();

	assert.equal( fetch.calls[ 0 ].url, CONFIG.restUrl + '/conversations/uuid-1?since_id=0' );
	assert.equal( received.length, 2 );
	assert.equal( received[ 0 ].id, 1 );
	assert.equal( received[ 1 ].id, 2 );

	client.stopPolling();
	timers.fireAll();
} );

test( 'duplicate messages across overlapping polls are rendered only once', async () => {
	const fetch = makeFakeFetch();
	const { sandbox, timers } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse(
		jsonResponse( 200, { ok: true, status: 'open', messages: [ { id: 1, direction: 'visitor', text: 'hi', created_at: 'now' } ] } )
	);
	fetch.queueResponse(
		jsonResponse( 200, { ok: true, status: 'open', messages: [ { id: 1, direction: 'visitor', text: 'hi', created_at: 'now' }, { id: 2, direction: 'operator', text: 'reply', created_at: 'now' } ] } )
	);

	const received = [];
	client.on( 'message', ( message ) => received.push( message.id ) );

	client.open();
	await flush();
	await timers.fireAll();
	await flush();

	assert.deepEqual( received, [ 1, 2 ] );

	client.stopPolling();
} );

test( 'polling pauses when the document is hidden and resumes immediately when visible again', async () => {
	const fetch = makeFakeFetch();
	const sandboxState = { visibilityState: 'hidden' };
	const visibilityHandlers = [];
	const { sandbox } = makeSandbox( {
		fetch,
		document: {
			get visibilityState() {
				return sandboxState.visibilityState;
			},
			addEventListener: ( name, handler ) => {
				if ( 'visibilitychange' === name ) {
					visibilityHandlers.push( handler );
				}
			},
		},
	} );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	client.open();
	await flush();

	assert.equal( fetch.calls.length, 0, 'no poll should fire while hidden' );
	assert.equal( visibilityHandlers.length, 1 );

	sandboxState.visibilityState = 'visible';
	fetch.queueResponse( jsonResponse( 200, { ok: true, status: 'open', messages: [] } ) );
	visibilityHandlers[ 0 ]();
	await flush();

	assert.equal( fetch.calls.length, 1, 'a poll should fire once visible' );

	client.stopPolling();
} );

test( 'a conversation status of resolved ends the conversation and clears state', async () => {
	const fetch = makeFakeFetch();
	const { sandbox } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse( jsonResponse( 200, { ok: true, status: 'resolved', messages: [] } ) );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	client.open();
	await flush();

	assert.ok( seen.includes( 'ended' ) );
	assert.equal( state.getConversation(), null );
} );

test( 'poll backoff doubles on transient failure and resets on success', async () => {
	const fetch = makeFakeFetch();
	const { sandbox, timers } = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse( jsonResponse( 500, { ok: false } ) );
	client.open();
	await flush();

	assert.equal( timers.lastDelay(), 6000 );

	client.stopPolling();
} );
