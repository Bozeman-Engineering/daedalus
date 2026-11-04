<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\ElementModel\SketchPrinter;
use Drupal\daedalus_ui\EventSubscriber\ElementCardsSubscriber;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The render cards the catalog carries, derived from the declarations.
 *
 * The real catalog builder runs over real bundles and the fixture
 * declarations (daedalus_ui_resolver_test's text, image, formatted text
 * and container shapes; daedalus_ui_declarations_test's block kind shell,
 * card type and menu plugin, with its extra layer), and the cards it
 * carries are pinned whole as input/output pairs: the text block's
 * default paragraph, its link on the paragraph and its data-decided tag
 * with the link inside; the image's four shapes; the formatted text's one
 * div; the container's shapes by depth and emptiness; the card type's
 * nested parts in the stand-in root; the fallback card of a tagged bundle
 * no layer declares, and the same fallback under the generic owner
 * spelling for a placeable bundle the contract cannot spell; no card for
 * a plugin block. Each output is locked to the resolver: the tree for the
 * facade the input sets, printed, is the card's html.
 */
#[CoversClass(ElementCardsSubscriber::class)]
#[CoversClass(SketchPrinter::class)]
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ElementCardsTest extends BlueprintKernelTestBase {

  /**
   * The built catalog.
   */
  protected DialectCatalog $catalog;

  /**
   * The resolver, for the sketch lock.
   */
  protected ElementResolver $resolver;

  /**
   * The declarations spine.
   */
  protected PageBuilderDeclarations $declarations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'layout_discovery',
      'link',
      'options',
      'image',
      'media',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_ui_resolver_test',
      'daedalus_ui_declarations_test',
      'daedalus_ui_declarations_test_extra',
      // The eb_test_container layout: the container layout the catalog
      // builder derives containerLayoutId from.
      'daedalus_blueprint_test',
    ]);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    // The shipped text block's fields: a list tag with a default, a link,
    // a plain body.
    $this->createBlockContentType('text', 'Text');
    $this->createBlockField('field_text_text', 'string_long', 'text');
    $this->createBlockField('field_text_tag', 'list_string', 'text', [
      'allowed_values' => ['h2' => 'H2', 'h3' => 'H3', 'p' => 'P'],
    ]);
    $tag = FieldConfig::loadByName('block_content', 'text', 'field_text_tag');
    assert($tag instanceof FieldConfig);
    $tag->setDefaultValue('p')->save();
    $this->createBlockField('field_text_url', 'link', 'text');

    // The image block: a media reference and a link, no body.
    $this->createBlockContentType('image', 'Image');
    $this->createBlockField('field_image', 'entity_reference', 'image', ['target_type' => 'media']);
    $this->createBlockField('field_image_url', 'link', 'image');

    // The formatted text block: one rich body.
    $this->createBlockContentType('formatted_text', 'Formatted text');
    $this->createBlockField('field_formatted_text', 'text_long', 'formatted_text');

    // The declarations fixture's card: a title and a plain body, both
    // claimed by declared parts.
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_title', 'string', 'card', ['max_length' => 255]);
    $this->createBlockField('field_body', 'string_long', 'card');

    // A bundle no layer declares: tagged by the dialect, no markup model.
    $this->createBlockContentType('notes', 'Notes');
    $this->createBlockField('field_notes_text', 'string_long', 'notes');

    // A bundle the contract cannot spell (a multi-value field): printed
    // generically as <d-block type="gallery">, never tagged.
    $this->createBlockContentType('gallery', 'Gallery');
    $this->createBlockField('field_gallery_captions', 'string', 'gallery', ['max_length' => 255], ['cardinality' => -1]);

    $builder = $this->container->get('daedalus_blueprint.dialect_catalog_builder');
    $builder->reset();
    $this->catalog = $builder->build();
    $this->resolver = $this->container->get('daedalus_ui.element_resolver');
    $this->declarations = $this->container->get('daedalus_ui.page_builder_declarations');
  }

  /**
   * One card per dialect-tagged owner, in contribution order; none per plugin.
   */
  public function testRoster(): void {
    $tagged = array_map(DialectCatalog::tagForBlockType(...), array_keys($this->catalog->blockTypes()));
    $bundles = array_keys($this->catalog->blockTypes());
    sort($bundles);
    $this->assertSame(['card', 'formatted_text', 'image', 'notes', 'text'], $bundles, 'Every bundle but the gallery is tagged: its contract is inexpressible.');
    $this->assertSame(
      array_merge(['d-container'], $tagged, ['d-block[type=gallery]']),
      array_keys($this->catalog->cards()),
      'The container first, then every tagged bundle in the catalog\'s order, then the generically printed bundles; the declared menu plugin has no card (its parts ride the styling roster).',
    );
  }

  /**
   * The text block's card.
   *
   * The default paragraph, the link on it, the tag with the link inside.
   */
  public function testTextCard(): void {
    $this->assertSame([
      'renders' => [
        '<d-text>…</d-text>' => '<p>…</p>',
        '<d-text url="…">…</d-text>' => '<p><a class="text-block-link" href="…">…</a></p>',
        '<d-text tag="h2" url="…">…</d-text>' => '<h2><a class="text-block-link" href="…">…</a></h2>',
      ],
      'shells' => [],
    ], $this->catalog->cardFor('d-text'), 'The base facade holds the tag field\'s default; the link part alone sits on it; the tag form at its fullest samples the first non-default value and shows the anchor inside it.');

    $body = 'field:field_text_text';
    $this->assertSketch('text', 'd-text', '<d-text>…</d-text>', ['fields' => ['field_text_tag' => 'p']], ElementResolver::DEPTH_ROOT, $body);
    $linked = ['fields' => ['field_text_tag' => 'p', 'field_text_url' => '…']];
    $this->assertSketch('text', 'd-text', '<d-text url="…">…</d-text>', $linked, ElementResolver::DEPTH_ROOT, $body);
    $heading = ['fields' => ['field_text_tag' => 'h2', 'field_text_url' => '…']];
    $this->assertSketch('text', 'd-text', '<d-text tag="h2" url="…">…</d-text>', $heading, ElementResolver::DEPTH_ROOT, $body);
  }

  /**
   * The image block: four shapes, the picture part in every one that has media.
   */
  public function testImageCard(): void {
    $this->assertSame([
      'renders' => [
        '<d-image/>' => '<div></div>',
        '<d-image media="…"/> (raster media)' => '<img class="image-block-image">',
        '<d-image media="…"/> (vector media)' => '<svg class="image-block-image"></svg>',
        '<d-image media="…" url="…"/> (raster media)' => '<a href="…"><img class="image-block-image"></a>',
      ],
      'shells' => [],
    ], $this->catalog->cardFor('d-image'), 'No defaults, so the base facade is empty (the empty form); the media kinds ride the media attribute with the kind in words; the link form at its fullest carries the raster picture.');

    $root = ElementResolver::DEPTH_ROOT;
    $this->assertSketch('image', 'd-image', '<d-image/>', ['fields' => []], $root, NULL);
    $raster = ['fields' => [], 'mediaKind' => 'raster'];
    $this->assertSketch('image', 'd-image', '<d-image media="…"/> (raster media)', $raster, $root, NULL);
    $vector = ['fields' => [], 'mediaKind' => 'vector'];
    $this->assertSketch('image', 'd-image', '<d-image media="…"/> (vector media)', $vector, $root, NULL);
    $linked = ['fields' => ['field_image_url' => '…'], 'mediaKind' => 'raster'];
    $this->assertSketch('image', 'd-image', '<d-image media="…" url="…"/> (raster media)', $linked, $root, NULL);
  }

  /**
   * The formatted text block: one fixed div holding the body.
   */
  public function testFormattedTextCard(): void {
    $this->assertSame([
      'renders' => ['<d-formatted-text>…</d-formatted-text>' => '<div>…</div>'],
      'shells' => [],
    ], $this->catalog->cardFor('d-formatted-text'));
    $this->assertSketch('formatted_text', 'd-formatted-text', '<d-formatted-text>…</d-formatted-text>', ['fields' => []], ElementResolver::DEPTH_ROOT, 'field:field_formatted_text');
  }

  /**
   * The container: shapes by depth and emptiness, children at the root.
   */
  public function testContainerCard(): void {
    $this->assertSame([
      'renders' => [
        '<d-container>…</d-container> (top-level)' => '<section>…</section>',
        '<d-container>…</d-container> (nested)' => '<div>…</div>',
        '<d-container></d-container> (top-level)' => '<section data-container-empty=""></section>',
        '<d-container></d-container> (nested)' => '<div data-container-empty=""></div>',
      ],
      'shells' => [],
    ], $this->catalog->cardFor('d-container'), 'An element whose shape turns on depth says the depth in words; the plainest write first; an empty form mounts no children.');

    $declaration = $this->declarations->getLayout('eb_test_container');
    $this->assertSketchOf($declaration, 'd-container', '<d-container>…</d-container> (top-level)', NULL, ElementResolver::DEPTH_ROOT, 'regions');
    $this->assertSketchOf($declaration, 'd-container', '<d-container>…</d-container> (nested)', NULL, ElementResolver::DEPTH_NESTED, 'regions');
    $this->assertSketchOf($declaration, 'd-container', '<d-container></d-container> (top-level)', ['empty' => TRUE], ElementResolver::DEPTH_ROOT, NULL);
    $this->assertSketchOf($declaration, 'd-container', '<d-container></d-container> (nested)', ['empty' => TRUE], ElementResolver::DEPTH_NESTED, NULL);
  }

  /**
   * A tagged bundle no layer declares gets the fallback card.
   */
  public function testFallbackCard(): void {
    $this->assertSame(ElementCardsSubscriber::FALLBACK_CARD, $this->catalog->cardFor('d-notes'));
    $this->assertSame(['renders' => [], 'shells' => ['block', 'field']], ElementCardsSubscriber::FALLBACK_CARD, 'No pairs: the theme\'s block template, each field in the universal field shell.');
    $this->assertSame(ElementCardsSubscriber::FALLBACK_CARD, $this->catalog->cardFor(DialectCatalog::ownerForGenericBlockType('gallery')), 'A placeable bundle the contract cannot spell gets the fallback under its generic owner.');
    $this->assertNull($this->catalog->cardFor('d-gallery'), 'No d-gallery tag exists, so no card is owned by one.');
  }

  /**
   * A declared type with no root of its own: the parts nest in the stand-in.
   */
  public function testTaglessRootCard(): void {
    $this->assertSame([
      'renders' => ['<d-card>…</d-card>' => '<div><div class="card"><div class="card__body">…</div></div></div>'],
      'shells' => [],
    ], $this->catalog->cardFor('d-card'), 'Unconditional parts show in the one shape; the body lands in the claiming part.');
    $this->assertSketch('card', 'd-card', '<d-card>…</d-card>', ['fields' => []], ElementResolver::DEPTH_ROOT, 'field:field_body');
  }

  /**
   * The sketch printer: the format's facts the tree alone does not spell.
   */
  public function testSketchPrinter(): void {
    $tree = [
      'tag' => NULL,
      'attributes' => ['data-x' => 'a & b'],
      'classes' => ['hoisted'],
      'hoisted' => ['.hoisted'],
      'parts' => [
        '.shell' => [
          'tag' => NULL,
          'attributes' => [],
          'parts' => ['.ghost' => ['tag' => 'span', 'attributes' => [], 'parts' => []]],
        ],
        '.inner' => [
          'tag' => 'span',
          'attributes' => [ElementResolver::CLAIMS_CONTENT_ATTRIBUTE => '', 'role' => 'note'],
          'parts' => [],
        ],
        '.rule' => ['tag' => 'hr', 'attributes' => [], 'parts' => []],
      ],
      'slots' => ['field:body' => '.inner'],
    ];
    $this->assertSame(
      '<div class="hoisted" data-x="a &amp; b"><span class="inner" role="note">…</span><hr class="rule"></div>',
      SketchPrinter::print($tree, 'field:body'),
      'A tagless root prints the div stand-in, a tagless part prints nothing (subtree included, as the composed render mounts nothing for it), the claim marker is dropped, a void tag wears its class and self-closes, values escape.',
    );
    $this->assertSame('<div class="hoisted" data-x="a &amp; b"><span class="inner" role="note"></span><hr class="rule"></div>', SketchPrinter::print($tree, NULL), 'No content token, no placeholder.');
    $this->assertSame('<div class="hoisted" data-x="a &amp; b"><span class="inner" role="note"></span><hr class="rule">…</div>', SketchPrinter::print($tree, 'regions'), 'An unclaimed token mounts at the root.');
  }

  /**
   * Asserts a block type pair's output is the resolver's tree, printed.
   */
  protected function assertSketch(string $bundle, string $owner, string $input, ?array $facade, string $depth, ?string $content_token): void {
    $this->assertSketchOf($this->declarations->getBlockType($bundle), $owner, $input, $facade, $depth, $content_token);
  }

  /**
   * Asserts a pair's output is the resolver's tree for a facade, printed.
   */
  protected function assertSketchOf(array $declaration, string $owner, string $input, ?array $facade, string $depth, ?string $content_token): void {
    $expected = SketchPrinter::print($this->resolver->resolveData($declaration, $facade, $depth), $content_token);
    $this->assertSame($expected, $this->catalog->cardFor($owner)['renders'][$input] ?? NULL, sprintf('%s of %s sketches the resolver\'s tree.', $input, $owner));
  }

}
