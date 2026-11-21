<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Component\Plugin\PluginManagerBase;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Service\HostnameFilter;
use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\Event\AgentResponseEvent;
use Drupal\ai_agents\Output\StructuredResultDataInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_agents\Task\TaskInterface;
use Drupal\ai_assistant_api\AiAssistantInterface;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Context\ContextBlocksEvent;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai\Provider\UsageReader;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\daedalus_ai_memory\Segment\CarryOverGenerator;
use Drupal\daedalus_ai_memory\Segment\SegmentPolicy;
use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_memory\Transcript\TranscriptRenderer;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Pins the AgentMemoryRunner branches that integration coverage cannot reach.
 *
 * AgentMemoryRunner is the orchestrator of the context spine: it decides
 * whether an agent gets persistent state at all, classifies the request by
 * the last user message's identity, runs the usage gate, appends the user's
 * turn with its blocks, renders the transcript into the wrapper before
 * each leg and absorbs after it, and turns the agent's output into a
 * ChatOutput while refusing to echo the user's own text back. The
 * decisions pinned here carry the module's headline guarantees but are not
 * observable from the assembled-stack Functional test
 * (AgentMemoryCrossTurnTest), which drives the happy path of a real agent
 * through a tool-free provider:
 *
 *  - the enabled_agents allow-list (delegate verbatim when not opted in),
 *  - the usage gate's refusal (through the assistant's template, before the
 *    agent is even created, the record untouched),
 *  - the catch block (keep the record and persist nothing, reply with the
 *    failure's reason, never rethrow and never replay the user's message),
 *  - the guarded read of the record (an unreadable record is deleted, the
 *    one deletion there is, and the request runs fresh once),
 *  - the buildResponse echo-guard,
 *  - the failure reply's wording (the fronting assistant's error template),
 *  - the leg hooks: the user turn appended with the producers' blocks, the
 *    rendering set as the wrapper's history and swapped into the leg's
 *    input with a plan attached, what the wrapper appended absorbed, the
 *    segment tag set, and the transcript persisted without the wrapper's
 *    history,
 *  - a tool's images riding the leg after the tool (the wrapper's
 *    interjected screenshots message carries them on that leg's input
 *    only, and the record holds no bytes).
 *
 * Each is orchestration over injected collaborators, so this is a
 * container-free unit test: the runner is constructed directly with test
 * doubles, and the spine's own pure classes (renderer, policy, generator,
 * ledger, gate) are real over mocked storage. The state detection itself is
 * AgentStateTest's; the segment close math is SegmentPolicyTest's.
 *
 * The runner reads its logger via LoggerChannelTrait::getLogger(), which
 * would otherwise reach for the global container; setLoggerFactory()
 * injects a stub so no container bootstrap is needed.
 */
#[CoversClass(AgentMemoryRunner::class)]
#[Group('daedalus_ai_memory')]
class AgentMemoryRunnerTest extends UnitTestCase {

  /**
   * The assistant id used across the persistent-path tests.
   */
  private const ENABLED_AGENT = 'daedalus';

  /**
   * The reply when the agent produced nothing and recorded no failure.
   *
   * Hard-coded here (rather than read from source) so that if the production
   * string drifts the test fails loudly, forcing a deliberate update.
   */
  private const NO_REPLY_MESSAGE = 'The assistant produced no reply.';

  /**
   * A provider rejection as the Anthropic provider throws it.
   *
   * The SDK's message is a label line followed by the pretty-printed response
   * body; the API's sentence inside it is what the reply must carry.
   */
  private const PROVIDER_FAILURE = "Anthropic Bad Request Exception\n{\n    \"status\": 400,\n    \"body\": {\n        \"type\": \"error\",\n        \"error\": {\n            \"type\": \"invalid_request_error\",\n            \"message\": \"messages.0.content.1.image.source.base64.data: At least one of the image dimensions exceed max allowed size: 8000 pixels\"\n        },\n        \"request_id\": \"req_011\"\n    }\n}";

  /**
   * The sentence distilled from PROVIDER_FAILURE.
   */
  private const PROVIDER_REASON = 'messages.0.content.1.image.source.base64.data: At least one of the image dimensions exceed max allowed size: 8000 pixels';

