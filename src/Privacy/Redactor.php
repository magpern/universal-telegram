<?php
/**
 * Fail-closed redaction.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Privacy;

/**
 * Walks a data structure, including nested arrays, matched against an
 * explicit classification map keyed by dot-notation path. A SECRET field
 * is stripped entirely; a SENSITIVE field is masked; any field with no
 * corresponding map entry, at any depth, is rejected — never passed
 * through unchanged, and never defaulted to PUBLIC. This fail-closed
 * behaviour is deliberate and load-bearing (docs/adr/0009).
 */
final class Redactor {

	private const MASK = '***';

	/**
	 * Redacts a data structure against an explicit classification map.
	 *
	 * @param array<string, mixed>          $data              The data to redact.
	 * @param array<string, Classification> $classification_map Dot-notation path to classification.
	 *
	 * @return array<string, mixed>
	 */
	public function redact( array $data, array $classification_map ): array {
		return $this->redact_at_path( $data, $classification_map, '' );
	}

	/**
	 * Redacts one level of a data structure, recursing into array values.
	 *
	 * @param array<string, mixed>          $data              The data to redact at this depth.
	 * @param array<string, Classification> $classification_map Dot-notation path to classification.
	 * @param string                        $prefix            The dot-notation path prefix so far.
	 *
	 * @return array<string, mixed>
	 */
	private function redact_at_path( array $data, array $classification_map, string $prefix ): array {
		$result = array();

		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$result[ $key ] = $this->redact_at_path( $value, $classification_map, $path );
				continue;
			}

			if ( ! isset( $classification_map[ $path ] ) ) {
				continue;
			}

			$classification = $classification_map[ $path ];

			if ( Classification::SECRET === $classification ) {
				continue;
			}

			if ( Classification::SENSITIVE === $classification ) {
				$result[ $key ] = self::MASK;
				continue;
			}

			$result[ $key ] = $value;
		}

		return $result;
	}
}
