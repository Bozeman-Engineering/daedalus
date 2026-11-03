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
   * The bindings as designer-facing lines, one per property.
   *
   * A binding is a property's site-wide value space ("font-size: text
   * values", "padding: space values; also allowed: 0"), never a
   * constraint-grammar dump; the polarity sentence says whether the lines
   * bind or advise, the lines themselves never do. The one renderer the
   * JSON door's style_guide and the design system brief share.
   *
   * @param array<string, array> $bindings
   *   Property => value space, as the catalog carries them
   *   (DialectCatalog::bindings()).
   *
   * @return array<string, string>
   *   Property => line, in the given order; [] when none.
   */
  public function bindingLines(array $bindings): array;

  /**
   * The deviations as lines, one per place that narrows below a binding.
   *
   * Each place's value spaces as ONE line ("background-color limited to
   * brand-1, brand-2; font-size at most 2rem"): a scale narrowed to a
   * few stops reads as the curation it is, anything else as a value-space
   * line. Keyed as the deviations are keyed; the caller labels the
   * places in its own words (the brief's dialect, the style map's key
   * grammar) before or after.
   *
   * @param array<string, array<string, array>> $deviations
   *   Key => [property => value space], the places that deviate
   *   (DialectCatalog::deviations()).
   * @param array<string, array> $bindings
   *   The bindings the deviations narrow (DialectCatalog::bindings()).
   *
   * @return array<string, string>
   *   Key => line, in the given order; [] when none.
   */
  public function deviationLines(array $deviations, array $bindings): array;

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
   * The condition sentence: what a condition may be on this site.
   *
   * The posture's one sentence on the WHEN side, the twin of the
   * polarity sentence for conditions: the published conditions are
   * references (names whose CSS the site owns), and whether a LITERAL
   * the browser knows (a feature query, a supports test, a pseudo-class
   * state) is admitted beside them is the enforcement mode's verdict,
   * stated as plain fact. The JSON door's description and the prompt's
   * conditions line carry it, so neither restates the law in its own
   * words.
   */
  public function conditionSentence(): string;

  /**
   * The stack sentence: how an element's look composes, and where to read it.
   *
   * The styling layer owns the stack (skin defaults, worn variants, the
   * element's own styles) and the read tool that shows it, so it states
   * the sentence the brief carries; the spine names no styling tool.
   */
  public function stackSentence(): string;

}
