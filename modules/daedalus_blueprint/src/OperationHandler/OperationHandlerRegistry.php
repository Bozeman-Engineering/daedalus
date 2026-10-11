<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\OperationHandler;

use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\Requirement;
use Drupal\daedalus_blueprint\Dialect\PageLanguageOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\PageOnlyOperationsInterface;

/**
 * Collects operation handler services and resolves operation names to them.
 *
 * Populated by the service_collector compiler pass from services tagged
 * `daedalus_blueprint.operation_handler`. When two handlers claim the same
 * operation name, the higher-priority handler wins. A handler that
 * provides an operation without declaring what it requires
 * (OperationHandlerInterface::getRequirements()) is refused: a default of
 * "needs everything" would keep the code running and hide the contract.
 */
class OperationHandlerRegistry {

  /**
   * Handlers sorted by priority (highest first).
   *
   * @var array<array{priority: int, handler: \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface}>
   */
  protected array $handlers = [];

  /**
   * Operation name to handler map, built lazily.
   *
   * @var array<string, \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface>|null
   */
  protected ?array $operationMap = NULL;

  /**
   * Adds an operation handler.
   *
   * Called by the service_collector compiler pass for each tagged service.
   *
   * @param \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface $handler
   *   The operation handler service.
   * @param int $priority
   *   Handler priority. Higher values win operation name conflicts.
   *
   * @throws \LogicException
   *   When the handler provides an operation its requirements declaration
   *   does not cover, or declares something that is not a Requirement.
   */
  public function addHandler(OperationHandlerInterface $handler, int $priority = 0): void {
    $declared = $handler->getRequirements();
    foreach ($handler->getOperations() as $op) {
      if (!array_key_exists($op, $declared) || !is_array($declared[$op])) {
        throw new \LogicException(sprintf(
          '%s provides the operation "%s" without declaring what it requires: getRequirements() must list every operation with the capabilities (%s) it asks of its targets, an empty list for an operation with nothing to judge.',
          $handler::class,
          $op,
          implode(', ', Capability::names()),
        ));
      }
      foreach ($declared[$op] as $requirement) {
        if (!$requirement instanceof Requirement) {
          throw new \LogicException(sprintf(
            '%s declares a requirement of "%s" that is not a %s; declare the capability (%s) and the parameter naming its target through the Requirement shapes.',
            $handler::class,
            $op,
            Requirement::class,
            implode(', ', Capability::names()),
          ));
        }
      }
    }
    $this->handlers[] = [
      'priority' => $priority,
      'handler' => $handler,
    ];
    usort($this->handlers, fn($a, $b) => $b['priority'] <=> $a['priority']);
    $this->operationMap = NULL;
  }

  /**
   * Gets the handler for an operation name.
   *
   * @param string $op
   *   The operation name.
   *
   * @return \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface|null
   *   The handler, or NULL when no handler provides the operation.
   */
  public function getHandler(string $op): ?OperationHandlerInterface {
    return $this->getOperationMap()[$op] ?? NULL;
  }

  /**
   * Gets all registered operation names.
   *
   * @return string[]
   *   Operation names across all handlers.
   */
  public function getOperationNames(): array {
    return array_keys($this->getOperationMap());
  }

  /**
   * Gets the page language's op set: every handler's declared page ops.
   *
   * See \Drupal\daedalus_blueprint\Dialect\PageLanguageOperationsInterface:
   * page_edit admits exactly these, and the cached catalog teaches them.
   *
   * @return string[]
   *   Operation names, DOM vocabulary first, in handler priority order.
   */
  public function getPageLanguageOperations(): array {
    $ops = [];
    foreach ($this->getOperationMap() as $op => $handler) {
      if ($handler instanceof PageLanguageOperationsInterface && in_array($op, $handler->getPageLanguageOperations(), TRUE)) {
        $ops[] = $op;
      }
    }
    return $ops;
  }

  /**
   * Gets the ops whose only home is a page.
   *
   * The union of the page language's op set (page_edit's surface is a
   * page by definition) and the ops handlers declare page-only because
   * the page language spells their gesture differently (see
   * \Drupal\daedalus_blueprint\Dialect\PageOnlyOperationsInterface). On a
   * site where the page language speaks for pages, eb_edit's
   * module-registered roll-call drops exactly these.
   *
   * @return string[]
   *   Operation names.
   */
  public function getPageOnlyOperations(): array {
    $ops = [];
    foreach ($this->getOperationMap() as $op => $handler) {
      $page_only = ($handler instanceof PageLanguageOperationsInterface && in_array($op, $handler->getPageLanguageOperations(), TRUE))
        || ($handler instanceof PageOnlyOperationsInterface && in_array($op, $handler->getPageOnlyOperations(), TRUE));
      if ($page_only) {
        $ops[] = $op;
      }
    }
    return $ops;
  }

  /**
   * Gets the LLM-facing router lines for all registered operations.
   *
   * See OperationHandlerInterface::getGuidance() for the router-line
   * contract: a short always-on line per op, with doctrine living in the
   * owning module's on-demand skill.
   *
   * @return array<string, string>
   *   One router line keyed by operation name, only for operations whose
   *   handler provides one.
   */
  public function getGuidance(): array {
    $guidance = [];
    foreach ($this->getOperationMap() as $op => $handler) {
      $text = $handler->getGuidance()[$op] ?? NULL;
      if ($text !== NULL) {
        $guidance[$op] = $text;
      }
    }
    return $guidance;
  }

  /**
   * Builds the operation name to handler map.
   *
   * @return array<string, \Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface>
   *   Handlers keyed by operation name, first (highest-priority) claim wins.
   */
  protected function getOperationMap(): array {
    if ($this->operationMap === NULL) {
      $this->operationMap = [];
      foreach ($this->handlers as $candidate) {
        foreach ($candidate['handler']->getOperations() as $op) {
          $this->operationMap[$op] ??= $candidate['handler'];
        }
      }
    }
    return $this->operationMap;
  }

}
