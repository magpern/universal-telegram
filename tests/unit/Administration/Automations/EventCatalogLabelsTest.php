<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Automations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Administration\Automations\EventCatalogLabels;

final class EventCatalogLabelsTest extends TestCase {

	public function test_known_event_types_return_plain_language_labels(): void {
		$this->assertSame( 'Successful user login', EventCatalogLabels::event_type_label( 'wordpress.login_succeeded' ) );
		$this->assertSame( 'Visitor viewed a page', EventCatalogLabels::event_type_label( 'visitor.page_viewed' ) );
	}

	public function test_known_fields_return_plain_language_labels(): void {
		$this->assertSame( 'Username', EventCatalogLabels::field_label( 'actor.user_login' ) );
		$this->assertSame( 'Order ID', EventCatalogLabels::field_label( 'subject.order_id' ) );
	}

	public function test_unknown_field_paths_fall_back_to_a_humanized_segment(): void {
		$this->assertSame( 'Example Field', EventCatalogLabels::field_label( 'payload.example_field' ) );
	}
}
