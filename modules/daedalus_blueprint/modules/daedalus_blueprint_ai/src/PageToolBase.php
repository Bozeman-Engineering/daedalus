<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
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
 * persist per entity per conversation and every payload parses against
 * live vocabulary.
 */
abstract class PageToolBase extends DaedalusBlueprintToolBase {

  /**
   * The dialect catalog builder.
   */
  protected DialectCatalogBuilder $catalogBuilder;

  /**
   * The operation handler registry (the page language's op set).
   */
  protected OperationHandlerRegistry $operationHandlerRegistry;

  /**
   * The lean node tree builder.
   */
  protected NodeTreeBuilder $treeBuilder;

  /**
   * The dialect printer.
   */
  protected DialectPrinter $dialectPrinter;

  /**
   * The dialect alias map.
   */
  protected DialectAliasMap $aliasMap;

  /**
   * The DOM operation handler.
   */
  protected DomOperationHandler $domOperationHandler;

  /**
   * The conversation context.
   */
  protected ConversationContext $conversationContext;

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
    $instance->catalogBuilder = $container->get('daedalus_blueprint.dialect_catalog_builder');
    $instance->operationHandlerRegistry = $container->get('daedalus_blueprint.operation_handler_registry');
    $instance->treeBuilder = $container->get('daedalus_blueprint.node_tree_builder');
    $instance->dialectPrinter = $container->get('daedalus_blueprint.dialect_printer');
    $instance->aliasMap = $container->get('daedalus_blueprint.dialect_alias_map');
    $instance->domOperationHandler = $container->get('daedalus_blueprint.dom_operation_handler');
    $instance->conversationContext = $container->get('daedalus_blueprint_ai.conversation_context');
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
   * Prints an entity's current page in the dialect, aliases echoed.
   */
  protected function printPage(FieldableEntityInterface $entity): string {
    return $this->dialectPrinter->print(
      $this->treeBuilder->build($entity),
      $this->catalogBuilder->build(),
      $this->aliasMap->printAliases($entity, $this->conversationContext->get()),
    );
  }

}
