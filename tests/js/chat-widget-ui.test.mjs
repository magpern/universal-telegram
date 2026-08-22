// Dependency-free behavioural tests for the UI module's pure
// state-to-markup logic in assets/js/chat-widget.js (M06 plan §5, WP6).
// Real DOM/focus/keyboard/ARIA behavior has no automated coverage in
// this repository (no jsdom/browser runner) and is instead covered by
// a one-time manual accessibility/mobile checklist (M06 plan §9). This
// file only exercises describeUiState(), which needs no document at all.

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

function makeSandbox() {
	const sandbox = { console };
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( WIDGET_SOURCE, sandbox );
	return sandbox;
}

test( 'describeUiState maps every known status to the correct input-disabled flag', () => {
	const sandbox = makeSandbox();
	const describe = sandbox.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__;

	assert.equal( describe( 'idle' ).inputDisabled, false );
	assert.equal( describe( 'active' ).inputDisabled, false );
	assert.equal( describe( 'sending' ).inputDisabled, true );
	assert.equal( describe( 'unavailable' ).inputDisabled, true );
	assert.equal( describe( 'transient-failure' ).inputDisabled, false );
	assert.equal( describe( 'ended' ).inputDisabled, true );
	assert.equal( describe( 'pending' ).inputDisabled, false );
	assert.equal( describe( 'rate-limited' ).inputDisabled, false );
} );

test( 'pending and rate-limited are distinct, non-transient-failure states with their own announcement', () => {
	const sandbox = makeSandbox();
	const describe = sandbox.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__;

	const pending = describe( 'pending' );
	const rateLimited = describe( 'rate-limited' );
	const transientFailure = describe( 'transient-failure' );

	assert.equal( pending.status, 'pending' );
	assert.equal( rateLimited.status, 'rate-limited' );
	assert.notEqual( pending.announce, transientFailure.announce );
	assert.notEqual( rateLimited.announce, transientFailure.announce );
	assert.notEqual( pending.announce, '' );
	assert.notEqual( rateLimited.announce, '' );
} );

test( 'describeUiState never claims a Telegram-delivery-confirmed announcement', () => {
	const sandbox = makeSandbox();
	const describe = sandbox.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__;

	[ 'idle', 'sending', 'active', 'unavailable', 'transient-failure', 'ended' ].forEach( ( status ) => {
		const announce = describe( status ).announce;
		assert.equal( announce.toLowerCase().includes( 'delivered' ), false );
	} );
} );

test( 'describeUiState falls back to idle for an unrecognized status', () => {
	const sandbox = makeSandbox();
	const describe = sandbox.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__;

	const described = describe( 'something-unexpected' );
	assert.equal( described.status, 'idle' );
	assert.equal( described.inputDisabled, false );
} );

test( 'describeUiState provides a non-empty announcement for every failure/end state', () => {
	const sandbox = makeSandbox();
	const describe = sandbox.__UT_CHAT_WIDGET_UI_DESCRIBE_STATE__;

	[ 'unavailable', 'transient-failure', 'ended' ].forEach( ( status ) => {
		assert.notEqual( describe( status ).announce, '' );
	} );
} );
