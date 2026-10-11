<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Reads a single element by UUID from a non-page entity.
 *
 * A block or container answers its node exactly as the full read's layout
 * carries it (a container with its subtree, styles on the node); a
 * paragraph its fields fully populated, with nested content as summaries.
 * Use the UUID from read_entity to target a specific element. A page's
 * elements ride its print and page_read (PageHosts::isPage(), the one meaning of
 * page across the AI doors), never this tool.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:read_element',
  function_name: 'eb_read_element',
  name: 'Read Element',
  description: 'Reads a single element (block, container or paragraph) by UUID from a NON-PAGE entity; a page\'s elements ride the current page print and page_read, never this tool. A block or container answers its node exactly as the full read\'s layout carries it (a container with its whole subtree, every node\'s styles on the node); a paragraph answers its fields fully populated, with nested content as summaries. Use the UUID from read_entity to target the element you want to inspect. In markup mode, returns the element as key-annotated HTML instead — the structure your CSS lands on, with data-key carrying the selectable element keys.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node, paragraph, block_content).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'integer',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The ID of the entity containing the element.'),
    ),
    'element' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Element UUID'),
      required: TRUE,
      description: new TranslatableMarkup('The UUID of the element to read.'),
    ),
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      required: FALSE,
      description: new TranslatableMarkup('Reading mode: "full" (default) for the element\'s field data, or "markup" for the element as key-annotated HTML (the rendered structure with selectable element keys).'),
    ),
    'langcode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Language Code'),
      required: FALSE,
      description: new TranslatableMarkup('The language code for translation output. Leave empty for the default language.'),
    ),
  ],
)]
class ReadElement extends DaedalusBlueprintToolBase {

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
    $element = $this->getContextValue('element');
    $mode = $this->getContextValue('mode') ?: 'full';
    $langcode = $this->getContextValue('langcode') ?: NULL;

    $host = $this->loadHost($entity_type, $entity_id, 'view');
    if ($host === NULL) {
      return;
    }

    // A page's elements are page_read's (PageHosts::isPage(): the one meaning across
    // the AI doors), in both modes of this tool: the print carries every
    // element by id, and the projection of a page's element goes with the
    // JSON road.
    if ($this->pageHosts->isPage($host)) {
      $this->output = $this->pageReadCorrective($host, $entity_type, $entity_id);
      return;
    }

    try {
      // Markup mode rides the projection socket: the element model's HTML
      // print format, supplied by the model's owner (the page builder).
      if ($mode === 'markup') {
        $html = $this->markupProjection->projectElement($host, $element, $langcode);
        if ($html === NULL && $this->markupProjection->hasProjector()) {
          $this->output = "Element with UUID '$element' not found in $entity_type:$entity_id.";
          return;
        }
        $this->output = $this->formatMarkupProjection($html);
        return;
      }
      $data = $this->blueprintOperations->getElement($host, $element, $langcode);
      if ($data === NULL) {
        $this->output = "Element with UUID '$element' not found in $entity_type:$entity_id.";
        return;
      }
      $this->output = $this->formatArray($data);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
