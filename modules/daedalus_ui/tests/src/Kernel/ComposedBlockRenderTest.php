<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block_content\BlockContentInterface;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;
use Drupal\layout_builder\SectionComponent;
use Drupal\Core\Render\Markup;
use Drupal\media\Entity\Media;
use Drupal\daedalus_ui\Render\ComposedBlockContentInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generative composed-block render (declaration as the render).
 *
 * Three halves, split where the mechanism splits:
 * - The GATE: ComposedBlockRender::onBuildRender() marks a build for the
 *   render-time swap exactly when the element model says the plugin's
 *   block type declares `composed: true`. Compose is a block-TYPE fact, so
 *   only inline_block plugin ids pass.
 * - The TREE: the shared composed_block element prints what the resolver
 *   computed — every root form of the mirrored image shape, the text
 *   shape's conditional anchor, part classes, hoisted classes and
 *   attribute overrides on the root, declared attributes, the mounting
 *   rule for provider children (claimed into the part, unclaimed at the
 *   root), and the provider's suppress answer.
 * - The MOUNT: a real inline_block section component renders with no
 *   sandwich at all — the composed root is the region's direct child,
 *   carrying the hoisted editing attributes in edit mode and nothing but
 *   markup live.
 *
 * The declarations come from daedalus_ui_resolver_test (the mirrored
 * shipped shapes), so this lock does not depend on daedalus_edit; the shipped
 * bundles' real content is each block module's own kernel coverage.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class ComposedBlockRenderTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * Navigation_plus's hard dependencies (daedalus_blueprint +
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
    'daedalus_ui_resolver_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('block_content');
    $this->installConfig(['system']);

    BlockContentType::create(['id' => 'text', 'label' => 'Text'])->save();
    BlockContentType::create(['id' => 'image', 'label' => 'Image'])->save();
    $fields = [
      'text' => [
        'field_text_text' => ['type' => 'string_long'],
        'field_text_tag' => ['type' => 'string'],
        'field_text_url' => ['type' => 'link'],
      ],
      'image' => [
        'field_image' => [
          'type' => 'entity_reference',
          'settings' => ['target_type' => 'media'],
        ],
        'field_image_url' => ['type' => 'link'],
      ],
    ];
    foreach ($fields as $bundle => $definitions) {
      foreach ($definitions as $field_name => $storage) {
        FieldStorageConfig::create($storage + [
          'field_name' => $field_name,
          'entity_type' => 'block_content',
        ])->save();
        FieldConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'block_content',
          'bundle' => $bundle,
        ])->save();
      }
    }
  }

  /**
   * Registers a content provider on the collected renderer.
   */
  protected function addProvider(string $bundle, callable $content): void {
    $provider = new class($bundle, $content) implements ComposedBlockContentInterface {

      public function __construct(
        private readonly string $bundle,
        private $callable,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function blockType(): string {
        return $this->bundle;
      }

      /**
       * {@inheritdoc}
       */
      public function content(BlockContentInterface $block_content, array $resolved, bool $edit_capable): array {
        return ($this->callable)($block_content, $resolved, $edit_capable);
      }

    };
    $this->container->get('daedalus_ui.composed_block_renderer')->addContentProvider($provider);
  }

  /**
   * Renders the composed element for a block with a hoisted bag.
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
      'data-selectable' => 'block:11111111-1111-1111-1111-111111111111',
    ];
  }

  /**
   * Builds the section-component event for a plugin id.
   */
  protected function buildRenderEvent(string $plugin_id): SectionComponentBuildRenderArrayEvent {
    $component = new SectionComponent('10000000-0000-0000-0000-000000000000', 'content', ['id' => $plugin_id]);
    $event = new SectionComponentBuildRenderArrayEvent($component, []);
    $this->assertSame($plugin_id, $event->getPlugin()->getPluginId());
    $event->setBuild(['content' => ['#markup' => 'x']]);
    return $event;
  }

  /**
   * The gate: a declared block TYPE marks its inline blocks, nothing else.
   */
  public function testDeclaredBlockTypeGate(): void {
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic'])->save();

    /** @var \Drupal\daedalus_ui\EventSubscriber\ComposedBlockRender $subscriber */
    $subscriber = $this->container->get('daedalus_ui.event_subscriber.composed_block_render');

    $declared = $this->buildRenderEvent('inline_block:text');
    $subscriber->onBuildRender($declared);
    $this->assertContains(
      ['Drupal\daedalus_ui\EventSubscriber\ComposedBlockRender', 'preRenderComposed'],
      $declared->getBuild()['#pre_render'] ?? [],
      'A composed block type marks its inline blocks for the render-time swap.',
    );

    $undeclared = $this->buildRenderEvent('inline_block:basic');
    $subscriber->onBuildRender($undeclared);
    $this->assertArrayNotHasKey('#pre_render', $undeclared->getBuild(), 'An undeclared block type keeps the sandwich.');

    $plugin = $this->buildRenderEvent('system_powered_by_block');
    $subscriber->onBuildRender($plugin);
    $this->assertArrayNotHasKey('#pre_render', $plugin->getBuild(), 'Plugin blocks have no compose declaration.');
  }

  /**
   * The text shape: data-decided tag root, conditional anchor, mounting.
   */
  public function testTextTree(): void {
    $this->addProvider('text', function (BlockContentInterface $block, array $resolved, bool $edit_capable): array {
      // The mounting rule resolves the editing attribute's element: the
      // claiming anchor while present, the root while not.
      $mount = $resolved['slots']['field:field_text_text'] ?? '';
      return [
        'children' => [
          'field:field_text_text' => ['#plain_text' => $block->get('field_text_text')->value ?? ''],
        ],
        'attributes' => [
          $mount => ['data-test-surface' => ''],
        ],
      ];
    });

    // Unlinked: the tag element is the root; the unclaimed text and the
    // surface attribute both land on it.
    $block = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_text' => 'Hello <world>',
      'field_text_tag' => 'h2',
    ]);
    $block->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<h2[^>]*data-layout-builder-block-uuid="11111111-1111-1111-1111-111111111111"/', $html, 'The tag root carries the hoisted bag.');
    $this->assertStringContainsString('data-test-surface', $html, 'The provider attribute rode the root: the unclaimed mount.');
    $this->assertStringContainsString('Hello &lt;world&gt;', $html, 'The text child mounted at the root, escaped.');
    $this->assertStringNotContainsString('<a', $html, 'No anchor exists unlinked.');
    $this->assertStringNotContainsString('data-claims-content', $html, 'No claiming element exists unlinked: no marker anywhere.');
    $this->assertStringEndsWith('</h2>', $html);

    // Linked: the anchor part renders inside the root wearing its part
    // class and declared href, claims the text, and the surface attribute
    // follows the mount onto it.
    $block->set('field_text_url', ['uri' => 'https://example.com/'])->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<h2[^>]*data-layout-builder-block-uuid=/', $html);
    $this->assertMatchesRegularExpression('/<a[^>]*class="[^"]*text-block-link/', $html, 'The anchor wears its part-key class.');
    $this->assertMatchesRegularExpression('/<a[^>]*href="https:\/\/example\.com\/"/', $html, 'The declared attribute resolved from the link field.');
    $this->assertMatchesRegularExpression('/<a[^>]*data-test-surface/', $html, 'The provider attribute followed the mount onto the claiming anchor.');
    $this->assertMatchesRegularExpression('/<a[^>]*>Hello/', $html, 'The claimed text mounted inside the anchor.');
    $this->assertMatchesRegularExpression('/^<h2(?![^>]*data-test-surface)/', $html, 'The root does not carry the mount-following attribute when the anchor claims it.');
    $this->assertMatchesRegularExpression('/<a[^>]*data-claims-content/', $html, 'The field-claiming anchor wears the content-claim marker in the composed render.');
    $this->assertMatchesRegularExpression('/^<h2(?![^>]*data-claims-content)/', $html, 'The root claims nothing; the marker rides the claiming interior element only.');

    // The marker is an anatomy fact of the markup itself, so LIVE markup
    // carries it too (the compiled stylesheets co-target it in every
    // mode), unlike the editing attributes.
    $live = trim($this->renderComposed($block));
    $this->assertStringNotContainsString('data-layout-builder-block-uuid', $live);
    $this->assertMatchesRegularExpression('/<a[^>]*data-claims-content/', $live, 'Live visitor markup carries the content-claim marker.');
  }

  /**
   * The image shape: every root form, hoisting, and suppression.
   */
  public function testImageForms(): void {
    $this->createMediaType('image', ['id' => 'image']);
    $this->container->get('file_system')->copy($this->root . '/core/tests/fixtures/files/image-test.png', 'public://image-test.png');
    $png = File::create(['uri' => 'public://image-test.png']);
    $png->save();
    $raster = Media::create([
      'bundle' => 'image',
      'name' => 'Raster',
      'field_media_image' => ['target_id' => $png->id(), 'alt' => 'A raster image'],
    ]);
    $raster->save();
    file_put_contents('public://icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $svg = File::create(['uri' => 'public://icon.svg']);
    $svg->save();
    $vector = Media::create([
      'bundle' => 'image',
      'name' => 'Vector',
      'field_media_image' => ['target_id' => $svg->id(), 'alt' => 'A vector image'],
    ]);
    $vector->save();

    $this->addProvider('image', function (BlockContentInterface $block, array $resolved, bool $edit_capable): array {
      $content = [];
      // The part key addresses the picture element whether it hoisted
      // (the root) or nests; its resolved tag names the media lane.
      $hoisted = in_array('.image-block-image', $resolved['hoisted'], TRUE);
      $tag = $hoisted ? $resolved['tag'] : ($resolved['parts']['.image-block-image']['tag'] ?? NULL);
      if ($tag === 'img') {
        $content['attributes']['.image-block-image'] = ['src' => '/test.png'];
      }
      elseif ($tag === 'svg') {
        // The inliner's contract: the file's attributes address the part
        // key, and the sanitized-at-source inner markup rides as a Markup
        // object under the claimed image slot.
        $content['attributes']['.image-block-image'] = ['viewBox' => '0 0 10 10'];
        $content['children']['field:field_image'] = ['#markup' => Markup::create('<rect data-test-inline/>')];
      }
      if ($block->get('field_image')->isEmpty()) {
        if (!$edit_capable) {
          $content['suppress'] = TRUE;
        }
        else {
          $content['children']['placeholder'] = ['#plain_text' => 'Add one'];
        }
      }
      return $content;
    });

    // Unlinked raster: the img form and the img part COINCIDE — one void
    // element as the root, wearing the hoisted part class, the provider's
    // part-addressed attributes, and the bag.
    $block = BlockContent::create(['type' => 'image', 'info' => 'Image', 'field_image' => $raster->id()]);
    $block->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<img[^>]*\/>$/', $html, 'The unlinked raster root is the one void img element.');
    $this->assertMatchesRegularExpression('/^<img[^>]*class="[^"]*image-block-image/', $html, 'The coinciding part hoisted its class onto the root.');
    $this->assertStringContainsString('src="/test.png"', $html, 'The part-addressed provider attributes landed on the hoisted root.');
    $this->assertStringContainsString('data-layout-builder-block-uuid=', $html);

    // Linked raster: the anchor form is the root; the img part nests
    // inside it with its class stamped literally and the provider
    // attributes on the part element, not the root.
    $block->set('field_image_url', ['uri' => 'https://example.com/'])->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<a[^>]*href="https:\/\/example\.com\/"/', $html, 'The matched anchor form carries its declared attribute.');
    $this->assertMatchesRegularExpression('/^<a[^>]*data-layout-builder-block-uuid=/', $html, 'The bag rides the anchor root.');
    $this->assertMatchesRegularExpression('/<img[^>]*class="[^"]*image-block-image/', $html, 'The nested img part wears its class.');
    $this->assertMatchesRegularExpression('/<img[^>]*src="\/test\.png"/', $html, 'The provider attributes address the nested part.');
    $this->assertMatchesRegularExpression('/^<a(?![^>]*image-block-image)/', $html, 'The anchor root does not wear the part class when the part nests.');
    $this->assertStringEndsWith('</a>', $html);

    // Unlinked vector: the svg form and the svg part form COINCIDE, the
    // raster shape one form over — the svg root wears the hoisted part
    // class, the part-addressed attributes, and the bag, and the inner
    // markup mounts at the root (the hoisted part claims nothing).
    $block->set('field_image_url', NULL)->set('field_image', $vector->id())->save();
    $block = BlockContent::load($block->id());
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<svg[^>]*data-layout-builder-block-uuid=/', $html);
    $this->assertMatchesRegularExpression('/^<svg[^>]*class="[^"]*image-block-image/', $html, 'The coinciding svg part hoisted its class onto the root.');
    $this->assertStringContainsString('viewBox="0 0 10 10"', $html, 'The part-addressed provider attributes landed on the hoisted root, case preserved.');
    $this->assertStringContainsString('<rect data-test-inline/>', $html, 'The inner markup mounted at the root.');
    $this->assertStringEndsWith('</svg>', $html);

    // Linked vector: the anchor form is the root and the svg part nests
    // inside it, wearing its class, the provider attributes, and the
    // content-claim marker; the claimed inner markup mounts in the part.
    $block->set('field_image_url', ['uri' => 'https://example.com/'])->save();
    $block = BlockContent::load($block->id());
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<a[^>]*href="https:\/\/example\.com\/"/', $html);
    $this->assertMatchesRegularExpression('/<svg[^>]*class="[^"]*image-block-image/', $html, 'The nested svg part wears its class.');
    $this->assertMatchesRegularExpression('/<svg[^>]*viewBox="0 0 10 10"/', $html, 'The provider attributes address the nested part.');
    $this->assertMatchesRegularExpression('/<svg[^>]*data-claims-content/', $html, 'The field-claiming svg part wears the content-claim marker.');
    $this->assertMatchesRegularExpression('/<svg[^>]*><rect data-test-inline\/><\/svg>/', $html, 'The claimed inner markup mounted inside the part.');
    $this->assertMatchesRegularExpression('/^<a(?![^>]*image-block-image)/', $html, 'The anchor root does not wear the part class when the part nests.');
    $this->assertStringEndsWith('</a>', $html);
    $block->set('field_image_url', NULL)->save();

    // Empty: the placeholder div form renders edit-capable, and the
    // provider's suppress answer renders NOTHING live — cache metadata
    // only.
    $block->set('field_image', NULL)->save();
    $block = BlockContent::load($block->id());
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<div[^>]*data-layout-builder-block-uuid=/', $html, 'The empty form renders the placeholder root edit-capable.');
    $this->assertStringContainsString('Add one', $html);
    $this->assertSame('', trim($this->renderComposed($block)), 'The suppressed live empty state renders nothing.');
  }

  /**
   * The mount: a real inline block renders with no sandwich at all.
   */
  public function testMountThroughEvent(): void {
    $block = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_text' => 'Cooperative content',
      'field_text_tag' => 'p',
    ]);
    $block->save();
    $this->addProvider('text', fn (BlockContentInterface $b): array => [
      'children' => ['field:field_text_text' => ['#plain_text' => $b->get('field_text_text')->value ?? '']],
    ]);

    $component = new SectionComponent('20000000-0000-0000-0000-000000000000', 'content', [
      'id' => 'inline_block:text',
      'block_revision_id' => $block->getRevisionId(),
    ]);

    // Edit-capable: the editing attributes hoist onto the composed root;
    // the sandwich-only stamps are dropped with the sandwich.
    $this->setUpCurrentUser([], ['use daedalus ui edit mode']);
    $build = $component->toRenderArray();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Cooperative content', $html);
    $this->assertStringContainsString('<p data-layout-builder-block-uuid="20000000-0000-0000-0000-000000000000"', $html, 'The composed root is the block: the bag rides the tag element.');
    $this->assertStringContainsString('data-selectable="block:20000000-0000-0000-0000-000000000000"', $html);
    $this->assertStringNotContainsString('<div', $html, 'No sandwich renders at all.');
    $this->assertStringNotContainsString('data-block-type', $html);
    $this->assertStringNotContainsString('data-layout-builder-region', $html);
    $this->assertStringNotContainsString('data-layout-builder-highlight-id', $html);

    // Live: clean markup, no editing attributes (nothing demanded the
    // selection identity).
    $this->setUpCurrentUser();
    $build = $component->toRenderArray();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Cooperative content', $html);
    $this->assertStringNotContainsString('<div', $html);
    $this->assertStringNotContainsString('data-selectable', $html);
    $this->assertStringNotContainsString('data-layout-builder-block-uuid', $html);
  }

}
