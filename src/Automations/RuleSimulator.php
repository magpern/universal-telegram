<?php
/**
 * Safe rule simulation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use Throwable;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;

/**
 * Constructs an EventEnvelope from hand-entered sample data (or an existing
 * event_history row), then calls the identical RuleEvaluator code path used
 * for real events — with the matched/rejected extension points replaced by
 * a no-op "would send"/"would reject" recorder: no MessageDispatcher::send()
 * call, no queue enqueue, no HTTP traffic, and no write to
 * notification_dispatch_log (M02 plan §9.2). Simulation never consumes the
 * (rule_id, event_id) idempotency space a real occurrence might later need.
 */
final class RuleSimulator {

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules              Supplies each event type's own enabled rules.
	 * @param Registry                   $registry           The current request's event registry.
	 * @param DispatchLogRepository      $dispatch_log       Required only to satisfy RuleEvaluator's constructor; never invoked during simulation.
	 * @param NotificationDispatcher     $dispatcher         Required only to satisfy RuleEvaluator's constructor; never invoked during simulation.
	 * @param DigestEligibility|null     $digest_eligibility Surfaces the same live "currently batched by Visitor Digest" outcome a real evaluation would produce for a digest-eligible event type (M11A §3.1).
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly DispatchLogRepository $dispatch_log,
		private readonly NotificationDispatcher $dispatcher,
		private readonly ?DigestEligibility $digest_eligibility = null
	) {}

	/**
	 * Runs a simulation.
	 *
	 * @param string               $event_type      A registered event type.
	 * @param array<string, mixed> $sample_data     actor/subject/context/payload sub-arrays.
	 * @param string               $idempotency_key A sample idempotency key. Never written anywhere.
	 *
	 * @return SimulationResult
	 */
	public function simulate( string $event_type, array $sample_data, string $idempotency_key ): SimulationResult {
		try {
			$envelope = new EventEnvelope(
				$this->registry,
				$event_type,
				$idempotency_key,
				EventSource::WORDPRESS_CORE,
				$sample_data['actor'] ?? array(),
				$sample_data['subject'] ?? array(),
				$sample_data['context'] ?? array(),
				$sample_data['payload'] ?? array()
			);
		} catch ( Throwable $exception ) {
			return new SimulationResult( array(), 'invalid_sample_data' );
		}

		$entries   = array();
		$evaluator = new class( $this->rules, $this->registry, $this->dispatch_log, $this->dispatcher, $this->digest_eligibility, $entries ) extends RuleEvaluator {

			/**
			 * Reference to the enclosing simulate() call's own $entries array.
			 * PHPStan cannot see that the by-reference binding in the
			 * constructor below makes every append here visible to that
			 * outer, later-read variable (ignored in phpstan.neon.dist).
			 *
			 * @var array<int, array{rule_id: int, rule_name: string, outcome: string, reason_code: string|null}>
			 */
			private array $entries_ref;

			/**
			 * Constructor.
			 *
			 * @param NotificationRuleRepository                                                                    $rules              Supplies each event type's own enabled rules.
			 * @param Registry                                                                                      $registry           The current request's event registry.
			 * @param DispatchLogRepository                                                                         $dispatch_log       Never invoked; satisfies the parent constructor only.
			 * @param NotificationDispatcher                                                                        $dispatcher         Never invoked; satisfies the parent constructor only.
			 * @param DigestEligibility|null                                                                        $digest_eligibility Consulted read-only, exactly as a real evaluation would.
			 * @param array<int, array{rule_id: int, rule_name: string, outcome: string, reason_code: string|null}> $entries_ref Reference to the outer $entries array.
			 */
			public function __construct( $rules, $registry, $dispatch_log, $dispatcher, $digest_eligibility, array &$entries_ref ) {
				parent::__construct( $rules, $registry, $dispatch_log, $dispatcher, $digest_eligibility );
				$this->entries_ref = &$entries_ref;
			}

			/**
			 * Records a matched outcome instead of dispatching.
			 *
			 * @param NotificationRule $rule  The matched rule.
			 * @param EventEnvelope    $event Unused; required only to match the parent signature.
			 */
			protected function on_matched( NotificationRule $rule, EventEnvelope $event ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $event is required to match the parent RuleEvaluator::on_matched() signature.
				$this->entries_ref[] = array(
					'rule_id'     => $rule->id(),
					'rule_name'   => $rule->name(),
					'outcome'     => 'matched',
					'reason_code' => null,
				);
			}

			/**
			 * Records a rejected outcome instead of writing to the dispatch log.
			 *
			 * @param NotificationRule $rule        The rejected rule.
			 * @param EventEnvelope    $event       Unused; required only to match the parent signature.
			 * @param string           $reason_code The fixed rejection reason code.
			 */
			protected function on_rejected( NotificationRule $rule, EventEnvelope $event, string $reason_code ): void {
				$this->entries_ref[] = array(
					'rule_id'     => $rule->id(),
					'rule_name'   => $rule->name(),
					'outcome'     => 'rejected',
					'reason_code' => $reason_code,
				);
			}
		};

		$evaluator->evaluate( $envelope );

		return new SimulationResult( $entries );
	}
}
