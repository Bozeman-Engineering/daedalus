<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Markup;

use Drupal\Component\Utility\Html;

/**
 * Audits a markup projection for structural facts worth advising on.
 *
 * The audit runs over the element model's HTML print format (see
 * MarkupProjectorInterface), so it is block-type agnostic by construction:
 * whatever block or field produced a heading or a link, the projection
 * renders the real tag and the real href, and that is what gets checked.
 *
 * Two checks:
 * - Heading ladder in document order: a heading more than one level below
 *   the heading before it skips a level (h4 after h2).
 * - Dead links: an anchor whose href is exactly "#" goes nowhere.
 *
 * Advisories are corrective-shaped teaching lines and NEVER blocking: a
 * save must not fail on structure, because blocking would make half-built
 * states hostile for human editors on the same validation spine. Consumers
 * (the write echo) append these lines to an already-successful result.
 */
final class StructuralAuditor {

  /**
   * Audits one projection fragment and returns advisory lines.
   *
   * @param string $html
   *   A markup projection (one projected target). Fragments are audited
   *   individually because heading order across unrelated fragments is
   *   not document order.
   *
   * @return string[]
   *   Corrective-shaped advisory lines, deduplicated, heading-ladder
   *   findings first. Empty when the fragment is structurally clean.
   */
  public function audit(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $document = Html::load($html);
    $xpath = new \DOMXPath($document);

    $advisories = [];

    // Heading ladder in document order. The union expression returns
    // nodes in document order; only a downward skip (deeper by more than
    // one level) is a break — climbing back up is always legal, and the
    // fragment's first heading sets the ladder rather than breaking it.
    $previous = NULL;
    foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
      $level = (int) substr($heading->nodeName, 1);
      if ($previous !== NULL && $level > $previous + 1) {
        $advisories[] = sprintf(
          'h%d follows h%d; use h%d or promote the heading.',
          $level,
          $previous,
          $previous + 1,
        );
      }
      $previous = $level;
    }

    // Dead links: href="#" navigates nowhere. One line teaches the fix
    // for every occurrence; per-instance repetition adds nothing.
    if ($xpath->query('//a[@href="#"]')->length > 0) {
      $advisories[] = 'uri "#" is a dead link; use <nolink> or <button> for an actionless button, or a real path.';
    }

    return array_values(array_unique($advisories));
  }

}
