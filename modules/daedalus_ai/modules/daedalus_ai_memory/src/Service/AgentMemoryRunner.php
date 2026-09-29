<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Service\HostnameFilter;
use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\daedalus_ai_memory\FailureReason;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\AiAssistantInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorator for AgentRunner that persists agent state across turns.
 *
 * The default AgentRunner deletes serialized state when an agent finishes,
 * causing full context loss between conversation turns. This decorator
 * uses a separate temp store bin that survives across turns, allowing
 * the LLM to see previous tool results and avoid redundant calls.
 *
 * Only agents listed in the enabled_agents config get persistent state;
 * all others delegate directly to the inner AgentRunner.
 */
class AgentMemoryRunner extends AgentRunner {

  use LoggerChannelTrait;

  /**
   * Temp store bin for persistent agent threads.
   */
  private const TEMP_STORE_BIN = 'daedalus_ai_memory_threads';

  /**
   * Constructs AgentMemoryRunner.
   *
   * @param \Drupal\ai_assistant_api\Service\AgentRunner $inner
   *   The original AgentRunner service.
   * @param \Drupal\ai\AiProviderPluginManager $aiProvider
   *   The AI provider plugin manager.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStore
   *   The private temp store factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\daedalus_ai_memory\Service\ChatHistoryTrimmer $historyTrimmer
   *   The chat history trimmer.
   * @param \Drupal\daedalus_ai_memory\Service\ChatMessageFileStripper $fileStripper
   *   The chat message file stripper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai\Service\HostnameFilter $hostnameFilter
   *   The ai module's hostname filter, scoped inert around rehydration for
   *   agents whose config disables transport hostname filtering.
   * @param mixed $aiAgentPluginManager
   *   The AI agent plugin manager if it exists.
   */
  public function __construct(
    protected readonly AgentRunner $inner,
    AiProviderPluginManager $aiProvider,
    PrivateTempStoreFactory $tempStore,
    EventDispatcherInterface $eventDispatcher,
    protected ConfigFactoryInterface $configFactory,
    protected ChatHistoryTrimmer $historyTrimmer,
    protected ChatMessageFileStripper $fileStripper,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HostnameFilter $hostnameFilter,
    mixed $aiAgentPluginManager = NULL,
  ) {
    parent::__construct($aiProvider, $tempStore, $eventDispatcher, $aiAgentPluginManager);
  }

  /**
   * {@inheritdoc}
   */
  public function runAsAgent(string $assistant_id, array $chat_history, array $defaults, string $job_id, bool $verbose_mode = FALSE, array $context = []): ChatOutput {
    $enabled = $this->configFactory->get('daedalus_ai_memory.settings')->get('enabled_agents') ?: [];
    if (!in_array($assistant_id, $enabled, TRUE)) {
      return $this->inner->runAsAgent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
    }

    return $this->runPersistent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
  }

  /**
   * Runs an agent with state persisted across conversation turns.
   *
   * @param string $assistant_id
   *   The assistant/agent plugin ID.
   * @param array $chat_history
   *   The chat history from the session (plain text messages only).
   * @param array $defaults
   *   Provider/model defaults.
   * @param string $job_id
   *   The job id.
   * @param bool $verbose_mode
   *   Whether to run in verbose mode.
   * @param array $context
   *   The context from the assistant.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  private function runPersistent(string $assistant_id, array $chat_history, array $defaults, string $job_id, bool $verbose_mode, array $context): ChatOutput {
    $logger = $this->getLogger('daedalus_ai_memory');
    $store = $this->tempStore->get(self::TEMP_STORE_BIN);
    $persisted = $store->get($job_id);

    $state = AgentState::detect($persisted, count($chat_history));

    if ($state === AgentState::Stale) {
      $logger->notice('Stale state for job @job_id (session=@count, processed=@processed). Discarding.', [
        '@job_id' => $job_id,
        '@count' => count($chat_history),
        '@processed' => $persisted['processed_message_count'] ?? 0,
      ]);
      $store->delete($job_id);
      $persisted = NULL;
      $state = AgentState::Fresh;
    }

    try {
      /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent */
      $agent = $this->aiAgentPluginManager->createInstance($assistant_id);
      // Parity with the upstream AgentRunner: the job id IS the
      // conversation's stable identity, and consumers key per-conversation
      // state on getRunnerId() (e.g. daedalus_blueprint's dialect alias
      // layer). The restore paths never set it, so stamp it here on every
      // state.
      $agent->setRunnerId($job_id);
      $agent->setProgressThreadId($job_id);

