<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint\Tool\ParameterProse;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Reads a single element by UUID from a non-page entity.
 *
 * A block or container answers its node exactly as the full read's layout
 * carries it (a container with its subtree, styles on the node); a
 * paragraph its fields fully populated, with nested content as summaries.
 * Use the UUID from read_entity to target a specific element. A page
 * (PageHosts::isPage(), the one meaning of page across the AI doors) is
 * refused at execute toward its print and page_read.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:read_element',
  function_name: 'eb_read_element',
  name: 'Read Element',
  description: 'Reads a single element (block, container or paragraph) by UUID from a NON-PAGE entity. A block or container answers its node exactly as the full read\'s layout carries it (a container with its whole subtree, every node\'s styles on the node); a paragraph answers its fields fully populated, with nested content as summaries. Use the UUID from read_entity to target the element you want to inspect.',
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
      data_type: 'integer',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(ParameterProse::ENTITY_ID),
    ),
    'element' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Element UUID'),
      required: TRUE,
      description: new TranslatableMarkup('The UUID of the element to read.'),
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
    $langcode = $this->getContextValue('langcode') ?: NULL;

    $host = $this->loadHost($entity_type, $entity_id, 'view');
    if ($host === NULL) {
      return;
    }

    // A page's elements are page_read's (PageHosts::isPage(): the one meaning
    // across the AI doors): the print carries every element by id.
    if ($this->pageHosts->isPage($host)) {
      $this->output = $this->pageReadCorrective($host, $entity_type, $entity_id);
      return;
    }

    try {
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
