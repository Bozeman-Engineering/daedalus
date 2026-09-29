<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel;

use Drupal\Component\Uuid\Php;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\block_content\BlockContentInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus_blueprint\Kernel\EntityReferenceRevisionsTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Base class for daedalus_blueprint kernel tests.
 *
 * Provides common setup for testing field handlers, serialization, and
 * validation against real Drupal entity types and field definitions.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus')]
abstract class BlueprintKernelTestBase extends KernelTestBase {

  use EntityReferenceRevisionsTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // daedalus_ui (enabled by many subclasses) hard-depends on file: its
    // file lane injects file.upload_handler at container compile, and kernel
    // tests do not resolve info.yml dependencies.
    'file',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The field handler manager.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager
   */
  protected $fieldHandlerManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
    $this->installConfig(['filter', 'text']);

    // Run the base suite as an admin-role user (all permissions) so the
    // serialization/operations tests stay access-transparent: they verify
    // shape and behavior, not access. The read-path access filtering (which
    // now drops fields/references the user cannot view) is exercised by
    // dedicated tests that switch to a restricted user.
    $this->setUpCurrentUser([], [], TRUE);

    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->fieldHandlerManager = $this->container->get('daedalus_blueprint.field_handler_manager');

    $this->createTestFields();
  }

  /**
   * Creates test fields on entity_test for handler testing.
   */
  protected function createTestFields(): void {
    // Simple string field.
    $this->createField('field_subtitle', 'string', [
      'max_length' => 255,
    ]);

    // Integer field.
    $this->createField('field_count', 'integer');

    // Boolean field.
    $this->createField('field_published', 'boolean');

    // Email field.
    $this->createField('field_email', 'email');

    // Text (long) field.
    $this->createField('field_description', 'text_long');

    // Text with summary field.
    $this->createField('field_body', 'text_with_summary');

    // Multi-value string field.
    $this->createField('field_tags_text', 'string', [
      'max_length' => 255,
    ], [
      'cardinality' => -1,
    ]);
  }

  /**
   * Creates a field storage and field config on entity_test.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   * @param array $settings
   *   Field instance settings.
   * @param array $storage_settings
   *   Field storage settings (e.g., cardinality).
   */
  protected function createField(string $field_name, string $field_type, array $settings = [], array $storage_settings = []): void {
    $storage_config = [
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'type' => $field_type,
    ];
    if (isset($storage_settings['cardinality'])) {
      $storage_config['cardinality'] = $storage_settings['cardinality'];
    }
    // Pass type-specific settings to storage.
    if (!empty($settings)) {
      $storage_config['settings'] = $settings;
    }

    FieldStorageConfig::create($storage_config)->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => ucwords(str_replace('_', ' ', str_replace('field_', '', $field_name))),
    ])->save();
  }

  /**
   * Creates a field on an entity_test_rev entity type.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   * @param string $bundle
   *   The bundle (defaults to 'entity_test_rev').
   * @param array $settings
   *   Field storage settings.
   */
  protected function createRevField(string $field_name, string $field_type, string $bundle = 'entity_test_rev', array $settings = []): void {
    // Check if storage already exists (shared across bundles).
    $storage_config = FieldStorageConfig::loadByName('entity_test_rev', $field_name);
    if (!$storage_config) {
      $config = [
        'field_name' => $field_name,
        'entity_type' => 'entity_test_rev',
        'type' => $field_type,
      ];
      if (!empty($settings)) {
        $config['settings'] = $settings;
      }
      FieldStorageConfig::create($config)->save();
    }

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test_rev',
      'bundle' => $bundle,
      'label' => ucwords(str_replace('_', ' ', str_replace('field_', '', $field_name))),
    ])->save();
  }

  /**
   * Creates an entity_test entity with the given field values.
   *
   * @param array $values
   *   Field values to set.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The created entity.
   */
  protected function createTestEntity(array $values = []): object {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $entity = $storage->create($values + ['name' => 'Test entity']);
    $entity->save();
    return $entity;
  }

  /**
   * Creates an entity_test_rev entity with the given field values.
   *
   * @param array $values
   *   Field values to set.
   *
   * @return \Drupal\entity_test\Entity\EntityTestRev
   *   The created entity.
   */
  protected function createRevEntity(array $values = []): object {
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $entity = $storage->create($values + ['name' => 'Test rev entity']);
    $entity->save();
    return $entity;
  }

  /**
   * Enables Layout Builder for an entity type and bundle.
   *
   * Installs required modules and configures the entity view display
   * with Layout Builder overrides enabled.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   */
  protected function enableLayoutBuilder(string $entity_type = 'entity_test', string $bundle = 'entity_test'): void {
    // Enable required modules if not already enabled.
    $this->enableModules(['layout_builder', 'layout_discovery', 'block', 'block_content']);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);

    // Refresh service references after enabling new modules.
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    // Install layout_builder's schema (inline_block_usage table) if not
    // already present.
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    // Create or load the entity view display.
    $display = LayoutBuilderEntityViewDisplay::load("{$entity_type}.{$bundle}.default");
    if (!$display) {
      $display = LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => $entity_type,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }

    $display->enableLayoutBuilder()
      ->setOverridable()
      ->save();
  }

  /**
   * Creates a block_content type (bundle).
   *
   * @param string $type
   *   The block type machine name.
   * @param string $label
   *   The human-readable label.
   */
  protected function createBlockContentType(string $type, string $label): void {
    $this->entityTypeManager
      ->getStorage('block_content_type')
      ->create([
        'id' => $type,
        'label' => $label,
        'revision' => TRUE,
      ])
      ->save();
  }

  /**
   * Creates a field on a block_content type.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   * @param string $block_type
   *   The block content type.
   * @param array $settings
   *   Field storage settings.
   * @param array $storage_settings
   *   Additional storage settings (e.g., cardinality).
   */
  protected function createBlockField(string $field_name, string $field_type, string $block_type, array $settings = [], array $storage_settings = []): void {
    // Check if storage already exists (shared across bundles).
    $storage_config = FieldStorageConfig::loadByName('block_content', $field_name);
    if (!$storage_config) {
      $config = [
        'field_name' => $field_name,
        'entity_type' => 'block_content',
        'type' => $field_type,
      ];
      if (isset($storage_settings['cardinality'])) {
        $config['cardinality'] = $storage_settings['cardinality'];
      }
      if (!empty($settings)) {
        $config['settings'] = $settings;
      }
      FieldStorageConfig::create($config)->save();
    }

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'block_content',
      'bundle' => $block_type,
      'label' => ucwords(str_replace('_', ' ', str_replace('field_', '', $field_name))),
    ])->save();
  }

  /**
   * Creates a Layout Builder section with components.
   *
   * @param string $layout_id
   *   The layout plugin ID.
   * @param array $components
   *   Array of component definitions, each with keys: uuid, region, weight,
   *   and either plugin configuration or inline block data.
   * @param array $layout_settings
   *   Layout settings.
   * @param array $third_party_settings
   *   Third-party settings.
   *
   * @return \Drupal\layout_builder\Section
   *   The created section.
   */
  protected function createSection(string $layout_id, array $components = [], array $layout_settings = [], array $third_party_settings = []): Section {
    $section = new Section($layout_id, $layout_settings, [], $third_party_settings);

    foreach ($components as $comp_def) {
      $uuid = $comp_def['uuid'] ?? (new Php())->generate();
      $region = $comp_def['region'] ?? 'content';
      $configuration = $comp_def['configuration'] ?? [];
      $additional = $comp_def['additional'] ?? [];

      $component = new SectionComponent($uuid, $region, $configuration, $additional);
      if (isset($comp_def['weight'])) {
        $component->setWeight($comp_def['weight']);
      }

      $section->appendComponent($component);
    }

    return $section;
  }

  /**
   * Reads an inline block back out of a component's serialized configuration.
   *
   * @param string $serialized
   *   The component configuration's block_serialized value: the module's own
   *   inline block as InlineBlock::blockSubmit() stores it.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The block, as persisted.
   */
  protected function unserializeBlock(string $serialized): BlockContentInterface {
    // Layout Builder's own inline-block payload, written by the op under test.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    return unserialize($serialized);
  }

  /**
   * Creates an inline block component configuration.
   *
   * @param string $block_type
   *   The block content type machine name.
   * @param object $block_content
   *   The block_content entity (will be serialized).
   * @param string $label
   *   The admin label.
   * @param string $view_mode
   *   The view mode.
   *
   * @return array
   *   Plugin configuration for an InlineBlock component.
   */
  protected function createInlineBlockConfiguration(string $block_type, object $block_content, string $label = '', string $view_mode = 'full'): array {
    $config = [
      'id' => 'inline_block:' . $block_type,
      'provider' => 'layout_builder',
      'label' => $label,
      'label_display' => 'visible',
      'view_mode' => $view_mode,
      'block_serialized' => serialize($block_content),
    ];

    // If block is saved, use revision ID.
    if ($block_content->id()) {
      $config['block_revision_id'] = $block_content->getRevisionId();
      $config['block_serialized'] = NULL;
    }

    return $config;
  }

}
