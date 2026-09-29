<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\daedalus_blueprint_ai\PageToolBase;

/**
 * The page mutation tool: DOM-vocabulary ops on the dialect.
 *
 * The op-set gate lives HERE, at the tool surface: this
 * tool accepts ONLY the page language's ops, so the model-facing edit
 * grammar is the DOM vocabulary taught in the cached catalog (the six DOM
 * ops and the file declaration beside them), while the spine dispatcher
 * underneath keeps every registered op for other consumers.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:page_edit',
  function_name: 'page_edit',
  name: 'Page Edit',
  description: 'Edits a page with one atomic batch of ops on the page language (the op grammar and element reference live in the "Page language" section): insert/replace/move/remove/set carry dialect HTML and attribute patches, style carries real CSS. Every change to an existing page, structural and styling together, is ONE batch; failed ops roll the whole batch back with corrective errors. Returns the updated page print.',
  group: 'modification_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The entity ID.'),
    ),
    'ops' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Ops'),
      required: TRUE,
      description: new TranslatableMarkup('The op batch: an array of op objects from the page-language op set, applied atomically in order.'),
    ),
    'save' => new ContextDefinition(
      data_type: 'boolean',
      label: new TranslatableMarkup('Save'),
      required: FALSE,
      description: new TranslatableMarkup('TRUE to save directly to the database. When omitted or FALSE, the edit stays staged (visible on the canvas, invisible to visitors) until the user saves.'),
    ),
  ],
)]
class PageEdit extends PageToolBase {

  /**
   * {@inheritdoc}
   *
   * Ops is an array of op objects on the wire (the eb_edit precedent).
   */
  public function normalize(): ToolsFunctionInput {
    $function = parent::normalize();
    $ops = $function->getPropertyByName('ops');
    $ops->setType('array');
    $ops->deleteCustomValue('additionalProperties');
    $ops->setItems(['type' => 'object', 'additionalProperties' => TRUE]);
    // The op set is the registry's (the same list the cached catalog
    // teaches and execute() gates on), never a hand list here.
    $ops->setDescription(sprintf('The op batch: an array of op objects from the page-language op set (%s), applied atomically in order.', implode(', ', $this->operationHandlerRegistry->getPageLanguageOperations())));
    return $function;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // The tool-input fact rides an event before anything judges the input,
    // so dev tools capture exactly what arrived, guards included.
    $this->dispatchToolInput();
    if (!$this->requireValidParams()) {
      return;
    }

    $entity_type = $this->getContextValue('entity_type');
    if (!$this->requireWorkspaceFor($entity_type)) {
      return;
    }
    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }
    $entity = $this->loadAnyEntity($entity_type, $this->getContextValue('entity_id'), 'update');
    if (!$entity) {
      return;
    }

    $ops = $this->getContextValue('ops');
    // A bare op object is a one-element batch (the spine honors the same
    // leniency); the gate below needs the list shape.
    if (isset($ops['op'])) {
      $ops = [$ops];
    }
    // The page language's op set: the DOM vocabulary plus the gestures the
    // registered handlers declare as page ops (capturing a stencil). One
    // source (the registry) for this gate and the cached catalog.
    $page_ops = $this->operationHandlerRegistry->getPageLanguageOperations();
    foreach (is_array($ops) ? $ops : [] as $i => $op) {
      $name = is_array($op) ? ($op['op'] ?? NULL) : NULL;
      if (!is_string($name) || !in_array($name, $page_ops, TRUE)) {
        $this->output = sprintf(
          'Error: op %d ("%s") is not in the page edit grammar. The entire op set is: %s. Structure is HTML (insert/replace carry dialect fragments), styling is CSS (style, or set\'s style/class attrs), variants are class/axis attributes (set\'s detach unlinks one).',
          $i,
          is_string($name) ? $name : '(missing op key)',
          implode(', ', $page_ops),
        );
        return;
      }
    }

    $save = $this->getContextValue('save');
    try {
      $result = $backend->batchOperations($entity, $ops, $this->dialectOptions());
      // The batch is this tool's parse: fragments and CSS parse inside the
      // spine ops, so the result is the parse verdict on the input.
      $this->recordParseVerdict($this->resultVerdict($result));
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }
      if (!$this->persistViaBackend($backend, $result, force_save: (bool) $save)) {
        return;
      }

      $result_array = $result->toArray();
      $result_array['persisted'] = $save ? 'database' : 'tempstore';
      $this->output = $this->formatArray($result_array);
      // The echo is the page language itself: the updated page print (with
      // data-alias names), so the model verifies in the grammar it writes.
      $updated = $result->getEntity();
      if ($updated instanceof FieldableEntityInterface) {
        $this->output .= "\n\nUpdated page:\n" . $this->printPage($updated);
      }
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
