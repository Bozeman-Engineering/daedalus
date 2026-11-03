<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\daedalus_ai_memory\Form\SettingsForm;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The settings form refuses a segment budget under the minimum.
 *
 * The minimum exists so a budget under the prompt's own floor cannot close
 * every finished turn; the policy clamps a value set by other means, and
 * this form is the one place a human sets the budget, so it refuses one
 * under the minimum with an error naming both numbers (the budget, and
 * each per-model override in turn) and saves one at or above it. The
 * fixture is the recipe's real site, so the minimum the install config
 * ships (50000) is what the form shows before anyone touches it.
 */
#[RunTestsInSeparateProcesses]
final class MemorySettingsFormTest extends AgentMemoryFunctionalTestBase {

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/ai/agent-memory';

  /**
   * A budget under the minimum is refused; one at or above it is saved.
   */
  public function testBudgetUnderTheMinimumIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->fieldValueEquals('segment_budget_tokens', '80000');
    $assert->fieldValueEquals('segment_budget_minimum_tokens', '50000');

    // The budget under the minimum: refused, both numbers named, config
    // untouched.
    $this->submitForm(['segment_budget_tokens' => '20000'], 'Save configuration');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('The segment budget of 20000 tokens is under the minimum of 50000 tokens.');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertSame(80000, $this->config('daedalus_ai_memory.settings')->get('segment_budget_tokens'));

    // An override under the minimum: refused, naming the model too.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'segment_budget_tokens' => '60000',
      'segment_budget_overrides' => "claude-haiku-5-5: 20000",
    ], 'Save configuration');
    $assert->pageTextContains('The budget of 20000 tokens for claude-haiku-5-5 is under the minimum of 50000 tokens.');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertSame(80000, $this->config('daedalus_ai_memory.settings')->get('segment_budget_tokens'));
    $this->assertSame([], $this->config('daedalus_ai_memory.settings')->get('segment_budget_overrides'));

    // The budget and an override at or above the minimum: saved.
    $this->drupalGet(self::PATH);
    $this->submitForm([
      'segment_budget_tokens' => '60000',
      'segment_budget_overrides' => "claude-haiku-5-5: 50000",
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config('daedalus_ai_memory.settings');
    $this->assertSame(60000, $settings->get('segment_budget_tokens'));
    $this->assertSame(50000, $settings->get('segment_budget_minimum_tokens'));
    $this->assertSame(SettingsForm::parseOverrides('claude-haiku-5-5: 50000'), $settings->get('segment_budget_overrides'));

    // Raising the minimum above the saved budget is refused the same way:
    // the form reads the two values it is given, not the stored ones.
    $this->drupalGet(self::PATH);
    $this->submitForm(['segment_budget_minimum_tokens' => '70000'], 'Save configuration');
    $assert->pageTextContains('The segment budget of 60000 tokens is under the minimum of 70000 tokens.');
    $this->assertSame(50000, $this->config('daedalus_ai_memory.settings')->get('segment_budget_minimum_tokens'));
  }

}
