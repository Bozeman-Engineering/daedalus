<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Hook\MaterialsSchemaHooks;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint_materials_test\TestGroupsContributor;
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
 * page-language spelling and applicability, prints each blueprint as a
 * page-language fragment with its owned looks beneath it (the site favorites
 * by default, one group in full when a read narrows to it after the cache,
 * the group index with counts beside them), and the hook places it as the
 * schema's own `materials` key on every schema whose values can reference
 * materials (a layout, or a field carrying a style guide) with the roster's
 * cache tags on the alter context.
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
    TestGroupsContributor::$groups = [];
  }

  /**
   * Rebuilds the roster and the catalog after the fixtures changed.
   */
  protected function reset(): void {
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();
  }

  /**
   * The fragment: one group per kind, full text, spellings as written.
   */
  public function testFragment(): void {
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'groups', 'blueprints', 'animations'], array_keys($fragment));

    // Named values key by the custom property inside the var() spelling,
    // values as stored; a token's description rides only when written
    // (the fixture's carries none); a swatch carries the label a person
    // asks for.
    $this->assertSame(['value' => ['default' => '#1475ad', 'dark' => '#52b3eb']], $fragment['tokens']['color']['--color-accent']);
    $this->assertSame(['label' => 'Lime green', 'kind' => 'color', 'value' => '#4dff8c'], $fragment['swatches']['--swatch-lime_green']);

    // Variants: spelling as the page language writes it, axis only off
    // the style axis, applies for root-only, and nothing emitted empty.
    // A look a blueprint owns is not here: it lists under its blueprint.
    $this->assertSame([
      'label' => 'Full width',
      'spelling' => 'width="full"',
      'scope' => 'kind:container',
      'axis' => 'width',
      'applies' => MaterialsSchema::APPLIES_ROOT_ONLY,
      'description' => 'Let the container span the full page width.',
    ], $fragment['variants']['container_full']);
    $this->assertSame(['button', 'container_full', 'block_full', 'link_quiet'], array_keys($fragment['variants']));

    // Blueprints: the site favorites (the fixture's hero is one), each
    // with label, full description, scope, the owned looks under the
    // blueprint (label and spelling; no scope, no owner key); nothing
    // else (no fragment: the fixture record carries no tree; no placement
    // key: hero wears nothing root-only), and the guidance says nothing
    // about bands while the roster has none. The index beside it: no
    // vocabulary is declared, so the one offered blueprint files under
    // the reserved bucket.
    $this->assertSame([
      'label' => 'Hero',
      'description' => 'Single-column hero banner with a heading.',
      'scope' => 'block',
      'looks' => [
        'hero_glow' => ['label' => 'Hero glow', 'spelling' => 'class="hero_glow"'],
      ],
    ], $fragment['blueprints']['hero']);
    $this->assertSame([DialectCatalog::UNGROUPED => [MaterialsSchema::GROUP_COUNT => 1]], $fragment['groups']);
    $this->assertStringNotContainsString('"placement"', $fragment['guidance']);

    // Animations: label, the ident as animation-name writes it, the steps
    // as stored, description when carried.
    $this->assertSame([
      'label' => 'Fade in',
      'spelling' => 'animation-name: motion-fade_in',
      'steps' => ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']],
      'description' => 'Fade the element in from transparent.',
    ], $fragment['animations']['fade_in']);

    // The guidance teaches the page language's spellings, the blueprint
    // vocabulary (the site has blueprints), and never a JSON op.
    $guidance = $fragment['guidance'];
    $this->assertStringContainsString('a kind absent here has no materials on this site', $guidance);
    $this->assertStringContainsString('class="name"', $guidance);
    $this->assertStringContainsString('<pb-blueprint name="id"/>', $guidance);
    $this->assertStringContainsString('"fragment"', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance);
    $this->assertStringContainsString('"blueprints" holds the site favorites in full', $guidance);
    $this->assertStringContainsString('eb_read_schema with group (an id, or ungrouped)', $guidance);
    $this->assertStringNotContainsString('owned_by', $guidance);
    $this->assertStringContainsString('animation-name', $guidance);
    $this->assertStringNotContainsString('apply_variant', $guidance);
    $this->assertStringNotContainsString('place_blueprint', $guidance);
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
   * A band blueprint prints its fragment and carries placement: top_level.
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
  public function testBandBlueprintPrintsAndPlaces(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_band'],
        applies: [MaterialRecord::APPLIES_WEARS => ['hero_glow', 'container_full']],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        favorite: TRUE,
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
      'scope' => 'block',
      'fragment' => $expected,
      'placement' => MaterialsSchema::PLACEMENT_TOP_LEVEL,
    ], $fragment['blueprints']['wide_band']);
    $this->assertArrayNotHasKey('placement', $fragment['blueprints']['hero']);
    $this->assertArrayNotHasKey('fragment', $fragment['blueprints']['hero']);
    $this->assertStringContainsString('A blueprint with "placement": "top_level" is a page band', $fragment['guidance']);
  }

  /**
   * A tree the page language cannot spell whole says why; the schema answers.
   *
   * The fragment is a write template, so a print with a note (here a
   * multi-region layout: the print carries region facts the model cannot
   * write back) is not offered; the entry carries the print's first note
   * under "unprintable" and the placement spelling instead of a
   * fragment; the rest of the schema is untouched. The one recovery a
   * whole-site read takes for one definition: the blueprint stays
   * placeable by name.
   */
  public function testUnprintableBlueprintSaysWhy(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'two_up',
        label: 'Two up',
        description: 'Two columns.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'two_up'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        favorite: TRUE,
        tree: [[
          'kind' => 'container',
          'layout_id' => 'layout_twocol_section',
          'children' => [
            ['kind' => 'region', 'region' => 'first', 'children' => [['kind' => 'block', 'block_type' => 'text']]],
            ['kind' => 'region', 'region' => 'second', 'children' => []],
          ],
        ],
        ],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $entry = $fragment['blueprints']['two_up'];
    $this->assertSame(['label', 'description', 'scope', 'unprintable'], array_keys($entry));
    $this->assertStringStartsWith('The page language cannot print this blueprint (<pb-container> is a section on the "layout_twocol_section" layout (regions first, second), which the page language does not spell)', $entry['unprintable']);
    $this->assertStringContainsString('<pb-blueprint name="two_up"/>', $entry['unprintable']);
    $this->assertArrayHasKey('hero', $fragment['blueprints']);
  }

  /**
   * A material opted out of the AI has no entry; what wears it still prints.
   *
   * The fragment iterates the roster's offered view: an unoffered swatch,
   * variant, owned look, blueprint or animation is absent (entry and
   * description), and the vocabulary of a kind left empty by the opt-out
   * goes with it. Legality is untouched and the blueprint prints are
   * legality: a band blueprint whose root wears the unoffered root-only
   * variant still carries placement top_level (the verdict resolves
   * against the full roster) and its fragment still spells the axis the
   * definition wears, since the print says what the blueprint IS.
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
      blueprintScope: $record->blueprintScope,
      origin: $record->origin,
      offered: FALSE,
      favorite: $record->favorite,
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
        kind: MaterialKind::Blueprint,
        id: 'secret',
        label: 'Secret',
        description: 'For the UI only.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'secret'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        offered: FALSE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_band'],
        applies: [MaterialRecord::APPLIES_WEARS => ['container_full']],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        favorite: TRUE,
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

    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'groups', 'blueprints'], array_keys($fragment), 'The animation kind emptied by the opt-out is absent with its vocabulary.');
    $this->assertSame([DialectCatalog::UNGROUPED => [MaterialsSchema::GROUP_COUNT => 2]], $fragment['groups'], 'The unoffered blueprint is not counted.');
    $this->assertStringNotContainsStringIgnoringCase('animation', $fragment['guidance']);
    $this->assertSame(['--swatch-lime_green'], array_keys($fragment['swatches']));
    $this->assertSame(['button', 'block_full', 'link_quiet'], array_keys($fragment['variants']));
    $this->assertSame(['hero', 'wide_band'], array_keys($fragment['blueprints']));
    $this->assertArrayNotHasKey('looks', $fragment['blueprints']['hero'], 'An unoffered owned look lists nowhere.');
    $this->assertStringNotContainsString('Reach for it on body copy', json_encode($fragment));
    $this->assertStringNotContainsString('For the UI only', json_encode($fragment));

    // Legality and the print are the full roster's.
    $this->assertSame(MaterialsSchema::PLACEMENT_TOP_LEVEL, $fragment['blueprints']['wide_band']['placement']);
    $this->assertStringContainsString('A blueprint with "placement": "top_level" is a page band', $fragment['guidance']);
    $this->assertSame("<pb-container width=\"full\">\n</pb-container>\n", $fragment['blueprints']['wide_band']['fragment']);
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertTrue($roster->has(MaterialKind::Variant, 'container_full'));
    $this->assertTrue($roster->has(MaterialKind::Blueprint, 'secret'));
  }

  /**
   * A token's whole description rides its schema entry, when written.
   */
  public function testTokenDescriptionRides(): void {
    TestMaterialsProvider::$records = [
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'color/ink',
        label: 'ink',
        description: 'The reading color. Reach for it on body text and headings, never on a ground.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--color-ink)'],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    // The whole description, not the first sentence: the schema is the
    // surface that carries every material in full.
    $this->assertSame(
      [
        'value' => '#1a1a2e',
        'description' => 'The reading color. Reach for it on body text and headings, never on a ground.',
      ],
      $fragment['tokens']['color']['--color-ink'],
    );
    $this->assertStringContainsString('each entry carrying its "value" and, when its author wrote one, a "description"', $fragment['guidance']);
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
    $this->assertStringNotContainsStringIgnoringCase('blueprint', $fragment['guidance']);
    $this->assertStringNotContainsStringIgnoringCase('animation', $fragment['guidance']);
    $this->assertStringNotContainsString(MaterialsSchema::LOOKS_BY_POSITION, $fragment['guidance']);
  }

  /**
   * A page blueprint is a start, never a placement: it leaves the fragment.
   *
   * The blueprints group is block scope (what places into a page); a
   * page-scope record is in no entry and no description of it rides, and
   * a roster whose blueprints are all page scope carries no blueprints
   * group and none of the blueprint vocabulary in the guidance, exactly
   * as a site with no blueprints. Where a page blueprint IS shown (a page
   * bundle's `starts`) is the hook's, with a bundle host in hand.
   */
  public function testPageBlueprintsLeaveTheFragment(): void {
    $landing = new MaterialRecord(
      kind: MaterialKind::Blueprint,
      id: 'landing',
      label: 'Landing',
      description: 'A whole landing page.',
      spellings: [MaterialRecord::SLOT_BLUEPRINT => 'landing'],
      blueprintScope: MaterialRecord::SCOPE_PAGE,
      entityId: '9',
      tree: [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]],
    );
    TestMaterialsProvider::$records = [...TestMaterialsProvider::referenceRecords(), $landing];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['hero'], array_keys($fragment['blueprints']));
    $this->assertStringNotContainsString('landing', json_encode($fragment));
    $this->assertStringContainsString('<pb-blueprint name="id"/>', $fragment['guidance']);

    TestMaterialsProvider::$records = [$landing];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['guidance'], array_keys($fragment));
    $this->assertStringNotContainsStringIgnoringCase('blueprint', $fragment['guidance']);
    $this->assertTrue($this->container->get('daedalus_blueprint.materials_roster_builder')->build()->has(MaterialKind::Blueprint, 'landing'), 'The full roster keeps the page blueprint: it is a start.');
  }

  /**
   * A roster holding favorites, grouped, stray and page blueprints over a vocabulary.
   *
   * The reference hero (a favorite, no group), a favorite opener in
   * heroes, a wide band in bands and heroes, a stray filed under a group
   * this site does not declare, and a page blueprint in heroes that is a
   * start and never in the index.
   */
  protected function groupedRoster(): void {
    TestGroupsContributor::$groups = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers. The top of a page.'],
      'bands' => ['label' => 'Bands'],
      'cards' => ['label' => 'Cards'],
    ];
    $tree = [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]];
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'opener',
        label: 'Opener',
        description: 'An opening band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'opener'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['heroes'],
        favorite: TRUE,
        tree: $tree,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_band'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['bands', 'heroes'],
        tree: $tree,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'stray',
        label: 'Stray',
        description: 'Filed under a group this site lacks.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'stray'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['foreign'],
        tree: $tree,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'landing',
        label: 'Landing',
        description: 'A whole landing page.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'landing'],
        blueprintScope: MaterialRecord::SCOPE_PAGE,
        groups: ['heroes'],
        favorite: TRUE,
        tree: $tree,
      ),
    ];
    $this->reset();
  }

  /**
   * The default listing is the favorites; the index is the vocabulary with counts.
   *
   * The blueprints group prints the site favorites alone (the model's
   * cached text and the Add panel's default list agree); the groups group
   * carries every declared group in declaration order with its label,
   * its description when written and how many offered block blueprints
   * it holds (a group holding none included: the vocabulary is also the
   * ids a blueprint's groups take), then the reserved bucket counting the
   * ones in no declared group (an undeclared id counts nowhere). A page
   * blueprint is a start: it is neither listed nor counted.
   */
  public function testDefaultListingAndGroupIndex(): void {
    $this->groupedRoster();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();

    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'groups', 'blueprints', 'animations'], array_keys($fragment));
    $this->assertSame(['hero', 'opener'], array_keys($fragment['blueprints']), 'The favorites, in roster order.');
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers. The top of a page.', 'blueprints' => 2],
      'bands' => ['label' => 'Bands', 'blueprints' => 1],
      'cards' => ['label' => 'Cards', 'blueprints' => 0],
      DialectCatalog::UNGROUPED => ['blueprints' => 2],
    ], $fragment['groups']);
    $this->assertStringNotContainsString('landing', json_encode($fragment));
    $this->assertSame(['heroes', 'bands', 'cards', DialectCatalog::UNGROUPED], $schema->groupIds());
    $this->assertStringContainsString('"groups" is the site\'s group vocabulary (the ids a blueprint\'s groups take) with how many offered blueprints each holds', $fragment['guidance']);
  }

  /**
   * One group in full, after the cache: the listing replaces the favorites in place.
   *
   * Narrowing takes the fragment as built (what the schema cache holds)
   * and swaps its blueprints group for the group's offered block
   * blueprints printed in full, every other group carried through in its
   * place, so the cache key never carries the group. A declared group
   * holding nothing leaves the blueprints group absent, as an empty kind
   * is; the reserved bucket lists the ones in no declared group; an
   * unknown group is refused loudly (the tool answers the corrective
   * from groupIds() before asking).
   */
  public function testNarrowToOneGroup(): void {
    $this->groupedRoster();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();

    $heroes = $schema->narrow($fragment, NULL, 'heroes');
    $this->assertSame(array_keys($fragment), array_keys($heroes), 'The blueprints group keeps its place.');
    $this->assertSame(['opener', 'wide_band'], array_keys($heroes['blueprints']));
    $this->assertStringStartsWith('<pb-container', $heroes['blueprints']['wide_band']['fragment'], 'A group lists its blueprints in full.');
    $this->assertSame($fragment['groups'], $heroes['groups'], 'The index is carried through as cached.');
    $this->assertSame($fragment['guidance'], $heroes['guidance']);

    $this->assertSame(['hero', 'stray'], array_keys($schema->narrow($fragment, NULL, DialectCatalog::UNGROUPED)['blueprints']), 'The reserved bucket: no group, or only groups this site lacks; roster order.');
    $cards = $schema->narrow($fragment, NULL, 'cards');
    $this->assertArrayNotHasKey('blueprints', $cards, 'A declared group holding nothing lists nothing.');
    $this->assertSame(['guidance', 'tokens', 'swatches', 'variants', 'groups', 'animations'], array_keys($cards));

    try {
      $schema->narrow($fragment, NULL, 'foreign');
      $this->fail('An undeclared group id was narrowed to.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('heroes, bands, cards, ungrouped', $e->getMessage());
    }
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

    // A layout references materials (variants worn, blueprints placed).
    // The context names a type nothing hosts, so the blueprints are block
    // scope unfiltered by fit and no starts ride (a start needs a page
    // bundle's host).
    [$schema, $context] = $alter(['fields' => [], 'layout' => ['guidance' => 'x']]);
    $this->assertArrayHasKey('blueprints', $schema['materials']);
    $this->assertArrayNotHasKey('starts', $schema);
    $this->assertContains('materials_test_list', $context['cache_tags'], 'The roster\'s invalidation domain rides the alter context.');
    $this->assertContains('config:field_config_list', $context['cache_tags'], 'The offer\'s (the site lists a fit reads) rides beside it.');

    // A document's style store (the top-level `styles` entry, carrying the
    // style guide) references materials.
    [$schema] = $alter(['fields' => [], 'styles' => ['type' => 'x', 'style_guide' => ['guidance' => 'x']]]);
    $this->assertArrayHasKey('variants', $schema['materials']);
    $this->assertSame(['guidance'], array_keys($schema['styles']['style_guide']), 'The store\'s own fragment is untouched.');

    // Anything else says nothing about materials and carries none.
    [$schema, $context] = $alter(['fields' => ['name' => ['type' => 'string']]]);
    $this->assertArrayNotHasKey('materials', $schema);
    $this->assertSame([], $context['cache_tags']);
  }

}
