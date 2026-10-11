<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint_library\BlueprintLayout;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Base for blueprint kernel tests: the editing stack and shared fixtures.
 *
 * Provides the full daedalus_layout editing stack (blueprints ride nested
 * sections), inline block types, the blueprint entity type with its
 * install config, and the host-entity helpers every blueprint test needs.
 * Subclasses add daedalus_stylish or the fixture test modules they
 * exercise.
 */
abstract class BlueprintKernelTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'field',
    'text',
    'filter',
    'entity_test',
    // The blueprint entity's icon field references media, so installing
    // the blueprint schema needs the media entity type (and its field
    // types); its scope is a list_string.
    'file',
    'image',
    'media',
    'options',
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (its services are
    // autowired into daedalus_ui's container); kernel tests do not
    // resolve info.yml dependencies, so the chain is listed explicitly.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_blueprint_library',
  ];

  /**
   * Permissions granted to the test user.
   *
   * @var string[]
   */
  protected static array $permissions = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    // Discovery merges blueprint content entities over the file set, so it
    // queries blueprint storage on every rebuild even when no content
    // exists; the type's view mode, Layout Builder display and layout
    // field ship as the module's install config.
    $this->installEntitySchema('blueprint');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->setUpCurrentUser([], static::$permissions, TRUE);

    $this->createBlockContentType('basic');
    $this->createBlockContentType('text');

    // On a site that installs daedalus_stylish, this save and the blueprint
    // displays' import above are what open the style stores: entity_test
    // has no bundle entity type to carry an Edit Mode setting and a
    // blueprint type answers the gate as on by construction, so the field
    // manager's gate passes on Layout Builder alone and creates the style
    // map field at the display save.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->setThirdPartySetting('daedalus_layout', 'default_section', [
        'layout_plugin' => 'layout_onecol',
        'label' => '',
      ])
      ->save();
  }

  /**
   * The blueprint operations service.
   */
  protected function operations(): BlueprintOperationsInterface {
    return \Drupal::service('daedalus_blueprint.operations');
  }

  /**
   * The host of an entity, as the spine's doors resolve it.
   */
  protected function host(FieldableEntityInterface $entity): HostInterface {
    return \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($entity);
  }

  /**
   * The page-language request options: the site vocabulary and the thread.
   *
   * A bare onecol catalog (no axes, no block types) for a batch of DOM ops
   * or material gestures against an entity_test host.
   *
   * @param bool $with_roster
   *   TRUE to carry the live materials roster (the blueprints saved so
   *   far), rebuilt from storage, so a fragment can spell blueprint="" or
   *   <pb-blueprint name=""> against it.
   */
  protected function dialectOptions(bool $with_roster = FALSE): array {
    $roster = NULL;
    if ($with_roster) {
      $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
      $builder->reset();
      $roster = $builder->build();
    }
    return [
      'dialect_catalog' => new DialectCatalog(
        containerLayoutId: 'layout_onecol',
        axes: [],
        blockTypes: [],
        conditions: [],
        roster: $roster,
      ),
      'conversation' => 'conv-1',
    ];
  }

  /**
   * Creates a host entity with one empty root section.
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, root_section: string}
   *   The host entity and its root section's uuid.
   */
  protected function createHost(): array {
    $section_uuid = \Drupal::service('uuid')->generate();
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $entity = EntityTest::create(['layout_builder__layout' => [$section]]);
    $entity->save();
    return [
      'entity' => EntityTest::load($entity->id()),
      'root_section' => $section_uuid,
    ];
  }

  /**
   * A section with a daedalus_layout uuid, optionally holding components.
   *
   * @param string $layout_id
   *   The layout plugin id.
   * @param \Drupal\layout_builder\SectionComponent[] $components
   *   The components to place in the layout's first region.
   * @param string|null $uuid
   *   The section's uuid, minted when NULL.
   */
  protected function section(string $layout_id = 'layout_onecol', array $components = [], ?string $uuid = NULL): Section {
    $section = new Section($layout_id, [], $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid ?? \Drupal::service('uuid')->generate());
    return $section;
  }

  /**
   * A plugin block component in a region.
   */
  protected function pluginComponent(string $plugin_id, string $region = 'content', ?string $uuid = NULL): SectionComponent {
    return new SectionComponent($uuid ?? \Drupal::service('uuid')->generate(), $region, ['id' => $plugin_id]);
  }

  /**
   * Creates and saves a published block blueprint for entity_test hosts.
   *
   * The document is written as the saved sections given (one empty
   * onecol container by default), the way a capture or a canvas commit
   * leaves it; discovery reads it back through the entity's host.
   *
   * @param array $values
   *   Entity values overriding the defaults.
   * @param \Drupal\layout_builder\Section[]|null $sections
   *   The document's sections, or NULL for one empty onecol container.
   */
  protected function createBlueprint(array $values = [], ?array $sections = NULL): Blueprint {
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
      'label' => 'Testimonial',
      'description' => 'A quote beside a portrait.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      'layout_builder__layout' => $sections ?? [$this->section()],
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * The lean tree of a blueprint's saved document, as discovery builds it.
   */
  protected function builtLayout(Blueprint $blueprint): array {
    $fresh = \Drupal::entityTypeManager()->getStorage('blueprint')->loadUnchanged($blueprint->id());
    return \Drupal::service('daedalus_blueprint.node_tree_builder')->build($this->host($fresh));
  }

  /**
   * A blueprint's saved document as a definition: no identity, no stamps.
   *
   * What a capture of the same subtree reads, so a capture compares to
   * the saved blueprint without the fresh uuids the instantiator minted.
   */
  protected function definitionOf(Blueprint $blueprint): array {
    return BlueprintLayout::withoutProvenance(BlueprintLayout::withoutUuids($this->builtLayout($blueprint)));
  }

  /**
   * Loads a saved blueprint by machine name, uncached.
   */
  protected function loadBlueprint(string $machine_name): Blueprint {
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $storage->resetCache();
    $blueprints = $storage->loadByProperties(['machine_name' => $machine_name]);
    $blueprint = reset($blueprints);
    $this->assertInstanceOf(Blueprint::class, $blueprint, sprintf('The "%s" blueprint exists.', $machine_name));
    return $blueprint;
  }

  /**
   * Asserts a batch succeeded, dumping its errors when it did not.
   */
  protected function assertBatchSuccess($result): void {
    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn ($e) => $e->toArray(), $result->getErrors())));
  }

  /**
   * Creates a block content type with a view display.
   */
  protected function createBlockContentType(string $id): void {
    BlockContentType::create(['id' => $id, 'label' => $id, 'revision' => FALSE])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => $id,
      'mode' => 'default',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Creates a field on a block type.
   */
  protected function createBlockField(string $field_name, string $bundle, string $type, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'block_content',
      'type' => $type,
      'settings' => $settings,
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'block_content',
      'bundle' => $bundle,
      'label' => $field_name,
    ])->save();
  }

  /**
   * Builds the reference CTA section on a host, via the spine.
   *
   * The reference shape every blueprint test walks: a labeled two-column
   * section (non-default column widths) holding a text block (pinned
   * heading, entity reference, display label) and a basic block, with a
   * nested onecol section beside the basic block — sections all the way
   * down. Requires the text bundle's field_heading/field_ref fields
   * (createReferenceFields()).
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, root_section: string, section: string, text: string, inner: string}
   *   The host and the uuids the build pinned.
   */
  protected function buildReferenceSection(): array {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $uuid = \Drupal::service('uuid');
    $section = $uuid->generate();
    $text = $uuid->generate();
    $inner = $uuid->generate();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $section,
        'layout' => 'layout_twocol_section',
        'layout_settings' => ['column_widths' => '33-67'],
      ],
      [
        'op' => 'update_element',
        'element' => $section,
        'properties' => ['label' => 'Call to Action'],
      ],
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'first'],
        'block_type' => 'text',
        'uuid' => $text,
        'properties' => [
          'field_heading' => 'Pinned heading',
          'field_ref' => ['target_id' => (int) \Drupal::currentUser()->id()],
          'label' => 'My heading',
        ],
      ],
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
        'block_type' => 'basic',
      ],
      [
        'op' => 'add_section',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
        'uuid' => $inner,
      ],
      [
        'op' => 'add_element',
        'location' => ['anchor' => $inner, 'placement' => 'inside'],
        'block_type' => 'basic',
      ],
    ]);
    $this->assertBatchSuccess($result);

    return [
      'entity' => $entity,
      'root_section' => $root_section,
      'section' => $section,
      'text' => $text,
      'inner' => $inner,
    ];
  }

  /**
   * Creates the reference fields buildReferenceSection() pins.
   */
  protected function createReferenceFields(): void {
    $this->createBlockField('field_heading', 'text', 'string');
    $this->createBlockField('field_ref', 'text', 'entity_reference', ['target_type' => 'user']);
  }

  /**
   * A placed subtree as a definition: the host's tree at the element, id-less.
   *
   * What a capture of the element reads, so a saved blueprint's document
   * (definitionOf()) compares to its source after the instantiator minted
   * fresh uuids. For the reference section (buildReferenceSection()) the
   * tree is the serializer's: the labeled two-column container with its
   * column widths, the text block with its bundle, display label and
   * fields (field_ref PRESERVED with its site-local target, the firm
   * capture requirement), the basic block, the nested onecol container.
   */
  protected function definitionAt(HostInterface $host, string $uuid): array {
    $node = NodeTreeBuilder::find(\Drupal::service('daedalus_blueprint.node_tree_builder')->build($host), $uuid);
    $this->assertNotNull($node, sprintf('The host carries "%s".', $uuid));
    return BlueprintLayout::withoutProvenance(BlueprintLayout::withoutUuids([$node]))[0];
  }

  /**
   * Creates and saves a blueprint whose document is instantiated from a tree.
   *
   * The way a capture writes a blueprint (BlueprintManager::place()): the
   * lean nodes land on the unsaved blueprint's host through the one
   * placement machinery as a system batch, styles included, and the host
   * commits through the storage handler. For a document carrying styles
   * the bundle needs its style map field, which daedalus_stylish's field
   * manager creates at the display import on a site that installs it.
   *
   * @param array $values
   *   Entity values overriding the defaults (label, description, scope,
   *   subject for entity_test hosts).
   * @param array $nodes
   *   The root nodes, in the tree grammar (a file's bare variant ids
   *   included).
   */
  protected function createBlueprintFromTree(array $values, array $nodes): Blueprint {
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
      'label' => 'Testimonial',
      'description' => 'A quote beside a portrait.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
    ]);
    $host = $this->host($blueprint);
    $result = \Drupal::service('daedalus_blueprint.document_instantiator')->instantiate($host, $nodes, ['anchor' => $host->uuid(), 'placement' => 'inside'], [
      'indexed' => TRUE,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]);
    $this->assertBatchSuccess($result);
    \Drupal::service('daedalus_blueprint.storage_handler')->commit($host);
    return $blueprint;
  }

}
