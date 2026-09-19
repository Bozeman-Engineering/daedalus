<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves agent state survives a finished turn and accumulates across turns.
 *
 * The module's whole reason
 * to exist is invisible in isolation: the stock ai_assistant_api AgentRunner
 * DELETES an agent's serialized state when a turn finishes, so every new user
 * message starts the LLM over with no memory. AgentMemoryRunner decorates that
 * runner and persists the state instead. Proving that requires the real runner,
 * a real configured agent, and a real provider all wired together, which is
 * exactly what the per-module harness's require-dev on drupal/daedalus_recipe
 * provides: the test applies the recipe to get the real daedalus agent and
 * the daedalus_ai_memory configuration a site actually runs.
 *
 * The one synthetic piece is the LLM: a real chat turn needs an API key and a
 * paid network call, neither of which belongs in CI. So the test swaps in the
 * daedalus_blueprint_ai_dev_tools 'prompt_capture' provider, a fake AiProvider
 * that returns a tool-free response, which makes the agent loop finish
 * immediately with no network call. The behavior under test (does the finished
 * turn's state persist, and does a second turn build on it?) is unaffected by
 * what the model would have said.
 *
 * It drives AgentMemoryRunner::runAsAgent() directly rather than through the
 * full assistant runner: that method is the module's contract (chat history in,
 * persisted thread state out, keyed by job id), and calling it directly gives
 * deterministic control over the three turns. The
 * Fresh/Stale/CrossTurn/SameTurn decision itself is already covered by
 * AgentStateTest (Unit); this test covers the integration those unit cases
 * cannot: that a real finished turn leaves a real record in tempstore, a second
 * turn updates the same record, and a third turn with no new messages is
 * detected as Stale and DISCARDS the prior thread.
 *
 * The third turn pins the cleanup half of the lifecycle. The module's stated
 * contract is "never delete on finish, rely on stale detection to clean up":
 * the decorator deliberately persists state even when a turn finishes (turns
 * one and two prove that), and the ONLY thing that removes a finished thread
 * under normal operation is a later run that finds nothing new to do. Turn
 * three reproduces exactly that: the same job id, the same three-message
 * history turn two already processed, so the session message count has not
 * grown. AgentState::detect() then resolves to Stale (finished AND no new
 * messages), and runPersistent() deletes the persisted record before resolving
 * to Fresh.
 *
 * Crucially the discard is NOT observable as a null record afterward. Stale
 * resolves to Fresh WITHIN THE SAME runPersistent() call, so after deleting the
 * stale thread the runner immediately re-initializes a fresh agent and persists
 * a brand-new thread under the same job id; $store->get() returns that new
 * record, not null. What makes the discard observable is the CONTENT of the
 * rebuilt thread: initializeFresh() maps the whole replayed history verbatim,
 * so the client-supplied assistant line "Got it." now appears in the persisted
 * history, whereas turn two's cross-turn restore deliberately excluded it. So
 * "Got it." absent after turn two but present after turn three is the
 * same-fixture before/after that distinguishes the Stale-then-Fresh rebuild
 * from the cross-turn restore, which is the load-bearing assertion of turn
 * three. The test does not assert turn three's ChatOutput (just another
 * tool-free reply) nor anything requiring the prompt_capture sink (in-process
 * only, and it proves prompt assembly, not persistence).
 *
 * This is a plain Functional (not JavaScript) test: cross-turn persistence is a
 * runner-plus-tempstore behavior with no UI of its own. The only thing a
 * WebDriver run of the assembled DeepChat UI could observe is that a reply
 * bubble renders, which proves the chatbot works, not that state persisted or
 * was cleaned up; the persistence/cleanup records and the prompt_capture sink
 * are both readable only IN-PROCESS, not across an HTTP/WebDriver boundary, and
 * prompt_capture cannot emit tool calls to exercise a multi-tool loop. A
 * WebDriverTestBase would therefore assert strictly less than this test does,
 * so the honest assembled-stack proof stays at Functional level. One test
 * method keeps the expensive site-install-plus-recipe-apply fixture paid once.
 *
 * @group daedalus_ai_memory
 */