      // Rehydration re-runs the transport filter: fromArray() rebuilds every
      // historical tool call as a fresh ToolsFunctionOutput, whose property
      // results run each string argument through HostnameFilter::filterText()
      // with settings defaults — the same filtering the provider proxy
      // applies around an invocation, but with no per-call DTO in scope. The
      // tools a poll executes are exactly these rebuilt objects, so for an
      // agent whose config disables transport filtering (the
      // hostname_filter_disabled knob AiAgentEntityWrapper honors per
      // request), an unscoped rehydration would silently rewrite the very
      // arguments the flag promises byte-intact (it strips any style
      // attribute containing url(), the page language's media/generate
      // grammar). Scope it the way upstream scopes the provider call:
      // snapshot, full trust, restore in finally.
      $agent_entity = $this->entityTypeManager->getStorage('ai_agent')->load($assistant_id);
      $filter_snapshot = NULL;
      if ($agent_entity?->get('hostname_filter_disabled') && $state !== AgentState::Fresh) {
        $filter_snapshot = $this->hostnameFilter->snapshotSettings();
        $this->hostnameFilter->applySettings(new HostnameFilterDto(fullTrust: TRUE));
      }
      try {
        match ($state) {
          AgentState::Fresh => $this->initializeFresh($agent, $chat_history, $defaults, $verbose_mode, $logger),
          AgentState::CrossTurn => $this->restoreForCrossTurn($agent, $persisted, $chat_history, $verbose_mode, $logger),
          AgentState::SameTurn => $this->restoreForSameTurn($agent, $persisted, $verbose_mode, $logger),
          AgentState::Stale => throw new \LogicException('Stale state should have been resolved to Fresh.'),
        };
      }
      finally {
        if ($filter_snapshot !== NULL) {
          $this->hostnameFilter->restoreSettings($filter_snapshot);
        }
      }

      $this->applyProviderConfiguration($agent, $assistant_id, $defaults);

      $event = new AiAssistantPassContextToAgentEvent($agent, $context);
      $this->eventDispatcher->dispatch($event, AiAssistantPassContextToAgentEvent::EVENT_NAME);

      $solvability = $agent->determineSolvability();

      if ($solvability === AiAgentInterface::JOB_NOT_SOLVABLE) {
        $logger->warning('Agent returned JOB_NOT_SOLVABLE for job @job_id.', ['@job_id' => $job_id]);
      }

      // The files on this leg's messages have been delivered; drop the bytes
      // before the record is persisted so the tempstore holds no binaries and
      // no later turn pays to re-send them. The message text, and with it the
      // ATTACHMENTS block naming each file by its handle, survives; view_file
      // re-opens a handle on demand.
      $this->fileStripper->stripFiles($agent);
      $this->trimAndPersist($agent, $store, $job_id, count($chat_history), $solvability);

