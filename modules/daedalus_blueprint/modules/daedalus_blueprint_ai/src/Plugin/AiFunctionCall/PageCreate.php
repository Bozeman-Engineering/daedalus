<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Drupal\daedalus_blueprint_ai\PageToolBase;

/**
 * Creates a new page: a JSON envelope carrying one dialect document.
 *
 * The one-gesture create the dialect promised: entity identity and
 * entity-level fields ride the JSON envelope, the whole layout (with its
 * styles) is ONE page-language document. Non-layout entities (config,
 * variants, swatches, skins, media) stay on eb_create_entity's JSON
 * blueprint; this tool is for pages.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:page_create',
  function_name: 'page_create',
  name: 'Page Create',
  description: 'Creates a new page in one gesture. Workflow: (1) Call eb_skill for create_entity (its checklist names the skills a new page needs). (2) Call eb_read_schema for the chosen type+bundle. (3) Present your plan to the user and wait for approval. (4) Call this tool: entity fields (title etc.) in "fields", the whole layout as ONE page-language document in "layout" (top-level <pb-container> elements, styles inline or in a trailing <style> block). Compose and style together. Saves and publishes the new page at once (a page must exist before edits can stage; every page_edit after this stages until the user saves). Returns the page URL, id echoes, and the page print.',
  group: 'modification_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node).'),
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      required: TRUE,
      description: new TranslatableMarkup('The bundle (e.g. page). eb_read_schema teaches the entity-level fields per bundle.'),
    ),
    'fields' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Fields'),
      required: FALSE,
      description: new TranslatableMarkup('Entity field values (title, ...), never layout: the layout parameter carries the page content.'),
    ),
    'layout' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Layout'),
      required: TRUE,
      description: new TranslatableMarkup('The page as one page-language document.'),
    ),
  ],
)]
class PageCreate extends PageToolBase {

  /**
   * The refusal's closing line: nothing persisted, one whole re-emit.
   */
  private const NOTHING_CREATED = "\n\nNothing was created. Fix the document errors and call page_create again with the whole page.";

  /**
   * {@inheritdoc}
   *
   * The wire description closes with the birth rule the cached container
   * line states, from the same axes (DialectCatalogPrompt::
   * totalStatementProse()): a document is a total statement, and which
   * axes a band states is the site's data, never this attribute's.
   */
  public function normalize(): ToolsFunctionInput {
    $function = parent::normalize();
    $axes = $this->catalogBuilder->build()->axesFor(TRUE, NULL, NULL);
    $function->setDescription(rtrim($function->getDescription()) . DialectCatalogPrompt::totalStatementProse($axes));
    return $function;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // The tool-input fact rides an event before anything judges the input,
    // so dev tools capture exactly what arrived, guards included.
    $this->dispatchToolInput();
    if (!$this->requireValidParams()) {
      return;
    }

    $entity_type = $this->getContextValue('entity_type');
    $bundle = $this->getContextValue('bundle');
    if (!$this->requireContentEntityType($entity_type)) {
      return;
    }
    if (!$this->requireVisibleEntityType($entity_type, $bundle)) {
      return;
    }
    if (!$this->requireWorkspace()) {
      return;
    }

    $layout = (string) $this->getContextValue('layout');
    $options = $this->dialectOptions();

    try {
      $blueprint = [
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'fields' => $this->getContextValue('fields') ?: [],
      ];
      $result = $this->deserializer->deserialize($blueprint, $this->blueprintOptions());
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }
      $entity = $result->getEntity();
      if (!$entity instanceof FieldableEntityInterface) {
        $this->output = 'Error: the built entity is not fieldable.';
        return;
      }

      // Apply the whole document to the IN-MEMORY entity before anything
      // persists: the same spine batch the edit path rides parses the
      // document and validates the layout against the catalog and the
      // style guide, so a refusal costs one corrective and leaves nothing
      // half-made (no bare node to repair with page_edit, no open-link to
      // a page that never formed). One round for every fact: a document
      // that fails the grammar is still judged whole, so its grammar
      // errors and the semantic verdicts on the same bytes (a property
      // outside an element's roster, an unknown variant) come back
      // together, each answered in the spine's shape (context and hints).
      $layout_result = $this->domOperationHandler->applyDocument($entity, $layout, $options);
      $this->recordParseVerdict($this->resultVerdict($layout_result));
      if (!$layout_result->isSuccess()) {
        $this->output = $this->formatResult($layout_result) . self::NOTHING_CREATED;
        return;
      }

      // ONE save lands entity and layout together. Never save a bare
      // entity first: the layoutless original makes layout_builder treat
      // the layout save as a defaults-to-overrides transition and
      // DUPLICATE every inline block (an orphan block per component).
      // The unsaved-host usage gap is daedalus_layout's solved problem: its
      // presave flags each saved block by host uuid and records the
      // inline_block_usage rows post-insert, when the id exists.
      if (!$this->persistEntity($layout_result, is_new: TRUE)) {
        return;
      }

      $result_array = $layout_result->toArray();
      $result_array['entity_id'] = $entity->id();
      $result_array['persisted'] = 'database';
      // Say the publish state outright: without it the model narrates a
      // "draft" that does not exist.
      if ($entity instanceof EntityPublishedInterface) {
        $result_array['status'] = $entity->isPublished() ? 'published' : 'unpublished';
      }
      try {
        $result_array['view_url'] = $entity->toUrl()->toString();
      }
      catch (\Exception) {
      }
      $this->output = $this->formatArray($result_array)
        . "\n\nCreated page:\n" . $this->printPage($entity);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
