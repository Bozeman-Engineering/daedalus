<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Validates an entity blueprint without creating an entity.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:validate_blueprint',
  function_name: 'eb_validate',
  name: 'Validate Blueprint',
  description: 'Validates an entity blueprint without creating an entity. Works with both content entities and config entities. Returns validation errors if the blueprint is invalid, or success if valid. Use this to check a blueprint before creating an entity.',
  group: 'information_tools',
  context_definitions: [
    'blueprint' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Blueprint'),
      required: TRUE,
      description: new TranslatableMarkup('An object to validate. Must include "entity_type". Content: {"entity_type": "node", "bundle": "page", "fields": {"title": "..."}}. Config: {"entity_type": "image_style", "id": "...", "label": "..."}.'),
    ),
  ],
)]
class ValidateBlueprint extends DaedalusBlueprintToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->requireValidParams()) {
      return;
    }

    $data = $this->getContextValue('blueprint');
    $entity_type = $data['entity_type'] ?? '';
    if (!$entity_type) {
      $this->output = 'Error: blueprint must include "entity_type".';
      return;
    }

    if (!$this->requireVisibleEntityType($entity_type)) {
      return;
    }

    if (!$this->requireWorkspaceFor($entity_type)) {
      return;
    }

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }

    try {
      $result = $backend->validate($data);
      $this->output = $this->formatResult($result);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