  /**
   * The defaults every persistent-path call hands the runner.
   */
  private const DEFAULTS = ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => StubProvider::MODEL_ID];

  /**
   * The override's parameter list is the parent's, exactly.
   *
   * PHP refuses to load a class whose override is incompatible with its
   * parent, and that fatal error reaches no log a site owner reads: the
   * chat simply stops answering. Upstream has added a parameter to
   * runAsAgent() in a patch release, so this compares the two lists (names,
   * types, defaults, order) and fails here, by name, the next time they
   * part. A parameter the override lacks is also one it cannot pass on to
   * the inner runner, which a merely compatible signature would hide.
   */
  public function testRunAsAgentSignatureMatchesTheParent(): void {
    $describe = static fn (\ReflectionMethod $method): array => array_map(
      static fn (\ReflectionParameter $parameter): array => [
        'name' => $parameter->getName(),
        'type' => (string) $parameter->getType(),
        'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : '(required)',
        'variadic' => $parameter->isVariadic(),
        'by reference' => $parameter->isPassedByReference(),
      ],
      $method->getParameters(),
    );
    $parent = new \ReflectionMethod(AgentRunner::class, 'runAsAgent');
    $override = new \ReflectionMethod(AgentMemoryRunner::class, 'runAsAgent');

    $this->assertSame(AgentMemoryRunner::class, $override->getDeclaringClass()->getName());
    $this->assertSame($describe($parent), $describe($override));
    $this->assertSame((string) $parent->getReturnType(), (string) $override->getReturnType());
  }

  /**
   * Delegation: a non-enabled agent never touches persistence machinery.
   *
   * This is the module's surgical opt-in guarantee. If the enabled_agents check
   * regressed (inverted, dropped, or persistence ran unconditionally), every
   * agent would silently acquire cross-turn state and the "only enabled agents
   * get persistence" design would be broken. The test proves the negative two
   * ways for an agent absent from the allow-list: (1) the inner runner is
   * called exactly once with byte-identical arguments (the streaming flag
   * among them, so an agent this module does not manage streams exactly as
   * upstream intends) and its ChatOutput is returned unchanged (a verbatim
   * pass-through), and (2) the persistence
   * collaborators are never even consulted - the tempstore factory's get() is
   * never called and the agent plugin manager's createInstance() is never
   * called. It runs the guard for both an empty allow-list and a non-empty list
   * that omits the agent, since "not in the list" must hold in both shapes.
   */
  public function testNonEnabledAgentDelegatesVerbatim(): void {
    foreach ([[], ['some_other_agent']] as $enabled) {
      $chat_history = [['role' => 'user', 'message' => 'Hi there']];
      $context = ['page' => 'front'];
      $expected = new ChatOutput(new ChatMessage('assistant', 'delegated reply'), [''], []);

      $inner = $this->createMock(AgentRunner::class);
      // The decorator must forward EXACTLY what it received, in order, and hand
      // back the inner runner's output without inspecting or wrapping it.
      $inner->expects($this->once())
        ->method('runAsAgent')
        ->with('some_agent', $chat_history, self::DEFAULTS, 'job-1', TRUE, $context, TRUE)
        ->willReturn($expected);

      // Persistence machinery must be untouched on the delegate path.
      $tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
      $tempStoreFactory->expects($this->never())->method('get');
      $pluginManager = $this->createMock(PluginManagerInterface::class);
      $pluginManager->expects($this->never())->method('createInstance');

      $runner = $this->createRunner($this->createMock(PrivateTempStore::class), $pluginManager, enabled: $enabled, inner: $inner, tempStoreFactory: $tempStoreFactory);

      $result = $runner->runAsAgent('some_agent', $chat_history, self::DEFAULTS, 'job-1', TRUE, $context, TRUE);

      $this->assertSame(
        $expected,
        $result,
        'A non-enabled agent must return the inner runner output unchanged.',
      );
    }
  }

  /**
   * A reached cap refuses the turn before the agent exists.
   *
   * The gate runs before any model call: with a window at its cap the
   * runner never creates the agent, never writes the record, and answers
   * the gate's sentence through the assistant's error template, the same
   * road every failure takes to the bubble. A poll is refused too, since
   * its leg is a model call.
   */
  public function testUsageCapRefusalAnswersThroughTheTemplateBeforeAnyModelCall(): void {
    $assistant = $this->createMock(AiAssistantInterface::class);
    $assistant->method('get')->willReturnMap([
      ['error_message', 'Sorry: [error_message]'],
      ['specific_error_messages', []],
      ['llm_configuration', []],
    ]);

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturn($this->record('continue', finished: FALSE));
    $store->expects($this->never())->method('set');
    $store->expects($this->never())->method('delete');

    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->expects($this->never())->method('createInstance');

    // One window of an hour capped at one dollar, and one leg inside it
    // that cost exactly that: the cap is reached at equality.
    $runner = $this->createRunner(
      $store,
      $pluginManager,
      assistant: $assistant,
      caps: [['scope' => 'user', 'period' => 'PT1H', 'usd' => 1.0, 'anchor' => '']],
      legs: [['created' => 900, 'cost' => '1.0']],
    );
    $result = $runner->runAsAgent(self::ENABLED_AGENT, [['role' => 'user', 'message' => 'continue']], self::DEFAULTS, 'capped-job', FALSE, []);

    $this->assertStringStartsWith('Sorry: You have reached the AI usage limit for the last ', $result->getNormalized()->getText(), 'The refusal wears the template and names the window.');
  }

  /**
   * Error recovery: a thrown turn keeps the record and degrades gracefully.
   *
   * The record is the agent's memory, so a failed turn never costs it: the
   * runner (1) neither deletes the job's record nor writes a new one (the
   * stored record is the last good state, which the next turn builds on),
   * and (2) returns a ChatOutput carrying the failure's reason - NOT a
   * rethrow (the call returns normally) and NOT the user's own text. The
   * thrown point is createInstance() because it is the first thing inside
   * the try block, which proves the catch wraps the whole run rather than a
   * single late step. No assistant fronts the agent here, so the reason
   * stands alone. The one record ever deleted is one that cannot be read
   * (the next test).
   */
  public function testFailedTurnKeepsRecordAndRepliesWithTheReason(): void {
    $job_id = 'kept-job';

    $store = $this->createMock(PrivateTempStore::class);
    // A readable record exists; it answered 'first' and the session now
    // ends on 'second', so this is the CrossTurn path into the throw.
    $store->method('get')->with($job_id)->willReturn($this->record('first', finished: TRUE));
    $store->expects($this->never())->method('delete');
    // The error path must never write new state.
    $store->expects($this->never())->method('set');

    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')
      ->with(self::ENABLED_AGENT)
      ->willThrowException(new \RuntimeException('provider exploded'));

    $runner = $this->createRunner($store, $pluginManager);

    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [
        ['role' => 'user', 'message' => 'first'],
        ['role' => 'assistant', 'message' => 'reply'],
        ['role' => 'user', 'message' => 'second'],
      ],
      self::DEFAULTS,
      $job_id,
      FALSE,
      [],
    );

    $this->assertInstanceOf(ChatOutput::class, $result);
    $this->assertSame(
      'provider exploded',
      $result->getNormalized()->getText(),
      'A failed turn replies with the reason it failed, not the user prompt.',
    );
  }

  /**
   * An unreadable record is deleted and the request runs fresh, once.
   *
   * Reading the record is the runner's one guarded step: a transcript of a
   * version this code does not know refuses Transcript::fromArray(), and
   * that is the one failure that deletes the record. The request then runs
   * fresh in the same call: the agent is instantiated again (a restore
   * that failed midway is not reused), the fresh path resolves the
   * defaults' provider, the session's earlier messages are replayed, the
   * leg plays, and the new record is persisted once under the job id.
   */
  public function testUnreadableRecordIsDeletedAndTheRequestRunsFresh(): void {
    $job_id = 'unreadable-job';
    $dispatcher = new EventDispatcher();

    $record = $this->record('first', finished: TRUE, reply: 'first reply');
    $record['transcript']['version'] = 99;

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($record);
    $store->expects($this->once())->method('delete')->with($job_id);
    $persisted = NULL;
    $store->expects($this->once())->method('set')->with($job_id, $this->callback(static function (array $record) use (&$persisted): bool {
      $persisted = $record;
      return TRUE;
    }));

    $agent = new FakeEchoAgent(dispatcher: $dispatcher, reply: 'fresh reply');
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->expects($this->exactly(2))->method('createInstance')->with(self::ENABLED_AGENT)->willReturn($agent);

    $runner = $this->createRunner($store, $pluginManager, dispatcher: $dispatcher, answersStubProvider: TRUE);
    $session = [
      ['role' => 'user', 'message' => 'first', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'first reply', 'timestamp' => 101],
      ['role' => 'user', 'message' => 'second', 'timestamp' => 200],
    ];
    $result = $runner->runAsAgent(self::ENABLED_AGENT, $session, self::DEFAULTS, $job_id, FALSE, []);
    $this->assertSame('fresh reply', $result->getNormalized()->getText(), 'The fresh run played the leg.');
    $this->assertCount(1, $agent->callTags);
    $this->assertContains(UsageLedger::REBUILT_TAG, $agent->callTags[0], 'The fresh run replayed earlier session messages, so its first leg\'s call is tagged as a rebuild.');
    $this->assertContains('daedalus_ai_segment_1', $agent->callTags[0], 'The rebuilt tag rides beside the segment tag.');
    $this->assertSame(['daedalus_ai_segment_1'], $agent->providerTags, 'The leg\'s re-tagging leaves the segment tag alone for the legs that follow.');

    // The new record is a fresh start: the session's earlier messages
    // replayed as plain entries (a cross turn would have carried the
    // record's own reply instead), the turn, the wrapper's interjection and
    // the reply.
    $this->assertNotNull($persisted);
    $this->assertSame(AgentState::identity($session), $persisted['last_user']);
    $this->assertTrue($persisted['finished']);
    $entries = Transcript::fromArray($persisted['transcript'])->entries();
    $this->assertSame(
      ['first', 'first reply', 'second', FakeEchoAgent::CONTEXT_MESSAGE, 'fresh reply'],
      array_map(static fn ($entry): string => $entry->text, $entries),
      'The record was rebuilt from the session, not restored.',
    );
  }

  /**
   * Echo-guard: a trailing user message yields the no-reply line, not an echo.
   *
   * This guards the exact anti-pattern the module exists to prevent. The stock
   * runner ends with `end($history)` and returns that as the assistant reply,
   * so if the agent produced nothing the user's own last message becomes the
   * "answer". buildResponse() defends against this: when the last chat-history
   * message has role 'user' it replies with the failure the agent recorded or,
   * as here where it recorded none, the plain no-reply line. The test feeds a
   * fake agent that succeeds through determineSolvability()/solve() but whose
   * getChatHistory() ends with a user message, and asserts the runner returns
   * that line rather than echoing 'echo me back'. If the trailing-role check
   * were removed the assertion would catch the echoed prompt.
   *
   * It uses a hand-written fake agent (FakeEchoAgent below) rather than a
   * PHPUnit mock because the runner calls several ConfigAiAgentInterface
   * methods on the way to the response (fromArray, determineSolvability,
   * getChatHistory, solve, toArray, isFinished); a small fake with sane returns
   * is clearer and more robust than stubbing each one.
   *
   * The test drives the SameTurn restore path (an unfinished record for the
   * same user message) rather than the Fresh path on purpose: SameTurn
   * restores via fromArray() and never resolves a provider through the
   * plugin manager (the concrete class is final and cannot be doubled), so
   * the echo-guard, which lives in buildResponse() and is independent of how
   * the agent was initialized, is exercised without one.
   */
  public function testTrailingUserMessageReturnsErrorNotEcho(): void {
    $job_id = 'echo-job';

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($this->record('echo me back', finished: FALSE));
    // The run completes, so the thread is re-persisted, never deleted.
    $store->expects($this->once())->method('set');
    $store->expects($this->never())->method('delete');

    $agent = new FakeEchoAgent();

    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')
      ->with(self::ENABLED_AGENT)
      ->willReturn($agent);

    $runner = $this->createRunner($store, $pluginManager);

    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [['role' => 'user', 'message' => 'echo me back']],
      self::DEFAULTS,
      $job_id,
      FALSE,
      [],
    );

    $this->assertSame(
      self::NO_REPLY_MESSAGE,
      $result->getNormalized()->getText(),
      'When the last history message is the user, say no reply was produced, never echo it.',
    );
    $this->assertNotSame(
      'echo me back',
      $result->getNormalized()->getText(),
      'The user prompt must never be returned as the assistant reply.',
    );
  }

  /**
   * A provider rejection reaches the chat as the API's own sentence.
   *
   * AiAgentEntityWrapper catches the provider call's exception, records its
   * message through getLastError() and answers JOB_NOT_SOLVABLE with the
   * history still ending on the user's line. The runner must then persist the
   * thread as usual (the conversation survives a rejected attachment) and
   * reply with the sentence distilled from the recorded message, not a fixed
   * apology and not the raw SDK dump. The fake records the Anthropic
   * provider's message for an image over the API's dimension limit.
   */
  public function testRecordedProviderFailureRepliesWithItsReason(): void {
    $job_id = 'rejected-job';

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($this->record('describe website.png', finished: FALSE));
    $store->expects($this->once())->method('set');
    $store->expects($this->never())->method('delete');

    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')
      ->with(self::ENABLED_AGENT)
      ->willReturn(new FakeEchoAgent(self::PROVIDER_FAILURE));

    $runner = $this->createRunner($store, $pluginManager);

    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [['role' => 'user', 'message' => 'describe website.png']],
      self::DEFAULTS,
      $job_id,
      FALSE,
      [],
    );

    $this->assertSame(
      self::PROVIDER_REASON,
      $result->getNormalized()->getText(),
      'The reply is the sentence the provider gave for the rejection.',
    );
  }

  /**
   * The failure reply wears the fronting assistant's error template.
   *
   * The ai_assistant_api module lets a site phrase failures once on the
   * assistant entity (error_message, with specific_error_messages per
   * exception class, both carrying an [error_message] token). The runner
   * renders the same template so every road reads alike: a recorded provider
   * failure takes the default template, a thrown turn the entry for its
   * exception's short class name when one is set. Both are pinned here
   * through one assistant double.
   */
  public function testFailureReplyRendersTheAssistantTemplate(): void {
    $assistant = $this->createMock(AiAssistantInterface::class);
    $assistant->method('get')->willReturnMap([
      ['error_message', 'Sorry, I could not process that: [error_message]'],
      ['specific_error_messages', ['RuntimeException' => 'The site broke: [error_message]']],
      ['llm_configuration', []],
    ]);

    // A recorded provider failure: the default template.
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturn($this->record('x', finished: FALSE));
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->willReturn(new FakeEchoAgent(self::PROVIDER_FAILURE));
    $runner = $this->createRunner($store, $pluginManager, assistant: $assistant);
    $result = $runner->runAsAgent(self::ENABLED_AGENT, [['role' => 'user', 'message' => 'x']], self::DEFAULTS, 'templated-job', FALSE, []);
    $this->assertSame(
      'Sorry, I could not process that: ' . self::PROVIDER_REASON,
      $result->getNormalized()->getText(),
    );

    // A thrown turn: the entry for the exception's class wins.
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturn(NULL);
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->willThrowException(new \RuntimeException('provider exploded'));
    $runner = $this->createRunner($store, $pluginManager, assistant: $assistant);
    $result = $runner->runAsAgent(self::ENABLED_AGENT, [['role' => 'user', 'message' => 'x']], self::DEFAULTS, 'thrown-job', FALSE, []);
    $this->assertSame(
      'The site broke: provider exploded',
      $result->getNormalized()->getText(),
    );
  }

  /**
   * A transport-inert agent's rendering is scoped full trust.
   *
   * Rendering the transcript rebuilds every tool call as a fresh
   * ToolsFunctionOutput, which re-runs each string argument through
   * HostnameFilter::filterText() with settings defaults (no per-call DTO is
   * in scope at that time), and the rebuilt objects are exactly the tools a
   * poll then executes. For an agent whose config carries
   * hostname_filter_disabled (the knob the wrapper honors around its own
   * provider call), the runner must scope the render the same way upstream
   * scopes the invocation: snapshot the singleton, apply a full-trust DTO,
   * restore in finally. Otherwise the transport silently rewrites the very
   * tool arguments the flag promises byte-intact (the live symptom: any
   * style attribute containing url() stripped from a page_create layout).
   * Pinned both ways on the SameTurn path, which renders once: the flagged
   * agent gets snapshot/full-trust-apply/restore around it, and an unflagged
   * agent's render never touches the filter's programmatic state.
   */
  public function testTransportInertAgentScopesRenderingFullTrust(): void {
    foreach ([TRUE, FALSE] as $flag) {
      $store = $this->createMock(PrivateTempStore::class);
      $store->method('get')->willReturn($this->record('continue', finished: FALSE));

      $pluginManager = $this->createMock(PluginManagerInterface::class);
      $pluginManager->method('createInstance')
        ->with(self::ENABLED_AGENT)
        ->willReturn(new FakeEchoAgent());

      $agentEntity = $this->createMock(AiAgentEntityInterface::class);
      $agentEntity->method('get')->with('hostname_filter_disabled')->willReturn($flag);

      $hostnameFilter = $this->createMock(HostnameFilter::class);
      if ($flag) {
        $snapshot = ['allowedDomains' => NULL, 'rewriteLinks' => NULL, 'fullTrust' => NULL, 'plainTextMode' => NULL];
        $hostnameFilter->expects($this->once())->method('snapshotSettings')->willReturn($snapshot);
        $hostnameFilter->expects($this->once())->method('applySettings')
          ->with($this->callback(fn (HostnameFilterDto $dto) => $dto->fullTrust === TRUE));
        $hostnameFilter->expects($this->once())->method('restoreSettings')->with($snapshot);
      }
      else {
        $hostnameFilter->expects($this->never())->method('snapshotSettings');
        $hostnameFilter->expects($this->never())->method('applySettings');
        $hostnameFilter->expects($this->never())->method('restoreSettings');
      }

      $runner = $this->createRunner($store, $pluginManager, $hostnameFilter, $agentEntity);
      $runner->runAsAgent(self::ENABLED_AGENT, [['role' => 'user', 'message' => 'continue']], self::DEFAULTS, 'inert-job', FALSE, []);
    }
  }

  /**
   * A cross turn appends the user's turn with its blocks and drives the leg.
   *
   * Through a real event dispatcher: the producers answer the one
   * ContextBlocksEvent (not opening a segment, since the segment has a
   * turn) with a print block; the rendering, with the block's text on the
   * new user message, becomes the wrapper's history and is swapped into
   * the leg's input with a plan attached; what the wrapper appended before
   * the request (its own context message) is absorbed at the request and
   * what it appended after (the reply) at the end; the leg is tagged with
   * its segment; and the record persists the transcript, the identity of
   * the message answered, and the wrapper's state without its history.
   */
  public function testCrossTurnRendersBeforeAndAbsorbsAfterTheLeg(): void {
    $job_id = 'leg-job';
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(ContextBlocksEvent::EVENT_NAME, static function (ContextBlocksEvent $event): void {
      $event->addBlock(new Block(BlockKind::Print, 'the print for turn ' . ($event->opensSegment() ? 'one' : 'two')));
    });

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($this->record('first', finished: TRUE, reply: 'first reply'));
    $persisted = NULL;
    $store->expects($this->once())->method('set')->with($job_id, $this->callback(static function (array $record) use (&$persisted): bool {
      $persisted = $record;
      return TRUE;
    }));

    $agent = new FakeEchoAgent(dispatcher: $dispatcher, reply: 'second reply');
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->with(self::ENABLED_AGENT)->willReturn($agent);

    $runner = $this->createRunner($store, $pluginManager, dispatcher: $dispatcher);
    $session = [
      ['role' => 'user', 'message' => 'first', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'first reply', 'timestamp' => 101],
      ['role' => 'user', 'message' => 'second', 'timestamp' => 200],
    ];
    $result = $runner->runAsAgent(self::ENABLED_AGENT, $session, self::DEFAULTS, $job_id, FALSE, ['view_mode' => 'full']);
    $this->assertSame('second reply', $result->getNormalized()->getText());

    // The leg's input carried the rendering: the segment's turns, the new
    // one with the block's text ahead of the user's words, then the
    // context message the wrapper appended before the request, absorbed
    // and rendered back in place. The plan rode the input.
    $this->assertNotNull($agent->requestInput, 'The fake dispatched a request event.');
    $texts = array_map(static fn (ChatMessage $message): string => $message->getText(), $agent->requestInput->getMessages());
    $this->assertCount(4, $texts);
    $this->assertSame('first', $texts[0]);
    $this->assertSame('first reply', $texts[1]);
    $this->assertStringContainsString('the print for turn two', $texts[2]);
    $this->assertStringEndsWith("\n\nsecond", $texts[2]);
    $this->assertSame(FakeEchoAgent::CONTEXT_MESSAGE, $texts[3]);
    $this->assertNotNull(RenderingPlan::fromInput($agent->requestInput), 'The rendering plan rode the leg\'s input.');
    $this->assertSame(['daedalus_ai_segment_1'], $agent->providerTags, 'The leg was tagged with its segment.');
    $this->assertCount(1, $agent->callTags);
    $this->assertNotContains(UsageLedger::PREFIX_DRIFT_TAG, $agent->callTags[0], 'A segment\'s first prefix is no drift.');

    // The record: the transcript holds the turn, its block, the wrapper's
    // interjection and the reply; the wrapper's own history is not stored.
    $this->assertNotNull($persisted);
    $this->assertSame(AgentState::identity($session), $persisted['last_user']);
    $this->assertTrue($persisted['finished']);
    $this->assertSame([], $persisted['agent_data']['chat_history']);
    $entries = Transcript::fromArray($persisted['transcript'])->entries();
    $this->assertCount(5, $entries);
    $this->assertSame('second', $entries[2]->text);
    $this->assertSame('print', $entries[2]->blocks[0]->kind->value);
    $this->assertTrue($entries[2]->turn, 'The user\'s own message is the turn.');
    $this->assertFalse($entries[3]->turn, 'The wrapper\'s context message is an interjection, not a turn.');
    $this->assertSame('second reply', $entries[4]->text);
  }

  /**
   * A tool's images ride the leg after the tool, and that leg only.
   *
   * The wrapper collects the images a tool returned and, at the start of
   * the next leg, appends a user message carrying them ("Screenshot(s) from
   * the tool results above:"). Under this runner that message is absorbed
   * into the transcript, which holds no bytes, and the leg is re-rendered
   * from the transcript; so the runner lifts the files off the absorbed
   * user messages and renders that one leg with them, placed on the
   * rendering's newest user message, the wrapper's. Three legs: the first
   * reply is followed by a tool result and the screenshots message with an
   * ImageFile; the second leg's input carries that image on its last user
   * message, whose text is the wrapper's, and nowhere else; the third
   * leg's input carries no file at all; the persisted record's entry for
   * the screenshots message is an interjection (turn FALSE) and the record
   * contains none of the image's bytes.
   */
  public function testToolImagesRideTheLegAfterTheTool(): void {
    $job_id = 'image-job';
    $dispatcher = new EventDispatcher();

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($this->record('first', finished: TRUE, reply: 'first reply'));
    $persisted = NULL;
    $store->expects($this->once())->method('set')->with($job_id, $this->callback(static function (array $record) use (&$persisted): bool {
      $persisted = $record;
      return TRUE;
    }));

    $image = new ImageFile('png!', 'image/png', 'shot.png');
    $tool_result = new ChatMessage('tool', 'shot.png (image/png, 4 bytes) arrives as an image on your next turn.');
    $tool_result->setToolsId('call_1');
    $screenshots = new ChatMessage('user', FakeEchoAgent::SCREENSHOTS_MESSAGE);
    $screenshots->setImage($image);
    $prompt = 'You are a fake.';
    $agent = new FakeEchoAgent(dispatcher: $dispatcher, reply: 'a reply', legs: [$prompt, $prompt, $prompt], afterLeg: [
      1 => [$tool_result, $screenshots],
    ]);
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->with(self::ENABLED_AGENT)->willReturn($agent);

    $runner = $this->createRunner($store, $pluginManager, dispatcher: $dispatcher);
    $session = [
      ['role' => 'user', 'message' => 'first', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'first reply', 'timestamp' => 101],
      ['role' => 'user', 'message' => 'look at the screenshot again', 'timestamp' => 200],
    ];
    $result = $runner->runAsAgent(self::ENABLED_AGENT, $session, self::DEFAULTS, $job_id, FALSE, []);
    $this->assertSame('a reply', $result->getNormalized()->getText());
    $this->assertCount(3, $agent->requestInputs, 'Three legs dispatched a request event each.');

    // The first leg: the turn delivered no files, so none ride it.
    $this->assertSame([], self::filesOf($agent->requestInputs[0]), 'The turn\'s first leg carries no file.');

    // The second leg: the image rides the wrapper's screenshots message,
    // the rendering's newest user message, and no other message.
    $messages = $agent->requestInputs[1]->getMessages();
    $last_user = NULL;
    foreach ($messages as $message) {
      if ($message->getRole() === 'user') {
        $last_user = $message;
      }
    }
    $this->assertNotNull($last_user);
    $this->assertSame(FakeEchoAgent::SCREENSHOTS_MESSAGE, $last_user->getText(), 'The image rides the wrapper\'s own message.');
    $this->assertCount(1, $last_user->getImages());
    $delivered = array_values($last_user->getImages())[0];
    $this->assertSame('shot.png', $delivered->getFilename());
    $this->assertSame('png!', $delivered->getBinary());
    $this->assertSame([$delivered], self::filesOf($agent->requestInputs[1]), 'The image is on the screenshots message and nowhere else.');
    $this->assertSame('tool', $messages[count($messages) - 2]->getRole(), 'The tool result precedes the screenshots message.');

    // The third leg: bytes once; the message is rendered from the record,
    // which holds none.
    $this->assertSame([], self::filesOf($agent->requestInputs[2]), 'The leg after renders without the image.');
    $texts = array_map(static fn (ChatMessage $message): string => $message->getText(), $agent->requestInputs[2]->getMessages());
    $this->assertContains(FakeEchoAgent::SCREENSHOTS_MESSAGE, $texts, 'The screenshots message itself is kept.');

    // The record: the screenshots message is an interjection, and no bytes.
    $this->assertNotNull($persisted);
    $entries = Transcript::fromArray($persisted['transcript'])->entries();
    $interjections = array_values(array_filter($entries, static fn ($entry): bool => $entry->text === FakeEchoAgent::SCREENSHOTS_MESSAGE));
    $this->assertCount(1, $interjections);
    $this->assertFalse($interjections[0]->turn, 'The wrapper\'s screenshots message is not the user\'s turn.');
    $this->assertStringNotContainsString('png!', serialize($persisted), 'The record holds no bytes.');
  }

  /**
   * Every file set on any message of a leg's input, in message order.
   *
   * @return \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   *   The files.
   */
  private static function filesOf(ChatInput $input): array {
    $files = [];
    foreach ($input->getMessages() as $message) {
      array_push($files, ...$message->getFiles());
    }
    return $files;
  }

  /**
   * A leg whose prefix changed is tagged on its own call, not the next.
   *
   * Two legs in one segment with different system prompts: the second's
   * hash differs from the first's, so its call (the pre-generate event's
   * tags, which the proxy hands the provider) carries the drift tag and
   * the first's does not. The provider tags the runner sets on the agent
   * stay the segment tag alone: the wrapper merges those into a leg's
   * tags before the request event, so a drift tag there would land on the
   * leg after the one that drifted.
   */
  public function testDriftedLegIsTaggedOnItsOwnCall(): void {
    $job_id = 'drift-job';
    $dispatcher = new EventDispatcher();

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($this->record('first', finished: TRUE, reply: 'first reply'));
    $persisted = NULL;
    $store->expects($this->once())->method('set')->with($job_id, $this->callback(static function (array $record) use (&$persisted): bool {
      $persisted = $record;
      return TRUE;
    }));

    $agent = new FakeEchoAgent(dispatcher: $dispatcher, reply: 'second reply', legs: [
      'You are a fake.',
      'You are a fake, and a feeder changed this line.',
    ]);
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->with(self::ENABLED_AGENT)->willReturn($agent);

    $runner = $this->createRunner($store, $pluginManager, dispatcher: $dispatcher);
    $session = [
      ['role' => 'user', 'message' => 'first', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'first reply', 'timestamp' => 101],
      ['role' => 'user', 'message' => 'second', 'timestamp' => 200],
    ];
    $result = $runner->runAsAgent(self::ENABLED_AGENT, $session, self::DEFAULTS, $job_id, FALSE, []);
    $this->assertSame('second reply', $result->getNormalized()->getText());

    $this->assertCount(2, $agent->callTags, 'Both legs called the provider.');
    $this->assertNotContains(UsageLedger::PREFIX_DRIFT_TAG, $agent->callTags[0], 'The first leg sets the segment\'s hash; nothing drifted yet.');
    $this->assertContains(UsageLedger::PREFIX_DRIFT_TAG, $agent->callTags[1], 'The leg whose prefix changed carries the drift tag on its own call.');
    $this->assertContains('ai_agents_runner_' . $job_id, $agent->callTags[1], 'The call\'s other tags are kept.');
    $this->assertContains('daedalus_ai_segment_1', $agent->callTags[1], 'The segment tag set at the first leg rode the second.');
    $this->assertSame(['daedalus_ai_segment_1'], $agent->providerTags, 'The drift tag never rides the provider tags.');

    // The record's segment kept both hashes and reads as drifted.
    $this->assertNotNull($persisted);
    $segment = Transcript::fromArray($persisted['transcript'])->currentSegment();
    $this->assertTrue($segment->prefixDrifted());
    $this->assertCount(2, $segment->prefixHashes());
  }

  /**
   * The user's model choice applies at a turn's first leg, never on a poll.
   *
   * The pair a turn runs on resolves as the user's valid choice, else the
   * caller's defaults: on a fresh start the defaults are set first (the
   * fresh initialization is untouched) and the choice then replaces them;
   * on a cross turn the record's pair is restored and the choice replaces
   * it when it differs, by provider id through the provider manager and by
   * model name; a choice equal to the pair in hand sets nothing. A poll
   * (same turn) never reads the choice: the turn's legs share one prefix,
   * so the pair the record carries is the one the poll continues on. With
   * no choice (nothing stored, or stored but unpermitted) the agent keeps
   * what it had.
   *
   * @param array|null $record
   *   The stored record, or NULL for a fresh start.
   * @param string|null $stored
   *   The key the user's preference holds.
   * @param bool $permitted
   *   Whether the user holds the permission.
   * @param string[] $providerIds
   *   The provider ids the runner is expected to set, in order.
   * @param string[] $modelNames
   *   The model names the runner is expected to set, in order.
   */
  #[DataProvider('modelChoiceCases')]
  public function testModelChoiceAppliesAtTheTurnsFirstLegOnly(?array $record, ?string $stored, bool $permitted, array $providerIds, array $modelNames): void {
    $job_id = 'choice-job';
    $dispatcher = new EventDispatcher();

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn($record);
    $persisted = NULL;
    $store->expects($this->once())->method('set')->with($job_id, $this->callback(static function (array $record) use (&$persisted): bool {
      $persisted = $record;
      return TRUE;
    }));

    $agent = new FakeEchoAgent(dispatcher: $dispatcher, reply: 'the reply');
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->with(self::ENABLED_AGENT)->willReturn($agent);

    $runner = $this->createRunner(
      $store,
      $pluginManager,
      dispatcher: $dispatcher,
      answersStubProvider: TRUE,
      // Both rows on the managed provider (the stub): a choice switches
      // the model, never the provider, and ModelChoice refuses a row on
      // another one.
      curated: [
        ['provider' => 'daedalus_ai_test_stub', 'model' => 'stub-alt', 'label' => 'Alternate'],
        ['provider' => 'daedalus_ai_test_stub', 'model' => StubProvider::MODEL_ID, 'label' => ''],
      ],
      storedChoice: $stored,
      mayChoose: $permitted,
    );
    $session = [['role' => 'user', 'message' => 'continue']];
    $result = $runner->runAsAgent(self::ENABLED_AGENT, $session, self::DEFAULTS, $job_id, FALSE, []);
    $this->assertSame('the reply', $result->getNormalized()->getText(), 'The leg played.');

    $this->assertSame($providerIds, $agent->providerIds, 'The providers set on the agent, in order.');
    $this->assertSame($modelNames, $agent->modelNames, 'The model names set on the agent, in order.');
    $this->assertNotNull($persisted, 'The turn persisted the record the polls latch onto.');
  }

  /**
   * The cases of testModelChoiceAppliesAtTheTurnsFirstLegOnly().
   */
  public static function modelChoiceCases(): array {
    $finished = self::storedRecord('continue', finished: TRUE);
    $unfinished = self::storedRecord('continue', finished: FALSE);
    return [
      'a fresh turn with a choice sets the defaults, then the chosen pair' => [
        NULL, 'daedalus_ai_test_stub__stub-alt', TRUE,
        ['daedalus_ai_test_stub', 'daedalus_ai_test_stub'], [StubProvider::MODEL_ID, 'stub-alt'],
      ],
      'a cross turn with a choice different from the record\'s pair switches' => [
        $finished, 'daedalus_ai_test_stub__stub-alt', TRUE,
        ['daedalus_ai_test_stub'], ['stub-alt'],
      ],
      'a cross turn with a choice equal to the record\'s pair sets nothing' => [
        $finished, 'daedalus_ai_test_stub__' . StubProvider::MODEL_ID, TRUE,
        [], [],
      ],
      'a same-turn poll with a choice keeps the record\'s pair' => [
        $unfinished, 'daedalus_ai_test_stub__stub-alt', TRUE,
        [], [],
      ],
      'a fresh turn with no choice runs the defaults' => [
        NULL, NULL, TRUE,
        ['daedalus_ai_test_stub'], [StubProvider::MODEL_ID],
      ],
      'a cross turn with no choice keeps the record\'s pair' => [
        $finished, NULL, TRUE,
        [], [],
      ],
      'a stored choice without the permission does not count' => [
        $finished, 'daedalus_ai_test_stub__stub-alt', FALSE,
        [], [],
      ],
    ];
  }

  /**
   * Builds a runner wired for the persistent path.
   *
   * The spine's pure collaborators are real: the renderer, the segment
   * policy over the settings, the carry-over generator, the ledger and the
   * gate over mocked storage (no row for the conversation, so no segment
   * ever closes, and the configured caps against the legs given).
   *
   * @param \Drupal\Core\TempStore\PrivateTempStore $store
   *   The store mock returned for the threads bin.
   * @param \Drupal\Component\Plugin\PluginManagerInterface $pluginManager
   *   The agent plugin manager double.
   * @param \Drupal\ai\Service\HostnameFilter|null $hostnameFilter
   *   The hostname filter double, or NULL for an indifferent mock.
   * @param object|null $agentEntity
   *   The ai_agent config entity the runner loads to read the
   *   hostname_filter_disabled flag, or NULL when none exists.
   * @param \Drupal\ai_assistant_api\AiAssistantInterface|null $assistant
   *   The assistant entity fronting the agent (its error template and
   *   llm_configuration), or NULL when none fronts it.
   * @param array|null $enabled
   *   The enabled_agents list; the enabled agent alone when NULL.
   * @param \Drupal\ai_assistant_api\Service\AgentRunner|null $inner
   *   The inner runner, or NULL for an indifferent mock.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory|null $tempStoreFactory
   *   The tempstore factory, or NULL for one answering the store.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   The event dispatcher, or NULL for an indifferent mock.
   * @param array $caps
   *   The ledger's caps config.
   * @param array $legs
   *   The rows the ledger's user query answers (created, cost).
   * @param bool $answersStubProvider
   *   Whether the provider manager answers a provider by id, which the
   *   fresh path and a model switch need (see realAiProviderManager()).
   * @param array $curated
   *   The daedalus_ai.settings model_choices rows.
   * @param string|null $storedChoice
   *   The provider__model key the current user's editor preference holds.
   * @param bool $mayChoose
   *   Whether the current user holds the choose-ai-model permission.
   */
  private function createRunner(PrivateTempStore $store, PluginManagerInterface $pluginManager, ?HostnameFilter $hostnameFilter = NULL, ?object $agentEntity = NULL, ?AiAssistantInterface $assistant = NULL, ?array $enabled = NULL, ?AgentRunner $inner = NULL, ?PrivateTempStoreFactory $tempStoreFactory = NULL, ?EventDispatcherInterface $dispatcher = NULL, array $caps = [], array $legs = [], bool $answersStubProvider = FALSE, array $curated = [], ?string $storedChoice = NULL, bool $mayChoose = FALSE): AgentMemoryRunner {
    if ($tempStoreFactory === NULL) {
      $tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
      $tempStoreFactory->method('get')->with('daedalus_ai_memory_threads')->willReturn($store);
    }

    // The runner resolves the fronting assistant for its llm_configuration
    // and its error template; most tests run without one, so the lookup
    // legitimately finds nothing (loadByProperties has no declared return
    // type, so the mock must be told to return an array or reset() throws).
    // It also loads the agent config entity to read the
    // hostname_filter_disabled flag before rendering, and the ledger reads
    // ai_usage rows through the entity type manager.
    $assistantStorage = $this->createMock(EntityStorageInterface::class);
    $assistantStorage->method('loadByProperties')->willReturn($assistant ? [$assistant] : []);
    $agentStorage = $this->createMock(EntityStorageInterface::class);
    $agentStorage->method('load')->with(self::ENABLED_AGENT)->willReturn($agentEntity);
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort', 'range'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn([]);
    $usageStorage = $this->createMock(EntityStorageInterface::class);
    $usageStorage->method('getQuery')->willReturn($query);
    $usageType = $this->createMock(EntityTypeInterface::class);
    $usageType->method('getBaseTable')->willReturn('ai_usage');
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturnMap([
      ['ai_assistant', $assistantStorage],
      ['ai_agent', $agentStorage],
      ['ai_usage', $usageStorage],
    ]);
    $entityTypeManager->method('getDefinition')->with('ai_usage')->willReturn($usageType);

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchAll')->willReturn(array_map(static fn (array $leg): object => (object) $leg, $legs));
    $select = $this->createMock(SelectInterface::class);
    foreach (['fields', 'condition', 'orderBy'] as $method) {
      $select->method($method)->willReturnSelf();
    }
    $select->method('execute')->willReturn($statement);
    $connection = $this->createMock(Connection::class);
    $connection->method('select')->willReturn($select);

    $configFactory = $this->configFactory($enabled ?? [self::ENABLED_AGENT], $caps, $curated);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $currentUser = $this->createMock(AccountInterface::class);
    $currentUser->method('id')->willReturn(7);
    // No account bypasses the caps here; the model-choice permission is the
    // one a test may grant.
    $currentUser->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $mayChoose && $permission === ModelChoice::PERMISSION);
    $editorSettings = $this->createMock(EditorSettings::class);
    $editorSettings->method('getFor')->with($currentUser, ModelChoice::NAMESPACE, ModelChoice::KEY)->willReturn($storedChoice);
    $providerManager = $this->realAiProviderManager($answersStubProvider);
    // The managed provider every curated row must name is the site's
    // default chat provider (the daedalus_ai config mock names no
    // assistant), the stub, which the provider manager reads off its own
    // ai.settings.
    $modelChoice = new ModelChoice($configFactory, $editorSettings, $this->createMock(LoggerInterface::class), $entityTypeManager, $providerManager);
    $dateFormatter = $this->createMock(DateFormatterInterface::class);
    $dateFormatter->method('formatInterval')->willReturn('1 hour');
    $dateFormatter->method('format')->willReturn('later');

    $ledger = new UsageLedger($entityTypeManager, $connection, new UsageReader(), new CostResolver($configFactory), $providerManager, $currentUser, $time);
    $gate = new UsageGate($configFactory, $ledger, $time, $dateFormatter);
    $gate->setStringTranslation($this->getStringTranslationStub());

    $runner = new AgentMemoryRunner(
      $inner ?? $this->createMock(AgentRunner::class),
      $providerManager,
      $tempStoreFactory,
      $dispatcher ?? $this->createMock(EventDispatcherInterface::class),
      $configFactory,
      $entityTypeManager,
      $hostnameFilter ?? $this->createMock(HostnameFilter::class),
      new TranscriptRenderer(),
      new SegmentPolicy($configFactory, $this->createMock(SkillsResolverInterface::class)),
      new CarryOverGenerator(),
      $ledger,
      $gate,
      $currentUser,
      $modelChoice,
      $pluginManager,
    );
    $runner->setLoggerFactory($this->stubLoggerFactory());

    return $runner;
  }

  /**
   * A record as the runner persists it: a transcript with one turn answered.
   *
   * @param string $userText
   *   The user message the record's turn ran for (no timestamp, as the
   *   tests' sessions carry none).
   * @param bool $finished
   *   Whether that turn finished.
   * @param string|null $reply
   *   The assistant's reply to hold after the turn, or NULL for none.
   */
  private function record(string $userText, bool $finished, ?string $reply = NULL): array {
    return self::storedRecord($userText, $finished, $reply);
  }

  /**
   * The static form of record(), for a data provider.
   *
   * @param string $userText
   *   The user message the record's turn ran for.
   * @param bool $finished
   *   Whether that turn finished.
   * @param string|null $reply
   *   The assistant's reply to hold after the turn, or NULL for none.
   */
  private static function storedRecord(string $userText, bool $finished, ?string $reply = NULL): array {
    $transcript = new Transcript();
    $transcript->openSegment();
    $transcript->appendUserTurn($userText);
    if ($reply !== NULL) {
      $transcript->appendReplayed('assistant', $reply);
    }
    return [
      'transcript' => $transcript->toArray(),
      'agent_data' => ['chat_history' => []],
      'last_user' => AgentState::identity([['role' => 'user', 'message' => $userText]]),
      'finished' => $finished,
    ];
  }

  /**
   * A config factory answering the memory, ledger and daedalus_ai settings.
   *
   * @param array $enabled
   *   The enabled_agents list.
   * @param array $caps
   *   The ledger's caps.
   * @param array $curated
   *   The daedalus_ai.settings model_choices rows.
   */
  private function configFactory(array $enabled, array $caps, array $curated = []): ConfigFactoryInterface {
    $memory = $this->createMock(ImmutableConfig::class);
    $memory->method('get')->willReturnMap([
      ['enabled_agents', $enabled],
      ['cache_ttl', RenderingPlan::TTL_5M],
      ['segment_budget_tokens', 80000],
      ['segment_budget_minimum_tokens', 50000],
      ['segment_budget_overrides', []],
      ['segment_headroom_tokens', 32000],
      ['reframe_minimum_tokens', 25000],
    ]);
    $ledger = $this->createMock(ImmutableConfig::class);
    $ledger->method('get')->willReturnMap([
      ['caps', $caps],
      ['prices', []],
    ]);

    $daedalusAi = $this->createMock(ImmutableConfig::class);
    $daedalusAi->method('get')->willReturnMap([
      [ModelChoice::CHOICES_KEY, $curated],
    ]);

    // The gate runs its windows in the site's timezone.
    $systemDate = $this->createMock(ImmutableConfig::class);
    $systemDate->method('get')->willReturnMap([
      ['timezone.default', 'UTC'],
    ]);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['daedalus_ai_memory.settings', $memory],
      ['daedalus_ai_ledger.settings', $ledger],
      [ModelChoice::SETTINGS, $daedalusAi],
      ['system.date', $systemDate],
    ]);

    return $factory;
  }

  /**
   * A logger factory whose channels are inert, swallowing every log call.
   *
   * @return \Drupal\Core\Logger\LoggerChannelFactoryInterface
   *   The logger factory double.
   */
  private function stubLoggerFactory(): LoggerChannelFactoryInterface {
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($this->createMock(LoggerInterface::class));

    return $factory;
  }

  /**
   * A real (never-mocked) AiProviderPluginManager wired with stub dependencies.
   *
   * The parent AgentRunner constructor type-hints the concrete, final
   * AiProviderPluginManager, so it cannot be doubled. Most tests never reach
   * its createInstance() (the delegate path returns first, the error paths
   * throw at the agent manager, and the restore paths take the provider off
   * the agent), so a genuine instance built from mocked collaborators
   * satisfies the type without doing any plugin discovery. The fresh path
   * does reach it (the defaults' provider is resolved by id), and so does
   * a user's model choice naming another provider; for that the one hook
   * the final class leaves is the plugin factory it inherits from
   * PluginManagerBase, replaced by one answering a bare stub provider
   * stamped with the id asked for, which the manager wraps in the real
   * ProviderProxy as it does any plugin.
   *
   * @param bool $answersStub
   *   Whether createInstance() answers a proxied bare stub provider for
   *   any id instead of running discovery.
   *
   * @return \Drupal\ai\AiProviderPluginManager
   *   The provider manager instance.
   */
  private function realAiProviderManager(bool $answersStub = FALSE): AiProviderPluginManager {
    $container = $this->createMock(ContainerInterface::class);
    $behavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
    $container->method('get')->willReturnMap([
      [
        'event_dispatcher',
        $behavior,
        $this->createMock(EventDispatcherInterface::class),
      ],
      [
        'logger.factory',
        $behavior,
        $this->createMock(LoggerChannelFactoryInterface::class),
      ],
      [
        'config.factory',
        $behavior,
        // The site's default chat provider: the stub, which is also the
        // managed provider ModelChoice resolves for the curated rows.
        $this->getConfigFactoryStub(['ai.settings' => ['default_providers' => ['chat' => self::DEFAULTS]]]),
      ],
    ]);

    $manager = new AiProviderPluginManager(
      new \ArrayObject(),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $container,
      $this->createMock(MessengerInterface::class),
      $this->createMock(UuidInterface::class),
      $this->createMock(HostnameFilter::class),
    );
    if ($answersStub) {
      $factory = $this->createMock(FactoryInterface::class);
      $factory->method('createInstance')
        ->willReturnCallback(static fn (string $plugin_id): StubProvider => FakeEchoAgent::bareProvider($plugin_id));
      (new \ReflectionProperty(PluginManagerBase::class, 'factory'))->setValue($manager, $factory);
    }
    return $manager;
  }

}

