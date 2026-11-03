<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Functional;

use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\ModelChoiceOption;
use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the transcript survives a finished turn and grows across turns.
 *
 * The module's whole reason to exist is invisible in isolation: the stock
 * ai_assistant_api AgentRunner DELETES an agent's serialized state when a
 * turn finishes, so every new user message starts the LLM over with no
 * memory. AgentMemoryRunner decorates that runner and persists the
 * conversation's transcript instead. Proving that requires the fixture the
 * base class builds: the recipe's real daedalus agent and configuration,
 * and the stub provider in place of the LLM.
 *
 * The stub is left unscripted here, so every reply is a tool-free text and
 * the agent loop finishes on the first leg. The behavior under test (does
 * the finished turn's transcript persist, does a second turn build on it,
 * is a leg tagged with its segment, does the gate refuse?) is unaffected
 * by what the model would have said.
 *
 * It drives AgentMemoryRunner::runAsAgent() directly rather than through the
 * full assistant runner: that method is the module's contract (chat history
 * in, persisted record out, keyed by job id), and calling it directly gives
 * deterministic control over the turns. The state detection itself is
 * covered by AgentStateTest (Unit) and the leg hooks by
 * AgentMemoryRunnerTest; this test covers the integration those unit cases
 * cannot: that a real finished turn leaves a real record in tempstore
 * holding a transcript, a second turn appends to the same transcript, a
 * repeat of an answered message is appended as a new turn on the kept
 * record, a record that cannot be read back is the one thing deleted (the
 * request then runs fresh), the usage ledger records each leg under its
 * segment, and a reached cap refuses the next turn through the assistant's
 * template.
 *
 * Turns three and four pin the two halves of the record's lifecycle. The
 * record is the agent's memory and is never discarded for what the session
 * carries: the decorator persists it when a turn finishes (turns one and
 * two prove that), and a later request with the same session turn two
 * already ran for (turn three) restores it and appends the repeated message
 * as a new turn. The ONLY thing that removes a record is a record the
 * runner cannot read (turn four, which corrupts the stored transcript's
 * version by hand): the runner deletes it and runs the request fresh in the
 * same call, so the record is not null afterward but REBUILT. What makes
 * the rebuild observable is the CONTENT of the transcript: a fresh start
 * replays the session's earlier messages, so the client-supplied assistant
 * line "Got it." appears as an entry, whereas a restore carries the agent's
 * own prior reply and never a client-supplied line. So "Got it." absent
 * after turns two and three but present after turn four is the
 * same-fixture before/after that distinguishes a rebuild from a restore.
 *
 * This is a plain Functional (not JavaScript) test: cross-turn persistence
 * is a runner-plus-tempstore behavior with no UI of its own. The only thing
 * a WebDriver run of the assembled DeepChat UI could observe is that a reply
 * bubble renders, which proves the chatbot works, not that state persisted
 * or was cleaned up; the record and the ledger rows are readable only
 * IN-PROCESS, and the stub cannot emit tool calls to exercise a multi-tool
 * loop. The lifecycle is one test method so the expensive
 * site-install-plus-recipe-apply fixture is paid once for it; the model
 * choice is a second method, since it runs as another account over its
 * own conversations.
 *
 * @group daedalus_ai_memory
 */
#[RunTestsInSeparateProcesses]
class AgentMemoryCrossTurnTest extends AgentMemoryFunctionalTestBase {

  /**
   * A finished turn persists, the next builds on it, a stale one cleans up.
   */
  public function testAgentStatePersistsAcrossTurns(): void {
    // PrivateTempStore keys by the current user when authenticated, so run as a
    // real account to get a stable, session-independent record owner that both
    // the runner's writes and this test's reads agree on. A plain account,
    // not an administrator: the usage gate never refuses a user who may
    // bypass the caps, and the admin role holds every permission.
    $account = $this->drupalCreateUser([], 'memory-tester');
    $this->assertFalse($account->hasPermission('bypass ai usage caps'), 'The account is subject to the usage caps.');
    $this->setCurrentUser($account);

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
    $ledger = \Drupal::service('daedalus_ai_ledger.usage_ledger');
    $job_id = 'cross-turn-test';

    // The stub finishes the agent loop with a tool-free response and no
    // network call, so a real turn runs end to end without an API key.
    $defaults = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];

