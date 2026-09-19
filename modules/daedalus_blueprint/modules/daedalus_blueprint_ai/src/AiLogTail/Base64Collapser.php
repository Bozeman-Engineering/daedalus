<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * Collapses base64 blobs to a short head plus a byte count.
 *
 * Detection is by value, never by key: a data: URI with a base64 payload,
 * or any bare run of base64 characters at or beyond the length floor,
 * collapses wherever it sits (prompt text, response JSON, tool results,
 * configuration, debug data), so a provider that stores its image bytes
 * under a new key (Gemini's inlineData.data, OpenAI's b64_json, Imagen's
 * bytesBase64Encoded) still prints as one line. A bare run must also mix
 * upper case, lower case and digits, which binary data past the floor
 * always does and a hex digest or a long lower-case URL path never does;
 * the data: prefix is proof enough on its own. Strings shorter than the
 * floor and long strings that are not one such run (prose, URLs, JSON)
 * pass through untouched.
 */
final class Base64Collapser {

  /**
   * The shortest run of base64 characters that counts as a blob.
   *
   * 128 characters encode 96 bytes: far below the smallest image a provider
   * returns, and past any identifier that mixes cases and digits without a
   * separator.
   */
  public const MIN_RUN = 128;

  /**
   * How many characters of the match survive the collapse.
   */
  public const KEEP = 64;

  /**
   * Matches a base64 payload, with its data: URI prefix when it has one.
   *
   * Group 1 is the prefix (empty for a bare run), group 2 the payload; the
   * prefix stays in the match so the head of a data URI still names its
   * media type. The run is possessive so a long string never backtracks.
   */
  private const PATTERN = '~((?i:data:[a-z0-9.+/-]*;base64,)?)([A-Za-z0-9+/]{' . self::MIN_RUN . ',}+={0,2})~';

  /**
   * Collapses base64 blobs in a string or, recursively, in an array.
   *
   * @param mixed $value
   *   A string, a nested array of anything, or any other value.
   *
   * @return mixed
   *   The same shape with every base64 blob replaced by its first
   *   self::KEEP characters and "... [N bytes base64]" where N is the
   *   payload's length in the log. Array keys and non-string scalars are
   *   returned as they were.
   */
  public function collapse(mixed $value): mixed {
    if (is_array($value)) {
      return array_map($this->collapse(...), $value);
    }
    if (!is_string($value) || strlen($value) < self::MIN_RUN) {
      return $value;
    }
    return preg_replace_callback(self::PATTERN, self::replace(...), $value);
  }

  /**
   * Replaces one match, or hands it back when the run is not base64.
   *
   * @param string[] $match
   *   The whole match, the data: prefix and the payload.
   *
   * @return string
   *   The collapsed form, or the original text.
   */
  private static function replace(array $match): string {
    [$whole, $prefix, $payload] = $match;
    if ($prefix === '' && !self::mixesCasesAndDigits($payload)) {
      return $whole;
    }
    return substr($whole, 0, self::KEEP) . '... [' . strlen($payload) . ' bytes base64]';
  }

  /**
   * Tells whether a run has upper case, lower case and digits alike.
   *
   * @param string $run
   *   A run of base64 characters.
   *
   * @return bool
   *   TRUE when all three classes occur.
   */
  private static function mixesCasesAndDigits(string $run): bool {
    return preg_match('/[A-Z]/', $run) === 1
      && preg_match('/[a-z]/', $run) === 1
      && preg_match('/[0-9]/', $run) === 1;
  }

}
