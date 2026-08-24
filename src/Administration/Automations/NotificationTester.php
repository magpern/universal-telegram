<?php
/**
 * Safe, no-dispatch notification testing.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use Throwable;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The M08.2 "Test notifications" tab's own orchestration: answers "would
 * this rule match, and what would it send" for administrator-supplied
 * example values, without ever touching a queue, dispatch log, event
 * history table, audit log, or Telegram/HTTP client (M08.2 plan §4, §6).
 * Deliberately kept in Administration\Automations, one layer above the
 * pure Automations engine, because it depends on this layer's own
 * friendly-metadata catalogs (FieldTypeCatalog, RuleEditor) alongside
 * RuleEvaluator's structured evaluate_conditions() trace — RuleEvaluator
 * itself remains free of any such dependency.
 *
 * Match/no-match evaluation uses the administrator's own example values
 * (merged over FieldTypeCatalog's own fixed defaults for any field left
 * unset), so a changed example value genuinely changes the outcome; the
 * rendered message preview, on a WOULD_SEND outcome, is always
 * PreviewRenderer's own fixed, non-sensitive synthetic rendering — never
 * the raw stored template — so the preview is stable and safe regardless
 * of what an administrator typed into the example-values fields.
 */
final class NotificationTester {

	private const TEST_IDEMPOTENCY_KEY = 'notification-test';

	/**
	 * Constructor.
	 *
	 * @param RuleEvaluator               $evaluator        The pure condition-evaluation engine (M08.2 plan §1).
	 * @param NotificationRuleRepository  $rules            Supplies each event type's own enabled rules for the custom-scenario mode.
	 * @param BotProfileRepository       $bots             Re-checked, never mutated, to determine destination eligibility.
	 * @param DestinationRepository      $destinations     Re-checked, never mutated, to determine destination eligibility.
	 * @param Registry                   $registry         The current request's event registry.
	 * @param PreviewRenderer            $preview_renderer The existing "Example notification preview" renderer (M08.1), reused unchanged.
	 */
	public function __construct(
		private readonly RuleEvaluator $evaluator,
		private readonly NotificationRuleRepository $rules,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly Registry $registry,
		private readonly PreviewRenderer $preview_renderer
	) {}

	/**
	 * Tests one specific existing notification rule against
	 * administrator-supplied example values ("Test an existing
	 * notification"). A disabled rule is never evaluated for a match at
	 * all — its outcome is always DISABLED.
	 *
	 * @param NotificationRule     $rule          The rule to test.
	 * @param array<string, mixed> $sample_values Administrator-supplied example values, keyed by field path.
	 *
	 * @return NotificationTestResult
	 */
	public function test_rule( NotificationRule $rule, array $sample_values ): NotificationTestResult {
		if ( ! $rule->enabled() ) {
			return new NotificationTestResult(
				$rule->id(),
				$rule->name(),
				NotificationTestOutcome::DISABLED,
				array(),
				null,
				self::has_unrepresentable_legacy_conditions( $rule )
			);
		}

		return $this->evaluate_and_build_result( $rule, $sample_values );
	}

	/**
	 * Tests administrator-supplied example values for one event type
	 * against every one of that event type's own enabled rules ("Test a
	 * custom scenario") — the same enabled-only rule set a real occurrence
	 * of this event would be evaluated against.
	 *
	 * @param string                $event_type    The event type to test.
	 * @param array<string, mixed>  $sample_values Administrator-supplied example values, keyed by field path.
	 *
	 * @return array<int, NotificationTestResult>
	 */
	public function test_event( string $event_type, array $sample_values ): array {
		$results = array();

		foreach ( $this->rules->for_event_type( $event_type, true ) as $rule ) {
			$results[] = $this->evaluate_and_build_result( $rule, $sample_values );
		}

		return $results;
	}

