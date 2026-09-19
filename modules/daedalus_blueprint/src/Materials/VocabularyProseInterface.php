<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * Renders vocabulary rosters as the model-facing prose, from ONE source.
 *
 * The twin of MaterialsProse for the vocabulary spine: the styling layer
 * that builds the rosters (VocabularyRosterInterface) also owns how they
 * are taught, so the cached prompt's roster lines and the schema's
 * stylable entries cannot drift apart or restate each other.
 * The spine's dialect catalog carries one of these beside the rosters it
 * carries, and the prompt renderer (daedalus_blueprint_ai) calls it rather
 * than spelling the roster itself; the spine stays styling-agnostic and
 * never decides how a property outside a roster is judged.
 */
interface VocabularyProseInterface {

  /**
   * The roster lines the cached prompt teaches.
   *
   * The first line states what a roster IS and how a property outside it
   * is judged (the one sentence that varies with the site's posture);
   * the rest are one per label, the widest roster in full and the others
   * spelled against it where that reads shorter.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface> $rosters
   *   Label => roster, in teaching order.
   *
   * @return string[]
   *   The lines; [] when no roster is given.
   */
  public function rosterLines(array $rosters): array;

  /**
   * The polarity sentence: what this site accepts, stated as plain fact.
   *
   * The whole-site sentence the design system brief's Law line carries
   * and the schema's guidance paragraph opens with. It never names a
   * mode or that alternatives exist; the brief's Law line is the one
   * place the mode is spelled.
   */
  public function polaritySentence(): string;

  /**
   * The stack sentence: how an element's look composes, and where to read it.
   *
   * The styling layer owns the stack (skin defaults, worn variants, the
   * element's own styles) and the read tool that shows it, so it states
   * the sentence the brief carries; the spine names no styling tool.
   */
  public function stackSentence(): string;

}
