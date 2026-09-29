<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_text_block\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Serialization\Json;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the text block's composed render (one tag root, generative).
 *
 * Renders the shared composed_block element directly with a hand-built
 * hoisted bag: the generative mechanics (tree building, hoisting, the
 * event mount) are pinned by daedalus_ui's ComposedBlockRenderTest;
 * what this covers is THIS bundle's declaration and content provider:
 * the data-decided tag root with its declared p fallback, the conditional
 * anchor part carrying the stable text-block-link class and the href in
 * the linked shape, the typing-surface marker riding the DECLARED mount
 * element (the root unlinked, the anchor linked; no editing-only span),
 * the escaped text, and the edit-capable editor attribution. It also pins
 * the bundle's placement contract: a text block placed with sample
 * population is an unlinked paragraph.
 *
 * @group daedalus_edit_text_block
 */
#[RunTestsInSeparateProcesses]
class TextBlockComposedRenderTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and
   * must be listed explicitly or the container fails to compile.
   */
  protected static $modules = [
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
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    // The shipped block type carries a daedalus_layout third-party palette
    // icon, so installing the module's config needs daedalus_layout's TPS
    // schema.
    'daedalus_layout',
    'daedalus_edit_text_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The Layout Builder host for placement (installs block_content's schema
    // before the bundle's field config lands on it).
    $this->enableLayoutBuilder();
    $this->installConfig(['daedalus_edit_text_block']);
  }

  /**
   * Creates a text block.
   */
  protected function createBlock(array $values = []): BlockContent {
    $block = BlockContent::create($values + [
      'type' => 'text',
      'info' => 'Text block',
    ]);
    $block->save();
    return $block;
  }

  /**
   * Renders the composed element with a hand-built hoisted bag.
   */
  protected function renderComposed(BlockContent $block, array $bag = []): string {
    $build = [
      '#type' => 'composed_block',
      '#block_content' => $block,
      '#hoisted_attributes' => $bag,
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * The edit-capable hoisted bag (the component uuid is the tell).
   */
  protected function editBag(): array {
    return [
      'data-layout-builder-block-uuid' => '11111111-1111-1111-1111-111111111111',
    ];
  }

  /**
   * Unlinked: the tag element is the root and the typing surface.
   */
  public function testUnlinkedRoot(): void {
    $block = $this->createBlock([
      'field_text_text' => 'Hello <world>',
      'field_text_tag' => 'h2',
    ]);

    // A live render: clean markup, no editor attribution; the
    // typing-surface marker is baked into the composed markup in both
    // render modes (inert live), exactly as it always was.
    $html = trim($this->renderComposed($block));
    $this->assertMatchesRegularExpression('/^<h2[^>]*data-daedalus-edit-text[^>]*>Hello &lt;world&gt;<\/h2>$/', $html, 'The tag root is the typing surface and prints the escaped text.');
    $this->assertStringNotContainsString('data-daedalus-edit-editor', $html);
    $this->assertStringNotContainsString('<a', $html, 'No anchor exists unlinked.');
    $this->assertStringNotContainsString('<span', $html, 'No editing-only span exists.');

    // Edit-capable: the bag hoists onto the root and the editor
    // attribution (identity and settings, never values) rides beside it.
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<h2[^>]*data-layout-builder-block-uuid="11111111-1111-1111-1111-111111111111"/', $html);
    $this->assertStringContainsString('data-daedalus-edit-editor="text_composite"', $html);
    $this->assertSame(1, preg_match('/data-daedalus-edit-editor-settings="([^"]+)"/', $html, $matches));
    $settings = Json::decode(html_entity_decode($matches[1], ENT_QUOTES));
    $this->assertArrayHasKey('h2', $settings['tag_allowed_values'], 'The editor settings carry the tag field\'s allowed values.');
    $this->assertArrayHasKey('p', $settings['tag_allowed_values']);
    // A text block may be the page's one h1 (the theme's title does not
    // own the tag), and the ladder reads in order so the picker and the
    // AI catalog offer h1 first.
    $this->assertSame('h1', array_key_first($settings['tag_allowed_values']), 'The shipped tag field allows h1, first in the ladder.');
  }

  /**
   * Linked: the anchor nests inside the root and is the typing surface.
   */
  public function testLinkedShape(): void {
    $block = $this->createBlock([
      'field_text_text' => 'Hello',
      'field_text_tag' => 'h3',
      'field_text_url' => ['uri' => 'https://example.com/'],
    ]);

    $html = trim($this->renderComposed($block, $this->editBag()));
    // The root is ALWAYS the tag element; the anchor nests INSIDE it (a
    // linked heading's natural HTML shape) so linked and unlinked blocks
    // render with identical typography.
    $this->assertMatchesRegularExpression('/^<h3[^>]*data-layout-builder-block-uuid=/', $html, 'The tag root carries the hoisted bag, linked or not.');
    $this->assertMatchesRegularExpression('/<a[^>]*class="[^"]*\btext-block-link\b/', $html, 'The anchor wears its stable part class.');
    $this->assertMatchesRegularExpression('/<a[^>]*href="https:\/\/example\.com\/"/', $html, 'The anchor carries the url field as href.');
    // The typing surface rides the DECLARED element the text mounts in:
    // the anchor claims the text, so the marker follows it (no span).
    $this->assertMatchesRegularExpression('/<a[^>]*data-daedalus-edit-text[^>]*>Hello<\/a>/', $html, 'The anchor is the typing surface and holds the claimed text.');
    $this->assertMatchesRegularExpression('/^<h3(?![^>]*data-daedalus-edit-text)/', $html, 'The root cedes the typing surface to the anchor.');
    $this->assertStringNotContainsString('<span', $html, 'No editing-only span exists.');
    $this->assertStringEndsWith('</h3>', $html);
  }

  /**
   * The declared fallback: a tag field with no value renders a paragraph.
   *
   * The tag field ships a p default, so a block created without the key
   * would carry p as data; passing NULL explicitly skips the default and
   * exercises the residual form.
   */
  public function testTagFallback(): void {
    $block = $this->createBlock(['field_text_text' => 'Plain', 'field_text_tag' => NULL]);
    $this->assertTrue($block->get('field_text_tag')->isEmpty(), 'The fixture holds no tag value.');
    $html = trim($this->renderComposed($block));
    $this->assertMatchesRegularExpression('/^<p[^>]*>Plain<\/p>$/', $html, 'The residual root form carries the p fallback as declared data, not template code.');
  }

  /**
   * Placement: a text block dropped with sample population is an unlinked p.
   *
   * The sample ladder's placement entrance fills a field from its default
   * value, else its configured sample policy, else nothing: the tag field's
   * p default answers, field_text_text's random_string policy answers, and
   * the policy-less url field stays empty. Core's type-level sample (a random
   * tag from the allowed values, a fabricated external link) is not a
   * placement rung, so the render is a paragraph with no anchor.
   */
  public function testPlacementIsUnlinkedParagraph(): void {
    $host = $this->createTestEntity([
      'name' => 'Text block placement',
      'layout_builder__layout' => [
        $this->createSection('layout_onecol', [
          [
            'uuid' => '7a3f08ce-2f70-4c98-8f5b-3d1f6a9be0c4',
            'region' => 'content',
            'weight' => 0,
            'configuration' => [
              'id' => 'system_powered_by_block',
              'provider' => 'system',
              'label' => 'Anchor seed',
              'label_display' => '0',
            ],
          ],
        ]),
      ],
    ]);

    $result = $this->container->get('daedalus_blueprint.operations')->addElement($host, [
      'anchor' => '7a3f08ce-2f70-4c98-8f5b-3d1f6a9be0c4',
      'placement' => 'after',
    ], [
      'block_type' => 'text',
      'uuid' => 'f0f45ea4-c594-783b-9ccb-94c896ee4891',
      'populate' => 'sample',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $components = $result->getEntity()->get('layout_builder__layout')->getSection(0)->getComponents();
    $block = $this->unserializeBlock($components['f0f45ea4-c594-783b-9ccb-94c896ee4891']->get('configuration')['block_serialized']);
    $this->assertSame('p', $block->get('field_text_tag')->value, 'The tag field\'s p default is the site\'s answer on placement.');
    $this->assertTrue($block->get('field_text_url')->isEmpty(), 'The policy-less url field is left empty: core\'s random link is not a placement rung.');
    $this->assertNotSame('', (string) $block->get('field_text_text')->value, 'The text field\'s configured random_string policy fills it.');

    $html = trim($this->renderComposed($block));
    $this->assertMatchesRegularExpression('/^<p[^>]*>.+<\/p>$/s', $html, 'A placed text block renders as a paragraph.');
    $this->assertStringNotContainsString('<a', $html, 'A placed text block carries no anchor.');
  }

}