	/**
	 * Evaluates one enabled rule and builds its result: match/no-match,
	 * destination eligibility, then the preview render — in that order,
	 * each a strictly narrower gate than the last.
	 *
	 * @param NotificationRule     $rule          The rule to evaluate.
	 * @param array<string, mixed> $sample_values Administrator-supplied example values.
	 *
	 * @return NotificationTestResult
	 */
	private function evaluate_and_build_result( NotificationRule $rule, array $sample_values ): NotificationTestResult {
		$legacy = self::has_unrepresentable_legacy_conditions( $rule );

		$envelope = $this->build_envelope( $rule->event_type(), $sample_values );

		if ( null === $envelope ) {
			return new NotificationTestResult( $rule->id(), $rule->name(), NotificationTestOutcome::NOT_MATCHED, array(), null, $legacy );
		}

		$trace = $this->evaluator->evaluate_conditions( $rule, $envelope );

		if ( ! $trace->matched() ) {
			return new NotificationTestResult( $rule->id(), $rule->name(), NotificationTestOutcome::NOT_MATCHED, FailingConditionExplainer::explain( $trace ), null, $legacy );
		}

		if ( ! $this->destination_is_eligible( $rule->bot_id(), $rule->destination_id() ) ) {
			return new NotificationTestResult( $rule->id(), $rule->name(), NotificationTestOutcome::DESTINATION_INELIGIBLE, array(), null, $legacy );
		}

		$preview = $this->preview_renderer->render( $rule->event_type(), $rule->template() );

		if ( '' === $preview ) {
			return new NotificationTestResult( $rule->id(), $rule->name(), NotificationTestOutcome::TEMPLATE_INVALID, array(), null, $legacy );
		}

		return new NotificationTestResult( $rule->id(), $rule->name(), NotificationTestOutcome::WOULD_SEND, array(), $preview, $legacy );
	}

	/**
	 * Builds a synthetic EventEnvelope for one event type from the
	 * administrator's own example values, defaulting any field left unset
	 * to FieldTypeCatalog::preview_value() (mirrors PreviewRenderer's own
	 * envelope-building loop, but honors the caller's own overrides so a
	 * changed example value can genuinely change the match outcome).
	 * Returns null, never throws, if the event type is unregistered or the
	 * envelope otherwise cannot be constructed.
	 *
	 * @param string                $event_type    The event type.
	 * @param array<string, mixed>  $sample_values Administrator-supplied example values, keyed by field path.
	 *
	 * @return EventEnvelope|null
	 */
	private function build_envelope( string $event_type, array $sample_values ): ?EventEnvelope {
		if ( ! $this->registry->is_registered( $event_type ) ) {
			return null;
		}

		$sample = array(
			'actor'   => array(),
			'subject' => array(),
			'context' => array(),
			'payload' => array(),
		);

		foreach ( ConditionRowRenderer::eligible_fields( $event_type, $this->registry ) as $field_path ) {
			$segments = explode( '.', $field_path, 2 );
			if ( 2 !== count( $segments ) || ! isset( $sample[ $segments[0] ] ) ) {
				continue;
			}

			$sample[ $segments[0] ][ $segments[1] ] = $sample_values[ $field_path ] ?? FieldTypeCatalog::preview_value( $field_path );
		}

		try {
			return new EventEnvelope(
				$this->registry,
				$event_type,
				self::TEST_IDEMPOTENCY_KEY,
				EventSource::CUSTOM,
				$sample['actor'],
				$sample['subject'],
				$sample['context'],
				$sample['payload']
			);
		} catch ( Throwable $exception ) {
			return null;
		}
	}

	/**
	 * Whether the destination this rule is configured to send to is
	 * currently eligible — mirrors NotificationDispatcher's own dispatch-
	 * time gate exactly (bot ACTIVE, destination enabled), not
	 * DigestEligibility::destination_is_eligible(), which additionally
	 * excludes conversation-linked destinations — a digest-target-specific
	 * rule that would misreport an otherwise perfectly eligible
	 * notification destination as ineligible here.
	 *
	 * @param int $bot_id         The rule's own bot id.
	 * @param int $destination_id The rule's own destination id.
	 *
	 * @return bool
	 */
	private function destination_is_eligible( int $bot_id, int $destination_id ): bool {
		$bot = $this->bots->find( $bot_id );

		if ( null === $bot || BotStatus::ACTIVE !== $bot->status() ) {
			return false;
		}

		$destination = $this->destinations->find( $destination_id );

		return null !== $destination && $destination->enabled();
	}

	/**
	 * Whether this rule's stored conditions include a clause the friendly
	 * builder cannot represent — reuses RuleEditor's own existing
	 * representability check (M08.1) rather than a second implementation
	 * of the same field/operator allowlist logic.
	 *
	 * @param NotificationRule $rule The rule to check.
	 *
	 * @return bool
	 */
	private static function has_unrepresentable_legacy_conditions( NotificationRule $rule ): bool {
		return ! RuleEditor::from_existing( $rule )['representable'];
	}
}
