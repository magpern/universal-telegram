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
	public const PAYMENT_COMPLETED    = 'woocommerce.payment_completed';
	public const ORDER_FAILED         = 'woocommerce.order_failed';
	public const ORDER_CANCELLED      = 'woocommerce.order_cancelled';
	public const REFUND_CREATED       = 'woocommerce.refund_created';

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

		$payment_completed_fields = array(
			'subject.order_id'           => Classification::PUBLIC,
			'payload.order_total'        => Classification::PUBLIC,
			'payload.currency'           => Classification::PUBLIC,
			'context.has_transaction_id' => Classification::PUBLIC,
		);
		$registry->register(
			self::PAYMENT_COMPLETED,
			1,
			$payment_completed_fields,
			array_keys( $payment_completed_fields ),
			array_keys( $payment_completed_fields )
		);

		$order_failed_fields = array(
			'subject.order_id'    => Classification::PUBLIC,
			'payload.order_total' => Classification::PUBLIC,
			'payload.currency'    => Classification::PUBLIC,
			'payload.status_from' => Classification::PUBLIC,
		);
		$registry->register(
			self::ORDER_FAILED,
			1,
			$order_failed_fields,
			array_keys( $order_failed_fields ),
			array_keys( $order_failed_fields )
		);

		$order_cancelled_fields = array(
			'subject.order_id'    => Classification::PUBLIC,
			'payload.order_total' => Classification::PUBLIC,
			'payload.currency'    => Classification::PUBLIC,
			'payload.status_from' => Classification::PUBLIC,
		);
		$registry->register(
			self::ORDER_CANCELLED,
			1,
			$order_cancelled_fields,
			array_keys( $order_cancelled_fields ),
			array_keys( $order_cancelled_fields )
		);

		$refund_created_fields = array(
			'subject.order_id'      => Classification::PUBLIC,
			'subject.refund_id'     => Classification::PUBLIC,
			'payload.refund_amount' => Classification::PUBLIC,
			'payload.currency'      => Classification::PUBLIC,
		);
		$registry->register(
			self::REFUND_CREATED,
			1,
			$refund_created_fields,
			array_keys( $refund_created_fields ),
			array_keys( $refund_created_fields )
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
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_order_status_failed' ), 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_status_cancelled' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
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
		$fields = $this->extract_order_fields( $order );

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
			'payload' => $fields,
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

		$fields = $this->extract_order_fields( $order );

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
				'order_total' => $fields['order_total'],
			),
		);

		$key = 'order:' . $order_id . ':' . $status_from . '->' . $status_to . ':' . $this->order_modified_timestamp( $order );

		universal_telegram_emit_event( self::ORDER_STATUS_CHANGED, $data, $key );
	}

	/**
	 * The woocommerce_payment_complete callback. Fires only when a
	 * gateway/extension explicitly calls WC_Order::payment_complete() and
	 * the order's status is in the valid-for-completion list — a known,
	 * accepted core limitation (M03 plan §5.5); order_status_changed
	 * remains the broader net for admins who need it.
	 *
	 * @param int   $order_id       The order id.
	 * @param mixed $transaction_id The transaction id. Never read into the
	 *                               envelope; only its presence is recorded
	 *                               (M03 plan §5.5, §5.14).
	 */
	public function on_payment_complete( int $order_id, $transaction_id = '' ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$fields = $this->extract_order_fields( $order );

		$data = array(
			'subject' => array(
				'order_id' => $order_id,
			),
			'context' => array(
				'has_transaction_id' => '' !== (string) $transaction_id,
			),
			'payload' => array(
				'order_total' => $fields['order_total'],
				'currency'    => $fields['currency'],
			),
		);

		universal_telegram_emit_event( self::PAYMENT_COMPLETED, $data, 'order:' . $order_id . ':payment_complete' );
	}

	/**
	 * The woocommerce_order_status_failed callback. Observes an order's
	 * status transitioning to "failed" by any means (gateway-reported
	 * failure, manual admin edit, or third-party extension) — not
	 * exclusively a verified payment-gateway failure. Named and documented
	 * as "order entered failed status," never "payment failed" (M03 plan
	 * §5.6, ADR-0018 Decision §7).
	 *
	 * @param int                  $order_id          The order id.
	 * @param WC_Order|null        $order             The order, if resolvable.
	 * @param array<string, mixed> $status_transition The status transition detail.
	 */
	public function on_order_status_failed( int $order_id, ?WC_Order $order = null, array $status_transition = array() ): void {
		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$fields = $this->extract_order_fields( $order );

		$data = array(
			'subject' => array(
				'order_id' => $order_id,
			),
			'payload' => array(
				'order_total' => $fields['order_total'],
				'currency'    => $fields['currency'],
				'status_from' => $status_transition['from'] ?? '',
			),
		);

		$key = 'order:' . $order_id . ':failed:' . $this->order_modified_timestamp( $order );

		universal_telegram_emit_event( self::ORDER_FAILED, $data, $key );
	}

	/**
	 * The woocommerce_order_status_cancelled callback. WooCommerce core
	 * exposes no dedicated "woocommerce_cancelled_order" hook; this is the
	 * hook WC's own internals use for coupon-usage decrement and stock
	 * restoration (M03 plan §5.7).
	 *
	 * @param int                  $order_id          The order id.
	 * @param WC_Order|null        $order             The order, if resolvable.
	 * @param array<string, mixed> $status_transition The status transition detail.
	 */
	public function on_order_status_cancelled( int $order_id, ?WC_Order $order = null, array $status_transition = array() ): void {
		$order = $order ?? wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$fields = $this->extract_order_fields( $order );

		$data = array(
			'subject' => array(
				'order_id' => $order_id,
			),
			'payload' => array(
				'order_total' => $fields['order_total'],
				'currency'    => $fields['currency'],
				'status_from' => $status_transition['from'] ?? '',
			),
		);

		$key = 'order:' . $order_id . ':cancelled:' . $this->order_modified_timestamp( $order );

		universal_telegram_emit_event( self::ORDER_CANCELLED, $data, $key );
	}

	/**
	 * The woocommerce_order_refunded callback. Chosen over
	 * woocommerce_refund_created because it gives both the order id and
	 * refund id directly (M03 plan §5.8). A refund id is freshly created
	 * per refund action via a single DB-write code path, so the refund id
	 * alone is a sufficient idempotency key.
	 *
	 * @param int $order_id  The refunded order's id.
	 * @param int $refund_id The refund's id.
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$data = array(
			'subject' => array(
				'order_id'  => $order_id,
				'refund_id' => $refund_id,
			),
			'payload' => array(
				'refund_amount' => (float) $refund->get_amount(),
				'currency'      => $refund->get_currency(),
			),
		);

		universal_telegram_emit_event( self::REFUND_CREATED, $data, 'refund:' . $refund_id );
	}

	/**
	 * Shared scalar-field extraction, avoiding duplicating total/currency
	 * extraction across the order-lifecycle methods above (M03 plan §8,
	 * WP2). Never returns a WC_Order object or any payment-method/PII
	 * field (M03 plan §5.14).
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array{order_total: float, currency: string, item_count: int}
	 */
	private function extract_order_fields( WC_Order $order ): array {
		return array(
			'order_total' => (float) $order->get_total(),
			'currency'    => $order->get_currency(),
			'item_count'  => $order->get_item_count(),
		);
	}

	/**
	 * The order's own last-modified timestamp, used as the second-resolution
	 * attempt-window coalescing component of the status-transition
	 * idempotency keys (M03 plan §5.2, §5.4, §5.6, §5.7). get_date_modified()
	 * can return null for an order with no recorded modification date (e.g.
	 * one whose status was changed programmatically without a save() that
	 * touches this field); falling back to 0 keeps the key deterministic
	 * rather than raising a fatal error out of a WooCommerce hook callback.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return int
	 */
	private function order_modified_timestamp( WC_Order $order ): int {
		$date_modified = $order->get_date_modified();

		return null === $date_modified ? 0 : $date_modified->getTimestamp();
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
