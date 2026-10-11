<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests declarative composition under the one container model.
 *
 * Sections are the one container concept: they nest inside sections at any
 * depth, either operationally (add_section with placement 'inside', then
 * children anchored on the minted section uuid) or declaratively (a section
 * node inside a region of a created entity's layout tree). Blocks
 * never hold nested layouts; the retired layout-capable-block grammar
 * (data.layout, data.section_uuid) is refused loudly with a
 * pointer at the one placement grammar. The read shape IS the write shape:
 * the tree of a read creates an equal entity with
 * zero reshaping.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSubtreeAddTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The daedalus_layout UUID of the root section components are placed into.
   */
  protected string $rootSectionUuid;

  /**
   * The host entity holding the root section.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'file',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);

    $this->rootSectionUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * A fresh nested-aware section storage for the host entity.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   The storage.
   */
  protected function getStorage(): object {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->entity)->sectionStorage();
  }

  /**
   * Tests data.layout on a block add is refused.
   */
  public function testSubtreeDataKeyRefused(): void {
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'layout' => [
        ['layout_id' => 'layout_twocol_section', 'regions' => []],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('data.layout', $errors[0]->getPath());
    $this->assertSame('Blocks do not hold nested layouts.', $errors[0]->getMessage());
    $this->assertStringContainsString('add_section', $errors[0]->getHint());
  }

  /**
   * Tests data.section_uuid on a block add is refused.
   */
  public function testSectionUuidDataKeyRefused(): void {
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'section_uuid' => (new PhpUuid())->generate(),
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('data.section_uuid', $errors[0]->getPath());
    $this->assertStringContainsString('add_section', $errors[0]->getHint());
  }

  /**
   * Tests layout inside the property bag is refused.
   */
  public function testSubtreeInPropertiesRefused(): void {
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'block_fields' => [
        'layout' => [],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('properties.layout', $errors[0]->getPath());
    $this->assertStringContainsString('add_section', $errors[0]->getHint());
  }

  /**
   * Tests one batch composes a nested section and addresses its children.
   *
   * The composition route: add_section with placement 'inside' mints the
   * nested section's identity born as its explicit layout, and child
   * add_element / update_element operations anchor on uuids that did not
   * exist before the batch ran.
   */
  public function testBatchComposesNestedSectionAndAddressesChildren(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();

    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => [
          'anchor' => $this->rootSectionUuid,
          'placement' => 'inside',
          'region' => 'content',
        ],
        'layout' => 'layout_twocol_section',
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $child_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => 'first'],
        'properties' => ['field_heading' => 'Try Daedalus Stylish today'],
      ],
      [
        'op' => 'update_element',
        'element' => $child_uuid,
        'properties' => ['field_heading' => 'Rewritten by a later op'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // The container component sits in the root section, uuid-locked to the
    // embedded section (one identity).
    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $container = $sections[0]->getComponent($section_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->getPluginId());
    $this->assertSame('content', $container->getRegion());

    $storage = $this->getStorage();
    $nested = $storage->getSectionByUuid($section_uuid);
    $this->assertNotNull($nested);
    $this->assertSame('layout_twocol_section', $nested->getLayoutId());
    $this->assertSame($section_uuid, $nested->getThirdPartySetting('daedalus_layout', 'uuid'));

    $children = $nested->getComponentsByRegion('first');
    $this->assertCount(1, $children);
    $child = reset($children);
    $this->assertSame($child_uuid, $child->getUuid());
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $child_block = $adapter->extractBlockContent($child);
    $this->assertSame('Rewritten by a later op', $child_block->get('field_heading')->value);
  }

  /**
   * Tests a declarative nested container node in a created entity's tree.
   *
   * A container node among a region's children is a nested section,
   * recursive: it lands as a container component embedding the built
   * Section value.
   */
  public function testCreatedLayoutBuildsNestedContainerNodes(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();
    $root_uuid = (new PhpUuid())->generate();

    $result = $this->operations->createEntity([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Created'],
      'layout' => [
        [
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'uuid' => $root_uuid,
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'container',
                  'layout_id' => 'layout_twocol_section',
                  'uuid' => $section_uuid,
                  'children' => [
                    [
                      'kind' => 'region',
                      'region' => 'first',
                      'children' => [
                        [
                          'kind' => 'block',
                          'uuid' => $child_uuid,
                          'block_type' => 'basic',
                          'block_fields' => ['field_heading' => 'Declared in place'],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity = $result->getEntity();

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame($root_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $container = $sections[0]->getComponent($section_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->getPluginId());

    $storage = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity)->sectionStorage();
    $nested = $storage->getSectionByUuid($section_uuid);
    $this->assertNotNull($nested);
    $this->assertSame('layout_twocol_section', $nested->getLayoutId());

    $children = $nested->getComponentsByRegion('first');
    $this->assertCount(1, $children);
    $child = reset($children);
    $this->assertSame($child_uuid, $child->getUuid());
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $child_block = $adapter->extractBlockContent($child);
    $this->assertSame('Declared in place', $child_block->get('field_heading')->value);
  }

  /**
   * Tests a failing later op rolls the whole batch back atomically.
   */
  public function testAtomicRollbackOnInvalidChild(): void {
    $section_uuid = (new PhpUuid())->generate();

    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => [
          'anchor' => $this->rootSectionUuid,
          'placement' => 'inside',
          'region' => 'content',
        ],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'nonexistent_type',
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('INVALID_BLOCK_TYPE', $codes);

    // Atomic rollback: the earlier op's nested section is gone too.
    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertCount(0, $sections[0]->getComponents());
  }

  /**
   * Tests read/write isomorphism: the tree of a read is the write.
   *
   * A nested section is a container node inside its parent's region node
   * (one shape). Feeding the tree read from the host into a create
   * reproduces the exact structure on the new entity, identity included.
   */
  public function testReadShapeIsWriteShapeRoundTrip(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => [
          'anchor' => $this->rootSectionUuid,
          'placement' => 'inside',
          'region' => 'content',
        ],
        'layout' => 'layout_twocol_section',
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $child_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => 'first'],
        'properties' => ['field_heading' => 'Symmetry, verified'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // READ: the nested section is a container node in its parent region,
    // the same shape a top-level section has, its own regions as region
    // nodes.
    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $read = $serializer->serialize($this->host($this->entity));
    $nested_node = $read['layout'][0]['children'][0]['children'][0];
    $this->assertSame('container', $nested_node['kind']);
    $this->assertSame('layout_twocol_section', $nested_node['layout_id']);
    $this->assertSame($section_uuid, $nested_node['uuid']);
    $this->assertArrayNotHasKey('block_type', $nested_node);
    $this->assertArrayNotHasKey('plugin_id', $nested_node);
    $this->assertSame('first', $nested_node['children'][0]['region']);
    $this->assertSame($child_uuid, $nested_node['children'][0]['children'][0]['uuid']);

    // WRITE: the tree read from the host is the write input, verbatim.
    $builder = $this->container->get('daedalus_blueprint.node_tree_builder');
    $tree = $builder->build($this->host($this->entity));
    $result = $this->operations->createEntity([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Copy'],
      'layout' => $tree,
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // ROUND TRIP: identical structure, identity preserved.
    $this->assertEquals(
      $this->stripVolatileKeys($tree),
      $this->stripVolatileKeys($builder->build($this->host($result->getEntity()))),
    );
  }

  /**
   * Recursively strips persistence-volatile keys from serialized layout data.
   *
   * Block_revision_id appears once core's InlineBlockEntityOperations has
   * saved a block on host entity save; whether a given block has one depends
   * on save timing, not shape, so it is excluded from shape comparison.
   *
   * @param array $data
   *   Serialized layout data.
   *
   * @return array
   *   The data without volatile keys.
   */
  protected function stripVolatileKeys(array $data): array {
    unset($data['block_revision_id']);
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = $this->stripVolatileKeys($value);
      }
    }
    return $data;
  }

}
