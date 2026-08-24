<?php
/**
 * A notification test's own outcome.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

/**
 * Every distinct, plain-language-explainable state a NotificationTester
 * result can land on (M08.2 plan §2). DISABLED is mutually exclusive with
 * every other case — a disabled rule is never evaluated for a match at
 * all. Legacy-condition unrepresentability is deliberately not a case
 * here: it is an independent flag on NotificationTestResult, since a
 * legacy rule is still genuinely evaluated and can land on any of these
 * outcomes.
 */
enum NotificationTestOutcome: string {
	case WOULD_SEND             = 'would_send';
	case NOT_MATCHED            = 'not_matched';
	case DISABLED               = 'disabled';
	case DESTINATION_INELIGIBLE = 'destination_ineligible';
	case TEMPLATE_INVALID       = 'template_invalid';
}
