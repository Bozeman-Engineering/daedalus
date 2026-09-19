<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Eb_read_schema answers a page-builder bundle in the page language's view.
 *
 * The JSON schema (~10k tokens) landing right before the write outranks
 * the cached catalog's closed roster on recency with its `stylable`
 * SETS, and the writer pads the cards. On the dialect door the schema
 * must carry what the catalog does not (entity fields, what each element
 * is for, the materials, the stencils) and nothing that contradicts or
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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
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
      'layout_builder__layout',
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
    $this->assertArrayNotHasKey('daedalus_stylish_styles', $projected['fields']);
    $this->assertSame($schema['materials'], $projected['materials'], 'The schema\'s own materials key carries through unchanged.');
    $this->assertArrayNotHasKey('materials', $schema['fields']['daedalus_stylish_styles']['style_guide'], 'The materials are never the field\'s.');
    foreach (['stylable', 'conditions', 'current_values', 'anatomy'] as $taught) {
      $this->assertArrayNotHasKey($taught, $projected['style_guide']);
    }
    $this->assertArrayHasKey('guidance', $projected['style_guide']);

    $this->assertSame(['pb-container', 'pb-basic'], array_keys($projected['elements']));
    $basic = $projected['elements']['pb-basic'];
    $this->assertSame('Basic', $basic['label']);
    $this->assertSame(['field' => 'field_heading'], $basic['attributes']['heading']);
    $this->assertSame('field_link', $basic['attributes']['link']['field']);
    $this->assertStringContainsString('"<nolink>"', $basic['attributes']['link']['guidance'], 'Link attributes teach the authorable grammar.');
    $this->assertArrayNotHasKey('body', $basic);
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
      'layout_builder__layout' => [
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
    $this->assertStringContainsString('materials in full (tokens, swatches, variants, stencils, animations', $projector->project($schema, $bare)['_structure']);
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
    $this->assertStringContainsString('(' . implode(', ', $catalog->textTags()) . ')', $prompt);
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
