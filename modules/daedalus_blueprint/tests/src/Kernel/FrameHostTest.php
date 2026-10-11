<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Plugin\SectionStorage\FrameSectionStorage;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the frame host: a theme's site frame as a page document.
 *
 * A daedalus_frame config entity (id the theme) resolves to a FrameHost,
 * the display host's sibling: no display, no subject, no fields, a
 * document always, styles in its third-party settings, config persistence
 * with value blocks. Its storage is the `frame` plugin core and the
 * tempstore both resolve for it; the same op batch a template takes runs
 * on it, stages, commits, discards and undoes under the frame storage's
 * key; the slot block is offered on it as on a template; the lean tree
 * reads it; it is a page to the AI; and everything over it follows the one
 * frame permission.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class FrameHostTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    // The unbound site: literal paddings land as written.
    'daedalus_stylish_unbound_test',
    // The container layout the catalog needs: a page-language site, where
    // "page" means something.
    'daedalus_blueprint_test',
  ];

  /**
   * The theme whose frame is under test.
   */
  protected const THEME = 'stark';

  /**
   * The identities the batch mints.
   */
  protected const SECTION_UUID = '3c000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '3c000000-0000-4000-8000-000000000002';
  protected const PLUGIN_UUID = '3c000000-0000-4000-8000-000000000003';
  protected const SLOT_UUID = '3c000000-0000-4000-8000-000000000004';

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * The entity storage handler.
   *
   * @var \Drupal\daedalus_blueprint\EntityStorageHandler
   */
  protected $storageHandler;

  /**
   * The host resolver.
   *
   * @var \Drupal\daedalus_blueprint\Host\HostResolver
   */
  protected $hostResolver;

  /**
   * The entity tempstore repository.
   *
   * @var \Drupal\daedalus_tempstore\EntityTempstoreRepository
   */
  protected $tempstore;

  /**
   * The history recorder.
   *
   * @var \Drupal\daedalus_tempstore\History\HistoryRecorder
   */
  protected $recorder;

  /**
   * The history manager.
   *
   * @var \Drupal\daedalus_tempstore\History\HistoryManager
   */
  protected $history;

  /**
   * The layout adapter.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter
   */
  protected $adapter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content', 'daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('theme_installer')->install([self::THEME]);

    // A page bundle beside the frame: the slot block is refused there.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'block_content',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'block_content',
      'bundle' => 'basic',
      'label' => 'Text',
    ])->save();

    // The site's slots: the frame's main slot comes from the same roster
    // every template reads.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();

    Frame::create(['id' => self::THEME])->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->storageHandler = $this->container->get('daedalus_blueprint.storage_handler');
    $this->hostResolver = $this->container->get('daedalus_blueprint.host_resolver');
    $this->tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->recorder = $this->container->get('daedalus_tempstore.history_recorder');
    $this->history = $this->container->get('daedalus_tempstore.history_manager');
    $this->adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
  }

  /**
   * The frame, loaded fresh.
   */
  protected function loadFrame(): FrameInterface {
    /** @var \Drupal\daedalus_blueprint\Entity\FrameInterface $frame */
    $frame = $this->container->get('entity_type.manager')->getStorage(FrameInterface::ENTITY_TYPE_ID)->loadUnchanged(self::THEME);
    return $frame;
  }

  /**
   * The frame's host, as the storage handler's door answers it.
   */
  protected function frameHost(): HostInterface {
    $host = $this->storageHandler->load(FrameInterface::ENTITY_TYPE_ID, self::THEME);
    $this->assertInstanceOf(FrameHost::class, $host);
    return $host;
  }

  /**
   * The one batch: a section, an inline block, a plugin block, a style.
   */
  protected function batch(HostInterface $host): array {
    return [
      [
        'op' => 'add_section',
        'uuid' => self::SECTION_UUID,
        'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::BLOCK_UUID,
        'block_type' => 'basic',
        'properties' => ['field_text' => 'Hello frame'],
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::PLUGIN_UUID,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      ['op' => 'update_style', 'key' => 'container:' . self::SECTION_UUID, 'properties' => ['padding' => '2rem']],
    ];
  }

  /**
   * The component configurations of a host's document, keyed by uuid.
   */
  protected function components(HostInterface $host): array {
    $configurations = [];
    foreach ($host->sections() as $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        $configurations[$uuid] = $component->get('configuration');
      }
    }
    return $configurations;
  }

  /**
   * The block nodes of a lean tree at any depth, keyed by uuid.
   */
  protected function blockNodes(array $nodes): array {
    $blocks = [];
    foreach ($nodes as $node) {
      if (($node['kind'] ?? NULL) === 'block') {
        $blocks[$node['uuid']] = $node;
      }
      $blocks += $this->blockNodes($node['children'] ?? []);
    }
    return $blocks;
  }

  /**
   * The host answers the six questions for a frame.
   */
  public function testHostContract(): void {
    $frame = $this->loadFrame();
    $this->assertTrue($this->hostResolver->hosts($frame));

    $host = $this->hostResolver->fromEntity($frame);
    $this->assertInstanceOf(FrameHost::class, $host);
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $host->entityTypeId());
    $this->assertSame(self::THEME, $host->id());
    $this->assertSame($frame->uuid(), $host->uuid());
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $host->bundle(), 'A config entity\'s bundle is its type.');
    $this->assertSame('Stark', $host->label(), 'The frame is named after its theme.');
    $this->assertSame(self::THEME, $frame->theme());

    // No display and no subject: the frame belongs to no entity view
    // display and shows no entity.
    $this->assertNull($host->display());
    $this->assertNull($host->subject());
    $this->assertNull($host->fields());
    $this->assertSame(FrameHost::VIEW_MODE, $host->viewMode());

    // A document always, empty here; styles in the third-party setting.
    $this->assertTrue($host->hasDocument());
    $this->assertSame([], $host->sections());
    $this->assertSame([], $host->ownSections());
    $this->assertTrue($host->hasStyleStore());
    $this->assertSame([], $host->styleMap());
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $host->styleMapDefinition()->getTargetEntityTypeId());

    // Config persistence: no revisions, no workspaces, value blocks.
    $persistence = $host->persistence();
    $this->assertFalse($persistence->revisions);
    $this->assertFalse($persistence->workspaceAware);
    $this->assertTrue($persistence->valueBlocks);

    // The storage is the frame plugin, wrapped nested-aware, keyed by the
    // theme, and its host is the frame.
    $storage = $host->sectionStorage();
    assert($storage instanceof NestedSectionStorageInterface);
    $root = $storage->getRootStorage();
    $this->assertInstanceOf(FrameSectionStorage::class, $root);
    $this->assertSame(FrameHost::STORAGE_TYPE, $storage->getStorageType());
    $this->assertSame(self::THEME, $storage->getStorageId());
    $this->assertSame(self::THEME, $root->getTempstoreKey());
    $this->assertInstanceOf(FrameHost::class, $this->hostResolver->fromSectionStorage($storage));
    $this->assertSame('Stark', $root->label());
    // The preview contexts carry no subject: no field block is offered on
    // the frame, and the wrapper roots no entity.
    $preview = $root->getContextsDuringPreview();
    $this->assertArrayNotHasKey('layout_builder.entity', $preview);
    $this->assertInstanceOf(FrameInterface::class, $preview['entity']->getContextValue());
    $this->assertSame('default', $preview['view_mode']->getContextValue());
    $this->assertArrayNotHasKey(NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID, $storage->getContextsDuringPreview());

    // Core resolves the same storage for the frame entity alone, and the
    // tempstore's layout strategy claims the entity while the entity
    // strategy declines it.
    $resolved = $this->container->get('plugin.manager.layout_builder.section_storage')->findByContext([
      'entity' => EntityContext::fromEntity($frame),
    ], new CacheableMetadata());
    $this->assertInstanceOf(FrameSectionStorage::class, $resolved);
    $this->assertSame($root->getTempstoreKey(), $resolved->getTempstoreKey(), 'The host\'s storage and core\'s name one tempstore key.');
    $this->assertTrue($this->container->get('daedalus_tempstore.strategy.layout')->supports($frame));
    $this->assertFalse($this->container->get('daedalus_tempstore.strategy.entity')->supports($frame));
    $this->assertSame(self::THEME, $this->container->get('daedalus_tempstore.strategy.layout')->getKey($frame), 'A config host keys without a workspace suffix.');

    // The outermost layer: it renders itself and nothing owns it.
    $this->assertSame($host, $this->hostResolver->renderHost($host));
    $this->assertNull($this->hostResolver->foreignOwner($host));
    $this->assertInstanceOf(FrameHost::class, $this->storageHandler->load(FrameInterface::ENTITY_TYPE_ID, self::THEME));

    // A page to the AI: it edits on the canvas and reads through page_read.
    $this->assertTrue($this->container->get('daedalus_blueprint.page_hosts')->isPage($host));
    $this->assertFalse($this->container->get('daedalus_blueprint.bundle_list_builder')->isHiddenHost($host), 'No bundle\'s visibility hides a host with no display.');

    // The config record: the theme is a dependency, so the frame leaves
    // with its theme.
    $this->assertSame([self::THEME], $frame->getDependencies()['theme']);
  }

  /**
   * The frame has no Layout Builder page: its canvas is its own route.
   */
  public function testNoLayoutBuilderUrl(): void {
    $storage = $this->frameHost()->sectionStorage();
    assert($storage instanceof NestedSectionStorageInterface);
    $root = $storage->getRootStorage();
    $this->assertSame('<front>', $root->getRedirectUrl()->getRouteName());
    $this->expectException(\LogicException::class);
    $root->getLayoutBuilderUrl();
  }

  /**
   * The same op batch a template takes runs on the frame; slots are offered.
   */
  public function testBatchAndSlotOffer(): void {
    $host = $this->frameHost();
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $result->toArray()['entity_type']);
    $components = $this->components($host);
    $this->assertSame([self::BLOCK_UUID, self::PLUGIN_UUID], array_keys($components));
    $this->assertSame('inline_block:basic', $components[self::BLOCK_UUID]['id']);
    $this->assertNotEmpty($components[self::BLOCK_UUID]['block_serialized'], 'Staged, the block rides serialized as on a template.');
    $this->assertSame('system_powered_by_block', $components[self::PLUGIN_UUID]['id']);
    $this->assertSame(['default' => ['padding' => '2rem']], $host->styleMap()['container:' . self::SECTION_UUID]);
    $frame = $host->entity();
    assert($frame instanceof FrameInterface);
    $this->assertSame(
      [['key' => 'container:' . self::SECTION_UUID, 'entry' => ['default' => ['padding' => '2rem']]]],
      $frame->getThirdPartySetting(FrameHost::STYLES_PROVIDER, FrameHost::STYLES_KEY),
      'The frame keeps its map as a third-party setting, rows of key and entry, the display host\'s shape.',
    );

    // The frame's main slot is the roster's slot block, placeable in the
    // frame as in a template and refused on a page.
    $restrictions = $this->container->get('daedalus_blueprint.placement_restrictions');
    $slot = SlotBlock::PLUGIN_ID . ':main';
    $this->assertTrue($restrictions->blockAllowed($slot, $host->sectionStorage()));
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'uuid' => self::SLOT_UUID,
        'plugin_id' => $slot,
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame($slot, $this->components($host)[self::SLOT_UUID]['id']);
    $this->assertSame(['main'], $this->adapter->declaredSlots($host->sections()), 'The frame declares the slot it places.');

    $page = EntityTest::create(['name' => 'A page']);
    $page->save();
    $page_host = $this->hostResolver->fromEntity($page);
    $this->assertFalse($restrictions->blockAllowed($slot, $page_host->sectionStorage()));

    // The lean tree reads the frame: one container, its three blocks, the
    // slot block carrying the slot it declares.
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $this->assertCount(1, $tree);
    $this->assertSame('container', $tree[0]['kind']);
    $this->assertSame(self::SECTION_UUID, $tree[0]['uuid']);
    $blocks = $this->blockNodes($tree);
    $this->assertSame([self::BLOCK_UUID, self::PLUGIN_UUID, self::SLOT_UUID], array_keys($blocks));
    $this->assertSame('main', $blocks[self::SLOT_UUID]['slot']);
    $read = $this->container->get('daedalus_blueprint.serializer')->serialize($host);
    $this->assertArrayNotHasKey('fields', $read, 'A frame has no fields of its own.');
    $this->assertSame(self::SECTION_UUID, $read['layout'][0]['uuid']);

    // A field node has nothing to present: the frame shows no entity.
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiate($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              ['kind' => 'block', 'field' => 'name'],
            ],
          ],
        ],
      ],
    ], ['anchor' => $host->uuid(), 'placement' => 'inside']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('FIELD_NOT_ON_HOST', $result->toArray()['errors'][0]['code']);
  }

  /**
   * Stage, undo, redo, commit and discard share the frame storage key.
   */
  public function testStageCommitDiscardHistory(): void {
    $host = $this->frameHost();
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('tempstore', $this->storageHandler->persist($host));
    $this->recorder->flush();

    // The stage: a fresh load answers the staged document; the saved frame
    // is untouched.
    $staged = $this->frameHost();
    $this->assertCount(1, $staged->sections());
    $this->assertCount(0, $this->loadFrame()->getSections());
    $this->assertTrue($this->tempstore->has($host->entity()));
    $this->assertStringStartsWith('layout_builder.section_storage.frame:', $this->history->timelineFor($host), 'The timeline is the frame storage\'s.');
    $this->assertSame($this->history->timelineFor($host), $this->history->timelineFor($staged));

    // History: undo to the absent baseline, redo back.
    $metadata = $this->history->metadata($host);
    $this->assertCount(2, $metadata['entries']);
    $this->assertTrue($metadata['can_undo']);
    $undone = $this->history->undo($host);
    $this->assertSame(0, $undone['pointer']);
    $this->assertFalse($this->tempstore->has($host->entity()), 'Undo to the baseline ends the stage.');
    $this->assertCount(0, $this->frameHost()->sections());
    $redone = $this->history->redo($host);
    $this->assertSame(1, $redone['pointer']);
    $this->assertInstanceOf(FrameHost::class, $redone['hosts'][0]);
    $this->assertCount(1, $this->frameHost()->sections());

    // Commit: a config save, the stage ends, the export carries no block
    // entity id (a value block) and no block entity was ever saved.
    $this->storageHandler->commit($this->frameHost());
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('block_content')->getQuery()->accessCheck(FALSE)->count()->execute(), 'A value block is never a block entity.');
    $this->assertFalse($this->tempstore->has($host->entity()));
    $this->assertSame([], $this->history->metadata($host)['entries']);
    $frame = $this->loadFrame();
    $this->assertCount(1, $frame->getSections());
    $export = Yaml::encode($this->config('daedalus_blueprint.frame.' . self::THEME)->getRawData());
    foreach (InlineBlockValues::ENTITY_KEYS as $key) {
      $this->assertStringNotContainsString($key, $export, "The export names no block entity ($key).");
    }
    $this->assertStringContainsString(InlineBlockValues::KEY, $export);
    $this->assertStringContainsString('layout_id: ' . $frame->getSections()[0]->getLayoutId(), $export, 'The sections are config.');
    $this->assertSame(['default' => ['padding' => '2rem']], $this->hostResolver->fromEntity($frame)->styleMap()['container:' . self::SECTION_UUID]);
    $this->assertSame([], \Drupal::database()->select('inline_block_usage', 'u')->fields('u')->execute()->fetchAll(), 'A value block records no usage.');
    $dependencies = $frame->getDependencies();
    $this->assertSame([self::THEME], $dependencies['theme']);
    $this->assertContains('system', $dependencies['module'], 'The placed block plugin\'s module is a dependency.');

    // Hydrated: the value block reads back as an unsaved block with its
    // fields, as on a template.
    $host = $this->frameHost();
    $values = $this->components($host)[self::BLOCK_UUID][InlineBlockValues::KEY];
    $this->assertSame('Hello frame', $values['field_text']);
    $block = $this->adapter->extractBlockContent($host->sections()[0]->getComponent(self::BLOCK_UUID));
    $this->assertTrue($block->isNew());
    $this->assertSame('Hello frame', $block->get('field_text')->value);

    // Discard: a staged change after the commit is dropped.
    $result = $this->operations->batchOperations($host, [
      ['op' => 'remove_element', 'element' => self::PLUGIN_UUID],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->persist($host);
    $this->assertCount(1, $this->components($this->frameHost()));
    $this->tempstore->delete($host->entity());
    $this->assertCount(2, $this->components($this->frameHost()), 'The discard answers the saved frame.');
  }

  /**
   * Everything over the frame follows the one frame permission.
   */
  public function testFramePermission(): void {
    $host = $this->frameHost();
    $checker = $this->container->get('daedalus_blueprint.access_checker');
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');
    $op = [
      'op' => 'add_section',
      'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
    ];
    $storage = $host->sectionStorage();
    assert($storage instanceof NestedSectionStorageInterface);
    $root = $storage->getRootStorage();

    // Without it: the frame is viewable (every visitor sees it) and closed
    // to every edit, at the entity, the storage and the verdicts alike.
    $viewer = $this->createUser(['configure any layout', 'style any elements']);
    $this->assertTrue($checker->hostAccess($host, 'view', $viewer));
    $this->assertFalse($checker->hostAccess($host, 'update', $viewer), 'The template\'s permission opens nothing on the frame.');
    $this->assertFalse($root->access('view', $viewer));
    $result = $this->operations->batchOperations($host, [$op], ['actor' => $viewer]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ACCESS_DENIED_ENTITY', $result->getErrors()[0]->getCode());
    $verdicts = $stamper->verdicts($host, $host->uuid(), $viewer);
    $this->assertSame([], array_keys(array_filter($verdicts['can'])), 'Nothing is open to an actor without the frame permission.');

    // With it: open, and the structure and style policies name it.
    $builder = $this->createUser([FrameInterface::PERMISSION]);
    $this->assertTrue($checker->hostAccess($host, 'update', $builder));
    $this->assertTrue($root->access('view', $builder));
    $verdicts = $stamper->verdicts($host, $host->uuid(), $builder);
    $this->assertSame(['content', 'configure', 'style', 'arrange', 'insert'], array_keys(array_filter($verdicts['can'])));
    $this->assertTrue($this->operations->batchOperations($host, $this->batch($host), ['actor' => $builder])->isSuccess());
  }

}
