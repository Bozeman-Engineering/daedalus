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
 * element roster places; stencils by root kind, each with the looks it
 * owns beneath it; named values with
 * swatch descriptions; animations; the dressed tags) and one mint line.
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
  protected function catalog(bool $styled = TRUE): DialectCatalog {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return new DialectCatalog(
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
      'STENCILS (id "Label" (root; a page band says top-level only); the schema prints each one in full; the looks beneath a stencil are its own, wearable inside its placements):',
      '- hero "Hero" (section): Single-column hero banner with a heading',
      '  - hero_glow "Hero glow" (class="hero_glow")',
      'NAMED VALUES (var(--name)):',
      '- color: --color-accent: #1475ad (dark: #52b3eb)',
      '- swatch color: --swatch-lime_green "Lime green": #4dff8c',
      'ANIMATIONS (id "Label" (animation-name: spelling)):',
      '- fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent',
      'The skin dresses h1, h2, p: pick the tag, the skin sizes it.',
      'MINT (save_material {kind, label, ...}; never mint a material unless the user asks, and a mint is published site-wide at once): swatch: value; stencil: element.',
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
   * A stencil's line carries its root kind and top-level only, from the record.
   *
   * A swatch's description rides its named-value entry, first sentence,
   * beside the label; the schema keeps the whole text.
   */
  public function testStencilAndSwatchLines(): void {
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'wide_band',
        label: 'Wide band',
        description: 'A full-width band. Edge to edge.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'wide_band'],
        applies: [MaterialRecord::APPLIES_WEARS => ['container_full']],
        rootKind: MaterialRecord::ROOT_SECTION,
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
    $this->assertContains('- hero "Hero" (section): Single-column hero banner with a heading', $lines);
    $this->assertContains('- wide_band "Wide band" (section, top-level only): A full-width band', $lines);
    $this->assertContains('- swatch color: --swatch-lime_green "Lime green": #4dff8c; --swatch-ink "Ink" (Reach for it on body copy over a light ground): #1a1a2e', $lines);
  }

  /**
   * A material opted out of the AI is on no line of the rendered section.
   *
   * Render() reads the catalog's offered view once, so the opt-out
   * reaches every surface that names materials: the element lines' axis
   * values and class rosters, the brief's element groups, OTHER LOOKS,
   * STENCILS, NAMED VALUES and ANIMATIONS, a heading emptied by the
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
      rootKind: $record->rootKind,
      origin: $record->origin,
      offered: FALSE,
    );
    foreach (['swatch:lime_green', 'variant:block_full', 'variant:hero_glow', 'variant:link_quiet', 'animation:fade_in'] as $key) {
      $reference[$key] = $opt_out($reference[$key]);
    }
    TestMaterialsProvider::$records = [
      ...array_values($reference),
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'secret',
        label: 'Secret',
        description: 'For the UI only.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'secret'],
        rootKind: MaterialRecord::ROOT_BLOCK,
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
    $this->assertStringContainsString("OTHER LOOKS (worn where their scope fits):\n- button \"Button\" (class=\"button\"; scope block_type:text): Dress the text block's link as a solid button\nSTENCILS", $prompt);
    $this->assertStringNotContainsString('ANIMATIONS', $prompt);
    $this->assertStringNotContainsString('swatch color', $prompt);
    $this->assertStringContainsString('- <pb-block plugin="plugin_id"/> renders an existing plugin block.', $prompt, 'The block kind\'s only axis value left the element line.');
    $this->assertStringContainsString('- <pb-container width(full; top-level only)>', $prompt);
    $this->assertStringContainsString('- hero "Hero" (section): Single-column hero banner with a heading', $prompt);
    $this->assertStringNotContainsString('  - hero', $prompt, 'The unoffered owned look no longer lists under its stencil.');
    $this->assertStringContainsString('- color: --color-accent: #1475ad (dark: #52b3eb)', $prompt);

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertTrue($roster->has(MaterialKind::Swatch, 'lime_green'), 'The full roster keeps what the prompt leaves out.');
    $this->assertTrue($roster->has(MaterialKind::Stencil, 'secret'));
    $this->assertSame(['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet'], array_keys($roster->variants()));
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
      'MINT (save_material {kind, label, ...}; never mint a material unless the user asks, and a mint is published site-wide at once): swatch: value; stencil: element.',
    ], $lines);
  }

}
