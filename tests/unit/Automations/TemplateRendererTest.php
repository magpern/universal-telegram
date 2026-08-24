<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\TemplateRenderer;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

final class TemplateRendererTest extends TestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'wordpress.post_published',
			1,
			array(
				'subject.post_id' => Classification::PUBLIC,
				'context.ip_hash' => Classification::INTERNAL,
			),
			array( 'subject.post_id', 'context.ip_hash' ),
			array( 'subject.post_id' )
		);

		return $registry;
	}

	private function envelope( Registry $registry ): EventEnvelope {
		return new EventEnvelope(
			$registry,
			'wordpress.post_published',
			'key',
			EventSource::WORDPRESS_CORE,
			array(),
			array( 'post_id' => 17 ),
			array( 'ip_hash' => '1.2.3.4' ),
			array()
		);
	}

	public function test_renders_an_allowed_public_field(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		$result = $renderer->render( 'Post {{ subject.post_id }} published', $this->envelope( $registry ), array( 'subject.post_id', 'context.ip_hash' ) );

		$this->assertSame( 'Post 17 published', $result );
	}

	public function test_renders_an_allowed_internal_field_since_use_is_transient(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		$result = $renderer->render( 'IP {{ context.ip_hash }}', $this->envelope( $registry ), array( 'subject.post_id', 'context.ip_hash' ) );

		$this->assertSame( 'IP 1\\.2\\.3\\.4', $result );
	}

	public function test_a_disallowed_field_renders_as_empty_string(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		$result = $renderer->render( 'Value: [{{ subject.post_id }}]', $this->envelope( $registry ), array() );

		// The literal "[" and "]" are themselves MarkdownV2-reserved and are
		// escaped like any other literal template text.
		$this->assertSame( 'Value: \\[\\]', $result );
	}

	public function test_a_missing_field_renders_as_empty_string(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		$result = $renderer->render( 'X: {{ subject.missing }}', $this->envelope( $registry ), array( 'subject.missing' ) );

		$this->assertSame( 'X: ', $result );
	}

	public function test_markdown_v2_special_characters_are_escaped(): void {
		$registry = new Registry();
		$registry->register(
			'wordpress.post_published',
			1,
			array( 'payload.text' => Classification::PUBLIC ),
			array( 'payload.text' ),
			array( 'payload.text' )
		);

		$envelope = new EventEnvelope(
			$registry,
			'wordpress.post_published',
			'key',
			EventSource::WORDPRESS_CORE,
			array(),
			array(),
			array(),
			array( 'text' => '_*[]()~`>#+-=|{}.!' )
		);

		$renderer = new TemplateRenderer();
		$result   = $renderer->render( '{{ payload.text }}', $envelope, array( 'payload.text' ) );

		$this->assertSame( '\\_\\*\\[\\]\\(\\)\\~\\`\\>\\#\\+\\-\\=\\|\\{\\}\\.\\!', $result );
	}

	public function test_no_conditionals_or_function_calls_exist_in_the_grammar(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		// A token that is not the fixed {{ field.path }} shape is never
		// evaluated as an expression — proving there is no broader
		// expression grammar to exploit. It is still literal template text,
		// so it is still MarkdownV2-escaped like any other literal text.
		$result = $renderer->render( '{{ subject.post_id + 1 }}', $this->envelope( $registry ), array( 'subject.post_id' ) );

		$this->assertSame( '\\{\\{ subject\\.post\\_id \\+ 1 \\}\\}', $result );
	}

	public function test_literal_template_text_surrounding_a_token_is_markdown_v2_escaped(): void {
		$registry = $this->registry();
		$renderer = new TemplateRenderer();

		// Real preset/admin-authored templates put plain free-form
		// punctuation around tokens (e.g. "Product #123 (quantity: 2).") —
		// every literal MarkdownV2-reserved character must be escaped, or
		// Telegram rejects the whole message and it is dead-lettered.
		$result = $renderer->render( 'Product #{{ subject.post_id }} added (in stock).', $this->envelope( $registry ), array( 'subject.post_id' ) );

		$this->assertSame( 'Product \\#17 added \\(in stock\\)\\.', $result );
	}
}
