<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Variant classes on a document rendered outside its host's own view.
 *
 * Live delivery of a worn variant is a class on the element plus the
 * compiled variants stylesheet. The class is stamped by a walk over the
 * host's own entity view build, and by the render primitive's alter hook
 * (daedalus_stylish_daedalus_blueprint_inner_render_alter()) for every
 * document rendered inside another. Two renders pass through the primitive
 * with the inner host equal to the subject and neither is inside the
 * subject's own view build, so only the hook can stamp them: a page's
 * claimed slot section, shown by the frame's door in place of the door's
 * default; and a frame door whose default is the subject's own blueprint.
 * The style tag is not repeated for either (the subject's own view
 * delivers it, and its @scope CSS reaches every copy by key); the class
 * is, because it rides each rendered element. A fill shown by a door in
 * the page's own locked template is not pinned here: the page's view
 * builds the template, the door and the fill eagerly, so the entity view
 * walk already stamps it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class InnerRenderVariantClassTest extends KernelTestBase {

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
    // The container layout the catalog needs: a page-language site.
    'daedalus_blueprint_test',
    // The blueprint entity a door shows by default.
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
   * The variant class a reference to test_variant stamps.
   *
   * Derived from the stored reference verbatim
   * (VariantStylesheet::fragmentClass()), so no variant entity is needed.
   */
  protected const VARIANT_CLASS = 'daedalus-stylish-variant--test_variant';

  /**
   * The identities of the frame's document.
   */
  protected const FRAME_SECTION = '6a000000-0000-4000-8000-000000000001';
  protected const HEADER_DOOR = '6a000000-0000-4000-8000-000000000002';
  protected const MAIN_SLOT = '6a000000-0000-4000-8000-000000000003';

  /**
   * The identities of the header blueprint's document.
   */
  protected const BLUEPRINT_SECTION = '6a000000-0000-4000-8000-000000000004';
  protected const BLUEPRINT_BLOCK = '6a000000-0000-4000-8000-000000000005';

  /**
   * The identities of the locked bundle's template and the page's fill.
   */
  protected const TEMPLATE_SECTION = '6a000000-0000-4000-8000-000000000006';
  protected const TEMPLATE_BLOCK = '6a000000-0000-4000-8000-000000000007';
  protected const PAGE_HEADER_FILL = '6a000000-0000-4000-8000-000000000008';
  protected const PAGE_HEADER_BLOCK = '6a000000-0000-4000-8000-000000000009';

  /**
   * The header blueprint, its one section wearing test_variant.
   */
  protected Blueprint $blueprint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
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
    // Live renders only: a visitor, no editing permission.
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
    // A kernel test has no request to negotiate a theme from, so the theme
    // a page would be rendered in is set the way the negotiator would.
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName(self::THEME));
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => TRUE])->save();

    // The site's slots: the frame's main and header.
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'header' => ['label' => 'Header', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();

    // The locked bundle: Layout Builder on, overrides off, a template of
    // one section holding a plugin block, so its pages render the template
    // and own only their slot fills.
    $template = $this->section(self::TEMPLATE_SECTION, [
      $this->pluginBlock(self::TEMPLATE_BLOCK, 'Template sentinel'),
    ]);
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder();
    $display->save();
    $display->removeAllSections()->appendSection($template)->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    // Both documents under test store styles: the locked bundle's pages
    // and the site chrome blueprints.
    $field_manager = $this->container->get('daedalus_stylish.field_manager');
    $field_manager->enable('entity_test_rev', 'entity_test_rev');
    $field_manager->enable('blueprint', 'site_chrome');

    // The header: a site_chrome blueprint for the locked bundle whose one
    // section wears test_variant. Its block renders for any subject, so
    // the section's element is on every render of the document.
    $this->blueprint = Blueprint::create([
      'type' => 'site_chrome',
      'label' => 'Header',
      'machine_name' => 'header',
      'description' => 'The site header.',
      'scope' => 'block',
      'subject_type' => 'entity_test_rev',
      'subject_bundle' => 'entity_test_rev',
      'status' => 1,
      ContentHost::LAYOUT_FIELD => [
        $this->section(self::BLUEPRINT_SECTION, [
          $this->pluginBlock(self::BLUEPRINT_BLOCK, 'Header sentinel'),
        ]),
      ],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($this->wearing(self::BLUEPRINT_SECTION)),
    ]);
    $this->blueprint->save();

    // The theme's frame: the header door defaulting to the header
    // blueprint, and the main slot.
    $frame = Frame::create(['id' => self::THEME]);
    $frame->appendSection($this->section(self::FRAME_SECTION, [
      $this->slotBlock(self::HEADER_DOOR, 'header', 'header'),
      $this->slotBlock(self::MAIN_SLOT, 'main'),
    ]));
    $frame->save();
  }

  /**
   * A style map in which one section key references test_variant.
   */
  protected function wearing(string $section_uuid): array {
    return [
      'container:' . $section_uuid => ['variants' => [['variant' => 'test_variant', 'fragment' => '']]],
    ];
  }

  /**
   * A one-column section with components, stamped with its identity.
   */
  protected function section(string $uuid, array $components, ?string $slot = NULL): Section {
    foreach ($components as $weight => $component) {
      $component->setWeight($weight);
    }
    $section = new Section('layout_onecol', [], $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    if ($slot !== NULL) {
      $section->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, $slot);
    }
    return $section;
  }

  /**
   * A plugin block component with a visible label naming it.
   */
  protected function pluginBlock(string $uuid, string $label): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => 'system_powered_by_block',
      'label' => $label,
      'label_display' => 'visible',
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
   * A page of the locked bundle, claiming the frame's header slot or not.
   *
   * A claiming page's own header section wears test_variant.
   */
  protected function lockedPage(bool $claims_header): EntityTestRev {
    $values = ['name' => 'Page sentinel'];
    if ($claims_header) {
      $values[ContentHost::LAYOUT_FIELD] = [
        $this->section(self::PAGE_HEADER_FILL, [
          $this->pluginBlock(self::PAGE_HEADER_BLOCK, 'Page header sentinel'),
        ], 'header'),
      ];
      $values[DaedalusStylishFieldManager::FIELD_NAME] = json_encode($this->wearing(self::PAGE_HEADER_FILL));
    }
    $page = EntityTestRev::create($values);
    $page->save();
    return $this->container->get('entity_type.manager')->getStorage('entity_test_rev')->loadUnchanged($page->id());
  }

  /**
   * The frame's host.
   */
  protected function frameHost(): HostInterface {
    $frame = $this->container->get('entity_type.manager')->getStorage(FrameInterface::ENTITY_TYPE_ID)->loadUnchanged(self::THEME);
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($frame);
  }

  /**
   * Renders the frame around a route's output for a subject.
   */
  protected function renderFrame(?FieldableEntityInterface $subject): string {
    $build = $this->container->get('daedalus_blueprint.inner_render')
      ->renderFrame($this->frameHost(), new RouteOutput(['#markup' => 'Main content sentinel'], 'Title sentinel'), $subject);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
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
   * The one element stamped with a section's key inside a slot door.
   *
   * A visitor's render carries no block uuids (daedalus_ui stamps them for
   * editors), so the door is found by the slot block's element and the
   * section by its key.
   */
  protected function sectionInDoor(string $html, string $section_uuid): \DOMElement {
    $xpath = $this->domXpath($html);
    $this->assertSame(1, $xpath->query(sprintf('//*[@data-selectable="container:%s"]', $section_uuid))->length, "The section renders once: $html");
    $sections = $xpath->query(sprintf('//*[@data-element="block_plugin:%s"]//*[@data-selectable="container:%s"]', SlotBlock::PLUGIN_ID, $section_uuid));
    $this->assertSame(1, $sections->length, "The section renders inside a slot door: $html");
    return $sections->item(0);
  }

  /**
   * A page's claimed slot section wears its variant class in the door.
   *
   * The frame's header door shows the page's own header section in place
   * of its default: the fill renders through the primitive with the page
   * as both the inner host and the subject, inside the frame, where the
   * page's own view build never reaches.
   */
  public function testSlotFillWearsItsVariantClass(): void {
    $html = $this->renderFrame($this->lockedPage(TRUE));
    $fill = $this->sectionInDoor($html, self::PAGE_HEADER_FILL);
    $this->assertStringContainsString(self::VARIANT_CLASS, $fill->getAttribute('class'), "The page's claimed section wears its variant class inside the frame's door: $html");
    $this->assertStringNotContainsString('container:' . self::BLUEPRINT_SECTION, $html, 'The claim replaces the default.');
  }

  /**
   * A door showing the subject's own document wears its variant class.
   *
   * The frame's header door defaults to the header blueprint; with that
   * blueprint as the subject (its own canvas route), the default renders
   * through the primitive with the inner host equal to the subject. The
   * control is the same door on an entity route: a page that claims
   * nothing as the subject, which the hook always delivered to.
   */
  public function testDoorShowingTheSubjectsOwnDocumentWearsItsVariantClass(): void {
    $html = $this->renderFrame($this->blueprint);
    $door_copy = $this->sectionInDoor($html, self::BLUEPRINT_SECTION);
    $this->assertStringContainsString(self::VARIANT_CLASS, $door_copy->getAttribute('class'), "The door's copy of the subject's own document wears its variant class: $html");

    $html = $this->renderFrame($this->lockedPage(FALSE));
    $door_copy = $this->sectionInDoor($html, self::BLUEPRINT_SECTION);
    $this->assertStringContainsString(self::VARIANT_CLASS, $door_copy->getAttribute('class'), "The control: the door's copy of the header wears its variant class on an entity route: $html");
  }

}
