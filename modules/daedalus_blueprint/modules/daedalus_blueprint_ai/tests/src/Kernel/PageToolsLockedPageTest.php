<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageEdit;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageRead;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page tools on a locked page: the template read-only, the slots its own.
 *
 * A page of a locked bundle (its display Layout Builder enabled, not
 * overridable) renders its template and owns only the slots the template
 * places. page_read prints one tree: the notice naming the template and
 * the slots the page may fill, the template's elements in the read-only
 * register (data-can="", content kept on a field block of the page's own
 * field), the slot block as the door (data-can="insert") and, after the
 * template, the page's own slot sections writable (<pb-slot name="id">,
 * no data-can). page_edit writes into a slot by inserting inside the door:
 * the first fill is born as the slot section itself, later fills land in
 * it, staged under the page's key and saved with the page, the display
 * untouched; a write on the template or at the page's root is refused
 * FOREIGN_DOCUMENT.
 */
#[CoversClass(PageEdit::class)]
#[CoversClass(PageRead::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageToolsLockedPageTest extends BlueprintKernelTestBase {

  /**
   * The locked template: the entity_test bundle's default display.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The field block the template places: the bundle's name field.
   */
  protected const FIELD_BLOCK = 'field_block:entity_test:entity_test:name';

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * The template section's uuid.
   */
  protected string $templateSection;

  /**
   * The template's plugin block uuid.
   */
  protected string $templateBlock;

  /**
   * The template's field block uuid, presenting the bundle's name field.
   */
  protected string $fieldBlock;

  /**
   * The template's hero slot block uuid: the door of slot "hero".
   */
  protected string $heroDoor;

  /**
   * The locked page.
   */
  protected object $page;

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
      'file',
      'daedalus_ui',
      // Section identity (the uuid the tree addresses containers by) is
      // daedalus_layout's third-party setting, and the override field a
      // locked bundle keeps through the lock is its doing.
      'daedalus_layout',
      // The eb_test_container layout: the container layout the catalog
      // builder derives containerLayoutId from.
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    // The site's slots: the roster save rebuilds the block definitions.
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();
    $uuids = new PhpUuid();
    $this->templateSection = $uuids->generate();
    $this->templateBlock = $uuids->generate();
    $this->fieldBlock = $uuids->generate();
    $this->heroDoor = $uuids->generate();
    // The locked template: a plugin block, the page's name field and the
    // hero slot (aside is declared on the site but placed nowhere).
    $template = $this->createSection('eb_test_container', [
      [
        'uuid' => $this->templateBlock,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block'],
      ],
      [
        'uuid' => $this->fieldBlock,
        'configuration' => ['id' => self::FIELD_BLOCK, 'label' => 'Name'],
      ],
      [
        'uuid' => $this->heroDoor,
        'configuration' => [
          'id' => SlotBlock::PLUGIN_ID . ':hero',
          'label' => 'Hero',
          'label_display' => '0',
          'context_mapping' => SlotBlock::CONTEXT_MAPPING,
          SlotBlock::DEFAULT_KEY => NULL,
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $this->templateSection);
    LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID)
      ->setOverridable(FALSE)
      ->removeAllSections()
      ->appendSection($template)
      ->save();
    $this->page = $this->createTestEntity(['name' => 'Locked page']);
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($this->host($this->page)), 'The page renders its template.');
    $this->assertSame([], $this->host($this->page)->ownSections(), 'A locked page is never seeded.');
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
   */
  protected function runTool(string $function_name, array $contexts): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName($function_name);
    $this->assertNotNull($tool, "The $function_name tool is available.");
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The page's address for the tools.
   */
  protected function address(): array {
    return ['entity_type' => 'entity_test', 'entity_id' => (string) $this->page->id()];
  }

  /**
   * The template's saved component plugin ids, loaded fresh.
   *
   * @return string[]
   *   The plugin ids of every component in the display's own sections.
   */
  protected function savedTemplatePluginIds(): array {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged(self::DISPLAY_ID);
    $ids = [];
    foreach ($display->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $ids[] = $component->getPluginId();
      }
    }
    return $ids;
  }

  /**
   * The page's saved own sections, loaded fresh, as [slot => plugin ids].
   *
   * @return array<string, string[]>
   *   The slot each own section fills to the plugin ids it holds.
   */
  protected function savedFills(): array {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache();
    $fills = [];
    foreach ($this->host($storage->load($this->page->id()))->ownSections() as $section) {
      $ids = [];
      foreach ($section->getComponents() as $component) {
        $ids[] = $component->getPluginId();
      }
      $fills[(string) LayoutSerializer::slotOf($section)] = $ids;
    }
    return $fills;
  }

  /**
   * The notice a locked page's read is headed with: the template and the slots.
   */
  protected function notice(): string {
    return 'The layout of this entity_test belongs to the template entity_view_display ' . self::DISPLAY_ID . ', which it renders but does not own. Edit this entity_test\'s own fields here and fill its slots (hero) by inserting inside their slot blocks; edit its layout on the template: run the operation against entity_view_display ' . self::DISPLAY_ID . '.';
  }

  /**
   * Asserts the template prints in the read-only register, the door open.
   */
  protected function assertTemplateReadOnly(string $output, string $message = ''): void {
    $this->assertStringContainsString('<pb-container id="' . $this->templateSection . '" data-can="">', $output, $message . ' The template container is marked: nothing may be done to it on this page.');
    $this->assertStringContainsString('<pb-block id="' . $this->templateBlock . '" data-can="" plugin="system_powered_by_block" />', $output, $message . ' The template block is marked.');
    $this->assertStringContainsString('<pb-block id="' . $this->fieldBlock . '" data-can="content" field="name" />', $output, $message . ' A field block presenting the page\'s own field keeps content.');
    $this->assertStringContainsString('<pb-block id="' . $this->heroDoor . '" data-can="insert" plugin="daedalus_slot:hero" />', $output, $message . ' The slot block is the door: insert is the page\'s own, nothing else.');
  }

  /**
   * A locked page reads as one tree: the template read-only, fills writable.
   *
   * Empty, the read is the notice (template and slots), the page's own
   * fields and the template with its elements marked, no slot section.
   * An insert inside the door is born as the page's slot section, printed
   * after the template as <pb-slot name="hero"> with no data-can on it or
   * its children, staged (the display untouched); a second insert into
   * the fill, saved, lands both in the page's one slot section; the read
   * after the save prints the same.
   */
  public function testLockedPageReadsAndFillsItsSlot(): void {
    $output = $this->runTool('page_read', $this->address());
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringStartsWith($this->notice() . "\nfields:\n", $output, 'The read says where the layout is edited and which slots the page fills, then answers the page\'s own fields.');
    $this->assertStringContainsString("\n  name: 'Locked page'\n", $output);
    $this->assertTemplateReadOnly($output, 'Empty:');
    $this->assertStringNotContainsString('<pb-slot', $output, 'Nothing fills the slot yet.');

    // The first fill: a container inserted inside the door becomes the slot
    // section itself, the page's own.
    $output = $this->runTool('page_edit', $this->address() + [
      'ops' => [
        [
          'op' => 'insert',
          'html' => '<pb-container id="band"><pb-block field="name"/></pb-container>',
          'at' => ['ref' => $this->heroDoor, 'position' => 'beforeend'],
        ],
      ],
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: tempstore', $output, 'An unsaved fill stages under the page\'s key.');
    $this->assertStringContainsString('Updated page:', $output);
    $this->assertTemplateReadOnly($output, 'Staged:');
    $this->assertMatchesRegularExpression('/\n<pb-slot id="([0-9a-f-]{36})" data-alias="band" name="hero">\n  <pb-block id="[0-9a-f-]{36}" field="name" \/>\n<\/pb-slot>\n$/', $output, 'The fill prints after the template as the page\'s own slot section: writable (no data-can), named by the slot, its alias kept.');
    $this->assertSame(['system_powered_by_block', self::FIELD_BLOCK, SlotBlock::PLUGIN_ID . ':hero'], $this->savedTemplatePluginIds(), 'The display is untouched: the fill is the page\'s.');
    $this->assertSame([], $this->savedFills(), 'The staged fill is not saved yet.');

    $read = $this->runTool('page_read', $this->address());
    $this->assertStringStartsWith($this->notice() . "\nfields:\n", $read);
    $this->assertMatchesRegularExpression('/\n<pb-slot id="[0-9a-f-]{36}" data-alias="band" name="hero">\n  <pb-block id="[0-9a-f-]{36}" field="name" \/>\n<\/pb-slot>\n$/', $read, 'page_read answers the staged fill, writable.');

    // A second fill lands in the slot section the page already owns, and
    // the save lands both with the page.
    $output = $this->runTool('page_edit', $this->address() + [
      'ops' => [
        [
          'op' => 'insert',
          'html' => '<pb-block plugin="system_powered_by_block"/>',
          'at' => ['ref' => 'band', 'position' => 'beforeend'],
        ],
      ],
      'save' => TRUE,
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertMatchesRegularExpression('/\n<pb-slot id="[0-9a-f-]{36}" data-alias="band" name="hero">\n  <pb-block id="[0-9a-f-]{36}" field="name" \/>\n  <pb-block id="[0-9a-f-]{36}" plugin="system_powered_by_block" \/>\n<\/pb-slot>\n$/', $output);
    $this->assertSame(['hero' => [self::FIELD_BLOCK, 'system_powered_by_block']], $this->savedFills(), 'The save landed the page\'s one slot section with both blocks.');
    $this->assertSame(['system_powered_by_block', self::FIELD_BLOCK, SlotBlock::PLUGIN_ID . ':hero'], $this->savedTemplatePluginIds(), 'The display is still untouched.');
    $this->assertStringContainsString('<pb-slot id="', $this->runTool('page_read', $this->address()), 'The saved fill reads back.');
  }

  /**
   * A write on the template or at the page's root is the template's, refused.
   *
   * An insert at the page's root (its top-level flow is the template's),
   * an insert inside the template's container and a remove of a template
   * block are each refused FOREIGN_DOCUMENT naming the template, and
   * nothing is staged; the one road is the door. The page's own fields
   * stay its own: a set on root writes the name.
   */
  public function testTemplateWritesAreRefused(): void {
    $refused = [
      'root' => [
        'op' => 'insert',
        'html' => '<pb-container id="band"></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
      'template container' => [
        'op' => 'insert',
        'html' => '<pb-block plugin="system_powered_by_block"/>',
        'at' => ['ref' => $this->templateSection, 'position' => 'beforeend'],
      ],
      'template block' => [
        'op' => 'remove',
        'ref' => $this->templateBlock,
      ],
    ];
    foreach ($refused as $where => $op) {
      $output = $this->runTool('page_edit', $this->address() + ['ops' => [$op]]);
      $this->assertStringContainsString('FOREIGN_DOCUMENT', $output, "A write on the $where is refused.");
      $this->assertStringContainsString(self::DISPLAY_ID, $output, 'The refusal names the template.');
      $this->assertStringNotContainsString('Updated page:', $output);
    }
    $this->assertSame([], $this->savedFills(), 'Nothing landed.');
    $this->assertStringNotContainsString('<pb-slot', $this->runTool('page_read', $this->address()), 'Nothing staged.');

    $output = $this->runTool('page_edit', $this->address() + [
      'ops' => [['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed']]],
      'save' => TRUE,
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString("\n  name: Renamed\n", $output, 'The page\'s own fields are its own to write.');
  }

}