/**
 * A minimal real agent whose final chat-history message is from the user.
 *
 * This exercises the buildResponse() echo-guard: the agent "succeeds" (solve()
 * returns a value, determineSolvability() reports solvable) yet its history
 * ends with a user message, which is the empty-response condition the guard
 * must catch. Given a dispatcher it plays one leg the way the wrapper does
 * instead: appends its own context message, then per leg computes the
 * call's tags the way the wrapper does (before the request event, the
 * provider tags merged in), dispatches the request event with an input
 * built from its history, dispatches the provider proxy's pre-generate
 * event with those tags and keeps what came back, dispatches the response
 * event, and appends the reply, so the runner's leg hooks run. Several
 * legs play with the system prompts given, one leg each, and after a leg's
 * reply the fake appends whatever messages were given for that leg (a
 * tool result and the screenshots message the wrapper adds when a tool
 * returned images), before the next leg's request event, as the wrapper
 * does. It is a hand-written fake rather than a mock so the many methods
 * the runner calls have plain, predictable behavior; only the methods the
 * runner actually invokes do anything meaningful, the rest satisfy the
 * interface.
 */
class FakeEchoAgent implements ConfigAiAgentInterface {

  /**
   * The message the fake appends to its history before a leg's request.
   */
  public const CONTEXT_MESSAGE = 'Context the wrapper added itself.';

