// Dependency-free behavioural tests for the M06 chat widget's lifecycle
// wiring (M06 plan WP6): the full happy path, 404-during-poll, and local-
// only "end conversation" behavior. Zero npm dependencies, uses only
// Node's own built-in test runner and vm module, run via
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
	return { status, json: () => Promise.resolve( body ) };
}

function makeFakeFetch() {
	const calls = [];
	const queue = [];
	function fetch( url, init ) {
		calls.push( { url, init } );
		if ( 0 === queue.length ) {
			return Promise.reject( new Error( 'no queued fetch response' ) );
		}
		const next = queue.shift();
		return next.reject ? Promise.reject( next.reject ) : Promise.resolve( next.response );
	}
	fetch.calls = calls;
	fetch.queueResponse = ( response ) => queue.push( { response } );
	return fetch;
}

function makeFakeTimers() {
	let nextId = 1;
	const pending = new Map();
	return {
		setTimeout: ( fn, delay ) => {
			const id = nextId++;
			pending.set( id, { fn, delay } );
			return id;
		},
		clearTimeout: ( id ) => pending.delete( id ),
	};
}

function makeSandbox( overrides ) {
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
		document: { visibilityState: 'visible', addEventListener: () => {} },
		...makeFakeTimers(),
		console,
	};
	Object.assign( sandbox, overrides );
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( WIDGET_SOURCE, sandbox );
	return sandbox;
}

const CONFIG = { restUrl: 'https://example.test/wp-json/universal-telegram/v1', loggedIn: true, nonce: 'test-nonce' };

async function flush() {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
}

test( 'full happy path: start, send, then poll picks up an operator reply', async () => {
	const fetch = makeFakeFetch();
	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-1', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );
	fetch.queueResponse(
		jsonResponse( 200, {
			ok: true,
			status: 'open',
			messages: [ { id: 5, direction: 'operator', text: 'How can I help?', created_at: 'now' } ],
		} )
	);

	const sandbox = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	const messages = [];
	client.on( 'message', ( m ) => messages.push( m ) );

	await client.sendMessage( 'Hi there' );
	await flush();

	assert.ok( state.getConversation() );
	assert.equal( messages.length, 1 );
	assert.equal( messages[ 0 ].text, 'How can I help?' );

	client.stopPolling();
} );

test( '404 during poll is terminal: clears state, stops polling, emits ended', async () => {
	const fetch = makeFakeFetch();
	const sandbox = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse( { status: 404, json: () => Promise.resolve( { ok: false } ) } );

	const seen = [];
	client.on( 'state', ( event ) => seen.push( event.status ) );

	client.open();
	await flush();

	assert.ok( seen.includes( 'ended' ) );
	assert.equal( state.getConversation(), null );
} );

// M06.3.1 (ADR-0025): the visible "End conversation" control and the
// client's own endConversation() method are removed entirely — a
// conversation only ever ends server-side (404/resolved/archived, already
// covered above), never via a local-only client action.

test( 'no endConversation method is exposed on the client', async () => {
	const fetch = makeFakeFetch();
	const sandbox = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	assert.equal( typeof client.endConversation, 'undefined' );
} );

test( 'a new sendMessage after a server-driven end starts an entirely new conversation', async () => {
	const fetch = makeFakeFetch();
	const sandbox = makeSandbox( { fetch } );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();
	state.setConversation( 'uuid-1', 'a'.repeat( 64 ) );

	const client = sandbox.__UT_CHAT_WIDGET_CLIENT_FACTORY__( state, CONFIG );

	fetch.queueResponse( { status: 404, json: () => Promise.resolve( { ok: false } ) } );
	client.open();
	await flush();

	assert.equal( state.getConversation(), null );

	fetch.queueResponse( jsonResponse( 200, { ok: true, conversation_uuid: 'uuid-2', secret: 'irrelevant' } ) );
	fetch.queueResponse( jsonResponse( 200, { ok: true } ) );

	await client.sendMessage( 'starting over' );

	assert.equal( state.getConversation().uuid, 'uuid-2' );
} );
