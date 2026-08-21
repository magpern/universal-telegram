<?php
/**
 * Core (always-on) visitor/browser event catalog registration.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;

/**
 * Registers the six always-on visitor.* event types (M04 plan §4.2). No
 * field at any classification ever holds an IP address, raw user-agent,
 * cookie value, or any other PII (docs/adr/0019). `context.visit_ref` is
 * never a field in any of these types — it is used only as transient
 * idempotency-key input by IngestController.
 */
final class VisitorEventCatalog {

	public const SESSION_STARTED  = 'visitor.session_started';
	public const PAGE_VIEWED      = 'visitor.page_viewed';
	public const NAVIGATION       = 'visitor.navigation';
	public const SEARCH_PERFORMED = 'visitor.search_performed';
	public const CLICK            = 'visitor.click';
	public const JAVASCRIPT_ERROR = 'visitor.javascript_error';

	/**
	 * Registers this catalog's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::SESSION_STARTED,
			1,
			array(),
			array(),
			array()
		);

		$registry->register(
			self::PAGE_VIEWED,
			1,
			array(
				'subject.path'      => Classification::PUBLIC,
				'subject.page_type' => Classification::PUBLIC,
			),
			array( 'subject.path', 'subject.page_type' ),
			array( 'subject.path', 'subject.page_type' )
		);

		$registry->register(
			self::NAVIGATION,
			1,
			array(
				'subject.from_path' => Classification::PUBLIC,
				'subject.to_path'   => Classification::PUBLIC,
			),
			array( 'subject.from_path', 'subject.to_path' ),
			array( 'subject.to_path' )
		);

		$registry->register(
			self::SEARCH_PERFORMED,
			1,
			array( 'payload.result_count' => Classification::PUBLIC ),
			array( 'payload.result_count' ),
			array( 'payload.result_count' )
		);

		$registry->register(
			self::CLICK,
			1,
			array( 'subject.target_key' => Classification::PUBLIC ),
			array( 'subject.target_key' ),
			array( 'subject.target_key' )
		);

		$registry->register(
			self::JAVASCRIPT_ERROR,
			1,
			array( 'payload.error_category' => Classification::PUBLIC ),
			array( 'payload.error_category' ),
			array( 'payload.error_category' )
		);
	}
}
