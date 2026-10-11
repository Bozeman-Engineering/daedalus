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
 * stylable sets, conditions, anatomy trees). A bundle without a layout
 * keeps the JSON schema: that door is still eb_create_entity/eb_edit's.
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
    // The materials roster queries published swatches.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
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
    $this->assertStringContainsString('pb-container:', $output);
    $this->assertStringContainsString('pb-basic:', $output);
    $this->assertStringContainsString('field: field_heading', $output);
    $this->assertStringContainsString('materials:', $output);
    // Grows-by-data: the fixture saves no variant, so no variants group
    // rides, and the guidance says an absent kind has none.
    $this->assertStringNotContainsString('variants:', $output);
    $this->assertStringContainsString('a kind absent here has no materials on this site', $output);

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

    $this->assertSame(['entity_type', 'bundle', 'label', '_structure', 'fields', 'elements', 'materials', 'style_guide'], array_keys($projected));
    $this->assertArrayNotHasKey('styles', $projected);
    $this->assertSame($schema['materials'], $projected['materials'], 'The schema\'s own materials key carries through unchanged.');
    $this->assertArrayNotHasKey('starts', $projected, 'No page blueprint fits this bundle, so no starts ride (grows-by-data).');
    $this->assertStringNotContainsString('"starts"', $projected['_structure']);
    $this->assertArrayNotHasKey('materials', $schema['styles']['style_guide'], 'The materials are never the field\'s.');
    foreach (['stylable', 'conditions', 'current_values', 'anatomy'] as $taught) {
      $this->assertArrayNotHasKey($taught, $projected['style_guide']);
    }
    $this->assertArrayHasKey('guidance', $projected['style_guide']);

    $this->assertSame(['pb-container', 'pb-slot', 'pb-linked', 'pb-basic'], array_keys($projected['elements']));
    $this->assertSame(['name'], array_keys($projected['elements']['pb-slot']['attributes']), 'The slot form takes the container\'s attributes with name as the slot id (no axes on this catalog).');
    $this->assertStringContainsString('fills a slot', $projected['elements']['pb-slot']['guidance']);
    $this->assertSame(['blueprint'], array_keys($projected['elements']['pb-linked']['attributes']), 'The linked placement takes the blueprint it shows (no axes on this catalog).');
    $this->assertStringContainsString('by reference', $projected['elements']['pb-linked']['guidance']);
    $basic = $projected['elements']['pb-basic'];
    $this->assertSame('Basic', $basic['label']);
    $this->assertSame(['field' => 'field_heading'], $basic['attributes']['heading']);
    $this->assertSame('field_link', $basic['attributes']['link']['field']);
    $this->assertStringContainsString('"<nolink>"', $basic['attributes']['link']['guidance'], 'Link attributes teach the authorable grammar.');
    $this->assertArrayNotHasKey('body', $basic);
  }

  /**
   * A page bundle's starts ride the projection; a page blueprint is nowhere else.
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
    // daedalus_stylish already teaches the swatch gesture; two providers
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
        id: 'band',
        label: 'Band',
        description: 'A block blueprint any host can hold.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'band'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        favorite: TRUE,
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
    $this->assertSame(['band'], array_keys($schema['materials']['blueprints']), 'The favorite block blueprint lists in full; the page blueprint is a start.');
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
      'style_guide',
    ];
    $this->assertSame($keys, array_keys($projected));
    $this->assertSame($schema['starts'], $projected['starts']);
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
   * The schema read lists the favorites, and one group in full on demand.
   *
   * By default materials.blueprints holds the site favorites in full and
   * materials.groups the vocabulary with counts (the reserved bucket for
   * the ones in no group included). With `group`, materials.blueprints
   * holds that group's offered block blueprints instead, narrowed AFTER
   * the cached schema is read: a default read right after answers the
   * favorites again, and the index rides every answer unchanged. The
   * reserved bucket lists the ones in no group; an unknown group answers
   * a corrective naming the ids; a bundle whose schema carries no
   * materials answers a corrective too.
   */
  public function testGroupNarrowsTheSchemaRead(): void {
    $this->enableModules(['daedalus_blueprint_materials_test']);
    TestMaterialsProvider::$gestureLines = [];
    TestGroupsContributor::$groups = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.'],
      'bands' => ['label' => 'Bands'],
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
      $record('wide_band', ['bands'], FALSE),
      $record('stray_card', [], FALSE),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();

    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test');
    $this->assertSame(['opener'], array_keys($schema['materials']['blueprints']), 'The default listing is the site favorites.');
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.', 'blueprints' => 1],
      'bands' => ['label' => 'Bands', 'blueprints' => 1],
      DialectCatalog::UNGROUPED => ['blueprints' => 1],
    ], $schema['materials']['groups']);

    $read = fn (?string $group = NULL): string => $this->runTool('eb_read_schema', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ] + ($group === NULL ? [] : ['group' => $group]));
    $default = $read();
    $this->assertStringContainsString("  blueprints:\n    opener:\n", $default);
    $this->assertStringNotContainsString('wide_band:', $default);
    $this->assertStringNotContainsString('stray_card:', $default);
    $this->assertStringContainsString("  groups:\n    heroes:\n", $default);
    $this->assertStringContainsString("    ungrouped:\n      blueprints: 1\n", $default);

    $bands = $read('bands');
    $this->assertStringContainsString("  blueprints:\n    wide_band:\n", $bands);
    $this->assertStringContainsString('<pb-container', $bands, 'The group prints in full.');
    $this->assertStringNotContainsString('opener:', $bands, 'The favorites are replaced by the group.');
    $this->assertStringNotContainsString('stray_card:', $bands);
    $this->assertStringContainsString("  groups:\n    heroes:\n", $bands, 'The index rides every answer.');

    $ungrouped = $read(DialectCatalog::UNGROUPED);
    $this->assertStringContainsString("  blueprints:\n    stray_card:\n", $ungrouped);
    $this->assertStringNotContainsString('opener:', $ungrouped);
    $this->assertStringNotContainsString('wide_band:', $ungrouped);

    $again = $read();
    $this->assertSame($default, $again, 'The narrowing never reaches the cached schema.');

    $unknown = $read('cards');
    $this->assertSame('Error: unknown group "cards". The groups are heroes, bands (materials.groups in the schema read); ungrouped lists the blueprints in no group. Pass one of them, or omit group for the site favorites.', $unknown);

    $none = $this->runTool('eb_read_schema', ['entity_type' => 'block_content', 'bundle' => 'basic', 'group' => 'bands']);
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
    $this->assertSame('A placement from the palette is born wearing width="site"; a document states what it wears itself (an omitted axis is not worn).', $elements['pb-container']['born_wearing']);
    $this->assertSame('A placement from the palette is born wearing button="primary"; a document states what it wears itself (an omitted axis is not worn).', $elements['pb-text']['born_wearing']);
    $this->assertSame('The skin dresses h1, h2, p: pick the tag, the skin sizes it.', $elements['pb-text']['attributes']['tag']['dressed']);
    $this->assertSame('THE HANDLER\'S OWN WORDS, ASPECT RATIO INCLUDED.', $elements['pb-image']['attributes']['generate']['guidance']);
    $this->assertSame(['1:1', '16:9'], $elements['pb-image']['attributes']['aspect-ratio']['values']);
    $this->assertStringNotContainsString('16:9 for', json_encode($elements), 'The projector keeps no copy of the aspect-ratio heuristic.');
    $this->assertStringNotContainsString('JSON DOOR', json_encode($elements), 'The JSON door\'s grammar never reaches the page language.');
    $this->assertArrayNotHasKey('born_wearing', $elements['pb-image']);

    $bare = new DialectCatalog(containerLayoutId: 'layout_onecol', axes: [], blockTypes: $block_types, conditions: []);
    $elements = $projector->project($schema, $bare)['elements'];
    $this->assertArrayNotHasKey('born_wearing', $elements['pb-container']);
    $this->assertArrayNotHasKey('dressed', $elements['pb-text']['attributes']['tag']);
    $this->assertSame('Chosen with generate (its guidance carries the choice).', $elements['pb-image']['attributes']['aspect-ratio']['guidance']);
    $this->assertStringContainsString('materials in full (tokens, swatches, variants, blueprints, animations', $projector->project($schema, $bare)['_structure']);
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
    $this->assertStringNotContainsString('pb-container:', $output);
    $this->assertStringNotContainsString('This bundle carries a page-builder layout', $output);
  }

}
