<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\daedalus_blueprint\Materials\VocabularyProseInterface;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;
use Drupal\daedalus_stylish\StyleGuide;

/**
 * The vocabulary doctrine every model-facing surface states from ONE source.
 *
 * The twin of MaterialsProse for the vocabulary roster: how a roster is
 * taught (by property name, the widest in full and the others as deltas
 * against it), what a roster IS and how a property outside it is judged
 * (the roster sentence), what the site accepts as a whole (the polarity
 * sentence, the design system brief's Law line), the stack every element
 * wears and the tool that shows it (the stack sentence, the brief's one
 * styling pointer) and how a property's value space reads (the
 * value-space line) are stated here and nowhere else, so the cached
 * prompt's STYLES lines and brief (through the dialect catalog) and the
 * schema fragments' stylable entries and bindings cannot drift apart. The
 * roster is taught in EVERY posture; only the sentence saying what
 * happens outside it varies, and that sentence never names a mode or
 * that alternatives exist (the enforcement mode stays opaque to the AI;
 * the brief's Law line is the one place the mode is spelled, and it is
 * the brief's, not this class's). The motion trigger line rides the
 * roster lines wherever a roster grants the trigger property: the
 * mechanics of the in-view pair belong to the layer that grants it.
 *
 * A value object over the site's posture: construct it from the guide
 * (VocabularyProse::of()) or, where no guide is at hand, from the mode.
 */
final class VocabularyProse implements VocabularyProseInterface {

  /**
   * What a roster prints as when it grants nothing.
   */
  public const NOTHING = 'nothing';

  /**
   * The property whose grant makes entrances trigger-bound on a roster.
   *
   * Daedalus Stylish's motion grant carries the Animation Triggers pair
   * (timeline-trigger, animation-trigger); where a roster grants it the
   * roster lines teach the pair, and where none does the line is silent.
   */
  public const TRIGGER_PROPERTY = 'timeline-trigger';

