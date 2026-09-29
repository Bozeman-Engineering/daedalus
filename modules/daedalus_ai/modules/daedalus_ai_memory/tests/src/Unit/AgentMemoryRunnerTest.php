<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_agents\Output\StructuredResultDataInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\ai_agents\Task\TaskInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Service\HostnameFilter;
use Drupal\daedalus_ai_memory\Service\AgentMemoryRunner;
use Drupal\daedalus_ai_memory\Service\ChatHistoryTrimmer;
use Drupal\daedalus_ai_memory\Service\ChatMessageFileStripper;
use Drupal\ai_agents\AiAgentInterface as AiAgentEntityInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_assistant_api\AiAssistantInterface;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Pins the AgentMemoryRunner branches that integration coverage cannot reach.
 *
 * AgentMemoryRunner is a thin orchestrator: it decides whether an agent gets
 * persistent state at all, restores or initializes the agent, and turns the
 * agent's output into a ChatOutput while refusing to echo the user's own text
 * back. Three of those decisions carry the module's headline guarantees but are
 * not observable from the assembled-stack Functional test (AgentMemoryCrossTurn
 * Test), which only ever drives the happy persistent path of a real agent:
 *
 *  - the enabled_agents allow-list (delegate verbatim when not opted in),
 *  - the catch block (drop the poison record, reply with the failure's
 *    reason, never rethrow and never replay the user's message),
 *  - the buildResponse echo-guard (if the agent's last history line is from the
 *    user, reply with the failure the agent recorded, or the plain fact that
 *    no reply was produced, instead of echoing the prompt back),
 *  - the failure reply's wording (the fronting assistant's error template
 *    with its [error_message] token carrying the reason).
 *
 * Each of those is pure orchestration over injected collaborators, so this is a
 * container-free unit test: the runner is constructed directly with test
 * doubles and the doubles' expectations encode the contract. The state-machine
 * decision itself (Fresh/Stale/CrossTurn/SameTurn) is AgentState's job and is
 * covered by AgentStateTest; this test deliberately does NOT re-test that, nor
 * does it re-test the trimmer/stripper (covered by their own unit tests) - it
 * only verifies that the runner wires those decisions together correctly.
 *
 * The runner reads its logger via LoggerChannelTrait::getLogger(), which would
 * otherwise reach for the global container; setLoggerFactory() injects a stub
 * so no container bootstrap is needed.
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
   * Delegation: a non-enabled agent never touches persistence machinery.
   *
   * This is the module's surgical opt-in guarantee. If the enabled_agents check
   * regressed (inverted, dropped, or persistence ran unconditionally), every
   * agent would silently acquire cross-turn state and the "only enabled agents
   * get persistence" design would be broken. The test proves the negative two
   * ways for an agent absent from the allow-list: (1) the inner runner is
   * called exactly once with byte-identical arguments and its ChatOutput is
   * returned unchanged (a verbatim pass-through), and (2) the persistence
   * collaborators are never even consulted - the tempstore factory's get() is
   * never called and the agent plugin manager's createInstance() is never
   * called. It runs the guard for both an empty allow-list and a non-empty list
   * that omits the agent, since "not in the list" must hold in both shapes.
   */
  public function testNonEnabledAgentDelegatesVerbatim(): void {
    foreach ([[], ['some_other_agent']] as $enabled) {
      $chat_history = [['role' => 'user', 'message' => 'Hi there']];
      $defaults = ['provider_id' => 'prompt_capture', 'model_id' => 'capture'];
      $context = ['page' => 'front'];
      $expected = new ChatOutput(new ChatMessage('assistant', 'delegated reply'), [''], []);

      $inner = $this->createMock(AgentRunner::class);
      // The decorator must forward EXACTLY what it received, in order, and hand
      // back the inner runner's output without inspecting or wrapping it.
      $inner->expects($this->once())
        ->method('runAsAgent')
        ->with('some_agent', $chat_history, $defaults, 'job-1', TRUE, $context)
        ->willReturn($expected);

      // Persistence machinery must be untouched on the delegate path.
      $tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
      $tempStoreFactory->expects($this->never())->method('get');
      $pluginManager = $this->createMock(PluginManagerInterface::class);
      $pluginManager->expects($this->never())->method('createInstance');

      $runner = new AgentMemoryRunner(
        $inner,
        $this->realAiProviderManager(),
        $tempStoreFactory,
        $this->createMock(EventDispatcherInterface::class),
        $this->configFactoryWithEnabledAgents($enabled),
        $this->createMock(ChatHistoryTrimmer::class),
        $this->createMock(ChatMessageFileStripper::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(HostnameFilter::class),
        $pluginManager,
      );
      $runner->setLoggerFactory($this->stubLoggerFactory());

      $result = $runner->runAsAgent('some_agent', $chat_history, $defaults, 'job-1', TRUE, $context);

      $this->assertSame(
        $expected,
        $result,
        'A non-enabled agent must return the inner runner output unchanged.',
      );
    }
  }

  /**
   * Error recovery: a thrown turn drops the poison record, degrades gracefully.
   *
   * This is the "fail visibly, drop poison state" contract. Without it a single
   * corrupt persisted record would make every subsequent turn for that job
   * throw forever, and a provider outage would surface to the user as an
   * uncaught exception instead of a message. The test makes the agent plugin
   * manager throw while a record already exists in the bin, then asserts the
   * runner (1) deletes exactly that job's record so the next turn can start
   * clean, and (2) returns a ChatOutput carrying the failure's reason - NOT a
   * rethrow (the call returns normally) and NOT the user's own text. The
   * thrown point is createInstance() because it is the first thing inside the
   * try block, which proves the catch wraps the whole run rather than a single
   * late step. No assistant fronts the agent here, so the reason stands alone.
   */
  public function testFailedTurnDeletesRecordAndRepliesWithTheReason(): void {
    $job_id = 'poison-job';

    $store = $this->createMock(PrivateTempStore::class);
    // A non-null record is what makes the deletion meaningful: there is real
    // poison state to drop.
    $store->method('get')->with($job_id)->willReturn([
      'agent_data' => ['chat_history' => []],
      'processed_message_count' => 1,
      'finished' => TRUE,
    ]);
    $store->expects($this->once())->method('delete')->with($job_id);
    // The error path must never write new state.
    $store->expects($this->never())->method('set');

    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')
      ->with(self::ENABLED_AGENT)
      ->willThrowException(new \RuntimeException('provider exploded'));

    $runner = $this->createRunner($store, $pluginManager);

    // The agent had finished, so a session that has NOT grown is the Stale
    // path; a session that HAS grown (2 > processed 1) is the CrossTurn path.
    // Either way createInstance() throws, so use the grown form to exercise the
    // catch from inside the try after a non-Stale detect.
    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [
        ['role' => 'user', 'message' => 'first'],
        ['role' => 'user', 'message' => 'second'],
      ],
      ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
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
   * is clearer and more robust than stubbing each one. The trimmer and stripper
   * are mocked to no-ops because their behavior is covered by their own unit
   * tests; here they must simply not interfere.
   *
   * The test drives the SameTurn restore path (a persisted record with finished
   * = FALSE and no new messages) rather than the Fresh path on purpose:
   * SameTurn restores via fromArray() and never resolves an AI provider, so the
   * echo-guard - which lives in buildResponse() and is independent of how the
   * agent was initialized - is exercised without needing a working provider
   * plugin manager (the concrete class is final and cannot be doubled).
   */
  public function testTrailingUserMessageReturnsErrorNotEcho(): void {
    $job_id = 'echo-job';

    // SameTurn path: a persisted, not-yet-finished record with no new session
    // messages, so detect() restores it as-is and the agent runs to
    // buildResponse() without ever resolving an AI provider.
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn([
      'agent_data' => ['chat_history' => []],
      'processed_message_count' => 1,
      'finished' => FALSE,
    ]);
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
      ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
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
   * thread as usual (the conversation survives a rejected attachment; the
   * stripper has already dropped the bytes) and reply with the sentence
   * distilled from the recorded message, not a fixed apology and not the raw
   * SDK dump. The fake records the Anthropic provider's message for an image
   * over the API's dimension limit.
   */
  public function testRecordedProviderFailureRepliesWithItsReason(): void {
    $job_id = 'rejected-job';

    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->with($job_id)->willReturn([
      'agent_data' => ['chat_history' => []],
      'processed_message_count' => 1,
      'finished' => FALSE,
    ]);
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
      ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
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
   * exception class, both carrying an [error_message] token). The runner renders the same template
   * so every road reads alike: a recorded provider failure takes the default
   * template, a thrown turn the entry for its exception's short class name
   * when one is set. Both are pinned here through one assistant double.
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
    $store->method('get')->willReturn([
      'agent_data' => ['chat_history' => []],
      'processed_message_count' => 1,
      'finished' => FALSE,
    ]);
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->willReturn(new FakeEchoAgent(self::PROVIDER_FAILURE));
    $runner = $this->createRunner($store, $pluginManager, NULL, NULL, $assistant);
    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [['role' => 'user', 'message' => 'x']],
      ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
      'templated-job',
      FALSE,
      [],
    );
    $this->assertSame(
      'Sorry, I could not process that: ' . self::PROVIDER_REASON,
      $result->getNormalized()->getText(),
    );

    // A thrown turn: the entry for the exception's class wins.
    $store = $this->createMock(PrivateTempStore::class);
    $store->method('get')->willReturn(NULL);
    $pluginManager = $this->createMock(PluginManagerInterface::class);
    $pluginManager->method('createInstance')->willThrowException(new \RuntimeException('provider exploded'));
    $runner = $this->createRunner($store, $pluginManager, NULL, NULL, $assistant);
    $result = $runner->runAsAgent(
      self::ENABLED_AGENT,
      [['role' => 'user', 'message' => 'x']],
      ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
      'thrown-job',
      FALSE,
      [],
    );
    $this->assertSame(
      'The site broke: provider exploded',
      $result->getNormalized()->getText(),
    );
  }

  /**
   * A transport-inert agent's rehydration is scoped full trust.
   *
   * AiAgentEntityWrapper::fromArray() rebuilds every persisted tool call as
   * a fresh ToolsFunctionOutput, which re-runs each string argument through
   * HostnameFilter::filterText() with settings defaults (no per-call DTO is
   * in scope at rehydration time), and the rebuilt objects are exactly the
   * tools the poll then executes. For an agent whose config carries
   * hostname_filter_disabled (the knob the wrapper honors around its own
   * provider call), the runner must scope the restore the same way upstream
   * scopes the invocation: snapshot the singleton, apply a full-trust DTO,
   * restore in finally. Otherwise the transport silently rewrites the very
   * tool arguments the flag promises byte-intact (the live symptom: any
   * style attribute containing url() stripped from a page_create layout).
   * Pinned both ways on the SameTurn restore path: the flagged agent gets
   * snapshot/full-trust-apply/restore around its run, and an unflagged
   * agent's rehydration never touches the filter's programmatic state.
   */
  public function testTransportInertAgentScopesRehydrationFullTrust(): void {
    foreach ([TRUE, FALSE] as $flag) {
      $store = $this->createMock(PrivateTempStore::class);
      $store->method('get')->willReturn([
        'agent_data' => ['chat_history' => []],
        'processed_message_count' => 1,
        'finished' => FALSE,
      ]);

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
      $runner->runAsAgent(
        self::ENABLED_AGENT,
        [['role' => 'user', 'message' => 'continue']],
        ['provider_id' => 'prompt_capture', 'model_id' => 'capture'],
        'inert-job',
        FALSE,
        [],
      );
    }
  }

  /**
   * Builds a runner wired for the persistent path (the agent IS enabled).
   *
   * Shared by the error-recovery and echo-guard tests: both need enabled_agents
   * to contain the agent so runPersistent() runs, a tempstore factory that
   * yields the supplied store mock for the threads bin, and a stub logger so
   * the trait's getLogger() never reaches the container.
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
   *
   * @return \Drupal\daedalus_ai_memory\Service\AgentMemoryRunner
   *   The runner under test.
   */
  private function createRunner(PrivateTempStore $store, PluginManagerInterface $pluginManager, ?HostnameFilter $hostnameFilter = NULL, ?object $agentEntity = NULL, ?AiAssistantInterface $assistant = NULL): AgentMemoryRunner {
    $tempStoreFactory = $this->createMock(PrivateTempStoreFactory::class);
    $tempStoreFactory->method('get')
      ->with('daedalus_ai_memory_threads')
      ->willReturn($store);

    // The runner resolves the fronting assistant for its llm_configuration
    // and its error template; most tests run without one, so the lookup
    // legitimately finds nothing (loadByProperties has no declared return
    // type, so the mock must be told to return an array or reset() throws).
    // It also loads the agent config entity to read the
    // hostname_filter_disabled flag before rehydration.
    $assistantStorage = $this->createMock(EntityStorageInterface::class);
    $assistantStorage->method('loadByProperties')->willReturn($assistant ? [$assistant] : []);
    $agentStorage = $this->createMock(EntityStorageInterface::class);
    $agentStorage->method('load')->with(self::ENABLED_AGENT)->willReturn($agentEntity);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['ai_assistant', $assistantStorage],
        ['ai_agent', $agentStorage],
      ]);

    $runner = new AgentMemoryRunner(
      $this->createMock(AgentRunner::class),
      $this->realAiProviderManager(),
      $tempStoreFactory,
      $this->createMock(EventDispatcherInterface::class),
      $this->configFactoryWithEnabledAgents([self::ENABLED_AGENT]),
      $this->createMock(ChatHistoryTrimmer::class),
      $this->createMock(ChatMessageFileStripper::class),
      $entityTypeManager,
      $hostnameFilter ?? $this->createMock(HostnameFilter::class),
      $pluginManager,
    );
    $runner->setLoggerFactory($this->stubLoggerFactory());

    return $runner;
  }

  /**
   * A config factory whose daedalus_ai_memory.settings.enabled_agents is fixed.
   *
   * @param array $enabled
   *   The enabled_agents list to return.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory double.
   */
  private function configFactoryWithEnabledAgents(array $enabled): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('enabled_agents')->willReturn($enabled);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('daedalus_ai_memory.settings')->willReturn($config);

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
   * AiProviderPluginManager, so it cannot be doubled. None of these tests reach
   * its createInstance() (the delegate path returns first, the error path
   * throws at the agent manager, and the echo-guard uses the SameTurn restore
   * path that never resolves a provider), so a genuine instance built from
   * mocked collaborators satisfies the type without doing any plugin discovery.
   *
   * @return \Drupal\ai\AiProviderPluginManager
   *   The provider manager instance.
   */
  private function realAiProviderManager(): AiProviderPluginManager {
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
        $this->createMock(ConfigFactoryInterface::class),
      ],
    ]);

    return new AiProviderPluginManager(
      new \ArrayObject(),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $container,
      $this->createMock(MessengerInterface::class),
      $this->createMock(UuidInterface::class),
      $this->createMock(HostnameFilter::class),
    );
  }

}

