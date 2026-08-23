<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Administration\AI;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Administration\AI\AISettingsPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * Covers the model-identifier field's dropdown-plus-"Other (advanced)"
 * fallback: a known model submitted via the select is persisted directly;
 * the sentinel `__other__` value defers to the free-text `model_other`
 * field instead.
 */
final class AISettingsPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		( new CapabilityRegistrar() )->grant_to_administrator();
		$this->reset_ai_state();
	}

	protected function tearDown(): void {
		$this->reset_ai_state();
		parent::tearDown();
	}

	private function reset_ai_state(): void {
		global $wpdb;

		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET model = '', enabled = 0, api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function repository(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	/**
	 * @return AISettingsPage&object{redirected_to: ?string}
	 */
	private function saving_page(): AISettingsPage {
		return new class( $this->repository() ) extends AISettingsPage {
			public ?string $redirected_to = null;

			protected function redirect_and_exit( string $url ): void {
				$this->redirected_to = $url;
			}
		};
	}

	private function authenticate_as_administrator(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function test_saving_a_known_model_from_the_dropdown_persists_it_directly(): void {
		$this->authenticate_as_administrator();

		$_POST['model']       = 'gpt-4o-mini';
		$_POST['enabled']     = '1';
		$nonce                = wp_create_nonce( AISettingsPage::ACTION_SAVE_SETTINGS );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->saving_page()->handle_save_settings();

		$this->assertSame( 'gpt-4o-mini', $this->repository()->get()->model() );

		unset( $_POST['model'], $_POST['enabled'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_saving_other_uses_the_free_text_field_instead_of_the_sentinel(): void {
		$this->authenticate_as_administrator();

		$_POST['model']       = '__other__';
		$_POST['model_other'] = 'gpt-5-preview';
		$nonce                = wp_create_nonce( AISettingsPage::ACTION_SAVE_SETTINGS );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->saving_page()->handle_save_settings();

		$this->assertSame( 'gpt-5-preview', $this->repository()->get()->model() );

		unset( $_POST['model'], $_POST['model_other'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_the_other_sentinel_is_never_itself_persisted_as_a_model(): void {
		$this->authenticate_as_administrator();

		$_POST['model']       = '__other__';
		$nonce                = wp_create_nonce( AISettingsPage::ACTION_SAVE_SETTINGS );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->saving_page()->handle_save_settings();

		$this->assertSame( '', $this->repository()->get()->model() );

		unset( $_POST['model'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_render_selects_the_other_option_and_prefills_the_text_field_for_an_unlisted_model(): void {
		$this->authenticate_as_administrator();
		$this->repository()->update_settings( 'gpt-5-preview', false );

		ob_start();
		( new AISettingsPage( $this->repository() ) )->render_tab_content();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="__other__"  selected=\'selected\'', $html );
		$this->assertStringContainsString( 'id="ut-ai-model-other" name="model_other" maxlength="191" placeholder="Custom model identifier" value="gpt-5-preview"', $html );
	}

	public function test_render_selects_a_known_model_and_hides_the_text_field(): void {
		$this->authenticate_as_administrator();
		$this->repository()->update_settings( 'gpt-4o', false );

		ob_start();
		( new AISettingsPage( $this->repository() ) )->render_tab_content();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="gpt-4o"  selected=\'selected\'', $html );
		$this->assertStringContainsString( 'style="display:none"', $html );
	}
}