  /**
   * The user message the wrapper appends carrying a tool's images.
   *
   * Hard-coded to AiAgentEntityWrapper's wording so a drift there fails
   * loudly here.
   */
  public const SCREENSHOTS_MESSAGE = 'Screenshot(s) from the tool results above:';

  /**
   * The history as the runner set it, plus what the fake appended.
   *
   * @var \Drupal\ai\OperationType\Chat\ChatMessage[]
   */
  private array $chatHistory = [];

  /**
   * The input the fake dispatched its last request event with, once it has.
   */
  public ?ChatInput $requestInput = NULL;

  /**
   * The input of every request event the fake dispatched, in leg order.
   *
   * @var \Drupal\ai\OperationType\Chat\ChatInput[]
   */
  public array $requestInputs = [];

  /**
   * The provider tags the runner set last.
   */
  public array $providerTags = [];

  /**
   * The tags each played leg's call carried after the pre-generate event.
   *
   * @var string[][]
   */
  public array $callTags = [];

  /**
   * The runner id the runner set.
   */
  private string $runnerId = '';

  /**
   * The plugin id of each provider the runner set, in order.
   *
   * @var string[]
   */
  public array $providerIds = [];

  /**
   * Each model name the runner set, in order.
   *
   * @var string[]
   */
  public array $modelNames = [];

