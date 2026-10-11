<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_ui_icon_test\LeadingIconProvider;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the delivered-blueprint `_element` annotations.
 *
 * The annotator walks a delivered blueprint's `layout` (the lean node
 * tree), calling the one model, so the fixture is a hand-built blueprint
 * array over real bundle config — the node shapes the serializer emits,
 * without paying for a full Layout Builder render — and every assertion
 * reads the annotation off `layout`, the one shape the mirror's consumers
 * walk. One uniform
 * annotation on every owning node, the root included: the naming spine's
 * `name` and `type`, presentation, compose, the resolver's markup tree,
 * and per-field facts (existence + presentation + rich-text instances)
 * all ride `_element`. Markup for components resolves against the
 * entities the root's REAL section data carries, so the hand-built nodes
 * here (no backing section entity) carry no component markup, the
 * annotation only claims what the data can back, while containers and
 * the root, which need no entity lookup, are stamped on every walk.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class BlueprintElementAnnotatorTest extends KernelTestBase {

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
    'node',
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
    'link',
    'daedalus_ui',
    // Grammar-v2 declarations mirroring the shipped variation shapes (the
    // text tag/link lanes, the container depth part, the '.rt-*' instance
    // namespace on the spine's instances lane) for the `_element.markup`
    // annotation cases.
    'daedalus_ui_resolver_test',
    // Declares the text_block block type composed
    // (daedalus_ui_collapse_test.page_builder.block_type.text_block.yml)
    // for the composed cases.
    'daedalus_ui_collapse_test',
    // Declares field_root_gone `element: false` at the entity kind
    // (daedalus_ui_element_model_test.page_builder.yml) for the root
    // existence cases.
    'daedalus_ui_element_model_test',
  ];

  /**
   * The annotator under test.
   *
   * @var \Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator
   */
  protected $annotator;

  /**
   * The host of an entity, as the spine's doors resolve it.
   */
  protected function host(FieldableEntityInterface $entity): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->annotator = $this->container->get('daedalus_ui.element_annotator');

    $this->createBlockType('text_block', ['field_text' => 'text_long']);
    $this->createBlockType('cta', [
      'field_cta_text' => 'text_long',
      'field_cta_image' => 'entity_reference_media',
    ]);
  }

  /**
   * Creates a block_content type with the given fields.
   */
  protected function createBlockType(string $type, array $fields = []): void {
    $this->container->get('entity_type.manager')
      ->getStorage('block_content_type')
      ->create(['id' => $type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'revision' => TRUE])
      ->save();
    foreach ($fields as $field_name => $field_type) {
      if (!FieldStorageConfig::loadByName('block_content', $field_name)) {
        $storage = ['field_name' => $field_name, 'entity_type' => 'block_content', 'type' => $field_type];
        if ($field_type === 'entity_reference_media') {
          $storage = [
            'field_name' => $field_name,
            'entity_type' => 'block_content',
            'type' => 'entity_reference',
            'settings' => ['target_type' => 'media'],
          ];
        }
        FieldStorageConfig::create($storage)->save();
      }
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'block_content',
        'bundle' => $type,
        'label' => ucwords(str_replace(['field_', '_'], ['', ' '], $field_name)),
      ])->save();
    }
  }

  /**
   * Every owning node gets `_element`; only non-default facts are emitted.
   */
  public function testAnnotatesDeliveredBlueprint(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'bundle' => 'cta',
      'fields' => [
        'field_cta_text' => 'Hello',
        'field_cta_image' => ['target_id' => 3, '_label' => 'A picture'],
      ],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'merged-uuid',
                  'block_type' => 'text_block',
                  'label' => 'Machine label',
                  'block_fields' => ['field_text' => 'Some text'],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'open-uuid',
                  'block_type' => 'cta',
                  'label' => 'CTA',
                  'block_fields' => [
                    'field_cta_text' => 'CTA text',
                    'field_cta_image' => ['target_id' => 4, '_label' => 'CTA picture'],
                  ],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'plugin-uuid',
                  'plugin_id' => 'system_powered_by_block',
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'collapsed-plugin-uuid',
                  'plugin_id' => 'collapse_test_cooperative',
                ],
                // A nested container node inside a region's children: it
                // presents as a container, and its blocks ride the same
                // walk.
                [
                  'kind' => 'container',
                  'uuid' => 'nested-section-uuid',
                  'layout_id' => 'layout_onecol',
                  'children' => [
                    [
                      'kind' => 'region',
                      'region' => 'content',
                      'children' => [
                        [
                          'kind' => 'block',
                          'uuid' => 'nested-collapsed-uuid',
                          'block_type' => 'text_block',
                          'label' => 'Nested',
                          'block_fields' => ['field_text' => 'Nested text'],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');

    // The ROOT node carries the same uniform annotation. A block_content
    // root's label key (info) is DECLARED element: false, but the derived
    // edit-target rule only defers to render-configuration inference: the
    // declaration folds info's own row, while the root row still routes
    // editing to it (its canvas render is the page title on the editing
    // surface); the media reference maps to unit, open fields are omitted.
    // Its name is the entity label, its type the bundle label.
    $this->assertSame(
      [
        'name' => 'Root fixture',
        'type' => 'Cta',
        'icon' => $this->glyph('page'),
        'presentation' => ['mode' => Presentation::OPEN, 'edit_target' => 'info'],
        'markup' => $this->emptyMarkup(),
        'fields' => ['field_cta_image' => ['presentation' => Presentation::UNIT]],
      ],
      $annotated['_element'],
    );

    // A one-column layout's sole region is a region node under the
    // container; the region carries nothing, its blocks are annotated.
    $this->assertArrayNotHasKey('_element', $annotated['layout'][0]['children'][0]);
    $components = $annotated['layout'][0]['children'][0]['children'];

    // The single-field block derives MERGED with its content's identity,
    // and its type declares compose (`composed: true` in its
    // page_builder declaration); an absent composed key means the
    // sandwich. Its one open field emits no fact, and no markup is stamped:
    // the hand-built component has no backing section entity to resolve.
    // Merged, it is NAMED by its content (the edit target's text), never by
    // its configuration label, and typed by its block type's label.
    $this->assertSame(
      [
        'name' => 'Some text',
        'type' => 'Text block',
        'icon' => NULL,
        'presentation' => ['mode' => Presentation::MERGED, 'label' => 'Text', 'edit_target' => 'field_text'],
        'composed' => TRUE,
      ],
      $components[0]['_element'],
    );

    // The multi-field block stays OPEN and maps its media field to unit.
    // Open, with a label that is not rendered, the label IS its name.
    $this->assertSame(
      [
        'name' => 'CTA',
        'type' => 'Cta',
        'icon' => NULL,
        'presentation' => ['mode' => Presentation::OPEN],
        'fields' => ['field_cta_image' => ['presentation' => Presentation::UNIT]],
      ],
      $components[1]['_element'],
    );

    // Plugin components are not fieldable bundles: no presentation, no
    // markup, no field facts, they present open. Compose is a block-TYPE
    // fact, so plugin components never carry it, whatever their plugin id.
    // They are still elements on the canvas with rows in the tree, so they
    // carry the naming spine's two words: the plugin's admin label as the
    // type and, unlabeled, as the name; a plugin the manager does not know
    // degrades to its id, the one fact left. Their icon is daedalus_ui's
    // own plugin-block glyph: the providers are never asked for them.
    $this->assertSame($this->pluginWords('Powered by Drupal', 'Powered by Drupal'), $components[2]['_element']);
    $this->assertSame($this->pluginWords('collapse_test_cooperative', 'collapse_test_cooperative'), $components[3]['_element']);

    // The walk reaches nested sections: the section node presents AS a
    // section (no component annotation, no block facade) but carries the
    // layout's resolved markup, at NESTED depth — the container's
    // multi-form root resolves the plain div there — and its inner
    // components are annotated like any others. Nested, it is a Container
    // in the layout vocabulary, and unrenamed its name is that type word.
    $this->assertSame(['name', 'type', 'icon', 'markup'], array_keys($components[4]['_element']));
    $this->assertNull($components[4]['_element']['icon'], 'A container is daedalus_layout\'s concept: no icon until a provider answers.');
    $this->assertSame('Container', $components[4]['_element']['name']);
    $this->assertSame('Container', $components[4]['_element']['type']);
    $this->assertSame('div', $components[4]['_element']['markup']['tag']);
    $this->assertSame([], $components[4]['_element']['markup']['attributes'], 'A populated container is not empty; the annotator fed the fact from its region nodes.');
    $nested = $components[4]['children'][0]['children'][0];
    $this->assertTrue($nested['_element']['composed']);

    // The top-level section resolves the same declaration at ROOT depth:
    // ONE box, a real <section>, no parts and no slots (children mount at
    // the root per the mounting rule). At page depth it is a Section.
    $section = $annotated['layout'][0];
    $this->assertSame('Section', $section['_element']['name']);
    $this->assertSame('Section', $section['_element']['type']);
    $this->assertNull($section['_element']['icon']);
    $this->assertSame(
      [
        'tag' => 'section',
        'attributes' => [],
        'classes' => [],
        'hoisted' => [],
        'parts' => [],
        'slots' => [],
      ],
      $section['_element']['markup'],
    );
  }

  /**
   * The walk covers container layouts' ordered children lists.
   *
   * A container layout's node holds its element children directly (its
   * sole region is a storage vestige), no region node between; the
   * annotator annotates them and recurses into nested container nodes
   * exactly like a region node's children.
   */
  public function testAnnotatesContainerChildren(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'bundle' => 'cta',
      'fields' => [],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'container-uuid',
          'layout_id' => 'daedalus_stylish_container',
          'children' => [
            [
              'kind' => 'block',
              'uuid' => 'merged-uuid',
              'block_type' => 'text_block',
              'label' => 'Machine label',
              'block_fields' => ['field_text' => 'Some text'],
            ],
            // A nested container node inside a children list.
            [
              'kind' => 'container',
              'uuid' => 'nested-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'nested-block-uuid',
                  'block_type' => 'text_block',
                  'label' => 'Nested',
                  'block_fields' => ['field_text' => 'Nested text'],
                ],
              ],
            ],
            // An EMPTY nested container: no children at all, so the
            // annotator's emptiness feed answers the {empty: true} fact.
            [
              'kind' => 'container',
              'uuid' => 'empty-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $children = $annotated['layout'][0]['children'];

    $this->assertSame(
      [
        'name' => 'Some text',
        'type' => 'Text block',
        'icon' => NULL,
        'presentation' => ['mode' => Presentation::MERGED, 'label' => 'Text', 'edit_target' => 'field_text'],
        'composed' => TRUE,
      ],
      $children[0]['_element'],
    );

    // The nested container node presents AS a section (markup only, no
    // block facade) at NESTED depth — the multi-form root resolves the
    // plain div — and its inner components are annotated like any others.
    $this->assertSame(['name', 'type', 'icon', 'markup'], array_keys($children[1]['_element']));
    $this->assertNull($children[1]['_element']['icon']);
    $this->assertSame('div', $children[1]['_element']['markup']['tag']);
    $this->assertSame([], $children[1]['_element']['markup']['attributes']);
    $this->assertTrue($children[1]['children'][0]['_element']['composed']);

    // The empty nested container: the annotator fed emptiness from the
    // node's (empty) children list, so the empty form matched and wears
    // its declared drop-affordance attribute.
    $this->assertSame('div', $children[2]['_element']['markup']['tag']);
    $this->assertSame(['data-container-empty' => ''], $children[2]['_element']['markup']['attributes']);

    // The top-level container holds children, so at ROOT depth the
    // populated section form matches.
    $this->assertSame('section', $annotated['layout'][0]['_element']['markup']['tag']);
    $this->assertSame([], $annotated['layout'][0]['_element']['markup']['attributes']);
  }

  /**
   * The root's derived rule: its label field presents AS the entity row.
   *
   * The fixture is a Layout-Builder-enabled node bundle — the product's
   * root case. A node root's title is the entity type's label key and a
   * real field, so the root presentation names it (edit_target) while
   * staying OPEN: the panel folds the title row into the root row. Entity-kind `element: false` declarations surface as
   * root field facts, so their rows fold in every view. That covers title
   * itself (daedalus_ui's own universal declaration, since preprocess
   * lifts it out of the entity render and nothing inside the entity
   * answers to its key) alongside styling infrastructure like a style map
   * field. A DECLARED element: false never vetoes the derived edit_target,
   * so title editing keeps routing through the root row: an LB node
   * bundle's annotation carries BOTH `fields.title.element: false` and
   * `presentation.edit_target: title`.
   */
  public function testRootAnnotation(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('node_type')
      ->create(['type' => 'landing_page', 'name' => 'Landing page'])
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_root_gone',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_root_gone',
      'entity_type' => 'node',
      'bundle' => 'landing_page',
      'label' => 'Root gone',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'landing_page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $root = $entity_type_manager->getStorage('node')
      ->create(['type' => 'landing_page', 'title' => 'Hello there']);

    $blueprint = [
      'bundle' => 'landing_page',
      'fields' => [
        'title' => 'Hello there',
        'field_root_gone' => 'infrastructure',
      ],
      'layout' => [],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');

    $this->assertSame(
      [
        'name' => 'Hello there',
        'type' => 'Landing page',
        'icon' => $this->glyph('page'),
        'presentation' => ['mode' => Presentation::OPEN, 'edit_target' => 'title'],
        'markup' => $this->emptyMarkup(),
        'fields' => [
          'title' => ['element' => FALSE],
          'field_root_gone' => ['element' => FALSE],
        ],
      ],
      $annotated['_element'],
    );
  }

  /**
   * A field block of the root presents MERGED into the field it renders.
   *
   * A plugin component whose id is a Layout Builder field block derivative
   * for the root's own type and bundle carries the model's field-block
   * presentation (merged, labeled with the field's definition label,
   * editing routed to the field) and names the presented field by its
   * selectable key, the twin of the placement's data-presented-field
   * stamp. A field block of another bundle, or any other plugin block,
   * stays unannotated and presents open.
   */
  public function testFieldBlockAnnotation(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('node_type')
      ->create(['type' => 'landing_page', 'name' => 'Landing page'])
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'node',
      'bundle' => 'landing_page',
      'label' => 'Summary',
    ])->save();
    $root = $entity_type_manager->getStorage('node')
      ->create(['type' => 'landing_page', 'title' => 'Hello there', 'field_summary' => 'Placed']);

    $blueprint = [
      'bundle' => 'landing_page',
      'fields' => ['title' => 'Hello there', 'field_summary' => 'Placed'],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                ['kind' => 'block', 'uuid' => 'summary-block-uuid', 'field' => 'field_summary'],
                ['kind' => 'block', 'uuid' => 'missing-field-uuid', 'field' => 'field_nope'],
                ['kind' => 'block', 'uuid' => 'plugin-uuid', 'plugin_id' => 'system_powered_by_block'],
                ['kind' => 'block', 'uuid' => 'named-plugin-uuid', 'plugin_id' => 'system_powered_by_block', 'label' => 'Footer credits'],
                ['kind' => 'block', 'uuid' => 'headed-plugin-uuid', 'plugin_id' => 'system_powered_by_block', 'label' => 'Footer credits', 'label_rendered' => TRUE],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $components = $annotated['layout'][0]['children'][0]['children'];

    $this->assertSame(
      [
        'name' => 'Summary',
        'type' => 'Summary',
        'icon' => $this->glyph('field-block'),
        'presentation' => ['mode' => Presentation::MERGED, 'label' => 'Summary', 'edit_target' => 'field_summary'],
        'presented_field' => 'field:' . $root->uuid() . ':field_summary',
      ],
      $components[0]['_element'],
      'The field block presents merged into the root field it renders and names it by key; the field label is both its name and its type.',
    );
    // A field node of a field the root lacks presents nothing of this root:
    // no presentation, no presented field. Like any plugin block it still
    // names itself, here by the field name, since the subject has no such
    // field to lend a label. (The tree names the field, never a per-bundle
    // derivative, so there is no "field block of another bundle" to spell.)
    $this->assertSame(
      ['name' => 'field_nope', 'type' => 'field_nope', 'icon' => $this->glyph('field-block')],
      $components[1]['_element'],
      'A field node of a field the root lacks presents nothing of it.',
    );
    // A plain plugin block: the admin label is its type; a hidden
    // configured label is its name, a rendered one is content, so the type
    // stands in.
    $this->assertSame($this->pluginWords('Powered by Drupal', 'Powered by Drupal'), $components[2]['_element']);
    $this->assertSame($this->pluginWords('Footer credits', 'Powered by Drupal'), $components[3]['_element']);
    $this->assertSame($this->pluginWords('Powered by Drupal', 'Powered by Drupal'), $components[4]['_element']);
    $this->assertSame('summary-block-uuid', $components[0]['uuid']);
  }

  /**
   * Display-hidden block fields surface as `element: false` field facts.
   *
   * A display-hidden field never renders as an attributed field element,
   * so no canvas element answers to its key — inline-block components
   * only: the display-hidden inference never speaks for ROOT entities (a
   * hidden root field can still reach the canvas through a Layout Builder
   * field block), so the same field stays existent on the root. A bundle
   * with no saved display hides nothing (core fabricates a display with
   * every field visible).
   */
  public function testHiddenFieldsAnnotation(): void {
    $this->container->get('entity_type.manager')
      ->getStorage('entity_view_display')
      ->create([
        'targetEntityType' => 'block_content',
        'bundle' => 'cta',
        'mode' => 'default',
        'status' => TRUE,
      ])
      ->setComponent('field_cta_text', ['type' => 'text_default'])
      ->removeComponent('field_cta_image')
      ->save();

    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'bundle' => 'cta',
      'fields' => [
        'field_cta_text' => 'Hello',
        'field_cta_image' => ['target_id' => 3, '_label' => 'A picture'],
      ],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'cta-uuid',
                  'block_type' => 'cta',
                  'label' => 'CTA',
                  'block_fields' => [
                    'field_cta_text' => 'CTA text',
                    'field_cta_image' => ['target_id' => 4, '_label' => 'CTA picture'],
                  ],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'displayless-uuid',
                  'block_type' => 'text_block',
                  'label' => 'Text',
                  'block_fields' => ['field_text' => 'Some text'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $components = $annotated['layout'][0]['children'][0]['children'];

    $this->assertSame(
      ['field_cta_image' => ['element' => FALSE]],
      $components[0]['_element']['fields'],
      'The display-hidden field is nonexistent; existence outranks its unit presentation.',
    );
    $this->assertArrayNotHasKey(
      'fields',
      $components[1]['_element'],
      'A bundle with no saved display hides nothing.',
    );
    $this->assertSame(
      ['field_cta_image' => ['presentation' => Presentation::UNIT]],
      $annotated['_element']['fields'],
      'The display-hidden inference never speaks for root entities: the same field stays existent (and unit) on the root.',
    );
  }

  /**
   * A rendered label joins the meaningful-content count at the delivery seam.
   *
   * The fact is the tree's `label_rendered` (NodeTreeBuilder::labelRendered:
   * a non-empty label with display on, which the serializer spells on the
   * node only when TRUE), the same rule the canvas attribution reads, so a
   * label that is displayed but empty renders nothing and counts for
   * nothing: the one-field block stays merged.
   */
  public function testLabelRenderedFeedsTheDerivedDefault(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'labeled-uuid',
                  'block_type' => 'text_block',
                  'label' => 'Shown label',
                  'label_rendered' => TRUE,
                  'block_fields' => ['field_text' => 'Some text'],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'blank-label-uuid',
                  'block_type' => 'text_block',
                  'block_fields' => ['field_text' => 'Some text'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $components = $annotated['layout'][0]['children'][0]['children'];

    // Rendered label + one field = two meaningful things: open.
    $this->assertSame(['mode' => Presentation::OPEN], $components[0]['_element']['presentation']);
    // Nothing rendered: one meaningful thing, merged.
    $this->assertSame(Presentation::MERGED, $components[1]['_element']['presentation']['mode']);
  }

  /**
   * Markup resolves against the entities the root's REAL sections carry.
   *
   * The instance-model delivery: a component whose block_content the
   * root's section data resolves gets the effective declaration resolved
   * against THAT entity, data-decided tag, present parts, resolved
   * attributes, and a composed block's rich-text values enumerate their
   * minted instance elements onto its markup (labels stamped, translated,
   * so no client carries a tag-vocabulary copy). The root's own rich-text
   * fields carry per-field instances instead: open renders own their
   * carriers at the field key, mirroring where the innermost selectable
   * element lands in the DOM. Beside the carriers rides `tags`, the
   * distinct tag names the same prose contains, minted or not, so a tag
   * that never mints (the link, the emphasis) is still a positive
   * presence fact; a block whose prose wears no identity class at all
   * carries `tags` and no `instances`.
   */
  public function testMarkupAnnotation(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('node_type')
      ->create(['type' => 'landing_page', 'name' => 'Landing page'])
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_prose',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_prose',
      'entity_type' => 'node',
      'bundle' => 'landing_page',
      'label' => 'Prose',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'landing_page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    // The resolver-test text bundle: composed, tag decided by
    // field_text_tag, the conditional link part by field_text_url, plus a
    // rich-text field (storage exposes a filter_format `format` property)
    // whose stored HTML carries identity classes from the declared '.rt-*'
    // namespace.
    $this->createBlockType('text', [
      'field_text_text' => 'string_long',
      'field_text_tag' => 'string',
      'field_text_url' => 'link',
      'field_text_body' => 'text_long',
    ]);
    $block = $entity_type_manager->getStorage('block_content')->create([
      'type' => 'text',
      'info' => 'Linked heading',
      'reusable' => FALSE,
      'field_text_text' => 'Read more',
      'field_text_tag' => 'h2',
      'field_text_url' => ['uri' => 'https://drupal.org/'],
      'field_text_body' => [
        'value' => '<p class="rt-ab">Hello <strong>world</strong></p>'
        . '<blockquote class="rt-cd"><p class="rt-ef">Quoted</p></blockquote>',
        'format' => 'plain_text',
      ],
    ]);
    $block->save();
    // Prose with no identity class anywhere: carriers absent, tags present.
    $unminted = $entity_type_manager->getStorage('block_content')->create([
      'type' => 'text',
      'info' => 'Unminted prose',
      'reusable' => FALSE,
      'field_text_text' => 'Plain',
      'field_text_tag' => 'p',
      'field_text_body' => [
        'value' => '<p>Plain <a href="#">link</a> and <em>emphasis</em></p><p>Again</p>',
        'format' => 'plain_text',
      ],
    ]);
    $unminted->save();

    $component_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $unminted_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $section = new Section('layout_onecol', [], [
      new SectionComponent($component_uuid, 'content', [
        'id' => 'inline_block:text',
        'provider' => 'layout_builder',
        'label' => 'Linked heading',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $block->getRevisionId(),
      ]),
      new SectionComponent($unminted_uuid, 'content', [
        'id' => 'inline_block:text',
        'provider' => 'layout_builder',
        'label' => 'Unminted prose',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $unminted->getRevisionId(),
      ]),
    ]);
    $root = $entity_type_manager->getStorage('node')->create([
      'type' => 'landing_page',
      'title' => 'Hello there',
      'field_prose' => [
        'value' => '<p class="rt-zz">Root prose</p>',
        'format' => 'plain_text',
      ],
      'layout_builder__layout' => [$section],
    ]);

    $blueprint = [
      'bundle' => 'landing_page',
      'fields' => [
        'title' => 'Hello there',
        'field_prose' => 'Root prose',
      ],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => $component_uuid,
                  'block_type' => 'text',
                  'label' => 'Linked heading',
                  'block_fields' => ['field_text_text' => 'Read more'],
                ],
                [
                  'kind' => 'block',
                  'uuid' => $unminted_uuid,
                  'block_type' => 'text',
                  'label' => 'Unminted prose',
                  'block_fields' => ['field_text_text' => 'Plain'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');

    // The component's markup: the data-decided tag, the present link part
    // carrying its resolved href, the text slot claimed by the anchor,
    // the composed block's instance elements, first-carrier-owned, nested
    // carriers nesting, and the distinct tags the prose contains (the
    // unminted `strong` included) in first-occurrence order.
    $component = $annotated['layout'][0]['children'][0]['children'][0];
    $this->assertTrue($component['_element']['composed']);
    $this->assertSame(
      [
        'tag' => 'h2',
        'attributes' => [],
        'classes' => [],
        'hoisted' => [],
        'parts' => [
          '.text-block-link' => [
            'tag' => 'a',
            'attributes' => ['href' => 'https://drupal.org/', 'data-claims-content' => ''],
            'parts' => [],
          ],
        ],
        'slots' => ['field:field_text_text' => '.text-block-link'],
        'instances' => [
          [
            'class' => 'rt-ab',
            'tag' => 'p',
            'label' => 'Paragraph',
            'text' => 'Hello world',
            'children' => [],
          ],
          [
            'class' => 'rt-cd',
            'tag' => 'blockquote',
            'label' => 'Block quote',
            'text' => 'Quoted',
            'children' => [
              [
                'class' => 'rt-ef',
                'tag' => 'p',
                'label' => 'Paragraph',
                'text' => 'Quoted',
                'children' => [],
              ],
            ],
          ],
        ],
        'tags' => ['p', 'strong', 'blockquote'],
      ],
      $component['_element']['markup'],
    );

    // No carrier anywhere: `instances` is absent (the annotation claims
    // nothing it cannot back) while `tags` still answers presence, so a
    // never-minting link or emphasis is offerable on this block.
    $unminted_markup = $annotated['layout'][0]['children'][0]['children'][1]['_element']['markup'];
    $this->assertArrayNotHasKey('instances', $unminted_markup);
    $this->assertSame(['p', 'a', 'em'], $unminted_markup['tags']);

    // The root's rich-text field owns its carriers and tags at the field
    // key.
    $this->assertSame(
      [
        'markup' => [
          'instances' => [
          [
            'class' => 'rt-zz',
            'tag' => 'p',
            'label' => 'Paragraph',
            'text' => 'Root prose',
            'children' => [],
          ],
          ],
          'tags' => ['p'],
        ],
      ],
      $annotated['_element']['fields']['field_prose'],
    );
  }

  /**
   * The matched-form label rides the markup annotation.
   *
   * A multi-form part resolves through the same first-match-wins list the
   * root does, and the matched form's own name ('Vector' for the image
   * part's svg form) rides the resolved part node as `label`, between
   * attributes and parts, exactly as the resolver emits it. The client's
   * markup index reads it from here (markup-index.js partLabel), so the
   * annotator must deliver it without reshaping. The unlinked sibling locks the
   * other lane: a coinciding matched form hoists, and a hoisted part
   * carries no node (and so no label) at all; consumers degrade to the
   * declared part-level label.
   */
  public function testMatchedFormLabelAnnotation(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['system']);
    $this->createMediaType('image', ['id' => 'image']);
    file_put_contents('public://icon.svg', '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $svg = File::create(['uri' => 'public://icon.svg']);
    $svg->save();
    $vector_media = Media::create([
      'bundle' => 'image',
      'name' => 'Vector',
      'field_media_image' => ['target_id' => $svg->id(), 'alt' => 'A vector image'],
    ]);
    $vector_media->save();
    $this->container->get('file_system')->copy($this->root . '/core/tests/fixtures/files/image-test.png', 'public://image-test.png');
    $png = File::create(['uri' => 'public://image-test.png']);
    $png->save();
    $raster_media = Media::create([
      'bundle' => 'image',
      'name' => 'Raster',
      'field_media_image' => ['target_id' => $png->id(), 'alt' => 'A raster image'],
    ]);
    $raster_media->save();

    // The resolver-test image bundle: multi-form root (a/img/svg/div) and
    // the multi-form picture part (img/Raster + svg/Vector).
    $this->createBlockType('image', [
      'field_image' => 'entity_reference_media',
      'field_image_url' => 'link',
    ]);
    $linked_vector = $entity_type_manager->getStorage('block_content')->create([
      'type' => 'image',
      'info' => 'Linked vector',
      'reusable' => FALSE,
      'field_image' => ['target_id' => $vector_media->id()],
      'field_image_url' => ['uri' => 'https://drupal.org/'],
    ]);
    $linked_vector->save();
    $unlinked_raster = $entity_type_manager->getStorage('block_content')->create([
      'type' => 'image',
      'info' => 'Unlinked raster',
      'reusable' => FALSE,
      'field_image' => ['target_id' => $raster_media->id()],
    ]);
    $unlinked_raster->save();

    $entity_type_manager->getStorage('node_type')
      ->create(['type' => 'landing_page', 'name' => 'Landing page'])
      ->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'landing_page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $linked_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $unlinked_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $section = new Section('layout_onecol', [], [
      new SectionComponent($linked_uuid, 'content', [
        'id' => 'inline_block:image',
        'provider' => 'layout_builder',
        'label' => 'Linked vector',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $linked_vector->getRevisionId(),
      ]),
      new SectionComponent($unlinked_uuid, 'content', [
        'id' => 'inline_block:image',
        'provider' => 'layout_builder',
        'label' => 'Unlinked raster',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $unlinked_raster->getRevisionId(),
      ]),
    ]);
    $root = $entity_type_manager->getStorage('node')->create([
      'type' => 'landing_page',
      'title' => 'Hello there',
      'layout_builder__layout' => [$section],
    ]);

    $blueprint = [
      'bundle' => 'landing_page',
      'fields' => ['title' => 'Hello there'],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => $linked_uuid,
                  'block_type' => 'image',
                  'label' => 'Linked vector',
                  'block_fields' => [],
                ],
                [
                  'kind' => 'block',
                  'uuid' => $unlinked_uuid,
                  'block_type' => 'image',
                  'label' => 'Unlinked raster',
                  'block_fields' => [],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $components = $annotated['layout'][0]['children'][0]['children'];

    // Linked vector: the anchor form wins first-match on the root, the
    // part nests as its matched svg form's element, and the form's own
    // name rides the node as `label`, between attributes and parts.
    $this->assertSame(
      [
        'tag' => 'a',
        'attributes' => ['href' => 'https://drupal.org/'],
        'classes' => [],
        'hoisted' => [],
        'parts' => [
          '.image-block-image' => [
            'tag' => 'svg',
            'attributes' => ['data-claims-content' => ''],
            'label' => 'Vector',
            'parts' => [],
          ],
        ],
        'slots' => ['field:field_image' => '.image-block-image'],
      ],
      $components[0]['_element']['markup'],
    );

    // Unlinked raster: the root's img form and the part's matched img form
    // coincide, so the part hoists onto the root; no part node, no label,
    // and its claim dissolves to the root mount.
    $this->assertSame(
      [
        'tag' => 'img',
        'attributes' => [],
        'classes' => ['image-block-image'],
        'hoisted' => ['.image-block-image'],
        'parts' => [],
        'slots' => [],
      ],
      $components[1]['_element']['markup'],
    );
  }

  /**
   * The naming spine: `name` and `type` ride every node of the lean tree.
   *
   * The two facts are minted once here and delivered as data, read off
   * `layout`, the shape the satellite receives over the broadcast channel
   * (no facade, no `Drupal.t`). Per node kind and depth: a section of the
   * root layout is a Section, one nested inside a component list a
   * Container, and either is NAMED by its custom label when renamed, else
   * by its type word (never a humanized layout id); a fresh text block
   * with content is named by its content's first line, clamped at 80
   * characters with an ellipsis (empty content falls to the presentation's
   * label, the absorbed field's), and typed by its block type's label; a
   * heading-style block (label rendered as content) names by that
   * content through the same excerpt rule, so a long rendered label
   * clamps like any content and an empty one falls to the type word, the
   * rendered label never serving as a name by itself; a field block is
   * named and typed by the field's label; the root by its entity label
   * and bundle label.
   */
  public function testNamesAndTypesRideTheTree(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('node_type')
      ->create(['type' => 'landing_page', 'name' => 'Landing page'])
      ->save();
    FieldStorageConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_summary',
      'entity_type' => 'node',
      'bundle' => 'landing_page',
      'label' => 'Summary',
    ])->save();
    // A heading-style bundle: no content fields, so with a visible label
    // the model merges it into its label row (edit_target 'label').
    $this->createBlockType('heading');
    $root = $entity_type_manager->getStorage('node')
      ->create(['type' => 'landing_page', 'title' => 'Hello there', 'field_summary' => 'Placed']);

    $long = str_repeat('Welcome to the widest headline anyone ever wrote ', 3);
    $blueprint = [
      'bundle' => 'landing_page',
      'fields' => ['title' => 'Hello there', 'field_summary' => 'Placed'],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'renamed-band-uuid',
          'layout_id' => 'daedalus_stylish_container',
          'label' => 'Welcome',
          'children' => [
            [
              'kind' => 'block',
              'uuid' => 'fresh-text-uuid',
              'block_type' => 'text_block',
              'block_fields' => ['field_text' => "Try it\nA second line the name never shows"],
            ],
            [
              'kind' => 'container',
              'uuid' => 'nested-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'long-text-uuid',
                  'block_type' => 'text_block',
                  'block_fields' => ['field_text' => $long],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'empty-text-uuid',
                  'block_type' => 'text_block',
                  'block_fields' => ['field_text' => '   '],
                ],
              ],
            ],
          ],
        ],
        [
          'kind' => 'container',
          'uuid' => 'fresh-band-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'heading-uuid',
                  'block_type' => 'heading',
                  'label' => $long,
                  'label_rendered' => TRUE,
                  'block_fields' => [],
                ],
                // A blank label with display on renders nothing: the
                // serializer spells no label and no label_rendered.
                [
                  'kind' => 'block',
                  'uuid' => 'blank-heading-uuid',
                  'block_type' => 'heading',
                  'block_fields' => [],
                ],
                [
                  'kind' => 'block',
                  'uuid' => 'summary-block-uuid',
                  'field' => 'field_summary',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $this->host($root), 'full');
    $tree = $annotated['layout'];
    $names = static fn (array $node): array => [$node['_element']['name'], $node['_element']['type']];

    // The root (not a tree node) names by its entity label, types by its
    // bundle label.
    $this->assertSame(['Hello there', 'Landing page'], $names($annotated));

    // A renamed band: the custom label is its name, Section its type.
    $renamed = $tree[0];
    $this->assertSame('renamed-band-uuid', $renamed['uuid']);
    $this->assertSame(['Welcome', 'Section'], $names($renamed));

    // A fresh text block with content: its first line, and the block
    // type's label as the type (not the field label, not the machine id).
    $this->assertSame(['Try it', 'Text block'], $names($renamed['children'][0]));

    // A nested, unrenamed container: Container twice, never the layout id.
    $nested = $renamed['children'][1];
    $this->assertSame('nested-container-uuid', $nested['uuid']);
    $this->assertSame(['Container', 'Container'], $names($nested));

    // Content past the cap clamps to 79 characters plus an ellipsis, and
    // whitespace-only content is no name: the presentation's own label
    // (the absorbed field's definition label) stands in, before the type.
    $this->assertSame(mb_substr($long, 0, 79) . '…', $nested['children'][0]['_element']['name']);
    $this->assertSame(['Text', 'Text block'], $names($nested['children'][1]));

    // A fresh, unrenamed band: Section twice.
    $fresh = $tree[1];
    $this->assertSame('fresh-band-uuid', $fresh['uuid']);
    $this->assertSame(['Section', 'Section'], $names($fresh));

    // The heading-style block: the rendered label is CONTENT, so it names
    // through the excerpt rule (clamped like any content) and never as a
    // label; with nothing rendered the type word stands in.
    $blocks = $fresh['children'][0]['children'];
    $this->assertTrue($blocks[0]['label_rendered']);
    $this->assertSame([mb_substr($long, 0, 79) . '…', 'Heading'], $names($blocks[0]));
    $this->assertSame(['Heading', 'Heading'], $names($blocks[1]));

    // The field block: the field's label is both name and type.
    $this->assertSame('summary-block-uuid', $blocks[2]['uuid']);
    $this->assertSame(['Summary', 'Summary'], $names($blocks[2]));
  }

  /**
   * A container's and an inline block's icon are the providers' answer.
   *
   * The annotator answers its own kinds; a container and an inline block
   * are another module's concepts, so with no provider they carry NULL (the
   * pins above) and with providers the collector asks them in service
   * priority order, the first non-null answer winning: the test module's
   * leading provider names the container and passes on the block, which
   * its trailing provider names, although the trailing one is declared
   * first. A plugin block is never asked. What a provider reads rides the
   * caller's cacheability.
   */
  public function testIconProvidersAnswerContainersAndInlineBlocks(): void {
    $this->enableModules(['daedalus_ui_icon_test']);
    $annotator = $this->container->get('daedalus_ui.element_annotator');
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);
    $blueprint = [
      'bundle' => 'cta',
      'fields' => ['field_cta_text' => 'Hello'],
      'layout' => [
        [
          'kind' => 'container',
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'text-uuid',
                  'block_type' => 'text_block',
                  'block_fields' => ['field_text' => 'Some text'],
                ],
                ['kind' => 'block', 'uuid' => 'plugin-uuid', 'plugin_id' => 'system_powered_by_block'],
              ],
            ],
          ],
        ],
      ],
    ];

    $cacheability = new CacheableMetadata();
    $annotated = $annotator->annotate($blueprint, $this->host($root), 'full', $cacheability);
    $section = $annotated['layout'][0];
    $components = $section['children'][0]['children'];

    $this->assertSame('/leading/container.svg', $section['_element']['icon'], 'The higher-priority provider answers first, whatever the declaration order.');
    $this->assertSame(['name', 'type', 'icon', 'markup'], array_keys($section['_element']), 'The icon sits beside the two words.');
    $this->assertSame('/trailing/block.svg', $components[0]['_element']['icon'], 'A NULL answer falls through to the next provider.');
    $this->assertSame($this->glyph('plugin-block'), $components[1]['_element']['icon'], 'A plugin block is daedalus_ui\'s own kind: the providers are never asked.');
    $this->assertSame($this->glyph('page'), $annotated['_element']['icon'], 'The root is daedalus_ui\'s own kind too.');
    $this->assertContains(LeadingIconProvider::CACHE_TAG, $cacheability->getCacheTags(), 'What a provider read rides the caller\'s cacheability.');

    // The tree-only seam mints the same way.
    $tree = $annotator->annotateTree($blueprint['layout'], $this->host($root));
    $this->assertSame('/leading/container.svg', $tree[0]['_element']['icon']);
    $this->assertSame('/trailing/block.svg', $tree[0]['children'][0]['children'][0]['_element']['icon']);
  }

  /**
   * A join carries its words, the door glyph and the showing; roots their kind.
   *
   * A slot door is typed "Slot" and named by its derivative's admin label
   * (the roster's word with the kind's, "Hero slot"), wears daedalus_ui's
   * own slot glyph and carries `showing`: the spine's one answer for what
   * it shows seen from this canvas (VerdictStamper::showing()), the
   * portal's url resolved here. A linked placement carries the same
   * `showing` instead of a url of its own, and a plain plugin block, no
   * join, carries none. The portal rides only for an actor who may update
   * the shown blueprint. The frame's root is typed "Site frame", a saved
   * blueprint's "Blueprint" (not its blueprint type's bundle label).
   */
  public function testJoinsCarryTheShowingAndRootsTheirKind(): void {
    // daedalus_layout for the section uuid's config schema on the frame,
    // the library for the blueprint entity a door defaults to.
    $this->enableModules(['daedalus_layout', 'options', 'daedalus_blueprint_library']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->setUpCurrentUser([], [], TRUE);
    // The rebuilt container's annotator: setUp()'s predates the library.
    $annotator = $this->container->get('daedalus_ui.element_annotator');
    // A frame-tier slot: the roster save rebuilds the slot block
    // definitions, so daedalus_slot:hero exists at once.
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, ['hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME]])
      ->save();
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'A door default.',
      'scope' => 'block',
      // A sample of the subject renders the blueprint's canvas: a bundle
      // the sample generator can fill (no media reference).
      'subject_type' => 'block_content',
      'subject_bundle' => 'text_block',
      'status' => 1,
      'layout_builder__layout' => [new Section('layout_onecol')],
    ]);
    $blueprint->save();
    $uuid = $this->container->get('uuid');
    $door = $uuid->generate();
    $linked = $uuid->generate();
    $plain = $uuid->generate();
    $section = new Section('layout_onecol', [], [
      new SectionComponent($door, 'content', [
        'id' => SlotBlock::PLUGIN_ID . ':hero',
        // A door's configured label is never its name.
        'label' => 'Top of every page',
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
        SlotBlock::DEFAULT_KEY => 'card',
      ]),
      new SectionComponent($linked, 'content', [
        'id' => LinkedBlueprintBlock::pluginId('card'),
        'label_display' => '0',
        'context_mapping' => LinkedBlueprintBlock::CONTEXT_MAPPING,
      ]),
      new SectionComponent($plain, 'content', [
        'id' => 'system_powered_by_block',
        'label' => 'Chrome',
        'label_display' => '0',
      ]),
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid->generate());
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($section);
    $frame->save();

    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($frame);
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $annotate = fn (): array => $annotator->annotateTree($stamper->stampTree($tree, $host), $host);
    $nodes = $this->componentsByUuid($annotate());
    $document = ['entity_type' => 'blueprint', 'id' => (string) $blueprint->id(), 'label' => 'Card'];
    $portal = [
      'entity_type' => 'blueprint',
      'entity_id' => (string) $blueprint->id(),
      'label' => 'Card',
      'url' => $blueprint->toUrl()->toString(),
    ];

    $element = $nodes[$door]['_element'];
    $this->assertSame('Hero slot', $element['name'], 'The door is named by its derivative\'s admin label, never its configured label.');
    $this->assertSame('Slot', $element['type']);
    $this->assertSame($this->glyph('slot'), $element['icon']);
    $this->assertSame(['name', 'type', 'icon', 'can', 'refused', 'showing'], array_keys($element));
    $this->assertSame([
      'shows' => 'default',
      'document' => $document,
      'blueprint' => 'card',
      'portal' => $portal,
      'claimable' => FALSE,
    ], $element['showing'], 'The door shows its default by reference on the frame\'s own canvas, with the portal to the blueprint\'s canvas; a door is claimed from the page it frames, never from its own canvas.');

    $element = $nodes[$linked]['_element'];
    $this->assertSame('Card', $element['name']);
    $this->assertSame('Linked blueprint', $element['type']);
    $this->assertSame(['name', 'type', 'icon', 'can', 'refused', 'showing'], array_keys($element), 'The showing replaces the url the linked node once carried.');
    $this->assertSame([
      'shows' => 'default',
      'document' => $document,
      'blueprint' => 'card',
      'portal' => $portal,
      'claimable' => FALSE,
    ], $element['showing']);

    $this->assertSame(
      $this->pluginWords('Powered by Drupal', 'Powered by Drupal'),
      array_intersect_key($nodes[$plain]['_element'], array_flip(['name', 'type', 'icon'])),
      'A plugin block keeps its words and glyph.',
    );
    $this->assertArrayNotHasKey('showing', $nodes[$plain]['_element'], 'A plugin block that is no join carries no showing.');

    // An actor who may not update the blueprint sees what the door shows
    // and gets no portal: the spine's rule, copied, never re-judged here.
    $this->setCurrentUser($this->createUser([]));
    $nodes = $this->componentsByUuid($annotate());
    $this->assertSame('default', $nodes[$door]['_element']['showing']['shows']);
    $this->assertNull($nodes[$door]['_element']['showing']['portal']);
    $this->assertNull($nodes[$linked]['_element']['showing']['portal']);

    // The roots: the frame's and a saved blueprint's.
    $root = $annotator->annotate(['layout' => []], $host, 'full')['_element'];
    $this->assertSame('Stark', $root['name']);
    $this->assertSame('Site frame', $root['type']);
    $this->assertSame($this->glyph('template'), $root['icon']);
    $blueprint_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($blueprint);
    $root = $annotator->annotate(['bundle' => 'section', 'fields' => [], 'layout' => []], $blueprint_host, 'full')['_element'];
    $this->assertSame('Card', $root['name']);
    $this->assertSame('Blueprint', $root['type'], 'A blueprint\'s canvas root is typed by what it is to the editor, not by its blueprint type.');
    $this->assertSame($this->glyph('page'), $root['icon']);
  }

  /**
   * The component nodes of a tree's first section, keyed by uuid.
   */
  protected function componentsByUuid(array $tree): array {
    $nodes = [];
    foreach ($tree[0]['children'] ?? [] as $child) {
      foreach (($child['kind'] ?? NULL) === 'region' ? $child['children'] : [$child] as $node) {
        $nodes[$node['uuid']] = $node;
      }
    }
    return $nodes;
  }

  /**
   * A plugin block's whole annotation: its two words and the plugin glyph.
   */
  protected function pluginWords(string $name, string $type): array {
    return ['name' => $name, 'type' => $type, 'icon' => $this->glyph('plugin-block')];
  }

  /**
   * The URL of one of daedalus_ui's own outline glyphs (assets/outline).
   */
  protected function glyph(string $name): string {
    return '/' . $this->container->get('extension.list.module')->getPath('daedalus_ui') . '/assets/outline/' . $name . '.svg';
  }

  /**
   * The empty resolver tree: a declaration with no tag and no parts.
   *
   * @return array
   *   The resolved shape ElementResolver returns for such declarations,
   *   the root markup on kinds that declare only field facts.
   */
  protected function emptyMarkup(): array {
    return [
      'tag' => NULL,
      'attributes' => [],
      'classes' => [],
      'hoisted' => [],
      'parts' => [],
      'slots' => [],
    ];
  }

}
