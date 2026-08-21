<?php
/**
 * WooCommerce order-lifecycle event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\WooCommerce\Events;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WC_Order;

/**
 * Order-lifecycle event types sourced from WooCommerce core order hooks
 * (M03 plan §5.3–§5.8, ADR-0018). Every method calls
 * universal_telegram_emit_event() only — never constructs EventEnvelope/
 * EventDispatcher directly (M03 plan §11). Every order is read exclusively
 * via wc_get_order(), WooCommerce's own storage-agnostic abstraction, so
 * this class is HPOS-compatible with no storage-backend branching in its
 * own field-extraction logic (M03 plan §9).
 */
final class OrderEventEmitter {

	public const ORDER_CREATED        = 'woocommerce.order_created';
	public const ORDER_STATUS_CHANGED = 'woocommerce.order_status_changed';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$order_created_fields = array(
			'actor.user_id'           => Classification::INTERNAL,
			'subject.order_id'        => Classification::PUBLIC,
			'context.order_status'    => Classification::PUBLIC,
			'context.storage_backend' => Classification::INTERNAL,
			'payload.order_total'     => Classification::PUBLIC,
			'payload.currency'        => Classification::PUBLIC,
			'payload.item_count'      => Classification::PUBLIC,
		);
		$registry->register(
			self::ORDER_CREATED,
			1,
			$order_created_fields,
			array_keys( $order_created_fields ),
			array( 'subject.order_id', 'context.order_status', 'payload.order_total', 'payload.currency', 'payload.item_count' )
		);

		$order_status_changed_fields = array(
			'actor.user_id'       => Classification::INTERNAL,
			'subject.order_id'    => Classification::PUBLIC,
			'payload.status_from' => Classification::PUBLIC,
			'payload.status_to'   => Classification::PUBLIC,
			'payload.order_total' => Classification::PUBLIC,
		);
		$registry->register(
			self::ORDER_STATUS_CHANGED,
			1,
			$order_status_changed_fields,
			array_keys( $order_status_changed_fields ),
			array( 'subject.order_id', 'payload.status_from', 'payload.status_to', 'payload.order_total' )
		);
	}

	/**
	 * Binds this emitter's WooCommerce hook callbacks. Called only when
	 * WooCommerceSupport::is_active() is true (M03 plan §4).
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_classic_order_processed' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_block_order_processed' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * The woocommerce_checkout_order_processed callback (classic checkout).
	 *
	 * @param int                  $order_id    The order id.
	 * @param array<string, mixed> $posted_data Unused; never read (no checkout body in the envelope, per M03 plan §5.14).
	 * @param WC_Order|null        $order       The order, if resolvable.
	 */
	public function on_classic_order_processed( int $order_id, array $posted_data, ?WC_Order $order = null ): void {
		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->emit_order_created( $order );
	}

	/**
	 * The woocommerce_store_api_checkout_order_processed callback (block checkout).
	 *
	 * @param WC_Order $order The order.
	 */
	public function on_block_order_processed( WC_Order $order ): void {
		$this->emit_order_created( $order );
	}

	/**
	 * Emits woocommerce.order_created. Shared by the classic and block
	 * hooks; the idempotency key is derived solely from the order's own
	 * identity, so a hypothetical double-fire for the same order collapses
	 * to one event_id (M03 plan §5.2, §5.3).
	 *
	 * @param WC_Order $order The order.
	 */
	private function emit_order_created( WC_Order $order ): void {
		$data = array(
			'actor'   => array(
				'user_id' => $order->get_customer_id(),
			),
			'subject' => array(
				'order_id' => $order->get_id(),
			),
			'context' => array(
				'order_status'    => $order->get_status(),
				'storage_backend' => $this->storage_backend(),
			),
			'payload' => array(
				'order_total' => (float) $order->get_total(),
				'currency'    => $order->get_currency(),
				'item_count'  => $order->get_item_count(),
			),
		);

		universal_telegram_emit_event( self::ORDER_CREATED, $data, 'order:' . $order->get_id() );
	}

	/**
	 * The woocommerce_order_status_changed callback — the sole generic
	 * transition hook bound to this event type (M03 plan §5.4). The
	 * per-status hook family is used only for the dedicated order_failed/
	 * order_cancelled types added in a later work package, never
	 * additionally bound here.
	 *
	 * @param int           $order_id    The order id.
	 * @param string        $status_from The prior status.
	 * @param string        $status_to   The new status.
	 * @param WC_Order|null $order       The order, if resolvable.
	 */
	public function on_order_status_changed( int $order_id, string $status_from, string $status_to, ?WC_Order $order = null ): void {
		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$data = array(
			'actor'   => array(
				'user_id' => get_current_user_id(),
			),
			'subject' => array(
				'order_id' => $order_id,
			),
			'payload' => array(
				'status_from' => $status_from,
				'status_to'   => $status_to,
				'order_total' => (float) $order->get_total(),
			),
		);

		$key = 'order:' . $order_id . ':' . $status_from . '->' . $status_to . ':' . $order->get_date_modified()->getTimestamp();

		universal_telegram_emit_event( self::ORDER_STATUS_CHANGED, $data, $key );
	}

	/**
	 * Whether HPOS's custom orders table is in use, for diagnostic-only
	 * context — never used to branch any order read/write path (all order
	 * access goes through wc_get_order() regardless, per M03 plan §9).
	 *
	 * @return string "hpos" or "legacy".
	 */
	private function storage_backend(): string {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return 'hpos';
		}

		return 'legacy';
	}
}
