<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Reads a content entity, returning either a structural summary or full data.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:read_entity',
  function_name: 'eb_read_entity',
  name: 'Read Entity',
  description: 'Reads a NON-PAGE content entity as a JSON blueprint; pages ride the current page print and page_read, never this tool. In summary mode (default), returns the structural overview — the document as its tree of container, region and block nodes with their UUIDs, labels and styles, but without field values. Use summary mode first to understand the entity, then eb_read_element to drill into a specific element. In full mode, returns all fields and values (every node of the tree in full, its styles on the node) — can be very verbose for large entities. In markup mode, returns the entity as key-annotated HTML: data-key attributes carry the selectable element keys and inner elements wear the classes that compose sub-element keys (KEY::.class-name).',
  group: 'information_tools',
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
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      required: FALSE,
      description: new TranslatableMarkup('Reading mode: "summary" (default) for structural overview with UUIDs, "full" for all field values, or "markup" for the entity as key-annotated HTML (the rendered structure with selectable element keys).'),
    ),
    'langcode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Language Code'),
      required: FALSE,
      description: new TranslatableMarkup('The language code for translation output. Leave empty for the default language.'),
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

    // A page is page_read's, whole (PageHosts::isPage(): the one meaning across the
    // AI doors), in every mode of this tool: the JSON summary, the full
    // read and the markup projection alike. The gate sits before any read
    // of the document, so a never-edited page's template seed is not fired
    // by a read.
    if ($this->pageHosts->isPage($host)) {
      $this->output = $this->pageReadCorrective($host, $entity_type, $entity_id);
      return;
    }

    try {
      // Markup mode rides the projection socket: the element model's HTML
      // print format, supplied by the model's owner (the page builder).
      if ($mode === 'markup') {
        $this->output = $this->formatMarkupProjection(
          $this->markupProjection->projectHost($host, $langcode),
        );
        return;
      }
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
