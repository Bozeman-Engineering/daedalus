<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageEdit;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageRead;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page tools on a template: a Layout Builder display is a page host.
 *
 * The read and edit tools address a template by its display id
 * (entity_type entity_view_display), the same door a page takes: the
 * batch rides the host, a field block placed through the page language
 * lands in the display's own sections, staged or saved, and the print
 * reads back the same document. The doors with no host in hand (page_create,
 * eb_read_schema) refuse the template type with a corrective, eb_edit
 * routes a template to page_edit as it routes a page, visibility follows
 * the document's kind (a hidden bundle hides its templates), and a display
 * without Layout Builder is config, not a template.
 */
#[CoversClass(PageEdit::class)]
#[CoversClass(PageRead::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageToolsTemplateTest extends BlueprintKernelTestBase {

  /**
   * The template under test: the entity_test bundle's default display.
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
      // daedalus_layout's third-party setting.
      'daedalus_layout',
      // The eb_test_container layout: the container layout the catalog
      // builder derives containerLayoutId from.
      'daedalus_blueprint_test',
      // The config backend claims every display by type; the eb_edit leg
      // pins that a template is routed to page_edit before it can reach
      // update_properties through it.
      'daedalus_blueprint_config',
    ]);
    $this->installEntitySchema('block_content');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();
    // A display without Layout Builder beside the template: config that
    // hosts nothing.
    EntityViewMode::create(['id' => 'entity_test.teaser', 'targetEntityType' => 'entity_test', 'label' => 'Teaser'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->save();
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
   * The template's saved component plugin ids, loaded fresh.
   *
   * @return string[]
   *   The plugin ids of every component in the display's own sections.
   */
  protected function savedPluginIds(): array {
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
   * The edit tool places a field block on the template, staged then saved.
   *
   * The staged edit is what the canvas shows and what page_read answers
   * (the storage handler loads the tempstore copy); the display's config
   * is untouched until the batch is saved, and then its own sections hold
   * the container and the field block the page language spelled.
   */
  public function testPageEditPlacesFieldBlockOnTemplate(): void {
    $this->assertStringContainsString('(the page has no layout content yet)', $this->runTool('page_read', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
    ]), 'An empty template reads as an empty page.');

    // The field is named; the template's subject (the bundle's sample
    // entity) supplies the field_block derivative the display stores.
    $insert = [
      'op' => 'insert',
      'html' => '<pb-container id="band"><pb-block field="name"/></pb-container>',
      'at' => ['ref' => 'root', 'position' => 'beforeend'],
    ];
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
      'ops' => [$insert],
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: tempstore', $output, 'An unsaved template edit stages, as a page edit does.');
    $this->assertStringContainsString('Updated page:', $output);
    $this->assertStringContainsString('field="name"', $output, 'The echo prints the field block the batch placed, by its field.');
    $this->assertStringNotContainsString(self::FIELD_BLOCK, $output, 'The derivative never prints.');
    $this->assertSame([], $this->savedPluginIds(), 'The staged edit left the display config untouched.');
    $this->assertStringContainsString('field="name"', $this->runTool('page_read', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
    ]), 'page_read answers the staged template.');

    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
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
    $this->assertSame([self::FIELD_BLOCK, 'system_powered_by_block'], $this->savedPluginIds(), 'The save landed the staged container, its field block and the new plugin block in the display\'s own sections.');
  }

  /**
   * The JSON door routes a template to page_edit, as it routes a page.
   *
   * A Layout Builder display is a page document on a page-language site,
   * so the JSON door refuses it toward page_edit instead of writing its
   * third-party settings through update_properties.
   */
  public function testEbEditRoutesTemplateToPageEdit(): void {
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
      'save' => TRUE,
      'operations' => [
        ['op' => 'update_properties', 'properties' => ['status' => FALSE]],
      ],
    ]);
    $this->assertStringContainsString('is a template', $output);
    $this->assertStringContainsString('page_edit', $output);
    $this->assertTrue(LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID)->status(), 'Nothing ran: the display is untouched.');
  }

  /**
   * The doors with no host in hand refuse the template type with a corrective.
   */
  public function testBirthAndGrammarDoorsRefuseTemplateType(): void {
    $create = $this->runTool('page_create', [
      'entity_type' => 'entity_view_display',
      'bundle' => 'entity_view_display',
      'layout' => '<pb-container id="band"></pb-container>',
    ]);
    $this->assertStringContainsString('born by enabling Layout Builder', $create);
    $this->assertStringContainsString('page_edit', $create);

    $schema = $this->runTool('eb_read_schema', ['entity_type' => 'entity_view_display']);
    $this->assertStringContainsString('eb_read_schema for the target type and bundle', $schema);

    $other = $this->runTool('page_read', ['entity_type' => 'entity_view_mode', 'entity_id' => 'entity_test.teaser']);
    $this->assertStringContainsString('templates (entity_view_display', $other, 'A config type that hosts nothing is refused with the host types named.');
  }

  /**
   * Visibility follows the document's kind: a hidden bundle hides its templates.
   */
  public function testHiddenTargetBundleHidesTemplate(): void {
    $this->config('daedalus_blueprint_ai.settings')
      ->set('hidden_bundles', ['entity_test' => ['entity_test']])
      ->save();
    $this->assertSame('Unknown or hidden bundle: entity_test/entity_test.', $this->runTool('page_read', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
    ]));
    $this->assertSame('Unknown or hidden bundle: entity_test/entity_test.', $this->runTool('page_edit', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
      'ops' => [['op' => 'remove', 'ref' => 'band']],
    ]));
  }

  /**
   * A page rendering its template reads with its template elements marked.
   *
   * On a plain host (the bundle's display not overridable, so the page owns
   * only its fields) page_read heads the print with where the layout is
   * edited and prints data-can on every template element: "" on the
   * container and the plugin block (nothing may be done to them here),
   * "content" on the field block presenting the page's own field.
   */
  public function testPlainHostPageReadMarksTemplateElements(): void {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $field_block_uuid = $uuids->generate();
    $template = $this->createSection('eb_test_container', [
      ['uuid' => $block_uuid, 'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block']],
      ['uuid' => $field_block_uuid, 'configuration' => ['id' => self::FIELD_BLOCK, 'label' => 'Name']],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID)
      ->setOverridable(FALSE)
      ->removeAllSections()
      ->appendSection($template)
      ->save();
    $page = $this->createTestEntity(['name' => 'Plain page']);
    $this->assertTrue($this->host($page)->hasDocument(), 'The locked bundle keeps the override field through the lock: the page owns what it holds there, nothing here.');
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($this->host($page)), 'The page renders its template.');
    $this->assertSame([], $this->host($page)->ownSections(), 'A locked page is never seeded.');

    $output = $this->runTool('page_read', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $page->id(),
    ]);
    $this->assertStringNotContainsString('Error', $output);
    $notice = 'The layout of this entity_test belongs to the template entity_view_display ' . self::DISPLAY_ID . ', which it renders but does not own. Edit this entity_test\'s own fields here; edit its layout on the template: run the operation against entity_view_display ' . self::DISPLAY_ID . '.';
    $this->assertStringStartsWith($notice . "\nfields:\n", $output, 'The read says where the layout is edited, then answers the page\'s own fields.');
    $this->assertStringContainsString("\n  name: 'Plain page'\n", $output, 'The fields block carries the page\'s own field value.');
    $this->assertMatchesRegularExpression('/\nfields:\n(  .*\n)+<pb-container id="' . preg_quote($section_uuid, '/') . '"/', $output, 'The fields block sits between the notice and the print.');
    $this->assertStringNotContainsString('_form_only', $output, 'Empty fields are stubs, and stubs are stripped.');
    $this->assertStringContainsString('<pb-container id="' . $section_uuid . '" data-can="">', $output, 'The template container is marked: nothing may be done to it on this page.');
    $this->assertStringContainsString('<pb-block id="' . $block_uuid . '" data-can="" plugin="system_powered_by_block" />', $output, 'The template block is marked.');
    $this->assertStringContainsString('<pb-block id="' . $field_block_uuid . '" data-can="content" field="name" />', $output, 'A field block presenting the page\'s own field keeps content, and prints the field it names.');

    // The template itself reads unmarked (its elements are its own) and
    // with no fields block: a template has no fields of its own.
    $output = $this->runTool('page_read', [
      'entity_type' => 'entity_view_display',
      'entity_id' => self::DISPLAY_ID,
    ]);
    $this->assertStringNotContainsString('data-can', $output);
    $this->assertStringNotContainsString('fields:', $output, 'A template answers no fields.');
    $this->assertStringStartsWith('<pb-container id="' . $section_uuid . '">', $output);
  }

  /**
   * A display without Layout Builder is config, not a template.
   */
  public function testDisplayWithoutLayoutBuilderIsConfigNotTemplate(): void {
    $output = $this->runTool('page_read', [
      'entity_type' => 'entity_view_display',
      'entity_id' => 'entity_test.entity_test.teaser',
    ]);
    $this->assertStringContainsString('is not a template', $output);
    $this->assertStringContainsString('Layout Builder is not enabled', $output);
  }

}
