<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Utility;

/**
 * Decodes HTML entities in plain-text values.
 *
 * Defense against LLMs that HTML-escape plain-text tool arguments (an
 * observed failure mode: &amp; emitted inside function-call JSON, stored
 * verbatim, rendered double-escaped). Only ever applied to values headed
 * for plain-text storage — in rich text, entities are legitimate HTML
 * source and must survive. Callers gate on the decode_html_entities
 * option, which only AI-facing consumers set.
 */
final class HtmlEntityDecoder {

  /**
   * Recursively decodes HTML entities in string values.
   *
   * Single pass, so a pre-escaped &amp;amp; degrades gracefully to &amp;.
   *
   * @param mixed $value
   *   The value to decode.
   *
   * @return mixed
   *   The value with all strings entity-decoded.
   */
  public static function decode(mixed $value): mixed {
    if (is_string($value)) {
      return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (is_array($value)) {
      return array_map(static fn (mixed $item) => static::decode($item), $value);
    }
    return $value;
  }

}
