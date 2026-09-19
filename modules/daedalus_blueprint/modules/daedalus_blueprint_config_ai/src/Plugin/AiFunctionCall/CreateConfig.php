<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;

/**
 * Creates a new config entity from a blueprint.
 */
#[FunctionCall(
  id: 'daedalus_blueprint_config:create_config',
  function_name: 'eb_config_create',
  name: 'Create Config',
  description: 'Creates a new config entity from a blueprint. Workflow: (1) Call eb_list_bundles to identify the entity type. (2) Call eb_config_read_schema for that type. (3) Present your plan to the user and wait for approval. (4) Call this tool. Format: {"entity_type": "image_style", "id": "my_style", "label": "My Style", "properties": {...}}.',
  group: 'modification_tools',
  context_definitions: [
    'blueprint' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Blueprint'),
      required: TRUE,
      description: new TranslatableMarkup('An object: {"entity_type": "image_style", "id": "my_style", "label": "My Style", "properties": {...}}. The "entity_type" key is always required.'),
    ),
  ],
)]
class CreateConfig extends DaedalusBlueprintToolBase {

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

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }

    if (!$backend instanceof ConfigBlueprintBackend) {
      $this->output = "This tool only works with config entities. '$entity_type' is a content entity — use create_entity instead.";
      return;
    }

    try {
      $result = $backend->create($data);
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }

      if (!$this->persistViaBackend($backend, $result, is_new: TRUE)) {
        return;
      }

      $entity = $result->getEntity();
      $result_array = $result->toArray();
      $result_array['entity_id'] = $entity->id();
      $result_array['persisted'] = 'database';
      $this->output = $this->formatArray($result_array);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
