<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_dev_tools\Drush\Commands;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint_ai_dev_tools\Plugin\AiProvider\PromptCaptureProvider;
use Drupal\daedalus_blueprint_ai_dev_tools\PromptCapture;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dumps the fully-assembled AI prompt surface for an assistant.
 *
 * Drives the real prompt-assembly pipeline with a capture provider swapped in
 * for the configured LLM provider, so the emitted snapshot is exactly what the
 * assistant would have sent to the model -- no LLM is called. The assembled
 * ChatInput (system prompt, messages, tools) is recorded by
 * PromptCaptureProvider into the shared PromptCapture sink, which this command
 * reads back and renders.
 *
 * By default the command feeds enough context to reveal the entire authored
 * surface, including the context-gated blocks (Selection Scope, Context Tags,
 * the active-selection reminder). Point it at real content with --entity to
 * upgrade the synthetic placeholders to a real page and a real selected
 * element, or reproduce a specific scenario with --view-mode and --select.
 */
#[AsCommand(
  name: 'daedalus-blueprint:prompt-audit',
  description: 'Audits the fully-assembled AI prompt surface for an assistant.',
  aliases: ['dd-blueprint-audit'],
)]
final class PromptAuditCommands extends Command {

  use AutowireTrait;

  /**
   * The plugin ID of the capture provider that records the assembled prompt.
   */
  protected const CAPTURE_PROVIDER_ID = 'prompt_capture';

  /**
   * Placeholder block UUID used when auto-selecting without a real entity.
   *
   * Resolves to a "Block (UUID: ...)" description so the active-selection
   * reminder still renders; the all-zero UUID makes the synthetic origin
   * obvious in the output.
   */
  protected const SYNTHETIC_BLOCK_UUID = '00000000-0000-0000-0000-000000000000';

  /**
   * The renderable sections, in canonical output order.
   *
   * The header is always printed and is not a selectable section; --only
   * narrows the output to a subset of these.
   */
  protected const SECTIONS = ['system-prompt', 'messages', 'tools', 'schema', 'element', 'markup'];

  /**
   * The runner's own session tempstore bin.
   *
   * The runner's resetStructuredResults()/resetOutputContexts() write an empty
   * session here under the thread id before the agent fork, so the isolated
   * audit thread leaves a stub here that must be cleaned up.
   */
  protected const RUNNER_TEMP_STORE_BIN = 'ai_assistant_api';

  /**
   * The daedalus_ai_memory thread tempstore bin.
   *
   * AgentMemoryRunner persists the agent's per-turn state here keyed by the
   * thread id. The isolated audit thread is written here and must be cleaned
   * up so the diagnostic leaves no footprint.
   */
  protected const MEMORY_TEMP_STORE_BIN = 'daedalus_ai_memory_threads';

