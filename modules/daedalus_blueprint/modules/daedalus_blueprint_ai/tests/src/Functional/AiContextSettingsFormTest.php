<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Functional;

use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The AI context budget's fields on daedalus_blueprint_ai's settings form.
 *
 * The form is the one place a human sets the budget and the ratio, and
 * they live on the spine's settings object (daedalus_blueprint.settings,
 * MaterialsBudget::KEY_BUDGET and ::KEY_RATIO), not this module's: the
 * guard reads the spine. So the contract is that the fields show the
 * shipped defaults, save to that object as integers the budget then
 * reads, and refuse what the object's schema refuses (a zero, by the
 * Range constraints; the object is fully validatable and the form saves
 * through #config_target, the validated road) without touching the
 * module's own settings beside them. The site is the AI recipe's
 * product; admin by explicit exception, the form being an admin-only
 * subject (`administer site configuration`).
 */
#[Group('daedalus_blueprint_ai')]
#[RunTestsInSeparateProcesses]
final class AiContextSettingsFormTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'daedalus_theme';

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/ai/daedalus-blueprint';

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * The two keys show their defaults, save as integers, and refuse a zero.
   */
  public function testBudgetFields(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $assert = $this->assertSession();
    $hidden = $this->config('daedalus_blueprint_ai.settings')->get('hidden_entity_types');
    $this->assertNotEmpty($hidden, 'The AI recipe hides entity types; the save below must keep them.');

    // The shipped defaults, and the link to the usage display.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('AI context budget');
    $assert->fieldValueEquals(MaterialsBudget::KEY_BUDGET, (string) MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_BUDGET]);
    $assert->fieldValueEquals(MaterialsBudget::KEY_RATIO, (string) MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_RATIO]);
    $assert->linkByHrefExists(MaterialsBudget::DIALOG_PATH);

    // Both keys save to the spine's object as integers the budget reads.
    $this->submitForm([
      MaterialsBudget::KEY_BUDGET => '30000',
      MaterialsBudget::KEY_RATIO => '3',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config(MaterialsBudget::CONFIG_NAME);
    $this->assertSame(30000, $settings->get(MaterialsBudget::KEY_BUDGET));
    $this->assertSame(3, $settings->get(MaterialsBudget::KEY_RATIO));
    $assert->fieldValueEquals(MaterialsBudget::KEY_BUDGET, '30000');
    $assert->fieldValueEquals(MaterialsBudget::KEY_RATIO, '3');
    $reading = $this->container->get(MaterialsBudget::class)->reading();
    $this->assertSame(30000, $reading->budgetTokens);
    $this->assertSame(3, $reading->charactersPerToken);
    // The module's own settings ride the same save as they always did:
    // the submit rewrites the hidden list from the catalog's checkboxes,
    // so a hidden type the catalog never carries (ai_log, an internal
    // type) leaves it, and every catalog type hidden before stays hidden.
    $catalog = array_keys($this->container->get('daedalus_blueprint.bundle_list_builder')->getCatalog());
    $this->assertEqualsCanonicalizing(array_intersect($hidden, $catalog), $this->config('daedalus_blueprint_ai.settings')->get('hidden_entity_types'));

    // A zero is refused by the object's schema and nothing changes.
    $this->submitForm([
      MaterialsBudget::KEY_BUDGET => '0',
      MaterialsBudget::KEY_RATIO => '3',
    ], 'Save configuration');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $assert->pageTextContains('Budget (estimated tokens)');
    $this->assertSame(30000, $this->config(MaterialsBudget::CONFIG_NAME)->get(MaterialsBudget::KEY_BUDGET));

    // A blank is refused too: the fields are required.
    $this->submitForm([
      MaterialsBudget::KEY_BUDGET => '30000',
      MaterialsBudget::KEY_RATIO => '',
    ], 'Save configuration');
    $assert->pageTextContains('Characters per token field is required.');
    $this->assertSame(3, $this->config(MaterialsBudget::CONFIG_NAME)->get(MaterialsBudget::KEY_RATIO));
  }

}