  public function __construct(
    private readonly string $enforcement,
  ) {
    if (!in_array($enforcement, [
      StyleGuide::ENFORCEMENT_OPEN,
      StyleGuide::ENFORCEMENT_GUARDED,
      StyleGuide::ENFORCEMENT_STRICT,
    ], TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an enforcement mode.', $enforcement));
    }
  }

  /**
   * The prose for the site's current posture.
   */
  public static function of(StyleGuide $styleGuide): self {
    return new self($styleGuide->enforcement());
  }

  /**
   * The roster sentence: what a roster is, and what happens outside it.
   *
   * The ONE sentence that varies with the posture on every roster
   * surface. Closed: everything outside the roster is refused. Otherwise
   * the roster is the design system's vocabulary and any recognized
   * property with a valid value is accepted beside it (an unrecognized
   * name is refused with suggestions on every posture). Stated without
   * a closing period so a caller may end it as a heading (the prompt's
   * colon) or a sentence.
   */
  public function rosterSentence(): string {
    if ($this->enforcement === StyleGuide::ENFORCEMENT_STRICT) {
      return 'Each element styles ONLY the properties its kind permits (everything else is refused)';
    }
    return 'Each element kind\'s roster is the properties its design system names; any other recognized CSS property with a valid value is accepted too (an unrecognized name is refused, with suggestions), so prefer the roster';
  }

  /**
   * The polarity sentence: what this site accepts, stated as plain fact.
   *
   * The whole-site sentence the design system brief's Law line carries
   * and the schema's guidance paragraph opens with. It never names a
   * mode or that alternatives exist (the brief's Law line is the one
   * place the mode is spelled). On a closed-world site it also carries the
   * free-value rule (a rostered property with no bindings line takes any
   * valid CSS value), because that surface's miss direction is silent:
   * correctives fix invalid attempts but never reveal a valid capability
   * the AI does not know exists.
   */
  public function polaritySentence(): string {
    if ($this->enforcement === StyleGuide::ENFORCEMENT_STRICT) {
      return 'Styling on this site is exactly what its rosters list: an element may style only the properties its kind\'s roster permits, a property with a bindings (or deviations) line takes only what that line says (a scale binding means that scale\'s named values only, no swatches and no raw values), and a permitted property with no such line takes any valid CSS value. Anything not listed is invalid; the error tells you what the element permits when you miss.';
    }
    if ($this->enforcement === StyleGuide::ENFORCEMENT_GUARDED) {
      return 'Any recognized CSS property with a valid value is accepted (unrecognized properties are rejected, with suggestions); where a bindings or deviations line states a rule for a property, that rule holds — a scale binding means that scale\'s named values only, no swatches and no raw values. Prefer the materials over raw values.';
    }
    return 'Any recognized CSS property with a valid value is accepted (unrecognized properties are rejected, with suggestions); the bindings and deviations lines are the design language\'s preferences. Prefer the materials over raw values.';
  }

  /**
   * {@inheritdoc}
   *
   * The stack the delivery composes (skin defaults, then worn variants,
   * then the element's own styles, topmost setter wins), where styles
   * persist, and the tool that shows any element's stack. The brief's
   * one styling pointer, stated by the layer that owns the tool.
   */
  public function stackSentence(): string {
    return 'Every element wears a stack (skin defaults, then worn variants, then its own styles; the topmost setter wins) and explain_element_styles shows any element\'s stack; styles persist in the host entity\'s daedalus_stylish_styles field.';
  }

  /**
   * {@inheritdoc}
   */
  public function rosterLines(array $rosters): array {
    if ($rosters === []) {
      return [];
    }
    $lines = [$this->rosterSentence() . ':'];
    foreach ($this->phrases($rosters) as $label => $phrase) {
      $lines[] = sprintf('- %s: %s', $label, $phrase);
    }
    foreach ($rosters as $roster) {
      if (in_array(self::TRIGGER_PROPERTY, $roster->granted(), TRUE)) {
        $lines[] = self::motionTriggerLine();
        break;
      }
    }
    return $lines;
  }

  /**
   * The motion trigger line: when a worn entrance plays, and how to move it.
   *
   * A published entrance stop binds its animation to the in-view trigger
   * pair, so the WHEN of an entrance is a Motion property on the wearer
   * (overridden like any variant value, never a condition). The line
   * spells the pair, the later-entry and every-time forms, the at-load
   * unset, the name that keeps the pair bound, and the reduced-motion
   * unset a direct animation-name wear owes (only the layer that sets
   * animation can honor the preference). One line, cached, so an edit
   * turn never needs a skill for it.
   */
  public static function motionTriggerLine(): string {
    return 'Motion triggers: a worn entrance plays when its element scrolls into view, bound by the trigger pair on the wearer (timeline-trigger: --in-view view() entry 20% exit 100%; animation-trigger: --in-view play-once), Motion properties you override like any variant value, never a condition: a later entry percentage plays later (keep exit 100%), animation-trigger: --in-view replay reset plays every time it scrolls in, timeline-trigger: none plays at load, and the --in-view name stays on either write so the pair holds; a direct animation-name wear takes the same pair to wait to be seen and carries its own reduced-motion unset (@media (prefers-reduced-motion: reduce) { #id { animation: none } }), the only layer that can honor the preference.';
  }

  /**
   * One phrase per roster: the widest in full, the others against it.
   *
   * The misses models make are "CSS they know" inside a granted group
   * (the padding shorthand on an element whose grant names only the
   * longhands), so a roster is taught by PROPERTY NAME, never by group
   * name. To keep the cost low the widest roster prints in full and the
   * others print as deltas against it ("same as {base}", "the {base}
   * roster, minus a, b; plus c"); a roster the delta form cannot shorten
   * prints in full too. Rosters print widest first (a stable sort: a tie
   * keeps the given order), and the base is the widest roster of an
   * element KIND among them (kind:block, kind:container: the universal
   * declarations every refinement, part and children pseudo-part leans
   * on), or the widest roster given when no kind root is among them; the
   * base leads whatever its width.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface> $rosters
   *   Label => roster.
   *
   * @return array<string, string>
   *   Label => phrase, the base first.
   */
  public function phrases(array $rosters): array {
    if ($rosters === []) {
      return [];
    }
    foreach ($rosters as $label => $roster) {
      if (!$roster instanceof VocabularyRosterInterface) {
        throw new \InvalidArgumentException(sprintf('The roster labeled "%s" must be a vocabulary roster (VocabularyRosterInterface).', $label));
      }
    }
    $granted = array_map(static fn (VocabularyRosterInterface $roster): array => $roster->granted(), $rosters);
    uasort($granted, static fn (array $a, array $b): int => count($b) <=> count($a));
    $base_label = array_key_first($granted);
    foreach (array_keys($granted) as $label) {
      $address = $rosters[$label]->address();
      if ($address->isRoot() && $address->isKind()) {
        $base_label = $label;
        break;
      }
    }
    $base = $granted[$base_label];
    $phrases = [$base_label => self::names($base)];
    foreach ($granted as $label => $names) {
      if ($label === $base_label) {
        continue;
      }
      $removed = array_values(array_diff($base, $names));
      $added = array_values(array_diff($names, $base));
      if ($removed === [] && $added === []) {
        $phrases[$label] = sprintf('same as %s', $base_label);
        continue;
      }
      $delta = [];
      if ($removed !== []) {
        $delta[] = 'minus ' . implode(', ', $removed);
      }
      if ($added !== []) {
        $delta[] = 'plus ' . implode(', ', $added);
      }
      // The delta form only pays when it is actually shorter to read.
      $phrases[$label] = $names !== [] && count($removed) + count($added) < count($names)
        ? sprintf('the %s roster, %s', $base_label, implode('; ', $delta))
        : self::names($names);
    }
    return $phrases;
  }

  /**
   * A property's value space as a designer-facing line.
   *
   * "text values" and "at most 4rem", not a constraint-grammar dump; the
   * polarity sentence says whether the line binds or advises, the line
   * itself never does. The one renderer the bindings and deviations
   * lines share.
   *
   * @param array $space
   *   The effective constraint block ({scale, stops, allowed, min, max,
   *   units} as authored; VocabularyRoster::valueSpace()).
   */
  public static function valueSpaceLine(array $space): string {
    $parts = [];
    if (isset($space['scale'])) {
      $phrase = $space['scale'] . ' values';
      if (!empty($space['stops']) && is_array($space['stops'])) {
        $phrase .= ' ' . implode(', ', $space['stops']) . ' only';
      }
      $parts[] = $phrase;
    }
    if (!empty($space['allowed']) && is_array($space['allowed'])) {
      $parts[] = (isset($space['scale']) ? 'also allowed: ' : 'one of: ') . implode(', ', $space['allowed']);
    }
    $range = [];
    if (isset($space['min'])) {
      $range[] = 'at least ' . $space['min'];
    }
    if (isset($space['max'])) {
      $range[] = 'at most ' . $space['max'];
    }
    if ($range) {
      $parts[] = implode(', ', $range);
    }
    if (!empty($space['units']) && is_array($space['units'])) {
      $parts[] = 'units: ' . implode(', ', $space['units']);
    }
    return implode('; ', $parts);
  }

  /**
   * A value space narrowed below its site-wide binding, as a phrase.
   *
   * A node narrowing a scale binding to a stops subset reads as the
   * curation it is ("limited to brand-1, brand-2"); anything else reads
   * as a full value-space line.
   */
  public static function deviationPhrase(array $space, ?array $binding): string {
    if ($binding !== NULL
      && isset($space['scale'], $binding['scale']) && $space['scale'] === $binding['scale']
      && !empty($space['stops']) && is_array($space['stops'])) {
      return 'limited to ' . implode(', ', $space['stops']);
    }
    return self::valueSpaceLine($space);
  }

  /**
   * A roster's names as one phrase.
   */
  private static function names(array $names): string {
    return $names === [] ? self::NOTHING : implode(', ', $names);
  }

}