    $this->assertNull($store->get($job_id), 'No record exists before the first turn.');

    // Turn one (Fresh): a single user message.
    $session_one = [['role' => 'user', 'message' => 'Remember the number 42.', 'timestamp' => 1000]];
    $runner->runAsAgent('daedalus', $session_one, $defaults, $job_id, FALSE, []);

    // The headline: the stock runner deletes state when the agent finishes; the
    // decorator persisted it instead. The record carries the transcript with
    // the user's turn and the stub's reply in its first segment, the
    // identity of the message the turn ran for, and the wrapper's state
    // without its history (the transcript is the history).
    $record_one = $store->get($job_id);
    $this->assertNotNull(
      $record_one,
      'The record survived the finished turn (the stock runner would have deleted it).',
    );
    $this->assertSame(AgentState::identity($session_one), $record_one['last_user'], 'Turn one ran for the session\'s one user message.');
    $this->assertTrue($record_one['finished'], 'The tool-free reply ended the loop.');
    $this->assertSame([], $record_one['agent_data']['chat_history'], 'The wrapper\'s history is never stored.');
    $texts_one = $this->entryTexts($record_one);
    $this->assertSame(['Remember the number 42.', StubProvider::RESPONSE_TEXT], $texts_one, 'Segment one holds the turn and its reply.');

    // The leg was tagged with its segment: the ledger's row for the
    // conversation names segment 1, which is how the segment policy
    // measures a segment by its own legs.
    $row_one = $ledger->newestLeg($job_id);
    $this->assertNotNull($row_one, 'The ledger recorded the leg under the runner id.');
    $this->assertSame('1', $row_one->segment(), 'The leg carried its segment tag.');
    $this->assertFalse($row_one->rebuilt(), 'A fresh start over a one-message session replays nothing: the conversation began, it was not rebuilt.');

    // Turn two: the same conversation, grown by the assistant reply and a
    // follow-up question, sent under the same job id.
    $session_two = [
      ['role' => 'user', 'message' => 'Remember the number 42.', 'timestamp' => 1000],
      ['role' => 'assistant', 'message' => 'Got it.', 'timestamp' => 1001],
      ['role' => 'user', 'message' => 'What number did I ask you to remember?', 'timestamp' => 1060],
    ];
    $runner->runAsAgent('daedalus', $session_two, $defaults, $job_id, FALSE, []);

    // Continuity: the same record was updated in place, and turn two genuinely
    // RESTORED turn one's transcript rather than starting over. The proof it
    // was a cross-turn restore and not a fresh rebuild: the transcript does
    // NOT contain "Got it.". The client replayed that assistant line in turn
    // two's chat history, but a cross turn appends only the new user turn
    // and carries the agent's own prior reply forward, so a client-supplied
    // assistant message never enters the transcript. A fresh run would have
    // replayed the whole session verbatim, "Got it." included.
    $record_two = $store->get($job_id);
    $this->assertNotNull($record_two, 'The record still exists after the second turn.');
    $this->assertSame(AgentState::identity($session_two), $record_two['last_user'], 'Turn two ran for the new user message.');
    $texts_two = $this->entryTexts($record_two);
    $this->assertSame([
      'Remember the number 42.',
      StubProvider::RESPONSE_TEXT,
      'What number did I ask you to remember?',
      StubProvider::RESPONSE_TEXT,
    ], $texts_two, 'Turn two appended its turn to the restored transcript (cross-turn) rather than rebuilding from the replayed history: the client-supplied assistant message was not carried in.');
    $this->assertCount(1, Transcript::fromArray($record_two['transcript'])->segments(), 'Two short turns stay in one segment.');

