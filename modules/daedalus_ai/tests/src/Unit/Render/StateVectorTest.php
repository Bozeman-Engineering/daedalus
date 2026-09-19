<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Render;

use Drupal\daedalus_ai\Render\StateVector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the PHP mirror of the canvas state vector grammar.
 *
 * The grammar's source of truth is daedalus_ui's vector-state.js; this
 * mirror must normalize identically (width clamping and rounding, the
 * scheme/motion vocabularies, defaults omitted from the boot query) so the
 * URL the render tool builds poses exactly the state the canvas chips
 * would. The one deliberate divergence — loud rejection instead of silent
 * dropping of unknown values — is pinned too, because a silently dropped
 * axis would let the model believe it verified a state it never posed.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
class StateVectorTest extends UnitTestCase {

  /**
   * Width normalizes exactly as the JS grammar does.
   *
   * 'full', NULL, and '' all mean the full canvas and stay out of the
   * query; numbers clamp and round to the JS bounds.
   */
  public function testWidthGrammar(): void {
    foreach ([NULL, '', 'full'] as $full) {
      $vector = StateVector::fromToolInput($full, NULL, NULL);
      $this->assertNull($vector->width);
      $this->assertArrayNotHasKey('canvasWidth', $vector->toQuery());
    }

    $this->assertSame(375, StateVector::fromToolInput('375', NULL, NULL)->width);
    // Clamping mirrors MIN_WIDTH/MAX_WIDTH in vector-state.js.
    $this->assertSame(240, StateVector::fromToolInput('100', NULL, NULL)->width);
    $this->assertSame(10000, StateVector::fromToolInput('20000', NULL, NULL)->width);
    // Rounding mirrors Math.round.
    $this->assertSame(376, StateVector::fromToolInput('375.6', NULL, NULL)->width);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Invalid canvas_width 'narrow'");
    StateVector::fromToolInput('narrow', NULL, NULL);
  }

  /**
   * Scheme and motion: closed vocabularies, 'auto' default, loud errors.
   */
  public function testSchemeAndMotionVocabularies(): void {
    $vector = StateVector::fromToolInput(NULL, 'dark', 'reduce');
    $this->assertSame('dark', $vector->scheme);
    $this->assertSame('reduce', $vector->motion);

    $this->assertSame('auto', StateVector::fromToolInput(NULL, NULL, NULL)->scheme);
    $this->assertSame('auto', StateVector::fromToolInput(NULL, '', '')->motion);

    try {
      StateVector::fromToolInput(NULL, 'darkmode', NULL);
      $this->fail('Unknown scheme must be rejected.');
    }
    catch (\InvalidArgumentException $e) {
      // The corrective must teach the vocabulary.
      $this->assertStringContainsString('auto, light, dark', $e->getMessage());
    }

    try {
      StateVector::fromToolInput(NULL, NULL, 'reduced');
      $this->fail('Unknown motion must be rejected.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('auto, full, reduce', $e->getMessage());
    }
  }

  /**
   * The boot query carries only posed axes, under the exact JS names.
   */
  public function testQueryOmitsDefaults(): void {
    $this->assertSame([], StateVector::fromToolInput(NULL, 'auto', 'auto')->toQuery());
    $this->assertSame(
      ['canvasWidth' => '375', 'canvasScheme' => 'dark', 'canvasMotion' => 'reduce'],
      StateVector::fromToolInput('375', 'dark', 'reduce')->toQuery(),
    );
    $this->assertSame(
      ['canvasScheme' => 'light'],
      StateVector::fromToolInput('full', 'light', 'auto')->toQuery(),
    );
  }

  /**
   * The description names every axis so tool output reads unambiguously.
   */
  public function testDescribe(): void {
    $this->assertSame('width=full, scheme=auto, motion=auto', StateVector::fromToolInput(NULL, NULL, NULL)->describe());
    $this->assertSame('width=375px, scheme=dark, motion=reduce', StateVector::fromToolInput('375', 'dark', 'reduce')->describe());
  }

}
