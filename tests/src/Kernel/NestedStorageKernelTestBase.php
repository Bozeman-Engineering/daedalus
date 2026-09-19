<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\Section;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\SectionComponent;
use Drupal\block_content\Entity\BlockContent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

/**
 * Base class for kernel tests involving nested section storage.
 *
 * Provides common setup for testing Layout Builder operations with
 * nested layouts (layout blocks).
 *
 * @group daedalus
 */
abstract class NestedStorageKernelTestBase extends KernelTestBase {

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
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (its services are
    // autowired into daedalus_ui's container); kernel tests do not
    // resolve info.yml dependencies, so the chain is listed explicitly.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The section storage manager.
   *
   * @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface
   */
  protected $sectionStorageManager;

  /**
   * UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content']);

    $this->setUpCurrentUser([], ['configure any layout']);

    // Create block content types.
    $this->createBlockContentType('basic', 'Basic Block');
    $this->createLayoutBlockType();

    // Enable Layout Builder for entity_test.
    $this->enableLayoutBuilder();

    // Get services.
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->sectionStorageManager = $this->container->get('plugin.manager.layout_builder.section_storage');
    $this->uuid = $this->container->get('uuid');
  }

  /**
   * Finds a section's delta within a section list by its daedalus_layout UUID.
   *
   * Test utility formerly provided by the deleted Dropzones service.
   *
   * @param object $section_list
   *   The section list to search.
   * @param string $uuid
   *   The section's daedalus_layout UUID.
   *
   * @return int
   *   The section's delta within the list.
   */
  protected function findSectionDeltaInList(object $section_list, string $uuid): int {
    foreach ($section_list->getSections() as $delta => $section) {
      if ($section->getThirdPartySetting('daedalus_layout', 'uuid') === $uuid) {
        return $delta;
      }
    }
    throw new \RuntimeException("Section with UUID $uuid not found in the section list.");
  }

  /**
   * Creates a block content type.
   *
   * @param string $id
   *   The block type ID.
   * @param string $label
   *   The block type label.
   */
  protected function createBlockContentType(string $id, string $label): void {
    $type = BlockContentType::create([
      'id' => $id,
      'label' => $label,
      'revision' => FALSE,
    ]);
    $type->save();

    // Create entity_view_display for this block type.
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => $id,
      'mode' => 'default',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Creates the LEGACY layout_block bundle with a layout_builder__layout field.
   *
   * Pre-migration fixture: the layout_block bundle is retired (nesting is
   * embedded sections now), but the layout-block migration
   * (LayoutBlockToSectionTransform, LayoutBlockMigrationTest) needs the old
   * data shape to convert, and the display doubles as a second Layout
   * Builder enabled display for EntityViewDisplayWalkerTest.
   */
  protected function createLayoutBlockType(): void {
    // Create the block type.
    $type = BlockContentType::create([
      'id' => 'layout_block',
      'label' => 'Block Layout',
      'revision' => FALSE,
    ]);
    $type->save();

    // Create the field storage for layout_builder__layout.
    FieldStorageConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'block_content',
      'type' => 'layout_section',
    ])->save();

    // Create the field instance.
    FieldConfig::create([
      'field_name' => 'layout_builder__layout',
      'entity_type' => 'block_content',
      'bundle' => 'layout_block',
      'label' => 'Layout',
    ])->save();

    // Create entity_view_display with layout_builder enabled. Overridable
    // matches the recipe's real layout_block bundle (allow_custom): without
    // it, findByContext resolves no overrides storage for a block_content
    // and its nested sections render empty.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'layout_block',
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
   * Enables Layout Builder for entity_test with overrides.
   */
  protected function enableLayoutBuilder(): void {
    // Create a display with Layout Builder enabled.
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
   * Creates an entity_test entity with an initial layout.
   *
   * @param array $sections
   *   Optional array of Section objects.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The created entity.
   */
  protected function createTestEntity(array $sections = []): EntityTest {
    // If no sections provided, create a default one.
    if (empty($sections)) {
      $sections = [$this->createSection()];
    }

    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => $sections,
    ]);
    $entity->save();

    return EntityTest::load($entity->id());
  }

