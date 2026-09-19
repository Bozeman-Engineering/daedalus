<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\daedalus_blueprint\Hook\MaterialsSchemaHooks;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The roster's schema fragment and where core lands it.
 *
 * One projection for both doors: the fragment groups the roster by kind
 * (grows-by-data: a kind the site lacks is absent), keys named values by the
 * custom property a style value references, serializes a variant's
 * page-language spelling and applicability, prints each stencil as a
 * page-language fragment with its owned looks beneath it, and the hook places
 * it as the schema's own `materials` key on every schema whose values can
 * reference materials (a layout, or a field carrying a style guide) with the
 * roster's cache tags on the alter context.
 */
#[CoversClass(MaterialsSchema::class)]
#[CoversClass(MaterialsSchemaHooks::class)]
#[Group('daedalus_blueprint')]
class MaterialsSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'daedalus_blueprint_materials_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
  }

  /**
   * The fragment: one group per kind, full text, spellings as written.
   */
  public function testFragment(): void {
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'stencils', 'animations'], array_keys($fragment));

    // Named values key by the custom property inside the var() spelling,
    // values as stored; a swatch carries the label a person asks for.
    $this->assertSame(['default' => '#1475ad', 'dark' => '#52b3eb'], $fragment['tokens']['color']['--color-accent']);
    $this->assertSame(['label' => 'Lime green', 'kind' => 'color', 'value' => '#4dff8c'], $fragment['swatches']['--swatch-lime_green']);

    // Variants: spelling as the page language writes it, axis only off
    // the style axis, applies for root-only, and nothing emitted empty.
    // A look a stencil owns is not here: it lists under its stencil.
    $this->assertSame([
      'label' => 'Full width',
      'spelling' => 'width="full"',
      'scope' => 'kind:container',
      'axis' => 'width',
      'applies' => MaterialsSchema::APPLIES_ROOT_ONLY,
      'description' => 'Let the container span the full page width.',
    ], $fragment['variants']['container_full']);
    $this->assertSame(['button', 'container_full', 'block_full', 'link_quiet'], array_keys($fragment['variants']));

    // Stencils: label, full description, root kind, the owned looks under
    // the stencil (label and spelling; no scope, no owner key); nothing
    // else (no fragment: the fixture record carries no tree; no placement
    // key: hero wears nothing root-only), and the guidance says nothing
    // about bands while the roster has none.
    $this->assertSame([
      'label' => 'Hero',
      'description' => 'Single-column hero banner with a heading.',
      'root' => 'section',
      'looks' => [
        'hero_glow' => ['label' => 'Hero glow', 'spelling' => 'class="hero_glow"'],
      ],
    ], $fragment['stencils']['hero']);
    $this->assertStringNotContainsString('"placement"', $fragment['guidance']);

    // Animations: label, the ident as animation-name writes it, the steps
    // as stored, description when carried.
    $this->assertSame([
      'label' => 'Fade in',
      'spelling' => 'animation-name: motion-fade_in',
      'steps' => ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']],
      'description' => 'Fade the element in from transparent.',
    ], $fragment['animations']['fade_in']);

    // The guidance teaches the page language's spellings, the stencil
    // vocabulary (the site has stencils), and never a JSON op.
    $guidance = $fragment['guidance'];
    $this->assertStringContainsString('a kind absent here has no materials on this site', $guidance);
    $this->assertStringContainsString('class="name"', $guidance);
    $this->assertStringContainsString('<pb-stencil name="id"/>', $guidance);
    $this->assertStringContainsString('"fragment"', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance);
    $this->assertStringNotContainsString('owned_by', $guidance);
    $this->assertStringContainsString('animation-name', $guidance);
    $this->assertStringNotContainsString('apply_variant', $guidance);
    $this->assertStringNotContainsString('instantiate_stencil', $guidance);
  }

  /**
   * A swatch's description rides its entry, whole; an undescribed one has none.
   *
   * The same omit-when-nothing-to-say rule the variants follow: the
   * fixture's lime green carries no description and no key, a described
   * swatch carries its whole text (the brief clips it to a sentence).
   */
  public function testSwatchDescription(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'ink',
        label: 'Ink',
        description: 'Reach for it on body copy over a light ground. Never on buttons.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-ink)'],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $swatches = $this->container->get('daedalus_blueprint.materials_schema')->fragment()['swatches'];
    $this->assertArrayNotHasKey('description', $swatches['--swatch-lime_green']);
    $this->assertSame([
      'label' => 'Ink',
      'kind' => 'color',
      'value' => '#1a1a2e',
      'description' => 'Reach for it on body copy over a light ground. Never on buttons.',
    ], $swatches['--swatch-ink']);
    // Membership is the brief's sentence now, not the fragment's.
    $this->assertStringNotContainsString('active design system\'s members', $this->container->get('daedalus_blueprint.materials_schema')->fragment()['guidance']);
  }

  /**
   * A band stencil prints its fragment and carries placement: top_level.
   *
   * The fragment is the record's tree through the dialect printer over
   * the site's catalog: no ids, the root's wears spelled as the page
   * language wears them (the fixture's width axis as width="full", the
   * owned look as a class), the child's sample content as its fields (an
   * undeclared block type prints in the generic form here: the kernel
   * has no block_content). The band is derived at the roster (the root
   * wears container_full, the fixture's root-only variant), never
   * authored: the schema is a thin projection of MaterialsRoster::
   * isBand(), and the placement sentence rides only while the roster has
   * a band (grows-by-data).
   */
  public function testBandStencilPrintsAndPlaces(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'wide_band'],
        applies: [MaterialRecord::APPLIES_WEARS => ['hero_glow', 'container_full']],
        rootKind: MaterialRecord::ROOT_SECTION,
        tree: [[
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'styles' => [
            '' => [
              'variants' => [['variant' => 'hero_glow'], ['variant' => 'container_full']],
              'default' => ['padding-top' => '2rem'],
            ],
          ],
          'children' => [
            [
              'kind' => 'block',
              'block_type' => 'text',
              'block_fields' => ['field_text_tag' => 'h2', 'field_text_text' => 'Wide & welcome'],
            ],
          ],
        ],
        ],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $expected = <<<HTML
