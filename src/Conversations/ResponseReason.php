<?php
/**
 * Machine-readable conversation REST response reason.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * Exactly four fixed values, never free text, additive to existing response
 * shapes (M06.2 corrective plan v2 §3.7, ADR-0023 amendment). `CONVERSATION_EXPIRED`
 * is attached uniformly, identically, with no branching on cause, to every
 * `controlled_not_found()` 404 — the response body stays byte-for-byte
 * identical across every distinct failure cause, preserving ADR-0021's
 * non-enumeration guarantee exactly; only one previously-empty field now
 * carries a constant label.
 */
enum ResponseReason: string {
	case RATE_LIMITED               = 'rate_limited';
	case CONVERSATION_EXPIRED       = 'conversation_expired';
	case REQUEST_FAILED             = 'request_failed';
	case TEMPORARY_DELIVERY_PENDING = 'temporary_delivery_pending';
	case AUTH_REQUIRED              = 'auth_required';
	case CONVERSATION_UNAVAILABLE   = 'conversation_unavailable';
}
