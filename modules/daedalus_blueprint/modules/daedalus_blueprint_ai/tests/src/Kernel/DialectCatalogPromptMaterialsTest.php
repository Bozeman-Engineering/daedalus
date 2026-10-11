<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Snapshot of the cached prompt's design system brief over the fixture roster.
 *
 * The brief is the styling half of the page-language section: the
 * active design system by name with its identity, law and skin, the
 * membership, preference and stack sentences, then the roster in the
 * system's structure (per element kind its axes with description, owns
 * and value sentences, its class looks and its birth line; the looks no
 * element roster places; the favorite block blueprints, each with the
 * looks it owns beneath it, and the group index of every offered one;
 * named values with swatch descriptions; animations; the dressed tags)
 * and one mint line.
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
   * The BLUEPRINTS header: the site favorites, then the index.
   */
  protected const BLUEPRINTS_HEADER = 'BLUEPRINTS (the site favorites; id "Label" (a page band says top-level only; a saved one names the entity_id page_read and page_edit take as entity_type blueprint, a shipped one is read-only); the schema prints each in full; the looks beneath a blueprint are its own, wearable inside its placements):';

  /**
   * The GROUPS header: the index's shape and how a group is read in full.
   */
  protected const GROUPS_HEADER = 'GROUPS (every offered blueprint, favorites included, by group: id "Label" (how many): what the group is for; ungrouped holds the ones in no group; eb_read_schema with group: id prints a group in full in place of the favorites):';

  /**
   * The MINT line over the fixture's two gesture lines.
   */
  protected const MINT_LINE = 'MINT (save_material {kind, label, ...}; never mint a material unless the user asks, and a mint is published site-wide at once): swatch: value; blueprint: element.';

  /**
   * {@inheritdoc}
   *
   * 'key' is an ai dependency.
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'daedalus_blueprint',
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
    ) extends DialectCatalogPrompt {

      /**
       * The brief's lines for a catalog.
       */
      public function brief(DialectCatalog $catalog): array {
        return $this->briefLines($catalog);
      }

    };
  }

  /**
   * A catalog over the live roster with hand-built styling contributions.
   *
   * What daedalus_stylish would contribute on a real site: the container's width
   * axis with a description and the properties it owns, the block kind's
   * bare width axis, a text block type (so the fixture's button look is
   * placed on <pb-text>), the dark condition, the strict prose, the
   * container layout's birth default, the dressed tags and the skin.
   */
  protected function catalog(bool $styled = TRUE, array $groups = []): DialectCatalog {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return new DialectCatalog(
      groups: $groups,
      containerLayoutId: 'layout_onecol',
      axes: [
        'kind:container' => [
          'width' => [
            'description' => 'How wide the band runs; most bands keep the site column.',
            'owns' => ['padding-left', 'padding-right'],
          ],
        ],
        'kind:block' => ['width' => []],
      ],
      blockTypes: ['text' => ['attributes' => ['tag' => ['field' => 'field_tag', 'values' => ['h1', 'h2', 'p']]]]],
      conditions: ['dark' => '@media (prefers-color-scheme: dark)'],
      roster: $builder->build(),
      prose: $styled ? new VocabularyProse('strict') : NULL,
      birthDefaults: $styled ? ['layout:layout_onecol' => ['width' => 'container_full']] : [],
      dressedTags: $styled ? ['h1', 'h2', 'p'] : [],
      skin: $styled ? 'Aurora' : NULL,
    );
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
      'The materials below are Aurora\'s members plus the global ones (members of no system, offered under every system); a save_material mint joins Aurora unless it passes "global": true. ' . MaterialsProse::preferenceSentence() . ' ' . $strict->stackSentence(),
      '<pb-container>:',
      '- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the band runs; most bands keep the site column. full "Full width": Let the container span the full page width',
      '- Born from the palette wearing width="full"; a document states what it wears itself.',
      'Every block element:',
      '- width: full "Full width": Let the block span its section\'s full width',
      '<pb-text>:',
      '- button "Button" (class="button"): Dress the text block\'s link as a solid button',
      'OTHER LOOKS (worn where their scope fits):',
      '- link_quiet "Quiet link" (class="link_quiet"; scope base:a)',
      self::BLUEPRINTS_HEADER,
      '- hero "Hero": Single-column hero banner with a heading',
      '  - hero_glow "Hero glow" (class="hero_glow")',
      self::GROUPS_HEADER,
      '- ungrouped (1 blueprint)',
      'NAMED VALUES (var(--name)):',
      '- color: --color-accent: #1475ad (dark: #52b3eb)',
      '- swatch color: --swatch-lime_green "Lime green": #4dff8c',
      'ANIMATIONS (id "Label" (animation-name: spelling)):',
      '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent',
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
    $this->assertSame(MaterialsProse::membershipSentence(NULL) . ' ' . MaterialsProse::preferenceSentence(), $lines[2]);
    $this->assertStringStartsWith('No design system is active', $lines[2]);
    $this->assertContains('- width (top-level only; owns padding-left, padding-right: wear the axis, never write them): How wide the band runs; most bands keep the site column. full "Full width": Let the container span the full page width', $lines);
    $this->assertNotContains('- Born from the palette wearing width="full"; a document states what it wears itself.', $lines);
    $this->assertSame([], preg_grep('/^The skin dresses/', $lines));
  }

  /**
   * A blueprint's line carries top-level only and its page id; block scope only.
   *
   * The band qualifier comes from the record; a saved blueprint's line
   * names the entity_id the page tools address it by, a shipped one's (no
   * entity id) does not. The block prints block scope alone, so no line
   * explains a scope: a page blueprint is a start, listed by a bundle's
   * schema read under starts, and is on no line of the cached prefix
   * (which has no bundle dimension), its description included. A
   * swatch's description rides its named-value entry, first sentence,
   * beside the label; the schema keeps the whole text.
   */
  public function testBlueprintAndSwatchLines(): void {
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
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band. Edge to edge.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_band'],
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
        kind: MaterialKind::Swatch,
        id: 'ink',
        label: 'Ink',
        description: 'Reach for it on body copy over a light ground. Never on buttons.',
        value: '#1a1a2e',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-ink)'],
      ),
    ];
    $lines = $this->prompt()->brief($this->catalog());
    $this->assertContains('- hero "Hero": Single-column hero banner with a heading', $lines);
    $this->assertContains('- wide_band "Wide band" (top-level only): A full-width band', $lines);
    $this->assertContains('- saved_card "Saved card" (entity_id 7): A card saved on this site', $lines);
    $this->assertContains('- ungrouped (3 blueprints)', $lines, 'The index counts block scope alone: the page blueprint is no entry.');
    $this->assertStringNotContainsString('landing', implode("\n", $lines), 'A page blueprint is on no line of the prefix.');
    $this->assertStringNotContainsString('(block', implode("\n", $lines), 'No line explains a scope.');
    $this->assertContains('- swatch color: --swatch-lime_green "Lime green": #4dff8c; --swatch-ink "Ink" (Reach for it on body copy over a light ground): #1a1a2e', $lines);
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
    foreach (['swatch:lime_green', 'variant:block_full', 'variant:hero_glow', 'variant:link_quiet', 'animation:fade_in'] as $key) {
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
      'lime_green',
      'Lime green',
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
    $this->assertStringContainsString("OTHER LOOKS (worn where their scope fits):\n- button \"Button\" (class=\"button\"; scope block_type:text): Dress the text block's link as a solid button\nBLUEPRINTS", $prompt);
    $this->assertStringNotContainsString('ANIMATIONS', $prompt);
    $this->assertStringNotContainsString('swatch color', $prompt);
    $this->assertStringContainsString('<pb-block plugin="plugin_id"/> renders an existing plugin block.', $prompt, 'The block kind\'s only axis value left the element line.');
    $this->assertStringNotContainsString('emphasis', $prompt, 'The block kind\'s only axis value left the element line.');
    $this->assertStringContainsString('- <pb-container width(full; top-level only)>', $prompt);
    $this->assertStringContainsString('- hero "Hero": Single-column hero banner with a heading', $prompt);
    $this->assertStringNotContainsString('  - hero', $prompt, 'The unoffered owned look no longer lists under its blueprint.');
    $this->assertStringContainsString("\n- ungrouped (1 blueprint)\n", $prompt, 'The unoffered blueprint is not counted.');
    $this->assertStringContainsString('- color: --color-accent: #1475ad (dark: #52b3eb)', $prompt);

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertTrue($roster->has(MaterialKind::Swatch, 'lime_green'), 'The full roster keeps what the prompt leaves out.');
    $this->assertTrue($roster->has(MaterialKind::Blueprint, 'secret'));
    $this->assertSame(['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet'], array_keys($roster->variants()));
  }

  /**
   * N block blueprints filed under bands, none a favorite.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records.
   */
  protected function groupedBlueprints(int $count): array {
    $records = [];
    for ($i = 1; $i <= $count; $i++) {
      $records[] = new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'band_' . $i,
        label: 'Band ' . $i,
        description: 'Band number ' . $i . '. Reach for it on page ' . $i . '.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'band_' . $i],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['bands'],
        entityId: (string) $i,
      );
    }
    return $records;
  }

  /**
   * The favorites print in full; everything offered is indexed by group.
   *
   * The lines are the site favorites alone (the fixture's hero and the
   * opener), each with its owned looks as before; a non-favorite is on no
   * line of its own. The index that follows is in the neighbours' shape:
   * one line per declared group that holds an offered block blueprint,
   * in declaration order, with its count and its description's first
   * sentence (a group holding nothing prints no line), then the reserved
   * bucket counting the offered ones in no declared group (an undeclared
   * id counts nowhere). A page blueprint is a start and is neither a line
   * nor a count, favorite or not.
   */
  public function testFavoritesAndTheIndex(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'opener',
        label: 'Opener',
        description: 'An opening band. Reach for it first.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'opener'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['heroes'],
        favorite: TRUE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'wide_band'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
        groups: ['bands', 'heroes'],
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
      'bands' => ['label' => 'Bands', 'description' => ''],
      'cards' => ['label' => 'Cards', 'description' => 'Never held here.'],
    ]));
    $start = array_search(self::BLUEPRINTS_HEADER, $lines, TRUE);
    $this->assertIsInt($start);
    $this->assertSame([
      self::BLUEPRINTS_HEADER,
      '- hero "Hero": Single-column hero banner with a heading',
      '  - hero_glow "Hero glow" (class="hero_glow")',
      '- opener "Opener": An opening band',
      self::GROUPS_HEADER,
      '- heroes "Heroes" (2 blueprints): Full-width openers',
      '- bands "Bands" (1 blueprint)',
      '- ungrouped (2 blueprints)',
      'NAMED VALUES (var(--name)):',
    ], array_slice($lines, $start, 9));
    $text = implode("\n", $lines);
    foreach (['wide_band', 'stray', 'landing', 'cards', 'Never held here'] as $absent) {
      $this->assertStringNotContainsString($absent, $text, "$absent is on no line of the prefix.");
    }
  }

  /**
   * Flat: the block's size follows the favorites and the vocabulary, never the library.
   *
   * Fifty grouped block blueprints and no favorite print the same block
   * as five, apart from the counts: the header, the index header, one
   * line for the group, and no line per blueprint. That is the premise
   * the index exists for (one line per blueprint is affordable for a
   * short list and not for a hundred).
   */
  public function testFlatness(): void {
    $block = function (int $count): array {
      TestMaterialsProvider::$records = $this->groupedBlueprints($count);
      $lines = $this->prompt()->brief($this->catalog(styled: FALSE, groups: [
        'bands' => ['label' => 'Bands', 'description' => 'Horizontal bands.'],
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
      self::GROUPS_HEADER,
      '- bands "Bands" (5 blueprints): Horizontal bands',
    ], $five);
    $this->assertSame('- bands "Bands" (50 blueprints): Horizontal bands', $fifty[2]);
    $normalize = static fn (array $lines): array => preg_replace('/\(\d+ blueprints?\)/', '(N)', $lines);
    $this->assertSame($normalize($five), $normalize($fifty), 'The block is the same size apart from the counts.');
    $this->assertSame(strlen(implode("\n", $normalize($five))), strlen(implode("\n", $normalize($fifty))));
  }

  /**
   * A token's description rides the named-value line as its first sentence.
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
    $lines = $this->prompt()->brief($this->catalog());
    $this->assertContains('- color: --color-ink (The reading color): #1a1a2e', $lines, 'The token line carries the custom property, the first sentence and the value.');
    $this->assertStringNotContainsString('never on a ground', implode("\n", $lines), 'Only the first sentence rides the prefix; the schema keeps the rest.');
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
      MaterialsProse::membershipSentence(NULL) . ' ' . MaterialsProse::preferenceSentence(),
      self::MINT_LINE,
    ], $lines);
  }

}
