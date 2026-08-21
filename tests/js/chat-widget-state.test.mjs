// Dependency-free behavioural tests for the state module of
// assets/js/chat-widget.js (M06 plan §2, WP3). Zero npm dependencies,
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
		_store: store,
	};
}

function makeSandbox( overrides ) {
	const sandbox = {
		sessionStorage: makeSessionStorage(),
		console,
	};
	Object.assign( sandbox, overrides );
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( WIDGET_SOURCE, sandbox );
	return sandbox;
}

test( 'getPendingStart returns null when nothing is stored', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	assert.equal( state.getPendingStart(), null );
} );

test( 'setPendingStart persists the idempotency key and secret exactly', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	state.setPendingStart( 'key-1', 'a'.repeat( 64 ) );

	assert.deepEqual( state.getPendingStart(), { idempotencyKey: 'key-1', secret: 'a'.repeat( 64 ) } );
} );

test( 'clearPendingStart removes the pending-start entry', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	state.setPendingStart( 'key-1', 'a'.repeat( 64 ) );
	state.clearPendingStart();

	assert.equal( state.getPendingStart(), null );
} );

test( 'getConversation returns null when nothing is stored', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	assert.equal( state.getConversation(), null );
} );

test( 'setConversation persists uuid, secret, and a numeric startedAt, and clears any pending start', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	state.setPendingStart( 'key-1', 'a'.repeat( 64 ) );
	state.setConversation( 'uuid-1', 'b'.repeat( 64 ) );

	const conversation = state.getConversation();
	assert.equal( conversation.uuid, 'uuid-1' );
	assert.equal( conversation.secret, 'b'.repeat( 64 ) );
	assert.equal( typeof conversation.startedAt, 'number' );
	assert.equal( state.getPendingStart(), null );
} );

test( 'clearConversation removes only the conversation entry', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	state.setConversation( 'uuid-1', 'b'.repeat( 64 ) );
	state.clearConversation();

	assert.equal( state.getConversation(), null );
} );

test( 'clearAll removes both pending-start and conversation entries', () => {
	const sandbox = makeSandbox();
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	state.setPendingStart( 'key-1', 'a'.repeat( 64 ) );
	state.setConversation( 'uuid-1', 'b'.repeat( 64 ) );
	state.clearAll();

	assert.equal( state.getPendingStart(), null );
	assert.equal( state.getConversation(), null );
} );

test( 'malformed stored JSON is treated as absent, never thrown', () => {
	const sandbox = makeSandbox();
	sandbox.sessionStorage.setItem( 'utChatConversation', '{not-json' );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	assert.equal( state.getConversation(), null );
} );

test( 'a conversation object missing required fields is treated as absent', () => {
	const sandbox = makeSandbox();
	sandbox.sessionStorage.setItem( 'utChatConversation', JSON.stringify( { uuid: 'only-uuid' } ) );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	assert.equal( state.getConversation(), null );
} );

test( 'a sessionStorage that throws on write never propagates the error', () => {
	const sandbox = makeSandbox( {
		sessionStorage: {
			getItem: () => null,
			setItem: () => {
				throw new Error( 'quota exceeded' );
			},
			removeItem: () => {},
		},
	} );
	const state = sandbox.__UT_CHAT_WIDGET_STATE_FACTORY__();

	assert.doesNotThrow( () => state.setConversation( 'uuid-1', 'b'.repeat( 64 ) ) );
} );
