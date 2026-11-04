<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_materials_test\TestGroupsContributor;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Eb_read_schema answers a page-builder bundle in the page language's view.
 *
 * The JSON schema (~10k tokens) landing right before the write outranks
 * the cached catalog's closed roster on recency with its `stylable`
 * SETS, and the writer pads the cards. On the dialect door the schema
 * must carry what the catalog does not (entity fields, what each element
 * is for, the materials, the blueprints) and nothing that contradicts or
 * duplicates it (the layout node grammar, the style-map key grammar,
 * stylable sets, conditions, anatomy trees), and each element entry
 * carries what it renders as from the catalog's render card (`renders`:
 * dialect writes paired with the HTML each renders as, or the one
 * sentence naming the shells an undeclared type renders through). A
 * bundle without a layout keeps the JSON
 * schema: that door is still eb_create_entity/eb_edit's.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class ReadSchemaProjectionTest extends BlueprintKernelTestBase {

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
      'link',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'file',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_guide_test',
      // The eb_test_container layout: the container layout the real
      // catalog builder derives containerLayoutId from.
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster queries the stylish material storages.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);
    $this->createBlockField('field_link', 'link', 'basic');
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
   * A layout bundle answers in the dialect's view; the JSON grammar is gone.
   */
  public function testLayoutBundleAnswersInThePageLanguage(): void {
    $output = $this->runTool('eb_read_schema', ['entity_type' => 'entity_test', 'bundle' => 'entity_test']);

    $this->assertStringContainsString('This bundle carries a page-builder layout', $output);
    $this->assertStringContainsString('elements:', $output);
    $this->assertStringContainsString('d-container:', $output);
    $this->assertStringContainsString('d-basic:', $output);
    $this->assertStringContainsString('field: field_heading', $output);
    $this->assertStringContainsString('materials:', $output);
    // The dialect door's materials: the guidance says where the rest is
    // (the brief) and that a kind this shape carries is absent only when
    // the site has none; the fixture saves no blueprint, animation or
    // font, so the narrowed shape is the guidance alone and the
    // structure line says so.
    $this->assertStringNotContainsString('variants:', $output);
    $this->assertStringContainsString('one of those kinds absent here has none on this site', $output);
    $this->assertStringContainsString('and no materials beyond the brief', $output);

    foreach ([
      'section_structure',
      'block_structure',
      'available_block_types',
      'available_layouts',
      'stylable:',
      'daedalus_stylish_styles',
      'anatomy',
      'current_values',
      'conditions:',
      'selectable key',
      'update_style',
      'style_guide',
      'bindings:',
      'deviations:',
      'tokens:',
      'materials.variants',
    ] as $residue) {
      $this->assertStringNotContainsString($residue, $output, "The dialect view carries no '$residue'.");
    }
  }

  /**
   * The projection's shape, asserted on the spine directly.
   */
  public function testProjectionShape(): void {
    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test');
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $projector = $this->container->get('daedalus_blueprint.dialect_schema_projector');

    $this->assertTrue($projector->applies($schema, $catalog));
    $projected = $projector->project($schema, $catalog);

    $this->assertSame(['entity_type', 'bundle', 'label', '_structure', 'fields', 'elements', 'materials'], array_keys($projected));
    $this->assertArrayNotHasKey('styles', $projected);
    $this->assertArrayNotHasKey('style_guide', $projected, 'The style guide\'s law (bindings, deviations, guidance) is the brief\'s on this door.');
    // The materials narrow to what the brief does not carry: the dialect
    // shape, its own guidance, never a token or variant.
    $materials_schema = $this->container->get('daedalus_blueprint.materials_schema');
    $this->assertSame($materials_schema->dialect($schema['materials']), $projected['materials'], 'The schema\'s own materials key carries through the dialect narrowing.');
    $this->assertSame([], array_diff(array_keys($projected['materials']), MaterialsSchema::DIALECT_KEYS));
    $this->assertStringStartsWith(MaterialsSchema::DIALECT_OPENING, $projected['materials']['guidance']);
    $this->assertStringNotContainsString(MaterialsSchema::DIALECT_OPENING, $schema['materials']['guidance'], 'The JSON door\'s fragment keeps its own paragraph.');
    foreach (['tokens', 'variants'] as $brief_kind) {
      $this->assertArrayNotHasKey($brief_kind, $projected['materials'], "The $brief_kind are the brief's.");
    }
    $this->assertStringContainsString('the design system brief (every token, variant, animation and font with its whole description and value, the bindings and deviations, the blueprints as a menu)', $projected['_structure']);
    // This fixture saves no blueprint, animation or font: the narrowed
    // shape is the guidance alone and the structure line says so (the
    // kinds listing is pinned where a blueprint rides, below).
    $this->assertSame(['guidance'], array_keys($projected['materials']));
    $this->assertStringContainsString('and no materials beyond the brief\'s.', $projected['_structure']);
    $this->assertStringNotContainsString('first sentence', $projected['_structure']);
    $this->assertStringNotContainsString('materials in full', $projected['_structure']);
    $this->assertArrayNotHasKey('starts', $projected, 'No page blueprint fits this bundle, so no starts ride (grows-by-data).');
    $this->assertStringNotContainsString('"starts"', $projected['_structure']);
    $this->assertArrayNotHasKey('materials', $schema['styles']['style_guide'], 'The materials are never the field\'s.');
    $this->assertArrayHasKey('guidance', $schema['styles']['style_guide'], 'The JSON door\'s style guide is untouched.');

    $this->assertSame(['d-container', 'd-slot', 'd-linked', 'd-basic'], array_keys($projected['elements']));
    $this->assertSame(['name'], array_keys($projected['elements']['d-slot']['attributes']), 'The slot form takes the container\'s attributes with name as the slot id (no axes on this catalog).');
    $this->assertStringContainsString('fills a slot', $projected['elements']['d-slot']['guidance']);
    $this->assertSame(['blueprint'], array_keys($projected['elements']['d-linked']['attributes']), 'The linked placement takes the blueprint it shows (no axes on this catalog).');
    $this->assertStringContainsString('by reference', $projected['elements']['d-linked']['guidance']);
    $basic = $projected['elements']['d-basic'];
    $this->assertSame('Basic', $basic['label']);
    $this->assertSame(['field' => 'field_heading'], $basic['attributes']['heading']);
    $this->assertSame('field_link', $basic['attributes']['link']['field']);
    $this->assertStringContainsString('"<nolink>"', $basic['attributes']['link']['guidance'], 'Link attributes teach the authorable grammar.');
    $this->assertArrayNotHasKey('body', $basic);
    // The live cards: a bundle no layer declares renders through the kind
    // shells (daedalus_ui's fallback card), the container through its
    // layout's declaration.
    $this->assertSame('through the theme\'s block template, each field in its field shell', $basic['renders']);
    $container_renders = $projected['elements']['d-container']['renders'];
    $this->assertIsArray($container_renders, 'The container is declared: pairs, not shells.');
    $this->assertNotEmpty($container_renders);
    foreach ($container_renders as $input => $html) {
      $this->assertStringStartsWith('<d-container', $input);
      $this->assertMatchesRegularExpression('/^<(section|div)[ >]/', $html);
    }
  }

  /**
   * Each element entry carries its render card, the generic entry the fallback.
   *
   * The schema register of the markup model: `renders` as the card's
   * pairs (the image's four, the text block's three, the container's
   * depth pair), or the shells sentence on a type that renders through
   * kind shells, the generic <d-block type> entry of a bundle the
   * contract cannot spell carrying the fallback under its own owner
   * spelling. A catalog carrying no card for an element projects no
   * `renders` for it.
   */
  public function testRendersRideTheElements(): void {
    $projector = $this->container->get('daedalus_blueprint.dialect_schema_projector');
    $text_renders = [
      '<d-text>…</d-text>' => '<p>…</p>',
      '<d-text url="…">…</d-text>' => '<p><a class="text-block-link" href="…">…</a></p>',
      '<d-text tag="h1" url="…">…</d-text>' => '<h1><a class="text-block-link" href="…">…</a></h1>',
    ];
    $image_renders = [
      '<d-image/>' => '<div></div>',
      '<d-image media="…"/> (raster media)' => '<img class="image-block-image">',
      '<d-image media="…"/> (vector media)' => '<svg class="image-block-image"></svg>',
      '<d-image media="…" url="…"/> (raster media)' => '<a href="…"><img class="image-block-image"></a>',
    ];
    $container_renders = [
      '<d-container>…</d-container> (top-level)' => '<section>…</section>',
      '<d-container>…</d-container> (nested)' => '<div>…</div>',
    ];
    $fallback = ['renders' => [], 'shells' => ['block', 'field']];
    $cards = [
      'd-container' => ['renders' => $container_renders, 'shells' => []],
      'd-text' => ['renders' => $text_renders, 'shells' => []],
      'd-image' => ['renders' => $image_renders, 'shells' => []],
      DialectCatalog::ownerForGenericBlockType('gallery') => $fallback,
    ];
    $block_types = [
      'text' => [
        'attributes' => ['tag' => ['field' => 'field_tag', 'values' => ['h1', 'p']]],
        'body' => ['field' => 'field_text', 'kind' => 'plain'],
      ],
      'image' => ['attributes' => [], 'void' => TRUE],
    ];
    $schema = [
      'layout' => [
        'available_block_types' => [
          'text' => ['label' => 'Text'],
          'image' => ['label' => 'Image'],
          'gallery' => ['label' => 'Gallery', 'block_fields' => ['field_images' => ['type' => 'entity_reference']]],
        ],
      ],
    ];
    $catalog = new DialectCatalog(containerLayoutId: 'layout_onecol', axes: [], blockTypes: $block_types, conditions: [], cards: $cards);
    $elements = $projector->project($schema, $catalog)['elements'];

    $this->assertSame(['d-container', 'd-slot', 'd-linked', 'd-text', 'd-image', 'd-block type="gallery"'], array_keys($elements));
    $this->assertSame($image_renders, $elements['d-image']['renders']);
    $this->assertSame($text_renders, $elements['d-text']['renders'], 'The pairs ride as the card carries them, plainest first.');
    $this->assertSame($container_renders, $elements['d-container']['renders']);
    $this->assertSame(['form', 'fields', 'renders'], array_keys($elements['d-block type="gallery"']));
    $this->assertSame('through the theme\'s block template, each field in its field shell', $elements['d-block type="gallery"']['renders'], 'The generic entry carries the fallback: the kind shells it renders through, as one sentence.');
    foreach (['d-slot', 'd-linked'] as $tag) {
      $this->assertArrayNotHasKey('renders', $elements[$tag], "$tag is a form of another element's card.");
    }

    $bare = new DialectCatalog(containerLayoutId: 'layout_onecol', axes: [], blockTypes: $block_types, conditions: []);
    foreach ($projector->project($schema, $bare)['elements'] as $tag => $entry) {
      $this->assertArrayNotHasKey('renders', $entry, "No card, no renders on $tag.");
    }
  }

  /**
   * A page bundle's starts ride the projection.
   *
   * A page blueprint is nowhere else.
   *
   * The hook lands `starts` on the schema of a bundle whose host owns a
   * document (the offer asked for page scope: a root, fits), the
   * projector carries it through with a structure sentence naming it,
   * and the tool prints it. The same page blueprint is absent from
   * materials.blueprints (block scope only), a page blueprint whose
   * allow-list names another bundle is not a start here, and a block
   * blueprint the bundle is offered stays in the fragment and out of the
   * starts. The fixture provider stands in for the library: the spine
   * knows a blueprint through its record alone.
   */
  public function testStartsRideTheProjection(): void {
    $this->enableModules(['daedalus_blueprint_materials_test']);
    // daedalus_stylish already teaches the token gesture; two providers
    // may not, so the fixture teaches none here.
    TestMaterialsProvider::$gestureLines = [];
    $tree = [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]];
    TestMaterialsProvider::$records = [
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'landing',
        label: 'Landing',
        description: 'A whole landing page: hero, three columns, a closing call.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'landing'],
        blueprintScope: MaterialRecord::SCOPE_PAGE,
        entityId: '9',
        tree: $tree,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'article_page',
        label: 'Article page',
        description: 'For articles only.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'article_page'],
        blueprintScope: MaterialRecord::SCOPE_PAGE,
        tree: $tree,
        requires: [BlueprintRequirements::BUNDLES => ['node:article']],
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'section',
        label: 'Section',
        description: 'A block blueprint any host can hold.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'section'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        tree: $tree,
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();

    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test');
    $starts = [
      'landing' => [
        'label' => 'Landing',
        'description' => 'A whole landing page: hero, three columns, a closing call.',
        'entity_id' => '9',
      ],
    ];
    $this->assertSame($starts, $schema['starts']);
    $this->assertSame(['section'], array_keys($schema['materials']['blueprints']), 'The shared block blueprint lists in full; the page blueprint is a start.');
    $this->assertSame([DialectCatalog::UNGROUPED => ['blueprints' => 1]], $schema['materials']['groups'], 'The index counts block scope alone.');

    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $projected = $this->container->get('daedalus_blueprint.dialect_schema_projector')->project($schema, $catalog);
    $keys = [
      'entity_type',
      'bundle',
      'label',
      '_structure',
      'fields',
      'elements',
      'materials',
      'starts',
    ];
    $this->assertSame($keys, array_keys($projected));
    $this->assertSame($schema['starts'], $projected['starts']);
    $this->assertSame(['guidance', 'groups', 'blueprints'], array_keys($projected['materials']), 'The narrowed shape: the index and the blueprint in full.');
    $this->assertSame($schema['materials']['blueprints'], $projected['materials']['blueprints']);
    $this->assertStringContainsString('the materials composing reads on demand (blueprints: the blueprints in full, the animations\' steps, the fonts\' faces)', $projected['_structure'], 'The structure line names the kinds the narrowed shape carries.');
    $this->assertStringContainsString('"starts" lists the page blueprints a page of this bundle may start as', $projected['_structure']);
    $this->assertStringContainsString('page_create\'s start takes one of their ids, blank or template, instead of a layout', $projected['_structure']);

    $output = $this->runTool('eb_read_schema', ['entity_type' => 'entity_test', 'bundle' => 'entity_test']);
    $this->assertStringContainsString('starts:', $output);
    $this->assertStringContainsString('landing:', $output);
    $this->assertStringContainsString('three columns, a closing call', $output);
    $this->assertStringNotContainsString('article_page', $output);
    $this->assertSame(1, substr_count($output, 'A whole landing page'), 'The page blueprint prints once, under starts, never under materials.');
  }

  /**
   * The schema read lists every shared blueprint, one group alone on demand.
   *
   * By default materials.blueprints holds every shared block blueprint in
   * full, favorite or not (the flag is the Add panel's listing), and
   * materials.groups the vocabulary with counts (the reserved bucket for
   * the ones in no group included). With `group`, materials.blueprints
   * holds that group's shared block blueprints alone, narrowed AFTER
   * the cached schema is read: a default read right after answers the
   * whole set again, and the index rides every answer unchanged. The
   * reserved bucket lists the ones in no group; an unknown group answers
   * a corrective naming the ids; a bundle whose schema carries no
   * materials answers a corrective too.
   */
  public function testGroupNarrowsTheSchemaRead(): void {
    $this->enableModules(['daedalus_blueprint_materials_test']);
    TestMaterialsProvider::$gestureLines = [];
    TestGroupsContributor::$groups = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.'],
      'sections' => ['label' => 'Sections'],
    ];
    $tree = [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]];
    $record = static fn (string $id, array $groups, bool $favorite) => new MaterialRecord(
      kind: MaterialKind::Blueprint,
      id: $id,
      label: ucfirst(str_replace('_', ' ', $id)),
      description: "The $id blueprint.",
      spellings: [MaterialRecord::SLOT_BLUEPRINT => $id],
      blueprintScope: MaterialRecord::SCOPE_BLOCK,
      groups: $groups,
      favorite: $favorite,
      tree: $tree,
    );
    TestMaterialsProvider::$records = [
      $record('opener', ['heroes'], TRUE),
      $record('wide_section', ['sections'], FALSE),
      $record('stray_card', [], FALSE),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();

    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test');
    $this->assertSame(['opener', 'wide_section', 'stray_card'], array_keys($schema['materials']['blueprints']), 'The default listing is every shared block blueprint, favorite or not.');
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.', 'blueprints' => 1],
      'sections' => ['label' => 'Sections', 'blueprints' => 1],
      DialectCatalog::UNGROUPED => ['blueprints' => 1],
    ], $schema['materials']['groups']);

    $read = fn (?string $group = NULL): string => $this->runTool('eb_read_schema', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ] + ($group === NULL ? [] : ['group' => $group]));
    $default = $read();
    $this->assertStringContainsString("  blueprints:\n    opener:\n", $default);
    $this->assertStringContainsString("\n    wide_section:\n", $default);
    $this->assertStringContainsString("\n    stray_card:\n", $default);
    $this->assertStringContainsString("  groups:\n    heroes:\n", $default);
    $this->assertStringContainsString("    ungrouped:\n      blueprints: 1\n", $default);

    $sections = $read('sections');
    $this->assertStringContainsString("  blueprints:\n    wide_section:\n", $sections);
    $this->assertStringContainsString('<d-container', $sections, 'The group prints in full.');
    $this->assertStringNotContainsString('opener:', $sections, 'The whole set is replaced by the group.');
    $this->assertStringNotContainsString('stray_card:', $sections);
    $this->assertStringContainsString("  groups:\n    heroes:\n", $sections, 'The index rides every answer.');

    $ungrouped = $read(DialectCatalog::UNGROUPED);
    $this->assertStringContainsString("  blueprints:\n    stray_card:\n", $ungrouped);
    $this->assertStringNotContainsString('opener:', $ungrouped);
    $this->assertStringNotContainsString('wide_section:', $ungrouped);

    $again = $read();
    $this->assertSame($default, $again, 'The narrowing never reaches the cached schema.');

    $unknown = $read('cards');
    $this->assertSame('Error: unknown group "cards". The groups are heroes, sections (materials.groups in the schema read); ungrouped lists the blueprints in no group. Pass one of them, or omit group for every shared blueprint.', $unknown);

    $none = $this->runTool('eb_read_schema', [
      'entity_type' => 'block_content',
      'bundle' => 'basic',
      'group' => 'sections',
    ]);
    $this->assertSame('Error: group narrows materials.blueprints, and the schema of block_content/basic carries no materials.', $none);
  }

  /**
   * The projector derives what the catalog carries and copies no guidance.
   *
   * Generation speaks in the image field handler's door-neutral words (the
   * generation entry the schema builder carried in, never the JSON door's
   * guidance), the sizing attribute leans on it, a placement's birth line
   * spells the catalog's birth defaults by attribute and value, and the tag
   * attribute names the tags the skin dresses; a catalog carrying none of these
   * projects none of them.
   */
  public function testProjectorDerivesGuidanceBirthAndDressedTags(): void {
    $projector = $this->container->get('daedalus_blueprint.dialect_schema_projector');
    $slot = MaterialRecord::attrSlot('block_type:text', 'button');
    $roster = new MaterialsRoster([
      new MaterialRecord(kind: MaterialKind::Variant, id: 'button_primary', label: 'Primary', group: 'button', spellings: [$slot => 'primary'], scope: Address::fromString('block_type:text')),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [MaterialRecord::attrSlot('kind:container', 'width') => 'site'], scope: Address::fromString('kind:container')),
    ], new CacheableMetadata());
    $block_types = [
      'text' => ['attributes' => ['tag' => ['field' => 'field_tag', 'values' => ['h1', 'h2', 'p', 'span']]]],
      'image' => [
        'attributes' => [
          'generate' => ['field' => 'field_image', 'key' => '_generate'],
          'aspect-ratio' => ['field' => 'field_image', 'key' => 'aspectRatio', 'values' => ['1:1', '16:9']],
        ],
      ],
    ];
    $schema = [
      'layout' => [
        'available_block_types' => [
          'text' => ['label' => 'Text'],
          'image' => [
            'label' => 'Image',
            'block_fields' => [
              'field_image' => [
                'guidance' => 'THE JSON DOOR\'S {_generate} GRAMMAR.',
                'generation' => [
                  'guidance' => 'THE HANDLER\'S OWN WORDS, ASPECT RATIO INCLUDED.',
                  'dimension_key' => 'aspectRatio',
                  'dimension_values' => ['1:1', '16:9'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
    $catalog = new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: ['kind:container' => ['width' => []], 'block_type:text' => ['button' => []]],
      blockTypes: $block_types,
      conditions: [],
      roster: $roster,
      birthDefaults: [
        'layout:layout_onecol' => ['width' => 'container_site'],
        'block_type:text' => ['button' => 'button_primary'],
      ],
      dressedTags: ['h1', 'h2', 'p', 'a'],
    );
    $elements = $projector->project($schema, $catalog)['elements'];
    $this->assertSame('A placement from the palette is born wearing width="site"; a document states what it wears itself (an omitted axis is not worn).', $elements['d-container']['born_wearing']);
    $this->assertSame('A placement from the palette is born wearing button="primary"; a document states what it wears itself (an omitted axis is not worn).', $elements['d-text']['born_wearing']);
    $this->assertSame('The skin dresses h1, h2, p: pick the tag, the skin sizes it.', $elements['d-text']['attributes']['tag']['dressed']);
    $this->assertSame('THE HANDLER\'S OWN WORDS, ASPECT RATIO INCLUDED.', $elements['d-image']['attributes']['generate']['guidance']);
    $this->assertSame(['1:1', '16:9'], $elements['d-image']['attributes']['aspect-ratio']['values']);
    $this->assertStringNotContainsString('16:9 for', json_encode($elements), 'The projector keeps no copy of the aspect-ratio heuristic.');
    $this->assertStringNotContainsString('JSON DOOR', json_encode($elements), 'The JSON door\'s grammar never reaches the page language.');
    $this->assertArrayNotHasKey('born_wearing', $elements['d-image']);

    $bare = new DialectCatalog(containerLayoutId: 'layout_onecol', axes: [], blockTypes: $block_types, conditions: []);
    $elements = $projector->project($schema, $bare)['elements'];
    $this->assertArrayNotHasKey('born_wearing', $elements['d-container']);
    $this->assertArrayNotHasKey('dressed', $elements['d-text']['attributes']['tag']);
    $this->assertSame('Chosen with generate (its guidance carries the choice).', $elements['d-image']['attributes']['aspect-ratio']['guidance']);
    // A schema carrying no materials says so; the axis note and the
    // class guidance point at the brief, where every variant is
    // described, never at a materials.variants the dialect door lacks.
    $projected = $projector->project($schema, $catalog);
    $this->assertStringContainsString('and no materials beyond the brief\'s.', $projected['_structure']);
    $this->assertArrayNotHasKey('materials', $projected);
    $this->assertStringContainsString('described under the element\'s heading on the design system brief in your instructions', $projected['elements']['d-text']['attributes_note']);
    $this->assertStringContainsString('described under the element\'s heading on the design system brief in your instructions', $projected['elements']['d-container']['attributes_note']);
    $this->assertStringNotContainsString('materials.variants', json_encode($projected));
  }

  /**
   * V0.5: spine instances reach the built catalog and the cached prompt.
   *
   * The dialect lane is the third consumer of the spine's `instances:`
   * declaration: daedalus_stylish's vocabulary subscriber contributes the
   * namespace with its declared tags to the generated catalog, and the
   * cached Page language section teaches the instance selectors from it.
   * Without a declaration both surfaces stay silent (grows-by-data).
   */
  public function testCatalogCarriesSpineInstances(): void {
    $this->enableModules(['daedalus_stylish_nested_test']);

    $builder = $this->container->get('daedalus_blueprint.dialect_catalog_builder');
    $this->assertSame([], $builder->build()->instances(), 'No declaration, no instance vocabulary.');
    // The text-tag vocabulary is the styling layer's base section, present
    // whether or not any namespace mints identity.
    $this->assertSame($this->container->get('daedalus_stylish.style_guide')->baseTags(), $builder->build()->textTags());
    $this->assertContains('a', $builder->build()->textTags());
    $prompt = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
    $this->assertStringNotContainsString('Rich text styles by element', $prompt);

    $this->container->get('state')->set('daedalus_stylish_nested_test.instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph', 'ul' => 'Bulleted list'],
        'property_groups' => ['typography' => []],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $builder->reset();

    $catalog = $builder->build();
    $this->assertSame(['.s-*'], array_keys($catalog->instances()));
    $this->assertSame(['p' => 'Paragraph', 'ul' => 'Bulleted list'], $catalog->instanceTags(), 'Identity is the instance lane\'s.');
    $this->assertNotContains('a', array_keys($catalog->instanceTags()));
    $this->assertContains('a', $catalog->textTags(), 'Styleability is base\'s: links never mint, yet the tier addresses them.');
    $this->assertSame('Text element', $catalog->instances()['.s-*']['label']);
    $this->assertArrayNotHasKey('property_groups', $catalog->instances()['.s-*'], 'The styling sections stay the guide\'s.');

    $prompt = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
    $this->assertStringContainsString('Rich text styles by element', $prompt);
    $this->assertStringContainsString('#id .s-x > *', $prompt);
    $this->assertStringContainsString('tags: ' . implode(', ', $catalog->textTags()) . ')', $prompt);
    $this->assertStringContainsString('KEEP the s-* classes', $prompt);
  }

  /**
   * A bundle without a layout keeps the JSON blueprint schema.
   */
  public function testNonLayoutBundleKeepsTheJsonSchema(): void {
    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('block_content', 'basic');
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $this->assertFalse($this->container->get('daedalus_blueprint.dialect_schema_projector')->applies($schema, $catalog));

    $output = $this->runTool('eb_read_schema', ['entity_type' => 'block_content', 'bundle' => 'basic']);
    $this->assertStringContainsString('field_heading', $output);
    $this->assertStringContainsString('Place all entity field values inside the "fields" object', $output);
    $this->assertStringNotContainsString('d-container:', $output);
    $this->assertStringNotContainsString('This bundle carries a page-builder layout', $output);
  }

}
