<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Intelligence;

use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * M11B plan §4 step 28: operator account deletion anonymizes requester/
 * reviewer identity on the draft — draft content, status, and the owning
 * summary row are all untouched, mirroring
 * AiDraftRepositoryAnonymizationTest's identical precedent.
 */
final class SummaryAiRepositoryAnonymizationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
		parent::tearDown();
	}

	private function reset(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function repository(): SummaryAiRepository {
		return new SummaryAiRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function summary_run_id(): int {
		$row = ( new OperationalSummaryRepository( new SchemaHealth() ) )->create_or_get_for_date( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );

		return (int) $row['id'];
	}

	public function test_anonymize_operator_nulls_requested_and_reviewed_by_leaving_content_and_summary_intact(): void {
		$repository     = $this->repository();
		$summary_run_id = $this->summary_run_id();

		$request = $repository->request( $summary_run_id, 42, 'openai', 'gpt', 'v1' );
		$draft   = $repository->find_by_uuid( (string) $request['draft_uuid'] );
		$claim   = $repository->claim_candidate_row( $draft->draft_uuid(), 90 );
		$repository->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'body text' );
		$repository->mark_reviewed( $draft->id(), 42 );

		$repository->anonymize_operator( 42 );

		$anonymized = $repository->find( $draft->id() );
		$this->assertNull( $anonymized->requested_by_user_id() );
		$this->assertNull( $anonymized->reviewed_by_user_id() );
		$this->assertSame( 'reviewed', $anonymized->status() );

		$decrypted = $repository->decrypt_body( $anonymized );
		$this->assertSame( 'body text', $decrypted->plaintext() );

		// The owning summary row is never touched by this path.
		$summary = ( new OperationalSummaryRepository( new SchemaHealth() ) )->find( $summary_run_id );
		$this->assertNotNull( $summary );
	}
}
