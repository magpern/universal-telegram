<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Telegram\Commands;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Commands\StockMenu;

final class StockMenuTest extends TestCase {

	public function test_products_keyboard_has_one_row_per_item_plus_no_nav_on_a_single_page(): void {
		$items = array(
			array(
				'id'             => 5,
				'name'           => 'Simple Product',
				'has_variations' => false,
			),
			array(
				'id'             => 9,
				'name'           => 'Variable Product',
				'has_variations' => true,
			),
		);

		$keyboard = StockMenu::products_keyboard( $items, 1, 1 );

		$this->assertCount( 2, $keyboard['inline_keyboard'] );
		$this->assertSame( 'Simple Product', $keyboard['inline_keyboard'][0][0]['text'] );
		$this->assertSame( 'sk:i:5', $keyboard['inline_keyboard'][0][0]['callback_data'] );
		$this->assertSame( 'Variable Product', $keyboard['inline_keyboard'][1][0]['text'] );
		$this->assertSame( 'sk:v:9:1', $keyboard['inline_keyboard'][1][0]['callback_data'] );
	}

	public function test_products_keyboard_adds_a_prev_next_row_only_when_more_than_one_page(): void {
		$items = array(
			array(
				'id'             => 1,
				'name'           => 'A',
				'has_variations' => false,
			),
		);

		$single_page = StockMenu::products_keyboard( $items, 1, 1 );
		$this->assertCount( 1, $single_page['inline_keyboard'] );

		$middle_page = StockMenu::products_keyboard( $items, 2, 3 );
		$nav_row     = $middle_page['inline_keyboard'][1];
		$this->assertCount( 2, $nav_row );
		$this->assertSame( 'sk:p:1', $nav_row[0]['callback_data'] );
		$this->assertSame( 'sk:p:3', $nav_row[1]['callback_data'] );

		$first_page = StockMenu::products_keyboard( $items, 1, 3 );
		$first_nav  = $first_page['inline_keyboard'][1];
		$this->assertCount( 1, $first_nav, 'no Prev button on page 1' );
		$this->assertSame( 'sk:p:2', $first_nav[0]['callback_data'] );

		$last_page = StockMenu::products_keyboard( $items, 3, 3 );
		$last_nav  = $last_page['inline_keyboard'][1];
		$this->assertCount( 1, $last_nav, 'no Next button on the last page' );
		$this->assertSame( 'sk:p:2', $last_nav[0]['callback_data'] );
	}

	public function test_variations_keyboard_always_has_a_back_to_products_row(): void {
		$items = array(
			array(
				'id'    => 42,
				'label' => '10mg',
			),
		);

		$keyboard = StockMenu::variations_keyboard( 7, $items, 1, 1 );
		$last_row = $keyboard['inline_keyboard'][ count( $keyboard['inline_keyboard'] ) - 1 ];

		$this->assertSame( '« Back to products', $last_row[0]['text'] );
		$this->assertSame( 'sk:p:1', $last_row[0]['callback_data'] );
		$this->assertSame( 'sk:i:42', $keyboard['inline_keyboard'][0][0]['callback_data'] );
	}

	public function test_variations_keyboard_pagination_carries_the_parent_id(): void {
		$items = array(
			array(
				'id'    => 1,
				'label' => 'X',
			),
		);

		$keyboard = StockMenu::variations_keyboard( 7, $items, 1, 2 );
		$nav_row  = $keyboard['inline_keyboard'][1];

		$this->assertSame( 'sk:v:7:2', $nav_row[0]['callback_data'] );
	}

	public function test_long_labels_are_truncated(): void {
		$items = array(
			array(
				'id'             => 1,
				'name'           => str_repeat( 'a', 100 ),
				'has_variations' => false,
			),
		);

		$keyboard = StockMenu::products_keyboard( $items, 1, 1 );
		$text     = $keyboard['inline_keyboard'][0][0]['text'];

		$this->assertLessThanOrEqual( 60, mb_strlen( $text ) );
		$this->assertStringEndsWith( '…', $text );
	}

	public function test_parse_recognizes_every_action_shape(): void {
		$this->assertSame(
			array(
				'action' => 'p',
				'args'   => array( '2' ),
			),
			StockMenu::parse( 'sk:p:2' )
		);

		$this->assertSame(
			array(
				'action' => 'v',
				'args'   => array( '9', '1' ),
			),
			StockMenu::parse( 'sk:v:9:1' )
		);

		$this->assertSame(
			array(
				'action' => 'i',
				'args'   => array( '5' ),
			),
			StockMenu::parse( 'sk:i:5' )
		);
	}

	public function test_parse_rejects_anything_not_shaped_like_this_menu(): void {
		$this->assertNull( StockMenu::parse( '' ) );
		$this->assertNull( StockMenu::parse( 'sk' ) );
		$this->assertNull( StockMenu::parse( 'other:p:1' ) );
		$this->assertNull( StockMenu::parse( 'not-a-callback-at-all' ) );
	}
}
