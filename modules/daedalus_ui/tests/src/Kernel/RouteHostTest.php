<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Tests\daedalus_ui\Traits\CurrentRouteTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the route host: the owner of the canvas on every route shape.
 *
 * The UI derives one host from the route (an entity parameter, an
 * overrides storage, a defaults storage, the site frame on its canvas
 * route), Edit Mode applies to it when
 * the actor may do something to it (the capability spine's verdict, not
 * a second access check), and the canvas of a template host (core's
 * manage-display layout route) carries the top bar, the layout root and a
 * root wrapper naming the display while core's sample entity, the
 * render's subject, stays inert. A plain host (no document of its own)
 * renders its display's document as a foreign document: its own fields
 * edit in place, the template's blocks do not.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class RouteHostTest extends KernelTestBase {

  use CurrentRouteTrait;
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
    'editor',
    'field_sample_value',
    'daedalus_edit',
  ];

  /**
   * The overridable display: the content host's template.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The non-overridable bundle: its entities are plain hosts.
   */
  protected const PLAIN_BUNDLE = 'template';

  /**
   * The template's inline block, and its field block.
   */
  protected const TEMPLATE_BLOCK_UUID = '2b000000-0000-4000-8000-000000000001';
  protected const TEMPLATE_FIELD_UUID = '2b000000-0000-4000-8000-000000000002';

  /**
   * The slot a locked page's test places in its template, and the page's fill.
   */
  protected const SLOT_BLOCK_UUID = '2b000000-0000-4000-8000-000000000003';
  protected const OWN_SECTION_UUID = '2b000000-0000-4000-8000-000000000011';
  protected const OWN_BLOCK_UUID = '2b000000-0000-4000-8000-000000000012';

  /**
   * The daedalus_ui service.
   *
   * @var \Drupal\daedalus_ui\DaedalusUi
   */
  protected $ui;

  /**
   * The host resolver.
   *
   * @var \Drupal\daedalus_blueprint\Host\HostResolver
   */
  protected $hostResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'block_content', 'daedalus_ui']);
    $this->setUpCurrentUser([], [], TRUE);

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
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'basic',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_text', ['type' => 'string'])->save();

    // The overridable bundle: entities host their own document; the display
    // hosts the template, one section with a field block and a text block.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->appendSection($this->templateSection('entity_test'))
      ->save();
    // A display without Layout Builder beside it: config that hosts nothing.
    EntityViewMode::create(['id' => 'entity_test.teaser', 'targetEntityType' => 'entity_test', 'label' => 'Teaser'])->save();

    // The non-overridable bundle: no layout field, so its entities are
    // plain hosts rendering the display's document.
    EntityTestHelper::createBundle(self::PLAIN_BUNDLE, 'Template bundle', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => self::PLAIN_BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->appendSection($this->templateSection(self::PLAIN_BUNDLE))
      ->save();

    $this->ui = $this->container->get('daedalus_ui.ui');
    $this->hostResolver = $this->container->get('daedalus_blueprint.host_resolver');
  }

  /**
   * A template section: the bundle's name field block and a text block.
   */
  protected function templateSection(string $bundle): Section {
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', '2b000000-0000-4000-8000-00000000000' . ($bundle === 'entity_test' ? '9' : '8'));
    $section->appendComponent(new SectionComponent(self::TEMPLATE_FIELD_UUID, 'content', [
      'id' => "field_block:entity_test:$bundle:name",
      'label' => 'Name',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]));
    $block = BlockContent::create([
      'type' => 'basic',
      'info' => 'Template text',
      'reusable' => FALSE,
      'field_text' => 'Template text value',
    ]);
    $section->appendComponent(new SectionComponent(self::TEMPLATE_BLOCK_UUID, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Template text',
      'label_display' => '0',
      'block_serialized' => serialize($block),
    ]));
    return $section;
  }

  /**
   * The display of a bundle, loaded fresh.
   */
  protected function display(string $bundle = 'entity_test'): LayoutBuilderEntityViewDisplay {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->container->get('entity_type.manager')->getStorage('entity_view_display')->loadUnchanged("entity_test.$bundle.default");
    return $display;
  }

  /**
   * The display's defaults storage, as core's route upcasts it.
   */
  protected function defaultsStorage(LayoutBuilderEntityViewDisplay $display): SectionStorageInterface {
    return $this->container->get('plugin.manager.layout_builder.section_storage')->load('defaults', [
      'display' => EntityContext::fromEntity($display),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
  }

  /**
   * An entity's overrides storage, as core's route upcasts it.
   */
  protected function overridesStorage(EntityTest $entity): SectionStorageInterface {
    return $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($entity),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
  }

  /**
   * An XPath over rendered HTML.
   */
  protected function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * One host from each route shape.
   */
  public function testHostFromEachRouteShape(): void {
    $entity = EntityTest::create(['name' => 'Route entity']);
    $entity->save();
    $display = $this->display();

    $host = $this->ui->deriveHostFromRoute(new RouteMatch('entity.entity_test.canonical', $this->route(['entity_test']), ['entity_test' => $entity]));
    $this->assertInstanceOf(ContentHost::class, $host, 'An entity parameter derives a content host.');
    $this->assertSame($entity->id(), $host->id());

    $route = $this->route(['entity_test', 'section_storage']);
    $host = $this->ui->deriveHostFromRoute(new RouteMatch('layout_builder.overrides.entity_test.view', $route, [
      'entity_test' => $entity,
      'section_storage' => $this->overridesStorage($entity),
    ]));
    $this->assertInstanceOf(ContentHost::class, $host, 'An overrides storage derives its entity, one host with the entity parameter beside it.');
    $this->assertSame($entity->id(), $host->id());

    $route = $this->route(['section_storage', 'view_mode_name', 'entity_view_mode']);
    $host = $this->ui->deriveHostFromRoute(new RouteMatch('layout_builder.defaults.entity_test.view', $route, [
      'section_storage' => $this->defaultsStorage($display),
      'view_mode_name' => 'default',
      // A config entity that hosts nothing beside the storage is passed over.
      'entity_view_mode' => EntityViewMode::load('entity_test.teaser'),
    ]));
    $this->assertInstanceOf(DisplayHost::class, $host, 'A defaults storage derives its display, whatever else rides the route.');
    $this->assertSame(self::DISPLAY_ID, $host->id());

    $frame = Frame::create(['id' => 'stark']);
    $frame->save();
    $host = $this->ui->deriveHostFromRoute(new RouteMatch(FrameInterface::CANVAS_ROUTE, $this->route(['daedalus_frame']), ['daedalus_frame' => $frame]));
    $this->assertInstanceOf(FrameHost::class, $host, 'A frame parameter derives the frame host: the frame canvas route.');
    $this->assertSame('stark', $host->id());
    $this->assertTrue($this->ui->hasEditingUrl($frame), 'The frame is edited on its canvas route, daedalus_layout\'s.');
    $this->assertSame('/site-frame/stark', $this->ui->getEditingUrl($frame)->toString());

    $other = EntityTest::create(['name' => 'Other']);
    $other->save();
    $this->assertNull($this->ui->deriveHostFromRoute(new RouteMatch('x', $this->route(['entity_test', 'section_storage']), [
      'entity_test' => $entity,
      'section_storage' => $this->overridesStorage($other),
    ])), 'Two different hosts on one route derive none.');
    $this->assertNull($this->ui->deriveHostFromRoute(new RouteMatch('x', $this->route(['entity_view_mode']), [
      'entity_view_mode' => EntityViewMode::load('entity_test.teaser'),
    ])), 'A route with no host derives none.');
  }

  /**
   * Edit Mode applies on the defaults route for an editor, not otherwise.
   */
  public function testEditModeAppliesOnDefaultsRoute(): void {
    $modes = $this->container->get('plugin.manager.modes');
    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $this->defaultsStorage($this->display())]);
    $this->assertTrue($modes->createInstance('edit')->applies(), 'The template host is a route host Edit Mode applies to.');

    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $this->defaultsStorage($this->display())], NULL, TRUE);
    $this->assertFalse($modes->createInstance('edit')->applies(), 'An admin route still vetoes.');

    $this->setUpCurrentUser([], ['view test entity']);
    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $this->defaultsStorage($this->display())]);
    $this->assertFalse($modes->createInstance('edit')->applies(), 'Without the edit-mode permission there is no Edit Mode.');

    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute('x', [
      'entity_view_mode' => EntityViewMode::load('entity_test.teaser'),
    ]);
    $this->assertFalse($modes->createInstance('edit')->applies(), 'No host, no Edit Mode.');
  }

  /**
   * Edit Mode on the frame canvas follows the one frame permission.
   */
  public function testFrameRouteEditModeByPermission(): void {
    $modes = $this->container->get('plugin.manager.modes');
    $frame = Frame::create(['id' => 'stark']);
    $frame->save();

    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame], 'edit');
    $this->assertInstanceOf(FrameHost::class, $this->ui->deriveHostFromRoute());
    $this->assertTrue($modes->createInstance('edit')->applies(), 'The admin may configure the frame: Edit Mode applies on its canvas.');

    $this->setUpCurrentUser([], ['use daedalus ui edit mode']);
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame], 'edit');
    $this->assertFalse($this->container->get('daedalus_blueprint.verdict_stamper')->allowsAnywhere($this->ui->deriveHostFromRoute()), 'Without the frame permission nothing on the frame is the actor\'s.');
    $this->assertFalse($modes->createInstance('edit')->applies(), 'No capability on the frame, no Edit Mode.');

    $this->setUpCurrentUser([], ['use daedalus ui edit mode', FrameInterface::PERMISSION]);
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame], 'edit');
    $this->assertTrue($modes->createInstance('edit')->applies(), 'The one frame permission opens the frame canvas.');
  }

  /**
   * Edit Mode on a plain host follows the capability spine, not entity access.
   *
   * With no capability on anything (the host access policy refusing an
   * actor without update access) Edit Mode does not apply; the editor,
   * whose content capability on the page's own fields is open though the
   * template's structure is not, gets Edit Mode.
   */
  public function testPlainHostEditModeByCapability(): void {
    $modes = $this->container->get('plugin.manager.modes');
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain host name']);
    $entity->save();

    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'view test entity']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity]);
    $this->assertFalse($this->container->get('daedalus_blueprint.verdict_stamper')->allowsAnywhere($this->ui->deriveHostFromRoute()), 'A viewer may do nothing to the page.');
    $this->assertFalse($modes->createInstance('edit')->applies(), 'No capability on anything, no Edit Mode.');

    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'administer entity_test content']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity]);
    $host = $this->ui->deriveHostFromRoute();
    $this->assertNotNull($this->hostResolver->foreignOwner($host), 'The page renders its template.');
    $this->assertTrue($modes->createInstance('edit')->applies(), 'The editor may edit the page\'s own fields, so Edit Mode applies on the plain host.');
  }

  /**
   * The template canvas in Edit Mode.
   *
   * The top bar, the layout root, a root wrapper naming the display, and an
   * inert sample entity beside the template's own editable text block.
   */
  public function testDefaultsRouteCanvasInEditMode(): void {
    $display = $this->display();
    $this->setCurrentRoute('layout_builder.defaults.entity_test.view', ['section_storage' => $this->defaultsStorage($display)], 'edit');
    $host = $this->ui->deriveHostFromRoute();
    $this->assertInstanceOf(DisplayHost::class, $host);

    $page_top = [];
    $this->ui->buildPageTop($page_top);
    $chrome = (string) $this->container->get('renderer')->renderInIsolation($page_top);
    $this->assertStringContainsString('id="edit-mode-top-bar"', $chrome, 'The top bar renders on the defaults route.');
    $this->assertStringContainsString('daedalus-ui-save', $chrome, 'The top bar owns Save.');

    $build = $this->container->get('daedalus_blueprint_api.canvas_builder')->build($host, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    $roots = $xpath->query('//*[@id="layout-builder"]');
    $this->assertSame(1, $roots->length, 'The canvas renders the layout root: ' . $html);
    $this->assertStringContainsString('daedalus-layout-layout', $roots->item(0)->getAttribute('class'));

    $wrapper_id = 'entity_view_display::' . self::DISPLAY_ID . '::entity_view_display';
    $wrappers = $xpath->query(sprintf('//*[@data-daedalus-ui-entity-wrapper="%s"]', $wrapper_id));
    $this->assertSame(1, $wrappers->length, 'The canvas carries one root wrapper naming the display: ' . $html);
    $wrapper = $wrappers->item(0);
    $this->assertSame('true', $wrapper->getAttribute('data-main-entity'));
    $this->assertSame('entity:' . $display->uuid(), $wrapper->getAttribute('data-selectable'));
    $this->assertSame('default', $wrapper->getAttribute('data-daedalus-ui-view-mode'));
    $this->assertSame(1, $xpath->query('//*[@id="layout-builder"][ancestor::*[@data-main-entity]]')->length, 'The layout root sits inside the root wrapper.');

    // The sample entity's field renders as the field block's content and
    // is inert: no selection identity, no editor leaf. The template's own
    // text block edits in place.
    $sample = $host->subject();
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_UUID . '"]//*[@data-element="kind:field"]')->length, 'The sample entity\'s name field renders inside the field block: ' . $html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_UUID . '"]//*[@data-daedalus-edit-id]')->length, 'No editor leaf on the sample entity\'s field: ' . $html);
    $this->assertSame(0, $xpath->query('//*[starts-with(@data-selectable, "field:' . $sample->uuid() . '")]')->length, 'The sample entity\'s fields carry no selection identity.');
    $this->assertFalse($xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_UUID . '"]')->item(0)->hasAttribute('data-presented-field'), 'A field block on a template presents no field of the host.');
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK_UUID . '"]//*[@data-daedalus-edit-id]')->length, 'The template\'s own text block edits in place: ' . $html);

    // The mirror rides the canvas, rooted on the display.
    $settings = $build['#attached']['drupalSettings']['daedalusUi']['blueprint'] ?? NULL;
    $this->assertIsString($settings, 'The blueprint mirror is delivered on the template canvas.');
    $envelope = json_decode($settings, TRUE);
    $this->assertSame('entity_view_display', $envelope['entityType']);
    $this->assertSame(self::DISPLAY_ID, $envelope['entityId']);
    $this->assertSame($display->uuid(), $envelope['uuid']);
    $this->assertSame('Template', $envelope['blueprint']['_element']['type']);
    $this->assertArrayNotHasKey('fields', $envelope['blueprint']);
    $this->assertArrayNotHasKey('owner', $envelope['blueprint']['layout'][0]['_element'], 'A template owns its document.');
  }

  /**
   * A plain host renders its display's document as a foreign document.
   */
  public function testPlainHostRendersForeignDocument(): void {
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Plain host name']);
    $entity->save();
    $this->assertTrue($entity->hasField(OverridesSectionStorage::FIELD_NAME), 'A locked bundle carries the layout field too: daedalus_layout adds it to every enabled bundle.');
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $host = $this->ui->deriveHostFromRoute();
    $this->assertTrue($host->hasDocument(), 'The page owns a document, empty here.');
    $this->assertSame([], $host->ownSections(), 'A locked page is never seeded.');
    $owner = $this->hostResolver->foreignOwner($host);
    $this->assertInstanceOf(DisplayHost::class, $owner);
    $this->assertCount(1, $host->sections(), 'The plain host reads the display\'s document.');

    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    $roots = $xpath->query('//*[@id="layout-builder"]');
    $this->assertSame(1, $roots->length, 'The plain host\'s canvas renders the foreign document: ' . $html);
    $this->assertFalse($roots->item(0)->hasAttribute('data-daedalus-ui-document-owner-label'), 'The canvas carries no owner stamp: the verdict rides the mirror envelope.');
    $this->assertSame(0, $xpath->query('//*[@data-daedalus-ui-entity-wrapper="entity_view_display::' . $owner->id() . '::entity_view_display"]')->length, 'The owner is named, never made the canvas root: the entity view is (entity_test has no attribute-printing template in a kernel render, so the root wrapper itself is another test\'s).');

    // The mirror envelope explains the page: the host-level verdict names
    // the owner, refuses the document writes, and (for this admin, who may
    // open the template) carries the portal, whose url is NULL without
    // field_ui's manage-layout route.
    $envelope = json_decode($build['#attached']['drupalSettings']['daedalusUi']['blueprint'], TRUE);
    $this->assertTrue($envelope['verdict']['can']['content'], 'The page\'s own fields stay the page\'s.');
    $this->assertFalse($envelope['verdict']['can']['arrange'], 'The top-level flow is the template\'s.');
    $this->assertSame('FOREIGN_DOCUMENT', $envelope['verdict']['refused'][0]['code']);
    $this->assertSame($owner->label(), $envelope['verdict']['refused'][0]['context']['owner']['label']);
    $this->assertSame((string) $owner->id(), $envelope['verdict']['refused'][0]['context']['portal']['entity_id']);
    $this->assertNull($envelope['verdict']['refused'][0]['context']['portal']['url']);
    $this->assertFalse($envelope['blueprint']['layout'][0]['_element']['can']['arrange'], 'A template section carries its verdict on its annotation.');

    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_UUID . '"]//*[@data-selectable="field:' . $entity->uuid() . ':name"]//*[@data-daedalus-edit-id]')->length, 'The host\'s own field edits in place through the field block: ' . $html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK_UUID . '"]//*[@data-daedalus-edit-id]')->length, 'The template\'s text block is inert on a plain host: ' . $html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK_UUID . '"]//*[starts-with(@data-selectable, "field:")]')->length, 'The template block\'s fields carry no selection identity.');
  }

  /**
   * A locked page's own inline block edits in place; the template's does not.
   *
   * The multi-host render: the template (with a slot) around the page's
   * own slot section, every inline block rooted on the page. Editable
   * attribution follows the document that holds the block, not the root:
   * the text block the page placed in its slot section carries the editor
   * leaf and its field's selection identity, the template's text block
   * beside it stays inert.
   */
  public function testLockedPageOwnsItsSlotBlocks(): void {
    // The site's one slot, placed in the locked bundle's template.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $display = $this->display(self::PLAIN_BUNDLE);
    $display->getSection(0)->appendComponent(new SectionComponent(self::SLOT_BLOCK_UUID, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':hero',
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]));
    $display->save();

    $own_block = BlockContent::create([
      'type' => 'basic',
      'info' => 'Own text',
      'reusable' => FALSE,
      'field_text' => 'Own text value',
    ]);
    $own = new Section('layout_onecol');
    $own->setThirdPartySetting('daedalus_layout', 'uuid', self::OWN_SECTION_UUID);
    $own->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $own->appendComponent(new SectionComponent(self::OWN_BLOCK_UUID, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Own text',
      'label_display' => '0',
      'block_serialized' => serialize($own_block),
    ]));
    $entity = EntityTest::create(['type' => self::PLAIN_BUNDLE, 'name' => 'Locked host name']);
    $entity->set(OverridesSectionStorage::FIELD_NAME, [$own]);
    $entity->save();

    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $entity], 'edit');
    $host = $this->ui->deriveHostFromRoute();
    $this->assertNotNull($this->hostResolver->foreignOwner($host), 'The page renders inside its template.');
    $this->assertCount(2, $host->sections(), 'The template, then the page\'s slot section.');

    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    // The page's own block rendered under the page as the root (the
    // template's did too, but a template's block is a value block whose
    // hydration mints a uuid per extraction, so only the render's own
    // instance could be asked; the markup below is its proof).
    $this->assertSame($entity->uuid(), $this->container->get('daedalus_layout.root_entity_registry')->getRoot($own_block)?->uuid(), 'The page\'s own block is rooted on the page.');
    // The one owner rule attributes per document, not per root.
    $this->assertTrue($this->ui->ownsFields($entity), 'The page\'s own fields.');
    $this->assertTrue($this->ui->ownsFields($own_block), 'The block in the page\'s slot section is the page\'s own.');

    $slot = '//*[@data-layout-builder-block-uuid="' . self::SLOT_BLOCK_UUID . '"]';
    $this->assertSame(1, $xpath->query($slot . '//*[@data-selectable="container:' . self::OWN_SECTION_UUID . '"]')->length, 'The slot renders the page\'s section: ' . $html);
    $this->assertSame(1, $xpath->query($slot . '//*[@data-layout-builder-block-uuid="' . self::OWN_BLOCK_UUID . '"]//*[@data-selectable="field:' . $own_block->uuid() . ':field_text"]//*[@data-daedalus-edit-id]')->length, 'The page\'s own text block edits in place inside the slot: ' . $html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK_UUID . '"]//*[@data-daedalus-edit-id]')->length, 'The template\'s text block stays inert beside it: ' . $html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK_UUID . '"]//*[starts-with(@data-selectable, "field:")]')->length);
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_UUID . '"]//*[@data-selectable="field:' . $entity->uuid() . ':name"]//*[@data-daedalus-edit-id]')->length, 'The page\'s own field still edits through the template\'s field block.');
  }

}
