<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint\Tool\ParameterProse;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Reads a content entity, returning either a structural summary or full data.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:read_entity',
  function_name: 'eb_read_entity',
  name: 'Read Entity',
  description: 'Reads a NON-PAGE content entity as a JSON blueprint. In summary mode (default), returns the structural overview — the document as its tree of container, region and block nodes with their UUIDs, labels and styles, but without field values. Use summary mode first to understand the entity, then eb_read_element to drill into a specific element. In full mode, returns all fields and values (every node of the tree in full, its styles on the node) — can be very verbose for large entities.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(ParameterProse::ENTITY_TYPE),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(ParameterProse::ENTITY_ID),
    ),
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      required: FALSE,
      description: new TranslatableMarkup('Reading mode: "summary" (default) for structural overview with UUIDs, or "full" for all field values.'),
    ),
    'langcode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Language Code'),
      required: FALSE,
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(ParameterProse::LANGCODE),
    ),
  ],
)]
class ReadEntity extends DaedalusBlueprintToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = $this->getContextValue('entity_type');

    if (!$this->requireDocumentHostType($entity_type)) {
      return;
    }

    if (!$this->requireWorkspace()) {
      return;
    }

    $entity_id = $this->getContextValue('entity_id');
    $mode = $this->getContextValue('mode') ?: 'summary';
    $langcode = $this->getContextValue('langcode') ?: NULL;

    $host = $this->loadHost($entity_type, $entity_id, 'view');
    if ($host === NULL) {
      return;
    }

    // A page is page_read's, whole (PageHosts::isPage(): the one meaning across
    // the AI doors), in every mode of this tool: the JSON summary and the full
    // read alike. The gate sits before any read of the document, so a
    // never-edited page's template seed is not fired by a read.
    if ($this->pageHosts->isPage($host)) {
      $this->output = $this->pageReadCorrective($host, $entity_type, $entity_id);
      return;
    }

    try {
      // FULL blueprints carry identity-only form-only stubs for structural
      // consumers (the page-elements panel); AI transport strips them — the
      // model never sees fields it cannot write through the blueprint.
      $data = $mode === 'full'
        ? FormOnlyStub::strip($this->blueprintOperations->getBlueprint($host, $langcode))
        : $this->blueprintOperations->getBlueprintSummary($host, $langcode);
      $this->output = $this->formatArray($data);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