  /**
   * Constructs the fake.
   *
   * @param string|null $lastError
   *   The failure the agent recorded for its provider call, as
   *   AiAgentEntityWrapper::getLastError() would report it, or NULL for a
   *   turn that recorded none.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   A dispatcher to play one leg through, or NULL to end on the user's
   *   line without a leg.
   * @param string $reply
   *   The reply each played leg appends.
   * @param string[] $legs
   *   The system prompt of each leg to play, one leg per prompt.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[][] $afterLeg
   *   Messages to append after a leg's reply, keyed by the leg's loop
   *   number (1 for the first), as the wrapper appends tool results and the
   *   screenshots message before the next leg's request event.
   */
  public function __construct(
    private readonly ?string $lastError = NULL,
    private readonly ?EventDispatcherInterface $dispatcher = NULL,
    private readonly string $reply = 'played reply',
    private readonly array $legs = ['You are a fake.'],
    private readonly array $afterLeg = [],
  ) {}

  /**
   * Mirrors AiAgentEntityWrapper::getLastError().
   *
   * The ai_agents module keeps that method off the agent interfaces; the
   * runner reads it by presence.
   */
  public function getLastError(): ?string {
    return $this->lastError;
  }

  /**
   * {@inheritdoc}
   */
  public function determineSolvability() {
    if ($this->dispatcher === NULL) {
      return $this->lastError === NULL ? AiAgentInterface::JOB_SOLVABLE : AiAgentInterface::JOB_NOT_SOLVABLE;
    }
    $this->chatHistory[] = new ChatMessage('user', self::CONTEXT_MESSAGE);
    foreach (array_values($this->legs) as $index => $system_prompt) {
      $loop = $index + 1;
      // As the wrapper: the call's tags, provider tags included, are fixed
      // before the request event is dispatched.
      $tags = ['ai_agents', 'ai_agents_runner_' . $this->runnerId, ...$this->providerTags];
      $input = new ChatInput($this->chatHistory);
      $input->setSystemPrompt($system_prompt);
      $this->dispatcher->dispatch(new AgentRequestEvent($this, $input, $system_prompt, 'daedalus', '', $this->chatHistory, $loop, $this->runnerId), AgentRequestEvent::EVENT_NAME);
      $this->requestInput = $input;
      $this->requestInputs[] = $input;
      $generate = new PreGenerateResponseEvent('thread-' . $loop, 'daedalus_ai_test_stub', 'chat', [], $input, StubProvider::MODEL_ID, $tags);
      $this->dispatcher->dispatch($generate, PreGenerateResponseEvent::EVENT_NAME);
      $this->callTags[] = $generate->getTags();
      $output = new ChatOutput(new ChatMessage('assistant', $this->reply), [], []);
      $this->dispatcher->dispatch(new AgentResponseEvent($this, $system_prompt, 'daedalus', '', $this->chatHistory, $output, $loop, $this->runnerId), AgentResponseEvent::EVENT_NAME);
      $this->chatHistory[] = $output->getNormalized();
      foreach ($this->afterLeg[$loop] ?? [] as $message) {
        $this->chatHistory[] = $message;
      }
    }
    return AiAgentInterface::JOB_SOLVABLE;
  }

