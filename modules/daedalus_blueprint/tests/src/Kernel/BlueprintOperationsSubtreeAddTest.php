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
 * node inside a region through restructure_layout / create_entity). Blocks
 * never hold nested layouts; the retired layout-capable-block grammar
 * (data.layout_builder__layout, data.section_uuid) is refused loudly with a
 * pointer at the one placement grammar. The read shape IS the write shape:
 * a serialized nested section feeds back through restructure_layout with
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
      'daedalus_ui',
      'daedalus_tempstore',
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
    return $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->getSectionStorage($this->entity);
  }

  /**
   * Tests data.layout_builder__layout on a block add is refused.
   */
  public function testSubtreeDataKeyRefused(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'layout_builder__layout' => [
        ['layout_id' => 'layout_twocol_section', 'regions' => []],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('data.layout_builder__layout', $errors[0]->getPath());
    $this->assertSame('Blocks do not hold nested layouts.', $errors[0]->getMessage());
    $this->assertStringContainsString('add_section', $errors[0]->getHint());
  }

  /**
   * Tests data.section_uuid on a block add is refused.
   */
  public function testSectionUuidDataKeyRefused(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
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
   * Tests layout_builder__layout inside the property bag is refused.
   */
  public function testSubtreeInPropertiesRefused(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'block_fields' => [
        'layout_builder__layout' => [],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('properties.layout_builder__layout', $errors[0]->getPath());
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

    $result = $this->operations->batchOperations($this->entity, [
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
   * Tests a declarative nested section node through restructure_layout.
   *
   * A region entry carrying layout_id is a nested section node, recursive:
   * the deserializer wraps it in a container component embedding the built
   * Section value.
   */
  public function testRestructureLayoutBuildsNestedSectionNodes(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();

    $result = $this->operations->restructureLayout($this->entity, [
      [
        'layout_id' => 'layout_onecol',
        'uuid' => $this->rootSectionUuid,
        'regions' => [
          'content' => [
            [
              'layout_id' => 'layout_twocol_section',
              'uuid' => $section_uuid,
              'regions' => [
                'first' => [
                  [
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
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $container = $sections[0]->getComponent($section_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->getPluginId());

    $storage = $this->getStorage();
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

    $result = $this->operations->batchOperations($this->entity, [
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
   * Tests read/write isomorphism: the read shape IS the write shape.
   *
   * A nested section serializes as a section node inside its parent region
   * (storage shape == sugar shape). Feeding the serialized sections array
   * back through restructure_layout verbatim reproduces the exact structure,
   * identity included.
   */
  public function testReadShapeIsWriteShapeRoundTrip(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();
    $result = $this->operations->batchOperations($this->entity, [
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

    // READ: the nested section is a section node in its parent region, the
    // same shape a top-level section has.
    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $read = $serializer->serialize($this->entity);
    $nested_node = $read['layout_builder__layout'][0]['regions']['content'][0];
    $this->assertSame('layout_twocol_section', $nested_node['layout_id']);
    $this->assertSame($section_uuid, $nested_node['uuid']);
    $this->assertArrayNotHasKey('block_type', $nested_node);
    $this->assertArrayNotHasKey('plugin_id', $nested_node);
    $this->assertSame($child_uuid, $nested_node['regions']['first'][0]['uuid']);

    // WRITE: the read output is the write input, verbatim.
    $result = $this->operations->restructureLayout($this->entity, $read['layout_builder__layout']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // ROUND TRIP: identical structure, identity preserved.
    $reread = $serializer->serialize($this->entity);
    $this->assertEquals(
      $this->stripVolatileKeys($read['layout_builder__layout']),
      $this->stripVolatileKeys($reread['layout_builder__layout']),
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
