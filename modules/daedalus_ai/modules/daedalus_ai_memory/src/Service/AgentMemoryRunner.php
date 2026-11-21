<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Dto\HostnameFilterDto;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Service\HostnameFilter;
use Drupal\ai_agents\Event\AgentRequestEvent;
use Drupal\ai_agents\Event\AgentResponseEvent;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_assistant_api\AiAssistantInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\ai_assistant_api\Service\AgentRunner;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Context\ContextBlocksEvent;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\ModelChoiceOption;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\daedalus_ai_memory\FailureReason;
use Drupal\daedalus_ai_memory\Segment\CarryOverGenerator;
use Drupal\daedalus_ai_memory\Segment\CloseReason;
use Drupal\daedalus_ai_memory\Segment\LastLeg;
use Drupal\daedalus_ai_memory\Segment\SegmentPolicy;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\EntryType;
use Drupal\daedalus_ai_memory\Transcript\Prefix;
use Drupal\daedalus_ai_memory\Transcript\Rendering;
use Drupal\daedalus_ai_memory\Transcript\Segment;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\daedalus_ai_memory\Transcript\TranscriptRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Decorator for AgentRunner that runs an agent on the context spine.
 *
 * The default AgentRunner deletes serialized state when an agent finishes,
 * so every turn starts the model over. This decorator keeps, per
 * conversation (the job id), the transcript (the provider-neutral,
 * append-only model of the model-facing history, held in segments) and the
 * wrapper's non-history state in a temp store bin that survives turns.
 *
 * Per request it classifies the state by the identity of the session's
 * last user message (Fresh, SameTurn, CrossTurn, Stale), refuses the turn
 * through the assistant's error template when the usage gate says a cap
 * is reached, appends the user's turn with the blocks the producers state
 * for it, and drives the wrapper's legs: before each leg the transcript's
 * current segment is rendered into the wrapper's history and the leg's
 * input (with the rendering plan for a capable provider), the leg is
 * tagged with its segment, and the prefix (system prompt and tools) is
 * hashed so a leg whose prefix changed is tagged with
 * UsageLedger::PREFIX_DRIFT_TAG for the ledger row; after each leg what
 * the wrapper appended is absorbed, and the segment policy decides
 * whether the segment closes (a carry-over is generated over the same
 * prefix and the next segment opens). Nothing already sent in a segment
 * is ever edited.
 *
 * Bytes reach the model once, by two roads over one placement: the files
 * delivered with the user's turn (the context blocks event's) ride the
 * turn's first leg, and the images a tool handed back (the screenshots
 * message ai_agents' wrapper interjects as a user message at the start of
 * the leg after the tool) are lifted off the absorbed slice and ride that
 * leg; either way the renderer puts them on the rendering's newest user
 * message, which is the message that carried them. The transcript records
 * the message and never the bytes, so the leg after renders without them
 * and `view_file` is the road back.
 *
 * A turn ends at the provider's hard line before the provider refuses: when
 * the leg a request just ran measures past the hard limit (the smallest
 * bound the provider declares less the headroom) and the agent has not
 * finished, the runner ends the turn through the assistant's failure
 * template, leaves the model's pending tool calls unexecuted (they run at
 * the start of the next poll, and the reply carries no tools, so there is
 * none), and closes the segment with the carry-over while the prefix is
 * still cached and fits, the headroom being the margin. Decided at the
 * end of each request, so it bites where the front end runs one leg per
 * request (the sidebar); a request that runs the whole loop ends finished
 * or at max_loops and the check is a no-op.
 *
 * The record survives a failed turn. Reading the record (the transcript
 * and the wrapper's state) is its own guarded step: a record that cannot be
 * read is the one thing that is ever deleted, and the request then runs
 * fresh once. Any failure after that keeps the record and persists nothing,
 * so the stored record is the last good state: the next turn builds on it
 * and settles an assistant entry's pending calls as unanswered. A
 * carry-over call the provider refuses leaves the segment open, the turn
 * unharmed. The record is the agent's memory, and the no-forgetting rule
 * covers the error path too. When a fresh start does replay earlier
 * messages of the visible chat (the record expired a week idle, or was
 * unreadable), the turn's first leg is tagged with
 * UsageLedger::REBUILT_TAG so the ledger row keeps it and the status report
 * counts the rebuilt conversations.
 *
 * The provider and model a turn runs on resolve in this order: the user's
 * valid model choice (ModelChoice::current(): the account holds the
 * permission and chose a curated pair), else the caller's defaults (the
 * assistant's llm_provider, else ai.settings). The choice is read at a
 * turn's first leg only (Fresh and CrossTurn): a poll (SameTurn) continues
 * on the pair the record carries, since a turn's legs must share one
 * prefix. The chosen pair is persisted with the wrapper's state, which is
 * how the polls latch onto it; the defaults stay the fallback and the
 * fresh start is initialized from them as before.
 *
 * Only agents listed in the enabled_agents config run here; all others
 * delegate directly to the inner AgentRunner.
 */
class AgentMemoryRunner extends AgentRunner {

  use LoggerChannelTrait;

  /**
   * Temp store bin for persistent agent threads.
   */
  private const TEMP_STORE_BIN = 'daedalus_ai_memory_threads';

  /**
   * The module's settings.
   */
  public const SETTINGS = 'daedalus_ai_memory.settings';

  /**
   * The reason a turn ended at the hard line, worded for the editor.
   *
   * Answered through the assistant's failure template in place of the
   * model's reply when the leg just run measured past the hard limit with
   * the turn unfinished.
   */
  public const TURN_BOUNDED_REASON = "This turn reached the model's context limit before it finished. What was established so far has been carried forward; your next message continues from there.";

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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\ai\Service\HostnameFilter $hostnameFilter
   *   The ai module's hostname filter, scoped inert around a rendering for
   *   agents whose config disables transport hostname filtering.
   * @param \Drupal\daedalus_ai_memory\Transcript\TranscriptRenderer $renderer
   *   The transcript renderer.
   * @param \Drupal\daedalus_ai_memory\Segment\SegmentPolicy $segmentPolicy
   *   The segment policy.
   * @param \Drupal\daedalus_ai_memory\Segment\CarryOverGenerator $carryOverGenerator
   *   The carry-over generator.
   * @param \Drupal\daedalus_ai_ledger\UsageLedger $ledger
   *   The usage ledger, for the segment's measurement.
   * @param \Drupal\daedalus_ai_ledger\UsageGate $usageGate
   *   The usage gate, checked before any model call.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The account the turn runs as.
   * @param \Drupal\daedalus_ai\ModelChoice $modelChoice
   *   The user's model choice, read at a turn's first leg.
   * @param mixed $aiAgentPluginManager
   *   The AI agent plugin manager if it exists.
   */
  public function __construct(
    protected readonly AgentRunner $inner,
    AiProviderPluginManager $aiProvider,
    PrivateTempStoreFactory $tempStore,
    EventDispatcherInterface $eventDispatcher,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HostnameFilter $hostnameFilter,
    protected TranscriptRenderer $renderer,
    protected SegmentPolicy $segmentPolicy,
    protected CarryOverGenerator $carryOverGenerator,
    protected UsageLedger $ledger,
    protected UsageGate $usageGate,
    protected AccountInterface $currentUser,
    protected ModelChoice $modelChoice,
    mixed $aiAgentPluginManager = NULL,
  ) {
    parent::__construct($aiProvider, $tempStore, $eventDispatcher, $aiAgentPluginManager);
  }

  /**
   * {@inheritdoc}
   *
   * An agent on the context spine does not stream: its reply is absorbed
   * whole into the transcript before the turn answers, so $streaming only
   * reaches the inner runner, for an agent this module does not manage.
   */
  public function runAsAgent(string $assistant_id, array $chat_history, array $defaults, string $job_id, bool $verbose_mode = FALSE, array $context = [], bool $streaming = FALSE): ChatOutput {
    $enabled = $this->configFactory->get(self::SETTINGS)->get('enabled_agents') ?: [];
    if (!in_array($assistant_id, $enabled, TRUE)) {
      return $this->inner->runAsAgent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context, $streaming);
    }

    return $this->runPersistent($assistant_id, $chat_history, $defaults, $job_id, $verbose_mode, $context);
  }

  /**
   * Runs an agent on the conversation's transcript.
   *
   * @param string $assistant_id
   *   The assistant/agent plugin ID.
   * @param array $chat_history
   *   The session's messages (role, message, timestamp): the user-visible
   *   chat, read only for the last user message and, on a fresh start, the
   *   earlier messages to replay.
   * @param array $defaults
   *   Provider/model defaults.
   * @param string $job_id
   *   The job id.
   * @param bool $verbose_mode
   *   Whether to run one leg per request (the front end polls).
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

    $identity = AgentState::identity($chat_history);
    $state = AgentState::detect($persisted, $identity);

    // The gate runs before any model call, on a poll as on a new turn: a
    // poll's leg is a model call too. A refusal is answered the way every
    // failure is, through the assistant's template, and the record stays
    // as it was.
    $verdict = $this->usageGate->check($this->currentUser);
    if (!$verdict->allowed) {
      $logger->notice('Usage cap reached for user @uid on job @job_id: @message', [
        '@uid' => $this->currentUser->id(),
        '@job_id' => $job_id,
        '@message' => $verdict->message,
      ]);
      return $this->failureReply($assistant_id, $verdict->message);
    }

    try {
      $agent = $this->createAgent($assistant_id, $job_id);
      $agent_entity = $this->entityTypeManager->getStorage('ai_agent')->load($assistant_id);
      $filter_inert = (bool) $agent_entity?->get('hostname_filter_disabled');

      // Reading the record is its own guarded step: a record that cannot be
      // read back (a transcript this code does not know, a pre-spine record,
      // a wrapper state the agent refuses) is the one thing ever deleted,
      // and the request runs fresh once; a failure in the fresh run is a
      // turn failure like any other, caught below with the record gone.
      $rendering = NULL;
      if ($state !== AgentState::Fresh) {
        try {
          [$transcript, $rendering] = $this->readRecord($state, $persisted, $agent, $filter_inert, $logger);
        }
        catch (\Throwable $e) {
          $logger->error('The record of job @job_id cannot be read (@state; @class: @message); it is deleted and the request runs fresh.', [
            '@job_id' => $job_id,
            '@state' => $state->name,
            '@class' => get_class($e),
            '@message' => $e->getMessage(),
          ]);
          $store->delete($job_id);
          $state = AgentState::Fresh;
          // A restore that failed midway may have left its marks on the
          // agent; the fresh run starts on a fresh instance.
          $agent = $this->createAgent($assistant_id, $job_id);
        }
      }
      $rebuilt = FALSE;
      if ($state === AgentState::Fresh) {
        $transcript = new Transcript();
        $rebuilt = $this->initializeFresh($agent, $transcript, $chat_history, $defaults, $logger);
      }

      if ($verbose_mode) {
        $agent->setLooped(FALSE);
      }

      // The user's choice applies at a turn's first leg; a poll continues
      // on the pair the record carries, since a turn's legs share one
      // prefix. The configuration below lands on the chosen provider.
      if ($state !== AgentState::SameTurn) {
        $this->applyModelChoice($agent, $job_id, $logger);
      }

      $this->applyProviderConfiguration($agent, $assistant_id, $defaults);

      $provider = $agent->getAiProvider();
      $model_id = (string) $agent->getModelName();
      $turn = new TurnState($job_id, $agent, $transcript, $provider, $model_id, $this->segmentPolicy->budget($provider, $model_id), $filter_inert);

      if ($state === AgentState::SameTurn) {
        $this->applyRendering($turn, $rendering);
      }
      else {
        $turn->turnFiles = $this->appendUserTurn($transcript, $chat_history, $context, $job_id);
        $this->render($turn, $turn->turnFiles);
      }
      // The tags set here ride the first leg (the wrapper fixes a leg's
      // tags before its request event, where beforeLeg() re-tags for the
      // legs that follow), which is where a rebuilt record is recorded:
      // its first leg's row carries the tag beside the segment's.
      $this->tagSegment($turn, $rebuilt ? [UsageLedger::REBUILT_TAG] : []);

      $event = new AiAssistantPassContextToAgentEvent($agent, $context);
      $this->eventDispatcher->dispatch($event, AiAssistantPassContextToAgentEvent::EVENT_NAME);

      $solvability = $this->runLegs($turn);

      if ($solvability === AiAgentInterface::JOB_NOT_SOLVABLE) {
        $logger->warning('Agent returned JOB_NOT_SOLVABLE for job @job_id.', ['@job_id' => $job_id]);
      }

      // The last leg's reply (and whatever the wrapper appended after it)
      // is absorbed here, since no request event follows it; a finished
      // turn is where a budget close is decided, while the prefix is still
      // cached.
      $this->absorbLeg($turn);
      $finished = $solvability === AiAgentInterface::JOB_NOT_SOLVABLE || $agent->isFinished();

      // The hard line. The leg just run wrote the conversation's newest
      // ledger row during its call; past the hard limit with the turn
      // unfinished, the next leg would be refused by the provider and the
      // carry-over call with it, so the turn ends here instead: the close
      // below answers Ceiling over this leg's exact prefix, and the reply
      // is the failure template, which carries no tools, so the front end
      // stops polling and the model's pending calls never run.
      $bounded = FALSE;
      if (!$finished) {
        $segment = $transcript->currentSegment();
        $last_leg = $this->lastLeg($turn);
        if ($this->segmentPolicy->pastLimit($segment, $last_leg, $turn->budget)) {
          $bounded = TRUE;
          $finished = TRUE;
          $logger->notice('The turn of job @job_id ended at the hard line: segment @segment measured @measured tokens against a limit of @limit; the segment closes and the pending tool calls are not run.', [
            '@job_id' => $job_id,
            '@segment' => $segment->number(),
            '@measured' => $last_leg?->measures($segment->number()) ?? 0,
            '@limit' => $turn->budget->hardLimit() ?? 0,
          ]);
        }
      }
      if ($finished) {
        $this->closeIfDue($turn, TRUE);
      }

      $this->persist($store, $job_id, $turn, $identity, $finished);

      if ($bounded) {
        return $this->failureReply($assistant_id, self::TURN_BOUNDED_REASON);
      }
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
      // The record is kept and nothing of this request is persisted: the
      // stored record is the last good state, which the next turn builds
      // on (an assistant entry left with pending calls is settled as
      // unanswered when that turn appends). Only a record that cannot be
      // read is deleted, in the guarded step above.
      return $this->failureReply($assistant_id, FailureReason::distill($e->getMessage()), $e);
    }
  }

  /**
   * Instantiates the agent for a request.
   *
   * Parity with the upstream AgentRunner: the job id IS the conversation's
   * stable identity, and consumers key per-conversation state on
   * getRunnerId() (e.g. daedalus_blueprint's dialect alias layer). The
   * restore paths never set it, so it is stamped here on every state.
   *
   * @param string $assistant_id
   *   The assistant/agent plugin ID.
   * @param string $job_id
   *   The job id.
   *
   * @return \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface
   *   The agent.
   */
  private function createAgent(string $assistant_id, string $job_id): ConfigAiAgentInterface {
    /** @var \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent */
    $agent = $this->aiAgentPluginManager->createInstance($assistant_id);
    $agent->setRunnerId($job_id);
    $agent->setProgressThreadId($job_id);
    return $agent;
  }

  /**
   * Reads the record: the transcript, and the wrapper's state onto the agent.
   *
   * The one place the stored record is trusted to be readable; whatever it
   * throws, the caller treats the record as unreadable. A pre-spine record
   * (Stale: no transcript) is refused here for the same reason, so every
   * unreadable record takes one road.
   *
   * @param \Drupal\daedalus_ai_memory\Enum\AgentState $state
   *   The state detected for the record (never Fresh).
   * @param array $persisted
   *   The record from the tempstore.
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance to restore.
   * @param bool $filter_inert
   *   Whether a rendering runs under full trust.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   *
   * @return array{\Drupal\daedalus_ai_memory\Transcript\Transcript, \Drupal\daedalus_ai_memory\Transcript\Rendering|null}
   *   The transcript, and the rendering the agent was restored with on a
   *   poll (NULL on a cross turn, which renders once its turn is appended).
   *
   * @throws \UnexpectedValueException
   *   When the record carries no transcript.
   */
  private function readRecord(AgentState $state, array $persisted, ConfigAiAgentInterface $agent, bool $filter_inert, LoggerInterface $logger): array {
    if ($state === AgentState::Stale) {
      throw new \UnexpectedValueException('The record carries no transcript (it predates the context spine).');
    }
    $transcript = Transcript::fromArray($persisted['transcript']);
    if ($state === AgentState::SameTurn) {
      return [$transcript, $this->restoreForSameTurn($agent, $persisted, $transcript, $filter_inert, $logger)];
    }
    $this->restoreForCrossTurn($agent, $persisted, $logger);
    return [$transcript, NULL];
  }

  /**
   * Runs the turn on the user's chosen pair when it differs from the agent's.
   *
   * ModelChoice::current() answers an option only for an account holding the
   * permission whose stored preference names a curated pair; in every other
   * case the agent keeps the pair it has (the caller's defaults on a fresh
   * start, the record's on a cross turn). The pair the agent ends up on is
   * persisted with the wrapper's state, so the turn's polls run on it.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance, with its provider and model already set.
   * @param string $job_id
   *   The job id, for the notice.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  private function applyModelChoice(ConfigAiAgentInterface $agent, string $job_id, LoggerInterface $logger): void {
    $choice = $this->modelChoice->current($this->currentUser);
    if ($choice === NULL) {
      return;
    }
    $provider_id = (string) $agent->getAiProvider()->getPluginId();
    $model_id = (string) $agent->getModelName();
    if ($choice->provider === $provider_id && $choice->model === $model_id) {
      return;
    }
    $agent->setAiProvider($this->aiProvider->createInstance($choice->provider));
    $agent->setModelName($choice->model);
    $logger->notice('Job @job_id runs on the user\'s model choice @chosen instead of @default.', [
      '@job_id' => $job_id,
      '@chosen' => $choice->key(),
      '@default' => $provider_id . ModelChoiceOption::SEPARATOR . $model_id,
    ]);
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
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance, with its provider already set.
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to (the runner receives
   *   the assistant's ai_agent value here).
   * @param array $defaults
   *   Provider/model defaults from the caller.
   */
  private function applyProviderConfiguration(ConfigAiAgentInterface $agent, string $assistant_id, array $defaults): void {
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
   * The transcript opens its first segment. The session's earlier messages
   * (any before the last user message: a conversation whose record was
   * discarded or expired) are replayed as plain entries so the model reads
   * what the user can see; the last user message is the turn appended
   * afterwards, with its blocks. Whether anything was replayed is answered
   * so the caller can tag the turn's first leg as a rebuilt record (the
   * ledger keeps it, the status report counts it).
   *
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance.
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The new transcript.
   * @param array $chat_history
   *   The chat history from the session.
   * @param array $defaults
   *   Provider/model defaults.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   *
   * @return bool
   *   TRUE when at least one earlier session message was replayed: the
   *   conversation is rebuilt from the visible chat, not begun.
   */
  private function initializeFresh(ConfigAiAgentInterface $agent, Transcript $transcript, array $chat_history, array $defaults, LoggerInterface $logger): bool {
    $logger->notice('Fresh start for job. Session has @count messages.', [
      '@count' => count($chat_history),
    ]);

    if (empty($defaults['provider_id'])) {
      throw new \RuntimeException('No chat AI provider could be resolved. Set a default chat provider at ai.settings (default_providers.chat) or set the assistant\'s llm_provider.');
    }

    // The wrapper copies its chat input into the history on its first
    // leg; the history is the rendering the runner sets, so the input
    // carries nothing.
    $agent->setChatInput(new ChatInput([]));
    $agent->setAiProvider($this->aiProvider->createInstance($defaults['provider_id']));
    $agent->setModelName($defaults['model_id']);
    $agent->setCreateDirectly(TRUE);

    $transcript->openSegment();
    $messages = array_values($chat_history);
    $last_user = self::lastUserIndex($messages);
    $replayed = FALSE;
    foreach ($messages as $index => $message) {
      if ($last_user !== NULL && $index >= $last_user) {
        break;
      }
      // The session stores one assistant message per leg, and a leg that
      // only called tools stored an empty one; a replayed message carries
      // no calls, so an empty one would reach the provider as an empty
      // content block, which the Messages API refuses.
      $text = (string) ($message['message'] ?? '');
      if ($text === '') {
        continue;
      }
      if (in_array($message['role'] ?? NULL, [EntryType::User->role(), EntryType::Assistant->role()], TRUE)) {
        $transcript->appendReplayed($message['role'], $text);
        $replayed = TRUE;
      }
    }
    if ($replayed) {
      $logger->notice('The conversation is rebuilt from the visible chat: the record had expired or could not be read, and the earlier session messages were replayed.');
    }
    return $replayed;
  }

  /**
   * Restores an agent for a new turn after a previous turn completed.
   *
   * Resets execution counters; the history is the transcript's, rendered
   * once the user's turn is appended.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance.
   * @param array $persisted
   *   The persisted state from tempstore.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  private function restoreForCrossTurn(ConfigAiAgentInterface $agent, array $persisted, LoggerInterface $logger): void {
    $logger->notice('Cross-turn continuation.');

    $agent_data = $persisted['agent_data'];
    $agent_data['looped'] = 0;
    $agent_data['context_tools'] = [];
    $agent_data['tool_results'] = [];
    $agent_data['agent_results'] = [];
    $agent_data['chat_history'] = [];

    $agent->fromArray($agent_data);
  }

  /**
   * Restores an agent mid-execution for frontend polling.
   *
   * The wrapper re-derives the tool calls a poll executes from the history
   * array fromArray() is handed, so it is handed the transcript's
   * rendering; the same rendering then becomes the wrapper's history.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance.
   * @param array $persisted
   *   The persisted state from tempstore.
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The conversation's transcript.
   * @param bool $filter_inert
   *   Whether the rendering runs under full trust.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Rendering
   *   The rendering the agent was restored with.
   */
  private function restoreForSameTurn(ConfigAiAgentInterface $agent, array $persisted, Transcript $transcript, bool $filter_inert, LoggerInterface $logger): Rendering {
    $agent_data = $persisted['agent_data'];
    $agent_data['tool_results'] = [];
    $agent_data['agent_results'] = [];

    $logger->notice('Same-turn poll (looped=@looped, context_tools=@ct).', [
      '@looped' => $agent_data['looped'] ?? 0,
      '@ct' => count($agent_data['context_tools'] ?? []),
    ]);

    // The provider, model and counters first, with no history; then the
    // rendering on that provider; then the pending calls, which fromArray()
    // re-derives only from the history array it is handed.
    $agent_data['chat_history'] = [];
    $agent->fromArray($agent_data);
    $rendering = $this->renderTranscript($transcript, $agent->getAiProvider(), (string) $agent->getModelName(), $filter_inert);
    $agent_data['chat_history'] = array_map(
      static fn (ChatMessage $message): array => $message->toArray(),
      $rendering->messages(),
    );
    $agent->fromArray($agent_data);
    return $rendering;
  }

  /**
   * Appends the user's turn to the transcript with the blocks stated for it.
   *
   * The block producers answer one event per user turn with blocks of a
   * kind and the files delivered with the turn; the event never carries
   * the agent.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The transcript.
   * @param array $chat_history
   *   The session's messages.
   * @param array $context
   *   The context from the assistant.
   * @param string $job_id
   *   The conversation's id.
   *
   * @return \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   *   The files delivered with the turn.
   *
   * @throws \RuntimeException
   *   When the session carries no user message to run.
   */
  private function appendUserTurn(Transcript $transcript, array $chat_history, array $context, string $job_id): array {
    $messages = array_values($chat_history);
    $index = self::lastUserIndex($messages);
    if ($index === NULL) {
      throw new \RuntimeException('A turn needs a user message; the session history carries none.');
    }
    $event = new ContextBlocksEvent($context, $job_id, !$transcript->currentSegment()->hasUserTurn());
    $this->eventDispatcher->dispatch($event, ContextBlocksEvent::EVENT_NAME);
    $transcript->appendUserTurn((string) ($messages[$index]['message'] ?? ''), $event->blocks());
    return $event->files();
  }

  /**
   * Runs the wrapper's legs with the runner listening on each.
   *
   * The wrapper dispatches a request event before every provider call and
   * a response event after it; the listeners live for this call only and
   * answer only the events of this job (a sub-agent runs under its own
   * runner id).
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   *
   * @return int
   *   The solvability the wrapper answered.
   */
  private function runLegs(TurnState $turn): int {
    $on_request = function (AgentRequestEvent $event) use ($turn): void {
      if ($event->getAgentRunnerId() === $turn->jobId) {
        $this->beforeLeg($turn, $event);
      }
    };
    $on_response = function (AgentResponseEvent $event) use ($turn): void {
      if ($event->getAgentRunnerId() === $turn->jobId) {
        $turn->lastOutput = $event->getResponse();
      }
    };
    // The wrapper merges its provider tags into a leg's tags before it
    // dispatches the request event, so a tag set there rides the next leg,
    // not this one. The drift the request event detects must land on the
    // leg that paid for it, which the provider proxy's pre-generate event
    // allows: it carries this call's tags and takes them back changed.
    $runner_tag = UsageLedger::RUNNER_TAG_PREFIX . $turn->jobId;
    $on_generate = static function (PreGenerateResponseEvent $event) use ($turn, $runner_tag): void {
      $tags = $event->getTags();
      if ($turn->prefixDrifted && in_array($runner_tag, $tags, TRUE)) {
        $turn->prefixDrifted = FALSE;
        $tags[] = UsageLedger::PREFIX_DRIFT_TAG;
        $event->setTags($tags);
      }
    };
    // Before any other listener reads the leg's input: the messages it
    // carries are the rendering's from here on. The drift tag goes on
    // last, after any listener that replaces the tags wholesale.
    $this->eventDispatcher->addListener(AgentRequestEvent::EVENT_NAME, $on_request, 1000);
    $this->eventDispatcher->addListener(AgentResponseEvent::EVENT_NAME, $on_response, 1000);
    $this->eventDispatcher->addListener(PreGenerateResponseEvent::EVENT_NAME, $on_generate, -1000);
    try {
      return $turn->agent->determineSolvability();
    }
    finally {
      $this->eventDispatcher->removeListener(AgentRequestEvent::EVENT_NAME, $on_request);
      $this->eventDispatcher->removeListener(AgentResponseEvent::EVENT_NAME, $on_response);
      $this->eventDispatcher->removeListener(PreGenerateResponseEvent::EVENT_NAME, $on_generate);
    }
  }

  /**
   * Prepares a leg: absorb, decide a close, render, and shape the input.
   *
   * What the wrapper appended since the last rendering (the previous
   * reply, the tool results it ran, a screenshots or reminder message) is
   * absorbed; the policy may close the segment on it (a reframing skill
   * answered); the transcript is rendered again when it changed, with the
   * files the absorbed user messages carried (a tool's images, on the
   * screenshots message the wrapper interjected) placed on the rendering's
   * newest user message, the road beside the turn's own files on its first
   * leg; and the leg's input takes the rendering's messages and plan. The
   * prefix hash is noted so drift is logged and this leg's call tagged
   * with it (the pre-generate listener in runLegs() does the tagging), and
   * the segment tag is set for the leg that follows (this leg's tags were
   * computed before the event).
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   * @param \Drupal\ai_agents\Event\AgentRequestEvent $event
   *   The wrapper's request event.
   */
  private function beforeLeg(TurnState $turn, AgentRequestEvent $event): void {
    $changed = $this->absorbLeg($turn);
    $changed = $this->closeIfDue($turn, FALSE) || $changed;
    if ($changed || $turn->rendering === NULL) {
      $this->render($turn, $turn->legFiles);
    }

    $input = $event->getChatInput();
    $input->setMessages($turn->rendering->messages());
    $turn->rendering->plan()->attachTo($input);
    $turn->lastInput = $input;

    $segment = $turn->transcript->currentSegment();
    $tools = $input->getChatTools()?->renderToolsArray() ?? [];
    if ($segment->notePrefix(Prefix::hash($event->getSystemPrompt(), $tools))) {
      $turn->prefixDrifted = TRUE;
      $this->getLogger('daedalus_ai_memory')->warning('The prompt prefix drifted on leg @loop of job @job_id (segment @segment); the provider cache is rewritten from the top.', [
        '@loop' => $event->getLoopCount(),
        '@job_id' => $turn->jobId,
        '@segment' => $segment->number(),
      ]);
    }

    $this->tagSegment($turn);
    $turn->legs++;
  }

  /**
   * Absorbs what the wrapper appended to its history since the rendering.
   *
   * The transcript takes the messages' text and calls, never their bytes;
   * the files the slice's user-role messages carry (the images a tool
   * returned, set on the screenshots message the wrapper interjected) are
   * collected on the turn state for the one leg prepared next, replacing
   * whatever the previous absorb collected. The trailing absorb after the
   * last leg collects for no leg, and its files go nowhere.
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   *
   * @return bool
   *   TRUE when anything was absorbed.
   */
  private function absorbLeg(TurnState $turn): bool {
    $history = $turn->agent->getChatHistory();
    $slice = array_slice($history, $turn->renderedCount);
    $turn->absorbed = [];
    $turn->legFiles = [];
    if ($slice === []) {
      return FALSE;
    }
    foreach ($slice as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === EntryType::User->role()) {
        array_push($turn->legFiles, ...$message->getFiles());
      }
    }
    // The leg's output belongs to the first assistant message of the slice
    // (the leg's reply); anything after it the wrapper appended without a
    // model call (the reply it writes itself when the loop limit is hit).
    $reply = NULL;
    foreach ($slice as $index => $message) {
      if ($message instanceof ChatMessage && $message->getRole() === EntryType::Assistant->role()) {
        $reply = $index;
        break;
      }
    }
    if ($reply === NULL) {
      $turn->absorbed = $turn->transcript->absorb($slice);
    }
    else {
      $turn->absorbed = [
        ...$turn->transcript->absorb(array_slice($slice, 0, $reply + 1), $turn->lastOutput, $turn->provider, $turn->modelId),
        ...$turn->transcript->absorb(array_slice($slice, $reply + 1)),
      ];
    }
    $turn->lastOutput = NULL;
    $turn->renderedCount = count($history);
    return TRUE;
  }

  /**
   * Closes the segment when the policy says so, opening the next.
   *
   * The carry-over is generated through one chat call over the closing
   * segment's exact prefix (the last leg's input: system prompt, tools and
   * hostname filter as sent) and its rendering, tagged with the runner and
   * the closing segment. On a reframe close the current turn is re-sent
   * into the new segment, with the segment's skills block on its user
   * entry. A carry-over the model would not give (a tool call, no text) or
   * the provider refused leaves the segment open for now; the next finished
   * leg asks again.
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   * @param bool $turn_finished
   *   Whether the agent finished the user's turn.
   *
   * @return bool
   *   TRUE when a new segment was opened.
   */
  private function closeIfDue(TurnState $turn, bool $turn_finished): bool {
    $transcript = $turn->transcript;
    $segment = $transcript->currentSegment();
    $last_leg = $this->lastLeg($turn);
    $reason = $this->segmentPolicy->closeReason($segment, $last_leg, $turn->budget, $turn->absorbed, $turn_finished);
    if ($reason === NULL) {
      return FALSE;
    }

    $logger = $this->getLogger('daedalus_ai_memory');
    if ($turn->lastInput === NULL) {
      $logger->warning('Segment @segment of job @job_id is due to close (@reason) but no leg of this request sent a prefix to reuse; it stays open.', [
        '@segment' => $segment->number(),
        '@job_id' => $turn->jobId,
        '@reason' => $reason->value,
      ]);
      return FALSE;
    }

    $tags = [UsageLedger::RUNNER_TAG_PREFIX . $turn->jobId, self::segmentTag($segment)];
    try {
      $rendering = $this->renderTranscript($transcript, $turn->provider, $turn->modelId, $turn->filterInert);
      $request = CarryOverGenerator::request($turn->lastInput, $rendering, $transcript->loadedKeys());
      $carry_over = $this->carryOverGenerator->generate($turn->provider, $turn->modelId, $request, $tags);
    }
    catch (\Exception $e) {
      // A reply the generator refuses (a tool call, no text) and a call the
      // provider refuses (the ai module's exceptions extend \Exception, not
      // \RuntimeException) take one road: the segment stays open and the
      // turn is unharmed.
      $logger->warning('Segment @segment of job @job_id stays open: the carry-over could not be generated (@class: @message).', [
        '@segment' => $segment->number(),
        '@job_id' => $turn->jobId,
        '@class' => get_class($e),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    $resend = $reason === CloseReason::Reframe ? self::withSkillsBlock($segment->currentTurn(), $segment) : [];
    $opened = $transcript->openSegment($carry_over, $resend);
    $logger->notice('Segment @segment of job @job_id closed (@reason; measured @measured tokens against @bound); segment @next opened with the carry-over@resent.', [
      '@segment' => $segment->number(),
      '@job_id' => $turn->jobId,
      '@reason' => $reason->value,
      '@measured' => $last_leg?->measures($segment->number()) ?? 0,
      '@bound' => $reason === CloseReason::Ceiling
        ? sprintf('the hard limit of %d', $turn->budget->hardLimit() ?? 0)
        : sprintf('a budget of %d from %s', $turn->budget->effective(), $turn->budget->source()),
      '@next' => $opened->number(),
      '@resent' => $resend === [] ? '' : ' and the current turn re-sent',
    ]);
    return TRUE;
  }

  /**
   * The conversation's newest ledger row, as the policy measures it.
   *
   * The row of the leg just run is written during its call (the ledger
   * reads the provider's post-response event), so after any leg this is
   * that leg's measurement when it ran in the current segment.
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   */
  private function lastLeg(TurnState $turn): ?LastLeg {
    $row = $this->ledger->newestLeg($turn->jobId);
    return $row === NULL ? NULL : LastLeg::fromRow($row);
  }

  /**
   * Renders the transcript into the wrapper's history.
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   * @param \Drupal\ai\OperationType\GenericType\FileBaseInterface[] $files
   *   The files this leg delivers, placed on the rendering's newest user
   *   message: the turn's own on its first leg, the files the wrapper's
   *   interjected user messages carried on the leg after an absorb that
   *   collected them, none otherwise.
   */
  private function render(TurnState $turn, array $files = []): void {
    $this->applyRendering($turn, $this->renderTranscript($turn->transcript, $turn->provider, $turn->modelId, $turn->filterInert, $files));
  }

  /**
   * Makes a rendering the wrapper's history.
   */
  private function applyRendering(TurnState $turn, Rendering $rendering): void {
    $turn->rendering = $rendering;
    $turn->agent->setChatHistory($rendering->messages());
    $turn->renderedCount = count($rendering->messages());
  }

  /**
   * Renders a transcript's current segment for a provider and model.
   *
   * Rendering rebuilds every tool call through the ai module's hostname
   * filter (ToolsPropertyResult filters each string argument in its
   * constructor with the settings defaults, no per-call DTO in scope), so
   * for an agent whose config disables transport filtering the filter is
   * scoped to full trust around the render, the way the wrapper scopes it
   * around its own rehydration: otherwise the transport would silently
   * rewrite the very arguments the flag promises byte-intact.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The transcript.
   * @param object $provider
   *   The provider plugin (its proxy).
   * @param string $model_id
   *   The model id.
   * @param bool $filter_inert
   *   Whether to scope the filter to full trust.
   * @param \Drupal\ai\OperationType\GenericType\FileBaseInterface[] $files
   *   The files delivered with the turn.
   */
  private function renderTranscript(Transcript $transcript, object $provider, string $model_id, bool $filter_inert, array $files = []): Rendering {
    $snapshot = NULL;
    if ($filter_inert) {
      $snapshot = $this->hostnameFilter->snapshotSettings();
      $this->hostnameFilter->applySettings(new HostnameFilterDto(fullTrust: TRUE));
    }
    try {
      return $this->renderer->render($transcript, $provider, $model_id, $this->cacheTtl(), $files);
    }
    finally {
      if ($snapshot !== NULL) {
        $this->hostnameFilter->restoreSettings($snapshot);
      }
    }
  }

  /**
   * Tags the wrapper's next leg with the current segment.
   *
   * The wrapper merges its provider tags into every chat call's tags, and
   * the ledger reads the segment off them, which is how the policy
   * measures a segment by its own legs only. The tags are replaced, not
   * added to, so a tag meant for one leg (UsageLedger::REBUILT_TAG on a
   * rebuilt record's first leg) is gone once the next leg is tagged.
   *
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   * @param string[] $extra
   *   Tags to set beside the segment tag.
   */
  private function tagSegment(TurnState $turn, array $extra = []): void {
    $turn->agent->setProviderTags([self::segmentTag($turn->transcript->currentSegment()), ...$extra]);
  }

  /**
   * The request tag naming a segment.
   */
  private static function segmentTag(Segment $segment): string {
    return UsageLedger::SEGMENT_TAG_PREFIX . $segment->number();
  }

  /**
   * The cache TTL the renderer asks a capable provider for.
   */
  private function cacheTtl(): string {
    $ttl = $this->configFactory->get(self::SETTINGS)->get('cache_ttl');
    return is_string($ttl) && in_array($ttl, RenderingPlan::TTLS, TRUE) ? $ttl : RenderingPlan::TTL_5M;
  }

  /**
   * The current turn with the closing segment's skills block on its user entry.
   *
   * Only a segment's first user turn gets the skills block from the
   * producers; a turn re-sent into a new segment carries the block it
   * read in the old one, so the new segment states the skills too.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Entry[] $turn
   *   The current turn (Segment::currentTurn()).
   * @param \Drupal\daedalus_ai_memory\Transcript\Segment $closing
   *   The segment it is re-sent from.
   *
   * @return \Drupal\daedalus_ai_memory\Transcript\Entry[]
   *   The turn to re-send.
   */
  private static function withSkillsBlock(array $turn, Segment $closing): array {
    if ($turn === []) {
      return [];
    }
    $first = $turn[0];
    foreach ($first->blocks as $block) {
      if ($block->kind === BlockKind::Skills) {
        return $turn;
      }
    }
    foreach ($closing->entries() as $entry) {
      if ($entry->type !== EntryType::User) {
        continue;
      }
      foreach ($entry->blocks as $block) {
        if ($block->kind === BlockKind::Skills) {
          $turn[0] = Entry::user($first->text, [...$first->blocks, $block], $first->turn);
          return $turn;
        }
      }
    }
    return $turn;
  }

  /**
   * Persists the transcript and the wrapper's non-history state.
   *
   * The wrapper's chat history is the rendering and is never stored: the
   * transcript is the history, and the files a turn delivered live only
   * on the rendering's messages, so the record holds no bytes.
   *
   * @param \Drupal\Core\TempStore\PrivateTempStore $store
   *   The tempstore instance.
   * @param string $job_id
   *   The job id.
   * @param \Drupal\daedalus_ai_memory\Service\TurnState $turn
   *   The request's state.
   * @param string|null $identity
   *   The identity of the user message this turn ran for.
   * @param bool $finished
   *   Whether the agent finished the turn.
   */
  private function persist(PrivateTempStore $store, string $job_id, TurnState $turn, ?string $identity, bool $finished): void {
    $agent_data = $turn->agent->toArray();
    $agent_data['chat_history'] = [];
    $store->set($job_id, [
      'transcript' => $turn->transcript->toArray(),
      'agent_data' => $agent_data,
      'last_user' => $identity,
      'finished' => $finished,
    ]);
  }

  /**
   * The index of the last user message in a session's message list.
   *
   * @param array $messages
   *   The messages, as a list.
   */
  private static function lastUserIndex(array $messages): ?int {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
      if (($messages[$i]['role'] ?? NULL) === 'user') {
        return $i;
      }
    }
    return NULL;
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
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance.
   * @param string $assistant_id
   *   The agent plugin id the assistant delegates to.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output.
   */
  private function buildResponse(ConfigAiAgentInterface $agent, string $assistant_id, LoggerInterface $logger): ChatOutput {
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
   * @param \Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface $agent
   *   The agent instance.
   *
   * @return string|null
   *   The distilled failure, or NULL when the agent recorded none.
   */
  private function recordedFailure(ConfigAiAgentInterface $agent): ?string {
    if (!method_exists($agent, 'getLastError')) {
      return NULL;
    }
    $raw = $agent->getLastError();
    return is_string($raw) && trim($raw) !== '' ? FailureReason::distill($raw) : NULL;
  }

  /**
   * Builds the assistant's reply for a turn that failed or was refused.
   *
   * The wording is the assistant entity's own error template
   * (ai_assistant_api's `error_message`, or the `specific_error_messages` entry
   * for the exception's class when one is set), with its `[error_message]`
   * token replaced by the reason, so a site phrases failures once for every
   * road: a thrown turn, a provider's rejection, and the usage gate's refusal.
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
