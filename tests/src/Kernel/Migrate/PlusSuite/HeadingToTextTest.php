<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel\Migrate\PlusSuite;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus\Migrate\PlusSuite\HeadingToText;
use Drupal\daedalus\Migrate\PlusSuite\HeadingTransform;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content phase over a translated site carrying old headings.
 *
 * The site after the rename, `drush updb` and the migration recipe, in
 * miniature: the engine and the text block module installed, the old
 * heading block type still present with its fieldless blocks, and a landing
 * page whose layout carries two heading components in the latest old
 * release's shape, one linked at the top level and one centered inside a
 * nested section. After the phase both are text components over saved text
 * blocks with the expected field values, the old blocks and their type
 * remain, and a second run changes nothing.
 *
 * @group daedalus
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
final class HeadingToTextTest extends KernelTestBase {

  use OldSuiteFixtureTrait;

  private const LINKED = '11111111-1111-1111-1111-111111111111';
  private const CENTERED = '33333333-3333-3333-3333-333333333333';
  private const POWERED = '22222222-2222-2222-2222-222222222222';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'editor',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'path',
    'node',
    'twig_events',
    'field_sample_value',
    'daedalus',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_edit',
    'daedalus_layout',
    'daedalus_edit_text_block',
  ];

  /**
   * The landing page carrying the headings.
   */
  private Node $node;

  /**
   * The old heading blocks the components referenced, by component uuid.
   *
   * @var array<string, \Drupal\block_content\Entity\BlockContent>
   */
  private array $headings = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['filter', 'daedalus_edit_text_block']);

    BlockContentType::create(['id' => 'heading', 'label' => 'Heading'])->save();
    NodeType::create(['type' => 'landing_page', 'name' => 'Landing page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'landing_page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $linked = $this->oldHeading(self::LINKED, 'Read the articles', [
      'link_enabled' => 1,
      'url' => '/articles',
      'title_size' => 'text-jumbo1',
      'horizontal_alignment' => 'text-left',
      // A key the old suite did not own (a site's own block property).
      'animation' => 'fade',
    ]);
    $centered = $this->oldHeading(self::CENTERED, 'Nested and centered', [
      'title_size' => 'h3',
      'horizontal_alignment' => 'text-center',
    ]);
    $inner = new Section('layout_onecol', [], [$centered], [
      'daedalus_layout' => ['uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
    ]);
    $first = new Section('layout_onecol', [], [
      $linked,
      new SectionComponent(self::POWERED, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']]);
    $second = new Section('layout_onecol', [], [SectionBlock::createComponent($inner, 'content')], [
      'daedalus_layout' => ['uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc'],
    ]);
    $this->node = Node::create([
      'type' => 'landing_page',
      'title' => 'Home',
      OverridesSectionStorage::FIELD_NAME => [['section' => $first], ['section' => $second]],
    ]);
    $this->node->save();
  }

  /**
   * An old heading component over a saved, fieldless heading block.
   */
  private function oldHeading(string $uuid, string $label, array $configuration): SectionComponent {
    $block = BlockContent::create(['type' => 'heading', 'info' => $label, 'reusable' => FALSE]);
    $block->save();
    $this->headings[$uuid] = $block;
    return $this->heading($uuid, $label, $configuration + [
      'provider' => 'layout_builder',
      'view_mode' => 'full',
      'block_id' => $block->id(),
      'block_revision_id' => $block->getRevisionId(),
      'block_serialized' => NULL,
      'context_mapping' => [],
    ]);
  }

  /**
   * The components of the node's stored layout, by uuid, nested included.
   *
   * @return array<string, \Drupal\layout_builder\SectionComponent>
   *   The components.
   */
  private function storedComponents(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $components = [];
    $collect = static function (Section $section) use (&$collect, &$components): void {
      foreach ($section->getComponents() as $component) {
        $components[$component->getUuid()] = $component;
        $embedded = SectionBlock::embeddedSection($component);
        if ($embedded) {
          $collect($embedded);
        }
      }
    };
    $field = $storage->load($this->node->id())->get(OverridesSectionStorage::FIELD_NAME);
    $this->assertInstanceOf(SectionListInterface::class, $field);
    foreach ($field->getSections() as $section) {
      $collect($section);
    }
    return $components;
  }

  /**
   * The node's stored layout rows, as serialized.
   *
   * @return list<string>
   *   The section column of the data table, in delta order.
   */
  private function storedRows(): array {
    return $this->container->get('database')
      ->select('node__layout_builder__layout', 't')
      ->fields('t', ['layout_builder__layout_section'])
      ->condition('entity_id', $this->node->id())
      ->orderBy('delta')
      ->execute()
      ->fetchCol();
  }

  /**
   * The text block a component references, loaded fresh.
   */
  private function textBlock(SectionComponent $component): BlockContent {
    $configuration = $component->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id']);
    $this->assertNull($configuration['block_serialized']);
    $block = $this->container->get('entity_type.manager')->getStorage('block_content')->loadRevision($configuration['block_revision_id']);
    $this->assertInstanceOf(BlockContent::class, $block);
    $this->assertSame('text', $block->bundle());
    $this->assertFalse($block->isReusable());
    return $block;
  }

  /**
   * Both headings become text components over saved text blocks.
   */
  public function testHeadingsBecomeTextBlocks(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $this->assertSame(2, $storage->getQuery()->accessCheck(FALSE)->count()->execute());

    $report = HeadingToText::create($this->container)->run();
    $this->assertTrue($report->ok(), $report->summary());
    $this->assertNull($report->refusal);
    $this->assertNull($report->failure);
    $this->assertSame(['node'], array_keys($report->hosts));
    $this->assertSame(1, $report->hosts['node']['hosts']);
    $this->assertSame(1, $report->hosts['node']['saved']);
    $this->assertSame(2, $report->hosts['node']['components']);
    $this->assertSame(['node 1 (Home): 2'], $report->hosts['node']['items']);
    // The size class is dropped where it was not the tag; the alignment
    // always is.
    $this->assertSame([
      'title_size' => ['text-jumbo1' => 1],
      'horizontal_alignment' => ['text-left' => 1, 'text-center' => 1],
    ], $report->dropped);
    $this->assertSame([], $report->left);
    $this->assertSame('content: 2 heading components became text blocks on 1 of 1 node hosts; dropped title_size 1 (text-jumbo1 1), horizontal_alignment 2 (text-left 1, text-center 1).', $report->summary());
    $this->assertStringContainsString("  node: 2 components rewritten on 1 of 1 hosts\n    node 1 (Home): 2\n", $report->render());

    $components = $this->storedComponents();
    $this->assertSame([self::LINKED, self::POWERED, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', self::CENTERED], array_keys($components));
    $this->assertSame('system_powered_by_block', $components[self::POWERED]->getPluginId(), 'The unrelated component is untouched.');

    // The linked heading: tag from label_tag (its title_size is a size
    // class), the url as an internal uri, the site's own key kept.
    $linked = $components[self::LINKED]->get('configuration');
    $this->assertSame(HeadingTransform::TEXT, $linked['id']);
    $this->assertSame('Read the articles', $linked['label']);
    $this->assertSame('0', $linked['label_display']);
    $this->assertSame('full', $linked['view_mode']);
    $this->assertSame('fade', $linked['animation']);
    foreach (['title_size', 'label_tag', 'horizontal_alignment', 'link_enabled', 'url'] as $key) {
      $this->assertArrayNotHasKey($key, $linked);
    }
    $block = $this->textBlock($components[self::LINKED]);
    $this->assertSame('Read the articles', $block->label());
    $this->assertSame('Read the articles', $block->get('field_text_text')->value);
    $this->assertSame('h2', $block->get('field_text_tag')->value);
    $this->assertSame('internal:/articles', $block->get('field_text_url')->uri);

    // The nested heading: tag from title_size (an allowed value), no link.
    $centered = $components[self::CENTERED]->get('configuration');
    $this->assertSame(HeadingTransform::TEXT, $centered['id']);
    $this->assertSame('Nested and centered', $centered['label']);
    $this->assertArrayNotHasKey('horizontal_alignment', $centered);
    $block = $this->textBlock($components[self::CENTERED]);
    $this->assertSame('Nested and centered', $block->get('field_text_text')->value);
    $this->assertSame('h3', $block->get('field_text_tag')->value);
    $this->assertTrue($block->get('field_text_url')->isEmpty());

    // The new blocks are tracked as the node's, so the unused-block garbage
    // collection never takes them; the old heading blocks and their type
    // remain for the older revisions.
    $usage = $this->container->get('inline_block.usage');
    foreach ([self::LINKED, self::CENTERED] as $uuid) {
      $record = $usage->getUsage($this->textBlock($components[$uuid])->id());
      $this->assertSame(['node', $this->node->id()], $record ? [$record->layout_entity_type, $record->layout_entity_id] : []);
    }
    $this->assertSame(4, $storage->getQuery()->accessCheck(FALSE)->count()->execute());
    $this->assertNotNull(BlockContentType::load('heading'));
    foreach ($this->headings as $heading) {
      $this->assertNotNull($storage->loadUnchanged($heading->id()));
    }
    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertSame(1, $node_storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $this->node->id())->count()->execute(), 'The default revision was rewritten in place.');

    // A second run finds nothing and changes nothing.
    $rows = $this->storedRows();
    $report = HeadingToText::create($this->container)->run();
    $this->assertTrue($report->ok(), $report->summary());
    $this->assertSame('content: 0 heading components became text blocks on 0 of 1 node hosts.', $report->summary());
    $this->assertSame([], $report->dropped);
    $this->assertSame($rows, $this->storedRows());
    $this->assertSame(4, $storage->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  /**
   * Without the text block type the phase refuses and touches nothing.
   */
  public function testRefusesWithoutTextType(): void {
    $rows = $this->storedRows();
    BlockContentType::load('text')->delete();
    $report = HeadingToText::create($this->container)->run();
    $this->assertFalse($report->ok());
    $this->assertSame('content: REFUSED. The text block type is not installed. Apply the migration recipe first (drush recipe <daedalus>/migrate), then run this phase again.', $report->summary());
    $this->assertStringStartsWith("Content: old heading components to text blocks\n\nREFUSED: The text block type is not installed.", $report->render());
    $this->assertSame($rows, $this->storedRows());
  }

}
