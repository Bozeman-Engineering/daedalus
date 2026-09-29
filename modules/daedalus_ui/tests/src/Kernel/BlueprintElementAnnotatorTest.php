<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the delivered-blueprint `_element` annotations.
 *
 * The annotator is a pure walk over a delivered blueprint calling the one
 * model, so the fixture is a hand-built blueprint array over real bundle
 * config — the wire shapes the serializer emits, without paying for a full
 * Layout Builder render. One uniform annotation on every owning node, the
 * root included: the naming spine's `name` and `type`, presentation,
 * compose, the resolver's markup tree, and per-field facts (existence +
 * presentation + rich-text instances) all
 * ride `_element`. Markup for components resolves against the entities the
 * root's REAL section data carries, so the hand-built nodes here (no
 * backing section entity) carry no component markup, the annotation only
 * claims what the data can back, while sections and the root, which need
 * no entity lookup, are stamped on every walk.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class BlueprintElementAnnotatorTest extends KernelTestBase {

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
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'merged-uuid',
                'block_type' => 'text_block',
                'label' => 'Machine label',
                'label_display' => '0',
                'block_fields' => ['field_text' => 'Some text'],
              ],
              [
                'uuid' => 'open-uuid',
                'block_type' => 'cta',
                'label' => 'CTA',
                'label_display' => '0',
                'block_fields' => [
                  'field_cta_text' => 'CTA text',
                  'field_cta_image' => ['target_id' => 4, '_label' => 'CTA picture'],
                ],
              ],
              [
                'uuid' => 'plugin-uuid',
                'plugin_id' => 'system_powered_by_block',
              ],
              [
                'uuid' => 'collapsed-plugin-uuid',
                'plugin_id' => 'collapse_test_cooperative',
              ],
              // A nested SECTION node: a region entry carrying layout_id
              // presents as a section, and its components ride the same walk.
              [
                'uuid' => 'nested-section-uuid',
                'layout_id' => 'layout_onecol',
                'regions' => [
                  'content' => [
                    [
                      'uuid' => 'nested-collapsed-uuid',
                      'block_type' => 'text_block',
                      'label' => 'Nested',
                      'label_display' => '0',
                      'block_fields' => ['field_text' => 'Nested text'],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');

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
        'presentation' => ['mode' => Presentation::OPEN, 'edit_target' => 'info'],
        'markup' => $this->emptyMarkup(),
        'fields' => ['field_cta_image' => ['presentation' => Presentation::UNIT]],
      ],
      $annotated['_element'],
    );

    $components = $annotated['layout_builder__layout'][0]['regions']['content'];

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
    // degrades to its id, the one fact left.
    $this->assertSame(['name' => 'Powered by Drupal', 'type' => 'Powered by Drupal'], $components[2]['_element']);
    $this->assertSame(['name' => 'collapse_test_cooperative', 'type' => 'collapse_test_cooperative'], $components[3]['_element']);

    // The walk reaches nested sections: the section node presents AS a
    // section (no component annotation, no block facade) but carries the
    // layout's resolved markup, at NESTED depth — the container's
    // multi-form root resolves the plain div there — and its inner
    // components are annotated like any others. Nested, it is a Container
    // in the layout vocabulary, and unrenamed its name is that type word.
    $this->assertSame(['name', 'type', 'markup'], array_keys($components[4]['_element']));
    $this->assertSame('Container', $components[4]['_element']['name']);
    $this->assertSame('Container', $components[4]['_element']['type']);
    $this->assertSame('div', $components[4]['_element']['markup']['tag']);
    $this->assertSame([], $components[4]['_element']['markup']['attributes'], 'A populated section is not empty; the annotator fed the fact from its component lists.');
    $nested = $components[4]['regions']['content'][0];
    $this->assertTrue($nested['_element']['composed']);

    // The top-level section resolves the same declaration at ROOT depth:
    // ONE box, a real <section>, no parts and no slots (children mount at
    // the root per the mounting rule). At page depth it is a Section.
    $section = $annotated['layout_builder__layout'][0];
    $this->assertSame('Section', $section['_element']['name']);
    $this->assertSame('Section', $section['_element']['type']);
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
   * The walk covers container sections' ordered children lists.
   *
   * A container section serializes `children: []` in place of
   * `regions: {}` (its sole region is a storage vestige); the annotator
   * annotates the list's components and recurses into nested container
   * nodes exactly like region entries.
   */
  public function testAnnotatesContainerChildren(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'bundle' => 'cta',
      'fields' => [],
      'layout_builder__layout' => [
        [
          'uuid' => 'container-uuid',
          'layout_id' => 'daedalus_stylish_container',
          'children' => [
            [
              'uuid' => 'merged-uuid',
              'block_type' => 'text_block',
              'label' => 'Machine label',
              'label_display' => '0',
              'block_fields' => ['field_text' => 'Some text'],
            ],
            // A nested container node inside a children list.
            [
              'uuid' => 'nested-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [
                [
                  'uuid' => 'nested-block-uuid',
                  'block_type' => 'text_block',
                  'label' => 'Nested',
                  'label_display' => '0',
                  'block_fields' => ['field_text' => 'Nested text'],
                ],
              ],
            ],
            // An EMPTY nested container: no children at all, so the
            // annotator's emptiness feed answers the {empty: true} fact.
            [
              'uuid' => 'empty-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $children = $annotated['layout_builder__layout'][0]['children'];

    $this->assertSame(
      [
        'name' => 'Some text',
        'type' => 'Text block',
        'presentation' => ['mode' => Presentation::MERGED, 'label' => 'Text', 'edit_target' => 'field_text'],
        'composed' => TRUE,
      ],
      $children[0]['_element'],
    );

    // The nested container node presents AS a section (markup only, no
    // block facade) at NESTED depth — the multi-form root resolves the
    // plain div — and its inner components are annotated like any others.
    $this->assertSame(['name', 'type', 'markup'], array_keys($children[1]['_element']));
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
    $this->assertSame('section', $annotated['layout_builder__layout'][0]['_element']['markup']['tag']);
    $this->assertSame([], $annotated['layout_builder__layout'][0]['_element']['markup']['attributes']);
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
      'layout_builder__layout' => [],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');

    $this->assertSame(
      [
        'name' => 'Hello there',
        'type' => 'Landing page',
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
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              ['uuid' => 'summary-block-uuid', 'plugin_id' => 'field_block:node:landing_page:field_summary'],
              ['uuid' => 'foreign-field-uuid', 'plugin_id' => 'field_block:node:article:field_summary'],
              ['uuid' => 'missing-field-uuid', 'plugin_id' => 'field_block:node:landing_page:field_nope'],
              ['uuid' => 'plugin-uuid', 'plugin_id' => 'system_powered_by_block'],
              ['uuid' => 'named-plugin-uuid', 'plugin_id' => 'system_powered_by_block', 'label' => 'Footer credits', 'label_display' => '0'],
              ['uuid' => 'headed-plugin-uuid', 'plugin_id' => 'system_powered_by_block', 'label' => 'Footer credits', 'label_display' => 'visible'],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $components = $annotated['layout_builder__layout'][0]['regions']['content'];

    $this->assertSame(
      [
        'name' => 'Summary',
        'type' => 'Summary',
        'presentation' => ['mode' => Presentation::MERGED, 'label' => 'Summary', 'edit_target' => 'field_summary'],
        'presented_field' => 'field:' . $root->uuid() . ':field_summary',
      ],
      $components[0]['_element'],
      'The field block presents merged into the root field it renders and names it by key; the field label is both its name and its type.',
    );
    // A field block of another bundle, or of a field the root lacks,
    // presents nothing of this root: no presentation, no presented field.
    // Like any plugin block it still names itself, here by its id, since
    // no such field block derivative exists to lend an admin label.
    $this->assertSame(
      ['name' => 'field_block:node:article:field_summary', 'type' => 'field_block:node:article:field_summary'],
      $components[1]['_element'],
      'A field block of another bundle presents nothing of this root.',
    );
    $this->assertSame(
      ['name' => 'field_block:node:landing_page:field_nope', 'type' => 'field_block:node:landing_page:field_nope'],
      $components[2]['_element'],
      'A field block of a field the root lacks presents nothing of it.',
    );
    // A plain plugin block: the admin label is its type; a hidden
    // configured label is its name, a rendered one is content, so the type
    // stands in.
    $this->assertSame(['name' => 'Powered by Drupal', 'type' => 'Powered by Drupal'], $components[3]['_element']);
    $this->assertSame(['name' => 'Footer credits', 'type' => 'Powered by Drupal'], $components[4]['_element']);
    $this->assertSame(['name' => 'Powered by Drupal', 'type' => 'Powered by Drupal'], $components[5]['_element']);
    // The lean tree carries the same facts.
    $tree_block = $annotated['_tree'][0]['children'][0]['children'][0];
    $this->assertSame('summary-block-uuid', $tree_block['uuid']);
    $this->assertSame('field:' . $root->uuid() . ':field_summary', $tree_block['_element']['presented_field']);
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
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'cta-uuid',
                'block_type' => 'cta',
                'label' => 'CTA',
                'label_display' => '0',
                'block_fields' => [
                  'field_cta_text' => 'CTA text',
                  'field_cta_image' => ['target_id' => 4, '_label' => 'CTA picture'],
                ],
              ],
              [
                'uuid' => 'displayless-uuid',
                'block_type' => 'text_block',
                'label' => 'Text',
                'label_display' => '0',
                'block_fields' => ['field_text' => 'Some text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $components = $annotated['layout_builder__layout'][0]['regions']['content'];

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
   * A visible label joins the meaningful-content count at the delivery seam.
   */
  public function testLabelDisplayFeedsTheDerivedDefault(): void {
    $root = $this->container->get('entity_type.manager')
      ->getStorage('block_content')
      ->create(['type' => 'cta', 'info' => 'Root fixture']);

    $blueprint = [
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'labeled-uuid',
                'block_type' => 'text_block',
                'label' => 'Shown label',
                'label_display' => 'visible',
                'block_fields' => ['field_text' => 'Some text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $component = $annotated['layout_builder__layout'][0]['regions']['content'][0];

    // Visible label + one field = two meaningful things: open.
    $this->assertSame(['mode' => Presentation::OPEN], $component['_element']['presentation']);
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
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => $component_uuid,
                'block_type' => 'text',
                'label' => 'Linked heading',
                'label_display' => '0',
                'block_fields' => ['field_text_text' => 'Read more'],
              ],
              [
                'uuid' => $unminted_uuid,
                'block_type' => 'text',
                'label' => 'Unminted prose',
                'label_display' => '0',
                'block_fields' => ['field_text_text' => 'Plain'],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');

    // The component's markup: the data-decided tag, the present link part
    // carrying its resolved href, the text slot claimed by the anchor,
    // the composed block's instance elements, first-carrier-owned, nested
    // carriers nesting, and the distinct tags the prose contains (the
    // unminted `strong` included) in first-occurrence order.
    $component = $annotated['layout_builder__layout'][0]['regions']['content'][0];
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
    $unminted_markup = $annotated['layout_builder__layout'][0]['regions']['content'][1]['_element']['markup'];
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
      'layout_builder__layout' => [
        [
          'uuid' => 'section-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => $linked_uuid,
                'block_type' => 'image',
                'label' => 'Linked vector',
                'label_display' => '0',
                'block_fields' => [],
              ],
              [
                'uuid' => $unlinked_uuid,
                'block_type' => 'image',
                'label' => 'Unlinked raster',
                'label_display' => '0',
                'block_fields' => [],
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $components = $annotated['layout_builder__layout'][0]['regions']['content'];

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
   * The two facts are minted once here and delivered as data, so the
   * assertions read them off `_tree`, the shape the satellite receives
   * over the broadcast channel (no facade, no `Drupal.t`), not only off
   * `layout_builder__layout`. Per node kind and depth: a section of the
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
      'layout_builder__layout' => [
        [
          'uuid' => 'renamed-band-uuid',
          'layout_id' => 'daedalus_stylish_container',
          'label' => 'Welcome',
          'children' => [
            [
              'uuid' => 'fresh-text-uuid',
              'block_type' => 'text_block',
              'label' => '',
              'label_display' => '0',
              'block_fields' => ['field_text' => "Try it\nA second line the name never shows"],
            ],
            [
              'uuid' => 'nested-container-uuid',
              'layout_id' => 'daedalus_stylish_container',
              'children' => [
                [
                  'uuid' => 'long-text-uuid',
                  'block_type' => 'text_block',
                  'label' => '',
                  'label_display' => '0',
                  'block_fields' => ['field_text' => $long],
                ],
                [
                  'uuid' => 'empty-text-uuid',
                  'block_type' => 'text_block',
                  'label' => '',
                  'label_display' => '0',
                  'block_fields' => ['field_text' => '   '],
                ],
              ],
            ],
          ],
        ],
        [
          'uuid' => 'fresh-band-uuid',
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'heading-uuid',
                'block_type' => 'heading',
                'label' => $long,
                'label_display' => 'visible',
                'block_fields' => [],
              ],
              [
                'uuid' => 'blank-heading-uuid',
                'block_type' => 'heading',
                'label' => '',
                'label_display' => 'visible',
                'block_fields' => [],
              ],
              [
                'uuid' => 'summary-block-uuid',
                'plugin_id' => 'field_block:node:landing_page:field_summary',
              ],
            ],
          ],
        ],
      ],
    ];

    $annotated = $this->annotator->annotate($blueprint, $root, 'full');
    $tree = $annotated['_tree'];
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
