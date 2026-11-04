<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Drupal\daedalus_blueprint_ai\PromptLines;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Snapshot of the cached prompt's design system brief over the fixture roster.
 *
 * The brief is the styling half of the page-language section: the
 * active design system by name with its identity, law and skin, the
 * bindings that bind no token scale on one line under the law, the
 * membership, preference, standing-procedure and stack sentences, then
 * the roster in the system's structure (per element kind its axes as
 * nested lists, the heading carrying the whole description and the owns
 * clause and one line per value with its whole description, its class
 * looks, top-level only when root-only, its birth line and its
 * deviations in dialect words; the looks no element roster places; every
 * shared block blueprint as a menu line, each with the looks it owns
 * beneath it, and the group index over them; the named
 * values as nested lists, a scale heading carrying its bindings and a
 * line per token with its whole description; animations; the
 * dressed tags) and one mint line. Every description is whole but the
 * blueprint menu lines and the group index, the clip's two survivors.
 * The fixture provider (one record per kind, plus the applicability
 * cases) over a hand-built catalog renders every block, and this pins
 * the whole brief verbatim: a kind gaining or losing a line, a spelling
 * drifting from the roster's prose, or a sentence's composition changing
 * fails loudly here instead of silently in the prompt.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class DialectCatalogPromptMaterialsTest extends KernelTestBase {

  /**
   * The BLUEPRINTS header: every shared blueprint, then the index.
   */
  protected const BLUEPRINTS_HEADER = 'BLUEPRINTS (every blueprint shared with you; id "Label" (a section says top-level only; a saved one names the entity_id page_read and page_edit take as entity_type blueprint, a shipped one is read-only); the schema prints each in full; the looks beneath a blueprint are its own, wearable inside its placements):';

  /**
   * The GROUPS header: the index's shape and how one group is read alone.
   */
  protected const GROUPS_HEADER = 'GROUPS (the blueprints above by group: id "Label" (how many): what the group is for; ungrouped holds the ones in no group; eb_read_schema with group: id prints that group\'s blueprints alone):';

  /**
   * The MINT line over the fixture's two gesture lines.
   */
  protected const MINT_LINE = 'MINT (save_material {kind, label, ...}; never mint a material unless the user asks, and a mint is published site-wide at once): token: value; blueprint: element.';

  /**
   * {@inheritdoc}
   *
   * 'key' and 'file' are ai dependencies (the PAGES line asks every
   * visible bundle's unsaved host whether it is a page, and ai_file's
   * fields reference file).
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'file',
    'ai',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_materials_test',
    'daedalus_blueprint_design_system_test',
    'daedalus_blueprint_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
    TestMaterialsProvider::$mintPosture = NULL;
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
  }

  /**
   * The real prompt service with its brief projection exposed.
   *
   * BriefLines() is the prompt's own seam (render() composes it into the
   * section between the stylable lines and the ops); exposing it keeps
   * the snapshot on the brief alone, over a catalog the test hands it.
   */
  protected function prompt(): object {
    return new class(
      $this->container->get('daedalus_blueprint.dialect_catalog_builder'),
      $this->container->get('daedalus_blueprint.operation_handler_registry'),
      $this->container->get('daedalus_blueprint.materials_roster_builder'),
      $this->container->get('daedalus_blueprint.active_design_system'),
      $this->container->get('daedalus_blueprint.bundle_list_builder'),
    ) extends DialectCatalogPrompt {

      /**
       * The brief's lines for a catalog.
       */
      public function brief(DialectCatalog $catalog): array {
        return $this->briefLines($catalog)->texts();
      }

      /**
       * The brief's lines for a catalog, attributed.
       */
      public function attributed(DialectCatalog $catalog): PromptLines {
        return $this->briefLines($catalog);
      }

    };
  }

  /**
   * A catalog over the live roster with hand-built styling contributions.
   *
   * What daedalus_stylish would contribute on a real site: the container's
   * width axis with a description and the properties it owns, the block kind's
   * bare width axis, a text block type (so the fixture's button look is placed
   * on <d-text>), the dark condition, the strict prose, the container layout's
   * birth default, the dressed tags and the skin; the bindings and deviations a
   * case hands it (none by default, as a site that binds nothing).
   */
  protected function catalog(bool $styled = TRUE, array $groups = [], array $bindings = [], array $deviations = [], ?array $axes = NULL): DialectCatalog {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return new DialectCatalog(
      groups: $groups,
      containerLayoutId: 'layout_onecol',
      axes: $axes ?? [
        'kind:container' => [
          'width' => [
            'description' => 'How wide the section runs; most sections keep the site column.',
            'owns' => ['padding-left', 'padding-right'],
          ],
        ],
        'kind:block' => ['width' => []],
      ],
      blockTypes: ['text' => ['attributes' => ['tag' => ['field' => 'field_tag', 'values' => ['h1', 'h2', 'p']]]]],
      conditions: ['dark' => ['wrapper' => '@media (prefers-color-scheme: dark)', 'axis' => 'scheme']],
      roster: $builder->build(),
      prose: $styled ? new VocabularyProse('strict') : NULL,
      birthDefaults: $styled ? ['layout:layout_onecol' => ['width' => 'container_full']] : [],
      dressedTags: $styled ? ['h1', 'h2', 'p'] : [],
      skin: $styled ? 'Aurora' : NULL,
      bindings: $bindings,
      deviations: $deviations,
    );
  }

  /**
   * The fixture system's doctrine line.
   *
   * Membership, preference, procedure, stack.
   */
  protected function doctrineLine(): string {
    return 'The materials below are Aurora\'s members plus the global ones (members of no system, offered under every system); a save_material mint joins Aurora unless it passes "global": true. ' . MaterialsProse::preferenceSentence() . ' ' . MaterialsProse::standingProcedureSentence() . ' ' . (new VocabularyProse('strict'))->stackSentence();
  }

  /**
   * The whole brief, verbatim, under the fixture system.
   */
  public function testBrief(): void {
    $this->container->get('daedalus_blueprint.active_design_system')->activate('aurora');
    $lines = $this->prompt()->brief($this->catalog());
    $strict = new VocabularyProse('strict');

    $this->assertSame([
      '## Design system: Aurora',
      'A test design system: cool light on a dark ground.',
      'Law: strict. ' . $strict->polaritySentence() . ' Skin: Aurora; its named values also resolve under dark (@media (prefers-color-scheme: dark)).',
      $this->doctrineLine(),
      '<d-container>:',
      '- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the section runs; most sections keep the site column.',
      '  - full "Full width": Let the container span the full page width.',
      '- Born from the palette wearing width="full"; a document states what it wears itself.',
      'Every block element:',
      '- width:',
      '  - full "Full width": Let the block span its section\'s full width.',
      '<d-text>:',
      '- button "Button" (class="button"): Dress the text block\'s link as a solid button.',
      'OTHER LOOKS (worn where their scope fits):',
      '- link_quiet "Quiet link" (class="link_quiet"; scope base:a)',
      self::BLUEPRINTS_HEADER,
      '- hero "Hero": Single-column hero banner with a heading',
      '  - hero_glow "Hero glow" (class="hero_glow")',
      self::GROUPS_HEADER,
      '- ungrouped (1 blueprint)',
      'NAMED VALUES (var(--name)):',
      '- color:',
      '  - --color-accent: #1475ad (dark: #52b3eb)',
      '  - --color-lime-green: #4dff8c',
      'ANIMATIONS (id "Label" (animation-name: spelling)):',
      '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.',
      'The skin dresses h1, h2, p: pick the tag, the skin sizes it.',
      self::MINT_LINE,
    ], $lines);
    // The mode is spelled on the Law line and nowhere else in the brief.
    $this->assertCount(1, array_filter($lines, static fn (string $line): bool => (bool) preg_match('/\bstrict\b/', $line)));
  }

  /**
   * No active system: the brief says so and keeps the guarded posture.
   *
   * The global materials still ride (the fixture roster is global), the
   * membership sentence says a mint is global, and with no styling layer
   * contributing there is no polarity, stack, skin, birth or dressed
   * line: the brief grows by data like everything else.
   */
  public function testNoActiveSystem(): void {
    $lines = $this->prompt()->brief($this->catalog(styled: FALSE));
    $this->assertSame('## Design system: none active', $lines[0]);
    $this->assertSame('Law: guarded. Skin: none.', $lines[1]);
    $this->assertSame(MaterialsProse::membershipSentence(NULL) . ' ' . MaterialsProse::preferenceSentence() . ' ' . MaterialsProse::standingProcedureSentence(), $lines[2]);
    $this->assertStringStartsWith('No design system is active', $lines[2]);
    $this->assertContains('- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the section runs; most sections keep the site column.', $lines);
    $this->assertContains('  - full "Full width": Let the container span the full page width.', $lines);
    $this->assertNotContains('- Born from the palette wearing width="full"; a document states what it wears itself.', $lines);
    $this->assertSame([], preg_grep('/^The skin dresses/', $lines));
  }

  /**
   * A blueprint's line carries top-level only and its page id.
   *
   * Block scope only.
   *
   * The section qualifier comes from the record; a saved blueprint's line
   * names the entity_id the page tools address it by, a shipped one's (no
   * entity id) does not. The block prints block scope alone, so no line
   * explains a scope: a page blueprint is a start, listed by a bundle's
   * schema read under starts, and is on no line of the cached prefix
   * (which has no bundle dimension), its description included. A
   * blueprint's line is a menu line (the first sentence; the rule rides
   * the schema with the fragment); a token's whole description, the
   * rule in its second sentence included, closes its named-value line.
   */
  public function testBlueprintAndTokenLines(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'landing',
        label: 'Landing',
        description: 'A whole landing page. Hero, columns, a closing call.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'landing'],
        blueprintScope: MaterialRecord::SCOPE_PAGE,
        entityId: '9',
        tree: [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]],
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section. Edge to edge.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
        applies: [MaterialRecord::APPLIES_WEARS => ['container_full']],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        favorite: TRUE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'saved_card',
        label: 'Saved card',
        description: 'A card saved on this site. Edit it on its canvas.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'saved_card'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        entityId: '7',
        favorite: TRUE,
      ),
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
    $lines = $this->prompt()->brief($this->catalog());
    $this->assertContains('- hero "Hero": Single-column hero banner with a heading', $lines);
    $this->assertContains('- wide_section "Wide section" (top-level only): A full-width section', $lines);
    $this->assertContains('- saved_card "Saved card" (entity_id 7): A card saved on this site', $lines);
    $this->assertContains('- ungrouped (3 blueprints)', $lines, 'The index counts block scope alone: the page blueprint is no entry.');
    $this->assertStringNotContainsString('landing', implode("\n", $lines), 'A page blueprint is on no line of the prefix.');
    $this->assertStringNotContainsString('(block', implode("\n", $lines), 'No line explains a scope.');
    $start = array_search('- color:', $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      '- color:',
      '  - --color-accent: #1475ad (dark: #52b3eb)',
      '  - --color-lime-green: #4dff8c',
      '  - --color-ink: #1a1a2e — Reach for it on body copy over a light ground. Never on buttons.',
    ], array_slice($lines, $start, 4));
  }

  /**
   * A material opted out of the AI is on no line of the rendered section.
   *
   * Render() reads the catalog's offered view once, so the opt-out
   * reaches every surface that names materials: the element lines' axis
   * values and class rosters, the brief's element groups, OTHER LOOKS,
   * BLUEPRINTS, NAMED VALUES and ANIMATIONS, a heading emptied by the
   * opt-out included. What stays offered prints as before, and the
   * opted-out material stays on the full roster (legal to wear). The real
   * service over the real catalog builder, since the seam is render()'s.
   */
  public function testOptedOutMaterialsLeaveThePrompt(): void {
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
    foreach ([
      'token:color/lime-green',
      'variant:block_full',
      'variant:hero_glow',
      'variant:link_quiet',
      'animation:fade_in',
    ] as $key) {
      $reference[$key] = $opt_out($reference[$key]);
    }
    TestMaterialsProvider::$records = [
      ...array_values($reference),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'secret',
        label: 'Secret',
        description: 'For the UI only.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'secret'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        offered: FALSE,
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();
    $prompt = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();

    foreach ([
      'lime-green',
      'block_full',
      'hero_glow',
      'link_quiet',
      'fade_in',
      'secret',
      'For the UI only',
    ] as $needle) {
      $this->assertStringNotContainsString($needle, $prompt, "$needle left the prompt.");
    }
    $this->assertStringNotContainsString('Every block element:', $prompt, 'A heading emptied by the opt-out goes with it.');
    // Without a declared text block type the button look is placed on no
    // element and rides OTHER LOOKS; the unoffered quiet link is not
    // beside it.
    $this->assertStringContainsString("OTHER LOOKS (worn where their scope fits):\n- button \"Button\" (class=\"button\"; scope block_type:text): Dress the text block's link as a solid button.\nBLUEPRINTS", $prompt);
    $this->assertStringNotContainsString('ANIMATIONS', $prompt);
    $this->assertStringContainsString('<d-block plugin="plugin_id"/> renders an existing plugin block.', $prompt, 'The block kind\'s only axis value left the element line.');
    $this->assertStringNotContainsString('emphasis', $prompt, 'The block kind\'s only axis value left the element line.');
    $this->assertStringContainsString('- <d-container width(full; top-level only)>', $prompt);
    $this->assertStringContainsString('- hero "Hero": Single-column hero banner with a heading', $prompt);
    $this->assertStringNotContainsString('  - hero', $prompt, 'The unoffered owned look no longer lists under its blueprint.');
    $this->assertStringContainsString("\n- ungrouped (1 blueprint)\n", $prompt, 'The unoffered blueprint is not counted.');
    $this->assertStringContainsString("- color:\n  - --color-accent: #1475ad (dark: #52b3eb)\n", $prompt);

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertTrue($roster->has(MaterialKind::Token, 'color/lime-green'), 'The full roster keeps what the prompt leaves out.');
    $this->assertTrue($roster->has(MaterialKind::Blueprint, 'secret'));
    $this->assertSame(['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet'], array_keys($roster->variants()));
  }

  /**
   * N shared block blueprints filed under sections, none a favorite.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records.
   */
  protected function groupedBlueprints(int $count): array {
    $records = [];
    for ($i = 1; $i <= $count; $i++) {
      $records[] = new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'section_' . $i,
        label: 'Section ' . $i,
        description: 'Section number ' . $i . '. Reach for it on page ' . $i . '.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'section_' . $i],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['sections'],
        entityId: (string) $i,
      );
    }
    return $records;
  }

  /**
   * Every shared block blueprint is a menu line; the index groups them.
   *
   * The lines are every offered block blueprint in roster order, favorite
   * (the fixture's hero, the opener) or not (the wide section, the
   * stray): sharing is the one lever and the favorite flag is the Add
   * panel's listing. Each carries its owned looks as before. The index
   * that follows is in the neighbors' shape: one line per declared group
   * that holds a shared block blueprint, in declaration order, with its
   * count and its description's first sentence (a group holding nothing
   * prints no line), then the reserved bucket counting the ones in no
   * declared group (an undeclared id counts nowhere). A page blueprint is
   * a start and is neither a line nor a count, favorite or not.
   */
  public function testEverySharedBlueprintAndTheIndex(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'opener',
        label: 'Opener',
        description: 'An opening section. Reach for it first.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'opener'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['heroes'],
        favorite: TRUE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_section',
        label: 'Wide section',
        description: 'A full-width section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_section'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['sections', 'heroes'],
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'stray',
        label: 'Stray',
        description: 'Filed under a group this site lacks.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'stray'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['foreign'],
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
      ),
    ];
    $lines = $this->prompt()->brief($this->catalog(groups: [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers. The top of a page.'],
      'sections' => ['label' => 'Sections', 'description' => ''],
      'cards' => ['label' => 'Cards', 'description' => 'Never held here.'],
    ]));
    $start = array_search(self::BLUEPRINTS_HEADER, $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      self::BLUEPRINTS_HEADER,
      '- hero "Hero": Single-column hero banner with a heading',
      '  - hero_glow "Hero glow" (class="hero_glow")',
      '- opener "Opener": An opening section',
      '- wide_section "Wide section": A full-width section',
      '- stray "Stray": Filed under a group this site lacks',
      self::GROUPS_HEADER,
      '- heroes "Heroes" (2 blueprints): Full-width openers',
      '- sections "Sections" (1 blueprint)',
      '- ungrouped (2 blueprints)',
      'NAMED VALUES (var(--name)):',
    ], array_slice($lines, $start, 11));
    $text = implode("\n", $lines);
    foreach (['landing', 'cards', 'Never held here'] as $absent) {
      $this->assertStringNotContainsString($absent, $text, "$absent is on no line of the prefix.");
    }
  }

  /**
   * The block follows the shared set: one line per shared blueprint.
   *
   * Fifty shared block blueprints, none a favorite, print fifty menu
   * lines where five print five, under the one header, then the index
   * header and the group's line with its count. Sharing is the lever and
   * the AI context budget the ceiling (MaterialsBudget refuses sharing
   * past it); no favorite rule hides a shared blueprint behind a count.
   */
  public function testTheBlockFollowsTheSharedSet(): void {
    $block = function (int $count): array {
      TestMaterialsProvider::$records = $this->groupedBlueprints($count);
      $lines = $this->prompt()->brief($this->catalog(styled: FALSE, groups: [
        'sections' => ['label' => 'Sections', 'description' => 'Horizontal sections.'],
      ]));
      $start = array_search(self::BLUEPRINTS_HEADER, $lines, TRUE);
      $this->assertIsInt($start);
      $end = array_search(self::MINT_LINE, $lines, TRUE);
      $this->assertIsInt($end);
      return array_slice($lines, $start, $end - $start);
    };
    $five = $block(5);
    $fifty = $block(50);
    $this->assertSame([
      self::BLUEPRINTS_HEADER,
      '- section_1 "Section 1" (entity_id 1): Section number 1',
      '- section_2 "Section 2" (entity_id 2): Section number 2',
      '- section_3 "Section 3" (entity_id 3): Section number 3',
      '- section_4 "Section 4" (entity_id 4): Section number 4',
      '- section_5 "Section 5" (entity_id 5): Section number 5',
      self::GROUPS_HEADER,
      '- sections "Sections" (5 blueprints): Horizontal sections',
    ], $five);
    $this->assertCount(53, $fifty, 'A line per shared blueprint, the two headers and the group line.');
    $this->assertSame(self::BLUEPRINTS_HEADER, $fifty[0]);
    $this->assertSame('- section_50 "Section 50" (entity_id 50): Section number 50', $fifty[50]);
    $this->assertSame(self::GROUPS_HEADER, $fifty[51]);
    $this->assertSame('- sections "Sections" (50 blueprints): Horizontal sections', $fifty[52]);
  }

  /**
   * A token's whole description rides its named-value line, after the value.
   *
   * The brief is the one home of a token's text: the rule in the second
   * sentence ("never on a ground") reaches the model here or nowhere.
   */
  public function testTokenDescriptionRidesWhole(): void {
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
    $lines = $this->prompt()->brief($this->catalog());
    $start = array_search('NAMED VALUES (var(--name)):', $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      'NAMED VALUES (var(--name)):',
      '- color:',
      '  - --color-ink: #1a1a2e — The reading color. Reach for it on body text and headings, never on a ground.',
    ], array_slice($lines, $start, 3), 'The token line carries the custom property, the value and the whole description.');
  }

  /**
   * An axis heading carries its whole description, each value its own below.
   *
   * The nested list is the grammar that survives the semicolons the
   * rules are written with: the heading's three sentences, the value's
   * two, read as written, with no separator for them to collide with.
   */
  public function testAxisDescriptionRidesWhole(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'container_site',
        label: 'Site width',
        description: 'Constrain the container to the site column; centered. The default: every section of copy wears it.',
        group: 'width',
        spellings: [MaterialRecord::attrSlot('kind:container', 'width') => 'site'],
        scope: Address::fromString('kind:container'),
        applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT],
      ),
    ];
    $lines = $this->prompt()->brief($this->catalog(axes: [
      'kind:container' => [
        'width' => [
          'description' => 'How wide a section lets its content run. A section\'s background already spans the screen at every width, so Full width is never the way to get a full-bleed background; reach for it when the content itself should reach the edges. A nested container inherits its section\'s width and never wears it.',
          'owns' => ['padding-left', 'padding-right'],
        ],
      ],
      'kind:block' => ['width' => []],
    ]));
    $start = array_search('<d-container>:', $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      '<d-container>:',
      '- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide a section lets its content run. A section\'s background already spans the screen at every width, so Full width is never the way to get a full-bleed background; reach for it when the content itself should reach the edges. A nested container inherits its section\'s width and never wears it.',
      '  - full "Full width": Let the container span the full page width.',
      '  - site "Site width": Constrain the container to the site column; centered. The default: every section of copy wears it.',
      '- Born from the palette wearing width="full"; a document states what it wears itself.',
      'Every block element:',
    ], array_slice($lines, $start, 6));
  }

  /**
   * A root-only class look says top-level only on its line.
   *
   * A section's look is wearable at the top level alone, as a section axis is;
   * the axis heading says so and the look's line says so the same way,
   * beside its spelling.
   */
  public function testRootOnlyLookSaysTopLevelOnly(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'section_glow',
        label: 'Section glow',
        description: 'A soft glow behind a section. Never two glowing sections in a row.',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'section_glow'],
        scope: Address::fromString('kind:container'),
        applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT],
      ),
    ];
    $lines = $this->prompt()->brief($this->catalog());
    $this->assertContains('- section_glow "Section glow" (class="section_glow"; top-level only): A soft glow behind a section. Never two glowing sections in a row.', $lines);
    $this->assertContains('- button "Button" (class="button"): Dress the text block\'s link as a solid button.', $lines, 'A look worn at any depth says nothing about depth.');
  }

  /**
   * The bindings ride the scale headings; the rest ride one line under the law.
   *
   * A property bound to a scale an offered token names prints on that
   * scale's heading under NAMED VALUES ("font-size takes only text
   * values"), the properties sharing one line grouped into one sentence;
   * a range, an enumeration or a scale no offered token names rides the
   * line under the law. Nothing in the fixture's prose names a posture.
   */
  public function testBindingsRideTheScaleHeadings(): void {
    $this->container->get('daedalus_blueprint.active_design_system')->activate('aurora');
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'text/body',
        label: 'body',
        description: 'The reading size.',
        value: '1rem',
        group: 'text',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--text-body)'],
      ),
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'space/1',
        label: '1',
        value: '0.25rem',
        group: 'space',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--space-1)'],
      ),
    ];
    $lines = $this->prompt()->brief($this->catalog(bindings: [
      'font-size' => ['scale' => 'text'],
      'line-height' => ['scale' => 'leading'],
      'padding' => ['scale' => 'space', 'allowed' => ['0']],
      'gap' => ['scale' => 'space', 'allowed' => ['0']],
      'margin' => ['scale' => 'space', 'allowed' => ['0', 'auto']],
      'text-shadow' => ['allowed' => ['none']],
      'text-transform' => ['allowed' => ['none']],
      'z-index' => ['min' => '0', 'max' => '10'],
    ]));
    $this->assertSame('Bindings with no scale (a scale\'s own ride its heading under NAMED VALUES): line-height takes only leading values. text-shadow, text-transform take one of: none. z-index takes at least 0, at most 10.', $lines[3]);
    $this->assertSame($this->doctrineLine(), $lines[4]);
    $start = array_search('NAMED VALUES (var(--name)):', $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      'NAMED VALUES (var(--name)):',
      '- color:',
      '  - --color-accent: #1475ad (dark: #52b3eb)',
      '  - --color-lime-green: #4dff8c',
      '- text (bindings: font-size takes only text values):',
      '  - --text-body: 1rem — The reading size.',
      '- space (bindings: padding, gap take only space values; also allowed: 0. margin takes only space values; also allowed: 0, auto):',
      '  - --space-1: 0.25rem',
    ], array_slice($lines, $start, 8));
    $this->assertCount(1, array_filter($lines, static fn (string $line): bool => (bool) preg_match('/\bstrict\b/', $line)));
  }

  /**
   * The deviations ride the element groups, each place in dialect words.
   *
   * The container's own place merges the kind's and the container
   * layout's (the layout's space winning); a declared block type drops
   * what the block kind already says at the same place; a part is
   * "under" its selector; a plugin block and a block type the catalog
   * declares no tag for are "on" their <d-block> tag under the block
   * group; the field element prints a group of its own when a deviation
   * narrows it; the entity root and a layout that is not the container's
   * are on no line.
   */
  public function testDeviationsRideTheElementGroups(): void {
    $this->container->get('daedalus_blueprint.active_design_system')->activate('aurora');
    $lines = $this->prompt()->brief($this->catalog(
      bindings: [
        'color' => ['scale' => 'color'],
        'font-size' => ['scale' => 'text'],
        'letter-spacing' => ['scale' => 'tracking'],
        'padding-top' => ['scale' => 'space'],
        'gap' => ['scale' => 'space'],
      ],
      deviations: [
        'kind:container' => ['background-image' => ['allowed' => ['none']]],
        'layout:layout_onecol' => [
          'background-image' => ['scale' => 'color', 'stops' => ['bg', 'surface']],
          'padding-top' => ['scale' => 'space', 'stops' => ['1', '2']],
        ],
        'kind:block' => ['font-size' => ['scale' => 'text', 'stops' => ['body']]],
        'block_type:text' => [
          'font-size' => ['scale' => 'text', 'stops' => ['body']],
          'letter-spacing' => ['scale' => 'tracking', 'stops' => ['body']],
        ],
        'block_type:text::.text-block-link' => ['color' => ['scale' => 'color', 'stops' => ['link']]],
        'block_type:gallery' => ['gap' => ['scale' => 'space', 'stops' => ['3']]],
        'block_plugin:system_branding_block::.site-logo' => ['width' => ['max' => '200px']],
        'kind:field' => ['color' => ['scale' => 'color', 'stops' => ['text']]],
        'kind:entity' => ['color' => ['allowed' => ['red']]],
        'layout:layout_twocol' => ['gap' => ['allowed' => ['0']]],
      ],
    ));
    $start = array_search('<d-container>:', $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      '<d-container>:',
      '- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the section runs; most sections keep the site column.',
      '  - full "Full width": Let the container span the full page width.',
      '- Born from the palette wearing width="full"; a document states what it wears itself.',
      '- Deviations: background-image color values bg, surface only; padding-top limited to 1, 2',
      'Every block element:',
      '- width:',
      '  - full "Full width": Let the block span its section\'s full width.',
      '- Deviations: font-size limited to body',
      '- Deviations on <d-block type="gallery">: gap limited to 3',
      '- Deviations on <d-block plugin="system_branding_block"> under .site-logo: width at most 200px',
      '<d-text>:',
      '- button "Button" (class="button"): Dress the text block\'s link as a solid button.',
      '- Deviations: letter-spacing limited to body',
      '- Deviations under .text-block-link: color limited to link',
      '<d-field>:',
      '- Deviations: color limited to text',
      'OTHER LOOKS (worn where their scope fits):',
    ], array_slice($lines, $start, 18));
    $text = implode("\n", $lines);
    foreach (['one of: red', 'one of: 0', 'layout_twocol', 'kind:', 'block_type:', 'block_plugin:'] as $absent) {
      $this->assertStringNotContainsString($absent, $text, "$absent is on no line: the brief speaks dialect, and a place the dialect cannot address is not taught.");
    }
    $this->assertSame([], preg_grep('/Deviations/', $this->prompt()->brief($this->catalog(styled: FALSE, deviations: ['kind:block' => ['color' => ['allowed' => ['red']]]]))), 'With no prose to render them the deviations are on no line.');
  }

  /**
   * A lane the site closed to the AI is said on the MINT line, once.
   *
   * The posture is the provider's sentence (daedalus_stylish's ai_minting
   * on a real site), handed through the roster builder beside the
   * gesture lines: it rides the always-on layer because a mint can be
   * asked on any turn, after the gesture clauses and nowhere else on the
   * brief; an open gate (NULL) leaves the line exactly as testBrief pins.
   */
  public function testClosedMintPostureRidesTheMintLine(): void {
    $this->container->get('daedalus_blueprint.active_design_system')->activate('aurora');
    $posture = 'AI minting is DISABLED on this site (a test setting): never save_material a token; offer the minted values or a literal value instead.';
    TestMaterialsProvider::$mintPosture = $posture;
    $lines = $this->prompt()->brief($this->catalog());

    $this->assertSame(self::MINT_LINE . ' ' . $posture, end($lines), 'The posture closes the MINT line, after the gesture clauses.');
    $this->assertCount(1, array_filter($lines, static fn (string $line): bool => str_contains($line, 'AI minting is DISABLED')), 'The posture is stated once on the brief.');
    $this->assertNotContains($posture, $lines, 'The posture is a clause of the MINT line, not a line of its own.');

    TestMaterialsProvider::$mintPosture = NULL;
    $lines = $this->prompt()->brief($this->catalog());
    $this->assertSame(self::MINT_LINE, end($lines), 'An open gate leaves the MINT line as it was.');
  }

  /**
   * An empty roster keeps the head and the mint line: minting is grammar.
   */
  public function testEmptyRoster(): void {
    TestMaterialsProvider::$records = [];
    $lines = $this->prompt()->brief($this->catalog(styled: FALSE));
    $this->assertSame([
      '## Design system: none active',
      'Law: guarded. Skin: none.',
      MaterialsProse::membershipSentence(NULL) . ' ' . MaterialsProse::preferenceSentence() . ' ' . MaterialsProse::standingProcedureSentence(),
      self::MINT_LINE,
    ], $lines);
  }

  /**
   * Every line of the brief is attributed.
   *
   * A record's, a kind's shared, or the system's.
   *
   * The same brief testBrief pins, read line by line for its kind and
   * its record: a material's own line, an axis value line and a class
   * look are their record's, an owned look its blueprint's (where the
   * schema files it); an axis heading is the variants' shared, a scale
   * heading the tokens', a block heading over one kind that kind's
   * shared (NAMED VALUES, OTHER LOOKS, BLUEPRINTS, ANIMATIONS); the head,
   * an element group's heading, the birth line, the GROUPS index, the
   * dressed tags and MINT are the system's. The weights are those lines
   * counted, each with the newline that follows it, so the records plus
   * the shared plus the system sum to the brief's own length.
   */
  public function testBriefLinesAreAttributed(): void {
    $this->container->get('daedalus_blueprint.active_design_system')->activate('aurora');
    $lines = $this->prompt()->attributed($this->catalog());
    $texts = $lines->texts();

    $this->assertSame([
      '## Design system: Aurora' => NULL,
      'A test design system: cool light on a dark ground.' => NULL,
      $texts[2] => NULL,
      $this->doctrineLine() => NULL,
      '<d-container>:' => NULL,
      '- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the section runs; most sections keep the site column.' => MaterialKind::Variant,
      '  - full "Full width": Let the container span the full page width.' => MaterialKind::Variant,
      '- Born from the palette wearing width="full"; a document states what it wears itself.' => NULL,
      'Every block element:' => NULL,
      '- width:' => MaterialKind::Variant,
      '  - full "Full width": Let the block span its section\'s full width.' => MaterialKind::Variant,
      '<d-text>:' => NULL,
      '- button "Button" (class="button"): Dress the text block\'s link as a solid button.' => MaterialKind::Variant,
      'OTHER LOOKS (worn where their scope fits):' => MaterialKind::Variant,
      '- link_quiet "Quiet link" (class="link_quiet"; scope base:a)' => MaterialKind::Variant,
      self::BLUEPRINTS_HEADER => MaterialKind::Blueprint,
      '- hero "Hero": Single-column hero banner with a heading' => MaterialKind::Blueprint,
      '  - hero_glow "Hero glow" (class="hero_glow")' => MaterialKind::Blueprint,
      self::GROUPS_HEADER => NULL,
      '- ungrouped (1 blueprint)' => NULL,
      'NAMED VALUES (var(--name)):' => MaterialKind::Token,
      '- color:' => MaterialKind::Token,
      '  - --color-accent: #1475ad (dark: #52b3eb)' => MaterialKind::Token,
      '  - --color-lime-green: #4dff8c' => MaterialKind::Token,
      'ANIMATIONS (id "Label" (animation-name: spelling)):' => MaterialKind::Animation,
      '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.' => MaterialKind::Animation,
      'The skin dresses h1, h2, p: pick the tag, the skin sizes it.' => NULL,
      self::MINT_LINE => NULL,
    ], array_combine($texts, $lines->kinds()));
    $this->assertStringStartsWith('Law: strict.', $texts[2]);

    // The record each line belongs to: a material's own line its id, an
    // owned look its blueprint's, a heading over several records none.
    $ids = array_filter(array_combine($texts, $lines->ids()));
    $this->assertSame([
      '  - full "Full width": Let the container span the full page width.' => 'container_full',
      '  - full "Full width": Let the block span its section\'s full width.' => 'block_full',
      '- button "Button" (class="button"): Dress the text block\'s link as a solid button.' => 'button',
      '- link_quiet "Quiet link" (class="link_quiet"; scope base:a)' => 'link_quiet',
      '- hero "Hero": Single-column hero banner with a heading' => 'hero',
      '  - hero_glow "Hero glow" (class="hero_glow")' => 'hero',
      '  - --color-accent: #1475ad (dark: #52b3eb)' => 'color/accent',
      '  - --color-lime-green: #4dff8c' => 'color/lime-green',
      '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.' => 'fade_in',
    ], $ids);
    foreach (array_keys($ids) as $text) {
      $this->assertNotNull($lines->kinds()[array_search($text, $texts, TRUE)], 'A record\'s line has a kind.');
    }

    // The weights are the lines, each with the newline after it (the
    // last line has none): a record's lines its own, a kind's headings
    // its shared, and the three levels sum to the text's own length.
    $weigh = static fn (string ...$lines): int => array_sum(array_map('strlen', $lines)) + count($lines);
    $weights = $lines->weights();
    $this->assertSame($weigh(self::BLUEPRINTS_HEADER, '- hero "Hero": Single-column hero banner with a heading', '  - hero_glow "Hero glow" (class="hero_glow")'), $weights->of(MaterialKind::Blueprint));
    $this->assertSame($weigh('- hero "Hero": Single-column hero banner with a heading', '  - hero_glow "Hero glow" (class="hero_glow")'), $weights->record(MaterialKind::Blueprint, 'hero'));
    $this->assertSame($weigh(self::BLUEPRINTS_HEADER), $weights->shared[MaterialKind::Blueprint->value]);
    $this->assertSame($weigh('ANIMATIONS (id "Label" (animation-name: spelling)):', '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.'), $weights->of(MaterialKind::Animation));
    $this->assertSame($weigh('- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.'), $weights->record(MaterialKind::Animation, 'fade_in'));
    $this->assertSame($weigh('NAMED VALUES (var(--name)):', '- color:', '  - --color-accent: #1475ad (dark: #52b3eb)', '  - --color-lime-green: #4dff8c'), $weights->of(MaterialKind::Token));
    $this->assertSame($weigh('  - --color-accent: #1475ad (dark: #52b3eb)'), $weights->record(MaterialKind::Token, 'color/accent'));
    $this->assertSame($weigh('  - --color-lime-green: #4dff8c'), $weights->record(MaterialKind::Token, 'color/lime-green'));
    $this->assertSame($weigh('NAMED VALUES (var(--name)):', '- color:'), $weights->shared[MaterialKind::Token->value]);
    $this->assertSame(['container_full', 'block_full', 'button', 'link_quiet'], array_keys($weights->records[MaterialKind::Variant->value]));
    $this->assertSame($weigh('- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the section runs; most sections keep the site column.', '- width:', 'OTHER LOOKS (worn where their scope fits):'), $weights->shared[MaterialKind::Variant->value]);
    $this->assertSame(0, $weights->of(MaterialKind::Font));
    $this->assertSame(0, $weights->record(MaterialKind::Font, 'nothing'));
    $this->assertSame(strlen($lines->text()), $weights->total());
    $this->assertSame($weights->total() - array_sum($weights->characters), $weights->system);
    $this->assertSame(strlen($lines->text()), array_sum(array_map('array_sum', $weights->records)) + array_sum($weights->shared) + $weights->system);
    $this->assertSame(implode("\n", $texts), $lines->text());
  }

  /**
   * The section is its attributed lines, and the brief is a weight source.
   *
   * The real service over the real catalog builder: render() is the
   * attributed lines joined (every line of the section has a kind entry,
   * the grammar the system's), weights() sums to render()'s length, and
   * the service is collected by the budget under the tag, so the reading
   * carries the brief's figures beside the schema's. Unsharing the
   * fixture's hero takes its line and its owned look off the blueprint
   * figure while a shared blueprint that is no favorite keeps its line
   * (sharing is the lever, the favorite flag the Add panel's listing),
   * and the BLUEPRINTS heading and the GROUPS index stay; the metadata
   * the weights derive from is answered without a render.
   */
  public function testRenderIsTheWeighedLines(): void {
    $prompt = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt');
    $rendered = $prompt->render();
    $lines = $prompt->lines();
    $this->assertSame($rendered, $lines->text());
    $this->assertSame(explode("\n", $rendered), $lines->texts());
    $this->assertCount(count($lines->texts()), $lines->kinds());
    $this->assertNull($lines->kinds()[0], 'The heading is the system\'s.');
    $weights = $prompt->weights();
    $this->assertSame(strlen($rendered), $weights->total());
    $this->assertSame(strlen($rendered), array_sum(array_map('array_sum', $weights->records)) + array_sum($weights->shared) + $weights->system);
    $this->assertGreaterThan(0, $weights->of(MaterialKind::Blueprint));
    $this->assertGreaterThan(0, $weights->record(MaterialKind::Blueprint, 'hero'));
    $this->assertGreaterThan(0, $weights->system);

    // Collected under the tag: the reading is the schema's figures plus
    // the brief's, kind by kind, record by record.
    $schema = $this->container->get('daedalus_blueprint.materials_schema')->weights();
    $reading = $this->container->get('daedalus_blueprint.materials_budget')->reading();
    foreach (MaterialKind::cases() as $kind) {
      $this->assertSame($schema->of($kind) + $weights->of($kind), $reading->characters[$kind->value], $kind->value);
      $this->assertSame($schema->shared[$kind->value] + $weights->shared[$kind->value], $reading->shared[$kind->value], $kind->value);
    }
    $this->assertSame($schema->record(MaterialKind::Token, 'color/accent') + $weights->record(MaterialKind::Token, 'color/accent'), $reading->record(MaterialKind::Token, 'color/accent'));
    $this->assertSame($schema->record(MaterialKind::Blueprint, 'hero') + $weights->record(MaterialKind::Blueprint, 'hero'), $reading->record(MaterialKind::Blueprint, 'hero'));
    $this->assertSame($schema->system + $weights->system, $reading->system);

    // Unshare the hero (a favorite) and share an opener that is none:
    // the hero's menu line and its owned look leave the blueprint figure,
    // the opener's line is the figure now, and the heading and the index
    // stay (one blueprint in no group, as before).
    TestMaterialsProvider::$records = [
      ...array_map(
        static fn (MaterialRecord $record): MaterialRecord => $record->id === 'hero'
          ? new MaterialRecord(...[...get_object_vars($record), 'offered' => FALSE])
          : $record,
        TestMaterialsProvider::referenceRecords(),
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'opener',
        label: 'Opener',
        description: 'An opening section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'opener'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
      ),
    ];
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();
    $after = $prompt->weights();
    $opener_line = '- opener "Opener": An opening section';
    $this->assertSame(strlen(self::BLUEPRINTS_HEADER) + 1 + strlen($opener_line) + 1, $after->of(MaterialKind::Blueprint));
    $this->assertSame(strlen($opener_line) + 1, $after->record(MaterialKind::Blueprint, 'opener'), 'A shared blueprint that is no favorite weighs its own line.');
    $this->assertSame(0, $after->record(MaterialKind::Blueprint, 'hero'));
    $this->assertStringContainsString(self::BLUEPRINTS_HEADER . "\n" . $opener_line . "\n" . self::GROUPS_HEADER . "\n- ungrouped (1 blueprint)\n", $prompt->render());
    $this->assertSame($weights->of(MaterialKind::Token), $after->of(MaterialKind::Token));
    $this->assertSame($weights->system, $after->system);

    // What the weights derive from, answered without a render.
    $metadata = $prompt->cacheMetadata();
    $this->assertContains('materials_test_list', $metadata->getCacheTags());
    $this->assertContains('entity_bundles', $metadata->getCacheTags());
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
  }

}
