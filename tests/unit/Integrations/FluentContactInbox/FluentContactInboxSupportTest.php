<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integrations\FluentContactInbox;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\FluentContactInbox\FluentContactInboxSupport;
use UniversalTelegram\Integrations\FluentContactInbox\ReplyComposer;

final class FluentContactInboxSupportTest extends TestCase {

	public function test_inactive_without_the_plugin(): void {
		$this->assertFalse( ( new FluentContactInboxSupport( static fn () => null ) )->is_active() );
	}

	public function test_inactive_when_too_old_to_expose_the_hooks(): void {
		$this->assertFalse( ( new FluentContactInboxSupport( static fn () => '2.0.7' ) )->is_active() );
	}

	public function test_active_from_the_first_release_with_the_hooks(): void {
		$this->assertTrue( ( new FluentContactInboxSupport( static fn () => '2.1.0' ) )->is_active() );
		$this->assertTrue( ( new FluentContactInboxSupport( static fn () => '2.4.3' ) )->is_active() );
	}

	public function test_reply_body_escapes_html_and_keeps_line_breaks(): void {
		$this->assertSame( "Hi &lt;b&gt;there&lt;/b&gt;<br>\nSecond &amp; last", ReplyComposer::html_body( "Hi <b>there</b>\nSecond & last" ) );
	}

	public function test_reply_subject_mirrors_the_admin_form_default(): void {
		$this->assertSame( 'Re: Where is my order', ReplyComposer::subject( ' Where is my order ' ) );
		$this->assertSame( '', ReplyComposer::subject( '   ' ) );
	}
}