#[RunTestsInSeparateProcesses]
class AgentMemoryCrossTurnTest extends BrowserTestBase {

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
   * tests enable). This is a behavior test, not a config schema audit, so the
   * checker is disabled here, matching the other Daedalus recipe tests.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * The private tempstore bin AgentMemoryRunner persists threads into.
   */
  private const THREAD_BIN = 'daedalus_ai_memory_threads';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // drupal/daedalus_recipe is a require-dev dependency, so Composer installs it
    // as a sibling of the Drupal root. Applying it gives the real daedalus
    // agent/assistant and the daedalus_ai_memory.settings the recipe ships.
    $recipe = dirname(\Drupal::root()) . '/recipes/daedalus_recipe/ai';
    $this->assertDirectoryExists(
      $recipe,
      'The daedalus_ai recipe must be installed (require-dev) to run this test.',
    );
    $this->applyRecipe($recipe);

    // The fake provider lives in a dev-tools submodule the recipe does not
    // enable. It depends on daedalus_blueprint_ai, which the recipe just
    // installed, so enable it now.
    \Drupal::service('module_installer')->install(['daedalus_blueprint_ai_dev_tools']);
  }

  /**
   * A finished turn persists, the next builds on it, a stale one cleans up.
   */
  public function testAgentStatePersistsAcrossTurns(): void {
    // PrivateTempStore keys by the current user when authenticated, so run as a
    // real account to get a stable, session-independent thread owner that both
    // the runner's writes and this test's reads agree on.
    $this->setCurrentUser($this->drupalCreateUser([], 'memory-tester', TRUE));

    // Precondition: the recipe opted the daedalus agent into memory. Without
    // this, runAsAgent() would delegate straight to the stock runner and the
    // rest of the test would be meaningless, so assert it explicitly.
    $enabled = \Drupal::config('daedalus_ai_memory.settings')->get('enabled_agents') ?? [];
    $this->assertContains(
      'daedalus',
      $enabled,
      'The recipe enabled cross-turn memory for the daedalus agent.',
    );

    // The decorator sits in front of the stock runner; that decoration is what
    // turns "delete on finish" into "persist across turns".
    $runner = \Drupal::service('ai_assistant_api.agent_runner');
    $this->assertInstanceOf(
      AgentMemoryRunner::class,
      $runner,
      'daedalus_ai_memory decorates the assistant agent runner.',
    );

    $store = \Drupal::service('tempstore.private')->get(self::THREAD_BIN);
    $job_id = 'cross-turn-test';

    // prompt_capture finishes the agent loop with a tool-free response and no
    // network call, so a real turn runs end to end without an API key.
    $defaults = ['provider_id' => 'prompt_capture', 'model_id' => 'capture'];

    $this->assertNull($store->get($job_id), 'No thread state exists before the first turn.');

    // Turn one (Fresh): a single user message.
    $runner->runAsAgent(
      'daedalus',
      [['role' => 'user', 'message' => 'Remember the number 42.']],
      $defaults,
      $job_id,
      FALSE,
      [],
    );

    // The headline: the stock runner deletes state when the agent finishes; the
    // decorator persisted it instead. The record is present, carries the
    // serialized agent, and records how many session messages it has processed.
    $record_one = $store->get($job_id);
    $this->assertNotNull(
      $record_one,
      'Agent state survived the finished turn (the stock runner would have deleted it).',
    );
    $this->assertArrayHasKey('agent_data', $record_one, 'The persisted thread carries the serialized agent.');
    $this->assertSame(1, $record_one['processed_message_count'], 'Turn one processed the single session message.');

    // Turn two: the same conversation, grown by the assistant reply and a
    // follow-up question, sent under the same job id.
    $runner->runAsAgent(
      'daedalus',
      [
        ['role' => 'user', 'message' => 'Remember the number 42.'],
        ['role' => 'assistant', 'message' => 'Got it.'],
        ['role' => 'user', 'message' => 'What number did I ask you to remember?'],
      ],
      $defaults,
      $job_id,
      FALSE,
      [],
    );

    // Continuity: the same thread was updated in place, and turn two genuinely
    // RESTORED turn one's state rather than starting over.
    $record_two = $store->get($job_id);
    $this->assertNotNull($record_two, 'The thread still exists after the second turn.');
    $this->assertSame(3, $record_two['processed_message_count'], 'Turn two recorded the grown three-message session.');

    // The proof it was a cross-turn restore and not a fresh rebuild: the
    // persisted history does NOT contain "Got it.". The client replayed that
    // assistant line in turn two's chat history, but restoreForCrossTurn()
    // appends only the new USER message and carries the agent's own prior
    // assistant turn forward, so a client-supplied assistant message never
    // enters the thread. A fresh run (no state restored) would have mapped the
    // whole replayed history verbatim, "Got it." included. The message count
    // above cannot make this distinction on its own: trimAndPersist() sets it
    // to the session size on every path, so it reads 3 whether turn two
    // restored or rebuilt. The absence of the replayed assistant line is what
    // pins the cross-turn path.
    $history_two = array_column($record_two['agent_data']['chat_history'], 'text');
    $this->assertNotContains(
      'Got it.',
      $history_two,
      'Turn two restored the persisted thread (cross-turn) rather than rebuilding from the replayed history: the client-supplied assistant message was not carried in.',
    );

    // Precondition for the stale path below: turn two must have FINISHED, since
    // AgentState::detect() only returns Stale for a finished thread. The
    // tool-free prompt_capture reply ends the agent loop, so the record turn
    // two wrote is flagged finished. Asserting it makes the next step's intent
    // self evident: if this ever regressed to "not finished", turn three would
    // be a SameTurn poll (state restored as-is) instead of a Stale discard, and
    // the history assertion below would fail for a reason this line names up
    // front.
    $this->assertTrue(
      $record_two['finished'],
      'Turn two finished (the tool-free reply ended the loop), which is what makes turn three stale.',
    );

    // Turn three (Stale): the SAME job id with the SAME three-message history
    // turn two already processed. Nothing new arrived, so session count (3)
    // does not exceed processed_message_count (3). For a finished thread that
    // means Stale, and runPersistent() DISCARDS the persisted record (deletes
    // the bin key, sets $persisted = NULL) before resolving the state to Fresh
    // and re-running the agent from scratch. This is the cleanup half of the
    // module's "never delete on finish, rely on stale detection to clean up"
    // contract: turns one and two proved the decorator refuses to delete a
    // finished thread, and this proves the one path that does discard it.
    $runner->runAsAgent(
      'daedalus',
      [
        ['role' => 'user', 'message' => 'Remember the number 42.'],
        ['role' => 'assistant', 'message' => 'Got it.'],
        ['role' => 'user', 'message' => 'What number did I ask you to remember?'],
      ],
      $defaults,
      $job_id,
      FALSE,
      [],
    );

    // The cleanup proof. The discard is observable not as a null record but as
    // a REBUILT one: because Stale resolves to Fresh within the same
    // runPersistent call, the prior thread is deleted and then a brand-new
    // thread is persisted under the same job id from the replayed history, so
    // $store->get() is not null afterward. What pins the discard is that the
    // rebuilt history NOW CONTAINS "Got it.", the exact client-supplied
    // assistant line that turn two's cross-turn restore deliberately excluded.
    // initializeFresh() maps the replayed history verbatim (assistant lines
    // included), whereas restoreForCrossTurn() appends only new user messages
    // onto the agent's own carried-forward history. So "Got it." present here,
    // but absent from $history_two above, is the same-fixture before/after that
    // distinguishes a Fresh rebuild (stale was discarded) from a cross-turn
    // restore (state was kept). If turn three had instead been a SameTurn poll
    // or a CrossTurn continuation, the discard would not have happened and this
    // assistant line would still be missing. The processed count is 3 again on
    // either path, so it cannot make this distinction; the presence of "Got
    // it." can.
    $record_three = $store->get($job_id);
    $this->assertNotNull(
      $record_three,
      'Stale resolves to Fresh in the same call, so a brand-new thread is persisted under the job id.',
    );
    $history_three = array_column($record_three['agent_data']['chat_history'], 'text');
    $this->assertContains(
      'Got it.',
      $history_three,
      'Turn three discarded the stale thread and rebuilt fresh: the replayed assistant line "Got it." (excluded by turn two\'s cross-turn restore) is now mapped verbatim into the new thread, proving the stale-cleanup path ran.',
    );
  }

}