    // Precondition for turn three: turn two must have FINISHED. If this ever
    // regressed to "not finished", turn three would be a SameTurn poll
    // (state restored as-is, the message not appended) and the assertion
    // below would fail for a reason this line names up front.
    $this->assertTrue(
      $record_two['finished'],
      'Turn two finished (the tool-free reply ended the loop), which is what makes a repeat of its message a new turn.',
    );

    // Turn three (CrossTurn by repeat): the SAME job id with the SAME session
    // turn two already ran for. The last user message's identity is the one
    // the record answered and the record is finished: the user sent the
    // same message again. The record is the agent's memory, so it is kept
    // and restored, and the repeated message is appended as a new turn;
    // "Got it." stays out, as on every restore.
    $runner->runAsAgent('daedalus', $session_two, $defaults, $job_id, FALSE, []);
    $record_three = $store->get($job_id);
    $this->assertNotNull($record_three, 'A repeated message never discards the record.');
    $this->assertSame([
      'Remember the number 42.',
      StubProvider::RESPONSE_TEXT,
      'What number did I ask you to remember?',
      StubProvider::RESPONSE_TEXT,
      'What number did I ask you to remember?',
      StubProvider::RESPONSE_TEXT,
    ], $this->entryTexts($record_three), 'Turn three restored the record and appended the repeated message as a new turn; the client-supplied assistant line was not carried in.');

    // Turn four (the unreadable record): the stored transcript is given a
    // version this code does not know, as a record written by another
    // version of the module would carry. Reading the record is the runner's
    // one guarded step and the one place a record is ever deleted: it logs,
    // deletes the record and runs the request fresh in the same call, so
    // $store->get() is not null afterward but REBUILT from the replayed
    // session. What pins the rebuild is that the transcript NOW CONTAINS
    // "Got it.", the client-supplied assistant line every restore above
    // excluded. The session also carries an empty assistant message ahead
    // of the last user turn, the way ai_assistant_api stores one per leg
    // (empty for a leg that only called tools): the replay skips it, since
    // a replayed message carries no calls and an empty one would reach the
    // provider as an empty content block.
    $store->set($job_id, ['transcript' => ['version' => 99, 'segments' => []]] + $record_three);
    $session_four_replayed = [...array_slice($session_two, 0, -1),
      ['role' => 'assistant', 'message' => '', 'timestamp' => 1051],
      ...array_slice($session_two, -1),
    ];
    $runner->runAsAgent('daedalus', $session_four_replayed, $defaults, $job_id, FALSE, []);
    $record_rebuilt = $store->get($job_id);
    $this->assertNotNull(
      $record_rebuilt,
      'The unreadable record was deleted and the request ran fresh in the same call, so a brand-new record is persisted under the job id.',
    );
    $this->assertSame([
      'Remember the number 42.',
      'Got it.',
      'What number did I ask you to remember?',
      StubProvider::RESPONSE_TEXT,
    ], $this->entryTexts($record_rebuilt), 'The rebuilt transcript replays the session: the assistant line "Got it." (excluded by every restore) is now an entry, and the session\'s empty assistant message was not replayed.');

    // The rebuild is on the ledger: the fresh turn that replayed earlier
    // session messages tagged its first leg, and the row keeps it. The
    // runs before it (turn one began the conversation; turns two and three
    // restored the record) left no such row.
    $row_rebuilt = $ledger->newestLeg($job_id);
    $this->assertTrue($row_rebuilt->rebuilt(), 'The rebuilt record\'s first leg is recorded as a rebuild.');
    $rows = \Drupal::entityTypeManager()->getStorage('ai_usage')->loadByProperties([
      'conversation' => $job_id,
      'rebuilt' => 1,
    ]);
    $this->assertCount(1, $rows, 'Only the rebuilding turn\'s leg carries the flag.');

