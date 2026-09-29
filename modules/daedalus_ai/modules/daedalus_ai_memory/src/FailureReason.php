<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory;

use Drupal\Component\Serialization\Json;

/**
 * Distills a provider or runtime failure into the one line a user can act on.
 *
 * The ai provider modules wrap their SDK's exception message verbatim, and
 * the Anthropic SDK's message is a label line followed by the pretty-printed
 * response body, `{"status": 400, "body": {"error": {"type": ..., "message":
 * ...}}}`. The API's own sentence in `body.error.message` ("At least one of
 * the image dimensions exceed max allowed size: 8000 pixels") is the part
 * that tells an editor why the turn was rejected; the label and the JSON
 * around it are noise in a chat bubble. A message that carries no such body
 * is reduced to its first non-empty line, which is what a plain exception
 * puts there.
 */
final class FailureReason {

  /**
   * Returns the human sentence inside a raw failure message.
   *
   * @param string $raw
   *   The exception message as thrown.
   *
   * @return string
   *   The API's error sentence when the message embeds a response body
   *   carrying one, else the message's first non-empty line, else the raw
   *   message trimmed.
   */
  public static function distill(string $raw): string {
    $raw = trim($raw);
    $brace = strpos($raw, '{');
    if ($brace !== FALSE) {
      $decoded = Json::decode(substr($raw, $brace));
      $sentence = $decoded['body']['error']['message'] ?? $decoded['error']['message'] ?? NULL;
      if (is_string($sentence) && trim($sentence) !== '') {
        return trim($sentence);
      }
    }
    foreach (preg_split('/\R/', $raw) as $line) {
      if (trim($line) !== '') {
        return trim($line);
      }
    }
    return $raw;
  }

}
