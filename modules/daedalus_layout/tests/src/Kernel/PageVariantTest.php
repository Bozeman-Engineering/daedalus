<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Render\PageDisplayVariantSelectionEvent;
use Drupal\Core\Render\RenderEvents;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_layout\Controller\FrameCanvas;
use Drupal\daedalus_layout\EventSubscriber\FramePageVariant;
use Drupal\daedalus_layout\Frame\MainContentStandIn;
use Drupal\daedalus_layout\Theme\FrameCanvasTheme;
use Drupal\daedalus_ui\Chromeless;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\Plugin\DisplayVariant\DaedalusPageVariant;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus_ui\Traits\CurrentRouteTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * The site frame as the page: the page display variant and its render.
 *
 * A theme that has a daedalus_frame renders every front-end route as the
 * frame's document around the route's output (FramePageVariant selects
 * DaedalusPageVariant in place of the block module's variant, never on an
 * admin route, never over a chromeless one; a theme without a frame keeps
 * block layout). The frame renders through the one primitive as a
 * document root: its main slot prints the main content inside the page's
 * main landmark, a placed title block shows the route's title, a placed
 * messages block the messages (prepended to the body when none is
 * placed), a linked composition in the frame shows the route's entity's
 * field on an entity route and nothing of it on a route without one, a
 * frame slot is filled by the page beneath it, the frame's styles deliver
 * inside its layout root with their keys stamped for the visitor, and the
 * frame's offer carries the page blocks and refuses the main content.
 * From any page in Edit Mode the frame's nodes are foreign documents
 * with a portal (the spine's verdict seen from the page's canvas,
 * VerdictStamper::verdictsOn(): the frame canvas for an actor who may
 * configure the frame, the linked chrome's blueprint canvas for one who
 * may update the blueprint), and the frame canvas (the frame's canonical
 * route) is the one canvas element over the frame storage, rendered under
 * the frame render's own contexts with a stand-in for the route's output,
 * in the frame's own theme, never framed again; a blueprint's canvas (its
 * canonical route) is framed, and renders in situ inside the frame door
 * whose default is the blueprint, main printing the stand-in.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class PageVariantTest extends KernelTestBase {

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
    // The locked bundle's template is edited on core's manage-display
    // layout route, which exists only under field_ui: the template's
    // editing surface, the portal its nodes wear on a page.
    'field_ui',
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
    // The container layout the catalog needs: a page-language site.
    'daedalus_blueprint_test',
    // The blueprint entity a linked placement shows.
    'options',
    'image',
    'media',
    'daedalus_blueprint_library',
  ];

  /**
   * The theme whose frame is under test.
   */
  protected const THEME = 'stark';

  /**
   * The identities of the frame's document.
   */
  protected const FRAME_SECTION = '5f000000-0000-4000-8000-000000000001';
  protected const CHROME_BLOCK = '5f000000-0000-4000-8000-000000000002';
  protected const TITLE_BLOCK = '5f000000-0000-4000-8000-000000000003';
  protected const MESSAGES_BLOCK = '5f000000-0000-4000-8000-000000000004';
  protected const MAIN_SLOT = '5f000000-0000-4000-8000-000000000005';
  protected const HERO_SLOT = '5f000000-0000-4000-8000-000000000006';
  protected const LINKED_BLOCK = '5f000000-0000-4000-8000-000000000007';
  protected const CONTAINER = '5f000000-0000-4000-8000-000000000008';
  protected const NESTED_BLOCK = '5f000000-0000-4000-8000-000000000009';

  /**
   * The identities of the header blueprint's document.
   */
  protected const BLUEPRINT_SECTION = '5f000000-0000-4000-8000-00000000000a';
  protected const BLUEPRINT_FIELD_BLOCK = '5f000000-0000-4000-8000-00000000000b';

  /**
   * The identities of the locked bundle's template and the page's fill.
   */
  protected const TEMPLATE_SECTION = '5f000000-0000-4000-8000-00000000000c';
  protected const TEMPLATE_FIELD_BLOCK = '5f000000-0000-4000-8000-00000000000d';
  protected const TEMPLATE_HERO_FILL = '5f000000-0000-4000-8000-00000000000e';
  protected const TEMPLATE_HERO_BLOCK = '5f000000-0000-4000-8000-00000000000f';
  protected const PAGE_HERO_FILL = '5f000000-0000-4000-8000-000000000010';
  protected const PAGE_HERO_BLOCK = '5f000000-0000-4000-8000-000000000011';
  protected const TEMPLATE_BLOCK = '5f000000-0000-4000-8000-000000000012';

  /**
   * The header blueprint.
   */
  protected Blueprint $blueprint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig([
      'filter',
      'block_content',
      'image',
      'file',
      'media',
      'daedalus_stylish',
      'daedalus_blueprint_library',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
    // A kernel test has no request to negotiate a theme from (the manager
    // falls back to core's own), so the theme a page would be rendered in
    // is set the way the negotiator would.
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName(self::THEME));
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();

    // The site's slots: the frame's main and a hero the frame declares.
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();

    // The locked bundle: Layout Builder on, overrides off, a template of
    // one section presenting the name beside a block of the template's
    // own, so its pages render the template and own only their slot fills
    // and the field the template presents.
    $template = $this->section('layout_onecol', self::TEMPLATE_SECTION, [
      $this->fieldBlock(self::TEMPLATE_FIELD_BLOCK, 'entity_test'),
      $this->pluginBlock(self::TEMPLATE_BLOCK, 'system_powered_by_block', 'Template sentinel'),
    ]);
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder();
    $display->save();
    $display->removeAllSections()->appendSection($template)->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    // The header: a site_chrome blueprint for the locked bundle, holding
    // a field block of the subject, so on an entity route it shows the
    // page's own name and on any other route nothing of it.
    $this->blueprint = Blueprint::create([
      'type' => 'site_chrome',
      'label' => 'Header',
      'machine_name' => 'header',
      'description' => 'The site header.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      ContentHost::LAYOUT_FIELD => [
        $this->section('layout_onecol', self::BLUEPRINT_SECTION, [
          $this->fieldBlock(self::BLUEPRINT_FIELD_BLOCK, 'entity_test'),
        ]),
      ],
    ]);
    $this->blueprint->save();
  }

  /**
   * A section of a layout with components, stamped with its identity.
   *
   * @param string $layout_id
   *   The layout plugin id.
   * @param string $uuid
   *   The section's uuid (daedalus_layout's identity).
   * @param \Drupal\layout_builder\SectionComponent[] $components
   *   The components.
   * @param string|null $slot
   *   The slot the section fills, when it is a slot section.
   */
  protected function section(string $layout_id, string $uuid, array $components, ?string $slot = NULL): Section {
    $section = new Section($layout_id, [], $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    if ($slot !== NULL) {
      $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    }
    return $section;
  }

  /**
   * A plugin block component with a visible label naming it.
   */
  protected function pluginBlock(string $uuid, string $plugin_id, string $label, array $configuration = []): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => $plugin_id,
      'label' => $label,
      'label_display' => 'visible',
    ] + $configuration);
  }

  /**
   * A field block presenting the subject's name.
   */
  protected function fieldBlock(string $uuid, string $bundle): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => "field_block:$bundle:$bundle:name",
      'label_display' => '0',
      'formatter' => [
        'type' => 'string',
        'label' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
      'context_mapping' => ['entity' => 'layout_builder.entity'],
    ]);
  }

  /**
   * A slot block component, with an optional default blueprint.
   */
  protected function slotBlock(string $uuid, string $slot, ?string $default = NULL): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':' . $slot,
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => $default,
    ]);
  }

  /**
   * Saves the theme's frame.
   *
   * Chrome, title, messages, the header linked, the main slot, the hero
   * slot and a container holding a block.
   *
   * @param bool $messages
   *   Whether the frame places the messages block.
   * @param bool $main
   *   Whether the frame places the main slot.
   * @param string|null $hero_default
   *   The hero slot's default blueprint, or NULL for none.
   * @param bool $linked
   *   Whether the frame links the header blueprint (a second showing of
   *   it beside a hero door defaulting to it).
   */
  protected function createFrame(bool $messages = TRUE, bool $main = TRUE, ?string $hero_default = NULL, bool $linked = TRUE): FrameInterface {
    $components = [
      $this->pluginBlock(self::CHROME_BLOCK, 'system_powered_by_block', 'Chrome sentinel'),
      $this->pluginBlock(self::TITLE_BLOCK, 'page_title_block', 'Title block', ['label_display' => '0']),
      $this->slotBlock(self::HERO_SLOT, 'hero', $hero_default),
      SectionBlock::createComponent(
        $this->section('layout_onecol', self::CONTAINER, [
          $this->pluginBlock(self::NESTED_BLOCK, 'system_powered_by_block', 'Nested sentinel'),
        ]),
        'content',
      ),
    ];
    if ($linked) {
      $components[] = new SectionComponent(self::LINKED_BLOCK, 'content', [
        'id' => LinkedBlueprintBlock::pluginId('header'),
        'label_display' => '0',
        'context_mapping' => LinkedBlueprintBlock::CONTEXT_MAPPING,
      ]);
    }
    if ($messages) {
      $components[] = $this->pluginBlock(self::MESSAGES_BLOCK, 'system_messages_block', 'Messages', ['label_display' => '0']);
    }
    if ($main) {
      $components[] = $this->slotBlock(self::MAIN_SLOT, 'main');
    }
    foreach ($components as $weight => $component) {
      $component->setWeight($weight);
    }
    $frame = Frame::create(['id' => self::THEME]);
    $frame->appendSection($this->section('layout_onecol', self::FRAME_SECTION, $components));
    $frame->save();
    return $frame;
  }

  /**
   * A page of the locked bundle, filling the frame's hero slot or not.
   */
  protected function lockedPage(string $name, bool $fills_hero): EntityTest {
    $values = ['name' => $name];
    if ($fills_hero) {
      $values[ContentHost::LAYOUT_FIELD] = [
        $this->section('layout_onecol', self::PAGE_HERO_FILL, [
          $this->pluginBlock(self::PAGE_HERO_BLOCK, 'system_powered_by_block', 'Page hero sentinel'),
        ], 'hero'),
      ];
    }
    $page = EntityTest::create($values);
    $page->save();
    return $page;
  }

  /**
   * The frame's host.
   */
  protected function frameHost(): HostInterface {
    $frame = $this->container->get('entity_type.manager')->getStorage(FrameInterface::ENTITY_TYPE_ID)->loadUnchanged(self::THEME);
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($frame);
  }

  /**
   * Dispatches the real selection event for a route, from core's default.
   */
  protected function select(Route $route, array $parameters = [], array $raw = []): PageDisplayVariantSelectionEvent {
    $event = new PageDisplayVariantSelectionEvent('simple_page', new RouteMatch('test', $route, $parameters, $raw));
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    return $event;
  }

  /**
   * Builds the frame variant around a main content and title and renders it.
   *
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The contexts the selection would have handed over.
   * @param array|null $main_content
   *   The route's output, or NULL for a markup sentinel (a page's view when
   *   the page's own document, its locked template, must render in the
   *   frame's main slot).
   *
   * @return array{string, array}
   *   The HTML and the variant's build.
   */
  protected function renderVariant(array $contexts = [], ?array $main_content = NULL): array {
    $variant = $this->container->get('plugin.manager.display_variant')->createInstance(DaedalusPageVariant::ID, [DaedalusPageVariant::FRAME_KEY => self::THEME]);
    $variant->setMainContent($main_content ?? ['#markup' => 'Main content sentinel'])->setTitle('Title sentinel')->setContexts($contexts);
    $build = $variant->build();
    $rendered = $build;
    $html = (string) $this->container->get('renderer')->renderRoot($rendered);
    return [$html, $build];
  }

  /**
   * An XPath over rendered HTML.
   */
  protected function domXpath(string $html): \DOMXPath {
    $document = new \DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
    return new \DOMXPath($document);
  }

  /**
   * The frame variant is selected for a framed theme on a front-end route only.
   */
  public function testSelection(): void {
    $route = new Route('/entity_test/{entity_test}');
    $this->assertSame(FramePageVariant::BLOCK_VARIANT, $this->select($route)->getPluginId(), 'A theme without a frame keeps block layout.');

    $this->createFrame();
    $page = $this->lockedPage('Route sentinel', FALSE);
    $event = $this->select($route, ['entity_test' => $page], ['entity_test' => $page->id()]);
    $this->assertSame(DaedalusPageVariant::ID, $event->getPluginId(), 'A framed theme renders the frame.');
    $this->assertSame([DaedalusPageVariant::FRAME_KEY => self::THEME], $event->getPluginConfiguration(), 'The variant is told whose frame.');
    $this->assertSame($page->uuid(), $event->getContexts()['entity']->getContextValue()->uuid(), "The route's entity is the variant's subject.");
    $this->assertContains('theme', $event->getCacheContexts());
    $this->assertContains('config:daedalus_blueprint.frame.' . self::THEME, $event->getCacheTags(), 'The selection depends on the frame.');

    $event = $this->select(new Route('/user/login'));
    $this->assertSame(DaedalusPageVariant::ID, $event->getPluginId(), 'A route without an entity is framed too.');
    $this->assertArrayNotHasKey('entity', $event->getContexts(), 'With no subject.');

    $admin = new Route('/admin/structure/x', [], [], ['_admin_route' => TRUE]);
    $this->assertSame(FramePageVariant::BLOCK_VARIANT, $this->select($admin)->getPluginId(), 'An administration route is never framed.');
    $bare = new Route('/peek', [], [], [Chromeless::BARE_ROUTE_OPTION => TRUE]);
    $this->assertSame('simple_page', $this->select($bare)->getPluginId(), 'A chromeless route (daedalus_ui took it over after the block module) keeps its variant.');
  }

  /**
   * The frame renders around an entity route: main, title, messages, header, slot.
   */
  public function testFrameRendersAroundAnEntityRoute(): void {
    $this->createFrame();
    $host = $this->frameHost();
    $host->setStyleMap([
      'container:' . self::FRAME_SECTION => ['default' => ['padding' => '2rem']],
      'entity:' . $host->uuid() => ['default' => ['padding' => '3rem']],
    ]);
    $host->entity()->save();
    $page = $this->lockedPage('Page sentinel', TRUE);
    $this->container->get('messenger')->addStatus('Message sentinel');

    [$html, $build] = $this->renderVariant(['entity' => EntityContext::fromEntity($page)]);
    $xpath = $this->domXpath($html);
    $this->assertArrayNotHasKey('messages', $build['content'], 'The frame places the messages block: no fallback.');
    $this->assertArrayNotHasKey('main', $build['content'], 'The frame places the main slot: no fallback.');

    $roots = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " daedalus-layout-layout ")]');
    $this->assertSame(1, $roots->length, "The frame's sections render inside one layout root: $html");
    $root = $roots->item(0);
    $this->assertSame('entity:' . $host->uuid(), $root->getAttribute('data-selectable'), "The layout root is the frame document's root.");
    $section = $xpath->query('.//*[@data-selectable="container:' . self::FRAME_SECTION . '"]', $root);
    $this->assertSame(1, $section->length, "The frame's section keeps its identity: $html");
    $this->assertSame('frame', $section->item(0)->getAttribute('data-layout-builder-section-storage-type'), 'As a root section of the frame storage.');
    $this->assertSame(self::THEME, $section->item(0)->getAttribute('data-layout-builder-section-storage-id'));
    $this->assertSame('0', $section->item(0)->getAttribute('data-layout-builder-section-delta'));

    $main = $xpath->query('.//main[@role="main"]', $root);
    $this->assertSame(1, $main->length, "The main slot is the page's main landmark: $html");
    $this->assertStringContainsString('Main content sentinel', $main->item(0)->textContent);
    $this->assertSame(1, $xpath->query('.//a[@id="main-content"]', $main->item(0))->length, 'With the skip link target.');
    $this->assertSame(1, $xpath->query('.//h1[contains(text(), "Title sentinel")]', $root)->length, "The placed title block shows the route's title: $html");
    $this->assertStringContainsString('Message sentinel', $html, 'The placed messages block shows the messages.');
    $this->assertStringContainsString('Chrome sentinel', $html, "The frame's own block renders.");
    $this->assertStringContainsString('Nested sentinel', $html, "A container's block renders.");

    $linked = $xpath->query('.//*[@data-selectable="block:' . self::LINKED_BLOCK . '"]', $root);
    $this->assertSame(1, $linked->length, "The linked header renders: $html");
    $this->assertStringContainsString('Page sentinel', $linked->item(0)->textContent, "The header's field block shows the route's entity's own field.");
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . self::BLUEPRINT_SECTION . '"]', $linked->item(0))->length, "The blueprint's section keeps its identity inside the frame.");

    $hero = $xpath->query('.//*[@data-selectable="block:' . self::HERO_SLOT . '"]', $root);
    $this->assertSame(1, $hero->length, "The hero slot renders: $html");
    $this->assertStringContainsString('Page hero sentinel', $hero->item(0)->textContent, "The page's own fill of the frame's slot renders in it.");
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . self::PAGE_HERO_FILL . '"]', $hero->item(0))->length, "With the page's identity.");

    $styles = $xpath->query('.//style', $root);
    $this->assertGreaterThanOrEqual(1, $styles->length, "The frame's styles deliver inside its layout root: $html");
    $css = '';
    foreach ($styles as $style) {
      $css .= $style->textContent;
    }
    $this->assertStringContainsString('2rem', $css, "The frame's map is what delivers.");
    $this->assertStringContainsString('container:' . self::FRAME_SECTION, $css, 'Scoped to the frame section.');
  }

  /**
   * The frame slot's filler is read beneath the frame, template included.
   */
  public function testFrameSlotFillers(): void {
    $this->createFrame();
    $frame = $this->frameHost();
    $inner_render = $this->container->get('daedalus_blueprint.inner_render');

    $this->assertNull($inner_render->slotFiller('hero', $frame, NULL), 'No subject, nothing fills.');
    $empty = $this->lockedPage('Empty sentinel', FALSE);
    $this->assertNull($inner_render->slotFiller('hero', $frame, $empty), 'A page filling nothing, a template filling nothing.');

    // The template's own fill of a frame slot is read when the page has
    // none.
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->appendSection($this->section('layout_onecol', self::TEMPLATE_HERO_FILL, [
      $this->pluginBlock(self::TEMPLATE_HERO_BLOCK, 'system_powered_by_block', 'Template hero sentinel'),
    ], 'hero'))->save();
    $fill = $inner_render->slotFiller('hero', $frame, $empty);
    $this->assertNotNull($fill, 'The template fills the frame slot.');
    $this->assertSame('entity_view_display', $fill->host->entityTypeId());
    $this->assertSame(self::TEMPLATE_HERO_FILL, $fill->section->getThirdPartySetting('daedalus_layout', 'uuid'));

    // The page's own fill wins over the template's: the innermost.
    $filled = $this->lockedPage('Filled sentinel', TRUE);
    $fill = $inner_render->slotFiller('hero', $frame, $filled);
    $this->assertSame('entity_test', $fill->host->entityTypeId());
    $this->assertSame(self::PAGE_HERO_FILL, $fill->section->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * A route without an entity renders the frame with no subject.
   */
  public function testFrameRendersAroundRouteWithoutEntity(): void {
    $this->createFrame();
    $this->lockedPage('Page sentinel', TRUE);
    [$html] = $this->renderVariant();
    $xpath = $this->domXpath($html);
    $this->assertStringContainsString('Main content sentinel', $xpath->query('//main[@role="main"]')->item(0)->textContent, "The main slot shows the route's output: $html");
    $this->assertStringContainsString('Title sentinel', $html);
    $this->assertStringContainsString('Chrome sentinel', $html);
    $this->assertStringContainsString('Nested sentinel', $html, 'A container renders with no subject.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="block:' . self::LINKED_BLOCK . '"]')->length, 'The linked header renders.');
    $this->assertStringNotContainsString('Page sentinel', $html, "Its field block, with nothing to present, renders nothing: $html");
    $this->assertStringNotContainsString('Page hero sentinel', $html, 'Nothing beneath the frame fills its slot.');
  }

  /**
   * Without a messages block or a main slot the frame still shows both.
   */
  public function testFallbacksForFrameMissingPageEssentials(): void {
    $this->createFrame(messages: FALSE, main: FALSE);
    $this->container->get('messenger')->addStatus('Message sentinel');
    [$html, $build] = $this->renderVariant();
    $this->assertSame('status_messages', $build['content']['messages']['#type'], 'Status messages are prepended to the body.');
    $this->assertSame(-1000, $build['content']['messages']['#weight']);
    $this->assertStringContainsString('Message sentinel', $html);
    $this->assertSame(1000, $build['content']['main']['#weight'], 'The main content shows after the frame.');
    $this->assertStringContainsString('Main content sentinel', $html);
    $this->assertSame(0, $this->domXpath($html)->query('//main')->length, 'The fallback is bare, as the block module\'s: the landmark is the main slot\'s.');
  }

  /**
   * A frame placing a main slot the roster no longer names falls back too.
   *
   * The undeclared twin of the frame that places no main slot: a roster
   * write core never validates (a bare config save, drush config:set)
   * drops main, the placed slot block's derivative is gone and core's
   * broken fallback renders nothing for a visitor, so the main content
   * must show after the frame as it does for a frame that never placed
   * one. The adapter counts only the slots whose plugin exists, so every
   * caller of declaredSlots() sees the live doors alone: the hero slot
   * the roster keeps still shows the page's fill. Rendered as a visitor:
   * the swallow is theirs (an administrator sees core's broken-block
   * message where the chrome was), and core's broken block, built
   * without its base configuration, trips the user module's block
   * preprocess whenever it renders.
   */
  public function testFrameWithUnrosteredMainSlotFallsBack(): void {
    $this->createFrame();
    $page = $this->lockedPage('Page sentinel', TRUE);
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, ['hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME]])
      ->save();
    $this->assertFalse($this->container->get('plugin.manager.block')->hasDefinition(SlotBlock::PLUGIN_ID . ':main'), 'The dropped id has no slot block any more.');
    $frame = $this->frameHost();
    $this->assertSame(['hero'], $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->declaredSlots($frame->ownSections()), 'The frame declares only the slots that exist.');

    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    [$html, $build] = $this->renderVariant(['entity' => EntityContext::fromEntity($page)]);
    $this->assertSame(1000, $build['content']['main']['#weight'], 'The main content shows after the frame.');
    $this->assertStringContainsString('Main content sentinel', $html);
    $this->assertSame(0, $this->domXpath($html)->query('//main')->length, "The unrostered slot prints no landmark: the fallback is bare: $html");
    $this->assertStringContainsString('Page hero sentinel', $html, "The rostered hero slot still shows the page's fill: $html");
    $this->assertStringContainsString('Chrome sentinel', $html, 'The frame still renders around the fallback.');
  }

  /**
   * A visitor sees the frame's styles with their keys stamped by demand.
   */
  public function testVisitorStamping(): void {
    $this->createFrame();
    $host = $this->frameHost();
    $host->setStyleMap(['container:' . self::FRAME_SECTION => ['default' => ['padding' => '2rem']]]);
    $host->entity()->save();
    $page = $this->lockedPage('Page sentinel', TRUE);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());

    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($page)]);
    $xpath = $this->domXpath($html);
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid]')->length, "No editor attribution for a visitor: $html");
    $this->assertSame(1, $xpath->query('//*[@data-selectable="container:' . self::FRAME_SECTION . '"]')->length, "The styled section's key is stamped for the visitor, the frame being the demand host: $html");
    $this->assertSame(1, $xpath->query('//*[@data-selectable="container:' . self::CONTAINER . '"]')->length, "The frame's nested container is stamped too: a styled container's boundary (stylish's donut rule).");
    $this->assertSame(0, $xpath->query('//*[@data-selectable="container:' . self::BLUEPRINT_SECTION . '"]')->length, "The linked header's section is not: its demand host is the blueprint, whose map is empty: $html");
    $this->assertStringContainsString('2rem', $html, 'The styles deliver.');
    $this->assertStringContainsString('Main content sentinel', $html);
  }

  /**
   * The frame offers the page blocks and refuses the main content block.
   */
  public function testFrameOffer(): void {
    $this->createFrame();
    $restrictions = $this->container->get('daedalus_blueprint.placement_restrictions');
    $frame_storage = $this->frameHost()->sectionStorage();
    $offered = array_keys($restrictions->allowedBlockDefinitions($frame_storage));
    $expected = [
      'page_title_block',
      'system_messages_block',
      'local_tasks_block',
      'local_actions_block',
      SlotBlock::PLUGIN_ID . ':main',
      LinkedBlueprintBlock::pluginId('header'),
    ];
    foreach ($expected as $plugin_id) {
      $this->assertContains($plugin_id, $offered, "The frame offers $plugin_id.");
    }
    $this->assertNotContains('system_main_block', $offered, 'The main content is the main slot, never a block.');
    $this->assertFalse($restrictions->blockAllowed('system_main_block', $frame_storage));

    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $template_storage = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->sectionStorage();
    $on_template = array_keys($restrictions->allowedBlockDefinitions($template_storage));
    $this->assertNotContains('page_title_block', $on_template, "A template is not a page: core's filter stands.");
    $this->assertNotContains('system_main_block', $on_template);
    $this->assertNotContains(SlotBlock::PLUGIN_ID . ':hero', $on_template, 'A frame-tier slot is the frame\'s to place: a template is offered the template tier alone.');
  }

  /**
   * The frame's nodes on a page: foreign, with the portal the verdict offers.
   *
   * The site builder sees the frame canvas's door on the frame's own
   * blocks and the blueprint canvas's door on the linked header; the main
   * slot, which holds the page itself, wears none; an editor who may open
   * neither sees no door at all. The locked page's template is the same
   * kind of fact: the template's own block is a portal to the template
   * canvas, while the template's field block presenting the page's own
   * field is the page's to edit (content, the one capability the lock
   * keeps open) and wears none. The spine's answer and the DOM stamp are
   * one fact.
   */
  public function testFrameNodesPortalFromPage(): void {
    $this->createFrame();
    $frame = $this->frameHost();
    $page = $this->lockedPage('Page sentinel', TRUE);
    $page_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($page);
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');

    // The spine: a frame node seen from the page's canvas.
    $verdict = $stamper->verdictsOn($page_host, $frame, self::CHROME_BLOCK);
    $this->assertSame(array_fill_keys(['content', 'configure', 'style', 'arrange', 'insert'], FALSE), $verdict['can'], 'Nothing of the frame is the page\'s to do.');
    $this->assertCount(1, $verdict['refused']);
    $this->assertSame('FOREIGN_DOCUMENT', $verdict['refused'][0]['code']);
    $this->assertSame(['entity_type' => FrameInterface::ENTITY_TYPE_ID, 'entity_id' => self::THEME], $verdict['refused'][0]['context']['owner']);
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $verdict['refused'][0]['context']['portal']['entity_type'], 'The admin may configure the frame: the portal is the frame.');
    $linked = $stamper->verdictsOn($page_host, $frame, self::LINKED_BLOCK);
    $this->assertSame('blueprint', $linked['refused'][0]['context']['portal']['entity_type'], 'The linked header\'s portal is the blueprint it shows.');
    $this->assertSame((string) $this->blueprint->id(), $linked['refused'][0]['context']['portal']['entity_id']);
    $own = $stamper->verdictsOn($page_host, $page_host, self::PAGE_HERO_BLOCK);
    $this->assertTrue($own['can']['configure'], 'A node of the page\'s own document is judged by the resolver as before.');

    // The DOM, on the page's canonical route in Edit Mode, the page's own
    // view (its locked template) as the route's output.
    $view = $this->container->get('entity_type.manager')->getViewBuilder('entity_test');
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $page], 'edit');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($page)], $view->view($page, 'default'));
    $xpath = $this->domXpath($html);
    $frame_url = $frame->entity()->toUrl()->toString();
    $this->assertSame('/site-frame/' . self::THEME, $frame_url);
    $chrome = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::CHROME_BLOCK . '"]')->item(0);
    $this->assertSame($frame_url, $chrome->getAttribute('data-daedalus-ui-portal-url'), "The frame's block is a portal to the frame canvas: $html");
    $this->assertSame('Stark', $chrome->getAttribute('data-daedalus-ui-portal-label'), 'Labelled by the theme.');
    $this->assertSame('Site frame', $chrome->getAttribute('data-daedalus-ui-portal-type'), 'Typed by what the frame is, the word the chip reads.');
    $this->assertSame($frame_url, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TITLE_BLOCK . '"]')->item(0)->getAttribute('data-daedalus-ui-portal-url'));
    $linked_root = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::LINKED_BLOCK . '"]')->item(0);
    $this->assertSame($this->blueprint->toUrl()->toString(), $linked_root->getAttribute('data-daedalus-ui-portal-url'), 'The linked header is a portal to the blueprint canvas.');
    $this->assertSame('Header', $linked_root->getAttribute('data-daedalus-ui-portal-label'));
    $this->assertSame('Blueprint', $linked_root->getAttribute('data-daedalus-ui-portal-type'));
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::BLUEPRINT_FIELD_BLOCK . '"][@data-daedalus-ui-portal-url]')->length, 'A node inside the linked blueprint wears none: the placement root is the portal.');
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::MAIN_SLOT . '"][@data-daedalus-ui-portal-url]')->length, 'The main slot holds the page itself: no portal.');
    $hero = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]')->item(0);
    $this->assertSame($frame_url, $hero->getAttribute('data-daedalus-ui-portal-url'), 'A frame slot is the frame\'s door; the page\'s fill inside it is the page\'s (the client sets the portal aside over it).');
    $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::PAGE_HERO_BLOCK . '"][@data-daedalus-ui-portal-url]')->length, 'The page\'s own block in the frame\'s slot wears none.');

    // The template's nodes on the locked page. The spine: the template's
    // own block is refused everything, its field block presenting the
    // page's own field keeps content; the portal rides the refusal.
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $template = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $own_block = $stamper->verdictsOn($page_host, $template, self::TEMPLATE_BLOCK);
    $this->assertFalse($own_block['can']['content'], 'The template\'s own block is the template\'s to edit.');
    $this->assertSame('FOREIGN_DOCUMENT', $own_block['refused'][0]['code']);
    $this->assertContains('content', $own_block['refused'][0]['capabilities']);
    $this->assertSame($display->getEntityTypeId(), $own_block['refused'][0]['context']['portal']['entity_type'], 'The portal is the template.');
    $field = $stamper->verdictsOn($page_host, $template, self::TEMPLATE_FIELD_BLOCK);
    $this->assertTrue($field['can']['content'], 'The page\'s own field, presented by the template\'s block: its content is the page\'s to edit.');
    $this->assertFalse($field['can']['configure'], 'Everything else about the block is the template\'s.');
    $this->assertCount(1, $field['refused']);
    $this->assertSame('FOREIGN_DOCUMENT', $field['refused'][0]['code']);
    $this->assertNotContains('content', $field['refused'][0]['capabilities']);
    $this->assertSame($display->getEntityTypeId(), $field['refused'][0]['context']['portal']['entity_type'], 'The structural refusal still names the template as its portal.');
    // The DOM: the portal is the consequence of the content verdict alone.
    $template_url = $this->container->get('daedalus_ui.ui')->getEditingUrl($display)->toString();
    $this->assertStringEndsWith('/display/default/layout', $template_url, 'The template is edited on the manage-display layout route.');
    $template_block = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK . '"]')->item(0);
    $this->assertSame($template_url, $template_block->getAttribute('data-daedalus-ui-portal-url'), "The template's own block is a portal to the template canvas: $html");
    $this->assertSame((string) $display->label(), $template_block->getAttribute('data-daedalus-ui-portal-label'));
    $this->assertSame('Template', $template_block->getAttribute('data-daedalus-ui-portal-type'));
    $field_block = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::TEMPLATE_FIELD_BLOCK . '"]')->item(0);
    $this->assertNotNull($field_block, "The template's field block renders on the page: $html");
    $this->assertFalse($field_block->hasAttribute('data-daedalus-ui-portal-url'), "The page's own field, presented by the template's block: its content is the page's to edit, so no portal: $html");
    $this->assertFalse($field_block->hasAttribute('data-daedalus-ui-portal-label'));

    // An editor who may open neither the frame nor the blueprint.
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', 'view test entity', 'administer entity_test content']);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $page], 'edit');
    $verdict = $stamper->verdictsOn($page_host, $frame, self::CHROME_BLOCK);
    $this->assertArrayNotHasKey('portal', $verdict['refused'][0]['context'], 'No door for an actor who may not configure the frame.');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($page)], $view->view($page, 'default'));
    $this->assertStringNotContainsString('data-daedalus-ui-portal-url', $html, "No portal stamp for an editor who may open nothing: $html");
    $this->assertStringContainsString('data-layout-builder-block-uuid="' . self::TEMPLATE_BLOCK . '"', $html, "The template's block renders for them, without a door to a template they may not open.");
    $this->assertStringContainsString('data-layout-builder-block-uuid="' . self::CHROME_BLOCK . '"', $html, 'The editing attributes still stamp.');

    // A route with no host (a view page) inside the frame: the frame's
    // nodes are still foreign documents with their doors.
    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute('view.frontpage.page_1', [], 'edit');
    $this->assertNull($this->container->get('daedalus_ui.ui')->deriveHostFromRoute(), 'No route host.');
    [$html] = $this->renderVariant();
    $xpath = $this->domXpath($html);
    $this->assertSame($frame_url, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::CHROME_BLOCK . '"]')->item(0)->getAttribute('data-daedalus-ui-portal-url'), "The frame's door on a host-less route: $html");
    $this->assertSame($this->blueprint->toUrl()->toString(), $xpath->query('//*[@data-layout-builder-block-uuid="' . self::LINKED_BLOCK . '"]')->item(0)->getAttribute('data-daedalus-ui-portal-url'));
  }

  /**
   * A frame slot showing its default is claimable from the page beneath it.
   *
   * The door wears the claim stamp (the default's machine name) exactly
   * when the page may fill the slot and nothing fills it yet, with the
   * blueprint's portal for an actor who may update it and the blueprint's
   * label alone for one who may not (the chip needs a name either way); a
   * page filling the slot wears the frame's portal and no claim; a route
   * with no host claims nothing.
   */
  public function testClaimableFrameDoorStampsFromPage(): void {
    $this->createFrame(hero_default: 'header');
    $frame = $this->frameHost();
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');
    $empty = $this->lockedPage('Empty sentinel', FALSE);
    $filled = $this->lockedPage('Filled sentinel', TRUE);
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');

    // The spine: insert is the page's, the content the blueprint's.
    $verdict = $stamper->verdictsOn($resolver->fromEntity($empty), $frame, self::HERO_SLOT);
    $this->assertSame(['content' => FALSE, 'configure' => FALSE, 'style' => FALSE, 'arrange' => FALSE, 'insert' => TRUE], $verdict['can']);
    $this->assertSame('blueprint', $verdict['refused'][0]['context']['portal']['entity_type']);
    $this->assertSame(['content', 'configure', 'style', 'arrange'], $verdict['refused'][0]['capabilities']);
    $verdict = $stamper->verdictsOn($resolver->fromEntity($filled), $frame, self::HERO_SLOT);
    $this->assertTrue($verdict['can']['insert']);
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $verdict['refused'][0]['context']['portal']['entity_type'], 'A filled slot shows the page\'s own: the door is the frame\'s.');

    // The DOM, as the admin.
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $empty], 'edit');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($empty)]);
    $hero = $this->domXpath($html)->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]')->item(0);
    $this->assertSame('header', $hero->getAttribute(SlotBlock::CLAIM_ATTRIBUTE), "The door is claimable: $html");
    $this->assertSame('hero', $hero->getAttribute(SlotBlock::DOOR_ATTRIBUTE));
    $this->assertSame($this->blueprint->toUrl()->toString(), $hero->getAttribute('data-daedalus-ui-portal-url'), 'The portal is the blueprint the door shows.');
    $this->assertSame('Header', $hero->getAttribute('data-daedalus-ui-portal-label'));
    $this->assertSame('Blueprint', $hero->getAttribute('data-daedalus-ui-portal-type'), 'The door shows a blueprint by reference: the chip reads "Header · blueprint".');
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $filled], 'edit');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($filled)]);
    $hero = $this->domXpath($html)->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]')->item(0);
    $this->assertFalse($hero->hasAttribute(SlotBlock::CLAIM_ATTRIBUTE), "A filled slot offers no claim: $html");
    $this->assertSame($frame->entity()->toUrl()->toString(), $hero->getAttribute('data-daedalus-ui-portal-url'));
    $this->assertSame('Site frame', $hero->getAttribute('data-daedalus-ui-portal-type'), 'A filled door is the frame\'s node: the frame\'s kind.');

    // An editor who may fill the page's slots but update neither the
    // blueprint nor the frame: the claim, the label, no destination.
    $this->setUpCurrentUser([], [
      'use daedalus ui edit mode',
      'view test entity',
      'administer entity_test content',
      'configure any layout',
    ]);
    $this->setCurrentRoute('entity.entity_test.canonical', ['entity_test' => $empty], 'edit');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($empty)]);
    $hero = $this->domXpath($html)->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]')->item(0);
    $this->assertSame('header', $hero->getAttribute(SlotBlock::CLAIM_ATTRIBUTE), "The editor may claim: $html");
    $this->assertFalse($hero->hasAttribute('data-daedalus-ui-portal-url'), 'No door to the blueprint for them.');
    $this->assertFalse($hero->hasAttribute('data-daedalus-ui-portal-type'), 'The kind rides the portal alone.');
    $this->assertSame('Header', $hero->getAttribute('data-daedalus-ui-portal-label'), 'The chip still has the name.');

    // A route with no host: nothing beneath the frame claims.
    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute('view.frontpage.page_1', [], 'edit');
    [$html] = $this->renderVariant();
    $hero = $this->domXpath($html)->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]')->item(0);
    $this->assertFalse($hero->hasAttribute(SlotBlock::CLAIM_ATTRIBUTE), "No claim on a host-less route: $html");
    $this->assertSame($this->blueprint->toUrl()->toString(), $hero->getAttribute('data-daedalus-ui-portal-url'), 'The default still shows, with its portal.');
  }

  /**
   * The blueprint canvas: a blueprint's canonical route is framed.
   *
   * The route is a front-end route whose host is the blueprint, and the
   * variant frames it like any other, the blueprint entity as the subject
   * (the route host's own); only the frame's own canvas is left to the
   * block module (testFrameCanvas). With no frame door defaulting to the
   * blueprint its canvas lands in main, as a page does; the door case is
   * testBlueprintCanvasRendersInItsDoor.
   */
  public function testBlueprintCanvas(): void {
    $this->createFrame();
    $ui = $this->container->get('daedalus_ui.ui');
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.blueprint.canonical');
    $this->assertSame('/blueprint/{blueprint}', $route->getPath());
    $this->assertFalse($this->container->get('router.admin_context')->isAdminRoute($route), 'The canvas is a front-end route: the blueprint in the subject\'s skin.');
    $route_match = new RouteMatch('entity.blueprint.canonical', $route, ['blueprint' => $this->blueprint], ['blueprint' => $this->blueprint->id()]);
    $this->assertInstanceOf(BlueprintHost::class, $ui->deriveHostFromRoute($route_match), 'The blueprint is the route host of its canvas.');

    $event = new PageDisplayVariantSelectionEvent(FramePageVariant::BLOCK_VARIANT, $route_match);
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    $this->assertSame(DaedalusPageVariant::ID, $event->getPluginId(), 'The blueprint canvas is framed.');
    $this->assertSame($this->blueprint->uuid(), $event->getContexts()['entity']->getContextValue()->uuid(), 'The blueprint entity is the subject the variant is handed.');

    $frame = $this->frameHost();
    $blueprint_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->blueprint);
    $output = new RouteOutput(['#markup' => 'Canvas sentinel'], 'Header', $blueprint_host, MainContentStandIn::build());
    $this->assertSame(FrameHost::MAIN_SLOT, $this->container->get('daedalus_blueprint.inner_render')->routeSlot($frame, $output->host), 'No door defaults to the header: its canvas lands in main.');
    $this->setCurrentRoute('entity.blueprint.canonical', ['blueprint' => $this->blueprint], 'edit');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($this->blueprint)], ['#markup' => 'Canvas sentinel']);
    $xpath = $this->domXpath($html);
    $main = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::MAIN_SLOT . '"]//main[@role="main"]');
    $this->assertSame(1, $main->length, "Main prints the main landmark: $html");
    $this->assertStringContainsString('Canvas sentinel', $main->item(0)->textContent, 'With the canvas inside.');
    $this->assertStringNotContainsString("Each page's content shows here.", $html, 'No stand-in: main hosts the route.');
    $this->assertStringContainsString('Chrome sentinel', $html, 'The frame renders around the canvas.');
  }

  /**
   * The blueprint canvas renders in situ: the door defaulting to it hosts it.
   *
   * The spine: the frame-tier door whose default is the route host's
   * blueprint is the slot hosting the route output
   * (InnerRender::routeSlot()); a page's route and a host-less route land
   * in main. The render, on the header's canonical route in Edit Mode with
   * the header's view (its canvas) as the route output: the hero door
   * prints the one main landmark holding the canvas root, the header's
   * section renders once (the canvas, no door copy beside it), main prints
   * the stand-in with no landmark of its own, and the door hosting the
   * canvas wears no portal (it holds the page itself, as main does on a
   * page) while the frame's other nodes keep theirs.
   */
  public function testBlueprintCanvasRendersInItsDoor(): void {
    $this->createFrame(hero_default: 'header', linked: FALSE);
    $frame = $this->frameHost();
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $inner_render = $this->container->get('daedalus_blueprint.inner_render');
    $page = $this->lockedPage('Page sentinel', FALSE);
    $this->assertSame('hero', $inner_render->routeSlot($frame, $resolver->fromEntity($this->blueprint)), "The door defaulting to the header hosts the header's canvas.");
    $this->assertSame(FrameHost::MAIN_SLOT, $inner_render->routeSlot($frame, $resolver->fromEntity($page)), "A page's route lands in main.");
    $this->assertSame(FrameHost::MAIN_SLOT, $inner_render->routeSlot($frame, NULL), 'A host-less route lands in main.');

    $this->setCurrentRoute('entity.blueprint.canonical', ['blueprint' => $this->blueprint], 'edit');
    $view = $this->container->get('entity_type.manager')->getViewBuilder('blueprint')->view($this->blueprint, 'full');
    [$html] = $this->renderVariant(['entity' => EntityContext::fromEntity($this->blueprint)], $view);
    $xpath = $this->domXpath($html);
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-section-storage-type="frame"]')->length, "The frame's section renders once around the canvas: $html");
    $hero = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::HERO_SLOT . '"]');
    $this->assertSame(1, $hero->length, "One hero door: $html");
    $hero = $hero->item(0);
    $this->assertSame(1, $xpath->query('//main[@role="main"]')->length, "One main landmark on the page: $html");
    $this->assertSame(1, $xpath->query('.//main[@role="main"]', $hero)->length, 'The door prints it.');
    $this->assertSame(1, $xpath->query('.//*[@data-main-entity="true"]', $hero)->length, "The canvas root is inside the door: $html");
    $this->assertSame($this->blueprint->uuid(), $xpath->query('.//*[@data-main-entity="true"]', $hero)->item(0)->getAttribute('data-entity-uuid'), 'The blueprint is the canvas.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="container:' . self::BLUEPRINT_SECTION . '"]')->length, "The header's section renders once, as the canvas: $html");
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . self::BLUEPRINT_SECTION . '"]', $hero)->length, 'Inside the door.');
    $main = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::MAIN_SLOT . '"]')->item(0);
    $this->assertStringContainsString("Each page's content shows here.", $main->textContent, "Main prints the stand-in: $html");
    $this->assertSame(0, $xpath->query('.//main', $main)->length, 'With no landmark of its own.');
    $this->assertFalse($hero->hasAttribute('data-daedalus-ui-portal-url'), "The door hosting the canvas wears no portal: $html");
    $frame_url = $frame->entity()->toUrl()->toString();
    $this->assertSame($frame_url, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::CHROME_BLOCK . '"]')->item(0)->getAttribute('data-daedalus-ui-portal-url'), "The frame's own block is a portal to the frame canvas from the blueprint canvas: $html");
    $this->assertStringContainsString('Chrome sentinel', $html);
  }

  /**
   * The frame canvas: the frame's canonical route is where it is edited.
   *
   * The route host is the frame, Edit Mode applies for an actor who may
   * configure it, the canvas is the one canvas element over the frame
   * storage (the frame the main-entity root, the mirror the frame's, the
   * sections rendered under the frame render's contexts with the main
   * slot's stand-in and the linked header's door), the variant leaves the
   * route unframed, and the frame's theme renders it.
   */
  public function testFrameCanvas(): void {
    $frame_entity = $this->createFrame();
    $ui = $this->container->get('daedalus_ui.ui');
    $this->assertTrue($ui->hasEditingUrl($frame_entity), 'A frame has an editing surface: daedalus_layout\'s canvas route.');
    $this->assertSame('/site-frame/' . self::THEME, $ui->getEditingUrl($frame_entity)->toString());

    $route = $this->container->get('router.route_provider')->getRouteByName(FrameInterface::CANVAS_ROUTE);
    $this->assertSame('/site-frame/{daedalus_frame}', $route->getPath());
    $this->assertFalse($this->container->get('router.admin_context')->isAdminRoute($route), 'The canvas is a front-end route: the frame in its skin.');
    $route_match = new RouteMatch(FrameInterface::CANVAS_ROUTE, $route, ['daedalus_frame' => $frame_entity], ['daedalus_frame' => self::THEME]);
    $this->assertInstanceOf(FrameHost::class, $ui->deriveHostFromRoute($route_match), 'The frame is the route host of its canvas.');
    $negotiator = new FrameCanvasTheme($this->container->get('theme_handler'));
    $this->assertTrue($negotiator->applies($route_match));
    $this->assertSame(self::THEME, $negotiator->determineActiveTheme($route_match), 'The canvas renders in the frame\'s theme.');

    // The variant leaves the frame's own route to the block module: the
    // frame is the page's content there.
    $event = new PageDisplayVariantSelectionEvent(FramePageVariant::BLOCK_VARIANT, $route_match);
    $this->container->get('event_dispatcher')->dispatch($event, RenderEvents::SELECT_PAGE_DISPLAY_VARIANT);
    $this->assertSame(FramePageVariant::BLOCK_VARIANT, $event->getPluginId(), 'The frame canvas is never framed again.');

    // Edit Mode: the site builder's, not an editor's.
    $modes = $this->container->get('plugin.manager.modes');
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame_entity], 'edit');
    $this->assertTrue($modes->createInstance('edit')->applies(), 'Edit Mode applies on the frame canvas for an actor who may configure the frame.');
    $this->setUpCurrentUser([], ['use daedalus ui edit mode']);
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame_entity], 'edit');
    $this->assertFalse($modes->createInstance('edit')->applies(), 'Without the frame permission there is nothing to do on the frame: no Edit Mode.');
    $this->setUpCurrentUser([], ['use daedalus ui edit mode', FrameInterface::PERMISSION]);
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame_entity], 'edit');
    $this->assertTrue($modes->createInstance('edit')->applies(), 'The one frame permission opens the canvas.');

    // The canvas itself, as the route builds it and as the canvas-builder
    // socket (the mutation re-render, LoadEditablePage) builds it.
    $this->setUpCurrentUser([], [], TRUE);
    $this->setCurrentRoute(FrameInterface::CANVAS_ROUTE, ['daedalus_frame' => $frame_entity], 'edit');
    $frame = $this->frameHost();
    $builds = [
      'route' => FrameCanvas::create($this->container)->build($frame_entity),
      'socket' => $this->container->get('daedalus_blueprint_api.canvas_builder')->build($frame, 'default'),
    ];
    foreach ($builds as $road => $build) {
      $html = (string) $this->container->get('renderer')->renderInIsolation($build);
      $xpath = $this->domXpath($html);
      $wrappers = $xpath->query('//*[@data-main-entity="true"]');
      $this->assertSame(1, $wrappers->length, "The $road canvas has one main-entity root: $html");
      $wrapper = $wrappers->item(0);
      $this->assertSame('daedalus_frame::' . self::THEME . '::daedalus_frame', $wrapper->getAttribute('data-daedalus-ui-entity-wrapper'));
      $this->assertSame('entity:' . $frame->uuid(), $wrapper->getAttribute('data-selectable'));
      $this->assertSame('default', $wrapper->getAttribute('data-daedalus-ui-view-mode'));
      $this->assertSame(1, $xpath->query('//*[@id="layout-builder"][ancestor::*[@data-main-entity]]')->length, "The $road canvas renders the layout root inside the root wrapper: $html");
      $section = $xpath->query('//*[@data-selectable="container:' . self::FRAME_SECTION . '"]');
      $this->assertSame(1, $section->length, "The frame's section renders on the $road canvas: $html");
      $this->assertSame('frame', $section->item(0)->getAttribute('data-layout-builder-section-storage-type'));
      $this->assertSame(self::THEME, $section->item(0)->getAttribute('data-layout-builder-section-storage-id'));
      $main = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::MAIN_SLOT . '"]//main[@role="main"]');
      $this->assertSame(1, $main->length, "The main slot prints the main landmark on the $road canvas: $html");
      $this->assertStringContainsString("Each page's content shows here.", $main->item(0)->textContent, "With the stand-in for the route's output.");
      $this->assertStringContainsString('Chrome sentinel', $html);
      $linked = $xpath->query('//*[@data-layout-builder-block-uuid="' . self::LINKED_BLOCK . '"]');
      $this->assertSame(1, $linked->length, "The linked header renders on the $road canvas: $html");
      $this->assertSame($this->blueprint->toUrl()->toString(), $linked->item(0)->getAttribute('data-daedalus-ui-portal-url'), 'The linked header is a portal to the blueprint canvas from the frame canvas too.');
      $this->assertSame(0, $xpath->query('//*[@data-layout-builder-block-uuid="' . self::CHROME_BLOCK . '"][@data-daedalus-ui-portal-url]')->length, "The frame's own block is the canvas's own: no portal on the $road canvas.");
      $this->assertStringNotContainsString('Page sentinel', $html, "The frame renders with no subject on its canvas: a field block in the header shows nothing.");
      $envelope = json_decode($build['#attached']['drupalSettings']['daedalusUi']['blueprint'] ?? 'null', TRUE);
      $this->assertIsArray($envelope, "The blueprint mirror is delivered on the $road canvas.");
      $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $envelope['entityType']);
      $this->assertSame(self::THEME, $envelope['entityId']);
      $this->assertTrue($envelope['verdict']['can']['arrange'], 'The frame is the admin\'s to arrange on its canvas.');
    }
  }

}
