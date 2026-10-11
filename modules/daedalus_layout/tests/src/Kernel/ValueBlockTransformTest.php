<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Serialization\Yaml;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\daedalus_layout\Plugin\Block\InlineBlock;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the value block transform over Layout Builder default layouts.
 *
 * A display that names its inline blocks by revision id (the shape before
 * the display was a host) is rewritten to value blocks at any depth by the
 * post update's walk: the block's fields land under block_values with
 * references by uuid, no key names a block entity, the orphaned entities
 * are garbage-collected, and the value block hydrates for the render and
 * saves as an entity again when a content host carries it. A block the
 * grammar cannot spell is left as it is.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class ValueBlockTransformTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_blueprint_form_only_test',
  ];

  /**
   * The display under test.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The grammar spells what the acting user may view: the walk runs as
    // an administrator, as a deploy does.
    $this->setUpCurrentUser([], [], TRUE);
    foreach ([
      'field_text' => ['type' => 'string'],
      'field_form_only' => ['type' => 'string'],
      'field_ref' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'entity_test']],
    ] as $name => $storage) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
      ] + $storage)->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'block_content',
        'bundle' => 'basic',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * A saved, non-reusable basic block.
   */
  protected function savedBlock(array $values): BlockContent {
    $block = BlockContent::create($values + ['type' => 'basic', 'reusable' => FALSE]);
    $block->save();
    return $block;
  }

  /**
   * An inline block component naming a saved block by revision id.
   */
  protected function savedBlockComponent(BlockContent $block, string $uuid): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic',
      'label_display' => '0',
      'view_mode' => 'full',
      'block_id' => $block->id(),
      'block_revision_id' => $block->getRevisionId(),
    ]);
  }

  /**
   * Writes sections onto the display verbatim, as a config import would.
   */
  protected function importDisplay(array $sections): void {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load(self::DISPLAY_ID);
    foreach ($sections as $section) {
      $display->appendSection($section);
    }
    $display->setSyncing(TRUE)->save();
    $display->setSyncing(FALSE);
  }

  /**
   * The display, loaded fresh.
   */
  protected function loadDisplay(): LayoutBuilderEntityViewDisplay {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged(self::DISPLAY_ID);
    return $display;
  }

  /**
   * The export shape of the display: its raw config record.
   */
  protected function export(): string {
    return Yaml::encode($this->config('core.entity_view_display.' . self::DISPLAY_ID)->getRawData());
  }

  /**
   * The post update converts a display's saved blocks at every depth.
   */
  public function testPostUpdateConvertsDisplayBlocks(): void {
    $target = EntityTest::create(['name' => 'Referenced']);
    $target->save();
    $root_block = $this->savedBlock([
      'info' => 'Root block',
      'field_text' => 'At the root',
      'field_ref' => $target->id(),
    ]);
    $deep_block = $this->savedBlock(['info' => 'Deep block', 'field_text' => 'In a container']);
    $root_uuid = $this->uuid->generate();
    $deep_uuid = $this->uuid->generate();
    $deep_section = $this->createSection('layout_onecol', [$this->savedBlockComponent($deep_block, $deep_uuid)]);
    $container = $this->createNestedSectionComponent($deep_section);
    $this->importDisplay([
      $this->createSection('layout_onecol', [$this->savedBlockComponent($root_block, $root_uuid), $container]),
    ]);
    $this->assertStringContainsString('block_revision_id', $this->export(), 'The import kept the revision ids.');

    // The post update's walk.
    $this->container->get('module_handler')->loadInclude('daedalus_layout', 'php', 'daedalus_layout.post_update');
    $sandbox = [];
    do {
      daedalus_layout_post_update_0006_display_value_blocks($sandbox);
    } while ($sandbox['#finished'] < 1);

    $export = $this->export();
    foreach (InlineBlockValues::ENTITY_KEYS as $key) {
      $this->assertStringNotContainsString($key, $export, "The export names no block entity ($key).");
    }
    $display = $this->loadDisplay();
    $root_section = $display->getSections()[0];
    $root_values = $root_section->getComponent($root_uuid)->get('configuration')[InlineBlockValues::KEY];
    $this->assertSame([
      'info' => 'Root block',
      'field_text' => 'At the root',
      'field_ref' => ['uuid' => $target->uuid(), '_label' => 'Referenced'],
    ], $root_values, 'The root block is values, its reference spelled by uuid.');
    $embedded = SectionBlock::embeddedSection($root_section->getComponent($container->getUuid()));
    $deep_values = $embedded->getComponent($deep_uuid)->get('configuration')[InlineBlockValues::KEY];
    $this->assertSame(['info' => 'Deep block', 'field_text' => 'In a container'], $deep_values, 'The nested block is values too.');

    // The entities the display no longer names are gone, usage included.
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $this->assertNull($block_storage->load($root_block->id()));
    $this->assertNull($block_storage->load($deep_block->id()));
    $this->assertSame(0, (int) \Drupal::database()->select('inline_block_usage')->countQuery()->execute()->fetchField());

    // A second walk is a no-op: nothing applies, nothing is saved.
    $sandbox = [];
    $saved = $this->container->get('daedalus_layout.entity_view_display_walker')->walk($sandbox);
    $this->assertSame(0, $saved);
  }

  /**
   * A value block hydrates for the render and saves again on a content host.
   */
  public function testValueBlockHydratesAndSavesOnContentHost(): void {
    $target = EntityTest::create(['name' => 'Referenced']);
    $target->save();
    $uuid = $this->uuid->generate();
    $component = new SectionComponent($uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic',
      'label_display' => '0',
      'view_mode' => 'full',
      InlineBlockValues::KEY => [
        'info' => 'From values',
        'field_text' => 'Hydrated',
        'field_ref' => ['uuid' => $target->uuid()],
      ],
    ]);

    // The plugin hydrates: the render and the access chain see a block.
    $plugin = $component->getPlugin();
    $this->assertInstanceOf(InlineBlock::class, $plugin);
    $build = $plugin->build();
    $block = $build['#block_content'];
    $this->assertTrue($block->isNew());
    $this->assertSame('Hydrated', $block->get('field_text')->value);
    $this->assertSame($target->id(), $block->get('field_ref')->entity->id());
    $this->assertSame('From values', $block->label());

    // A content host carrying the component saves it as an entity.
    $entity = EntityTest::create([
      'name' => 'A page',
      OverridesSectionStorage::FIELD_NAME => [$this->createSection('layout_onecol', [$component])],
    ]);
    $entity->save();
    $configuration = EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent($uuid)->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id']);
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $configuration);
    $saved = $this->entityTypeManager->getStorage('block_content')->loadRevision($configuration['block_revision_id']);
    $this->assertSame('Hydrated', $saved->get('field_text')->value);
    $this->assertSame($target->id(), $saved->get('field_ref')->entity->id());
    $row = \Drupal::database()->select('inline_block_usage', 'u')->fields('u')->condition('block_content_id', $saved->id())->execute()->fetchObject();
    $this->assertSame('entity_test', $row->layout_entity_type, 'The saved block is used by the content host.');
  }

  /**
   * An unsaved inline block renders outside the preview.
   *
   * The root entity registration materializes the plugin's block ahead of
   * core's render subscriber, which hands the plugin its access dependency
   * afterwards; the dependency must still reach the block, because an
   * unsaved block (a value block, a staged serialized block) has no usage
   * row to answer for it.
   */
  public function testUnsavedBlocksRenderOutsidePreview(): void {
    $value_uuid = $this->uuid->generate();
    $staged_uuid = $this->uuid->generate();
    $base = [
      'id' => 'inline_block:basic',
      'label' => 'Basic',
      'label_display' => '0',
      'view_mode' => 'full',
    ];
    $section = $this->createSection('layout_onecol', [
      new SectionComponent($value_uuid, 'content', $base + [
        InlineBlockValues::KEY => ['info' => 'From values', 'field_text' => 'Hydrated'],
      ]),
      new SectionComponent($staged_uuid, 'content', $base + [
        'block_serialized' => serialize(BlockContent::create([
          'type' => 'basic',
          'reusable' => FALSE,
          'info' => 'Staged',
          'field_text' => 'Serialized',
        ])),
      ]),
    ]);
    $entity = EntityTest::create(['name' => 'A page']);
    $entity->save();

    $build = $section->toRenderArray(['layout_builder.entity' => EntityContext::fromEntity($entity)]);
    $this->assertSame('Hydrated', $build['content'][$value_uuid]['content']['#block_content']->get('field_text')->value, 'The value block renders outside the preview.');
    $this->assertSame('Serialized', $build['content'][$staged_uuid]['content']['#block_content']->get('field_text')->value, 'The staged block renders outside the preview.');
  }

  /**
   * A block the grammar cannot spell stays an entity; a missing one stays.
   */
  public function testUnspellableAndMissingBlocksAreLeft(): void {
    $unspellable = $this->savedBlock(['info' => 'Form only', 'field_form_only' => 'Unspelled']);
    $unspellable_uuid = $this->uuid->generate();
    $missing_uuid = $this->uuid->generate();
    $missing = new SectionComponent($missing_uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic',
      'label_display' => '0',
      'view_mode' => 'full',
      'block_revision_id' => 987654,
    ]);
    $this->importDisplay([
      $this->createSection('layout_onecol', [$this->savedBlockComponent($unspellable, $unspellable_uuid), $missing]),
    ]);

    $sandbox = [];
    $this->container->get('daedalus_layout.entity_view_display_walker')->walk($sandbox);

    $section = $this->loadDisplay()->getSections()[0];
    $kept = $section->getComponent($unspellable_uuid)->get('configuration');
    $this->assertSame((int) $unspellable->getRevisionId(), (int) $kept['block_revision_id'], 'The unspellable block keeps its entity.');
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $kept);
    $this->assertNotNull($this->entityTypeManager->getStorage('block_content')->loadUnchanged($unspellable->id()), 'Its entity survives the save.');
    $this->assertSame(987654, $section->getComponent($missing_uuid)->get('configuration')['block_revision_id'], 'A dangling revision id is left as it is.');
  }

}