  /**
   * {@inheritdoc}
   */
  public function solve() {
    return $this->dispatcher === NULL ? 'an assistant answer that must be suppressed' : $this->reply;
  }

  /**
   * {@inheritdoc}
   */
  public function getChatHistory(): array {
    if ($this->dispatcher !== NULL) {
      return $this->chatHistory;
    }
    // Deliberately ends on a user message so the echo-guard must fire.
    return [...$this->chatHistory, new ChatMessage('user', 'echo me back')];
  }

  /**
   * {@inheritdoc}
   */
  public function setChatHistory(array $history): void {
    $this->chatHistory = $history;
  }

  /**
   * {@inheritdoc}
   */
  public function isFinished(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['chat_history' => array_map(static fn (ChatMessage $message): array => $message->toArray(), $this->chatHistory)];
  }

  /**
   * {@inheritdoc}
   */
  public function fromArray(array $data): void {}

  /**
   * {@inheritdoc}
   */
  public function setProviderTags(array $tags): void {
    $this->providerTags = $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderTags(): array {
    return $this->providerTags;
  }

  /**
   * {@inheritdoc}
   */
  public function setStreaming(bool $streaming) {}

  /**
   * {@inheritdoc}
   */
  public function setChatInput(ChatInput $chatInput) {}

  /**
   * {@inheritdoc}
   */
  public function getChatInput(): ChatInput {
    return new ChatInput([], []);
  }

  /**
   * {@inheritdoc}
   *
   * Records the plugin id of what was set (a proxied provider answers it
   * through the proxy), so a test reads which pair the runner put the
   * agent on.
   */
  public function setAiProvider($aiProvider) {
    $this->providerIds[] = (string) $aiProvider->getPluginId();
  }

  /**
   * {@inheritdoc}
   *
   * A bare stub provider stamped with the id last set (the stub's own when
   * none was): the renderer and the policy read capabilities off its class
   * and the maximum input off drupal/ai's placeholder, neither of which
   * needs the constructor, and the runner reads the pair off its id.
   */
  public function getAiProvider() {
    return self::bareProvider($this->providerIds === [] ? 'daedalus_ai_test_stub' : end($this->providerIds));
  }

  /**
   * A stub provider built without its constructor, stamped with a plugin id.
   *
   * @param string $plugin_id
   *   The id getPluginId() answers.
   */
  public static function bareProvider(string $plugin_id): StubProvider {
    $provider = (new \ReflectionClass(StubProvider::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(AiProviderClientBase::class, 'pluginId'))->setValue($provider, $plugin_id);
    return $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function setModelName($modelName) {
    $this->modelNames[] = (string) $modelName;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelName() {
    return $this->modelNames === [] ? StubProvider::MODEL_ID : end($this->modelNames);
  }

  /**
   * {@inheritdoc}
   */
  public function setCreateDirectly($createDirectly) {}

  /**
   * {@inheritdoc}
   */
  public function getCreateDirectly() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setLooped(bool $enabled) {}

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'fake_echo_agent';
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleName() {
    return 'daedalus_ai_memory';
  }

  /**
   * {@inheritdoc}
   */
  public function getAiConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setAiConfiguration(array $aiConfiguration) {}

  /**
   * {@inheritdoc}
   */
  public function getTask() {}

  /**
   * {@inheritdoc}
   */
  public function setTask(TaskInterface $task) {}

  /**
   * {@inheritdoc}
   */
  public function agentsNames() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function agentsCapabilities() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRunnerId() {
    return $this->runnerId;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtraTags() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function answerQuestion() {}

  /**
   * {@inheritdoc}
   */
  public function inform() {}

  /**
   * {@inheritdoc}
   */
  public function hasAccess() {
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function askQuestion() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function approveSolution() {}

  /**
   * {@inheritdoc}
   */
  public function getStructuredOutput(): StructuredResultDataInterface {
    throw new \LogicException('Not used in this test.');
  }

  /**
   * {@inheritdoc}
   */
  public function setUserInterface($userInterface, array $extraTags = []) {}

  /**
   * {@inheritdoc}
   */
  public function rollback() {}

  /**
   * {@inheritdoc}
   */
  public function setRunnerId($runnerId) {
    $this->runnerId = (string) $runnerId;
  }

  /**
   * {@inheritdoc}
   */
  public function getData() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setData(array $data) {}

  /**
   * {@inheritdoc}
   */
  public function getTokenContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setTokenContexts(array $tokens): void {}

  /**
   * {@inheritdoc}
   */
  public function getSystemPrompt() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getToolResults(bool $recursive = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getToolResultsByPluginId(string $plugin_id, bool $recursive = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getToolResultsByClassName(string $class_name, bool $recursive = FALSE): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setProgressThreadId($progressThreadId) {}

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {}

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

}