<pb-container width="full" class="hero_glow" style="padding-top: 2rem">
  <pb-block type="text">
    <pb-field name="field_text_tag">h2</pb-field>
    <pb-field name="field_text_text">Wide & welcome</pb-field>
  </pb-block>
</pb-container>

HTML;
    $this->assertSame([
      'label' => 'Wide band',
      'description' => 'A full-width band.',
      'root' => 'section',
      'fragment' => $expected,
      'placement' => MaterialsSchema::PLACEMENT_TOP_LEVEL,
    ], $fragment['stencils']['wide_band']);
    $this->assertArrayNotHasKey('placement', $fragment['stencils']['hero']);
    $this->assertArrayNotHasKey('fragment', $fragment['stencils']['hero']);
    $this->assertStringContainsString('A stencil with "placement": "top_level" is a page band', $fragment['guidance']);
  }

  /**
   * An unprintable tree says why, and the schema still answers.
   *
   * A multi-region layout has no dialect print (region nodes), so the
   * entry carries the printer's reason under "unprintable" and the
   * placement spelling instead of a fragment; the rest of the schema is
   * untouched. The one recovery a whole-site read takes for one
   * definition: the stencil stays placeable by name.
   */
  public function testUnprintableStencilSaysWhy(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'two_up',
        label: 'Two up',
        description: 'Two columns.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'two_up'],
        rootKind: MaterialRecord::ROOT_SECTION,
        tree: [[
          'kind' => 'container',
          'layout_id' => 'layout_twocol_section',
          'children' => [
            ['kind' => 'region', 'region' => 'first', 'children' => [['kind' => 'block', 'block_type' => 'text']]],
          ],
        ],
        ],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $entry = $fragment['stencils']['two_up'];
    $this->assertSame(['label', 'description', 'root', 'unprintable'], array_keys($entry));
    $this->assertStringStartsWith('The page language cannot print this stencil (multi-region layouts have no dialect print', $entry['unprintable']);
    $this->assertStringContainsString('<pb-stencil name="two_up"/>', $entry['unprintable']);
    $this->assertArrayHasKey('hero', $fragment['stencils']);
  }

  /**
   * A material opted out of the AI has no entry; what wears it still prints.
   *
   * The fragment iterates the roster's offered view: an unoffered swatch,
   * variant, owned look, stencil or animation is absent (entry and
   * description), and the vocabulary of a kind left empty by the opt-out
   * goes with it. Legality is untouched and the stencil prints are
   * legality: a band stencil whose root wears the unoffered root-only
   * variant still carries placement top_level (the verdict resolves
   * against the full roster) and its fragment still spells the axis the
   * definition wears, since the print says what the stencil IS.
   */
  public function testOptedOutMaterialsLeaveTheFragment(): void {
    $reference = [];
    foreach (TestMaterialsProvider::referenceRecords() as $record) {
      $reference[$record->kind->value . ':' . $record->id] = $record;
    }
    $opt_out = static fn (MaterialRecord $record): MaterialRecord => new MaterialRecord(
      kind: $record->kind,
      id: $record->id,
      label: $record->label,
      description: $record->description,
      value: $record->value,
      group: $record->group,
      spellings: $record->spellings,
      scope: $record->scope,
      applies: $record->applies,
      ownedBy: $record->ownedBy,
      weight: $record->weight,
      rootKind: $record->rootKind,
      origin: $record->origin,
      offered: FALSE,
    );
    $reference['variant:container_full'] = $opt_out($reference['variant:container_full']);
    $reference['variant:hero_glow'] = $opt_out($reference['variant:hero_glow']);
    $reference['animation:fade_in'] = $opt_out($reference['animation:fade_in']);
    TestMaterialsProvider::$records = [
      ...array_values($reference),
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'ink',
        label: 'Ink',
        description: 'Reach for it on body copy.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-ink)'],
        offered: FALSE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'secret',
        label: 'Secret',
        description: 'For the UI only.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'secret'],
        rootKind: MaterialRecord::ROOT_BLOCK,
        offered: FALSE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'wide_band'],
        applies: [MaterialRecord::APPLIES_WEARS => ['container_full']],
        rootKind: MaterialRecord::ROOT_SECTION,
        tree: [[
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'styles' => ['' => ['variants' => [['variant' => 'container_full']]]],
          'children' => [],
        ],
        ],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();

    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'stencils'], array_keys($fragment), 'The animation kind emptied by the opt-out is absent with its vocabulary.');
    $this->assertStringNotContainsStringIgnoringCase('animation', $fragment['guidance']);
    $this->assertSame(['--swatch-lime_green'], array_keys($fragment['swatches']));
    $this->assertSame(['button', 'block_full', 'link_quiet'], array_keys($fragment['variants']));
    $this->assertSame(['hero', 'wide_band'], array_keys($fragment['stencils']));
    $this->assertArrayNotHasKey('looks', $fragment['stencils']['hero'], 'An unoffered owned look lists nowhere.');
    $this->assertStringNotContainsString('Reach for it on body copy', json_encode($fragment));
    $this->assertStringNotContainsString('For the UI only', json_encode($fragment));

    // Legality and the print are the full roster's.
    $this->assertSame(MaterialsSchema::PLACEMENT_TOP_LEVEL, $fragment['stencils']['wide_band']['placement']);
    $this->assertStringContainsString('A stencil with "placement": "top_level" is a page band', $fragment['guidance']);
    $this->assertSame("<pb-container width=\"full\">\n</pb-container>\n", $fragment['stencils']['wide_band']['fragment']);
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertTrue($roster->has(MaterialKind::Variant, 'container_full'));
    $this->assertTrue($roster->has(MaterialKind::Stencil, 'secret'));
  }

  /**
   * Grows-by-data: an empty kind is absent, and so is its vocabulary.
   */
  public function testEmptyKindsAreAbsent(): void {
    TestMaterialsProvider::$records = array_values(array_filter(
      TestMaterialsProvider::referenceRecords(),
      static fn ($record) => $record->kind->value === 'token',
    ));
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['guidance', 'tokens'], array_keys($fragment));
    $this->assertStringNotContainsStringIgnoringCase('stencil', $fragment['guidance']);
    $this->assertStringNotContainsStringIgnoringCase('animation', $fragment['guidance']);
    $this->assertStringNotContainsString(MaterialsSchema::LOOKS_BY_POSITION, $fragment['guidance']);
  }

  /**
   * The hook lands the fragment only where values can reference materials.
   */
  public function testPlacement(): void {
    $alter = function (array $schema): array {
      $context = ['entity_type' => 'x', 'bundle' => 'x', 'view_mode' => 'full', 'cache_tags' => []];
      $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
      return [$schema, $context];
    };

    // A layout references materials (variants worn, stencils placed).
    [$schema, $context] = $alter(['fields' => [], 'layout_builder__layout' => ['guidance' => 'x']]);
    $this->assertArrayHasKey('stencils', $schema['materials']);
    $this->assertContains('materials_test_list', $context['cache_tags'], 'The roster\'s invalidation domain rides the alter context.');

    // A field carrying a style guide references materials.
    [$schema] = $alter(['fields' => ['styles' => ['type' => 'x', 'style_guide' => ['guidance' => 'x']]]]);
    $this->assertArrayHasKey('variants', $schema['materials']);
    $this->assertSame(['guidance'], array_keys($schema['fields']['styles']['style_guide']), 'The field\'s own fragment is untouched.');

    // Anything else says nothing about materials and carries none.
    [$schema, $context] = $alter(['fields' => ['name' => ['type' => 'string']]]);
    $this->assertArrayNotHasKey('materials', $schema);
    $this->assertSame([], $context['cache_tags']);
  }

}
