<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one prose generator over the vocabulary roster.
 *
 * Pins the roster phrases (the widest in full, the others as deltas
 * where that reads shorter), the roster lines the cached prompt teaches,
 * the two posture sentences (only the roster sentence and the polarity
 * sentence vary, and neither names a mode), and the value-space lines
 * the bindings and deviations share.
 */
#[Group('daedalus_stylish')]
class VocabularyProseTest extends UnitTestCase {

  /**
   * A hand-built roster over one grants map.
   */
  protected static function roster(string $address, array $names): VocabularyRoster {
    return new VocabularyRoster(Address::fromString($address), 'unit', ['g' => $names]);
  }

  /**
   * The widest roster leads in full; the others print against it.
   */
  public function testPhrases(): void {
    $prose = new VocabularyProse(StyleGuide::ENFORCEMENT_STRICT);
    $block = [
      'padding',
      'padding-top',
      'padding-bottom',
      'padding-left',
      'padding-right',
      'padding-inline',
      'margin',
      'gap',
      'color',
      'font-size',
    ];
    $container = ['padding-top', 'padding-bottom', 'padding-inline', 'margin', 'gap', 'color', 'font-size', 'display'];
    $phrases = $prose->phrases([
      'container' => self::roster('kind:container', $container),
      'block' => self::roster('kind:block', $block),
      'field' => self::roster('kind:field', $block),
      'part' => self::roster('kind:block::.x', ['color']),
      'bare' => self::roster('kind:block::>', []),
    ]);
    $this->assertSame([
      'block' => implode(', ', $block),
      // Widest first; a stable sort: field ties block and keeps its
      // given order behind it.
      'field' => 'same as block',
      'container' => 'the block roster, minus padding, padding-left, padding-right; plus display',
      // The delta form only pays when it is shorter than the roster.
      'part' => 'color',
      'bare' => VocabularyProse::NOTHING,
    ], $phrases);
    $this->assertSame([], $prose->phrases([]));

    // The base is the widest KIND root even when a refinement or a part
    // is wider: refinements lean on kinds, never the other way round.
    $phrases = $prose->phrases([
      'kind:block' => self::roster('kind:block', ['padding', 'margin']),
      'block_type:card' => self::roster('block_type:card', ['padding', 'margin', 'color', 'font-size']),
      'block_type:card::.x' => self::roster('block_type:card::.x', ['padding', 'margin', 'color']),
    ]);
    $this->assertSame([
      'kind:block' => 'padding, margin',
      'block_type:card' => 'the kind:block roster, plus color, font-size',
      'block_type:card::.x' => 'the kind:block roster, plus color',
    ], $phrases);
  }

  /**
   * The roster lines: the roster sentence as a heading, then one per label.
   */
  public function testRosterLines(): void {
    $rosters = [
      'block' => self::roster('kind:block', ['padding', 'color']),
      'container' => self::roster('kind:container', ['padding']),
    ];
    $this->assertSame([
      'Each element styles ONLY the properties its kind permits (everything else is refused):',
      '- block: padding, color',
      '- container: padding',
    ], (new VocabularyProse(StyleGuide::ENFORCEMENT_STRICT))->rosterLines($rosters));
    foreach ([StyleGuide::ENFORCEMENT_GUARDED, StyleGuide::ENFORCEMENT_OPEN] as $mode) {
      $lines = (new VocabularyProse($mode))->rosterLines($rosters);
      $this->assertSame('Each element kind\'s roster is the properties its design system names; any other recognized CSS property with a valid value is accepted too (an unrecognized name is refused, with suggestions), so prefer the roster:', $lines[0]);
      $this->assertSame(['- block: padding, color', '- container: padding'], array_slice($lines, 1));
    }
    $this->assertSame([], (new VocabularyProse(StyleGuide::ENFORCEMENT_OPEN))->rosterLines([]));

    // The motion trigger line rides the roster lines only where a roster
    // grants the trigger property: the pair's mechanics belong to the
    // layer that grants it, and a site without the grant hears nothing.
    $this->assertNotContains(VocabularyProse::motionTriggerLine(), (new VocabularyProse(StyleGuide::ENFORCEMENT_STRICT))->rosterLines($rosters));
    $motion = $rosters + ['field' => self::roster('kind:field', ['color', 'timeline-trigger', 'animation-trigger'])];
    $lines = (new VocabularyProse(StyleGuide::ENFORCEMENT_STRICT))->rosterLines($motion);
    $this->assertSame(VocabularyProse::motionTriggerLine(), end($lines), 'The trigger line closes the roster lines.');
    $this->assertCount(5, $lines);
    $line = VocabularyProse::motionTriggerLine();
    $this->assertStringContainsString('timeline-trigger: --in-view view() entry 20% exit 100%; animation-trigger: --in-view play-once', $line);
    $this->assertStringContainsString('never a condition', $line);
    $this->assertStringContainsString('animation-trigger: --in-view replay reset', $line);
    $this->assertStringContainsString('timeline-trigger: none plays at load', $line);
    $this->assertStringContainsString('@media (prefers-reduced-motion: reduce) { #id { animation: none } }', $line);
  }

  /**
   * The posture sentences vary by mode and never name one.
   */
  public function testPostureSentences(): void {
    $strict = new VocabularyProse(StyleGuide::ENFORCEMENT_STRICT);
    $guarded = new VocabularyProse(StyleGuide::ENFORCEMENT_GUARDED);
    $open = new VocabularyProse(StyleGuide::ENFORCEMENT_OPEN);
    $this->assertStringContainsString('exactly what its rosters list', $strict->polaritySentence());
    $this->assertStringContainsString('Anything not listed is invalid', $strict->polaritySentence());
    $this->assertStringContainsString('that rule holds', $guarded->polaritySentence());
    $this->assertStringContainsString("the design language's preferences", $open->polaritySentence());
    $this->assertSame($guarded->rosterSentence(), $open->rosterSentence(), 'Only the closed world refuses outside the roster.');
    $this->assertNotSame($strict->rosterSentence(), $guarded->rosterSentence());
    foreach ([$strict, $guarded, $open] as $prose) {
      $this->assertDoesNotMatchRegularExpression('/\b(strict|guarded|enforcement)\b/i', $prose->rosterSentence() . ' ' . $prose->polaritySentence());
    }
    $this->expectException(\InvalidArgumentException::class);
    new VocabularyProse('loose');
  }

  /**
   * Value spaces read as designer-facing lines; narrowing as curation.
   */
  public function testValueSpaceLines(): void {
    $this->assertSame('text values sm, md only; also allowed: inherit; at least 1rem, at most 4rem; units: rem, px', VocabularyProse::valueSpaceLine([
      'scale' => 'text',
      'stops' => ['sm', 'md'],
      'allowed' => ['inherit'],
      'min' => '1rem',
      'max' => '4rem',
      'units' => ['rem', 'px'],
    ]));
    $this->assertSame('one of: Inter, Georgia', VocabularyProse::valueSpaceLine(['allowed' => ['Inter', 'Georgia']]));
    $this->assertSame('', VocabularyProse::valueSpaceLine([]));
    $this->assertSame('limited to brand-1, brand-2', VocabularyProse::deviationPhrase([
      'scale' => 'color',
      'stops' => ['brand-1', 'brand-2'],
    ], ['scale' => 'color']));
    $this->assertSame('at most 2rem', VocabularyProse::deviationPhrase(['max' => '2rem'], ['max' => '4rem']));
    $this->assertSame('space values a only', VocabularyProse::deviationPhrase(['scale' => 'space', 'stops' => ['a']], NULL));
  }

}
