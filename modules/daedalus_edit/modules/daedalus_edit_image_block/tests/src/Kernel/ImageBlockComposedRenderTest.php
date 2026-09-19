<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_image_block\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_edit\DaedalusEditMediaLibraryOpener;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests the image block's composed render (one img/anchor/placeholder root).
 *
 * Renders the shared composed_block element directly with a hand-built
 * hoisted bag: the generative mechanics (tree building, hoisting, the
 * event mount) are pinned by daedalus_ui's ComposedBlockRenderTest;
 * what this covers is THIS bundle's declaration and content provider —
 * root shape per state (img, a>img, svg, a>svg, placeholder div,
 * nothing), the server-derived src, the inliner's svg contract (the
 * allowlisted attribute bag on the modeled svg, the sanitized inner
 * markup, the <image href> failure lane), the stable image-block-image
 * part class per state (hoisted onto the picture root, img and svg
 * alike; literal when linked; absent on the placeholder), and the widget
 * tier gate — plus the style guide serving this module's part/controls
 * declaration and the declared defaults it ships (the box facts the
 * markup needs) reaching the compiled defaults stylesheet and stamping
 * the rendered root.
 *
 * @group daedalus_edit_image_block
 */
#[RunTestsInSeparateProcesses]
class ImageBlockComposedRenderTest extends KernelTestBase {