  /**
   * Creates a Section with a UUID.
   *
   * @param string $layout_id
   *   The layout plugin ID.
   * @param array $components
   *   Optional array of SectionComponent objects.
   * @param string|null $uuid
   *   Optional UUID, auto-generated if not provided.
   *
   * @return \Drupal\layout_builder\Section
   *   The created section.
   */
  protected function createSection(string $layout_id = 'layout_onecol', array $components = [], ?string $uuid = NULL): Section {
    $section = new Section($layout_id);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid ?? $this->uuid->generate());

    foreach ($components as $component) {
      $section->appendComponent($component);
    }

    return $section;
  }

  /**
   * Creates a basic inline block component.
   *
   * @param string|null $uuid
   *   Optional component UUID.
   * @param string $region
   *   The region name.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The created component.
   */
  protected function createBasicBlockComponent(?string $uuid = NULL, string $region = 'content'): SectionComponent {
    $block_content = BlockContent::create([
      'type' => 'basic',
      'info' => 'Test basic block',
      'reusable' => FALSE,
    ]);

    $component_uuid = $uuid ?? $this->uuid->generate();
    return new SectionComponent($component_uuid, $region, [
      'id' => 'inline_block:basic',
      'label' => 'Basic block',
      'label_display' => FALSE,
      'block_serialized' => serialize($block_content),
    ]);
  }

  /**
   * Creates a nested-section container component embedding a section.
   *
   * The component uuid IS the section's daedalus_layout uuid (one identity); address
   * the nested section by that uuid everywhere.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to embed. Create it with createSection(); its daedalus_layout uuid
   *   becomes the container identity (one is minted if absent).
   * @param string $region
   *   The region name.
   * @param int $weight
   *   The component weight.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The container component.
   */
  protected function createNestedSectionComponent(Section $section, string $region = 'content', int $weight = 0): SectionComponent {
    return SectionBlock::createComponent($section, $region, $weight);
  }

  /**
   * Creates a legacy layout block component (pre-migration shape).
   *
   * The storage spine no longer traverses these; they exist only so
   * pre-migration fixtures (collapse stamping, migration walkers) can be
   * built. New-shape nesting uses createNestedSectionComponent().
   *
   * @param array $nested_sections
   *   The sections to include in the nested layout.
   * @param string|null $uuid
   *   Optional component UUID.
   * @param string $region
   *   The region name.
   * @param string $label
   *   The component's admin label ('Layout Block' was the shipped default).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The created layout block component.
   */
  protected function createLayoutBlockComponent(array $nested_sections = [], ?string $uuid = NULL, string $region = 'content', string $label = 'Layout Block'): SectionComponent {
    // Create a block_content entity with nested sections.
    $block_content = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Test layout block',
      'reusable' => FALSE,
      'layout_builder__layout' => $nested_sections,
    ]);

    $component_uuid = $uuid ?? $this->uuid->generate();
    return new SectionComponent($component_uuid, $region, [
      'id' => 'inline_block:layout_block',
      'label' => $label,
      'label_display' => FALSE,
      'block_serialized' => serialize($block_content),
    ]);
  }

  /**
   * Gets a NestedSectionStorageInterface for an entity.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   The nested-aware section storage.
   */
  protected function getNestedStorage(EntityTest $entity): NestedSectionStorageInterface {
    $contexts = [
      'entity' => EntityContext::fromEntity($entity),
      'view_mode' => new Context(
        new ContextDefinition('string'),
        'default'
      ),
    ];

    $storage = $this->sectionStorageManager->load('overrides', $contexts);

    // The storage should be auto-wrapped, but ensure it is.
    if (!$storage instanceof NestedSectionStorageInterface) {
      $storage = NestedAwareSectionStorage::wrap($storage);
    }

    return $storage;
  }

  /**
   * Renders an entity's default view to HTML.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Parses rendered HTML for querying.
   */
  protected function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * Reloads an entity and gets fresh storage.
   *
   * This simulates what happens when storage is retrieved from tempstore.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   Fresh nested-aware section storage.
   */
  protected function reloadStorage(EntityTest $entity): NestedSectionStorageInterface {
    // Clear entity cache.
    $this->entityTypeManager->getStorage('entity_test')->resetCache([$entity->id()]);

    // Reload the entity.
    $entity = EntityTest::load($entity->id());

    return $this->getNestedStorage($entity);
  }

}