  /**
   * Constructs a new PromptAuditCommands object.
   *
   * @param \Drupal\ai_assistant_api\AiAssistantApiRunner $runner
   *   The assistant runner that assembles (and would dispatch) the prompt.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint_ai_dev_tools\PromptCapture $promptCapture
   *   The sink that receives the assembled chat input from the provider.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   The account switcher, used to assemble the prompt as a chosen user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, used to resolve the page builder's assistant.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager, used to pick a processable field when
   *   auto-selecting an element on a non-Layout-Builder entity.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   The private tempstore factory, used to delete the isolated audit thread
   *   from the runner and daedalus_ai_memory bins after capture.
   * @param \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager $functionCallManager
   *   The AI function call plugin manager, used to invoke the real read_schema
   *   and read_element tools so the captured Bucket B output is the production
   *   path verbatim rather than a reconstructed schema-builder call.
   * @param \Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem $activeDesignSystem
   *   The active design system, whose catalog the captured prompt speaks.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, or NULL when the workspaces module is not enabled.
   *   Used to force an in-memory active workspace around schema/element capture
   *   so the AI's workspace guard is satisfied without persisting anything.
   */
  public function __construct(
    #[Autowire(service: 'ai_assistant_api.runner')]
    protected AiAssistantApiRunner $runner,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'daedalus_blueprint_ai_dev_tools.prompt_capture')]
    protected PromptCapture $promptCapture,
    protected AccountSwitcherInterface $accountSwitcher,
    protected ConfigFactoryInterface $configFactory,
    protected FieldHandlerManager $fieldHandlerManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    #[Autowire(service: 'plugin.manager.ai.function_calls')]
    protected FunctionCallPluginManager $functionCallManager,
    #[Autowire(service: 'daedalus_blueprint.active_design_system')]
    protected ActiveDesignSystem $activeDesignSystem,
    #[Autowire(service: 'workspaces.manager')]
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('assistant', NULL, InputOption::VALUE_REQUIRED, 'Assistant ID to audit. Defaults to the page builder assistant (daedalus_ai.settings).', '')
      ->addOption('message', NULL, InputOption::VALUE_REQUIRED, 'The user message to seed the turn with.', 'This is the user prompt.')
      ->addOption('user', NULL, InputOption::VALUE_REQUIRED, 'User ID to assemble the prompt as; affects any permission-gated surface.', '1')
      ->addOption('entity', NULL, InputOption::VALUE_REQUIRED, 'Treat this entity as the current page, as type:id (e.g. node:1). Enables the real "Current page" context and a real auto-selected element.', '')
      ->addOption('view-mode', NULL, InputOption::VALUE_REQUIRED, 'Layout Builder view mode to report as active. Unlocks the Selection Scope and Context Tags blocks.', 'default')
      ->addOption('select', NULL, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Element(s) to select, as selectable keys (the same spine keys the canvas sends): "block:UUID", "block:UUID:field_name", "container:UUID", "field:ENTITY_UUID:field_name", "entity:UUID". Omit to auto-select a real element; "none" to select nothing.')
      ->addOption('schema', NULL, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entity type/bundle(s) to capture the read_schema tool output for, as type or type:bundle (e.g. node:article). Repeatable. When omitted, the bundle of --entity is used. Captured under the schema section.')
      ->addOption('only', NULL, InputOption::VALUE_REQUIRED, 'Show only the named section(s), comma-separated: system-prompt, messages, tools, schema, element, markup. The header always prints. Omit to show the full surface.', '')
      ->addUsage('')
      ->addUsage('--entity=node:1')
      ->addUsage('--entity=node:1 --select=none')
      ->addUsage('--only=system-prompt')
      ->addUsage('--schema=node:article --only=schema')
      ->addUsage('--entity=node:1 --only=element')
      ->addUsage('--entity=node:1 --only=markup')
      ->addUsage('--entity=node:1 --view-mode=full --select=block:UUID');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $assistant = $this->resolveAssistant((string) $input->getOption('assistant'));
    $message = (string) $input->getOption('message');
    $uid = (int) $input->getOption('user');
    $sections = $this->parseSections((string) $input->getOption('only'));

    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($account === NULL) {
      throw new \RuntimeException(sprintf('User %d not found.', $uid));
    }

    // Load the current-page entity once and thread it through both the context
    // builder (Bucket A) and the schema capture (Bucket B) so it is resolved
    // and validated a single time.
    $view_mode = (string) $input->getOption('view-mode');
    $entity_option = (string) $input->getOption('entity');
    $entity = $entity_option !== '' ? $this->loadEntityFromOption($entity_option) : NULL;

    // Build the context array exactly as DeepChatApi would from the request's
    // "contexts" body prop, so the context-gated injections fire identically.
    [$context, $context_meta] = $this->buildContext($input, $entity, $view_mode);

    // Record the production target before swapping the provider out, so the
    // report still shows which model would really have been called.
    $real_provider = (string) $assistant->get('llm_provider');
    $real_model = (string) $assistant->get('llm_model');

    // Mutate the loaded assistant in place (never saved): point it at the
    // capture provider so process() assembles the genuine prompt and hands it
    // to our chat() instead of a live LLM.
    $assistant->set('llm_provider', self::CAPTURE_PROVIDER_ID);
    $assistant->set('llm_model', PromptCaptureProvider::MODEL_ID);

    // Run inside an isolated, throwaway memory thread so the audit neither
    // replays nor pollutes the user's real assistant conversation. The
    // assistant uses session_one_thread history and is registered
    // with daedalus_ai_memory, so without this the runner would resolve the
    // user's live thread -- replaying every accumulated turn into the captured
    // prompt and writing this synthetic turn back into their chat memory.
    // Setting our own key before setAssistant() keeps the runner from ever
    // resolving (or repointing) the real thread.
    $thread_id = 'prompt_audit_' . uniqid();

    // Assemble as the chosen user, mirroring DeepChatApi's runner sequence.
    // The account switch wraps both buckets: the chat-turn capture and the
    // schema capture run as the same user, so any access-gated surface matches.
    $this->promptCapture->reset();
    $schema_captures = [];
    $workspace_note = '';
    $element_captures = [];
    $element_note = '';
    $markup_captures = [];
    $markup_note = '';
    $this->accountSwitcher->switchTo($account);
    try {
      try {
        $this->runner->setThreadsKey($thread_id);
        $this->runner->setAssistant($assistant);
        $this->runner->setVerboseMode(TRUE);
        $this->runner->setUserMessage(new UserMessage($message));
        $this->runner->setContext($context);
        $this->runner->setThrowException(TRUE);
        $this->runner->process();
      }
      finally {
        // Delete the throwaway thread from both stores it touched. Both
        // entries are owned by the switched-to user, so delete before
        // switching back.
        $this->tempStoreFactory->get(self::RUNNER_TEMP_STORE_BIN)->delete($thread_id);
        $this->tempStoreFactory->get(self::MEMORY_TEMP_STORE_BIN)->delete($thread_id);
      }

      if (!$this->promptCapture->hasCapture()) {
        throw new \RuntimeException('No prompt was captured: the runner never reached the capture provider. The assistant may not use the agent path, or provider routing did not resolve to the capture provider.');
      }

      // Bucket B: capture the read_schema tool output by invoking the real
      // FunctionCall plugin, forcing an active workspace in memory so the
      // tool's workspace guard is satisfied without persisting anything.
      if (in_array('schema', $sections, TRUE)) {
        [$schema_captures, $workspace_note] = $this->captureSchemas($entity, $input, $view_mode);
      }

      // Bucket B (Intent B, opt-in): capture the read_element tool output for
      // the selected component's live values. Reuses the exact selection fed to
      // the chat turn ($context['selected_elements']) and the same in-memory
      // workspace forcing. Only meaningful with a real --entity and a selected
      // block, so it is skipped -- with a reason -- otherwise.
      if (in_array('element', $sections, TRUE)) {
        [$element_captures, $element_note] = $this->captureElement($entity, $context['selected_elements'] ?? []);
      }

      // Bucket B (opt-in): capture the markup projection — the element
      // model's key-annotated HTML print format — as the AI receives it
      // from the read tools' markup mode: the whole page through
      // read_entity, plus the selected component through read_element when
      // the selection carries one. Rides the same real FunctionCall
      // plugins and the same in-memory workspace forcing as the other
      // captures, so the bytes are the production markup-mode surface.
      if (in_array('markup', $sections, TRUE)) {
        [$markup_captures, $markup_note] = $this->captureMarkup($entity, $context['selected_elements'] ?? []);
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $this->render($output, $sections, [
      'assistant' => (string) $assistant->id(),
      'agent' => (string) $assistant->get('ai_agent'),
      'provider' => $real_provider,
      'model' => $real_model,
      'uid' => $uid,
      'message' => $message,
    ] + $context_meta, $schema_captures, $workspace_note, $element_captures, $element_note, $markup_captures, $markup_note);

    return self::SUCCESS;
  }

  /**
   * Resolves which assistant to audit.
   *
   * @param string $override
   *   An explicit assistant ID from --assistant, or '' to resolve the default
   *   from the page builder configuration.
   *
   * @return \Drupal\ai_assistant_api\Entity\AiAssistant
   *   The loaded assistant entity.
   */
  protected function resolveAssistant(string $override): AiAssistant {
    $id = $override !== ''
      ? $override
      : (string) $this->configFactory->get('daedalus_ai.settings')->get('assistant_id');
    if ($id === '') {
      throw new \RuntimeException('No assistant ID given and daedalus_ai.settings has none. Pass --assistant=<id>.');
    }
    $assistant = $this->entityTypeManager->getStorage('ai_assistant')->load($id);
    if (!$assistant instanceof AiAssistant) {
      throw new \RuntimeException(sprintf('Assistant "%s" not found.', $id));
    }
    return $assistant;
  }

  /**
   * Resolves the --only option into the set of sections to render.
   *
   * @param string $option
   *   The raw --only value: a comma-separated list of section names, or '' to
   *   show every section.
   *
   * @return string[]
   *   The sections to render, in canonical output order.
   *
   * @throws \RuntimeException
   *   If any requested section name is not a known section.
   */
  protected function parseSections(string $option): array {
    if (trim($option) === '') {
      return self::SECTIONS;
    }
    $requested = array_filter(array_map('trim', explode(',', $option)), 'strlen');
    $unknown = array_diff($requested, self::SECTIONS);
    if ($unknown !== []) {
      throw new \RuntimeException(sprintf(
        'Unknown --only section(s): %s. Valid sections: %s.',
        implode(', ', $unknown),
        implode(', ', self::SECTIONS),
      ));
    }
    // Canonical order, regardless of the order given on the command line.
    return array_values(array_intersect(self::SECTIONS, $requested));
  }

  /**
   * Builds the context array fed to the runner, plus a human-readable summary.
   *
   * Mirrors the "contexts" body prop DeepChatApi hands to the runner: a
   * current_route path, a view_mode string, and a selected_elements list. The
   * page builder's event subscribers read these keys to inject the current
   * page, selection scope, and active-selection blocks.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input carrying the --select option.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The pre-loaded current-page entity, or NULL when --entity was not given.
   * @param string $view_mode
   *   The Layout Builder view mode to report as active, or '' for none.
   *
   * @return array{0: array, 1: array}
   *   A tuple of the context array for setContext() and a meta array with
   *   'current_page', 'view_mode', and 'selection' summary strings for the
   *   report header.
   */
  protected function buildContext(InputInterface $input, ?FieldableEntityInterface $entity, string $view_mode): array {
    $context = [];
    $meta = [
      'current_page' => '(none)',
      'view_mode' => '(none)',
      'selection' => '',
    ];

    if ($view_mode !== '') {
      $context['view_mode'] = $view_mode;
      $meta['view_mode'] = $view_mode;
    }

    if ($entity !== NULL) {
      $context['current_route'] = $this->entityToRoute($entity);
      $meta['current_page'] = sprintf(
        '%s:%s "%s" (route: %s)',
        $entity->getEntityTypeId(),
        $entity->id(),
        $entity->label(),
        $context['current_route'],
      );
    }

    $select = (array) $input->getOption('select');
    if (in_array('none', $select, TRUE)) {
      $meta['selection'] = 'none (no selection injected)';
    }
    elseif ($select === []) {
      [$elements, $description] = $this->autoSelect($entity);
      if ($elements !== []) {
        $context['selected_elements'] = $elements;
      }
      $meta['selection'] = $description;
    }
    else {
      $elements = $this->parseSelectionTokens($select);
      $context['selected_elements'] = $elements;
      $meta['selection'] = sprintf('explicit (%d element(s))', count($elements));
    }

    return [$context, $meta];
  }

  /**
   * Picks a single real element to select, or a synthetic placeholder.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The current-page entity, or NULL when --entity was not supplied.
   *
   * @return array{0: array, 1: string}
   *   A tuple of the selected_elements list (possibly empty) and a
   *   human-readable description of what was selected.
   */
  protected function autoSelect(?FieldableEntityInterface $entity): array {
    // No entity: a synthetic placeholder still reveals the active-selection
    // reminder wrapper (authored prose) even though the element is not real.
    if ($entity === NULL) {
      $elements = [['key' => 'block:' . self::SYNTHETIC_BLOCK_UUID, 'number' => 1]];
      return [
        $elements,
        sprintf('synthetic placeholder block %s — pass --entity for a real element', self::SYNTHETIC_BLOCK_UUID),
      ];
    }

    // Layout Builder entity: first component of the first non-empty section of
    // the entity's own (override) layout.
    if ($entity->hasField('layout_builder__layout') && !$entity->get('layout_builder__layout')->isEmpty()) {
      foreach ($entity->get('layout_builder__layout')->getSections() as $section) {
        $components = $section->getComponents();
        if ($components !== []) {
          $uuid = array_key_first($components);
          return [
            [['key' => 'block:' . $uuid, 'number' => 1]],
            sprintf('auto-selected block %s', $uuid),
          ];
        }
      }
    }

    // Non-Layout-Builder entity: first processable field.
    $definitions = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);
    if ($definitions !== []) {
      $field_name = (string) array_key_first($definitions);
      return [
        [['key' => sprintf('field:%s:%s', $entity->uuid(), $field_name), 'number' => 1]],
        sprintf('auto-selected field %s', $field_name),
      ];
    }

    return [[], 'none available (entity has no override components or processable fields)'];
  }

  /**
   * Parses --select tokens into a selected_elements list.
   *
   * Each token is a selectable key, the selection spine's own identity
   * (entity:, field:, container:, block:); the list is the same shape the
   * chat client sends as request context, so the resolver reads it
   * unchanged.
   *
   * @param array $tokens
   *   Raw selectable keys.
   *
   * @return array
   *   The selected_elements list, numbered in the order given.
   */
  protected function parseSelectionTokens(array $tokens): array {
    $elements = [];
    $number = 1;
    foreach ($tokens as $token) {
      $key = (string) $token;
      $prefix = strtok($key, ':');
      if (str_contains($key, '::') || !in_array($prefix, ['entity', 'field', 'container', 'block'], TRUE) || $prefix === $key) {
        throw new \RuntimeException(sprintf('Invalid --select key "%s": expected a selectable key such as "block:UUID", "container:UUID", "field:ENTITY_UUID:field_name", or "entity:UUID".', $key));
      }
      $elements[] = ['key' => $key, 'number' => $number];
      $number++;
    }
    return $elements;
  }

  /**
   * Loads the --entity option value into a fieldable entity.
   *
   * @param string $option
   *   The option value as "type:id" or "type/id" (e.g. node:1).
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The loaded entity.
   */
  protected function loadEntityFromOption(string $option): FieldableEntityInterface {
    $parts = preg_split('#[:/]#', $option, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw new \RuntimeException(sprintf('Invalid --entity "%s": expected type:id, e.g. node:1.', $option));
    }

    [$entity_type_id, $id] = $parts;
    if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
      throw new \RuntimeException(sprintf('Unknown entity type "%s".', $entity_type_id));
    }

    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    if (!$entity instanceof FieldableEntityInterface) {
      throw new \RuntimeException(sprintf('Entity %s:%s not found or not fieldable.', $entity_type_id, $id));
    }
    return $entity;
  }

  /**
   * Resolves an entity to the internal path the page builder reports as route.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve.
   *
   * @return string
   *   The internal canonical path, e.g. "/node/1".
   */
  protected function entityToRoute(FieldableEntityInterface $entity): string {
    try {
      return '/' . ltrim($entity->toUrl()->getInternalPath(), '/');
    }
    catch (\Exception $e) {
      throw new \RuntimeException(sprintf(
        'Entity %s:%s has no canonical URL, so it cannot stand in as a current page. Use a routable entity such as a node, taxonomy term, or user. (%s)',
        $entity->getEntityTypeId(),
        $entity->id(),
        $e->getMessage(),
      ));
    }
  }

  /**
   * Captures the read_schema tool output for the resolved schema targets.
   *
   * Invokes the real daedalus_blueprint:read_schema FunctionCall plugin -- the
   * production path -- so the captured bytes are exactly what the AI receives,
   * not a reconstructed schema-builder call (which is proven to drift). When
   * the workspaces module is enabled, the invocation is wrapped in an in-memory
   * workspace switch so the tool's workspace guard is satisfied; this persists
   * nothing and self-restores. With no workspace available the tool's own guard
   * text is captured instead, and the header notes why.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The current-page entity, used to derive a default schema target, or NULL.
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input carrying the repeatable --schema option.
   * @param string $view_mode
   *   The view mode to request the schema for, or '' to let the tool default.
   *
   * @return array{0: array<int, array{label: string, output: string}>, 1: string}
   *   A tuple of the per-target captures and a workspace note for the header.
   */
  protected function captureSchemas(?FieldableEntityInterface $entity, InputInterface $input, string $view_mode): array {
    $targets = $this->resolveSchemaTargets($entity, $input, $view_mode);
    if ($targets === []) {
      return [[], '(no schema source — pass --entity or --schema=type:bundle)'];
    }

    [$workspace_id, $note] = $this->resolveWorkspace();

    $captures = [];
    foreach ($targets as $target) {
      $values = ['entity_type' => $target['entity_type']];
      if ($target['bundle'] !== NULL) {
        $values['bundle'] = $target['bundle'];
      }
      if ($view_mode !== '') {
        $values['view_mode'] = $view_mode;
      }
      $captures[] = [
        'label' => $target['label'],
        'output' => $this->invokeToolInWorkspace($workspace_id, 'daedalus_blueprint:read_schema', $values),
      ];
    }

    return [$captures, $note];
  }

  /**
   * Captures the read_element tool output for the selected component.
   *
   * This is the Intent-B (opt-in) half of Bucket B: a single real component's
   * live field values, as the AI sees them inside a read_element tool response.
   * Unlike the schema, it requires a real entity and a real component UUID, so
   * it rides on the same selection that drives the chat turn -- there is
   * nothing useful to capture for a synthetic selection. Invokes the real
   * daedalus_blueprint:read_element FunctionCall plugin (the production path) and
   * forces an in-memory workspace exactly as the schema capture does.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The current-page entity, or NULL when --entity was not given.
   * @param array $selected_elements
   *   The selected_elements list fed to the runner, reused verbatim so the
   *   captured element matches the chat turn's active selection.
   *
   * @return array{0: array<int, array{label: string, output: string}>, 1: string}
   *   A tuple of the capture (zero or one entry) and a note explaining the
   *   skip reason or the workspace resolution for the report header.
   */
  protected function captureElement(?FieldableEntityInterface $entity, array $selected_elements): array {
    if ($entity === NULL) {
      return [[], '(no --entity — read_element needs a real entity; Intent B)'];
    }
    $uuid = $this->selectedComponentUuid($selected_elements);
    if ($uuid === NULL) {
      return [[], '(no component selected — select a block to read its live values; Intent B)'];
    }

    [$workspace_id, $note] = $this->resolveWorkspace();
    $output = $this->invokeToolInWorkspace($workspace_id, 'daedalus_blueprint:read_element', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (int) $entity->id(),
      'element' => $uuid,
    ]);

    $capture = [
      'label' => sprintf('%s:%s element %s', $entity->getEntityTypeId(), $entity->id(), $uuid),
      'output' => $output,
    ];
    return [[$capture], $note];
  }

  /**
   * Captures the read tools' markup-mode output — the projection surface.
   *
   * The markup projection is the element model's key-annotated HTML print
   * format, reached through daedalus_blueprint's markup-projection socket.
   * Two captures ride here: the whole page (read_entity mode=markup) and,
   * when the selection carries a component, that component
   * (read_element mode=markup). Both invoke the real FunctionCall plugins
   * so the captured bytes are exactly the markup-mode tool responses,
   * projector legend included — with no projector installed the captured
   * text is the tools' own unavailability corrective, which is itself the
   * faithful surface.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The current-page entity, or NULL when --entity was not given.
   * @param array $selected_elements
   *   The selected_elements list fed to the runner, reused so the captured
   *   component matches the chat turn's active selection.
   *
   * @return array{0: array<int, array{label: string, output: string}>, 1: string}
   *   A tuple of the captures and a note for the report header.
   */
  protected function captureMarkup(?FieldableEntityInterface $entity, array $selected_elements): array {
    if ($entity === NULL) {
      return [[], '(no --entity — the markup projection needs a real entity)'];
    }

    [$workspace_id, $note] = $this->resolveWorkspace();

    $captures = [
      [
        'label' => sprintf('%s:%s page markup (read_entity mode=markup)', $entity->getEntityTypeId(), $entity->id()),
        'output' => $this->invokeToolInWorkspace($workspace_id, 'daedalus_blueprint:read_entity', [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => (string) $entity->id(),
          'mode' => 'markup',
        ]),
      ],
    ];

    $uuid = $this->selectedComponentUuid($selected_elements);
    if ($uuid !== NULL) {
      $captures[] = [
        'label' => sprintf('%s:%s element %s markup (read_element mode=markup)', $entity->getEntityTypeId(), $entity->id(), $uuid),
        'output' => $this->invokeToolInWorkspace($workspace_id, 'daedalus_blueprint:read_element', [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => (int) $entity->id(),
          'element' => $uuid,
          'mode' => 'markup',
        ]),
      ];
    }

    return [$captures, $note];
  }

  /**
   * Extracts the first readable component UUID from a selection.
   *
   * The read_element tool reads a block or paragraph by UUID, so only
   * selections that carry a component UUID are readable: a block: key (the
   * placement uuid, with or without a field segment). A field: or entity:
   * selection (e.g. a node title) has no component to read.
   *
   * @param array $selected_elements
   *   The selected_elements list.
   *
   * @return string|null
   *   The component UUID to read, or NULL when the selection has none.
   */
  protected function selectedComponentUuid(array $selected_elements): ?string {
    foreach ($selected_elements as $element) {
      $segments = explode(':', (string) ($element['key'] ?? ''));
      if ($segments[0] === 'block' && !empty($segments[1])) {
        return $segments[1];
      }
    }
    return NULL;
  }

  /**
   * Resolves which entity type/bundle schemas to capture.
   *
   * The --entity bundle is captured by default; each --schema token adds (or
   * overrides) a target. Targets are de-duplicated by entity type and bundle.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The current-page entity, or NULL when --entity was not given.
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The command input carrying the repeatable --schema option.
   * @param string $view_mode
   *   The active view mode, used only to label the targets in the report.
   *
   * @return array<string, array{entity_type: string, bundle: string|null, label: string}>
   *   The schema targets keyed by "type:bundle".
   */
  protected function resolveSchemaTargets(?FieldableEntityInterface $entity, InputInterface $input, string $view_mode): array {
    $targets = [];
    $suffix = $view_mode !== '' ? ' @ ' . $view_mode : '';

    if ($entity !== NULL) {
      $type = $entity->getEntityTypeId();
      $bundle = $entity->bundle();
      $targets[$type . ':' . $bundle] = [
        'entity_type' => $type,
        'bundle' => $bundle,
        'label' => $type . ':' . $bundle . $suffix,
      ];
    }

    foreach ((array) $input->getOption('schema') as $token) {
      [$type, $bundle] = $this->parseSchemaToken((string) $token);
      $targets[$type . ':' . ($bundle ?? '')] = [
        'entity_type' => $type,
        'bundle' => $bundle,
        'label' => $type . ($bundle !== NULL ? ':' . $bundle : '') . $suffix,
      ];
    }

    return $targets;
  }

  /**
   * Parses a --schema token into an entity type and optional bundle.
   *
   * @param string $token
   *   The token as "type" or "type:bundle" (e.g. node:article).
   *
   * @return array{0: string, 1: string|null}
   *   A tuple of the entity type ID and the bundle (or NULL when omitted).
   */
  protected function parseSchemaToken(string $token): array {
    $parts = preg_split('#[:/]#', $token, 2);
    $type = $parts[0] ?? '';
    if ($type === '') {
      throw new \RuntimeException(sprintf('Invalid --schema "%s": expected type or type:bundle, e.g. node:article.', $token));
    }
    if (!$this->entityTypeManager->hasDefinition($type)) {
      throw new \RuntimeException(sprintf('Unknown entity type "%s" in --schema "%s".', $type, $token));
    }
    $bundle = (isset($parts[1]) && $parts[1] !== '') ? $parts[1] : NULL;
    return [$type, $bundle];
  }

  /**
   * Picks an existing workspace to force around schema/element capture.
   *
   * The choice does not change the read-only schema output, so the most
   * recently changed workspace is used. Access is not checked: under the CLI
   * the workspace switch bypasses the view-access gate anyway, and a dev
   * diagnostic should force a workspace reliably regardless of the audited
   * user's permissions.
   *
   * @return string|null
   *   A workspace ID, or NULL when no workspace exists.
   */
  protected function firstWorkspaceId(): ?string {
    $ids = $this->entityTypeManager->getStorage('workspace')
      ->getQuery()
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();
    return $ids ? (string) reset($ids) : NULL;
  }

  /**
   * Resolves the workspace to force around Bucket B tool captures.
   *
   * Both read_schema and read_element run behind the daedalus_blueprint workspace
   * guard. In production the AI always runs inside an active workspace, so the
   * faithful, high-value surface is the schema/element itself, not the guard
   * text. This picks an existing workspace to force in memory; it never creates
   * one (a persistent write) and the choice does not change the read-only
   * output. When workspaces is disabled, or none exists, no workspace is forced
   * and the tool's own guard text is captured instead.
   *
   * @return array{0: string|null, 1: string}
   *   A tuple of the workspace ID to force (or NULL to capture the guard text)
   *   and a human-readable note describing the resolution for the report.
   */
  protected function resolveWorkspace(): array {
    if ($this->workspaceManager === NULL) {
      return [NULL, 'workspaces module not enabled — no workspace forced'];
    }
    $workspace_id = $this->firstWorkspaceId();
    if ($workspace_id === NULL) {
      return [NULL, 'no workspace available — capturing the tool guard text'];
    }
    return [$workspace_id, 'forced workspace: ' . $workspace_id];
  }

  /**
   * Invokes an AI FunctionCall tool the way the agent runner does.
   *
   * Mirrors the production invocation path (AiAgentEntityWrapper::executeTool):
   * create the plugin instance, set each context value (type-converted by the
   * plugin), execute, and read the readable output. No agent-decision events
   * are dispatched -- they are side-effect-only and not part of producing the
   * captured bytes.
   *
   * @param string $plugin_id
   *   The FunctionCall plugin ID (e.g. daedalus_blueprint:read_schema).
   * @param array<string, mixed> $context_values
   *   The context values to set on the tool, keyed by context name.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function invokeTool(string $plugin_id, array $context_values): string {
    $tool = $this->functionCallManager->createInstance($plugin_id);
    foreach ($context_values as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Invokes a tool inside a forced workspace, or directly when none is forced.
   *
   * The switch is in-memory only: WorkspaceManager::executeInWorkspace
   * captures the active workspace, switches, runs, and restores, so the
   * capture leaves no persistent footprint. A NULL workspace ID means none
   * could be forced, in which case the tool runs as-is and its workspace guard
   * text is what gets captured.
   *
   * @param string|null $workspace_id
   *   The workspace ID to force, or NULL to invoke without forcing one.
   * @param string $plugin_id
   *   The FunctionCall plugin ID to invoke.
   * @param array<string, mixed> $context_values
   *   The context values to set on the tool, keyed by context name.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function invokeToolInWorkspace(?string $workspace_id, string $plugin_id, array $context_values): string {
    $invoke = fn(): string => $this->invokeTool($plugin_id, $context_values);
    return $workspace_id !== NULL
      ? $this->workspaceManager->executeInWorkspace($workspace_id, $invoke)
      : $invoke();
  }

  /**
   * Renders the captured prompt surface to the terminal in sections.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output to write the rendered sections to.
   * @param string[] $sections
   *   The sections to render (see self::SECTIONS). The header always prints.
   * @param array $meta
   *   Header metadata with keys: assistant, agent, provider, model, uid,
   *   message, current_page, view_mode, selection.
   * @param array<int, array{label: string, output: string}> $schemas
   *   The captured read_schema outputs, one per target (empty unless the
   *   schema section is active and a target was resolved).
   * @param string $workspace_note
   *   A note describing how the schema section's workspace was resolved.
   * @param array<int, array{label: string, output: string}> $element_captures
   *   The captured read_element output (zero or one entry; empty unless the
   *   element section is active and a real component was selected).
   * @param string $element_note
   *   A note describing the element section's skip reason or workspace.
   * @param array<int, array{label: string, output: string}> $markup_captures
   *   The captured markup-mode outputs (empty unless the markup section is
   *   active and an entity was given).
   * @param string $markup_note
   *   A note describing the markup section's skip reason or workspace.
   */
  protected function render(OutputInterface $output, array $sections, array $meta, array $schemas, string $workspace_note, array $element_captures, string $element_note, array $markup_captures = [], string $markup_note = ''): void {
    $input = $this->promptCapture->getInput();
    $separator = str_repeat('─', 70);

    $output->writeln('');
    $output->writeln($separator);
    $output->writeln(sprintf('<info>PROMPT AUDIT</info> — assistant: <info>%s</info> (agent: %s)', $meta['assistant'], $meta['agent'] !== '' ? $meta['agent'] : '(none)'));
    $output->writeln(sprintf('Production target: %s / %s', $meta['provider'] !== '' ? $meta['provider'] : '(none)', $meta['model'] !== '' ? $meta['model'] : '(none)'));
    $output->writeln(sprintf('Assembled as: user %d   |   seed message: "%s"', $meta['uid'], $meta['message']));
    $output->writeln(sprintf('Current page: %s', $meta['current_page']));
    $output->writeln(sprintf('View mode: %s   |   Selection: %s', $meta['view_mode'], $meta['selection'] !== '' ? $meta['selection'] : '(none)'));
    // The design system the brief introduces, and its posture: the one
    // fact the whole styling half of the surface partitions on.
    $system = $this->activeDesignSystem->load();
    $output->writeln(sprintf('Design system: %s   |   Law: %s', $system === NULL ? '(none active)' : sprintf('%s (%s)', $system->label(), $system->id()), $system?->getEnforcement() ?? DesignSystemInterface::ENFORCEMENT_GUARDED));
    $output->writeln($separator);

    if (in_array('system-prompt', $sections, TRUE)) {
      $this->renderSystemPrompt($output, $input);
    }
    if (in_array('messages', $sections, TRUE)) {
      $this->renderMessages($output, $input);
    }
    if (in_array('tools', $sections, TRUE)) {
      $this->renderTools($output, $input);
    }
    if (in_array('schema', $sections, TRUE)) {
      $this->renderToolCapture($output, 'SCHEMA', $schemas, $workspace_note);
    }
    if (in_array('element', $sections, TRUE)) {
      $this->renderToolCapture($output, 'ELEMENT', $element_captures, $element_note);
    }
    if (in_array('markup', $sections, TRUE)) {
      $this->renderToolCapture($output, 'MARKUP', $markup_captures, $markup_note);
    }
  }

  /**
   * Renders the captured system prompt.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The captured chat input.
   */
  protected function renderSystemPrompt(OutputInterface $output, ChatInput $input): void {
    $output->writeln('');
    $output->writeln(sprintf('── SYSTEM PROMPT %s', str_repeat('─', 20)));
    $system_prompt = $input->getSystemPrompt();
    $output->writeln($system_prompt !== '' ? $system_prompt : '<comment>(empty)</comment>');
  }

  /**
   * Renders the captured chat messages.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The captured chat input.
   */
  protected function renderMessages(OutputInterface $output, ChatInput $input): void {
    $messages = $input->getMessages();
    $output->writeln('');
    $output->writeln(sprintf('── MESSAGES (%d) %s', count($messages), str_repeat('─', 20)));
    if ($messages === []) {
      $output->writeln('<comment>(none)</comment>');
    }
    foreach ($messages as $chat_message) {
      $output->writeln(sprintf('<info>[%s]</info>', $chat_message->getRole()));
      $output->writeln($chat_message->getText());
      $output->writeln('');
    }
  }

  /**
   * Renders the captured tool definitions as readable text.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The captured chat input.
   */
  protected function renderTools(OutputInterface $output, ChatInput $input): void {
    $tools = $input->getChatTools();
    $functions = $tools !== NULL ? $tools->getFunctions() : [];
    $output->writeln('');
    $output->writeln(sprintf('── TOOLS (%d) %s', count($functions), str_repeat('─', 20)));
    if ($functions === []) {
      $output->writeln('<comment>(none)</comment>');
      return;
    }

    $names = [];
    foreach ($functions as $function) {
      $names[] = $function->getName();
    }
    $output->writeln('<info>' . implode(', ', $names) . '</info>');
    $output->writeln('');

    // Render each tool as readable text rather than raw JSON: the authored
    // surface (description, per-parameter guidance, required list) is what
    // matters for an audit, and the JSON envelope is pure noise. Drive off
    // renderToolsArray() -- the exact array that would be handed to the
    // provider -- so nothing authored is dropped or paraphrased.
    foreach ($tools->renderToolsArray() as $entry) {
      $function = $entry['function'] ?? [];
      $output->writeln(sprintf('<info>%s</info>: %s', $function['name'] ?? '(unnamed)', $function['description'] ?? ''));
      foreach ($function['parameters']['properties'] ?? [] as $param_name => $param) {
        $type = (string) ($param['type'] ?? '');
        $label = $type !== '' ? sprintf('%s (%s)', $param_name, $type) : (string) $param_name;
        $output->writeln(sprintf('- %s: %s', $label, $param['description'] ?? ''));
      }
      $required = $function['parameters']['required'] ?? [];
      if ($required !== []) {
        $output->writeln(sprintf('- required: %s', implode(', ', $required)));
      }
      $output->writeln('');
    }
  }

  /**
   * Renders a Bucket B tool capture (read_schema or read_element output).
   *
   * Bucket B is the schema/guidance and live component data the AI only sees
   * inside tool responses, not in the static prompt. Each entry's output is the
   * verbatim readable output of the real tool.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The command output.
   * @param string $heading
   *   The section heading (e.g. SCHEMA, ELEMENT).
   * @param array<int, array{label: string, output: string}> $captures
   *   The captured outputs, one per target.
   * @param string $workspace_note
   *   A note describing how the workspace was resolved (or why the capture was
   *   skipped) for this section.
   */
  protected function renderToolCapture(OutputInterface $output, string $heading, array $captures, string $workspace_note): void {
    $output->writeln('');
    $output->writeln(sprintf('── %s (%d) %s', $heading, count($captures), str_repeat('─', 20)));
    $output->writeln(sprintf('<comment>workspace: %s</comment>', $workspace_note));
    if ($captures === []) {
      return;
    }
    foreach ($captures as $capture) {
      $output->writeln('');
      $output->writeln(sprintf('<info>%s</info>', $capture['label']));
      $output->writeln($capture['output'] !== '' ? $capture['output'] : '<comment>(empty)</comment>');
    }
  }

}