  use MediaTypeCreationTrait;
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
    'link',
    'options',
    'editor',
    'file',
    'image',
    'views',
    'media',
    'media_library',
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
    'svg_image_field',
    // Daedalus Stylish consumes the block type's page_builder declaration (the
    // .image-block-image part, its element-bound controls, and its
    // declared defaults); enabling it lets the guide-serving test read the
    // declaration exactly as every styling surface does and the delivery
    // test compile it. Kernel installs run no install hooks, so the render
    // tests see no behavior change from its presence beyond the stamps.
    'daedalus_stylish',
    'daedalus_edit_image_block',
    'entity_test',
  ];

  /**
   * The image media entity referenced by the block.
   */
  protected Media $media;

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
    // This module DECLARES defaults, and every defaults compile builds the
    // style guide, which validates them through a validator whose materials
    // roster reads all four daedalus_stylish storages. Compiles fire on config saves
    // (system.theme below, daedalus_stylish.settings in the guide test), so the
    // schemas must exist before the first such save.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The composed render derives its src through an image style, and
    // derivative generation resolves the default toolkit from system.image,
    // which only exists once system's shipped config is installed.
    $this->installConfig(['system']);

    // The media types must exist before the module's shipped field config
    // (which depends on media.type.image and media.type.vector_image)
    // installs. Real sites get them from the daedalus_media_image and
    // daedalus_media_vector ingredients (or core's image_media_type
    // recipe plus svg_image_field_media_bundle).
    $this->createMediaType('image', ['id' => 'image']);
    $this->createMediaType('svg', ['id' => 'vector_image']);
    $this->installConfig(['daedalus_edit_image_block']);

    // Mirror a configured media display: the composed render must honor the
    // image style and loading attribute set on the media bundle's default
    // display, exactly like the formatter chain it replaces.
    ImageStyle::create(['name' => 'test_style', 'label' => 'Test style'])->save();
    $this->container->get('entity_display.repository')->getViewDisplay('media', 'image')
      ->setComponent('field_media_image', [
        'type' => 'image',
        'settings' => [
          'image_style' => 'test_style',
          'image_link' => '',
          'image_loading' => ['attribute' => 'lazy'],
        ],
      ])
      ->save();

    $this->container->get('file_system')->copy($this->root . '/core/tests/fixtures/files/image-test.png', 'public://image-test.png');
    $file = File::create(['uri' => 'public://image-test.png']);
    $file->save();
    $this->media = Media::create([
      'bundle' => 'image',
      'name' => 'Test image',
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => 'A test image',
      ],
    ]);
    $this->media->save();
  }

  /**
   * Creates an image block.
   */
  protected function createBlock(array $values = []): BlockContent {
    $block = BlockContent::create($values + [
      'type' => 'image',
      'info' => 'Image block',
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
   * Unlinked: the img IS the root; linked: the anchor is.
   */
  public function testImgAndAnchorRoots(): void {
    $block = $this->createBlock(['field_image' => $this->media->id()]);

    // A live render with nothing demanded: a bare img, no edit attribution.
    $html = trim($this->renderComposed($block));
    $this->assertStringStartsWith('<img', $html);
    $this->assertStringContainsString('/styles/test_style/', $html);
    $this->assertStringContainsString('alt="A test image"', $html);
    $this->assertStringContainsString('width="40"', $html);
    $this->assertStringContainsString('height="20"', $html);
    $this->assertStringContainsString('loading="lazy"', $html);
    $this->assertStringNotContainsString('data-daedalus-edit', $html);
    $this->assertStringNotContainsString('data-media-reference', $html);
    // The img is the block's declared styling part ("the picture"): the
    // stable image-block-image class rides the attributes bag when the img
    // IS the root — on live renders too, since part styles must reach
    // visitor markup.
    $this->assertMatchesRegularExpression('/^<img[^>]*class="[^"]*\bimage-block-image\b/', $html, 'The unlinked live img root carries the part class via the attributes bag.');

    // An edit-capable render hoists the bag onto the img root and stamps the
    // blueprint editor (the shipped widget is media_library_widget) plus the
    // drop-to-replace bag.
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertStringStartsWith('<img', $html);
    $this->assertStringContainsString('data-layout-builder-block-uuid="11111111-1111-1111-1111-111111111111"', $html);
    $this->assertStringContainsString('data-daedalus-edit-editor="image_composite"', $html);
    $this->assertStringContainsString('data-media-reference="field_image"', $html);
    $this->assertStringContainsString('data-media-bundle="image"', $html);
    $this->assertStringContainsString('data-timeout=', $html);
    $this->assertStringNotContainsString('data-daedalus-edit-id', $html);
    $this->assertMatchesRegularExpression('/^<img[^>]*class="[^"]*\bimage-block-image\b/', $html, 'The edit-capable unlinked img root carries the part class alongside the hoisted bag.');

    // Linked: the anchor is the root and carries every hoisted attribute;
    // the img inside carries none of them.
    $block->set('field_image_url', ['uri' => 'https://example.com/'])->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertStringStartsWith('<a', $html);
    $this->assertStringContainsString('href="https://example.com/"', $html);
    $this->assertMatchesRegularExpression('/^<a[^>]*data-layout-builder-block-uuid=/', $html);
    $this->assertMatchesRegularExpression('/<img(?![^>]*data-layout-builder-block-uuid)[^>]*>/', $html);
    $this->assertStringContainsString('/styles/test_style/', $html);
    // The part class follows the img into the linked shape: stamped
    // literally on the img (the anchor root holds the attributes bag), and
    // NOT on the anchor — root-key styles mean the outer box, image-part
    // styles mean the picture, in both link states.
    $this->assertMatchesRegularExpression('/<img[^>]*class="image-block-image"/', $html, 'Linked, the img carries the literally stamped part class.');
    $this->assertMatchesRegularExpression('/^<a(?![^>]*image-block-image)[^>]*>/', $html, 'Linked, the anchor root does not carry the part class.');
  }

  /**
   * Empty renders nothing live, and a fully attributed placeholder in edit.
   */
  public function testEmptyStates(): void {
    $block = $this->createBlock();

    $this->assertSame('', trim($this->renderComposed($block)));

    // The placeholder div is the root: it carries the hoisted bag, the
    // editor attribution, and the drop-to-replace bag (attributed from the
    // field's target bundle — the first drop is what fills the field).
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertStringStartsWith('<div', $html);
    $this->assertStringContainsString('daedalus-edit-image-block-placeholder', $html);
    $this->assertStringContainsString('data-layout-builder-block-uuid="11111111-1111-1111-1111-111111111111"', $html);
    $this->assertStringContainsString('data-daedalus-edit-editor="image_composite"', $html);
    $this->assertStringContainsString('data-media-reference="field_image"', $html);
    $this->assertStringContainsString('data-media-bundle="image"', $html);
    $this->assertStringContainsString('Add an image', $html);
    // No picture, no picture part: the placeholder is the outer box only.
    $this->assertStringNotContainsString('image-block-image', $html);
  }

  /**
   * The editor settings carry the server-built media library URL.
   *
   * The opener parameters address the HOST entity — the route's main
   * entity, the blueprint mutation root the selection commits to — so the
   * test renders under a faked route carrying one, the same shape the real
   * page render and the mutation re-render both have. Without a resolvable
   * host (every other test here), no settings are stamped and the editor
   * attribution still rides the root.
   */
  public function testMediaLibraryUrlInEditorSettings(): void {
    $this->installEntitySchema('entity_test');
    $host = EntityTest::create(['name' => 'Host page']);
    $host->save();

    $request = Request::create('/host-page');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.entity_test.canonical');
    // The route must declare the parameter or RouteMatch filters it out.
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/host-page/{entity_test}'));
    $request->attributes->set('entity_test', $host);
    // The test harness reads the current request's session at teardown.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $block = $this->createBlock(['field_image' => $this->media->id()]);
    $html = trim($this->renderComposed($block, $this->editBag()));

    $this->assertSame(1, preg_match('/data-daedalus-edit-editor-settings="([^"]+)"/', $html, $matches));
    $settings = Json::decode(html_entity_decode($matches[1], ENT_QUOTES));
    $this->assertArrayHasKey('media_library_url', $settings);
    parse_str(parse_url($settings['media_library_url'], PHP_URL_QUERY), $query);
    $this->assertSame(DaedalusEditMediaLibraryOpener::SERVICE_ID, $query['media_library_opener_id']);
    // Both shipped target bundles are offered: the raster image type and
    // the vector (SVG) type.
    $this->assertSame(['image', 'vector_image'], array_values($query['media_library_allowed_types']));
    $this->assertSame('image', $query['media_library_selected_type']);
    $this->assertSame('1', $query['media_library_remaining']);
    $this->assertSame([
      'entity_type_id' => 'entity_test',
      'entity_id' => (string) $host->id(),
    ], $query['media_library_opener_parameters']);
    $this->assertNotEmpty($query['hash']);
  }

  /**
   * Vector media renders the modeled svg root with sanitized inner markup.
   */
  public function testVectorMediaRendersInlineSanitizedSvg(): void {
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"'
      . ' class="authored-class" id="authored-id" style="background:red">'
      . '<script>alert("xss")</script>'
      . '<path d="M0 0h10v10H0z" fill="currentColor"/>'
      . '</svg>';
    file_put_contents('public://icon.svg', $svg);
    $file = File::create(['uri' => 'public://icon.svg']);
    $file->save();
    $media = Media::create([
      'bundle' => 'vector_image',
      'name' => 'Test icon',
      'field_media_svg' => [
        'target_id' => $file->id(),
        'alt' => 'A test icon',
      ],
    ]);
    $media->save();

    $block = $this->createBlock(['field_image' => $media->id()]);

    // Live render: the svg IS the root, the raster shape one form over.
    // Inline is the point (currentColor resolves against the inherited CSS
    // color), and inline SVG is live DOM, so the markup is sanitized at
    // render time; no image style derivative is ever built from an SVG.
    $html = trim($this->renderComposed($block));
    $this->assertStringStartsWith('<svg', $html);
    $this->assertStringEndsWith('</svg>', $html);
    $this->assertStringContainsString('fill="currentColor"', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<?xml', $html);
    $this->assertStringNotContainsString('/styles/', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringNotContainsString('<span', $html);
    // The inliner's attribute contract: the file's presentational set
    // passes through onto the modeled root (case preserved), the intrinsic
    // size is guaranteed from the viewBox, and the alt lands as the svg's
    // accessible name, mirroring the img branch.
    $this->assertMatchesRegularExpression('/^<svg[^>]*viewBox="0 0 10 10"/', $html, 'The file viewBox rides the modeled svg root.');
    $this->assertStringContainsString('width="10"', $html);
    $this->assertStringContainsString('height="10"', $html);
    $this->assertStringContainsString('role="img"', $html);
    $this->assertStringContainsString('aria-label="A test icon"', $html);
    // Authored class, id, and style are content, not model: they are
    // dropped so user-controlled attributes never land on the selectable,
    // style-scoped root.
    $this->assertStringNotContainsString('authored-class', $html, 'The authored class is dropped from the modeled root.');
    $this->assertStringNotContainsString('authored-id', $html, 'The authored id is dropped from the modeled root.');
    $this->assertStringNotContainsString('style=', $html, 'The authored style is dropped from the modeled root.');
    // The svg is the block's declared picture ("the picture" part): the
    // coinciding part hoists its stable class onto the root, exactly like
    // the unlinked raster img, so part styles reach vector visitors too.
    $this->assertMatchesRegularExpression('/^<svg[^>]*class="[^"]*\bimage-block-image\b/', $html, 'The unlinked svg root carries the hoisted part class.');

    // Edit-capable: the svg root carries the hoisted bag, the editor
    // attribution, and the drop-to-replace bag (vector bundle).
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<svg[^>]*data-layout-builder-block-uuid=/', $html);
    $this->assertStringContainsString('data-daedalus-edit-editor="image_composite"', $html);
    $this->assertStringContainsString('data-media-reference="field_image"', $html);
    $this->assertStringContainsString('data-media-bundle="vector_image"', $html);

    // Linked: the anchor is the root and the svg nests inside it as the
    // claimed picture part, wearing its class and the inliner attributes;
    // the anchor carries the bag and no part class.
    $block->set('field_image_url', ['uri' => 'https://example.com/'])->save();
    $html = trim($this->renderComposed($block, $this->editBag()));
    $this->assertMatchesRegularExpression('/^<a[^>]*data-layout-builder-block-uuid=/', $html);
    $this->assertStringContainsString('href="https://example.com/"', $html);
    $this->assertMatchesRegularExpression('/<svg[^>]*class="[^"]*\bimage-block-image\b/', $html, 'The nested svg part wears the stable part class.');
    $this->assertMatchesRegularExpression('/<svg[^>]*viewBox="0 0 10 10"/', $html, 'The inliner attributes address the nested part.');
    $this->assertStringContainsString('fill="currentColor"', $html);
    $this->assertMatchesRegularExpression('/^<a(?![^>]*image-block-image)/', $html, 'The anchor root does not wear the part class when the part nests.');
    $this->assertStringEndsWith('</a>', $html);
    $this->assertStringNotContainsString('<span', $html);

    // An empty alt marks the svg decorative, exactly like alt="". The block
    // is reloaded so its reference item resolves the updated media instead
    // of the computed entity cached on the in-memory item.
    $media->set('field_media_svg', ['target_id' => $file->id(), 'alt' => ''])->save();
    $block->set('field_image_url', NULL)->save();
    $block = BlockContent::load($block->id());
    $html = trim($this->renderComposed($block));
    $this->assertStringContainsString('aria-hidden="true"', $html);
    $this->assertStringNotContainsString('aria-label', $html);
  }

  /**
   * A file that cannot be inlined mounts an <image href> inside the svg.
   *
   * The modeled svg root has no home for an img fallback, and needs none:
   * <image> is real SVG content that renders the file, and its HTTP
   * request still lets Stage File Proxy fetch a missing file from the
   * origin. The failure bag carries no authored attributes at all, only
   * the a11y set.
   */
  public function testVectorInlineFailureLane(): void {
    file_put_contents('public://gone.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $file = File::create(['uri' => 'public://gone.svg']);
    $file->save();
    $media = Media::create([
      'bundle' => 'vector_image',
      'name' => 'Missing icon',
      'field_media_svg' => [
        'target_id' => $file->id(),
        'alt' => 'A missing icon',
      ],
    ]);
    $media->save();
    // The file entity survives; the file on disk does not (the state Stage
    // File Proxy exists for).
    $this->container->get('file_system')->delete('public://gone.svg');

    $block = $this->createBlock(['field_image' => $media->id()]);
    $html = trim($this->renderComposed($block));

    // Still the modeled svg root with its hoisted part class: the failure
    // lane is a content difference, never a grammar exception.
    $this->assertStringStartsWith('<svg', $html);
    $this->assertStringEndsWith('</svg>', $html);
    $this->assertMatchesRegularExpression('/^<svg[^>]*class="[^"]*\bimage-block-image\b/', $html);
    $this->assertMatchesRegularExpression('/<image[^>]*href="[^"]*gone\.svg"/', $html, 'The failure lane mounts an <image> referencing the raw file.');
    $this->assertStringContainsString('width="100%"', $html);
    $this->assertStringContainsString('height="100%"', $html);
    $this->assertStringContainsString('role="img"', $html);
    $this->assertStringContainsString('aria-label="A missing icon"', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringNotContainsString('viewBox', $html, 'No authored attributes ride the failure lane root.');
  }

  /**
   * A non-media_library widget routes the block to the form-swap tier.
   */
  public function testFallbackTierGate(): void {
    $this->container->get('entity_display.repository')->getFormDisplay('block_content', 'image')
      ->setComponent('field_image', ['type' => 'options_select'])
      ->save();

    $block = $this->createBlock(['field_image' => $this->media->id()]);
    $html = trim($this->renderComposed($block, $this->editBag()));

    $this->assertStringStartsWith('<img', $html);
    $this->assertStringNotContainsString('data-daedalus-edit-editor', $html);
    // The property segment is 'value': the form-side lookup ids hardcode it
    // for configurable fields, and the two sides only ever match each other.
    $id = $block->id();
    $this->assertStringContainsString(sprintf('data-daedalus-edit-field-value-wrapper="block_content::%s::field_image::value"', $id), $html);
    $this->assertStringContainsString(sprintf('data-daedalus-edit-id="block_content::image::%s::field_image::0::en::entity_reference::entity_reference_entity_view::value"', $id), $html);
    $this->assertStringContainsString(sprintf('data-daedalus-edit-page-element-id="block_content::%s::field_image::0::value"', $id), $html);
    // The drop-to-replace bag rides both tiers.
    $this->assertStringContainsString('data-media-reference="field_image"', $html);
  }

  /**
   * The style guide serves the img part and the element-bound controls.
   *
   * The declaration lives in this module's page_builder block type YML; a
   * broken declaration throws StyleGuideException on every guide read,
   * taking every styling surface (panel and AI alike) down with it. The
   * elements/controls GRAMMAR is daedalus_stylish's own coverage
   * (StyleGuideControlsTest); what this pins is OUR declaration as served:
   * exactly one declared part (the picture, whose ordered forms carry the
   * img on raster media and the svg on vector media), the picture property
   * groups on it, and the three picture controls restated element-bound so
   * they mean the picture in both link states instead of silently
   * retargeting onto the anchor root when a link is added.
   */
  public function testStyleGuideServesImagePartAndControls(): void {
    $this->installConfig(['daedalus_stylish']);
    $block_type = $this->container->get('daedalus_stylish.style_guide')->getBlockType('image');

    $elements = $block_type['elements'] ?? [];
    $this->assertSame(['.image-block-image'], array_keys($elements), 'Exactly one declared part: the picture, img and svg forms alike.');
    $this->assertSame(
      ['sizing', 'border', 'shadow'],
      array_keys($elements['.image-block-image']['property_groups'] ?? []),
      'The part carries the picture groups: sizing (width), border (radius clipping the picture pixels), shadow (elevation following that radius).',
    );

    $controls = $block_type['controls'] ?? [];
    foreach (['width', 'corners_border', 'elevation'] as $name) {
      $this->assertSame(
        '.image-block-image',
        $controls[$name]['element'] ?? NULL,
        sprintf('The "%s" control is bound to the picture part, not the unstable root.', $name),
      );
    }
    $this->assertSame('Image width', $controls['width']['label'], 'The width control reads as the picture control it is.');
    // The outer-box controls keep their kind-inherited root binding.
    $this->assertArrayNotHasKey('element', $controls['position'] ?? [], 'Position stays root-bound: its subject is the outer box.');
  }

  /**
   * The declared defaults deliver: compiled rules and a stamped root.
   *
   * The block's box facts (a block root shrink-wrapping a block picture)
   * are declared defaults on this declaration, never module CSS, so the
   * compiled defaults stylesheet must carry the root and part rules with
   * no design system active at all, and a live render's linked root must
   * wear the family class and this bundle's scope class the rules address.
   * The root is rendered through the layout builder component (the real
   * page path) rather than the hand-built bag: the stamps land at entity
   * view on the block content build and reach the composed root through
   * the hoisted bag, which is exactly the seam this pins for this bundle.
   * The mechanism itself is daedalus_stylish's coverage (DefaultsStylesheetTest).
   */
  public function testDeclaredDefaultsDeliverAndStampTheRoot(): void {
    $this->installConfig(['daedalus_stylish']);
    $this->assertNull($this->container->get('daedalus_stylish.skin_manager')->activeSkinName(), 'No design system is active: the declared tier delivers on its own.');

    $guide = $this->container->get('daedalus_stylish.style_guide');
    $this->assertSame(
      [
        '' => ['default' => ['display' => 'block', 'width' => 'fit-content']],
        '::.image-block-image' => ['default' => ['display' => 'block']],
      ],
      $guide->getBlockType('image')['defaults'],
      'The declaration ships the linked root and the picture as block boxes.',
    );

    $this->container->get('daedalus_stylish.defaults_stylesheet')->compile();
    $uri = \Drupal::state()->get(DefaultsStylesheet::STATE_KEY);
    $this->assertIsString($uri, 'The defaults stylesheet compiles with no skin.');
    $css = file_get_contents($uri);
    $scope_root = '@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ' .' . DefaultsStylesheet::scopeClass('block_type:image') . ')';
    $this->assertStringContainsString($scope_root . " {\n  :scope {\n    display: block;\n    width: fit-content;\n  }", $css, 'The root rule prints at :scope inside the stamped scope class.');
    $this->assertStringContainsString($scope_root . " {\n  :scope.image-block-image, .image-block-image {\n    display: block;\n  }", $css, 'The part rule prints with the root-hoist alternative.');

    $block = $this->createBlock([
      'field_image' => $this->media->id(),
      'field_image_url' => ['uri' => 'https://example.com/'],
    ]);
    $component = new SectionComponent('20000000-0000-0000-0000-000000000000', 'content', [
      'id' => 'inline_block:image',
      'block_revision_id' => $block->getRevisionId(),
    ]);
    // Live: nothing demands editing attributes; the stamps are delivery.
    $this->setUpCurrentUser();
    $build = $component->toRenderArray();
    $html = trim((string) $this->container->get('renderer')->renderInIsolation($build));
    $this->assertStringStartsWith('<a', $html, 'The linked block renders its anchor root with no sandwich.');
    $this->assertMatchesRegularExpression(
      '/^<a[^>]*class="[^"]*\b' . preg_quote(DefaultsStylesheet::scopeClass('kind:block'), '/') . '\b[^"]*\b' . preg_quote(DefaultsStylesheet::scopeClass('block_type:image'), '/') . '\b/',
      $html,
      'The anchor root wears the family class and the image bundle scope class.',
    );
    $this->assertMatchesRegularExpression('/<img[^>]*class="image-block-image"/', $html, 'The picture inside wears the part class the part rule addresses.');
  }

}