    // The usage gate. Price the stub's model so a leg costs a thousand
    // dollars, run one more turn (allowed: nothing priced was spent yet),
    // then cap the day at one dollar: the next turn is refused before any
    // model call, through the assistant's own error template, and the
    // record is left exactly as the allowed turn wrote it.
    \Drupal::configFactory()->getEditable('daedalus_ai_ledger.settings')
      ->set('prices', [
        [
          'model' => StubProvider::MODEL_ID,
          'input' => 1000000,
          'output' => 0,
          'cache_read' => 0,
          'cache_write' => 0,
        ],
      ])
      ->save();
    $session_four = [...$session_two,
      ['role' => 'assistant', 'message' => '42.', 'timestamp' => 1061],
      ['role' => 'user', 'message' => 'Add a hero.', 'timestamp' => 1120],
    ];
    $output_four = $runner->runAsAgent('daedalus', $session_four, $defaults, $job_id, FALSE, []);
    $this->assertSame(StubProvider::RESPONSE_TEXT, $output_four->getNormalized()->getText(), 'The priced turn still ran: no cap was set.');
    $record_four = $store->get($job_id);
    $this->assertSame(AgentState::identity($session_four), $record_four['last_user']);
    $row_four = $ledger->newestLeg($job_id);
    $this->assertSame(1000.0, $row_four->cost(), 'The leg was priced from the table.');
    $this->assertFalse($row_four->rebuilt(), 'A cross turn on the rebuilt record is not itself a rebuild.');

