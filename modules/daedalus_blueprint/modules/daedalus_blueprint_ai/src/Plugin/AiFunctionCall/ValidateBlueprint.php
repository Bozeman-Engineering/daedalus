<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Validates a NON-PAGE entity blueprint without creating an entity.
 *
 * A page bundle is refused toward page_create, which judges the whole page
 * before anything is built.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:validate_blueprint',
  function_name: 'eb_validate',
  name: 'Validate Blueprint',
  description: 'Validates a NON-PAGE entity blueprint without creating an entity; pages are page_create\'s job (it judges the whole page before anything is built), never this tool\'s. Works with both content entities and config entities. Returns validation errors if the blueprint is invalid, or success if valid. Use this to check a blueprint before creating an entity.',
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

    // A page bundle is page_create's, which judges the whole page itself;
    // refused before the deserializer runs (a missing bundle falls to the
    // deserializer's own INVALID_BUNDLE, and a config type has no page
    // bundle: PageHosts::bundleHost() answers NULL for it).
    $bundle = $data['bundle'] ?? NULL;
    if (is_string($bundle) && $bundle !== '' && $this->pageHosts->isPageBundle($entity_type, $bundle)) {
      $this->output = $this->pageCreateCorrective($entity_type, $bundle);
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
