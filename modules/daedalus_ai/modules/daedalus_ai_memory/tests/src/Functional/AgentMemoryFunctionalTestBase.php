<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * The fixture the memory runner's Functional tests share.
 *
 * Proving anything about the runner needs the real decorated runner, a
 * real configured agent and a real provider wired together, which is what
 * the per-module harness's require-dev on drupal/daedalus_recipe provides:
 * the fixture applies the recipe to get the real daedalus agent and the
 * daedalus_ai_memory configuration a site actually runs, then enables
 * daedalus_ai's test module for its scripted stub provider
 * (daedalus_ai_test_stub), so a turn runs end to end with no API key and
 * no network call. Site install plus recipe apply is the expensive part;
 * each test pays it once and keeps to one test method.
 */
abstract class AgentMemoryFunctionalTestBase extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The recipe is applied on top of a standard site (the page-builder base it
   * carries expects standard's content model), so 'standard' is the faithful
   * fixture, the same starting point a real site gives the recipe.
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   *
   * Inherited from the Daedalus base recipe: a few exported config values are
   * stored with a type that does not match their schema (harmless in production
   * where the checker is off, but it aborts recipe apply under the checker that
   * tests enable). These are behavior tests, not a config schema audit, so the
   * checker is disabled here, matching the other Daedalus recipe tests.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The private tempstore bin AgentMemoryRunner persists records into.
   */
  protected const THREAD_BIN = 'daedalus_ai_memory_threads';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // drupal/daedalus_recipe is a require-dev dependency, so Composer installs
    // it as a sibling of the Drupal root. Applying it gives the real daedalus
    // agent/assistant and the daedalus_ai_memory.settings the recipe ships.
    $recipe = dirname(\Drupal::root()) . '/recipes/daedalus_recipe/ai';
    $this->assertDirectoryExists(
      $recipe,
      'The daedalus_ai recipe must be installed (require-dev) to run this test.',
    );
    $this->applyRecipe($recipe);

    // The stub provider lives in daedalus_ai's test module, which the recipe
    // does not enable. It depends on daedalus_ai, which the recipe just
    // installed, so enable it now.
    \Drupal::service('module_installer')->install(['daedalus_ai_test']);
  }

  /**
   * A scripted reply calling the eb_skill tool for a skill.
   *
   * The call carries no input definition, the way the renderer rebuilds a
   * replayed call: the wrapper resolves the tool by function name and
   * populates its context from the arguments, then really executes it.
   *
   * @param string $id
   *   The tool call id.
   * @param string $skill
   *   The skill id to invoke.
   */
  protected static function skillCall(string $id, string $skill): ChatMessage {
    $call = new ToolsFunctionOutput(NULL, $id, ['skill' => $skill]);
    $call->setName('eb_skill');
    $message = new ChatMessage('assistant', 'Let me load that skill first.');
    $message->setTools([$call]);
    return $message;
  }

}