    \Drupal::configFactory()->getEditable('daedalus_ai_ledger.settings')
      ->set('caps', [['scope' => 'user', 'period' => 'P1D', 'usd' => 1.0, 'anchor' => '']])
      ->save();
    $session_five = [...$session_four,
      ['role' => 'assistant', 'message' => 'Done.', 'timestamp' => 1121],
      ['role' => 'user', 'message' => 'Now a footer.', 'timestamp' => 1180],
    ];
    $output_five = $runner->runAsAgent('daedalus', $session_five, $defaults, $job_id, FALSE, []);
    $reply_five = $output_five->getNormalized()->getText();
    $this->assertStringStartsWith('Sorry, I could not process that request: You have reached the AI usage limit for the last 1 day', $reply_five, 'The refusal rides the assistant\'s error template and names the window.');
    $this->assertSame($record_four, $store->get($job_id), 'A refused turn leaves the record as the last allowed turn wrote it.');
    $this->assertSame($row_four->id(), $ledger->newestLeg($job_id)->id(), 'No leg ran for the refused turn: the newest row is still the allowed turn\'s.');
  }

  /**
   * A user's valid model choice runs a new conversation on the chosen pair.
   *
   * The pair a turn runs on resolves as the user's valid choice, else the
   * caller's defaults. The choice is valid only for an account holding
   * "choose ai model" whose editor preference (daedalus_ui's lane,
   * namespace daedalus_ai, key model) names a curated pair, so the test
   * curates the stub provider's second model id and stores that key on a
   * permitted account: its conversation's first leg runs on stub-alt, which
   * is observable three ways in-process (the capture records the model id
   * per call, the ledger row names the provider and model the leg ran on,
   * and the persisted record's wrapper state carries the pair the polls
   * latch onto). With the permission revoked and the preference still
   * stored, the next conversation runs on the defaults' model: the lane
   * accepts any string, and the permission is checked where it is read.
   */
  public function testModelChoiceRunsTheConversationOnTheChosenPair(): void {
    // The managed provider, the one every curated row must name: the
    // recipe's assistant runs on the site default (llm_provider
    // "__default__"), which the Anthropic ingredient points at
    // daedalus_anthropic, so the site default moves to the stub pair
    // $defaults below carries before the stub-alt row is curated.
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID])
      ->save();
    \Drupal::configFactory()->getEditable(ModelChoice::SETTINGS)
      ->set(ModelChoice::CHOICES_KEY, [
        ['provider' => 'daedalus_ai_test_stub', 'model' => StubProvider::ALT_MODEL_ID, 'label' => 'Alternate'],
      ])
      ->save();

    $runner = \Drupal::service('ai_assistant_api.agent_runner');
    $store = \Drupal::service('tempstore.private')->get(self::THREAD_BIN);
    $ledger = \Drupal::service('daedalus_ai_ledger.usage_ledger');
    $capture = \Drupal::service('daedalus_ai_test.chat_capture');
    $defaults = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];
    $key = 'daedalus_ai_test_stub' . ModelChoiceOption::SEPARATOR . StubProvider::ALT_MODEL_ID;

    // A permitted account that chose the curated model, through the same
    // lane the chat's menu writes.
    $account = $this->drupalCreateUser([ModelChoice::PERMISSION], 'model-chooser');
    $this->setCurrentUser($account);
    \Drupal::service('daedalus_ui.editor_settings')->set(ModelChoice::NAMESPACE, ModelChoice::KEY, $key);
    $this->assertSame($key, \Drupal::service('daedalus_ui.editor_settings')->getFor($account, ModelChoice::NAMESPACE, ModelChoice::KEY), 'The preference is stored on the account.');

    $capture->reset();
    $session = [['role' => 'user', 'message' => 'Which model is this?', 'timestamp' => 1000]];
    $runner->runAsAgent('daedalus', $session, $defaults, 'chosen-job', FALSE, []);

    $calls = $capture->calls();
    $this->assertCount(1, $calls, 'The tool-free reply ended the loop on one leg.');
    $this->assertSame(StubProvider::ALT_MODEL_ID, $calls[0]['model_id'], 'The first leg ran on the chosen model, not the defaults\'.');
    $row = $ledger->newestLeg('chosen-job');
    $this->assertNotNull($row, 'The ledger recorded the leg.');
    $this->assertSame('daedalus_ai_test_stub', $row->provider(), 'The row names the chosen provider.');
    $this->assertSame(StubProvider::ALT_MODEL_ID, $row->model(), 'The row names the chosen model.');
    $record = $store->get('chosen-job');
    $this->assertSame('daedalus_ai_test_stub', $record['agent_data']['provider_id'], 'The record carries the chosen provider, for the polls.');
    $this->assertSame(StubProvider::ALT_MODEL_ID, $record['agent_data']['model_name'], 'The record carries the chosen model, for the polls.');

    // The same account without the permission: the stored preference no
    // longer counts, and a new conversation runs on the defaults. The
    // entity is reloaded first: the preference was written to a fresh load
    // of the user, and saving the stale object would overwrite it.
    $account = \Drupal::entityTypeManager()->getStorage('user')->loadUnchanged($account->id());
    foreach ($account->getRoles(TRUE) as $role) {
      $account->removeRole($role);
    }
    $account->save();
    $this->setCurrentUser($account);
    $this->assertFalse($account->hasPermission(ModelChoice::PERMISSION), 'The permission is revoked.');
    $this->assertSame($key, \Drupal::service('daedalus_ui.editor_settings')->getFor($account, ModelChoice::NAMESPACE, ModelChoice::KEY), 'The preference is still stored.');

    $capture->reset();
    $runner->runAsAgent('daedalus', $session, $defaults, 'unpermitted-job', FALSE, []);

    $calls = $capture->calls();
    $this->assertCount(1, $calls);
    $this->assertSame(StubProvider::MODEL_ID, $calls[0]['model_id'], 'Without the permission the defaults\' model runs.');
    $this->assertSame(StubProvider::MODEL_ID, $ledger->newestLeg('unpermitted-job')->model(), 'The row names the defaults\' model.');
    $this->assertSame(StubProvider::MODEL_ID, $store->get('unpermitted-job')['agent_data']['model_name'], 'The record carries the defaults\' model.');
  }

  /**
   * The texts of a record's current-segment entries, in order.
   *
   * @param array $record
   *   The persisted record.
   *
   * @return string[]
   *   The entry texts.
   */
  private function entryTexts(array $record): array {
    return array_map(
      static fn (Entry $entry): string => $entry->text,
      Transcript::fromArray($record['transcript'])->entries(),
    );
  }

}
