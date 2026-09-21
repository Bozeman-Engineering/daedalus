<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_formatted_text_block\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Render\RenderContext;
use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Serialization\Json;
use Drupal\daedalus_edit_formatted_text_block\Hook\FormattedTextBlockRequirements;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the formatted text block's composed render (one div root).
 *
 * Renders the shared composed_block element directly with a hand-built
 * hoisted bag: the generative mechanics (tree building, hoisting, the
 * event mount) are pinned by daedalus_ui's ComposedBlockRenderTest;
 * what this covers is THIS bundle's declaration and content provider —
 * the plain div root, the FILTERED (processed) output inside it (the raw
 * {value, format} pair never reaches visitor markup), and the
 * edit-capable CKEditor binding: editor identity, the stamped format and
 * the user's editor-configured formats, and the max-age 0 that keeps the
 * per-session editor settings out of shared caches. Also the module's
 * status report entry for the shipped format its field stamps.
 *
 * @group daedalus_edit_formatted_text_block
 */
#[RunTestsInSeparateProcesses]
class FormattedTextBlockComposedRenderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and
   * must be listed explicitly or the container fails to compile.
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
    'ckeditor5',
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
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    // The shipped block type carries an daedalus_layout third-party palette icon,
    // so installing the module's config needs daedalus_layout's TPS schema.
    'daedalus_layout',
    'daedalus_edit_formatted_text_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installConfig(['filter', 'daedalus_edit_formatted_text_block']);

    // A restricted format proving the render is the FILTERED output, with
    // a CKEditor5 editor so the format lists in the editor settings.
    FilterFormat::create([
      'format' => 'restricted',
      'name' => 'Restricted',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          // The class attribute rides every allowed vocabulary tag:
          // identity minting requires it or minted ids are stripped.
          'settings' => ['allowed_html' => '<p class> <strong>'],
        ],
      ],
    ])->save();
    $this->container->get('entity_type.manager')->getStorage('editor')->create([
      'format' => 'restricted',
      'editor' => 'ckeditor5',
      'settings' => ['toolbar' => ['items' => ['bold']]],
      'image_upload' => ['status' => FALSE],
    ])->save();
    $this->setUpCurrentUser([], ['use text format restricted']);
  }

  /**
   * Creates a formatted text block.
   */
  protected function createBlock(array $values = []): BlockContent {
    $block = BlockContent::create($values + [
      'type' => 'formatted_text',
      'info' => 'Formatted text block',
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
   * The div root prints the filtered output; the raw pair never escapes.
   */
  public function testFilteredOutputInDivRoot(): void {
    $block = $this->createBlock([
      'field_formatted_text_text' => [
        'value' => '<p>Kept</p><script>alert("dropped")</script>',
        'format' => 'restricted',
      ],
    ]);

    $html = trim($this->renderComposed($block));
    $this->assertStringStartsWith('<div', $html, 'The root is the declared plain div.');
    $this->assertStringEndsWith('</div>', $html);
    // The save-time identity minter stamps its .s-* class into the value,
    // so the paragraph renders minted.
    $this->assertMatchesRegularExpression('/<p[^>]*>Kept<\/p>/', $html, 'The filtered output renders.');
    $this->assertStringNotContainsString('<script', $html, 'The filter pipeline ran: disallowed markup never reaches the page.');
    $this->assertStringNotContainsString('data-daedalus-edit-editor', $html, 'Live visitor markup carries no editor attribution.');
  }

  /**
   * Edit-capable: the CKEditor binding rides the root.
   */
  public function testEditorBinding(): void {
    $block = $this->createBlock([
      'field_formatted_text_text' => [
        'value' => '<p>Editable</p>',
        'format' => 'restricted',
      ],
    ]);

    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<div[^>]*data-layout-builder-block-uuid="11111111-1111-1111-1111-111111111111"/', $html, 'The bag hoists onto the div root.');
    $this->assertStringContainsString('data-daedalus-edit-editor="formatted_text"', $html);
    $this->assertSame(1, preg_match('/data-daedalus-edit-editor-settings="([^"]+)"/', $html, $matches));
    $settings = Json::decode(html_entity_decode($matches[1], ENT_QUOTES));
    $this->assertSame('restricted', $settings['format'], 'The stored format is the stamped identity.');
    $this->assertArrayHasKey('restricted', $settings['formats'], 'Editor-configured formats the user may use are offered.');

    // The editor settings carry per-session CSRF'd URLs, so an
    // edit-capable render must never be served from a shared cache.
    $build = [
      '#type' => 'composed_block',
      '#block_content' => $block,
      '#hoisted_attributes' => $this->editBag(),
    ];
    $context = new RenderContext();
    $this->container->get('renderer')->executeInRenderContext($context, fn () => $this->container->get('renderer')->render($build));
    $this->assertSame(0, $context->pop()->getCacheMaxAge(), 'The edit-capable render bubbles max-age 0.');
  }

  /**
   * The status report flags the shipped format once it is disabled.
   *
   * Invoked through the hook system, not the class: a #[Hook] the container
   * never registered would pass a direct call and still be invisible to
   * the site (the procedural hook_requirements() this replaced was such a
   * ghost, skipped by #[LegacyRequirementsHook] on the module's core floor).
   */
  public function testRuntimeRequirementFlagsDisabledFormat(): void {
    $invoke = fn (): array => $this->container->get('module_handler')->invoke('daedalus_edit_formatted_text_block', 'runtime_requirements') ?? [];
    $this->assertSame([], $invoke(), 'The shipped format installed and enabled raises nothing.');

    $format = FilterFormat::load(FormattedTextBlockRequirements::FORMAT);
    $format->disable()->save();
    $requirements = $invoke();
    $this->assertArrayHasKey('daedalus_edit_formatted_text_block_text_format', $requirements);
    $entry = $requirements['daedalus_edit_formatted_text_block_text_format'];
    $this->assertSame(RequirementSeverity::Error, $entry['severity']);
    $this->assertSame(FormattedTextBlockRequirements::FORMAT, $entry['value']);
    $this->assertStringContainsString('missing or disabled', (string) $entry['description']);

    $format->enable()->save();
    $this->assertSame([], $invoke(), 'Re-enabling the format clears the entry.');
  }

}
