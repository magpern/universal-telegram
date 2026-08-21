<?php
/**
 * Invalid condition field reference.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use Exception;

/**
 * Thrown by NotificationRuleRepository::save() when a condition clause (or
 * the template) references a field not present in the rule's own event
 * type's Registry::allowed_variable_fields_for() at save time (M02 plan
 * §7.2).
 */
final class InvalidConditionFieldException extends Exception {}
