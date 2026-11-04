<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

// cspell:ignore zzzx
/**
 * Ranks the names of a vocabulary closest to a word outside it.
 *
 * The one near-miss rule behind every "did you mean" corrective on the
 * styling spine: an unrecognized property against the recognized names
 * (StyleMapValidator's INVALID_STYLE_PROPERTY), an ungranted property
 * against a roster's grant (VocabularyRoster::candidates()), a selector
 * word outside the wide selector tier against the tier
 * (STYLE_SELECTOR_UNKNOWN). Near-misses by edit distance ("pading" to
 * padding, "hovre" to hover) and containment either way ("font-color"
 * to color), ranked closest first; a needle contained in a candidate
 * counts only for candidates long enough to mean something (the wide
 * property tier has single-letter SVG names, and "zzzx" does not mean
 * the writer was reaching for "x").
 */
final class NearMisses {

  /**
   * The names closest to a word, closest first.
   *
   * @param string $needle
   *   The word outside the vocabulary (case-folded here).
   * @param iterable<string> $candidates
   *   The vocabulary, in its own order (ties keep it).
   * @param int $limit
   *   How many to answer.
   *
   * @return string[]
   *   Up to $limit candidates; [] when nothing is close.
   */
  public static function rank(string $needle, iterable $candidates, int $limit = 5): array {
    $needle = strtolower($needle);
    $scored = [];
    foreach ($candidates as $candidate) {
      if ($candidate === $needle) {
        continue;
      }
      $distance = levenshtein($needle, $candidate);
      if ($distance <= 3 || str_contains($candidate, $needle) || (strlen($candidate) >= 4 && str_contains($needle, $candidate))) {
        $scored[$candidate] = $distance;
      }
    }
    asort($scored);
    return array_slice(array_keys($scored), 0, $limit);
  }

}