/**
 * A minimal real agent whose final chat-history message is from the user.
 *
 * This exercises the buildResponse() echo-guard: the agent "succeeds" (solve()
 * returns a value, determineSolvability() reports solvable) yet its history
 * ends with a user message, which is the empty-response condition the guard
 * must catch. It is a hand-written fake rather than a mock so the many methods
 * the Fresh path calls have plain, predictable behavior; only the methods the
 * runner actually invokes do anything meaningful, the rest satisfy the
 * interface.
 */
class FakeEchoAgent implements ConfigAiAgentInterface {

  /**
   * The current chat history, ending on a user message by design.
   *
   * @var array
   */
  private array $chatHistory = [];

  /**
   * Constructs the fake.
   *
   * @param string|null $lastError
   *   The failure the agent recorded for its provider call, as
   *   AiAgentEntityWrapper::getLastError() would report it, or NULL for a
   *   turn that recorded none.
   */
  public function __construct(private readonly ?string $lastError = NULL) {}

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
    return $this->lastError === NULL ? AiAgentInterface::JOB_SOLVABLE : AiAgentInterface::JOB_NOT_SOLVABLE;
  }

  /**
   * {@inheritdoc}
   */
  public function solve() {
    return 'an assistant answer that must be suppressed';
  }

  /**
   * {@inheritdoc}
   */
  public function getChatHistory(): array {
    // Deliberately ends on a user message so the echo-guard must fire.
    return [
      new ChatMessage('assistant', 'earlier assistant turn'),
      new ChatMessage('user', 'echo me back'),
    ];
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
    return ['chat_history' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function fromArray(array $data): void {}

  /**
   * {@inheritdoc}
   */
  public function setProviderTags(array $tags): void {}

  /**
   * {@inheritdoc}
   */
  public function getProviderTags(): array {
    return [];
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
   */
  public function setAiProvider($aiProvider) {}

  /**
   * {@inheritdoc}
   */
  public function getAiProvider() {}

  /**
   * {@inheritdoc}
   */
  public function setModelName($modelName) {}

  /**
   * {@inheritdoc}
   */
  public function getModelName() {
    return 'capture';
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
    return 'fake';
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
  public function setRunnerId($runnerId) {}

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
