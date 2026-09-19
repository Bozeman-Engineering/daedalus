<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the two-level AND logic of the image-generation gate.
 *
 * ImageGenerationGate is the single source of truth both the field handler and
 * the tool's settings panel consult to decide whether the AI may spend real
 * Imagen calls. Its contract is an AND of two independent levels:
 *   - a site-wide master switch (daedalus_ai.settings.image_generation_enabled,
 *     default TRUE), and
 *   - a per-user preference stored on the user via daedalus_ui
 *     EditorSettings (key daedalus_ai.generate_images, default ON when unset).
 *
 * Three regressions would be damaging and are pinned here:
 *   - Defaulting the per-user preference to OFF (rather than ON) would silently
 *     disable generation for every editor who never touched the toggle.
 *   - Ignoring the master switch (returning isEnabled() from the per-user level
 *     alone) would let an editor work around a deliberate site-wide disable.
 *   - Conflating the two levels in isSiteEnabled()/isUserEnabled() would feed
 *     the field handler the wrong remediation level for its disabled-warning.
 *
 * The per-user level is read through the REAL EditorSettings service backed by
 * a real user entity (not a mock), because the gate's value depends on the
 * daedalus_ui_settings map field plumbing; a unit mock would not prove that
 * integration. This shares DaedalusAiKernelTestBase with the handler test so the
 * expensive install happens once for both.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ImageGenerationGateTest extends DaedalusAiKernelTestBase {

  /**
   * Walks the full AND truth table across both gate levels.
   *
   * One method covers all four combinations because they all read the same
   * installed fixture; reinstalling per combination would quadruple the cost
   * for no extra coverage. Each transition flips exactly one level through its
   * real backing store and re-asserts isSiteEnabled/isUserEnabled/isEnabled.
   */
  public function testTwoLevelAndTruthTable(): void {
    // Defaults: site switch ships TRUE, per-user preference unset => both on,
    // so generation is enabled. This pins the "unset defaults ON" behavior.
    $this->assertTrue($this->gate->isSiteEnabled());
    $this->assertTrue($this->gate->isUserEnabled());
    $this->assertTrue($this->gate->isEnabled());

    // Site OFF, user (still) ON => disabled. Proves the master switch alone can
    // force the AND to FALSE.
    $this->setSiteGate(FALSE);
    $this->assertFalse($this->gate->isSiteEnabled());
    $this->assertTrue($this->gate->isUserEnabled());
    $this->assertFalse($this->gate->isEnabled());

    // Restore the site switch; both on again => enabled.
    $this->setSiteGate(TRUE);
    $this->assertTrue($this->gate->isEnabled());

    // Site ON, user OFF => disabled. Proves the per-user preference alone can
    // force the AND to FALSE, and that the explicit '0' is honored.
    $this->setUserGate('0');
    $this->assertTrue($this->gate->isSiteEnabled());
    $this->assertFalse($this->gate->isUserEnabled());
    $this->assertFalse($this->gate->isEnabled());

    // Site OFF and user OFF => disabled (both-off corner of the table).
    $this->setSiteGate(FALSE);
    $this->assertFalse($this->gate->isEnabled());

    // An explicit '1' user preference restores the per-user level; with the
    // site still off the AND remains FALSE, then turning the site back on
    // yields the only TRUE corner (both on).
    $this->setUserGate('1');
    $this->assertTrue($this->gate->isUserEnabled());
    $this->assertFalse($this->gate->isEnabled());
    $this->setSiteGate(TRUE);
    $this->assertTrue($this->gate->isEnabled());
  }

}
