<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Plugin\SectionStorage\BlueprintSectionStorage;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Event\PrepareLayoutEvent;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the blueprint host: a saved blueprint as a page of its own.
 *
 * A blueprint entity resolves to a BlueprintHost (the spine knows the type
 * by id, without the library module's classes): its display is the
 * subject's, its subject is core's sample entity of the subject bundle, an
 * empty document is never seeded, its storage is the `blueprint` plugin
 * that core and the tempstore both resolve for it, it renders itself, and
 * a commit through the storage handler is a new revision. A field node
 * instantiated into it expands against the subject, and page_read answers
 * its empty document without seeding it.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintHostTest extends KernelTestBase {

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
    'node',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    // The blueprint entity: its icon references media, its scope is a
    // list_string.
    'options',
    'image',
    'media',
    'daedalus_blueprint_library',
    // The page_read tool and the container layout its catalog needs.
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
  ];

  /**
   * The identities the batch mints.
   */
  protected const SECTION_UUID = '2b000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '2b000000-0000-4000-8000-000000000002';
  protected const PLUGIN_UUID = '2b000000-0000-4000-8000-000000000003';

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->setUpCurrentUser([], [], TRUE);

    // The subject bundle: node pages, Layout Builder enabled with overrides
    // and one configurable field, so core derives field blocks for it.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tagline',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tagline',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Tagline',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

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

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->storageHandler = $this->container->get('daedalus_blueprint.storage_handler');
    $this->hostResolver = $this->container->get('daedalus_blueprint.host_resolver');
    $this->tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
  }

  /**
   * A saved block blueprint for node pages.
   */
  protected function createBlueprint(array $values = []): Blueprint {
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
      'label' => 'Hero',
      'machine_name' => 'hero',
      'description' => 'A hero banner for pages.',
      'scope' => 'block',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * The blueprint, loaded fresh.
   */
  protected function loadBlueprint(int|string $id): Blueprint {
    /** @var \Drupal\daedalus_blueprint_library\Entity\Blueprint $blueprint */
    $blueprint = $this->container->get('entity_type.manager')->getStorage('blueprint')->loadUnchanged($id);
    return $blueprint;
  }

  /**
   * The one batch: a section, an inline block, a plugin block.
   */
  protected function batch(HostInterface $host): array {
    return [
      [
        'op' => 'add_section',
        'uuid' => self::SECTION_UUID,
        'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
        'layout' => 'layout_onecol',
      ],
      [
        'op' => 'add_element',
        'uuid' => self::BLOCK_UUID,
        'block_type' => 'basic',
        'properties' => ['field_text' => 'Hello blueprint'],
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => self::PLUGIN_UUID,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SECTION_UUID, 'placement' => 'inside'],
      ],
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
   * The host answers the six questions for a blueprint.
   */
  public function testHostContract(): void {
    $blueprint = $this->createBlueprint();
    $this->assertTrue($this->hostResolver->hosts($blueprint));

    $host = $this->hostResolver->fromEntity($blueprint);
    $this->assertInstanceOf(BlueprintHost::class, $host);
    $this->assertSame('blueprint', $host->entityTypeId());
    $this->assertSame($blueprint->id(), $host->id());
    $this->assertSame($blueprint->uuid(), $host->uuid());
    $this->assertSame('section', $host->bundle(), 'The bundle is the blueprint type.');
    $this->assertSame('Hero', $host->label());

    // The display is the subject's, the view mode the blueprint's own.
    $this->assertSame('node.page.default', $host->display()->id(), 'The display is the subject bundle\'s render display.');
    $this->assertSame('full', $host->viewMode(), 'The view mode is the blueprint\'s own full display, the one the canonical route renders and the tempstore keys on.');

    // The subject is core's sample entity of the subject bundle.
    $subject = $host->subject();
    $this->assertInstanceOf(NodeInterface::class, $subject);
    $this->assertSame('page', $subject->bundle());
    $this->assertTrue($subject->isNew(), 'The subject is a sample, never saved.');
    $this->assertSame($subject, $host->subject(), 'One sample per host instance.');
    $this->assertSame($blueprint, $host->fields(), 'The blueprint\'s own fields are its details.');

    // An empty document is never seeded, through either door.
    $this->assertTrue($host->hasDocument());
    $this->assertSame([], $host->sections());
    $this->assertSame([], $host->styleMap());
    $this->assertTrue($blueprint->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'Reading an empty blueprint seeds nothing.');
    $this->assertFalse($this->tempstore->has($blueprint), 'Reading stages nothing.');

    $persistence = $host->persistence();
    $this->assertTrue($persistence->revisions);
    $this->assertTrue($persistence->workspaceAware);
    $this->assertFalse($persistence->valueBlocks);

    // The storage is the blueprint plugin, wrapped nested-aware, and its
    // host is the blueprint.
    $storage = $host->sectionStorage();
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $storage);
    $root = $storage->getRootStorage();
    $this->assertInstanceOf(BlueprintSectionStorage::class, $root);
    $this->assertSame('blueprint', $storage->getStorageType());
    $this->assertSame('blueprint.' . $blueprint->id(), $storage->getStorageId());
    $this->assertInstanceOf(BlueprintHost::class, $this->hostResolver->fromSectionStorage($storage));

    // The storage's preview entity and the wrapper's root entity are the
    // subject: what the canvas renders against and the Add panel offers.
    $preview = $root->getContextsDuringPreview();
    $this->assertArrayNotHasKey('entity', $preview);
    $this->assertSame('page', $preview['layout_builder.entity']->getContextValue()->bundle());
    $this->assertSame('node', $preview['layout_builder.entity']->getContextValue()->getEntityTypeId());
    $wrapped = $storage->getContextsDuringPreview();
    $this->assertSame('node', $wrapped[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID]->getContextValue()->getEntityTypeId(), 'The root entity at every depth is the subject, not the blueprint.');
    $this->assertSame('node.page.default', $root->getDefaultSectionStorage()->getContext('display')->getContextValue()->id(), 'The default storage is the subject bundle\'s display.');
    $this->assertSame('entity.blueprint.canonical', $root->getLayoutBuilderUrl()->getRouteName(), 'The canvas is the canonical route.');

    // The storage travels into the layout tempstore with every staged edit;
    // the host it memoized answering the contexts above is request state and
    // must not (a host holds services the serialization trait cannot
    // replace). The woken storage answers the same subject.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $woken = unserialize(serialize($root));
    $this->assertInstanceOf(BlueprintSectionStorage::class, $woken);
    $this->assertSame('page', $woken->getContextsDuringPreview()['layout_builder.entity']->getContextValue()->bundle(), 'A storage woken from the tempstore rebuilds its host and still renders against the subject.');

    // Core resolves the same storage for a render of the blueprint, empty
    // as it is, and the tempstore strategy claims the entity.
    $resolved = $this->container->get('plugin.manager.layout_builder.section_storage')->findByContext([
      'entity' => EntityContext::fromEntity($blueprint),
      'display' => EntityContext::fromEntity($this->container->get('entity_type.manager')->getStorage('entity_view_display')->load('blueprint.section.full')),
      'view_mode' => new Context(new ContextDefinition('string'), 'full'),
    ], new CacheableMetadata());
    $this->assertInstanceOf(BlueprintSectionStorage::class, $resolved, 'Core renders a blueprint through its own storage, never the display\'s template.');
    $this->assertTrue($this->container->get('daedalus_tempstore.strategy.layout')->supports($blueprint));
    $this->assertSame($storage->getRootStorage()->getTempstoreKey(), $resolved->getTempstoreKey(), 'The host\'s storage and core\'s name one tempstore key.');

    // The blueprint renders itself and owns what it renders.
    $this->assertSame($host, $this->hostResolver->renderHost($host));
    $this->assertNull($this->hostResolver->foreignOwner($host));
    $this->assertInstanceOf(BlueprintHost::class, $this->storageHandler->load('blueprint', $blueprint->id()));

    // Access: the entity's update access, judged by the blueprint's type.
    $this->setUpCurrentUser([], ['access content']);
    $checker = $this->container->get('daedalus_blueprint.access_checker');
    $this->assertFalse($checker->hostAccess($host, 'update'));
    $this->assertTrue($checker->hostAccess($host, 'view'), 'A published blueprint is viewable by every editor.');
    $this->setUpCurrentUser([], ['edit any site_chrome blueprint']);
    $this->assertFalse($checker->hostAccess($host, 'update'), 'Another type\'s grant opens nothing.');
    $this->setUpCurrentUser([], ['edit any section blueprint']);
    $this->assertTrue($checker->hostAccess($host, 'update'));
  }

  /**
   * Preparing an empty blueprint's canvas copies no template into it.
   *
   * Core's PrepareLayout subscriber fills an override that has not yet been
   * overridden with its default storage's sections, and a blueprint's
   * default storage is the subject bundle's display: entering Edit Mode on
   * a blueprint composed from scratch staged the subject's template (its
   * sections, without their styles). A blueprint's document is always its
   * own, so its storage answers overridden, empty or not.
   */
  public function testPrepareLayoutCopiesNoTemplate(): void {
    $blueprint = $this->createBlueprint();
    $storage = $this->hostResolver->fromEntity($blueprint)->sectionStorage();
    $this->assertNotEmpty($storage->getRootStorage()->getDefaultSectionStorage()->getSections(), 'The subject bundle\'s display carries a template.');

    $this->container->get('event_dispatcher')->dispatch(new PrepareLayoutEvent($storage), LayoutBuilderEvents::PREPARE_LAYOUT);

    $this->assertCount(0, $storage->getSections(), 'An empty blueprint is an empty canvas, never the subject\'s template.');
    $staged = $this->container->get('layout_builder.tempstore_repository')->get($storage->getRootStorage());
    $this->assertCount(0, $staged->getSections(), 'Nothing of the template is staged either.');
  }

  /**
   * A blueprint refuses an unknown subject, scope or design system at save.
   */
  public function testPreSaveRefusals(): void {
    foreach ([
      ['subject_bundle' => 'article'],
      ['subject_type' => 'entity_view_display', 'subject_bundle' => 'entity_view_display'],
      ['scope' => 'section'],
      ['design_system' => 'nowhere'],
    ] as $values) {
      try {
        $this->createBlueprint($values + ['machine_name' => 'refused']);
        $this->fail('The save was refused: ' . json_encode($values));
      }
      catch (EntityStorageException $e) {
        // Core wraps a presave refusal in the storage exception.
        $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious(), json_encode($values));
      }
    }
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('blueprint')->getQuery()->accessCheck(FALSE)->count()->execute());

    // A machine name is derived from the label and kept unique.
    $first = $this->createBlueprint(['machine_name' => NULL, 'label' => 'Call to Action']);
    $this->assertSame('call_to_action', $first->getMachineName());
    $second = $this->createBlueprint(['machine_name' => NULL, 'label' => 'Call to Action']);
    $this->assertSame('call_to_action_2', $second->getMachineName());
    $this->assertSame(['node:page'], $this->createBlueprint(['machine_name' => 'listed', 'bundles' => ['node:page']])->getBundles());
  }

  /**
   * Stage and commit: a commit through the handler is a new revision.
   */
  public function testStageAndCommitMakeRevision(): void {
    $blueprint = $this->createBlueprint();
    $first_revision = $blueprint->getRevisionId();
    $host = $this->storageHandler->load('blueprint', $blueprint->id());
    $result = $this->operations->batchOperations($host, $this->batch($host));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([self::BLOCK_UUID, self::PLUGIN_UUID], array_keys($this->components($host)));

    // The stage: a fresh load answers the staged document under the
    // blueprint storage's key; the saved row is untouched.
    $this->assertSame('tempstore', $this->storageHandler->persist($host));
    $staged = $this->storageHandler->load('blueprint', $blueprint->id());
    $this->assertInstanceOf(BlueprintHost::class, $staged);
    $this->assertCount(1, $staged->sections());
    $this->assertTrue($this->loadBlueprint($blueprint->id())->get(OverridesSectionStorage::FIELD_NAME)->isEmpty());
    $this->assertTrue($this->tempstore->has($blueprint));

    // The commit: a new revision of the blueprint, the stage ended, the
    // inline block saved as a block entity (content persistence).
    $this->storageHandler->commit($staged);
    $this->assertFalse($this->tempstore->has($blueprint));
    $saved = $this->loadBlueprint($blueprint->id());
    $this->assertNotSame($first_revision, $saved->getRevisionId(), 'A commit of an existing blueprint is a new revision.');
    $this->assertSame($this->container->get('current_user')->id(), $saved->getRevisionUserId());
    $sections = $saved->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(1, $sections);
    $configuration = $sections[0]->getComponent(self::BLOCK_UUID)->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id'], 'The blueprint saves its inline block as an entity, like a page.');
    $revisions = $this->container->get('entity_type.manager')->getStorage('blueprint')->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $blueprint->id())->count()->execute();
    $this->assertSame(2, (int) $revisions);

    // A second commit is a third revision: every commit is one.
    $host = $this->storageHandler->load('blueprint', $blueprint->id());
    $result = $this->operations->batchOperations($host, [
      ['op' => 'remove_element', 'element' => self::PLUGIN_UUID],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($host);
    $this->assertSame(3, (int) $this->container->get('entity_type.manager')->getStorage('blueprint')->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $blueprint->id())->count()->execute());
    $this->assertCount(1, $this->components($this->storageHandler->load('blueprint', $blueprint->id())));
  }

  /**
   * A field node instantiated into a blueprint expands against the subject.
   */
  public function testFieldNodeExpandsAgainstSubject(): void {
    $blueprint = $this->createBlueprint();
    $host = $this->hostResolver->fromEntity($blueprint);
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiate($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              ['kind' => 'block', 'field' => 'field_tagline'],
            ],
          ],
        ],
      ],
    ], ['anchor' => $host->uuid(), 'placement' => 'inside']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $components = $this->components($host);
    $this->assertCount(1, $components);
    $this->assertSame('field_block:node:page:field_tagline', reset($components)['id'], 'The field block is the subject bundle\'s, not the blueprint\'s.');
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $this->assertSame('field_tagline', $tree[0]['children'][0]['children'][0]['field']);

    // A field the subject lacks is refused with the subject's fields.
    $section_uuid = $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->getSectionUuid($host->sections()[0]);
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiate($host, [
      ['kind' => 'block', 'field' => 'machine_name'],
    ], ['anchor' => $section_uuid, 'placement' => 'inside']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('FIELD_NOT_ON_HOST', $result->getErrors()[0]->getCode());
  }

  /**
   * The live render of a blueprint renders its document against the subject.
   *
   * Outside Edit Mode the canonical route renders through core's view
   * builder (daedalus_layout's LayoutBuilderEntityViewDisplay::buildSections),
   * which must hand the sections the subject, not the blueprint, as
   * layout_builder.entity: a field block of the subject bundle is forbidden
   * against the blueprint (FieldBlock::blockAccess asks hasField() on the
   * entity in context), so with core's contexts a live view showed only the
   * non-field blocks. The canvas already rendered against the subject; the
   * two must agree.
   */
  public function testLiveRenderAgainstSubject(): void {
    $blueprint = $this->createBlueprint();
    $host = $this->storageHandler->load('blueprint', $blueprint->id());
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiate($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              ['kind' => 'block', 'field' => 'field_tagline'],
              ['kind' => 'block', 'plugin_id' => 'system_powered_by_block'],
            ],
          ],
        ],
      ],
    ], ['anchor' => $host->uuid(), 'placement' => 'inside']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->storageHandler->commit($host);

    $saved = $this->loadBlueprint($blueprint->id());
    $build = $this->container->get('entity_type.manager')->getViewBuilder('blueprint')->view($saved, 'full');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('Powered by', $html, 'The document\'s plugin block renders live.');
    $this->assertStringContainsString('data-element="block_plugin:field_block"', $html, 'The subject\'s field block renders live: the sections render against the subject, not the blueprint.');
    $this->assertStringContainsString('data-element="kind:field"', $html, 'The field block renders its field, not an empty block.');
    $this->assertStringContainsString('Tagline', $html, 'The field renders with the subject bundle\'s field label.');
    $tagline = $this->hostResolver->fromEntity($saved)->subject()->get('field_tagline')->value;
    $this->assertNotEmpty($tagline, 'The sample subject carries a tagline value.');
    $this->assertStringContainsString($tagline, $html, 'The field block shows the sample subject\'s value.');
    $this->assertStringContainsString(\Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS, $html, 'The sections render inside the layout root.');
  }

  /**
   * The page_read tool answers a blueprint's empty document, seeding nothing.
   */
  public function testPageReadAnswersEmptyDocument(): void {
    $blueprint = $this->createBlueprint();
    $tool = $this->container->get('plugin.manager.ai.function_calls')->getFunctionCallFromFunctionName('page_read');
    $tool->setContextValue('entity_type', 'blueprint');
    $tool->setContextValue('entity_id', (string) $blueprint->id());
    $tool->execute();
    $output = $tool->getReadableOutput();
    $this->assertStringContainsString('(the page has no layout content yet)', $output);
    $this->assertStringContainsString('Hero', $output, 'The blueprint\'s own fields head the read.');
    $this->assertStringNotContainsString('Error', $output);
    $this->assertTrue($this->loadBlueprint($blueprint->id())->get(OverridesSectionStorage::FIELD_NAME)->isEmpty(), 'The read seeded nothing.');
    $this->assertFalse($this->tempstore->has($blueprint), 'The read staged nothing.');
  }

}
