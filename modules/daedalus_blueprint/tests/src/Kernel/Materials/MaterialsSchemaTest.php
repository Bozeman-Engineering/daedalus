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
 * page-language fragment with its owned looks beneath it (every shared one
 * by default, one group alone when a read narrows to it after the cache,
 * the group index with counts beside them), and the hook places it as the
 * schema's own `materials` key on every schema whose values can reference
 * materials (a layout, or a field carrying a style guide) with the roster's
 * cache tags on the alter context. The dialect door narrows it after the
 * cache (dialect()) to what the cached brief does not carry, with its own
 * guidance paragraph.
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
    'filter',
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
    $this->assertSame(['guidance', 'tokens', 'variants', 'groups', 'blueprints', 'animations'], array_keys($fragment));

    // Named values key by the custom property inside the var() spelling,
    // values as stored, grouped by scale; a token's description rides
    // only when written (the fixture's carry none).
    $this->assertSame(['value' => ['default' => '#1475ad', 'dark' => '#52b3eb']], $fragment['tokens']['color']['--color-accent']);
    $this->assertSame(['value' => '#4dff8c'], $fragment['tokens']['color']['--color-lime-green']);

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

    // Blueprints: every shared one (the fixture's hero), each
    // with label, full description, scope, the owned looks under the
    // blueprint (label and spelling; no scope, no owner key); nothing
    // else (no fragment: the fixture record carries no tree; no placement
    // key: hero wears nothing root-only), and the guidance says nothing
    // about sections while the roster has none. The index beside it: no
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
    $this->assertStringContainsString('<d-blueprint name="id"/>', $guidance);
    $this->assertStringContainsString('"fragment"', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance);
    $this->assertStringContainsString('"blueprints" holds every blueprint shared with you in full', $guidance);
    $this->assertStringContainsString('eb_read_schema with group (an id, or ungrouped)', $guidance);
    $this->assertStringNotContainsString('owned_by', $guidance);
    $this->assertStringContainsString('animation-name', $guidance);
    $this->assertStringNotContainsString('apply_variant', $guidance);
    $this->assertStringNotContainsString('place_blueprint', $guidance);
  }

  /**
   * A token's description rides its entry, whole; an undescribed one has none.
   *
   * The same omit-when-nothing-to-say rule the variants follow: the
   * fixture's lime green carries no description and no key, a described
   * token carries its whole text (whole on the brief too, which is the
   * token's one home on the dialect door).
   */
  public function testTokenDescription(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'color/ink',
        label: 'ink',
        description: 'Reach for it on body copy over a light ground. Never on buttons.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--color-ink)'],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $tokens = $this->container->get('daedalus_blueprint.materials_schema')->fragment()['tokens']['color'];
    $this->assertArrayNotHasKey('description', $tokens['--color-lime-green']);
    $this->assertSame([
      'value' => '#1a1a2e',
      'description' => 'Reach for it on body copy over a light ground. Never on buttons.',
    ], $tokens['--color-ink']);
    // Membership is the brief's sentence now, not the fragment's.
    $this->assertStringNotContainsString('active design system\'s members', $this->container->get('daedalus_blueprint.materials_schema')->fragment()['guidance']);
  }

  /**
   * A section blueprint prints its fragment and carries placement: top_level.
   *
   * The fragment is the record's tree through the dialect printer over
   * the site's catalog: no ids, the root's wears spelled as the page
   * language wears them (the fixture's width axis as width="full", the
   * owned look as a class), the child's sample content as its fields (an
   * undeclared block type prints in the generic form here: the kernel
   * has no block_content). The section is derived at the roster (the root
   * wears container_full, the fixture's root-only variant), never
   * authored: the schema is a thin projection of MaterialsRoster::
   * isSection(), and the placement sentence rides only while the roster has
   * a section (grows-by-data).
   */
  public function testSectionBlueprintPrintsAndPlaces(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
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
<d-container width="full" class="hero_glow" style="padding-top: 2rem">
  <d-block type="text">
    <d-field name="field_text_tag">h2</d-field>
    <d-field name="field_text_text">Wide & welcome</d-field>
  </d-block>
</d-container>

HTML;
    $this->assertSame([
      'label' => 'Wide section',
      'description' => 'A full-width section.',
      'scope' => 'block',
      'fragment' => $expected,
      'placement' => MaterialsSchema::PLACEMENT_TOP_LEVEL,
    ], $fragment['blueprints']['wide_section']);
    $this->assertArrayNotHasKey('placement', $fragment['blueprints']['hero']);
    $this->assertArrayNotHasKey('fragment', $fragment['blueprints']['hero']);
    $this->assertStringContainsString('A blueprint with "placement": "top_level" is a section', $fragment['guidance']);
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
    $this->assertStringStartsWith('The page language cannot print this blueprint (<d-container> is a section on the "layout_twocol_section" layout (regions first, second), which the page language does not spell)', $entry['unprintable']);
    $this->assertStringContainsString('<d-blueprint name="two_up"/>', $entry['unprintable']);
    $this->assertArrayHasKey('hero', $fragment['blueprints']);
  }

  /**
   * A material opted out of the AI has no entry; what wears it still prints.
   *
   * The fragment iterates the roster's offered view: an unoffered token,
   * variant, owned look, blueprint or animation is absent (entry and
   * description), and the vocabulary of a kind left empty by the opt-out
   * goes with it. Legality is untouched and the blueprint prints are
   * legality: a section blueprint whose root wears the unoffered root-only
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
        kind: MaterialKind::Token,
        id: 'color/ink',
        label: 'ink',
        description: 'Reach for it on body copy.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--color-ink)'],
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
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
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

    $this->assertSame(['guidance', 'tokens', 'variants', 'groups', 'blueprints'], array_keys($fragment), 'The animation kind emptied by the opt-out is absent with its vocabulary.');
    $this->assertSame([DialectCatalog::UNGROUPED => [MaterialsSchema::GROUP_COUNT => 2]], $fragment['groups'], 'The unoffered blueprint is not counted.');
    $this->assertStringNotContainsStringIgnoringCase('animation', $fragment['guidance']);
    $this->assertSame(['--color-accent', '--color-lime-green'], array_keys($fragment['tokens']['color']));
    $this->assertSame(['button', 'block_full', 'link_quiet'], array_keys($fragment['variants']));
    $this->assertSame(['hero', 'wide_section'], array_keys($fragment['blueprints']));
    $this->assertArrayNotHasKey('looks', $fragment['blueprints']['hero'], 'An unoffered owned look lists nowhere.');
    $this->assertStringNotContainsString('Reach for it on body copy', json_encode($fragment));
    $this->assertStringNotContainsString('For the UI only', json_encode($fragment));

    // Legality and the print are the full roster's.
    $this->assertSame(MaterialsSchema::PLACEMENT_TOP_LEVEL, $fragment['blueprints']['wide_section']['placement']);
    $this->assertStringContainsString('A blueprint with "placement": "top_level" is a section', $fragment['guidance']);
    $this->assertSame("<d-container width=\"full\">\n</d-container>\n", $fragment['blueprints']['wide_section']['fragment']);
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
    // The whole description: the JSON door's fragment carries every
    // material in full (the dialect door's narrowing drops the tokens,
    // since the brief states them whole).
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
    $this->assertStringContainsString('<d-blueprint name="id"/>', $fragment['guidance']);

    TestMaterialsProvider::$records = [$landing];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame(['guidance'], array_keys($fragment));
    $this->assertStringNotContainsStringIgnoringCase('blueprint', $fragment['guidance']);
    $this->assertTrue($this->container->get('daedalus_blueprint.materials_roster_builder')->build()->has(MaterialKind::Blueprint, 'landing'), 'The full roster keeps the page blueprint: it is a start.');
  }

  /**
   * A roster holding grouped, stray and page blueprints over a vocabulary.
   *
   * The reference hero (a site favorite, no group), an opener in heroes
   * (a favorite too: the flag is the Add panel's listing and changes
   * nothing here), a wide section in sections and heroes, a stray filed
   * under a group this site does not declare, and a page blueprint in
   * heroes that is a start and never in the index.
   */
  protected function groupedRoster(): void {
    TestGroupsContributor::$groups = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers. The top of a page.'],
      'sections' => ['label' => 'Sections'],
      'cards' => ['label' => 'Cards'],
    ];
    $tree = [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]];
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'opener',
        label: 'Opener',
        description: 'An opening section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'opener'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['heroes'],
        favorite: TRUE,
        tree: $tree,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['sections', 'heroes'],
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
   * The default listing is every shared block blueprint.
   *
   * The index is the vocabulary with counts.
   *
   * The blueprints group prints every offered block blueprint, favorite
   * or not (sharing is the one lever; the favorite flag is the Add
   * panel's listing); the groups group carries every declared group in
   * declaration order with its label, its description when written and
   * how many shared block blueprints it holds (a group holding none
   * included: the vocabulary is also the ids a blueprint's groups take),
   * then the reserved bucket counting the ones in no declared group (an
   * undeclared id counts nowhere). A page blueprint is a start: it is
   * neither listed nor counted.
   */
  public function testDefaultListingAndGroupIndex(): void {
    $this->groupedRoster();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();

    $this->assertSame(['guidance', 'tokens', 'variants', 'groups', 'blueprints', 'animations'], array_keys($fragment));
    $this->assertSame(['hero', 'opener', 'wide_section', 'stray'], array_keys($fragment['blueprints']), 'Every shared block blueprint, favorite or not, in roster order.');
    $this->assertStringStartsWith('<d-container', $fragment['blueprints']['stray']['fragment'], 'A non-favorite prints in full like any other.');
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers. The top of a page.', 'blueprints' => 2],
      'sections' => ['label' => 'Sections', 'blueprints' => 1],
      'cards' => ['label' => 'Cards', 'blueprints' => 0],
      DialectCatalog::UNGROUPED => ['blueprints' => 2],
    ], $fragment['groups']);
    $this->assertStringNotContainsString('landing', json_encode($fragment));
    $this->assertSame(['heroes', 'sections', 'cards', DialectCatalog::UNGROUPED], $schema->groupIds());
    $this->assertStringContainsString('"groups" is the site\'s group vocabulary (the ids a blueprint\'s groups take) with how many offered blueprints each holds', $fragment['guidance']);
  }

  /**
   * One group alone, after the cache.
   *
   * The listing replaces the whole set in place.
   *
   * Narrowing takes the fragment as built (what the schema cache holds)
   * and swaps its blueprints group for the group's shared block
   * blueprints alone, every other group carried through in its
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
    $this->assertSame(['opener', 'wide_section'], array_keys($heroes['blueprints']));
    $this->assertStringStartsWith('<d-container', $heroes['blueprints']['wide_section']['fragment'], 'A group lists its blueprints in full.');
    $this->assertSame($fragment['groups'], $heroes['groups'], 'The index is carried through as cached.');
    $this->assertSame($fragment['guidance'], $heroes['guidance']);

    $this->assertSame(['hero', 'stray'], array_keys($schema->narrow($fragment, NULL, DialectCatalog::UNGROUPED)['blueprints']), 'The reserved bucket: no group, or only groups this site lacks; roster order.');
    $cards = $schema->narrow($fragment, NULL, 'cards');
    $this->assertArrayNotHasKey('blueprints', $cards, 'A declared group holding nothing lists nothing.');
    $this->assertSame(['guidance', 'tokens', 'variants', 'groups', 'animations'], array_keys($cards));

    try {
      $schema->narrow($fragment, NULL, 'foreign');
      $this->fail('An undeclared group id was narrowed to.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('heroes, sections, cards, ungrouped', $e->getMessage());
    }
  }

  /**
   * The dialect door's narrowing.
   *
   * What the brief does not carry, its own guidance.
   *
   * The narrowing takes the fragment as cached and keeps DIALECT_KEYS alone
   * (the tokens and variants are the brief's on that door),
   * carries the group index and the blueprints through whole (the
   * fragment and the looks are the one place a blueprint's rule rides),
   * drops the description from every animation and font (the brief's)
   * while keeping their spelling, steps and faces, and writes the
   * dialect paragraph: the opening says where the rest is, then the
   * kind sentences the JSON door's paragraph also states, one text
   * each, and never a token or variant sentence. The JSON door's
   * fragment is untouched, and dialectKinds() names the kinds from the
   * same key list.
   */
  public function testDialectNarrowing(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Font,
        id: 'inter',
        label: 'Inter',
        description: 'The body face. Never at display sizes.',
        value: [
          'family' => 'Inter',
          'faces' => [['weight' => '400', 'style' => 'normal', 'source' => ['file' => 'f1']]],
        ],
        spellings: [MaterialRecord::SLOT_FONT => '"Inter"'],
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();
    $this->assertSame(['guidance', 'tokens', 'variants', 'groups', 'blueprints', 'animations', 'fonts'], array_keys($fragment));

    $dialect = $schema->dialect($fragment);
    $this->assertSame(MaterialsSchema::DIALECT_KEYS, array_keys($dialect));
    $this->assertSame(['guidance', 'groups', 'blueprints', 'animations', 'fonts'], array_keys($dialect));
    $this->assertSame($fragment['groups'], $dialect['groups'], 'The index carries through as cached.');
    $this->assertSame($fragment['blueprints'], $dialect['blueprints'], 'A blueprint carries through whole: its description, its looks.');
    $this->assertSame([
      'label' => 'Fade in',
      'spelling' => 'animation-name: motion-fade_in',
      'steps' => ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']],
    ], $dialect['animations']['fade_in'], 'An animation keeps its spelling and steps; its description is the brief\'s.');
    $this->assertSame([
      'label' => 'Inter',
      'spelling' => 'font-family: "Inter"',
      'family' => 'Inter',
      'faces' => [
        ['weight' => '400', 'style' => 'normal', 'source' => ['file' => 'f1']],
      ],
    ], $dialect['fonts']['inter'], 'A font keeps its family and faces; its description is the brief\'s.');
    $this->assertStringNotContainsString('Fade the element in from transparent', json_encode($dialect));
    $this->assertStringNotContainsString('Never at display sizes', json_encode($dialect));
    $this->assertStringNotContainsString('--color-accent', json_encode($dialect));
    $this->assertStringNotContainsString('container_full', json_encode($dialect));

    $guidance = $dialect['guidance'];
    $this->assertStringStartsWith(MaterialsSchema::DIALECT_OPENING, $guidance);
    $this->assertStringContainsString('the brief lists every token, variant, animation and font with its whole description and value', $guidance);
    $this->assertStringContainsString('this key carries what composing reads on demand, the blueprints in full, the animations\' steps and the fonts\' faces', $guidance);
    $this->assertStringContainsString('one of those kinds absent here has none on this site', $guidance);
    $this->assertStringContainsString('<d-blueprint name="id"/>', $guidance);
    $this->assertStringContainsString(MaterialsSchema::LOOKS_BY_POSITION, $guidance);
    $this->assertStringContainsString('eb_read_schema with group (an id, or ungrouped)', $guidance);
    $this->assertStringContainsString(MaterialsSchema::ANIMATIONS_SENTENCE, $guidance);
    $this->assertStringContainsString(MaterialsSchema::FONTS_SENTENCE, $guidance);
    $this->assertStringNotContainsString('"placement"', $guidance, 'No listed blueprint is a section.');
    $json_door_only = [
      '"tokens" are',
      '"variants" are',
      'var(--space-4)',
      'built-in "style" axis',
      'a kind absent here has no materials',
    ];
    foreach ($json_door_only as $json_door) {
      $this->assertStringNotContainsString($json_door, $guidance, "The dialect paragraph never says '$json_door'.");
    }
    // The JSON door's paragraph states the same kind sentences.
    $this->assertStringContainsString(MaterialsSchema::ANIMATIONS_SENTENCE, $fragment['guidance']);
    $this->assertStringContainsString(MaterialsSchema::FONTS_SENTENCE, $fragment['guidance']);
    $this->assertStringNotContainsString(MaterialsSchema::DIALECT_OPENING, $fragment['guidance']);

    $this->assertSame(['blueprints', 'animations', 'fonts'], MaterialsSchema::dialectKinds());
    $this->assertSame(['blueprints', 'animations', 'fonts'], MaterialsSchema::dialectKinds(array_keys($dialect)));
    $this->assertSame(['animations'], MaterialsSchema::dialectKinds(['guidance', 'groups', 'animations']));
    $this->assertSame([], MaterialsSchema::dialectKinds(['guidance', 'tokens']), 'A kind the dialect door never carries is never named.');

    // The JSON door's fragment is untouched: whole descriptions, every kind.
    $this->assertSame($fragment, $schema->fragment());
    $this->assertSame('Fade the element in from transparent.', $fragment['animations']['fade_in']['description']);
    $this->assertSame('The body face. Never at display sizes.', $fragment['fonts']['inter']['description']);
  }

  /**
   * The narrowing grows by data over the fragment's own keys.
   *
   * A roster of tokens alone narrows to the opening sentence and no kind
   * (the brief says the site has tokens; this shape says nothing of
   * them); a listed section brings the placement sentence, as the JSON
   * door's paragraph carries it while the roster has one.
   */
  public function testDialectNarrowingGrowsByData(): void {
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    TestMaterialsProvider::$records = array_values(array_filter(
      TestMaterialsProvider::referenceRecords(),
      static fn ($record) => $record->kind->value === 'token',
    ));
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $dialect = $schema->dialect($schema->fragment());
    $this->assertSame(['guidance'], array_keys($dialect));
    $this->assertSame(MaterialsSchema::DIALECT_OPENING, $dialect['guidance']);
    $this->assertSame([], MaterialsSchema::dialectKinds(array_keys($dialect)));

    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section. Never two in a row.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
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
    $dialect = $schema->dialect($schema->fragment());
    $this->assertSame(['guidance', 'groups', 'blueprints', 'animations'], array_keys($dialect));
    $this->assertSame(MaterialsSchema::PLACEMENT_TOP_LEVEL, $dialect['blueprints']['wide_section']['placement']);
    $this->assertSame('A full-width section. Never two in a row.', $dialect['blueprints']['wide_section']['description'], 'A blueprint\'s rule rides the schema with its fragment.');
    $this->assertStringContainsString('A blueprint with "placement": "top_level" is a section', $dialect['guidance']);
  }

  /**
   * A group read and the dialect narrowing compose.
   *
   * The schema read narrows to a group first (after the cache) and the
   * projector narrows the kinds after: the group's blueprints print in
   * full where the whole set sat and the shape is the dialect door's.
   * The other order gives the same shape too (narrow() keeps a dialect
   * fragment's keys), the guidance following the listing it was written
   * over. A declared group holding nothing leaves the blueprints group
   * absent while the blueprint vocabulary rides on the index.
   */
  public function testDialectNarrowingComposesWithGroupRead(): void {
    $this->groupedRoster();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();

    $heroes = $schema->dialect($schema->narrow($fragment, NULL, 'heroes'));
    $this->assertSame(['guidance', 'groups', 'blueprints', 'animations'], array_keys($heroes));
    $this->assertSame(['opener', 'wide_section'], array_keys($heroes['blueprints']));
    $this->assertStringStartsWith('<d-container', $heroes['blueprints']['wide_section']['fragment']);
    $this->assertSame($fragment['groups'], $heroes['groups']);
    $this->assertStringStartsWith(MaterialsSchema::DIALECT_OPENING, $heroes['guidance']);
    $this->assertSame($heroes, $schema->narrow($schema->dialect($fragment), NULL, 'heroes'));

    $cards = $schema->dialect($schema->narrow($fragment, NULL, 'cards'));
    $this->assertSame(['guidance', 'groups', 'animations'], array_keys($cards));
    $this->assertStringContainsString('<d-blueprint name="id"/>', $cards['guidance'], 'The index says the site has blueprints, so the vocabulary rides.');
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

  /**
   * The fragment weighed by kind: each kind's group as JSON, the rest system.
   *
   * A shared blueprint with a tree weighs its whole printed fragment under
   * blueprint (sharing is the lever the budget watches; it is no
   * favorite, which changes nothing); the guidance and the group
   * index are the system's; a kind the site lacks weighs 0; the sums
   * equal the fragment's own; the metadata is the roster's and the offer's.
   */
  public function testWeights(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        tree: [[
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
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
    $this->reset();
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $fragment = $schema->fragment();
    $json = static fn (array $value): int => strlen(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $weights = $schema->weights();
    $this->assertSame($json($fragment['tokens']), $weights->of(MaterialKind::Token));
    $this->assertSame($json($fragment['variants']), $weights->of(MaterialKind::Variant));
    $this->assertSame($json($fragment['blueprints']), $weights->of(MaterialKind::Blueprint));
    $this->assertSame($json($fragment['animations']), $weights->of(MaterialKind::Animation));
    $this->assertSame(0, $weights->of(MaterialKind::Font));
    $this->assertSame(strlen($fragment['guidance']) + $json($fragment['groups']), $weights->system);
    $this->assertSame(array_sum(array_map(static fn ($value): int => is_string($value) ? strlen($value) : $json($value), $fragment)), $weights->total());

    // Entry by entry: a record weighs its entry's JSON plus its key,
    // quotes and colon, keyed by the record id (a named value's key is
    // its custom property, its record id the roster's); the kind's
    // braces, the commas between entries and a scale's key and braces
    // are the kind's shared; records plus shared is the kind's figure.
    $entry = static fn (string $key, array $value): int => strlen(json_encode($key)) + 1 + $json($value);
    $this->assertSame($entry('--color-accent', $fragment['tokens']['color']['--color-accent']), $weights->record(MaterialKind::Token, 'color/accent'));
    $this->assertSame($entry('--color-lime-green', $fragment['tokens']['color']['--color-lime-green']), $weights->record(MaterialKind::Token, 'color/lime-green'));
    $this->assertSame(['color/accent', 'color/lime-green'], array_keys($weights->records[MaterialKind::Token->value]));
    $this->assertSame(strlen('{"color":{,}}'), $weights->shared[MaterialKind::Token->value]);
    $this->assertSame(['button', 'container_full', 'block_full', 'link_quiet'], array_keys($weights->records[MaterialKind::Variant->value]));
    $this->assertSame($entry('button', $fragment['variants']['button']), $weights->record(MaterialKind::Variant, 'button'));
    $this->assertSame(strlen('{,,,}'), $weights->shared[MaterialKind::Variant->value]);
    $this->assertSame($entry('wide_section', $fragment['blueprints']['wide_section']), $weights->record(MaterialKind::Blueprint, 'wide_section'));
    $this->assertSame($entry('hero', $fragment['blueprints']['hero']), $weights->record(MaterialKind::Blueprint, 'hero'));
    $this->assertSame(strlen('{,}'), $weights->shared[MaterialKind::Blueprint->value]);
    $this->assertSame($entry('fade_in', $fragment['animations']['fade_in']), $weights->record(MaterialKind::Animation, 'fade_in'));
    $this->assertSame([], $weights->records[MaterialKind::Font->value]);
    $this->assertSame(0, $weights->shared[MaterialKind::Font->value]);
    foreach (MaterialKind::cases() as $kind) {
      $this->assertSame($weights->shared[$kind->value] + array_sum($weights->records[$kind->value]), $weights->of($kind), $kind->value);
    }

    // The blueprint's printed fragment is in the blueprint figure, and
    // the owned look rides in the blueprint's record, nowhere else.
    $this->assertStringContainsString('<d-block type="text">', $fragment['blueprints']['wide_section']['fragment']);
    $this->assertArrayHasKey('hero_glow', $fragment['blueprints']['hero']['looks']);
    $this->assertSame(0, $weights->record(MaterialKind::Variant, 'hero_glow'));

    // Unshare it (the offered view drops the record): the blueprint
    // figure drops to the other blueprint's entry (the whole printed
    // fragment left with it), nothing else moves.
    TestMaterialsProvider::$records = TestMaterialsProvider::referenceRecords();
    $this->reset();
    $after = $schema->weights();
    $this->assertSame($json(['hero' => $fragment['blueprints']['hero']]), $after->of(MaterialKind::Blueprint));
    $this->assertSame(['hero' => $weights->record(MaterialKind::Blueprint, 'hero')], $after->records[MaterialKind::Blueprint->value]);
    $this->assertSame(strlen('{}'), $after->shared[MaterialKind::Blueprint->value]);
    $this->assertSame($weights->of(MaterialKind::Variant), $after->of(MaterialKind::Variant));
    $this->assertSame($weights->records[MaterialKind::Variant->value], $after->records[MaterialKind::Variant->value]);

    // What the weights derive from, answered without a render.
    $metadata = $schema->cacheMetadata();
    $this->assertContains('materials_test_list', $metadata->getCacheTags());
    $this->assertContains('config:field_config_list', $metadata->getCacheTags());
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
  }

}
