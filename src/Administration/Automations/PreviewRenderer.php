<?php
/**
 * "Example notification preview" rendering.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use Throwable;
use UniversalTelegram\Automations\TemplateRenderer;
use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;

/**
 * Renders a message template through the real production path
 * (TemplateRenderer::render(), unchanged) against a synthetic
 * EventEnvelope built solely from FieldTypeCatalog's own fixed,
 * non-sensitive preview_value() entries — so the "Example notification
 * preview" reflects real MarkdownV2 escaping and the real
 * "disallowed/absent field renders empty" behavior, while never touching
 * a database, an HTTP client, EventHistoryRepository, the current user, or
 * any real order/visitor/Telegram data (M08.1 plan "Define the preview
 * precisely"). This class's own construction takes only a Registry, which
 * itself carries no I/O.
 */
final class PreviewRenderer {

	private const PREVIEW_IDEMPOTENCY_KEY = 'preview';

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Renders the example preview for one event type and template. Returns
	 * an empty string for an unregistered event type or a template that
	 * fails to render (never a fatal).
	 *
	 * @param string $event_type The selected event type.
	 * @param string $template   The message template, exactly as the admin typed it.
	 *
	 * @return string
	 */
	public function render( string $event_type, string $template ): string {
		if ( ! $this->registry->is_registered( $event_type ) ) {
			return '';
		}

		$allowed_fields = ConditionRowRenderer::eligible_fields( $event_type, $this->registry );
		$sample         = array(
			'actor'   => array(),
			'subject' => array(),
			'context' => array(),
			'payload' => array(),
		);

		foreach ( $allowed_fields as $field_path ) {
			$segments = explode( '.', $field_path, 2 );
			if ( 2 !== count( $segments ) || ! isset( $sample[ $segments[0] ] ) ) {
				continue;
			}

			$sample[ $segments[0] ][ $segments[1] ] = FieldTypeCatalog::preview_value( $field_path );
		}

		try {
			$envelope = new EventEnvelope(
				$this->registry,
				$event_type,
				self::PREVIEW_IDEMPOTENCY_KEY,
				EventSource::CUSTOM,
				$sample['actor'],
				$sample['subject'],
				$sample['context'],
				$sample['payload']
			);
		} catch ( Throwable $exception ) {
			return '';
		}

		return ( new TemplateRenderer() )->render( $template, $envelope, $this->registry->allowed_variable_fields_for( $event_type ) );
	}
}
