<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Dialect\HostPrinter;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint_ai\Event\PageToolInputEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for the page tools (page_read / page_edit / page_create).
 *
 * The dialect surface of the tool layer: these tools speak the page
 * language (HTML documents and fragments, DOM ops, real CSS) against the
 * same spine every other consumer rides. The base wires the dialect
 * services and threads the per-call contract (the generated catalog as
 * options['dialect_catalog'] and the conversation id, the runner's job
 * id via ConversationContext, as options['conversation']) so aliases
 * persist per host per conversation and every payload parses against
 * live vocabulary.
 */
abstract class PageToolBase extends DaedalusBlueprintToolBase {

  /**
   * The operation handler registry (the page language's op set).
   */
  protected OperationHandlerRegistry $operationHandlerRegistry;

  /**
   * The model's read of a host: stamp, build, print, aliases, in one.
   */
  protected HostPrinter $hostPrinter;

  /**
   * The DOM operation handler.
   */
  protected DomOperationHandler $domOperationHandler;

  /**
   * The conversation context.
   */
  protected ConversationContext $conversationContext;

  /**
   * The verdict stamp: the host-level refusals a read is headed with.
   */
  protected VerdictStamper $verdictStamper;

  /**
   * The input event dispatched for the current execution, if any.
   *
   * Held so the tool can set the parse verdict on the same instance and
   * re-dispatch it (see PageToolInputEvent's lifecycle docblock).
   */
  protected ?PageToolInputEvent $toolInputEvent = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->operationHandlerRegistry = $container->get('daedalus_blueprint.operation_handler_registry');
    $instance->hostPrinter = $container->get('daedalus_blueprint.host_printer');
    $instance->domOperationHandler = $container->get('daedalus_blueprint.dom_operation_handler');
    $instance->conversationContext = $container->get('daedalus_blueprint_ai.conversation_context');
    $instance->verdictStamper = $container->get('daedalus_blueprint.verdict_stamper');
    return $instance;
  }

  /**
   * Dispatches the tool-input fact: what this tool received, as received.
   *
   * Call FIRST in execute(), before any guard: the point is a record of
   * the raw input even when the tool refuses or dies on it.
   */
  protected function dispatchToolInput(): void {
    $this->toolInputEvent = new PageToolInputEvent(
      $this->getFunctionName(),
      $this->conversationContext->get(),
      $this->getContextValues(),
    );
    $this->eventDispatcher->dispatch($this->toolInputEvent);
  }

  /**
   * Records the parse verdict on the dispatched input event.
   *
   * Re-dispatches the same event instance with the verdict set, so
   * subscribers can complete the record they opened at the top of the
   * execution. A no-op when dispatchToolInput() was never called.
   *
   * @param array $verdict
   *   ['valid' => bool, 'errors' => array] (JSON-safe).
   */
  protected function recordParseVerdict(array $verdict): void {
    if ($this->toolInputEvent === NULL) {
      return;
    }
    $this->toolInputEvent->setVerdict($verdict);
    $this->eventDispatcher->dispatch($this->toolInputEvent);
  }

  /**
   * A parse verdict from a spine batch result (page_edit's parse).
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The batch result.
   *
   * @return array
   *   The verdict for recordParseVerdict().
   */
  protected function resultVerdict(BlueprintResultInterface $result): array {
    return [
      'valid' => $result->isSuccess(),
      'errors' => array_map(
        static fn ($error) => $error instanceof BlueprintError ? $error->toArray() : (string) $error,
        $result->getErrors(),
      ),
    ];
  }

  /**
   * The request options a dialect-surface mutation rides.
   */
  protected function dialectOptions(): array {
    return $this->blueprintOptions() + [
      'dialect_catalog' => $this->catalogBuilder->build(),
      'conversation' => $this->conversationContext->get(),
    ];
  }

  /**
   * The page's own fields as one block, or NULL when there is nothing to say.
   *
   * The serializer's fields map for the host (the map page_create takes),
   * form-only stubs stripped as every AI transport strips them: the model
   * never reads a field it cannot write. A template has no fields of its
   * own (fields() is NULL) and prints none; an entity whose every field is
   * a stub prints none either.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host being read.
   *
   * @return string|null
   *   The block in the tools' array format, newline-terminated, or NULL.
   */
  protected function fieldsBlock(HostInterface $host): ?string {
    if ($host->fields() === NULL) {
      return NULL;
    }
    $fields = FormOnlyStub::strip($this->blueprintOperations->getBlueprint($host)['fields'] ?? []);
    return $fields === [] ? NULL : $this->formatArray(['fields' => $fields]);
  }

  /**
   * Whether a batch wrote the page's own fields (a set on root).
   *
   * Read off the spine's change targets: a target on the host that names a
   * real field, the style map aside (every style write names it, and it
   * is the document's store, not a field of the page).
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The batch's successful result.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the batch ran on.
   *
   * @return bool
   *   TRUE when at least one of the host's own fields changed.
   */
  protected function wroteOwnFields(BlueprintResult $result, HostInterface $host): bool {
    $style_map = $host->styleMapDefinition()?->getName();
    foreach ($result->getTargets() as $target) {
      if (is_array($target) && $target['uuid'] === $host->uuid() && !TargetType::isSentinel($target['field_name']) && $target['field_name'] !== $style_map) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The host-level refusals as one line, or NULL when nothing is refused.
   *
   * The spine's sentences for the root's verdict (VerdictStamper::explain):
   * a page whose layout is its template's, a frozen translation, a
   * required workspace, a missing permission. Heads a read so the model
   * knows where a gesture belongs before it writes.
   */
  protected function hostNotice(HostInterface $host): ?string {
    $sentences = VerdictStamper::explain($this->verdictStamper->verdicts($host, $host->uuid()));
    return $sentences === [] ? NULL : implode(' ', $sentences);
  }

}
