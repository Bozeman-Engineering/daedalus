<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

/**
 * Hashes the stable prefix of a leg.
 *
 * The prefix is the tool definitions in order plus the system prompt.
 * Every provider's cache keys on this prefix byte for byte, so the hash is
 * over the exact arrays in the exact order the request is rendered from:
 * no sorting, no normalizing. Two legs with equal hashes shared a prefix;
 * unequal hashes mean the cache was rewritten, which the segment notes.
 */
final class Prefix {

  /**
   * The hash of a leg's prefix.
   *
   * @param string $system
   *   The system prompt as sent.
   * @param array $tools
   *   The tool definitions as rendered, in the order sent.
   *
   * @return string
   *   A hex SHA-256.
   */
  public static function hash(string $system, array $tools): string {
    return hash('sha256', json_encode(
      ['system' => $system, 'tools' => $tools],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));
  }

}