      return $this->buildResponse($agent, $assistant_id, $logger);
    }
    catch (\Throwable $e) {
      $logger->error('Agent turn failed for job @job_id (state=@state, provider=@provider): @class: @message' . PHP_EOL . '@trace', [
        '@job_id' => $job_id,
        '@state' => $state->name,
        '@provider' => $defaults['provider_id'] ?? '(unresolved)',
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      // Drop the persisted record so a corrupt/poison state cannot make every
      // subsequent turn fail; the next user message starts fresh. This is
      // error recovery, distinct from the "never delete on finish" rule that
      // governs normal completion.
      $store->delete($job_id);
      return $this->failureReply($assistant_id, FailureReason::distill($e->getMessage()), $e);
    }
  }

  /**
   * Applies the LLM configuration to the agent's provider instance.
   *
   * The assistant's llm_configuration (max_tokens, temperature, ...) is dead
   * on the upstream agent path: AiAssistantApiRunner::getProviderAndModel()
   * returns only provider/model ids, and AiAgentEntityWrapper never copies
   * its aiConfiguration onto the provider, so every request runs on the
   * provider module's api defaults (e.g. Anthropic's max_tokens 4096).
   * fromArray() also recreates the provider bare, so this must run on every
   * turn state, not just the fresh one.
   *
   * The caller's defaults win when they carry a configuration; otherwise the
   * configuration comes from the assistant entity fronting this agent.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance, with its provider already set.
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to (the runner receives
   *   the assistant's ai_agent value here).
   * @param array $defaults
   *   Provider/model defaults from the caller.
   */
  private function applyProviderConfiguration(AiAgentInterface $agent, string $assistant_id, array $defaults): void {
    $configuration = $defaults['configuration'] ?? [];
    if (!$configuration && ($assistant = $this->frontingAssistant($assistant_id))) {
      $configuration = array_filter(
        $assistant->get('llm_configuration') ?? [],
        static fn ($value) => $value !== NULL && $value !== '',
      );
    }
    if (!$configuration) {
      return;
    }
    $provider = $agent->getAiProvider();
    $provider->setConfiguration($configuration + $provider->getConfiguration());
    $agent->setAiConfiguration($configuration);
  }

  /**
   * Loads the assistant entity fronting an agent.
   *
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to.
   *
   * @return \Drupal\ai_assistant_api\AiAssistantInterface|null
   *   The assistant, or NULL when no assistant fronts this agent.
   */
  private function frontingAssistant(string $assistant_id): ?AiAssistantInterface {
    $assistants = $this->entityTypeManager->getStorage('ai_assistant')->loadByProperties(['ai_agent' => $assistant_id]);
    return reset($assistants) ?: NULL;
  }

  /**
   * Initializes an agent from scratch for a new conversation.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param array $chat_history
   *   The chat history from the session.
   * @param array $defaults
   *   Provider/model defaults.
   * @param bool $verbose_mode
   *   Whether to run in verbose mode.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  private function initializeFresh(AiAgentInterface $agent, array $chat_history, array $defaults, bool $verbose_mode, LoggerInterface $logger): void {
    $logger->notice('Fresh start for job. Session has @count messages.', [
      '@count' => count($chat_history),
    ]);

    $messages = array_map(
      fn(array $m) => new ChatMessage($m['role'], $m['message']),
      $chat_history,
    );

    if (empty($defaults['provider_id'])) {
      throw new \RuntimeException('No chat AI provider could be resolved. Set a default chat provider at ai.settings (default_providers.chat) or set the assistant\'s llm_provider.');
    }

    $agent->setChatInput(new ChatInput($messages, []));
    $agent->setAiProvider($this->aiProvider->createInstance($defaults['provider_id']));
    $agent->setModelName($defaults['model_id']);
    $agent->setCreateDirectly(TRUE);

    if ($verbose_mode) {
      $agent->setLooped(FALSE);
    }
  }

  /**
   * Restores an agent for a new turn after a previous turn completed.
   *
   * Resets execution counters and appends only the new user messages
   * that arrived since the last turn.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param array $persisted
   *   The persisted state from tempstore.
   * @param array $chat_history
   *   The chat history from the session.
   * @param bool $verbose_mode
   *   Whether to run in verbose mode.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  private function restoreForCrossTurn(AiAgentInterface $agent, array $persisted, array $chat_history, bool $verbose_mode, LoggerInterface $logger): void {
    $logger->notice('Cross-turn continuation.');

    $agent_data = $persisted['agent_data'];
    $agent_data['looped'] = 0;
    $agent_data['context_tools'] = [];
    $agent_data['tool_results'] = [];
    $agent_data['agent_results'] = [];

    $agent->fromArray($agent_data);

    if ($verbose_mode) {
      $agent->setLooped(FALSE);
    }

    $processed = $persisted['processed_message_count'] ?? 0;
    $new_messages = array_slice($chat_history, $processed);
    $existing_history = $agent->getChatHistory();
    foreach ($new_messages as $message) {
      if ($message['role'] === 'user') {
        $existing_history[] = new ChatMessage('user', $message['message']);
      }
    }
    $agent->setChatHistory($existing_history);
  }

  /**
   * Restores an agent mid-execution for frontend polling.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param array $persisted
   *   The persisted state from tempstore.
   * @param bool $verbose_mode
   *   Whether to run in verbose mode.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  private function restoreForSameTurn(AiAgentInterface $agent, array $persisted, bool $verbose_mode, LoggerInterface $logger): void {
    $agent_data = $persisted['agent_data'];
    $agent_data['tool_results'] = [];
    $agent_data['agent_results'] = [];

    $logger->notice('Same-turn poll (looped=@looped, context_tools=@ct).', [
      '@looped' => $agent_data['looped'] ?? 0,
      '@ct' => count($agent_data['context_tools'] ?? []),
    ]);

    $agent->fromArray($agent_data);

    if ($verbose_mode) {
      $agent->setLooped(FALSE);
    }
  }

  /**
   * Trims chat history and persists agent state to tempstore.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param \Drupal\Core\TempStore\PrivateTempStore $store
   *   The tempstore instance.
   * @param string $job_id
   *   The job id.
   * @param int $session_message_count
   *   The number of messages in the current session.
   * @param int $solvability
   *   The solvability result from determineSolvability().
   */
  private function trimAndPersist(AiAgentInterface $agent, $store, string $job_id, int $session_message_count, int $solvability): void {
    $agent->setChatHistory(
      $this->historyTrimmer->trim($agent->getChatHistory()),
    );

    $store->set($job_id, [
      'agent_data' => $agent->toArray(),
      'processed_message_count' => $session_message_count,
      'finished' => $solvability === AiAgentInterface::JOB_NOT_SOLVABLE || $agent->isFinished(),
    ]);
  }

  /**
   * Builds a ChatOutput from the agent's current state.
   *
   * Guards against echoing the user's own message back when the agent
   * produces no response: the history then still ends on the user's line,
   * and the reply is the failure the agent recorded for it (a provider
   * rejection such as an image over the API's size limit) or, when it
   * recorded none, the plain fact that no reply was produced.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  private function buildResponse(AiAgentInterface $agent, string $assistant_id, LoggerInterface $logger): ChatOutput {
    $response = $agent->solve() ?? '';

    if ($history = $agent->getChatHistory()) {
      $last = end($history);
      if ($last->getRole() === 'user') {
        $reason = $this->recordedFailure($agent) ?? 'The assistant produced no reply.';
        $logger->warning('Agent produced no response for the last user message: @reason', ['@reason' => $reason]);
        return $this->failureReply($assistant_id, $reason);
      }
      return new ChatOutput($last, [$response], []);
    }

    return new ChatOutput(new ChatMessage('assistant', $response), [$response], []);
  }

  /**
   * Returns the failure the agent recorded for its last provider call.
   *
   * AiAgentEntityWrapper catches the provider call's exception, keeps its
   * message and answers JOB_NOT_SOLVABLE with an empty reply; the message is
   * reachable through getLastError(), which ai_agents keeps on the wrapper
   * class rather than on any agent interface, so it is read by presence.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   *
   * @return string|null
   *   The distilled failure, or NULL when the agent recorded none.
   */
  private function recordedFailure(AiAgentInterface $agent): ?string {
    if (!method_exists($agent, 'getLastError')) {
      return NULL;
    }
    $raw = $agent->getLastError();
    return is_string($raw) && trim($raw) !== '' ? FailureReason::distill($raw) : NULL;
  }

  /**
   * Builds the assistant's reply for a turn that failed.
   *
   * The wording is the assistant entity's own error template (ai_assistant_api's
   * `error_message`, or the `specific_error_messages` entry for the
   * exception's class when one is set), with its `[error_message]` token
   * replaced by the reason, so a site phrases failures once for every road.
   * Without an assistant, or with an empty template, the reason stands alone.
   *
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to.
   * @param string $reason
   *   The distilled reason the turn failed.
   * @param \Throwable|null $exception
   *   The exception that ended the turn, when one did.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output carrying the reply.
   */
  private function failureReply(string $assistant_id, string $reason, ?\Throwable $exception = NULL): ChatOutput {
    $template = '';
    if ($assistant = $this->frontingAssistant($assistant_id)) {
      $specific = $exception ? ($assistant->get('specific_error_messages')[(new \ReflectionClass($exception))->getShortName()] ?? '') : '';
      $template = $specific !== '' ? $specific : (string) $assistant->get('error_message');
    }
    $text = $template === '' ? $reason : str_replace('[error_message]', $reason, $template);
    return new ChatOutput(new ChatMessage('assistant', $text), [$text], []);
  }

}
