<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;

/**
 * Reads the schema for a config entity type.
 */
#[FunctionCall(
  id: 'daedalus_blueprint_config:read_config_schema',
  function_name: 'eb_config_read_schema',
  name: 'Read Config Schema',
  description: 'Reads the schema describing available properties and plugin collections for a config entity type (image_style, filter_format, field_config, etc.). Use this to understand what properties are available before creating or modifying config entities.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The config entity type ID (e.g. image_style, filter_format, field_config).'),
    ),
  ],
)]
class ReadConfigSchema extends DaedalusBlueprintToolBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = $this->getContextValue('entity_type');

    $backend = $this->getBackend($entity_type);
    if ($backend === NULL) {
      return;
    }

    if (!$backend instanceof ConfigBlueprintBackend) {
      $this->output = "This tool only works with config entities. '$entity_type' is a content entity — use read_schema instead.";
      return;
    }

    try {
      $operations = $backend->getOperations();
      $schema = $operations->getSchema($entity_type);
      $this->output = $this->formatArray($schema);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
