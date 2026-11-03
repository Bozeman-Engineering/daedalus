<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\SkillType;

use Drupal\daedalus_blueprint\SkillType\DefaultSkillType;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the string composition of the default skill type.
 *
 * A skill type turns a skill definition into the instruction text the LLM
 * receives from the eb_skill tool. The default type is a pure string builder
 * with no container, so it is unit-tested here.
 *
 * DefaultSkillType is the verbatim passthrough used by skills that need no
 * dynamic composition (the design skill among them); we pin that it returns
 * the instructions unchanged and an empty string (never NULL) when the key
 * is missing, since the eb_skill tool assigns the return straight into a
 * string output.
 */
#[CoversClass(DefaultSkillType::class)]
#[Group('daedalus_blueprint')]
class SkillTypeComposeTest extends UnitTestCase {

  /**
   * Tests the default skill type returns instructions verbatim.
   */
  public function testDefaultComposeReturnsInstructionsVerbatim(): void {
    $skillType = new DefaultSkillType();

    $instructions = "Do the thing.\n\nThen do the other thing.";
    $this->assertSame(
      $instructions,
      $skillType->compose(['instructions' => $instructions], []),
    );
  }

  /**
   * Tests the default skill type returns '' (not NULL) when no instructions.
   *
   * The eb_skill tool assigns compose() straight into a string property, so a
   * NULL here would be a type error at the boundary; the contract is an empty
   * string for a definition with no instructions.
   */
  public function testDefaultComposeReturnsEmptyStringWhenMissing(): void {
    $skillType = new DefaultSkillType();

    $this->assertSame('', $skillType->compose([], []));
  }

}
