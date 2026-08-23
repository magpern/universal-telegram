<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Config;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * docs/adr/0028 decisions 3 and 5: the singleton config row is seeded by
 * migration (never inserted here), AI cannot become enabled without a
 * non-empty credential and model, and disabling/deleting the credential
 * cancels any in-flight draft.
 */
final class AIProviderRepositoryTest extends WP_UnitTestCase {

	private function repository(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	protected function tear_down(): void {
		global $wpdb;

		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET model = '', enabled = 0, api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	public function test_get_returns_the_migration_seeded_singleton_row(): void {
		$config = $this->repository()->get();

		$this->assertNotNull( $config );
		$this->assertSame( 'openai', $config->provider() );
		$this->assertFalse( $config->is_enabled() );
		$this->assertFalse( $config->has_credential() );
	}

	public function test_update_settings_fails_closed_when_no_credential_is_configured(): void {
		$repository = $this->repository();

		$repository->update_settings( 'gpt-4o-mini', true );

		$config = $repository->get();
		$this->assertFalse( $config->is_enabled(), 'Cannot be enabled without a credential.' );
	}

	public function test_update_settings_fails_closed_when_model_is_empty(): void {
		$repository = $this->repository();
		$repository->set_credential( 'sk-test-key' );

		$repository->update_settings( '', true );

		$config = $repository->get();
		$this->assertFalse( $config->is_enabled(), 'Cannot be enabled without a bounded model identifier.' );
	}

	public function test_update_settings_enables_once_credential_and_model_are_both_present(): void {
		$repository = $this->repository();
		$repository->set_credential( 'sk-test-key' );

		$repository->update_settings( 'gpt-4o-mini', true );

		$config = $repository->get();
		$this->assertTrue( $config->is_enabled() );
		$this->assertSame( 'gpt-4o-mini', $config->model() );
		$this->assertTrue( $config->is_ready() );
	}

	public function test_set_credential_round_trips_via_decrypt_api_key(): void {
		$repository = $this->repository();

		$repository->set_credential( 'sk-round-trip-value' );

		$result = $repository->decrypt_api_key();
		$this->assertNotNull( $result );
		$this->assertSame( CredentialState::AVAILABLE, $result->state() );
		$this->assertSame( 'sk-round-trip-value', $result->plaintext() );

		$stored = $repository->get()->api_key_ciphertext();
		$this->assertNotSame( 'sk-round-trip-value', $stored );
	}

	public function test_disabling_cancels_any_in_flight_draft(): void {
		$repository = $this->repository();
		$repository->set_credential( 'sk-test-key' );
		$repository->update_settings( 'gpt-4o-mini', true );

		$draft_uuid = $this->seed_in_flight_draft( 'generating' );

		$repository->update_settings( 'gpt-4o-mini', false );

		$this->assert_draft_cancelled( $draft_uuid );
	}

	public function test_delete_credential_clears_key_disables_and_cancels_in_flight_draft(): void {
		$repository = $this->repository();
		$repository->set_credential( 'sk-test-key' );
		$repository->update_settings( 'gpt-4o-mini', true );

		$draft_uuid = $this->seed_in_flight_draft( 'queued' );

		$repository->delete_credential();

		$config = $repository->get();
		$this->assertFalse( $config->has_credential() );
		$this->assertFalse( $config->is_enabled() );

		$this->assert_draft_cancelled( $draft_uuid );
	}

	public function test_bump_ack_policy_changes_version_and_text(): void {
		$repository       = $this->repository();
		$original_version = $repository->get()->ack_policy_version();

		$repository->bump_ack_policy( 'v2-test', 'New disclosure copy.' );

		$config = $repository->get();
		$this->assertNotSame( $original_version, $config->ack_policy_version() );
		$this->assertSame( 'New disclosure copy.', $config->ack_text() );
	}

	private function seed_in_flight_draft( string $status ): string {
		global $wpdb;

		$draft_uuid = wp_generate_uuid4();
		$now        = current_time( 'mysql', true );

		$wpdb->insert(
			$wpdb->prefix . Migrator::AI_DRAFTS_TABLE,
			array(
				'draft_uuid'            => $draft_uuid,
				'conversation_id'       => 1,
				'status'                => $status,
				'provider'              => 'openai',
				'model'                 => 'gpt-4o-mini',
				'prompt_policy_version' => 'v1',
				'requested_by_user_id'  => 1,
				'lease_token'           => 'generating' === $status ? wp_generate_uuid4() : null,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);

		return $draft_uuid;
	}

	private function assert_draft_cancelled( string $draft_uuid ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, failure_class, lease_token FROM {$wpdb->prefix}" . Migrator::AI_DRAFTS_TABLE . ' WHERE draft_uuid = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$draft_uuid
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'provider_disabled', $row['failure_class'] );
		$this->assertNull( $row['lease_token'] );
	}
}
