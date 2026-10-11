<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectSchemaProjector;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_ai\DaedalusBlueprintToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the schema describing available fields and types for an entity type.
 *
 * A page-builder bundle (one carrying a layout the dialect catalog can
 * write) answers in the page language's view (DialectSchemaProjector):
 * the entity fields, what each element is for, the materials (every kind,
 * full text), and none of the JSON layout grammar the cached catalog would
 * contradict. Every other bundle answers with the JSON blueprint schema,
 * the grammar eb_create_entity and eb_edit take.
 *
 * The materials' blueprints are the site favorites in full, with the
 * group index beside them (MaterialsSchema); `group` names one group (a
 * declared id, or DialectCatalog::UNGROUPED) to list in full instead,
 * narrowed AFTER the cached schema is read (MaterialsSchema::narrow(): the
 * schema cache key never carries it). An unknown group answers a
 * corrective naming the ids.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:read_schema',
  function_name: 'eb_read_schema',
  name: 'Read Schema',
  description: 'Reads an entity type\'s schema. A page-builder bundle (pages): the entity fields page_create takes, what each page-language element is for, and the materials in full. Any other bundle: the fields in the JSON shape eb_create_entity and eb_edit take. Read it before designing a new page or minting a material.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node, block_content).'),
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      required: FALSE,
      description: new TranslatableMarkup('The bundle machine name. Required for entity types with bundles (node, block_content, paragraph).'),
    ),
    'view_mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('View Mode'),
      required: FALSE,
      description: new TranslatableMarkup('The Layout Builder view mode to filter available block types by restrictions. Defaults to "full" when not specified.'),
    ),
    'group' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Group'),
      required: FALSE,
      description: new TranslatableMarkup('A group id from the schema\'s materials.groups, or ungrouped: materials.blueprints then lists that group\'s blueprints in full instead of the site favorites.'),
    ),
  ],
)]
class ReadSchema extends DaedalusBlueprintToolBase {

  /**
   * The dialect schema projector.
   */
  protected DialectSchemaProjector $schemaProjector;

  /**
   * The materials fragment, for the group narrowing after the cache.
   */
  protected MaterialsSchema $materialsSchema;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->schemaProjector = $container->get('daedalus_blueprint.dialect_schema_projector');
    $instance->materialsSchema = $container->get('daedalus_blueprint.materials_schema');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The wire description derives what the attribute cannot: the material
   * kinds the schema carries in full (MaterialKind, so a kind added to
   * the roster is named here by data) and the mint gate (MaterialsProse,
   * the one sentence every mint surface states).
   */
  public function normalize(): ToolsFunctionInput {
    $function = parent::normalize();
    $function->setDescription(sprintf(
      'Reads an entity type\'s schema. A page-builder bundle (pages): the entity fields page_create takes, what each page-language element is for, and the materials in full (%s, each with its whole description). Any other bundle: the fields in the JSON shape eb_create_entity and eb_edit take. Read it before designing a new page or minting a material (%s).',
      implode(', ', array_map(static fn (MaterialKind $kind): string => $kind->plural(), MaterialKind::cases())),
      rtrim(MaterialsProse::mintGateSentence(), '.'),
    ));
    return $function;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = $this->getContextValue('entity_type');

    if (!$this->requireDocumentHostType($entity_type) || !$this->requireFieldableType($entity_type)) {
      return;
    }

    if (!$this->requireWorkspace()) {
      return;
    }

    $bundle = $this->getContextValue('bundle') ?: NULL;
    if ($bundle !== NULL && !$this->requireVisibleEntityType($entity_type, $bundle)) {
      return;
    }
    $view_mode = $this->getContextValue('view_mode') ?: NULL;
    $group = trim((string) $this->getContextValue('group'));

    try {
      $schema = $this->schemaBuilder->getSchema($entity_type, $bundle ?: $entity_type, $view_mode);
      // One group in full, in place of the favorites: narrowed here, after
      // the cached schema is read, so the cache key carries no group.
      if ($group !== '') {
        if (!isset($schema[DialectSchemaProjector::MATERIALS])) {
          $this->output = sprintf('Error: group narrows materials.blueprints, and the schema of %s/%s carries no materials.', $entity_type, $bundle ?: $entity_type);
          return;
        }
        $ids = $this->materialsSchema->groupIds();
        if (!in_array($group, $ids, TRUE)) {
          $declared = array_diff($ids, [DialectCatalog::UNGROUPED]);
          $this->output = sprintf(
            'Error: unknown group "%s". %s; %s lists the blueprints in no group. Pass one of them, or omit group for the site favorites.',
            $group,
            $declared === [] ? 'This site declares no groups' : 'The groups are ' . implode(', ', $declared) . ' (materials.groups in the schema read)',
            DialectCatalog::UNGROUPED,
          );
          return;
        }
        $host = $this->pageHosts->bundleHost($entity_type, $bundle ?: $entity_type);
        $schema[DialectSchemaProjector::MATERIALS] = $this->materialsSchema->narrow($schema[DialectSchemaProjector::MATERIALS], $host, $group);
      }
      // The projection teaches, so it reads the catalog's offered view: an
      // element's axis values and class roster name only the materials
      // their authors share with the AI (the materials fragment beneath
      // already does, MaterialsSchema).
      $catalog = $this->catalogBuilder->build()->offered();
      if ($this->schemaProjector->applies($schema, $catalog)) {
        $schema = $this->schemaProjector->project($schema, $catalog);
      }
      $this->output = $this->formatArray($schema);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
