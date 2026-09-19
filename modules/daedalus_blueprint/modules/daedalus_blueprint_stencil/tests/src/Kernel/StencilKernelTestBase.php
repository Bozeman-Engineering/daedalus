<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Base for stencil kernel tests: the editing stack and shared fixtures.
 *
 * Provides the full daedalus_layout editing stack (stencils ride nested sections),
 * inline block types, and the host-entity helpers every stencil test needs.
 * Subclasses add daedalus_stylish or the fixture test modules they exercise.
 */
abstract class StencilKernelTestBase extends KernelTestBase {

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
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'file',
    'image',
    'media',
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (its services are
    // autowired into daedalus_ui's container); kernel tests do not
    // resolve info.yml dependencies, so the chain is listed explicitly.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_blueprint_stencil',
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
    // Discovery merges stencil content entities over the file set, so it
    // queries stencil storage on every rebuild even when no content exists.
    $this->installEntitySchema('stencil');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content']);
    $this->setUpCurrentUser([], static::$permissions, TRUE);

    $this->createBlockContentType('basic');
    $this->createBlockContentType('text');

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
   * Creates the daedalus_stylish_styles map field on the host bundle directly.
   *
   * The field daedalus_stylish's field manager would create when styling is enabled
   * for the bundle; created raw where a test wants the map without the
   * styling validation stack.
   */
  protected function createStyleMapField(): void {
    FieldStorageConfig::create([
      'field_name' => 'daedalus_stylish_styles',
      'entity_type' => 'entity_test',
      'type' => 'daedalus_stylish_style_map',
    ])->save();
    FieldConfig::create([
      'field_name' => 'daedalus_stylish_styles',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Styles',
    ])->save();
  }

  /**
   * Builds the reference CTA section on a host, via the spine.
   *
   * The reference shape every stencil test walks: a labeled two-column
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

    $result = $this->operations()->batchOperations($entity, [
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
   * The reference section's expected capture, without styles.
   *
   * References are PRESERVED (the firm capture requirement): field_ref
   * carries its site-local target.
   */
  protected function expectedReferenceElement(): array {
    return [
      'kind' => 'container',
      'layout_id' => 'layout_twocol_section',
      'label' => 'Call to Action',
      'layout_settings' => ['column_widths' => '33-67'],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'first',
          'children' => [
            [
              'kind' => 'block',
              'block_type' => 'text',
              'block_fields' => [
                'info' => 'My heading',
                'field_heading' => 'Pinned heading',
                'field_ref' => [
                  'target_id' => (int) \Drupal::currentUser()->id(),
                  '_label' => \Drupal::currentUser()->getAccountName(),
                ],
                'label' => 'My heading',
              ],
            ],
          ],
        ],
        [
          'kind' => 'region',
          'region' => 'second',
          'children' => [
            [
              'kind' => 'block',
              'block_type' => 'basic',
              'block_fields' => ['info' => 'basic'],
            ],
            [
              'kind' => 'container',
              'layout_id' => 'layout_onecol',
              'children' => [
                [
                  'kind' => 'region',
                  'region' => 'content',
                  'children' => [
                    [
                      'kind' => 'block',
                      'block_type' => 'basic',
                      'block_fields' => ['info' => 'basic'],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

}
