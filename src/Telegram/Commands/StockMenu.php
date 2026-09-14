<?php
/**
 * `/stock` inline-keyboard menu: callback_data encoding and keyboard
 * building, shared between the initial command render
 * (BotCommandDispatcher) and every subsequent tap (CallbackQueryDispatcher).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

/**
 * Purely static: no instance, no injected state. `callback_data` is kept
 * short (Telegram's own 64-byte limit) and carries only opaque ids/page
 * numbers — never a product name or any other business data.
 */
final class StockMenu {

	private const PREFIX = 'sk';

	/** Callback_data action: show a page of top-level products. */
	public const ACTION_PRODUCTS = 'p';

	/** Callback_data action: show a page of one product's variations. */
	public const ACTION_VARIATIONS = 'v';

	/** Callback_data action: show one product's/variation's stock. */
	public const ACTION_ITEM = 'i';

	/**
	 * The top-level products-menu keyboard.
	 *
	 * @param array<int, array{id:int,name:string,has_variations:bool}> $items       This page's items.
	 * @param int                                                       $page        1-based current page.
	 * @param int                                                       $total_pages Total pages.
	 *
	 * @return array{inline_keyboard: array<int, array<int, array{text:string,callback_data:string}>>}
	 */
	public static function products_keyboard( array $items, int $page, int $total_pages ): array {
		$rows = array();

		foreach ( $items as $item ) {
			$action = $item['has_variations'] ? self::ACTION_VARIATIONS . ':' . $item['id'] . ':1' : self::ACTION_ITEM . ':' . $item['id'];

			$rows[] = array(
				array(
					'text'          => self::truncate_label( $item['name'] ),
					'callback_data' => self::callback_data( $action ),
				),
			);
		}

		$nav = self::pagination_row( self::ACTION_PRODUCTS . ':', $page, $total_pages );

		if ( array() !== $nav ) {
			$rows[] = $nav;
		}

		return array( 'inline_keyboard' => $rows );
	}

	/**
	 * A variable product's variations-menu keyboard, plus a "Back to
	 * products" row.
	 *
	 * @param int                                    $parent_id   The variable product's own id.
	 * @param array<int, array{id:int,label:string}> $items       This page's variations.
	 * @param int                                    $page        1-based current page.
	 * @param int                                    $total_pages Total pages.
	 *
	 * @return array{inline_keyboard: array<int, array<int, array{text:string,callback_data:string}>>}
	 */
	public static function variations_keyboard( int $parent_id, array $items, int $page, int $total_pages ): array {
		$rows = array();

		foreach ( $items as $item ) {
			$rows[] = array(
				array(
					'text'          => self::truncate_label( $item['label'] ),
					'callback_data' => self::callback_data( self::ACTION_ITEM . ':' . $item['id'] ),
				),
			);
		}

		$nav = self::pagination_row( self::ACTION_VARIATIONS . ':' . $parent_id . ':', $page, $total_pages );

		if ( array() !== $nav ) {
			$rows[] = $nav;
		}

		$rows[] = array(
			array(
				'text'          => '« Back to products',
				'callback_data' => self::callback_data( self::ACTION_PRODUCTS . ':1' ),
			),
		);

		return array( 'inline_keyboard' => $rows );
	}

	/**
	 * A « Prev / Next » row, or an empty array when there is only one page.
	 *
	 * @param string $action_prefix Prepended to the target page number to form the full action (e.g. "p:" or "v:123:").
	 * @param int    $page          1-based current page.
	 * @param int    $total_pages   Total pages.
	 *
	 * @return array<int, array{text:string,callback_data:string}>
	 */
	private static function pagination_row( string $action_prefix, int $page, int $total_pages ): array {
		if ( $total_pages <= 1 ) {
			return array();
		}

		$row = array();

		if ( $page > 1 ) {
			$row[] = array(
				'text'          => '« Prev',
				'callback_data' => self::callback_data( $action_prefix . ( $page - 1 ) ),
			);
		}

		if ( $page < $total_pages ) {
			$row[] = array(
				'text'          => 'Next »',
				'callback_data' => self::callback_data( $action_prefix . ( $page + 1 ) ),
			);
		}

		return $row;
	}

	/**
	 * Prefixes and bounds one action string to Telegram's own 64-byte
	 * callback_data limit — a defensive truncation that should never
	 * actually trigger given this menu's own bounded ids/page numbers.
	 *
	 * @param string $action The unprefixed action (e.g. "i:123").
	 *
	 * @return string
	 */
	private static function callback_data( string $action ): string {
		$data = self::PREFIX . ':' . $action;

		return strlen( $data ) > 64 ? substr( $data, 0, 64 ) : $data;
	}

	/**
	 * Telegram inline-keyboard button text has its own (generous) length
	 * ceiling; this is a conservative, display-friendly bound rather than
	 * an attempt to hit Telegram's own exact limit.
	 *
	 * @param string $label The raw label.
	 *
	 * @return string
	 */
	private static function truncate_label( string $label ): string {
		return mb_strlen( $label ) > 60 ? mb_substr( $label, 0, 59 ) . '…' : $label;
	}

	/**
	 * Parses a `/stock` menu callback_data string back into its action and
	 * arguments. Null for anything not shaped like one of this menu's own
	 * callback_data values (including a stale/foreign one).
	 *
	 * @param string $data The inbound `callback_query.data`.
	 *
	 * @return array{action:string,args:array<int,string>}|null
	 */
	public static function parse( string $data ): ?array {
		$parts = explode( ':', $data );

		if ( count( $parts ) < 2 || self::PREFIX !== $parts[0] ) {
			return null;
		}

		return array(
			'action' => $parts[1],
			'args'   => array_slice( $parts, 2 ),
		);
	}
}
