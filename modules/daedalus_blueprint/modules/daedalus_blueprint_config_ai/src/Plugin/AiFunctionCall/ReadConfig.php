<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config_ai\Plugin\AiFunctionCall;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;

/**
 * Reads a config entity, returning either a summary or full data.
 */
#[FunctionCall(
  id: 'daedalus_blueprint_config:read_config',
  function_name: 'eb_config_read',
  name: 'Read Config',
  description: 'Reads a config entity (image_style, filter_format, field_config, etc.). In summary mode (default), returns properties and plugin collections without full configuration details. In full mode, returns all properties and plugin configurations.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The config entity type ID (e.g. image_style, filter_format, field_config).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The config entity machine name (e.g. "large", "full_html").'),
    ),
    'mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Mode'),
      required: FALSE,
      description: new TranslatableMarkup('Reading mode: "summary" (default) for structural overview, or "full" for all property values.'),
    ),
  ],
)]
class ReadConfig extends DaedalusBlueprintToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = $this->getContextValue('entity_type');
    $entity_id = $this->getContextValue('entity_id');
    $mode = $this->getContextValue('mode') ?: 'summary';

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }

    if (!$backend instanceof ConfigBlueprintBackend) {
      $this->output = "This tool only works with config entities. '$entity_type' is a content entity — use read_entity instead.";
      return;
    }

    $entity = $this->loadAnyEntity($entity_type, $entity_id, 'view');
    if (!$entity) {
      return;
    }

    assert($entity instanceof ConfigEntityInterface);

    try {
      $operations = $backend->getOperations();
      $data = $mode === 'full'
        ? $operations->getBlueprint($entity)
        : $operations->getBlueprintSummary($entity);
      $this->output = $this->formatArray($data);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
