<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Serialization\Yaml;
use Drupal\daedalus_blueprint\Dispatch\ContentBlueprintBackend;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;
use Drupal\daedalus_layout\Plugin\Block\InlineBlock;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the display host: a Layout Builder display as a page document.
 *
 * The display hosts its default layout as a template: the same op batch a
 * content host takes (add_section, an inline block, a plugin block, a
 * style) runs on the display; it stages, commits, discards and undoes
 * through the same tempstore key as its defaults storage; its inline
 * blocks are value blocks, so its config export carries no content id; and
 * a block the field grammar cannot spell is refused on the template.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DisplayHostTest extends KernelTestBase {

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
    // A field no handler serializes (field_form_only).
    'daedalus_blueprint_form_only_test',
  ];

  /**
   * The display under test.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The identities the batch mints.
   */
  protected const SECTION_UUID = '1a000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '1a000000-0000-4000-8000-000000000002';
  protected const PLUGIN_UUID = '1a000000-0000-4000-8000-000000000003';

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
    // The content host's style store is its field; the display's is a
    // third-party setting and needs nothing attached.
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    // A display without Layout Builder: config, not a host.
    EntityViewMode::create(['id' => 'entity_test.teaser', 'targetEntityType' => 'entity_test', 'label' => 'Teaser'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->save();

    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();
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

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->storageHandler = $this->container->get('daedalus_blueprint.storage_handler');
    $this->hostResolver = $this->container->get('daedalus_blueprint.host_resolver');
    $this->tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->recorder = $this->container->get('daedalus_tempstore.history_recorder');
    $this->history = $this->container->get('daedalus_tempstore.history_manager');
    $this->adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
  }

  /**
   * The display, loaded fresh.
   */
  protected function loadDisplay(): LayoutBuilderEntityViewDisplay {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->loadUnchanged(self::DISPLAY_ID);
    return $display;
  }

  /**
   * The display's host, as the storage handler's door answers it.
   */
  protected function displayHost(): HostInterface {
    $host = $this->storageHandler->load('entity_view_display', self::DISPLAY_ID);
    $this->assertInstanceOf(DisplayHost::class, $host);
    return $host;
  }

  /**
   * The one batch: a section, an inline block, a plugin block, a style.
   */
  protected function batch(HostInterface $host, array $block_fields = ['field_text' => 'Hello template']): array {
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
        'properties' => $block_fields,
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
   * The host answers the six questions for a display.
   */
  public function testHostContract(): void {
    $display = $this->loadDisplay();
    $this->assertTrue($this->hostResolver->hosts($display));
    $this->assertFalse($this->hostResolver->hosts(EntityViewDisplay::load('entity_test.entity_test.teaser')), 'A display without Layout Builder is config, not a host.');

    $host = $this->hostResolver->fromEntity($display);
    $this->assertInstanceOf(DisplayHost::class, $host);
    $this->assertSame('entity_view_display', $host->entityTypeId());
    $this->assertSame(self::DISPLAY_ID, $host->id());
    $this->assertSame($display->uuid(), $host->uuid());
    $this->assertSame($display, $host->display(), 'The display resolves to itself.');
    $this->assertSame('default', $host->viewMode());
    $this->assertTrue($host->hasDocument());
    $this->assertSame([], $host->sections(), 'A fresh display has no document yet.');
    $this->assertNull($host->fields(), 'A template has no fields of its own.');
    $subject = $host->subject();
    $this->assertSame('entity_test', $subject->bundle());
    $this->assertTrue($subject->isNew(), 'The subject is core\'s sample entity, never saved.');
    $this->assertTrue($host->hasStyleStore());
    $this->assertSame([], $host->styleMap());
    $persistence = $host->persistence();
    $this->assertFalse($persistence->revisions);
    $this->assertFalse($persistence->workspaceAware);
    $this->assertTrue($persistence->valueBlocks);

    // The host of the defaults storage is the display.
    $storage = $host->sectionStorage();
    $this->assertNotNull($storage);
    $this->assertInstanceOf(DisplayHost::class, $this->hostResolver->fromSectionStorage($storage));

    // The backend resolver hands the display to the page backend.
    $resolver = $this->container->get('daedalus_blueprint.backend_resolver');
    $this->assertInstanceOf(ContentBlueprintBackend::class, $resolver->resolveFor($display));
    $this->assertNull($resolver->resolveFor(EntityViewDisplay::load('entity_test.entity_test.teaser')), 'Without the config backend nothing claims a plain display.');

    // The access is the display's update access.
    $this->setUpCurrentUser([], ['view test entity']);
    $checker = $this->container->get('daedalus_blueprint.access_checker');
    $this->assertFalse($checker->hostAccess($host, 'update'));
    $this->setUpCurrentUser([], [], TRUE);
    $this->assertTrue($checker->hostAccess($host, 'update'));
  }

  /**
   * The config backend yields a Layout Builder display to the page backend.
   *
   * With daedalus_blueprint_config installed its backend is asked before the
   * content backend (priority 0 against -10). Its entity door declines
   * whatever the host resolver hosts, so a template resolves to the page
   * backend while a display without Layout Builder stays config; the
   * type-level door stays the config backend's, because only the entity can
   * tell a template from config.
   */
  public function testConfigBackendYieldsTemplate(): void {
    $this->enableModules(['node', 'daedalus_blueprint_config']);
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    EntityViewMode::create(['id' => 'node.teaser', 'targetEntityType' => 'node', 'label' => 'Teaser'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->save();

    $resolver = $this->container->get('daedalus_blueprint.backend_resolver');
    $template = EntityViewDisplay::load('node.page.default');
    $this->assertTrue($this->container->get('daedalus_blueprint.host_resolver')->hosts($template));
    $this->assertInstanceOf(ContentBlueprintBackend::class, $resolver->resolveFor($template), 'A template is a page document, whatever its storage.');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $resolver->resolveFor(EntityViewDisplay::load('node.page.teaser')), 'A display without Layout Builder is config.');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $resolver->resolve('entity_view_display'), 'The type-level door is still the config backend\'s.');
  }

  /**
   * The same op batch runs on a content host and on a display host.
   */
  public function testSameBatchOnEntityAndDisplay(): void {
    $entity = EntityTest::create(['name' => 'A page']);
    $entity->save();
    $entity_host = $this->hostResolver->fromEntity($entity);
    $result = $this->operations->batchOperations($entity_host, $this->batch($entity_host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity_components = $this->components($entity_host);
    $this->assertSame([self::BLOCK_UUID, self::PLUGIN_UUID], array_keys($entity_components));
    $this->assertArrayHasKey('container:' . self::SECTION_UUID, $entity_host->styleMap());

    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('entity_view_display', $result->toArray()['entity_type']);
    $components = $this->components($host);
    $this->assertSame([self::BLOCK_UUID, self::PLUGIN_UUID], array_keys($components));
    $this->assertSame('inline_block:basic', $components[self::BLOCK_UUID]['id']);
    $this->assertNotEmpty($components[self::BLOCK_UUID]['block_serialized'], 'Staged, the block rides serialized as on a content host.');
    $this->assertSame('system_powered_by_block', $components[self::PLUGIN_UUID]['id']);
    $this->assertSame(['default' => ['padding' => '2rem']], $host->styleMap()['container:' . self::SECTION_UUID]);
    $this->assertSame([['key' => 'container:' . self::SECTION_UUID, 'entry' => ['default' => ['padding' => '2rem']]]], $host->entity()->getThirdPartySetting('daedalus_stylish', 'styles'), 'The display keeps its map as a third-party setting, rows of key and entry (a part key carries a dot, which config refuses in a key).');
  }

  /**
   * Stage, undo, redo, commit and discard share the defaults storage key.
   */
  public function testStageCommitDiscardHistory(): void {
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('tempstore', $this->storageHandler->persist($host));
    $this->recorder->flush();

    // The stage: a fresh load answers the staged document; the saved
    // display is untouched.
    $staged = $this->displayHost();
    $this->assertCount(1, $staged->sections());
    $this->assertCount(0, $this->loadDisplay()->getSections());
    $this->assertStringStartsWith('layout_builder.section_storage.defaults:', $this->history->timelineFor($host), 'The timeline is the defaults storage\'s.');
    $this->assertSame($this->history->timelineFor($host), $this->history->timelineFor($staged));

    // History: undo to the absent baseline, redo back.
    $metadata = $this->history->metadata($host);
    $this->assertCount(2, $metadata['entries']);
    $this->assertTrue($metadata['can_undo']);
    $undone = $this->history->undo($host);
    $this->assertSame(0, $undone['pointer']);
    $this->assertFalse($this->tempstore->has($host->entity()), 'Undo to the baseline ends the stage.');
    $this->assertCount(0, $this->displayHost()->sections());
    $redone = $this->history->redo($host);
    $this->assertSame(1, $redone['pointer']);
    $this->assertInstanceOf(DisplayHost::class, $redone['hosts'][0]);
    $this->assertCount(1, $this->displayHost()->sections());

    // Commit: a config save, the stage ends, the export carries no id and
    // no block entity was ever saved.
    $this->storageHandler->commit($this->displayHost());
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('block_content')->getQuery()->accessCheck(FALSE)->count()->execute(), 'A value block is never a block entity.');
    $this->assertFalse($this->tempstore->has($host->entity()));
    $this->assertSame([], $this->history->metadata($host)['entries']);
    $display = $this->loadDisplay();
    $this->assertCount(1, $display->getSections());
    // The export shape (what drush cex writes): the raw config record.
    $export = Yaml::encode($this->config('core.entity_view_display.' . self::DISPLAY_ID)->getRawData());
    foreach (InlineBlockValues::ENTITY_KEYS as $key) {
      $this->assertStringNotContainsString($key, $export, "The export names no block entity ($key).");
    }
    $this->assertStringContainsString(InlineBlockValues::KEY, $export);
    $this->assertSame(['default' => ['padding' => '2rem']], $this->hostResolver->fromEntity($display)->styleMap()['container:' . self::SECTION_UUID]);
    $this->assertSame([], \Drupal::database()->select('inline_block_usage', 'u')->fields('u')->execute()->fetchAll(), 'A value block records no usage.');

    // Discard: a staged change after the commit is dropped.
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, [
      ['op' => 'remove_element', 'element' => self::PLUGIN_UUID],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->persist($host);
    $this->assertCount(1, $this->components($this->displayHost()));
    $this->tempstore->delete($host->entity());
    $this->assertCount(2, $this->components($this->displayHost()), 'The discard answers the saved template.');
  }

  /**
   * A value block reads, edits and hydrates like any inline block.
   */
  public function testValueBlockRoundTrip(): void {
    $target = EntityTest::create(['name' => 'Referenced']);
    $target->save();
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host, [
      'field_text' => 'Hello template',
      'field_ref' => ['target_id' => (int) $target->id()],
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($host);

    // Stored: the block's fields in the grammar, references by uuid.
    $host = $this->displayHost();
    $values = $this->components($host)[self::BLOCK_UUID][InlineBlockValues::KEY];
    $this->assertSame('Hello template', $values['field_text']);
    $this->assertSame(['uuid' => $target->uuid(), '_label' => 'Referenced'], $values['field_ref']);
    $this->assertSame('Basic', $values['info']);

    // Hydrated: the adapter and the plugin answer a block with the values.
    $component = $host->sections()[0]->getComponent(self::BLOCK_UUID);
    $block = $this->adapter->extractBlockContent($component);
    $this->assertTrue($block->isNew());
    $this->assertSame('Hello template', $block->get('field_text')->value);
    $this->assertSame($target->id(), $block->get('field_ref')->entity->id());
    $plugin = $component->getPlugin();
    $this->assertInstanceOf(InlineBlock::class, $plugin);
    $build = $plugin->build();
    $this->assertSame('Hello template', $build['#block_content']->get('field_text')->value);

    // Read: the element carries its fields.
    $element = $this->container->get('daedalus_blueprint.serializer')->serializeElement($host, self::BLOCK_UUID);
    $this->assertSame('Hello template', $element['block_fields']['field_text']);
    $this->assertArrayNotHasKey('block_revision_id', $element);

    // Edit: the op hydrates, applies and stages serialized; the commit
    // stores values again.
    $result = $this->operations->batchOperations($host, [
      ['op' => 'update_element', 'element' => self::BLOCK_UUID, 'properties' => ['field_text' => 'Edited template']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $staged = $this->components($host)[self::BLOCK_UUID];
    $this->assertNotEmpty($staged['block_serialized']);
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $staged, 'The serialized block is the staged truth; stale values go.');
    $this->storageHandler->commit($host);
    $values = $this->components($this->displayHost())[self::BLOCK_UUID][InlineBlockValues::KEY];
    $this->assertSame('Edited template', $values['field_text']);
    $this->assertSame(['uuid' => $target->uuid(), '_label' => 'Referenced'], $values['field_ref']);
  }

  /**
   * A page seeded from the template saves the value block as an entity.
   */
  public function testSeededPageSavesValueBlockAsEntity(): void {
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($host);

    $entity = EntityTest::create(['name' => 'A page']);
    $entity->save();
    $page = $this->hostResolver->fromEntity($entity);
    // The page's document is the template's, seeded at the host's storage
    // door with identity derived from the page: no template uuid survives
    // on the page, and a second read of the page sees the same identity.
    $section = DocumentInstantiator::derivedUuid($entity->uuid(), self::SECTION_UUID);
    $block = DocumentInstantiator::derivedUuid($entity->uuid(), self::BLOCK_UUID);
    $plugin = DocumentInstantiator::derivedUuid($entity->uuid(), self::PLUGIN_UUID);
    $this->assertSame([$block, $plugin], array_keys($this->components($page)), 'The page renders the template with its own identity.');
    $this->assertSame($section, $this->adapter->getSectionUuid($page->sections()[0]));
    $this->assertSame([$block, $plugin], array_keys($this->components($this->hostResolver->fromEntity(EntityTest::load($entity->id())))), 'The seed is the same on every read.');
    // The template's style re-keyed onto the seeded section, in the page's
    // own map.
    $this->assertSame(['padding' => '2rem'], $page->styleMap()["container:$section"]['default']);
    // A placement on the seeded document, then the seeded block is edited.
    $result = $this->operations->batchOperations($page, [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $section, 'placement' => 'inside'],
      ],
      ['op' => 'update_element', 'element' => $block, 'properties' => ['field_text' => 'On the page']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($page);

    $entity = EntityTest::load($entity->id());
    $configuration = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent($block)->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id'], 'The content host saved the block as an entity.');
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $configuration);
    $block = $this->container->get('entity_type.manager')->getStorage('block_content')->loadRevision($configuration['block_revision_id']);
    $this->assertSame('On the page', $block->get('field_text')->value);

    // The template still holds values.
    $template = $this->components($this->displayHost())[self::BLOCK_UUID];
    $this->assertSame('Hello template', $template[InlineBlockValues::KEY]['field_text']);
    $this->assertArrayNotHasKey('block_revision_id', $template);
  }

  /**
   * The template serves a reader who may not use its text format.
   *
   * A formatted text value was judged when an editor stored it. Hydrating
   * it is a load and seeding a page from it is the site's act, so neither
   * asks whether the reader whose request triggered them may use the
   * format. An actor's own write with a format they may not use is still
   * refused (TextFormatWriteAccessTest).
   */
  public function testTemplateFormatNotJudgedForReader(): void {
    FilterFormat::create(['format' => 'editors_only', 'name' => 'Editors only'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'block_content',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'block_content',
      'bundle' => 'basic',
      'label' => 'Body',
      'required' => TRUE,
    ])->save();
    $body = ['value' => '<p>Rich</p>', 'format' => 'editors_only'];

    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host, [
      'field_text' => 'Hello template',
      'field_body' => $body,
    ]));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($host);
    $entity = EntityTest::create(['name' => 'A page']);
    $entity->save();

    // A reader with no permission at all.
    $this->setUpCurrentUser();
    $this->assertFalse(FilterFormat::load('editors_only')->access('use'));

    // The template's own render: the value block hydrates whole.
    $component = $this->displayHost()->sections()[0]->getComponent(self::BLOCK_UUID);
    $hydrated = $this->adapter->extractBlockContent($component);
    $this->assertSame($body, array_intersect_key($hydrated->get('field_body')->first()->getValue(), $body));

    // The never-edited page: the seed lands with the body on it.
    $page = $this->hostResolver->fromEntity(EntityTest::load($entity->id()));
    $block = DocumentInstantiator::derivedUuid($entity->uuid(), self::BLOCK_UUID);
    $seeded = $this->adapter->extractBlockContent($page->sections()[0]->getComponent($block));
    $this->assertSame($body, array_intersect_key($seeded->get('field_body')->first()->getValue(), $body));
  }

  /**
   * A value block placed on a content host directly saves as an entity.
   */
  public function testValueBlockComponentOnContentHostSaves(): void {
    $values = $this->container->get('daedalus_blueprint.inline_block_values');
    $section = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => self::SECTION_UUID]]);
    $section->appendComponent(new SectionComponent(self::BLOCK_UUID, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic',
      'label_display' => '0',
      'view_mode' => 'full',
      InlineBlockValues::KEY => ['info' => 'From values', 'field_text' => 'Value text'],
    ]));
    $entity = EntityTest::create(['name' => 'A page', OverridesSectionStorage::FIELD_NAME => [$section]]);
    $entity->save();
    $configuration = EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent(self::BLOCK_UUID)->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id']);
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $configuration);
    $this->assertSame('Value text', $this->container->get('entity_type.manager')->getStorage('block_content')->loadRevision($configuration['block_revision_id'])->get('field_text')->value);
    $this->assertSame([], $values->unspellable($this->adapter->extractBlockContent(EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->getComponent(self::BLOCK_UUID))));
  }

  /**
   * A block with a field the grammar cannot spell is refused on a template.
   */
  public function testFormOnlyFieldRefusedOnTemplate(): void {
    $fields = ['field_text' => 'Spelled', 'field_form_only' => 'Unspelled'];
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host, $fields));
    $this->assertFalse($result->isSuccess());
    $errors = $result->toArray()['errors'];
    $this->assertSame('FORM_ONLY_FIELDS', $errors[0]['code']);
    $this->assertSame(['field_form_only'], $errors[0]['context']['fields']);
    $this->assertSame([], $host->sections(), 'The batch rolled back.');

    // The same block on a content host is held as an entity, so it lands.
    $entity = EntityTest::create(['name' => 'A page']);
    $entity->save();
    $page = $this->hostResolver->fromEntity($entity);
    $result = $this->operations->batchOperations($page, $this->batch($page, $fields));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // An empty form-only field spells fine: nothing is lost.
    $host = $this->displayHost();
    $result = $this->operations->batchOperations($host, $this->batch($host, ['field_text' => 'Spelled']));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // The update door refuses the same way.
    $result = $this->operations->batchOperations($host, [
      ['op' => 'update_element', 'element' => self::BLOCK_UUID, 'properties' => ['field_form_only' => 'Unspelled']],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('FORM_ONLY_FIELDS', $result->toArray()['errors'][0]['code']);
  }

}
