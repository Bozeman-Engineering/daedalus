<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;

/**
 * Creates a new content entity from a blueprint.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:create_entity',
  function_name: 'eb_create_entity',
  name: 'Create Entity',
  description: 'Creates a NON-PAGE entity (skins, media, config) from a blueprint; pages are page_create\'s job and materials save_material\'s, never this tool\'s. Workflow: (1) Call eb_skill(create_entity) to discover available entity types and bundles. (2) Call eb_read_schema for the chosen type+bundle to get structure and design guidance. (3) Present your plan to the user and wait for approval. (4) Call this tool with the blueprint. Follow the guidance from the schema. Write text values literally, never HTML-escaped (write &, not &amp;) — HTML entities belong only inside rich-text HTML values.',
  group: 'modification_tools',
  context_definitions: [
    'blueprint' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Blueprint'),
      required: TRUE,
      description: new TranslatableMarkup('An object. Required: "entity_type". All entity field values (title, body, etc.) go inside a "fields" object — never at the top level. The document is the exception: the layout is the top-level "layout" key and the style map the top-level "styles" key, neither inside "fields". See the schema\'s "_structure" note.'),
    ),
  ],
)]
class CreateEntity extends DaedalusBlueprintToolBase {

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

    if (!$this->requireDocumentHostType($entity_type) || !$this->requireFieldableType($entity_type)) {
      return;
    }

    $bundle = $data['bundle'] ?? NULL;
    if (is_string($bundle) && $bundle !== '' && !$this->requireVisibleEntityType($entity_type, $bundle)) {
      return;
    }

    // A page bundle is page_create's, judged before the deserializer runs
    // (a missing bundle falls to the deserializer's own INVALID_BUNDLE).
    if (is_string($bundle) && $bundle !== '' && $this->pageHosts->isPageBundle($entity_type, $bundle)) {
      $this->output = $this->pageCreateCorrective($entity_type, $bundle);
      return;
    }

    if (!$this->requireWorkspace()) {
      return;
    }

    try {
      $result = $this->deserializer->deserialize($data, $this->blueprintOptions());
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }

      if (!$this->persistEntity($result, is_new: TRUE)) {
        return;
      }

      $entity = $result->getEntity();
      $result_array = $result->toArray();
      $result_array['entity_id'] = $entity->id();
      $result_array['persisted'] = 'database';

      try {
        $result_array['view_url'] = $entity->toUrl()->toString();
      }
      catch (\Exception $e) {
      }

      // The whole page is the affected target of a create.
      $this->output = $this->withMarkupEcho($this->formatArray($result_array), $result, whole_entity: TRUE);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
