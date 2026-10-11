<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictions;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_ui\Render\BlockStandIn;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The slot block and the render primitive.
 *
 * A locked entity_test_rev bundle whose template holds one section with a
 * plugin block and a slot block (daedalus_slot:hero). A page of the bundle
 * fills the slot with a top-level section of its own tagged `hero`, and
 * the slot block renders that section inside the template's render with
 * the page as the subject, so a field block in the slot shows the page's
 * own name. A page filling nothing shows the slot's default (a published
 * blueprint, rendered the same way, under the page's contexts) or
 * nothing; on the editing canvas the empty slot wears daedalus_ui's block
 * stand-in labelled with the slot's label. The slot block derives per
 * roster id and is offered on template canvases only. A slot the site
 * frame declares is filled by the innermost document beneath it: the page
 * over its template over the slot's default, and the door of a slot
 * (InnerRender::slotDoor(), what a claim detaches) is the block declaring
 * it in the nearest document around the host.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(InnerRender::class)]
#[CoversClass(SlotBlock::class)]
#[CoversClass(PlacementRestrictions::class)]
#[Group('daedalus_blueprint')]
class SlotRenderTest extends BlueprintKernelTestBase {

  /**
   * The template section's uuid.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid (outside the slot).
   */
  protected string $templateBlock;

  /**
   * The template's slot block uuid.
   */
  protected string $slotBlock;

  /**
   * The template section, as saved on the locked display.
   */
  protected Section $template;

  /**
   * The render primitive.
   */
  protected InnerRender $innerRender;

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
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_blueprint_test',
      // The blueprint entity (a slot's default): its scope is a
      // list_string, its icon a media reference.
      'options',
      'image',
      'media',
      'daedalus_blueprint_library',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['block_content', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->createBlockContentType('basic', 'Basic');
    $this->setUpCurrentUser([], [], TRUE);
    $this->innerRender = $this->container->get('daedalus_blueprint.inner_render');

    // The overridable bundle: entity_test's pages own their whole document.
    $this->enableLayoutBuilder();

    // The locked bundle: Layout Builder on, overrides off, one template
    // section holding a plugin block and the hero slot.
    $uuids = new PhpUuid();
    $this->templateSection = $uuids->generate();
    $this->templateBlock = $uuids->generate();
    $this->slotBlock = $uuids->generate();
    $this->template = $this->createSection('eb_test_container', [
      [
        'uuid' => $this->templateBlock,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block', 'label_display' => '0'],
      ],
    ]);
    $this->template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($this->template)->save();
    // Field blocks derive per Layout Builder enabled bundle.
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    // The site's slots: the roster save rebuilds the block definitions.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $this->placeSlot();
  }

