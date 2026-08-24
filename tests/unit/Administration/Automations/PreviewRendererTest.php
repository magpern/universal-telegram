<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Automations;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * PreviewRenderer must never touch a database, an HTTP client, or any real
 * event/order/visitor/Telegram data (M08.1 plan "Define the preview
 * precisely") — its own constructor takes only a Registry, which itself
 * carries no I/O, and every value it renders comes from
 * FieldTypeCatalog::preview_value().
 */
final class PreviewRendererTest extends TestCase {

	private function registry(): Registry {
		$registry = new Registry();
		$registry->register(
			'woocommerce.order_created',
			1,
			array(
				'subject.order_id'    => Classification::PUBLIC,
				'payload.order_total' => Classification::PUBLIC,
				'payload.currency'    => Classification::PUBLIC,
			),
			array( 'subject.order_id', 'payload.order_total', 'payload.currency' ),
			array( 'subject.order_id', 'payload.order_total', 'payload.currency' )
		);

		return $registry;
	}

	public function test_the_constructor_accepts_only_a_registry_no_io_dependency(): void {
		$reflection = new ReflectionClass( PreviewRenderer::class );
		$constructor = $reflection->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertCount( 1, $constructor->getParameters() );
		$this->assertSame( Registry::class, (string) $constructor->getParameters()[0]->getType() );
	}

	public function test_renders_a_template_using_fixed_field_type_catalog_preview_values(): void {
		$renderer = new PreviewRenderer( $this->registry() );

		$preview = $renderer->render(
			'woocommerce.order_created',
			'New order #{{subject.order_id}} — {{payload.order_total}} {{payload.currency}}.'
		);

		// The template's own literal text ("New order #", the trailing ".")
		// is MarkdownV2-escaped exactly like a substituted value.
		$this->assertSame( 'New order \\#1042 — 49\\.90 EUR\\.', $preview );
	}

	public function test_a_disallowed_token_renders_empty_never_the_raw_placeholder(): void {
		$renderer = new PreviewRenderer( $this->registry() );

		$preview = $renderer->render( 'woocommerce.order_created', 'Total: {{payload.not_allowed}}' );

		$this->assertSame( 'Total: ', $preview );
	}

	public function test_an_unregistered_event_type_renders_an_empty_string_not_a_fatal(): void {
		$renderer = new PreviewRenderer( $this->registry() );

		$this->assertSame( '', $renderer->render( 'wordpress.never_registered', 'Hello {{subject.order_id}}' ) );
	}
}
