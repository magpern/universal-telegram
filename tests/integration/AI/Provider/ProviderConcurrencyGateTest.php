<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Provider;

use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Provider\ProviderConcurrencyGate;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ProviderConcurrencyGateTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function ai_drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function summary_drafts(): SummaryAiRepository {
		return new SummaryAiRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function gate(): ProviderConcurrencyGate {
		return new ProviderConcurrencyGate( new SchemaHealth() );
	}

	public function test_admits_up_to_the_shared_cap_across_both_domains_and_then_defers(): void {
		$ai_drafts      = $this->ai_drafts();
		$summary_drafts = $this->summary_drafts();

		// One already-active generation in each domain — sum is 2, at cap.
		$conversations = new \UniversalTelegram\Conversations\ConversationRepository( new SchemaHealth(), new CredentialVault(), new \UniversalTelegram\Conversations\VisitorTokenGenerator() );
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed', 1, null );
		$draft_a       = $ai_drafts->create( $conversation->id(), 1, 'openai', 'gpt', 'v1' );
		$ai_drafts->claim_candidate_row( $draft_a->draft_uuid(), 90 );

		$summaries = new OperationalSummaryRepository( new SchemaHealth() );
		$row       = $summaries->create_or_get_for_date( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );
		$request_b = $summary_drafts->request( (int) $row['id'], 1, 'openai', 'gpt', 'v1' );
		$summary_drafts->claim_candidate_row( (string) $request_b['draft_uuid'], 90 );

		// A third claim attempt, from either domain, must be deferred (null),
		// never admitted — the combined active-generation count is already 2.
		$draft_c = $ai_drafts->create( $conversation->id(), 1, 'openai', 'gpt', 'v1' );

		$result = $this->gate()->claim_or_defer(
			2,
			array(
				fn() => $ai_drafts->count_active_generating(),
				fn() => $summary_drafts->count_active_generating(),
			),
			fn() => $ai_drafts->claim_candidate_row( $draft_c->draft_uuid(), 90 )
		);

		$this->assertNull( $result );

		// The candidate row was never claimed — still queued, no lease.
		$unclaimed = $ai_drafts->find( $draft_c->id() );
		$this->assertSame( 'queued', $unclaimed->status() );
		$this->assertNull( $unclaimed->lease_token() );
	}

	public function test_admits_when_the_combined_count_is_below_cap(): void {
		$ai_drafts      = $this->ai_drafts();
		$summary_drafts = $this->summary_drafts();

		$conversations = new \UniversalTelegram\Conversations\ConversationRepository( new SchemaHealth(), new CredentialVault(), new \UniversalTelegram\Conversations\VisitorTokenGenerator() );
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed', 1, null );
		$draft         = $ai_drafts->create( $conversation->id(), 1, 'openai', 'gpt', 'v1' );

		$result = $this->gate()->claim_or_defer(
			2,
			array(
				fn() => $ai_drafts->count_active_generating(),
				fn() => $summary_drafts->count_active_generating(),
			),
			fn() => $ai_drafts->claim_candidate_row( $draft->draft_uuid(), 90 )
		);

		$this->assertNotNull( $result );
		$this->assertSame( 'generating', $ai_drafts->find( $draft->id() )->status() );
	}

	/**
	 * "Design the gate so it has no prohibited repository reference" — the
	 * gate's own file must contain zero `use`/type-hint/instantiation
	 * references to either domain's repository class, using the identical
	 * static-scan technique AiDraftRepositoryAccessAllowListTest already
	 * uses, so M09's existing six-class allow-list needs no new entries.
	 */
	public function test_gate_file_references_no_domain_repository_class(): void {
		$file     = dirname( __DIR__, 4 ) . '/src/AI/Provider/ProviderConcurrencyGate.php';
		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertNotFalse( $contents );

		foreach ( array( 'AiDraftRepository', 'SummaryAiRepository' ) as $class ) {
			$this->assertFalse(
				(bool) preg_match( '/\buse\s+[^;]*' . $class . ';/', $contents ),
				"ProviderConcurrencyGate.php must not import {$class}."
			);
			$this->assertFalse(
				(bool) preg_match( '/\b' . $class . '\s+\$\w+/', $contents ),
				"ProviderConcurrencyGate.php must not type-hint a {$class} parameter."
			);
			$this->assertStringNotContainsString(
				"new {$class}(",
				$contents,
				"ProviderConcurrencyGate.php must not instantiate {$class}."
			);
		}
	}
}
