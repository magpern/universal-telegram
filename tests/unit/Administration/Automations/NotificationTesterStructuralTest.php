<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Administration\Automations;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use UniversalTelegram\Administration\Automations\NotificationTester;
use UniversalTelegram\Administration\Automations\PreviewRenderer;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationDispatcher;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Automations\RuleEvaluator;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * M08.2 plan §7 WP4 / §6 / §8 item 13: the structural half of the
 * no-side-effect proof. NotificationTester must be able to reach a
 * dispatch, a dispatch-log write, an event-history write, an audit-log
 * write, a queue job, or an HTTP client only if one of those types is
 * actually wired into its constructor — so an allowlist over the
 * constructor's own declared parameter types is a stronger, executable
 * guarantee than grepping the class body for forbidden method calls.
 */
final class NotificationTesterStructuralTest extends TestCase {

	/**
	 * @var array<int, class-string>
	 */
	private const ALLOWED_CONSTRUCTOR_PARAMETER_TYPES = array(
		RuleEvaluator::class,
		NotificationRuleRepository::class,
		BotProfileRepository::class,
		DestinationRepository::class,
		Registry::class,
		PreviewRenderer::class,
	);

	/**
	 * @return array<int, ReflectionParameter>
	 */
	private function constructor_parameters(): array {
		$reflection  = new ReflectionClass( NotificationTester::class );
		$constructor = $reflection->getConstructor();

		$this->assertNotNull( $constructor, 'NotificationTester must declare a constructor.' );

		return $constructor->getParameters();
	}

	public function test_constructor_accepts_exactly_the_allowlisted_collaborators(): void {
		$actual_types = array();

		foreach ( $this->constructor_parameters() as $parameter ) {
			$type = $parameter->getType();
			$this->assertInstanceOf( ReflectionNamedType::class, $type, "Parameter \${$parameter->getName()} must have a single named class type." );
			$actual_types[] = $type->getName();
		}

		$expected = self::ALLOWED_CONSTRUCTOR_PARAMETER_TYPES;
		sort( $actual_types );
		sort( $expected );

		$this->assertSame( $expected, $actual_types, 'NotificationTester must depend on exactly the allowlisted collaborators — no more, no fewer.' );
	}

	public function test_constructor_never_depends_on_dispatch_history_audit_or_a_transport_client(): void {
		$actual_types = array_map(
			static function ( ReflectionParameter $parameter ): string {
				$type = $parameter->getType();
				return $type instanceof ReflectionNamedType ? $type->getName() : '';
			},
			$this->constructor_parameters()
		);

		$forbidden = array_merge(
			array(
				NotificationDispatcher::class,
				DispatchLogRepository::class,
				MessageDispatcher::class,
				TelegramApiClient::class,
				EventHistoryRepository::class,
				AuditLogger::class,
				AuditLogRepository::class,
			),
			self::queue_namespace_classes()
		);

		foreach ( $forbidden as $forbidden_class ) {
			$this->assertNotContains( $forbidden_class, $actual_types, "NotificationTester must never depend on {$forbidden_class}." );
		}
	}

	/**
	 * Every class file under src/Queue/, converted to its FQCN — derived
	 * from the filesystem rather than hand-typed, so a future Queue class
	 * is automatically covered without this test needing an update.
	 *
	 * @return array<int, class-string>
	 */
	private static function queue_namespace_classes(): array {
		$plugin_root = dirname( __DIR__, 4 );
		$found       = glob( $plugin_root . '/src/Queue/*.php' );
		$files       = false !== $found ? $found : array();

		$classes = array();
		foreach ( $files as $file ) {
			$classes[] = 'UniversalTelegram\\Queue\\' . basename( $file, '.php' );
		}

		return $classes;
	}

	public function test_the_queue_namespace_scan_actually_found_classes(): void {
		// Guards against a silently broken filesystem path defeating the
		// forbidden-dependency check above.
		$this->assertNotEmpty( self::queue_namespace_classes() );
	}
}
