<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the element resolver: declaration + entity data -> concrete tree.
 *
 * The instance tier of the page-elements model, locked against every
 * shipped variation shape (mirrored into daedalus_ui_resolver_test so
 * the lock does not depend on daedalus_edit/daedalus_stylish): the text block's
 * data-decided tag and conditional link wrapper, all four image root forms
 * against the multi-form picture part (matched-form tag/label, coincidence
 * between matched forms), the one-box container's multi-form root (the
 * depth-conditioned section/div swap, the caller-fed emptiness input, the
 * root-form AND), and the field shell with its pattern-part namespace.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class ElementResolverTest extends KernelTestBase {

  use MediaTypeCreationTrait;

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
   * The resolver under test.
   */
  protected ElementResolver $resolver;

  /**
   * Raster media: a png source file.
   */
  protected Media $rasterMedia;

  /**
   * Vector media: an svg source file.
   *
   * The kind derives from the FILE, so one image-source media type carries
   * both fixtures, exactly the honesty the render lane's file-based branch
   * encodes.
   */
  protected Media $vectorMedia;

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

    $this->resolver = $this->container->get('daedalus_ui.element_resolver');

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

    $this->createMediaType('image', ['id' => 'image']);
    $this->container->get('file_system')->copy($this->root . '/core/tests/fixtures/files/image-test.png', 'public://image-test.png');
    $png = File::create(['uri' => 'public://image-test.png']);
    $png->save();
    $this->rasterMedia = Media::create([
      'bundle' => 'image',
      'name' => 'Raster',
      'field_media_image' => ['target_id' => $png->id(), 'alt' => 'A raster image'],
    ]);
    $this->rasterMedia->save();

    file_put_contents('public://icon.svg', '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $svg = File::create(['uri' => 'public://icon.svg']);
    $svg->save();
    $this->vectorMedia = Media::create([
      'bundle' => 'image',
      'name' => 'Vector',
      'field_media_image' => ['target_id' => $svg->id(), 'alt' => 'A vector image'],
    ]);
    $this->vectorMedia->save();
  }

  /**
   * Resolves the effective declaration of a block type for a block.
   */
  protected function resolveBlock(BlockContent $block): array {
    $declaration = $this->container->get('daedalus_ui.page_builder_declarations')->getBlockType($block->bundle());
    return $this->resolver->resolve($declaration, $block);
  }

  /**
   * The text shape: data-decided tag, conditional link wrapper.
   */
  public function testTextBranches(): void {
    // Unlinked: the root is the tag field's element, the anchor does not
    // exist (a failed field condition is absence, never a hoist), and the
    // unclaimed text falls to the root per the mounting rule (no slots).
    $unlinked = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_text' => 'Hello',
      'field_text_tag' => 'h2',
    ]);
    $resolved = $this->resolveBlock($unlinked);
    $this->assertSame('h2', $resolved['tag']);
    $this->assertSame([], $resolved['attributes']);
    $this->assertSame([], $resolved['parts'], 'The unlinked text block renders no anchor.');
    $this->assertSame([], $resolved['slots'], 'The absent anchor claims nothing; the text mounts at the root.');
    $this->assertSame([], $resolved['classes']);
    $this->assertSame([], $resolved['hoisted']);

    // Linked: the anchor is present inside the tag root, carries the url
    // field's value as href, and claims the text.
    $linked = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_text' => 'Hello',
      'field_text_tag' => 'h3',
      'field_text_url' => ['uri' => 'https://example.com/target'],
    ]);
    $resolved = $this->resolveBlock($linked);
    $this->assertSame('h3', $resolved['tag']);
    $this->assertSame(['.text-block-link'], array_keys($resolved['parts']));
    $anchor = $resolved['parts']['.text-block-link'];
    $this->assertSame('a', $anchor['tag']);
    $this->assertSame(
      ['href' => 'https://example.com/target', ElementResolver::CLAIMS_CONTENT_ATTRIBUTE => ''],
      $anchor['attributes'],
      'The field-claiming anchor wears the content-claim marker: the anatomy fact enters the markup at the resolver, once.',
    );
    $this->assertSame([], $anchor['parts']);
    $this->assertArrayNotHasKey('label', $anchor, 'A plain tag-part resolves no label; consumers fall back to the declaration.');
    $this->assertSame(['field:field_text_text' => '.text-block-link'], $resolved['slots']);
    $this->assertSame([], $resolved['classes'], 'The nested anchor never coincides with the tag root.');
  }

  /**
   * The image shape: all four root forms against the multi-form part.
   *
   * The part resolves through its own first-match-wins forms (img on
   * raster, svg on vector), the matched form supplying tag and label, and
   * coincidence is decided between MATCHED forms: unlinked, the root's
   * media form and the part's media form name the same element, so the
   * part hoists on both lanes.
   */
  public function testImageRootForms(): void {
    // Linked raster: the anchor form matches first, so the part nests
    // inside it as its own element — the img form matched, so the node
    // wears its tag, its label (Raster), and the content-claim marker
    // for the claimed image field.
    $resolved = $this->resolveBlock(BlockContent::create([
      'type' => 'image',
      'info' => 'Image',
      'field_image' => ['target_id' => $this->rasterMedia->id()],
      'field_image_url' => ['uri' => 'https://example.com/target'],
    ]));
    $this->assertSame('a', $resolved['tag']);
    $this->assertSame(['href' => 'https://example.com/target'], $resolved['attributes']);
    $this->assertSame(['.image-block-image'], array_keys($resolved['parts']));
    $picture = $resolved['parts']['.image-block-image'];
    $this->assertSame('img', $picture['tag']);
    $this->assertSame('Raster', $picture['label'], 'The matched form\'s own name rides the resolved node.');
    $this->assertSame([ElementResolver::CLAIMS_CONTENT_ATTRIBUTE => ''], $picture['attributes']);
    $this->assertSame(['field:field_image' => '.image-block-image'], $resolved['slots']);
    $this->assertSame([], $resolved['classes']);
    $this->assertSame([], $resolved['hoisted']);

    // Unlinked raster: the root's img form IS the part's matched img form
    // (equal tag under the equal condition), so the part hoists — one
    // element, class on the root, the part key still addressing it, its
    // claim dissolved to the root.
    $resolved = $this->resolveBlock(BlockContent::create([
      'type' => 'image',
      'info' => 'Image',
      'field_image' => ['target_id' => $this->rasterMedia->id()],
    ]));
    $this->assertSame('img', $resolved['tag']);
    $this->assertSame([], $resolved['parts'], 'The img is the root, not a nested part.');
    $this->assertSame(['image-block-image'], $resolved['classes']);
    $this->assertSame(['.image-block-image'], $resolved['hoisted']);
    $this->assertSame([], $resolved['slots'], 'The hoisted part claims nothing; the image mounts at the root.');

    // Unlinked vector: the exact raster shape, one form over — the root's
    // svg form and the part's matched svg form coincide, so the vector
    // lane hoists like the raster lane does.
    $resolved = $this->resolveBlock(BlockContent::create([
      'type' => 'image',
      'info' => 'Image',
      'field_image' => ['target_id' => $this->vectorMedia->id()],
    ]));
    $this->assertSame('svg', $resolved['tag']);
    $this->assertSame([], $resolved['parts'], 'The svg is the root, not a nested part.');
    $this->assertSame(['image-block-image'], $resolved['classes']);
    $this->assertSame(['.image-block-image'], $resolved['hoisted']);

    // Linked vector: the anchor form wins first-match and the part nests
    // as the svg form's element, labeled Vector.
    $resolved = $this->resolveBlock(BlockContent::create([
      'type' => 'image',
      'info' => 'Image',
      'field_image' => ['target_id' => $this->vectorMedia->id()],
      'field_image_url' => ['uri' => 'https://example.com/target'],
    ]));
    $this->assertSame('a', $resolved['tag']);
    $this->assertSame(['href' => 'https://example.com/target'], $resolved['attributes']);
    $vector = $resolved['parts']['.image-block-image'];
    $this->assertSame('svg', $vector['tag']);
    $this->assertSame('Vector', $vector['label']);
    $this->assertSame(['field:field_image' => '.image-block-image'], $resolved['slots']);
    $this->assertSame([], $resolved['classes']);

    // Empty: no content in any field, so the {empty: true} residual form
    // resolves the placeholder div, and the part — none of whose forms
    // can match without media — is honestly absent, never hoisted. info
    // is a base field, not content, so it never blocks emptiness.
    $resolved = $this->resolveBlock(BlockContent::create([
      'type' => 'image',
      'info' => 'Image',
    ]));
    $this->assertSame('div', $resolved['tag']);
    $this->assertSame([], $resolved['attributes']);
    $this->assertSame([], $resolved['parts']);
    $this->assertSame([], $resolved['hoisted']);
  }

  /**
   * The container shape: one box whose root form swaps on depth and empty.
   *
   * A section has no entity, so its emptiness is the CALLER's input (the
   * annotator walking the serialized node, the render walking the regions
   * build): the resolver answers the {empty: true} fact once from it, and
   * the empty-at-root form composes depth AND empty in one present map
   * (the root-form AND lane).
   */
  public function testContainerForms(): void {
    $declaration = $this->container->get('daedalus_ui.page_builder_declarations')->getKind('container');

    // Root depth, populated: a real <section> page band, no parts, no
    // slots — children mount at the root per the mounting rule.
    $resolved = $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_ROOT, FALSE);
    $this->assertSame('section', $resolved['tag']);
    $this->assertSame([], $resolved['attributes']);
    $this->assertSame([], $resolved['parts'], 'The one-box container declares no parts.');
    $this->assertSame([], $resolved['slots'], 'No part claims regions; children mount at the root.');
    $this->assertSame([], $resolved['classes']);
    $this->assertSame([], $resolved['hoisted']);

    // Nested, populated: the same one box as a plain div.
    $resolved = $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_NESTED, FALSE);
    $this->assertSame('div', $resolved['tag']);
    $this->assertSame([], $resolved['attributes']);
    $this->assertSame([], $resolved['parts']);

    // Empty at root: the {depth: root, empty: true} form wins first-match
    // and carries the declared drop-affordance attribute.
    $resolved = $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_ROOT, TRUE);
    $this->assertSame('section', $resolved['tag']);
    $this->assertSame(['data-container-empty' => ''], $resolved['attributes']);

    // Empty nested: the div-shaped empty form.
    $resolved = $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_NESTED, TRUE);
    $this->assertSame('div', $resolved['tag']);
    $this->assertSame(['data-container-empty' => ''], $resolved['attributes']);

    // No emptiness input and no entity: emptiness cannot be vouched for,
    // so the populated forms match.
    $resolved = $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_ROOT);
    $this->assertSame('section', $resolved['tag']);
    $this->assertSame([], $resolved['attributes']);
  }

  /**
   * The field shell: unconditional nested parts, pattern part skipped.
   */
  public function testFieldShell(): void {
    $declaration = $this->container->get('daedalus_ui.page_builder_declarations')->getKind('field');

    $resolved = $this->resolver->resolve($declaration);
    $this->assertNull($resolved['tag']);
    $this->assertSame(['.field__label', '.field__items'], array_keys($resolved['parts']), 'The shipped shell resolves; the .pat-* owner pattern part is a namespace, not an element.');
    $this->assertSame('div', $resolved['parts']['.field__label']['tag']);
    $items = $resolved['parts']['.field__items'];
    $this->assertSame('div', $items['tag']);
    $this->assertSame(['.field__item'], array_keys($items['parts']));
    $this->assertSame('div', $items['parts']['.field__item']['tag']);
    $this->assertSame(['value' => '.field__item'], $resolved['slots'], 'The nested item part claims the value slot in the flat map.');
  }

  /**
   * The entity kind's layout root: daedalus_layout's declared part resolves.
   *
   * The daedalus_layout module renders the root sections inside one
   * div.daedalus-layout-layout and declares it as entity anatomy in its own
   * page_builder.yml (shell ownership follows template ownership: the
   * section list is markup daedalus_layout builds inside {{ content }}). The
   * shipped entity kind carries no parts of its own, so this case enables
   * daedalus_layout and resolves the layered entity declaration: the part is its
   * own unconditional div under the root, never hoisted (the theme owns
   * the entity root).
   */
  public function testEntityLayoutRoot(): void {
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $this->assertArrayNotHasKey('elements', $declarations->getKind('entity'), 'daedalus_ui itself declares no entity parts; the layout root is daedalus_layout\'s.');

    $this->enableModules(['daedalus_layout']);
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $declarations->resetCache();
    $declaration = $declarations->getKind('entity');
    $this->assertSame(['.daedalus-layout-layout'], array_keys($declaration['elements']));

    $resolved = $this->container->get('daedalus_ui.element_resolver')->resolve($declaration);
    $this->assertNull($resolved['tag'], 'The entity root is the theme template\'s: the grammar names no tag for it.');
    $this->assertSame([], $resolved['hoisted']);
    $this->assertSame(['.daedalus-layout-layout'], array_keys($resolved['parts']));
    $layout = $resolved['parts']['.daedalus-layout-layout'];
    $this->assertSame('div', $layout['tag']);
    $this->assertSame([], $layout['attributes']);
    $this->assertSame([], $layout['parts'], 'The layout root holds the sections, which are containers of their own, not parts of the entity.');
    $this->assertSame(['sections' => '.daedalus-layout-layout'], $resolved['slots'], 'The layout root claims the section list through the entity kind\'s sections token: the sketch and the read print mount the sections inside it.');
    $this->assertSame([], $layout['attributes'], 'A generic bucket stamps no claims attribute: only field:NAME claims are anatomy facts the markup carries.');
  }

  /**
   * An unknown depth is a caller bug, refused loudly.
   */
  public function testInvalidDepth(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->resolver->resolve([], NULL, 'sideways');
  }

  /**
   * The PHP resolver and its client twin resolve identical trees.
   *
   * The round-trip lock (the CssPrinter/printer.js pattern, see daedalus_stylish's
   * PrinterEquivalenceTest): every shipped variation shape resolves here
   * through the PHP resolver against real entities, and through the JS
   * twin (js/edit_mode/blueprint/element-resolver.js, via node running
   * tests/js/resolve-cases.mjs) against the documented data facade — the
   * shape the editors derive from the blueprint mirror and the gesture's
   * previewed values. The trees must be deeply equal, or the live editing
   * preview shows something other than what commits.
   */
  public function testClientTwinEquivalence(): void {
    $node = $this->findNode();
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $text = $declarations->getBlockType('text');
    $image = $declarations->getBlockType('image');
    $container_kind = $declarations->getKind('container');
    $field_kind = $declarations->getKind('field');

    // Degenerate media: the source file is gone (a dangling reference), so
    // the media resolves no kind and the image block matches no root form.
    $degenerate_media = Media::create([
      'bundle' => 'image',
      'name' => 'Degenerate',
      'field_media_image' => ['target_id' => 99999, 'alt' => 'Lost'],
    ]);
    $degenerate_media->save();

    // Each case: the entity the PHP side resolves against, and the data
    // facade the client would derive for the same state (fields: a key per
    // NON-EMPTY content field, valued by the string the markup prints or
    // null when unprintable; mediaKind from the referenced media's lane).
    $cases = [
      'text unlinked, tag from field' => [
        'declaration' => $text,
        'entity' => BlockContent::create([
          'type' => 'text',
          'info' => 'Text',
          'field_text_text' => 'Hello',
          'field_text_tag' => 'h2',
        ]),
        'data' => ['fields' => ['field_text_text' => 'Hello', 'field_text_tag' => 'h2']],
      ],
      'text linked' => [
        'declaration' => $text,
        'entity' => BlockContent::create([
          'type' => 'text',
          'info' => 'Text',
          'field_text_text' => 'Hello',
          'field_text_tag' => 'h3',
          'field_text_url' => ['uri' => 'https://example.com/target'],
        ]),
        'data' => [
          'fields' => [
            'field_text_text' => 'Hello',
            'field_text_tag' => 'h3',
            'field_text_url' => 'https://example.com/target',
          ],
        ],
      ],
      'text with no tag value falls to the residual form' => [
        'declaration' => $text,
        'entity' => BlockContent::create([
          'type' => 'text',
          'info' => 'Text',
          'field_text_text' => 'Hello',
        ]),
        'data' => ['fields' => ['field_text_text' => 'Hello']],
      ],
      'text fully empty' => [
        'declaration' => $text,
        'entity' => BlockContent::create(['type' => 'text', 'info' => 'Text']),
        'data' => ['fields' => []],
      ],
      'image linked raster' => [
        'declaration' => $image,
        'entity' => BlockContent::create([
          'type' => 'image',
          'info' => 'Image',
          'field_image' => ['target_id' => $this->rasterMedia->id()],
          'field_image_url' => ['uri' => 'https://example.com/target'],
        ]),
        'data' => [
          'fields' => ['field_image' => NULL, 'field_image_url' => 'https://example.com/target'],
          'mediaKind' => 'raster',
        ],
      ],
      'image unlinked raster (coincidence hoist)' => [
        'declaration' => $image,
        'entity' => BlockContent::create([
          'type' => 'image',
          'info' => 'Image',
          'field_image' => ['target_id' => $this->rasterMedia->id()],
        ]),
        'data' => ['fields' => ['field_image' => NULL], 'mediaKind' => 'raster'],
      ],
      'image unlinked vector' => [
        'declaration' => $image,
        'entity' => BlockContent::create([
          'type' => 'image',
          'info' => 'Image',
          'field_image' => ['target_id' => $this->vectorMedia->id()],
        ]),
        'data' => ['fields' => ['field_image' => NULL], 'mediaKind' => 'vector'],
      ],
      'image linked vector' => [
        'declaration' => $image,
        'entity' => BlockContent::create([
          'type' => 'image',
          'info' => 'Image',
          'field_image' => ['target_id' => $this->vectorMedia->id()],
          'field_image_url' => ['uri' => 'https://example.com/target'],
        ]),
        'data' => [
          'fields' => ['field_image' => NULL, 'field_image_url' => 'https://example.com/target'],
          'mediaKind' => 'vector',
        ],
      ],
      'image empty (placeholder form)' => [
        'declaration' => $image,
        'entity' => BlockContent::create(['type' => 'image', 'info' => 'Image']),
        'data' => ['fields' => []],
      ],
      'image degenerate media resolves honestly undeclared' => [
        'declaration' => $image,
        'entity' => BlockContent::create([
          'type' => 'image',
          'info' => 'Image',
          'field_image' => ['target_id' => $degenerate_media->id()],
        ]),
        'data' => ['fields' => ['field_image' => NULL], 'mediaKind' => NULL],
      ],
      'container at root depth' => [
        'declaration' => $container_kind,
        'entity' => NULL,
        'empty' => FALSE,
        'data' => ['empty' => FALSE],
        'depth' => ElementResolver::DEPTH_ROOT,
      ],
      'container at nested depth' => [
        'declaration' => $container_kind,
        'entity' => NULL,
        'empty' => FALSE,
        'data' => ['empty' => FALSE],
        'depth' => ElementResolver::DEPTH_NESTED,
      ],
      'container empty at root (the AND form, emptiness input)' => [
        'declaration' => $container_kind,
        'entity' => NULL,
        'empty' => TRUE,
        'data' => ['empty' => TRUE],
        'depth' => ElementResolver::DEPTH_ROOT,
      ],
      'container empty nested' => [
        'declaration' => $container_kind,
        'entity' => NULL,
        'empty' => TRUE,
        'data' => ['empty' => TRUE],
        'depth' => ElementResolver::DEPTH_NESTED,
      ],
      'container with no emptiness input resolves the populated form' => [
        'declaration' => $container_kind,
        'entity' => NULL,
        'data' => NULL,
        'depth' => ElementResolver::DEPTH_ROOT,
      ],
      'field shell (pattern part skipped)' => [
        'declaration' => $field_kind,
        'entity' => NULL,
        'data' => NULL,
      ],
      'block declaration with no data' => [
        'declaration' => $text,
        'entity' => NULL,
        'data' => NULL,
      ],
    ];

    $php_output = [];
    $js_cases = [];
    foreach ($cases as $case) {
      $depth = $case['depth'] ?? ElementResolver::DEPTH_ROOT;
      // The PHP side's emptiness input is the case's 'empty' fact; the
      // client twin reads the same fact from the facade's `empty` key.
      $php_output[] = $this->resolver->resolve($case['declaration'], $case['entity'], $depth, $case['empty'] ?? NULL);
      $js_cases[] = [
        'declaration' => $case['declaration'],
        'data' => $case['data'],
        'depth' => $depth,
      ];
    }

    $js_output = json_decode($this->runNode($node, 'tests/js/resolve-cases.mjs', json_encode(['cases' => $js_cases])), TRUE);
    $this->assertIsArray($js_output);
    $this->assertSameSize($php_output, $js_output);
    $labels = array_keys($cases);
    foreach ($php_output as $index => $expected) {
      // json_decode(assoc) renders the PHP trees' empty maps and the JS
      // trees' empty objects both as [], so the comparison is exact on
      // everything the tree can carry.
      $this->assertSame(
        json_decode(json_encode($expected), TRUE),
        $js_output[$index],
        sprintf('Case "%s" diverged between the PHP resolver and the client twin.', $labels[$index]),
      );
    }
    // Guard against a silently-degenerate battery: the linked text case
    // resolved its anchor on both sides, and the linked vector case
    // resolved the part's matched svg form — tag and label — on the
    // client too.
    $this->assertSame('a', $js_output[1]['parts']['.text-block-link']['tag'] ?? NULL);
    $this->assertSame('svg', $js_output[7]['parts']['.image-block-image']['tag'] ?? NULL);
    $this->assertSame('Vector', $js_output[7]['parts']['.image-block-image']['label'] ?? NULL);
  }

  /**
   * Locates the node binary, or skips (CI images without node).
   */
  protected function findNode(): string {
    foreach (['node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
      $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
      if ($path !== '') {
        return $path;
      }
    }
    $this->markTestSkipped('node is not available; the client half of the resolver pair cannot run.');
  }

  /**
   * Runs a node script from the module root with the given stdin.
   */
  protected function runNode(string $node, string $script, string $stdin): string {
    $module_root = dirname(__DIR__, 3);
    $process = proc_open(
      [$node, $module_root . '/' . $script],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $module_root,
    );
    $this->assertIsResource($process, 'node failed to start.');
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $this->assertSame(0, $exit, "node $script failed:\n$stderr\n$stdout");
    return $stdout;
  }

}
