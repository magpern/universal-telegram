// Dependency-free behavioural tests for assets/js/visitor-tracker.js
// (M04 plan §7). Zero npm dependencies, no package.json — uses only
// Node's own built-in test runner and vm module, run via
// bin/docker/test-js.sh.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const TRACKER_SOURCE = fs.readFileSync(
	path.join( __dirname, '..', '..', 'assets', 'js', 'visitor-tracker.js' ),
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

// Most tests only care about a specific behaviour, not the automatic
// session_started event init() emits for a genuinely new tab session
// (M04 plan §4.2) — those tests pre-seed sessionStorage with an existing
// visit_ref so init() treats it as an established session and skips it.
// Tests that specifically exercise session_started use a fresh store.
function makeSandbox( overrides ) {
	const listeners = {};
	const storage = ( overrides && overrides.sessionStorage ) || makeSessionStorage();
	if ( ! ( overrides && overrides.sessionStorage ) && ! ( overrides && overrides.freshSession ) ) {
		storage.setItem( 'ut_visit_ref', 'preexisting0000preexisting0000' );
	}
	const sandbox = {
		crypto: {
			getRandomValues: ( arr ) => {
				for ( let i = 0; i < arr.length; i++ ) {
					arr[ i ] = Math.floor( Math.random() * 256 );
				}
				return arr;
			},
		},
		sessionStorage: storage,
		navigator: {},
		document: {
			addEventListener: ( name, fn ) => {
				listeners[ name ] = listeners[ name ] || [];
				listeners[ name ].push( fn );
			},
			visibilityState: 'visible',
		},
		addEventListener: ( name, fn ) => {
			listeners[ name ] = listeners[ name ] || [];
			listeners[ name ].push( fn );
		},
		universalTelegramConsent: undefined,
		console,
	};
	Object.assign( sandbox, overrides );
	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	vm.createContext( sandbox );
	vm.runInContext( TRACKER_SOURCE, sandbox );
	return { sandbox, listeners };
}

test( 'constructed event objects match the short-code schema', () => {
	const { sandbox } = makeSandbox();
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'disabled' } );
	tracker.trackEvent( 'pv', { path: '/hello', page_type: 'singular' } );

	const queue = tracker.getQueue();
	assert.equal( queue.length, 1 );
	assert.match( queue[ 0 ].uuid, /^[0-9a-f-]{36}$/ );
	assert.equal( queue[ 0 ].type, 'pv' );
	assert.deepEqual( queue[ 0 ].data, { path: '/hello', page_type: 'singular' } );
} );

test( 'the consent gate suppresses every outbound call when consent is unset under required mode', () => {
	const { sandbox } = makeSandbox();
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'required' } );
	// sandbox.universalTelegramConsent left undefined (not === true).
	tracker.trackEvent( 'ck', { target_key: 'hero-cta' } );

	assert.equal( tracker.getQueue().length, 0 );
} );

test( 'consent granted under required mode allows collection', () => {
	const { sandbox } = makeSandbox( { universalTelegramConsent: true } );
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'required' } );
	tracker.trackEvent( 'ck', { target_key: 'hero-cta' } );

	assert.equal( tracker.getQueue().length, 1 );
} );

test( 'a retry of one logical event reuses the same uuid rather than generating a new one', async () => {
	let attempt = 0;
	const { sandbox } = makeSandbox( {
		navigator: {
			sendBeacon: () => {
				attempt++;
				return attempt > 1; // fails first attempt, succeeds on retry.
			},
		},
	} );
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'disabled' } );
	tracker.trackEvent( 'pv', { path: '/retry-me', page_type: 'other' } );

	const uuidBeforeFirstFlush = tracker.getQueue()[ 0 ].uuid;

	await tracker.flush(); // fails; queue must remain intact.
	assert.equal( tracker.getQueue().length, 1 );
	assert.equal( tracker.getQueue()[ 0 ].uuid, uuidBeforeFirstFlush );

	await tracker.flush(); // succeeds this time.
	assert.equal( tracker.getQueue().length, 0 );
} );

test( 'a simulated same-tab navigation records one from_path/to_path pair per transition', () => {
	const { sandbox } = makeSandbox();
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'disabled', initialPath: '/start', initialPageType: 'home' } );
	tracker.onNavigate( '/next' );
	tracker.onNavigate( '/final' );

	const navEvents = tracker.getQueue().filter( ( e ) => 'nv' === e.type );
	assert.equal( navEvents.length, 2 );
	assert.deepEqual( navEvents[ 0 ].data, { from_path: '/start', to_path: '/next' } );
	assert.deepEqual( navEvents[ 1 ].data, { from_path: '/next', to_path: '/final' } );
} );

test( 'a 6th simulated error after 5 produces no further outbound call', () => {
	const { sandbox } = makeSandbox();
	const tracker = sandbox.__UT_VISITOR_TRACKER_FACTORY__();

	tracker.init( { enabled: true, consentMode: 'disabled' } );

	for ( let i = 0; i < 6; i++ ) {
		tracker.onError( 'runtime' );
	}

	const errorEvents = tracker.getQueue().filter( ( e ) => 'je' === e.type );
	assert.equal( errorEvents.length, 5 );
} );

test( 'a reload (same sessionStorage) does not produce a new session_started event', () => {
	const sharedStorage = makeSessionStorage();

	const first = makeSandbox( { sessionStorage: sharedStorage } );
	const trackerA = first.sandbox.__UT_VISITOR_TRACKER_FACTORY__();
	trackerA.init( { enabled: true, consentMode: 'disabled' } );
	const sessionEventsA = trackerA.getQueue().filter( ( e ) => 'ss' === e.type );
	assert.equal( sessionEventsA.length, 1 );

	const second = makeSandbox( { sessionStorage: sharedStorage } );
	const trackerB = second.sandbox.__UT_VISITOR_TRACKER_FACTORY__();
	trackerB.init( { enabled: true, consentMode: 'disabled' } );
	const sessionEventsB = trackerB.getQueue().filter( ( e ) => 'ss' === e.type );
	assert.equal( sessionEventsB.length, 0 );
} );