  /**
   * Places the hero slot block in the template, with an optional default.
   */
  protected function placeSlot(?string $default = NULL): void {
    $display = $this->lockedDisplay();
    $section = $display->getSection(0);
    if ($section->getComponents()[$this->slotBlock] ?? NULL) {
      $section->removeComponent($this->slotBlock);
    }
    $section->appendComponent(new SectionComponent($this->slotBlock, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':hero',
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => $default,
    ]));
    $display->save();
    $this->template = $this->lockedDisplay()->getSection(0);
  }

  /**
   * The locked bundle's display, loaded fresh.
   */
  protected function lockedDisplay(): LayoutBuilderEntityViewDisplay {
    return $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default');
  }

  /**
   * The page's own field block: its name, label hidden.
   */
  protected function nameBlock(string $uuid): array {
    return [
      'uuid' => $uuid,
      'configuration' => [
        'id' => 'field_block:entity_test_rev:entity_test_rev:name',
        'label_display' => '0',
        'formatter' => [
          'label' => 'hidden',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
          'third_party_settings' => [],
        ],
        'context_mapping' => ['entity' => 'layout_builder.entity'],
      ],
    ];
  }

  /**
   * A section of a host's own, tagged with a slot.
   */
  protected function ownSection(string $uuid, ?string $slot, array $components = []): Section {
    $section = $this->createSection('eb_test_container', $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    if ($slot !== NULL) {
      $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    }
    return $section;
  }

  /**
   * A locked page filling the hero slot with its name, or filling nothing.
   *
   * @return array{0: \Drupal\Core\Entity\FieldableEntityInterface, 1: string|null, 2: string|null}
   *   The page, its slot section's uuid and its field block's uuid.
   */
  protected function lockedPage(string $name, bool $filled): array {
    if (!$filled) {
      return [$this->createRevEntity(['name' => $name]), NULL, NULL];
    }
    $uuids = new PhpUuid();
    $section = $uuids->generate();
    $block = $uuids->generate();
    $page = $this->createRevEntity([
      'name' => $name,
      ContentHost::LAYOUT_FIELD => [$this->ownSection($section, 'hero', [$this->nameBlock($block)])],
    ]);
    return [$page, $section, $block];
  }

  /**
   * Renders a page's full view to markup.
   */
  protected function renderPage(FieldableEntityInterface $page): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test_rev')->view($page, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Renders the template's section on the editing canvas for a subject.
   */
  protected function renderCanvas(FieldableEntityInterface $subject): string {
    $build = $this->template->toRenderArray($this->contexts($subject), TRUE);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * The outer render's contexts for a subject.
   */
  protected function contexts(FieldableEntityInterface $subject): array {
    return [
      'layout_builder.entity' => EntityContext::fromEntity($subject),
      'view_mode' => new Context(new ContextDefinition('string'), 'full'),
    ];
  }

  /**
   * An XPath over markup.
   */
  protected function domXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * How many text nodes inside the layout root carry a sentinel.
   *
   * The entity_test_rev view prints its name field beside the layout too,
   * so a sentinel is looked for under the layout root alone.
   */
  protected function inLayout(string $html, string $text): int {
    return $this->domXpath($html)->query('//div[contains(@class, "daedalus-layout-layout")]//text()[contains(., "' . $text . '")]')->length;
  }

  /**
   * The slot block's element in a render, by its placement uuid.
   */
  protected function slotElement(\DOMXPath $xpath, string $html): \DOMElement {
    $nodes = $xpath->query('//*[@data-layout-builder-block-uuid="' . $this->slotBlock . '"]');
    $this->assertSame(1, $nodes->length, "Exactly one slot block in: $html");
    return $nodes->item(0);
  }

  /**
   * A published blueprint whose one section shows the subject's name.
   *
   * @return array{0: \Drupal\daedalus_blueprint_library\Entity\Blueprint, 1: string}
   *   The blueprint and its section's uuid.
   */
  protected function blueprint(string $machine_name, bool $published = TRUE): array {
    $section = (new PhpUuid())->generate();
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'description' => 'A default for the hero slot.',
      'scope' => 'block',
      'subject_type' => 'entity_test_rev',
      'subject_bundle' => 'entity_test_rev',
      'status' => $published ? 1 : 0,
      ContentHost::LAYOUT_FIELD => [$this->ownSection($section, NULL, [$this->nameBlock((new PhpUuid())->generate())])],
    ]);
    $blueprint->save();
    return [$blueprint, $section];
  }

  /**
   * The host whose document holds a template slot: the subject's render host.
   */
  protected function outerOf(FieldableEntityInterface $subject): HostInterface {
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    return $resolver->renderHost($resolver->fromEntity($subject));
  }

  /**
   * Installs stark as the active theme: the theme whose frame is under test.
   *
   * The theme install rebuilds the container, so the primitive is re-read
   * from the one whose theme manager knows the active theme.
   */
  protected function installFrameTheme(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $this->innerRender = $this->container->get('daedalus_blueprint.inner_render');
  }

  /**
   * A slot block component of the frame, with an optional default.
   */
  protected function frameDoor(string $uuid, string $slot, ?string $default = NULL): array {
    return [
      'uuid' => $uuid,
      'configuration' => [
        'id' => SlotBlock::PLUGIN_ID . ':' . $slot,
        'label' => ucfirst($slot),
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
        SlotBlock::DEFAULT_KEY => $default,
      ],
    ];
  }

  /**
   * Saves stark's frame: one section holding the components.
   */
  protected function saveFrame(array $components): FrameInterface {
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($this->createSection('eb_test_container', $components));
    $frame->save();
    return $frame;
  }

  /**
   * A filled slot renders the page's own section with the page in context.
   */
  public function testFilledSlotRendersThePageSection(): void {
    [$page, $section, $block] = $this->lockedPage('Page A sentinel', TRUE);

    // The primitive's answer: the page fills hero, nothing fills aside.
    $fill = $this->innerRender->slotFiller('hero', $this->outerOf($page), $page);
    $this->assertNotNull($fill);
    $this->assertSame([$page->getEntityTypeId(), $page->id()], [$fill->host->entityTypeId(), $fill->host->id()], 'The page is the innermost filler.');
    $this->assertSame($section, $fill->section->getThirdPartySetting('daedalus_layout', 'uuid'), 'With its slot section.');
    $this->assertNull($this->innerRender->slotFiller('aside', $this->outerOf($page), $page), 'Nothing fills aside.');

    // The render: the template around, the page's section inside the slot
    // block, its field block showing the page's own name.
    $html = $this->renderPage($page);
    $xpath = $this->domXpath($html);
    $slot = $this->slotElement($xpath, $html);
    $this->assertStringContainsString('Page A sentinel', $slot->textContent, "The field block inside the slot shows the page's own field: $html");
    $this->assertStringContainsString('Powered by', $html, 'The template renders around it.');
    $this->assertSame(0, $xpath->query('.//*[contains(text(), "Powered by")]', $slot)->length, 'The template block is outside the slot.');
    $own = $xpath->query('.//*[@data-selectable="container:' . $section . '"]', $slot);
    $this->assertSame(1, $own->length, "The slot section keeps the page's identity inside the slot: $html");
    $this->assertSame('0', $own->item(0)->getAttribute('data-layout-builder-section-delta'), 'At the local delta of a one-section list.');
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="block:' . $block . '"]', $slot)->length, 'And so does its block.');
    $this->assertSame(1, $xpath->query('//*[@data-selectable="container:' . $this->templateSection . '"]')->length, 'The template section keeps its own.');
    $this->assertSame(0, $xpath->query('//*[@data-selectable="container:' . $this->templateSection . '"]//*[@data-selectable="container:' . $this->templateSection . '"]')->length, 'Once.');

    // A page filling nothing renders the template alone: the empty slot
    // block is dropped like any block that built nothing.
    [$other] = $this->lockedPage('Page B sentinel', FALSE);
    $html = $this->renderPage($other);
    $this->assertStringContainsString('Powered by', $html);
    $this->assertSame(0, $this->inLayout($html, 'Page B sentinel'), "An unfilled slot shows nothing of the page: $html");
    $this->assertSame(0, $this->domXpath($html)->query('//*[@data-layout-builder-block-uuid="' . $this->slotBlock . '"]')->length, "No slot block markup for a visitor: $html");
  }

  /**
   * An empty slot renders its default, a published blueprint, or nothing.
   */
  public function testEmptySlotRendersTheDefaultOrNothing(): void {
    [$default, $default_section] = $this->blueprint('hero_default');
    $this->blueprint('hero_draft', FALSE);
    [$page] = $this->lockedPage('Page C sentinel', FALSE);

    // The default: the blueprint's document, rendered under the page's
    // contexts, so its field block shows the page's name, and keeping the
    // blueprint's identity.
    $this->placeSlot('hero_default');
    $html = $this->renderPage($page);
    $xpath = $this->domXpath($html);
    $slot = $this->slotElement($xpath, $html);
    $this->assertStringContainsString('Page C sentinel', $slot->textContent, "The default's field block shows the page's own field: $html");
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $default_section . '"]', $slot)->length, "The default keeps the blueprint's identity: $html");
    $build = $this->entityTypeManager->getViewBuilder('entity_test_rev')->view($page, 'full');
    $this->container->get('renderer')->renderInIsolation($build);
    $this->assertContains('blueprint:' . $default->id(), $build['#cache']['tags'], 'The render depends on the blueprint.');

    // A page's own fill wins over the default.
    [$filled, $section] = $this->lockedPage('Page D sentinel', TRUE);
    $html = $this->renderPage($filled);
    $xpath = $this->domXpath($html);
    $slot = $this->slotElement($xpath, $html);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $section . '"]', $slot)->length, 'The page over the default.');
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $default_section . '"]', $slot)->length);

    // An unpublished default, or one naming no entity, shows nothing.
    $this->placeSlot('hero_draft');
    $html = $this->renderPage($page);
    $this->assertSame(0, $this->inLayout($html, 'Page C sentinel'), "An unpublished default shows nothing: $html");
    $this->assertNull($this->innerRender->blueprintHost('hero_draft'));
    $this->placeSlot('no_such_blueprint');
    $this->assertSame(0, $this->inLayout($this->renderPage($page), 'Page C sentinel'));
    $this->assertNull($this->innerRender->blueprintHost('no_such_blueprint'));
  }

  /**
   * On the editing canvas an empty slot wears the stand-in with its label.
   */
  public function testEmptySlotWearsTheStandInOnTheCanvas(): void {
    [$page] = $this->lockedPage('Page E sentinel', FALSE);
    $html = $this->renderCanvas($page);
    $xpath = $this->domXpath($html);
    $slot = $this->slotElement($xpath, $html);
    // No route host in a kernel render: the door reads shut (SLOT), the
    // open state being the route host's insert verdict on the slot block.
    $this->assertSame(BlockStandIn::SLOT, $slot->getAttribute('data-block-stand-in'), "The empty slot wears the slot stand-in: $html");
    $this->assertSame('hero', $slot->getAttribute(SlotBlock::DOOR_ATTRIBUTE), 'The slot block\'s root names the slot it declares for the drag layer.');
    $this->assertSame('Hero', trim($xpath->query('.//*[contains(@class, "daedalus-ui-block-stand-in__label")]', $slot)->item(0)->textContent), 'Labelled with the slot\'s label.');

    // A filled slot on the canvas shows the fill, in the canvas posture.
    [$filled, $section] = $this->lockedPage('Page F sentinel', TRUE);
    $html = $this->renderCanvas($filled);
    $xpath = $this->domXpath($html);
    $slot = $this->slotElement($xpath, $html);
    $this->assertFalse($slot->hasAttribute('data-block-stand-in'), "No stand-in beside a fill: $html");
    $this->assertStringContainsString('Page F sentinel', $slot->textContent);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $section . '"]', $slot)->length);

    // The template canvas itself: the subject is the bundle's sample, which
    // fills nothing, so the slot wears the stand-in there too.
    $sample = $this->container->get('layout_builder.sample_entity_generator')->get('entity_test_rev', 'entity_test_rev');
    $this->assertNull($this->innerRender->slotFiller('hero', $this->outerOf($sample), $sample), 'The sample fills nothing.');
    $html = $this->renderCanvas($sample);
    $this->assertSame(BlockStandIn::SLOT, $this->slotElement($this->domXpath($html), $html)->getAttribute('data-block-stand-in'));
  }

  /**
   * A frame slot shows the innermost filler: page over template over default.
   */
  public function testTheInnermostFillerWins(): void {
    // The frame of the active theme: one section placing the aside slot,
    // whose default is a published blueprint.
    $this->installFrameTheme();
    [$default, $default_section] = $this->blueprint('aside_default');
    $uuids = new PhpUuid();
    $door = $uuids->generate();
    $frame = $this->saveFrame([$this->frameDoor($door, 'aside', 'aside_default')]);
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $frame_host = $resolver->fromEntity($frame);
    $this->assertInstanceOf(FrameHost::class, $frame_host);
    $render = function (FieldableEntityInterface $page) use ($frame_host): string {
      $build = $this->innerRender->renderFrame($frame_host, new RouteOutput(['#markup' => 'Route output'], 'Title'), $page);
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };
    $aside = function (\DOMXPath $xpath, string $html) use ($door): \DOMElement {
      $nodes = $xpath->query('//*[@data-layout-builder-block-uuid="' . $door . '"]');
      $this->assertSame(1, $nodes->length, "Exactly one aside door in: $html");
      return $nodes->item(0);
    };

    // Nothing beneath fills: the default, the blueprint's own section
    // under the page as the subject.
    [$page] = $this->lockedPage('Page G sentinel', FALSE);
    $this->assertNull($this->innerRender->slotFiller('aside', $frame_host, $page));
    $html = $render($page);
    $xpath = $this->domXpath($html);
    $slot = $aside($xpath, $html);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $default_section . '"]', $slot)->length, "The default fills the frame slot: $html");
    $this->assertStringContainsString('Page G sentinel', $slot->textContent, "Under the page's contexts.");

    // The template fills the frame slot with a section of its own: every
    // page of the bundle shows it over the default.
    $template_fill = $uuids->generate();
    $template_block = $uuids->generate();
    $display = $this->lockedDisplay();
    $display->appendSection($this->ownSection($template_fill, 'aside', [
      [
        'uuid' => $template_block,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template aside', 'label_display' => '0'],
      ],
    ]))->save();
    $fill = $this->innerRender->slotFiller('aside', $frame_host, $page);
    $this->assertSame('entity_view_display', $fill->host->entityTypeId(), 'The template is the filler.');
    $html = $render($page);
    $xpath = $this->domXpath($html);
    $slot = $aside($xpath, $html);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $template_fill . '"]', $slot)->length, "The template's section fills the frame slot: $html");
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $default_section . '"]', $slot)->length, 'Over the default.');

    // The page fills it too: the page's own section, over the template's.
    $page_fill = $uuids->generate();
    $page_block = $uuids->generate();
    $page->set(ContentHost::LAYOUT_FIELD, [$this->ownSection($page_fill, 'aside', [$this->nameBlock($page_block)])])->save();
    $page = $this->entityTypeManager->getStorage('entity_test_rev')->loadUnchanged($page->id());
    $fill = $this->innerRender->slotFiller('aside', $frame_host, $page);
    $this->assertSame('entity_test_rev', $fill->host->entityTypeId(), 'The page is the innermost filler.');
    $html = $render($page);
    $xpath = $this->domXpath($html);
    $slot = $aside($xpath, $html);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $page_fill . '"]', $slot)->length, "The page's section fills the frame slot: $html");
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="block:' . $page_block . '"]', $slot)->length);
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $template_fill . '"]', $slot)->length, "Over the template's.");
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $default_section . '"]', $slot)->length, 'Over the default.');
    // Another page of the bundle still shows the template's fill.
    [$other] = $this->lockedPage('Page H sentinel', FALSE);
    $this->assertSame('entity_view_display', $this->innerRender->slotFiller('aside', $frame_host, $other)->host->entityTypeId());

    // An OVERRIDABLE page's own render leaves its fill out: the fill's
    // place is the frame's slot, where it renders once. A slot section
    // whose slot nothing around the page declares renders in the flow.
    $own_fill = $uuids->generate();
    $own_block = $uuids->generate();
    $orphan = $uuids->generate();
    $overridable = $this->createTestEntity([
      'name' => 'Page I sentinel',
      ContentHost::LAYOUT_FIELD => [
        $this->createSection('eb_test_container', [
          ['configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own flow', 'label_display' => '0']],
        ]),
        $this->ownSection($own_fill, 'aside', [
          [
            'uuid' => $own_block,
            'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own aside', 'label_display' => '0'],
          ],
        ]),
        $this->ownSection($orphan, 'nowhere', []),
      ],
    ]);
    $overridable_host = $resolver->fromEntity($overridable);
    $this->assertTrue($this->innerRender->renderedBySlot($overridable_host, $overridable->get(ContentHost::LAYOUT_FIELD)->getSections()[1]));
    $this->assertFalse($this->innerRender->renderedBySlot($overridable_host, $overridable->get(ContentHost::LAYOUT_FIELD)->getSections()[2]), 'No door for it: the flow.');
    $view = $this->entityTypeManager->getViewBuilder('entity_test')->view($overridable, 'full');
    $view_html = (string) $this->container->get('renderer')->renderInIsolation($view);
    $view_xpath = $this->domXpath($view_html);
    $this->assertSame(0, $view_xpath->query('//*[@data-selectable="container:' . $own_fill . '"]')->length, "The fill is not in the page's own flow: $view_html");
    $this->assertSame(1, $view_xpath->query('//*[@data-selectable="container:' . $orphan . '"]')->length, "The orphan fill renders in the flow: $view_html");
    $html = $render($overridable);
    $xpath = $this->domXpath($html);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $own_fill . '"]', $aside($xpath, $html))->length, "The fill is in the frame's slot: $html");

    // The door of a slot above a host: the frame's for aside (with its
    // default), the template's for hero (no default), nothing for a slot
    // nobody declares, nothing for the frame's main slot, and nothing for
    // a host that fills no slots (the frame itself, a blueprint).
    $page_host = $resolver->fromEntity($page);
    $aside_door = $this->innerRender->slotDoor('aside', $page_host);
    $this->assertSame([FrameInterface::ENTITY_TYPE_ID, 'stark', 'aside', 'aside_default', $door], [
      $aside_door->holder->entityTypeId(),
      $aside_door->holder->id(),
      $aside_door->slot(),
      $aside_door->default(),
      $aside_door->component->getUuid(),
    ]);
    $hero_door = $this->innerRender->slotDoor('hero', $page_host);
    $this->assertSame(
      ['entity_view_display', $this->slotBlock, NULL],
      [$hero_door->holder->entityTypeId(), $hero_door->component->getUuid(), $hero_door->default()],
    );
    $this->assertNotNull($this->innerRender->slotDoor('aside', $resolver->fromEntity($this->lockedDisplay())), 'A template claims a frame slot too.');
    $this->assertNull($this->innerRender->slotDoor('nowhere', $page_host));
    $this->assertNull($this->innerRender->slotDoor(FrameHost::MAIN_SLOT, $page_host));
    $this->assertNull($this->innerRender->slotDoor('aside', $frame_host));
    $this->assertNull($this->innerRender->slotDoor('aside', $resolver->fromEntity($default)));
    $this->assertSame('stark', $this->innerRender->frameHost()->id(), 'The active theme\'s frame.');
    $this->assertNull($this->innerRender->frameHost('claro'));
  }

  /**
   * The frame slot hosting the route's output: the door by default, else main.
   *
   * A blueprint's canvas lands in the frame-tier door whose default names
   * the blueprint (InnerRender::routeSlot()), so the chrome is edited in
   * situ; a blueprint no frame-tier door defaults to (a template-tier slot
   * placed in the frame is no door of the frame), a page's route and a
   * host-less route land in main. In the render the door prints the route
   * output inside the main landmark in place of its default, and main
   * prints the stand-in the output carries, with no landmark of its own.
   */
  public function testRouteSlot(): void {
    $this->installFrameTheme();
    // The frame's main slot joins the roster: a frame slot under test.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_FRAME],
        FrameHost::MAIN_SLOT => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    [$aside_default, $aside_section] = $this->blueprint('aside_default');
    [$hero_default] = $this->blueprint('hero_default');
    $this->blueprint('nowhere_default');
    $uuids = new PhpUuid();
    $aside_door = $uuids->generate();
    $hero_door = $uuids->generate();
    $main_slot = $uuids->generate();
    $frame = $this->saveFrame([
      $this->frameDoor($aside_door, 'aside', 'aside_default'),
      $this->frameDoor($hero_door, 'hero', 'hero_default'),
      $this->frameDoor($main_slot, FrameHost::MAIN_SLOT),
    ]);
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $frame_host = $resolver->fromEntity($frame);
    $stand_in = ['#markup' => 'Stand-in sentinel'];
    $output = fn (?HostInterface $host): RouteOutput => new RouteOutput(['#markup' => 'Route output sentinel'], 'Title', $host, $stand_in);

    // The spine.
    $this->assertSame('aside', $this->innerRender->routeSlot($frame_host, $resolver->fromEntity($aside_default)), 'The door whose default is the blueprint hosts its canvas.');
    $this->assertSame(FrameHost::MAIN_SLOT, $this->innerRender->routeSlot($frame_host, $resolver->fromEntity($hero_default)), 'A template-tier slot placed in the frame is no door of the frame: main.');
    $this->assertSame(FrameHost::MAIN_SLOT, $this->innerRender->routeSlot($frame_host, $this->innerRender->blueprintHost('nowhere_default')), 'A blueprint no door defaults to: main.');
    [$page] = $this->lockedPage('Page J sentinel', FALSE);
    $this->assertSame(FrameHost::MAIN_SLOT, $this->innerRender->routeSlot($frame_host, $resolver->fromEntity($page)), "A page's route: main.");
    $this->assertSame(FrameHost::MAIN_SLOT, $this->innerRender->routeSlot($frame_host, NULL), 'A host-less route: main.');

    // The render around the blueprint's route: the door hosts the output.
    $build = $this->innerRender->renderFrame($frame_host, $output($resolver->fromEntity($aside_default)), $aside_default);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->domXpath($html);
    $door = $xpath->query('//*[@data-layout-builder-block-uuid="' . $aside_door . '"]');
    $this->assertSame(1, $door->length, "One aside door in: $html");
    $landmarks = $xpath->query('//main[@role="main"]');
    $this->assertSame(1, $landmarks->length, "One main landmark on the page: $html");
    $this->assertSame(1, $xpath->query('.//main[@role="main"]', $door->item(0))->length, 'The door prints it.');
    $this->assertStringContainsString('Route output sentinel', $door->item(0)->textContent, 'With the route output inside.');
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $aside_section . '"]', $door->item(0))->length, "The door shows the canvas, not its default beside it: $html");
    $main = $xpath->query('//*[@data-layout-builder-block-uuid="' . $main_slot . '"]');
    $this->assertSame(1, $main->length, "One main slot in: $html");
    $this->assertStringContainsString('Stand-in sentinel', $main->item(0)->textContent, 'Main prints the stand-in.');
    $this->assertStringNotContainsString('Route output sentinel', $main->item(0)->textContent);
    $this->assertSame(0, $xpath->query('.//main', $main->item(0))->length, 'With no landmark of its own.');

    // Around a page's route: main hosts the output, the door its default.
    $build = $this->innerRender->renderFrame($frame_host, $output($resolver->fromEntity($page)), $page);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->domXpath($html);
    $main = $xpath->query('//*[@data-layout-builder-block-uuid="' . $main_slot . '"]')->item(0);
    $this->assertStringContainsString('Route output sentinel', $xpath->query('.//main[@role="main"]', $main)->item(0)->textContent, "Main prints the route output on a page: $html");
    $this->assertStringNotContainsString('Stand-in sentinel', $html, 'No stand-in anywhere.');
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . $aside_door . '"]//*[@data-selectable="container:' . $aside_section . '"]')->length, 'The door shows its default.');
  }

  /**
   * The slot block derives per roster id and is offered on templates only.
   */
  public function testOfferedOnTemplateCanvasesOnly(): void {
    $block_manager = $this->container->get('plugin.manager.block');
    $this->assertSame('Hero slot', (string) $block_manager->getDefinition(SlotBlock::PLUGIN_ID . ':hero')['admin_label'], 'The derivative is named by the roster\'s word with the kind\'s: the Add panel\'s and the tree\'s word for the door.');
    $this->assertSame('Aside slot', (string) $block_manager->getDefinition(SlotBlock::PLUGIN_ID . ':aside')['admin_label']);
    $this->assertFalse($block_manager->hasDefinition(SlotBlock::PLUGIN_ID . ':footer'));
    // A roster save rebuilds the definitions.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'footer' => ['label' => 'Footer', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $this->assertTrue($block_manager->hasDefinition(SlotBlock::PLUGIN_ID . ':footer'));
    $this->assertFalse($block_manager->hasDefinition(SlotBlock::PLUGIN_ID . ':aside'));

    /** @var \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface $restrictions */
    $restrictions = $this->container->get('daedalus_blueprint.placement_restrictions');
    $template = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->lockedDisplay());
    $this->assertInstanceOf(DisplayHost::class, $template);
    $offered = array_keys($restrictions->allowedBlockDefinitions($template->sectionStorage()));
    $this->assertContains(SlotBlock::PLUGIN_ID . ':hero', $offered, 'A template canvas offers the slot.');
    $this->assertContains(SlotBlock::PLUGIN_ID . ':footer', $offered);
    [$locked] = $this->lockedPage('Locked', TRUE);
    $this->assertNotContains(SlotBlock::PLUGIN_ID . ':hero', array_keys($restrictions->allowedBlockDefinitions($this->host($locked)->sectionStorage())), "A locked page's own storage does not.");
    $page = $this->host($this->createTestEntity(['name' => 'Page']));
    $this->assertNotContains(SlotBlock::PLUGIN_ID . ':hero', array_keys($restrictions->allowedBlockDefinitions($page->sectionStorage())), 'Nor an overridable page.');
    $this->assertFalse($restrictions->blockAllowed(SlotBlock::PLUGIN_ID . ':hero', NULL), 'Nor no storage at all.');

    // The op door says the same: the page refuses the slot, the template
    // takes it with config-only configuration.
    $operations = $this->container->get('daedalus_blueprint.operations');
    $root = ['anchor' => $page->uuid(), 'placement' => 'inside'];
    $section = (new PhpUuid())->generate();
    $this->assertTrue($operations->batchOperations($page, [
      ['op' => 'add_section', 'location' => $root, 'uuid' => $section],
    ])->isSuccess());
    $result = $operations->batchOperations($page, [
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside'],
        'plugin_id' => SlotBlock::PLUGIN_ID . ':hero',
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('RESTRICTED_PLUGIN_ID', $result->getErrors()[0]->getCode());

    $placed = (new PhpUuid())->generate();
    $result = $operations->batchOperations($template, [
      [
        'op' => 'add_element',
        'location' => ['anchor' => $this->templateSection, 'placement' => 'inside'],
        'uuid' => $placed,
        'plugin_id' => SlotBlock::PLUGIN_ID . ':footer',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $component = $template->sectionStorage()->getSection(0)->getComponent($placed);
    $configuration = $component->get('configuration');
    $this->assertSame(SlotBlock::PLUGIN_ID . ':footer', $configuration['id']);
    $this->assertSame(SlotBlock::CONTEXT_MAPPING, $configuration['context_mapping'], 'The subject and the view mode, mapped by default.');
    $this->assertNull($configuration[SlotBlock::DEFAULT_KEY], 'No default unless named.');
    $config_keys = ['id', 'label', 'label_display', 'provider', 'context_mapping', SlotBlock::DEFAULT_KEY];
    $this->assertSame([], array_diff(array_keys($configuration), $config_keys), 'Config only: ' . implode(', ', array_keys($configuration)));
  }

}
