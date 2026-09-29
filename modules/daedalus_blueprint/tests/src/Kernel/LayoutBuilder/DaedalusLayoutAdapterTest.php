<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\ElementContext;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the DaedalusLayoutAdapter flat walk, the path taken without daedalus_layout.
 *
 * The site here has layout_builder but not daedalus_layout, so locateElement() and
 * getBlockContentEntities() both ride the one flat walk of the root
 * sections. The cases pin what that walk promises: positional component
 * indexes per section, plugin blocks counted but never yielded as block
 * content, and one path string for the same component on both reads.
 */
#[CoversClass(DaedalusLayoutAdapter::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DaedalusLayoutAdapterTest extends BlueprintKernelTestBase {

  /**
   * The adapter under test.
   */
  protected DaedalusLayoutAdapter $adapter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $this->adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
  }

  /**
   * Builds an entity with two sections mixing plugin and inline blocks.
   *
   * Section 0 holds a plugin block then an unsaved inline block; section 1
   * holds a saved inline block then a second unsaved one. The plugin block
   * sits first on purpose: it occupies index 0, so the inline block behind
   * it is only found at index 1 when the walk counts every component.
   *
   * @return array{entity: object, uuids: string[]}
   *   The saved entity and the component UUIDs in walk order.
   */
  protected function createLayoutEntity(): array {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $unsaved_a = $storage->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => 'First inline', 'format' => 'plain_text'],
    ]);
    $saved = $storage->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Saved inline', 'format' => 'plain_text'],
    ]);
    $saved->save();
    $unsaved_b = $storage->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Last inline', 'format' => 'plain_text'],
    ]);

    $uuids = [
      'plugin-block-uuid',
      'inline-a-uuid',
      'inline-saved-uuid',
      'inline-b-uuid',
    ];
    $section_0 = $this->createSection('layout_onecol', [
      [
        'uuid' => $uuids[0],
        'weight' => 0,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Powered'],
      ],
      [
        'uuid' => $uuids[1],
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $unsaved_a, 'A'),
      ],
    ]);
    $section_1 = $this->createSection('layout_onecol', [
      [
        'uuid' => $uuids[2],
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $saved, 'Saved'),
      ],
      [
        'uuid' => $uuids[3],
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $unsaved_b, 'B'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Walk',
      'layout_builder__layout' => [$section_0, $section_1],
    ]);
    $entity->save();

    return ['entity' => $entity, 'uuids' => $uuids];
  }

  /**
   * Tests core's blank placeholder reads as no sections on both lists.
   *
   * Core seeds layout_builder_blank onto a display whose sections were all
   * removed and copies it into a fresh override; both lists answer empty
   * here, so a reader never meets a section the canvas does not render.
   */
  public function testBlankPlaceholderReadsAsNoSections(): void {
    $this->assertFalse($this->adapter->isBlankSectionList([]));
    $this->assertFalse($this->adapter->isBlankSectionList([new Section('layout_onecol')]));
    $this->assertTrue($this->adapter->isBlankSectionList([new Section('layout_builder_blank')]));

    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections(TRUE)->save();
    $this->assertSame('layout_builder_blank', $display->getSections()[0]->getLayoutId());

    $entity = $this->createTestEntity();
    $this->assertSame([], $this->adapter->getDefaultSections($entity), 'A blank-only display default is no default.');
    $this->assertSame([], $this->adapter->getFieldSections($entity), 'An empty field holds no sections.');

    $entity->set('layout_builder__layout', [new Section('layout_builder_blank')]);
    $this->assertSame([], $this->adapter->getFieldSections($entity), 'A blank-only field reads as empty.');

    $section = new Section('layout_onecol');
    $entity->set('layout_builder__layout', [$section]);
    $field = $this->adapter->getFieldSections($entity);
    $this->assertCount(1, $field);
    $this->assertSame('layout_onecol', $field[0]->getLayoutId());
  }

  /**
   * Tests that the flat locate reports positional paths across sections.
   */
  public function testLocateElementFlatCountsEveryComponent(): void {
    ['entity' => $entity, 'uuids' => $uuids] = $this->createLayoutEntity();

    $behind_plugin = $this->adapter->locateElement($entity, $uuids[1]);
    $this->assertNotNull($behind_plugin);
    $this->assertSame(ElementContext::TYPE_LB_COMPONENT, $behind_plugin->type);
    $this->assertSame($uuids[1], $behind_plugin->target->getUuid());
    $this->assertSame(0, $behind_plugin->sectionIndex);
    $this->assertSame('layout_builder__layout[0].components[1]', $behind_plugin->path);
    $this->assertSame('layout_onecol', $behind_plugin->section->getLayoutId());
    $this->assertSame($entity->id(), $behind_plugin->parentEntity->id());

    $second_section = $this->adapter->locateElement($entity, $uuids[3]);
    $this->assertNotNull($second_section);
    $this->assertSame(1, $second_section->sectionIndex);
    $this->assertSame('layout_builder__layout[1].components[1]', $second_section->path);

    $plugin_block = $this->adapter->locateElement($entity, $uuids[0]);
    $this->assertNotNull($plugin_block);
    $this->assertSame('layout_builder__layout[0].components[0]', $plugin_block->path);

    $this->assertNull($this->adapter->locateElement($entity, 'no-such-component'));
  }

  /**
   * Tests that the flat sweep yields inline blocks under the locate path.
   */
  public function testBlockContentEntitiesFlatShareTheLocatePath(): void {
    ['entity' => $entity, 'uuids' => $uuids] = $this->createLayoutEntity();

    $yielded = [];
    foreach ($this->adapter->getBlockContentEntities($entity) as $info) {
      $yielded[$info['component_uuid']] = $info;
    }

    // The plugin block is walked (it holds index 0) but never yielded.
    $this->assertSame([$uuids[1], $uuids[2], $uuids[3]], array_keys($yielded));
    $this->assertSame([
      'layout_builder__layout[0].components[1]',
      'layout_builder__layout[1].components[0]',
      'layout_builder__layout[1].components[1]',
    ], array_column($yielded, 'path'));

    foreach ($yielded as $uuid => $info) {
      $this->assertSame($this->adapter->locateElement($entity, $uuid)->path, $info['path']);
      $this->assertSame('text_section', $info['entity']->bundle());
    }

    // Both extraction paths deliver the block: the serialized unsaved one
    // and the saved one loaded by revision.
    $this->assertSame('First inline', $yielded[$uuids[1]]['entity']->get('field_body')->value);
    $this->assertSame('Saved inline', $yielded[$uuids[2]]['entity']->get('field_body')->value);
    $this->assertNotNull($yielded[$uuids[2]]['entity']->id());
  }

}
