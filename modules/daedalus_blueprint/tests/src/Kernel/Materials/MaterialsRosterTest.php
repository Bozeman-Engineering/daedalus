<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

// cspell:ignore acent buton fadein
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the materials roster: the typed spine every material surface reads.
 *
 * Pins the roster contract a provider and a consumer meet at: typed
 * accessors over the collected records, the spelling index per slot, the
 * one applicability predicate (scope family rule, root-only depth,
 * stencil ownership, band stencils derived from their root's wearings),
 * the one did-you-mean formatter, the record's
 * delivery shape, loud refusal of duplicate ids and spellings, the
 * builder's per-request memo with reset and merged cache metadata, and the
 * partition by design system (global records and the active system's).
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class MaterialsRosterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'daedalus_blueprint_materials_test',
    'daedalus_blueprint_design_system_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
    TestMaterialsProvider::$builds = 0;
  }

  /**
   * The builder service.
   */
  protected function builder(): MaterialsRosterBuilder {
    return $this->container->get('daedalus_blueprint.materials_roster_builder');
  }

  /**
   * Typed accessors serve the collected records by kind, in roster order.
   */
  public function testTypedAccessors(): void {
    $roster = $this->builder()->build();

    $this->assertFalse($roster->isEmpty());
    $this->assertSame(['color/accent'], array_keys($roster->tokens()));
    $this->assertSame(['lime_green'], array_keys($roster->swatches()));
    $this->assertSame(['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet'], array_keys($roster->variants()));
    $this->assertSame(['hero'], array_keys($roster->stencils()));
    $this->assertSame(['fade_in'], array_keys($roster->animations()));
    $this->assertSame($roster->variants(), $roster->ofKind(MaterialKind::Variant));

    // all() reads kind order (the enum's) then provider order.
    $this->assertSame(
      [
        'token:color/accent',
        'swatch:lime_green',
        'variant:button',
        'variant:container_full',
        'variant:block_full',
        'variant:hero_glow',
        'variant:link_quiet',
        'stencil:hero',
        'animation:fade_in',
      ],
      array_map(static fn (MaterialRecord $r): string => $r->kind->value . ':' . $r->id, $roster->all()),
    );

    $swatch = $roster->get(MaterialKind::Swatch, 'lime_green');
    $this->assertInstanceOf(MaterialRecord::class, $swatch);
    $this->assertSame('Lime green', $swatch->label);
    $this->assertSame('#4dff8c', $swatch->value);
    $this->assertSame('color', $swatch->group);
    $this->assertTrue($roster->has(MaterialKind::Stencil, 'hero'));
    $this->assertFalse($roster->has(MaterialKind::Stencil, 'lime_green'));
    $this->assertNull($roster->get(MaterialKind::Token, 'lime_green'), 'Ids are kind-local: a swatch id names no token.');

    // The record's delivery shape: every field, snake_case, always present.
    $full = $roster->get(MaterialKind::Variant, 'container_full');
    $this->assertSame([
      'kind' => 'variant',
      'id' => 'container_full',
      'label' => 'Full width',
      'description' => 'Let the container span the full page width.',
      'value' => NULL,
      'group' => 'width',
      'spellings' => ['attr:kind:container:width' => 'full'],
      'scope' => 'kind:container',
      'applies' => ['depth' => 'root'],
      'owned_by' => NULL,
      'published' => TRUE,
      'weight' => -10,
      'icon' => NULL,
      'root_kind' => NULL,
      'origin' => 'shipped',
      'updatable' => TRUE,
      'design_system' => NULL,
      'offered' => TRUE,
    ], $full->toArray());
    $this->assertTrue($full->rootOnly());
    $this->assertTrue($full->isGlobal());
    $this->assertSame('full', $full->spelling('attr:kind:container:width'));
    $this->assertNull($full->spelling('class'));
    $stencil = $roster->get(MaterialKind::Stencil, 'hero');
    $this->assertSame('section', $stencil->rootKind);
    $this->assertFalse($stencil->updatable);
    $this->assertSame('/modules/x/assets/hero.svg', $stencil->icon);
  }

  /**
   * The spelling index resolves each slot's vocabulary to its record.
   */
  public function testSpellingIndex(): void {
    $roster = $this->builder()->build();

    $this->assertSame('lime_green', $roster->bySpelling(MaterialRecord::SLOT_STYLE, 'var(--swatch-lime_green)')?->id);
    $this->assertSame('color/accent', $roster->bySpelling(MaterialRecord::SLOT_STYLE, 'var(--color-accent)')?->id);
    $this->assertSame('button', $roster->bySpelling(MaterialRecord::SLOT_CLASS, 'button')?->id);
    $this->assertSame('container_full', $roster->bySpelling(MaterialRecord::attrSlot('kind:container', 'width'), 'full')?->id);
    $this->assertSame('block_full', $roster->bySpelling(MaterialRecord::attrSlot('kind:block', 'width'), 'full')?->id, 'The same attribute value on another scope is that scope\'s variant.');
    $this->assertNull($roster->bySpelling('attr:width', 'full'), 'There is no site-wide attribute slot.');
    $this->assertSame('hero', $roster->bySpelling(MaterialRecord::SLOT_STENCIL, 'hero')?->id);
    $this->assertSame('fade_in', $roster->bySpelling(MaterialRecord::SLOT_ANIMATION, 'motion-fade_in')?->id);

    // Slots are separate vocabularies: the stencil "hero" is no class, and
    // an animation's ident is no style-slot value.
    $this->assertNull($roster->bySpelling(MaterialRecord::SLOT_CLASS, 'hero'));
    $this->assertNull($roster->bySpelling(MaterialRecord::SLOT_STYLE, 'motion-fade_in'));
    $this->assertNull($roster->bySpelling(MaterialRecord::SLOT_STYLE, 'var(--swatch-limegreen)'));
    $this->assertSame(['button', 'hero_glow', 'link_quiet'], array_keys($roster->spellings(MaterialRecord::SLOT_CLASS)));
    $this->assertSame([], $roster->spellings('attr:kind:container:nothing'));
    $this->assertSame('width', MaterialRecord::slotAttribute('attr:kind:container:width'));
    $this->assertSame('kind:container', MaterialRecord::slotScope('attr:kind:container:width'));
    $this->assertSame('emphasis', MaterialRecord::slotAttribute('attr:block_type:testimonial:emphasis'));
    $this->assertSame('block_type:testimonial', MaterialRecord::slotScope('attr:block_type:testimonial:emphasis'));
    $this->assertNull(MaterialRecord::slotAttribute('class'));
    $this->assertNull(MaterialRecord::slotScope('class'));
    foreach (['attr:width', 'attr:', 'attr::width', 'attr:kind::width', 'attr:kind:container:'] as $malformed) {
      $this->assertNull(MaterialRecord::slotAttribute($malformed), "$malformed names no attribute.");
      $this->assertNull(MaterialRecord::slotScope($malformed), "$malformed names no scope.");
    }
  }

  /**
   * The scope family rule, as the roster's static predicate.
   */
  #[DataProvider('scopePairs')]
  public function testScopesPair(string $variant_scope, string $element_scope, bool $expected): void {
    $this->assertSame($expected, MaterialsRoster::scopesPair($variant_scope, $element_scope));
  }

  /**
   * Scope pairs and their verdicts.
   */
  public static function scopePairs(): array {
    return [
      'exact' => ['block_type:text', 'block_type:text', TRUE],
      'kind pairs with its family refinement' => ['kind:container', 'layout:layout_onecol', TRUE],
      'refinement pairs with its kind' => ['layout:layout_onecol', 'kind:container', TRUE],
      'two layouts never pair' => ['layout:layout_onecol', 'layout:layout_twocol_section', FALSE],
      'block kind pairs with a block type' => ['kind:block', 'block_type:cta', TRUE],
      'block type pairs with the block kind' => ['block_type:cta', 'kind:block', TRUE],
      'block plugin pairs with the block kind' => ['block_plugin:menu', 'kind:block', TRUE],
      'block type and block plugin never pair' => ['block_type:cta', 'block_plugin:menu', FALSE],
      'families never cross' => ['block_type:cta', 'kind:container', FALSE],
      'container kind never pairs with a block' => ['kind:container', 'kind:block', FALSE],
      'base tag pairs with everything' => ['base:a', 'block_type:cta', TRUE],
      'base tag pairs with a container' => ['base:h2', 'layout:layout_onecol', TRUE],
      'an element scope is never a base wildcard' => ['block_type:cta', 'base:a', FALSE],
      'unknown families pair only exactly' => ['kind:field', 'kind:entity', FALSE],
      'unknown family exact' => ['kind:field', 'kind:field', TRUE],
    ];
  }

  /**
   * The one applicability predicate: scope, depth, ownership, kind.
   */
  public function testApplicable(): void {
    $roster = $this->builder()->build();
    $button = $roster->get(MaterialKind::Variant, 'button');
    $full = $roster->get(MaterialKind::Variant, 'container_full');
    $glow = $roster->get(MaterialKind::Variant, 'hero_glow');
    $quiet = $roster->get(MaterialKind::Variant, 'link_quiet');

    // Scope pairing.
    $this->assertTrue($roster->applicable($button, Address::fromString('block_type:text')));
    $this->assertTrue($roster->applicable($button, Address::fromString('kind:block')));
    $this->assertFalse($roster->applicable($button, Address::fromString('block_type:image')));
    $this->assertFalse($roster->applicable($button, Address::fromString('kind:container')));

    // A root-only variant applies at top level only.
    $this->assertTrue($roster->applicable($full, Address::fromString('layout:layout_onecol'), top_level: TRUE));
    $this->assertFalse($roster->applicable($full, Address::fromString('layout:layout_onecol'), top_level: FALSE));
    $this->assertTrue($roster->applicable($button, Address::fromString('block_type:text'), top_level: FALSE), 'Depth gates root-only variants only.');

    // An owned variant applies only inside its stencil's placements.
    $this->assertFalse($roster->applicable($glow, Address::fromString('kind:container')));
    $this->assertFalse($roster->applicable($glow, Address::fromString('kind:container'), within_stencil: 'call_to_action'));
    $this->assertTrue($roster->applicable($glow, Address::fromString('kind:container'), within_stencil: 'hero'));
    $this->assertTrue($roster->applicable($button, Address::fromString('block_type:text'), within_stencil: 'hero'), 'Unowned variants apply inside placements too.');

    // Base-scoped variants pair with every element scope.
    $this->assertTrue($roster->applicable($quiet, Address::fromString('block_type:text')));
    $this->assertTrue($roster->applicable($quiet, Address::fromString('layout:layout_onecol'), top_level: FALSE));

    // Non-variant kinds apply everywhere; a stencil wearing nothing too.
    $this->assertTrue($roster->applicable($roster->get(MaterialKind::Swatch, 'lime_green'), Address::fromString('block_type:image'), top_level: FALSE));
    $this->assertTrue($roster->applicable($roster->get(MaterialKind::Stencil, 'hero'), Address::fromString('kind:container'), top_level: FALSE));
    $this->assertTrue($roster->applicable($roster->get(MaterialKind::Animation, 'fade_in'), Address::fromString('kind:container'), top_level: FALSE));
    $this->assertFalse($roster->isBand($roster->get(MaterialKind::Stencil, 'hero')));
  }

  /**
   * A stencil is a band when its root wears a root-only variant.
   *
   * The stencil case of the one predicate: the record carries the ids
   * its root captured (a capture fact the provider writes without
   * knowing any axis) and the roster resolves them against ITS variant
   * records, so a band is not applicable nested and applicable at top
   * level, a stencil wearing only style variants applies everywhere, and
   * a wearing that names no record in the roster has no opinion.
   */
  public function testApplicableStencils(): void {
    $stencil = static fn (string $id, array $wears): MaterialRecord => new MaterialRecord(
      kind: MaterialKind::Stencil,
      id: $id,
      label: ucfirst($id),
      spellings: [MaterialRecord::SLOT_STENCIL => $id],
      applies: $wears === [] ? [] : [MaterialRecord::APPLIES_WEARS => $wears],
      rootKind: MaterialRecord::ROOT_SECTION,
    );
    $site = new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [MaterialRecord::attrSlot('kind:container', 'width') => 'site'], scope: Address::fromString('kind:container'), applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT]);
    $card = new MaterialRecord(kind: MaterialKind::Variant, id: 'card', label: 'Card', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'card'], scope: Address::fromString('kind:container'));
    $roster = new MaterialsRoster([
      $site,
      $card,
      $stencil('band', ['card', 'container_site']),
      $stencil('tile', ['card']),
      $stencil('plain', []),
      $stencil('orphan', ['ghost']),
    ], new CacheableMetadata());

    $band = $roster->get(MaterialKind::Stencil, 'band');
    $this->assertSame(['card', 'container_site'], $band->wears());
    $this->assertTrue($roster->isBand($band));
    $this->assertSame(['container_site' => $site], $roster->rootOnlyWears($band));
    $this->assertTrue($roster->applicable($band, Address::fromString('kind:container'), top_level: TRUE));
    $this->assertFalse($roster->applicable($band, Address::fromString('kind:container'), top_level: FALSE));
    $this->assertFalse($roster->applicable($band, Address::fromString('kind:container'), top_level: FALSE, within_stencil: 'band'), 'A placement inside a stencil is nested too.');

    foreach (['tile', 'plain', 'orphan'] as $id) {
      $record = $roster->get(MaterialKind::Stencil, $id);
      $this->assertFalse($roster->isBand($record), "$id is not a band.");
      $this->assertSame([], $roster->rootOnlyWears($record));
      $this->assertTrue($roster->applicable($record, Address::fromString('kind:container'), top_level: TRUE), "$id places at the top level.");
      $this->assertTrue($roster->applicable($record, Address::fromString('kind:container'), top_level: FALSE), "$id places nested.");
    }
    $this->assertSame([], $roster->get(MaterialKind::Stencil, 'plain')->wears());
    $this->assertSame([], $site->wears(), 'Only stencils carry wearings.');
    $this->assertSame([], $roster->rootOnlyWears($site));
  }

  /**
   * The offered view drops what an author opted out; legality keeps it.
   *
   * Offered() is the AI's roster: every material is offered by default,
   * an opted-out one is absent from the view's accessors and spelling
   * index (so no teaching surface can name it), and the full roster still
   * resolves it (the validators' universe is untouched). A roster with
   * nothing opted out answers with itself, so a consumer memoizing on
   * roster identity sees one roster. A band verdict on the view resolves
   * the stencil's wearings against the full roster: opting the worn
   * root-only variant out of the brief does not free the band.
   */
  public function testOfferedView(): void {
    $full = $this->builder()->build();
    $this->assertSame($full, $full->offered(), 'Nothing opted out: the roster is its own offered view.');
    $this->assertTrue($full->get(MaterialKind::Swatch, 'lime_green')->offered, 'Offered by default.');

    $site = new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [MaterialRecord::attrSlot('kind:container', 'width') => 'site'], scope: Address::fromString('kind:container'), applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT], offered: FALSE);
    $card = new MaterialRecord(kind: MaterialKind::Variant, id: 'card', label: 'Card', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'card'], scope: Address::fromString('kind:container'));
    $ink = new MaterialRecord(kind: MaterialKind::Swatch, id: 'ink', label: 'Ink', value: '#1a1a2e', group: 'color', spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-ink)'], offered: FALSE);
    $band = new MaterialRecord(kind: MaterialKind::Stencil, id: 'band', label: 'Band', spellings: [
      MaterialRecord::SLOT_STENCIL => 'band',
    ], applies: [
      MaterialRecord::APPLIES_WEARS => ['card', 'container_site'],
    ], rootKind: MaterialRecord::ROOT_SECTION);
    $secret = new MaterialRecord(kind: MaterialKind::Stencil, id: 'secret', label: 'Secret', spellings: [MaterialRecord::SLOT_STENCIL => 'secret'], rootKind: MaterialRecord::ROOT_BLOCK, offered: FALSE);
    $metadata = (new CacheableMetadata())->addCacheTags(['materials_x']);
    $roster = new MaterialsRoster([$site, $card, $ink, $band, $secret], $metadata);
    $offered = $roster->offered();

    $this->assertNotSame($roster, $offered);
    $this->assertSame($offered, $roster->offered(), 'Built once.');
    $this->assertSame($offered, $offered->offered(), 'The view is its own view.');
    $this->assertSame(['variant:card', 'stencil:band'], array_map(static fn (MaterialRecord $r): string => $r->kind->value . ':' . $r->id, $offered->all()));
    $this->assertSame([], $offered->swatches());
    $this->assertFalse($offered->has(MaterialKind::Variant, 'container_site'));
    $this->assertNull($offered->bySpelling(MaterialRecord::attrSlot('kind:container', 'width'), 'site'));
    $this->assertNull($offered->bySpelling(MaterialRecord::SLOT_STENCIL, 'secret'));
    $this->assertSame([], $offered->candidates(MaterialKind::Swatch, 'ink'), 'A did-you-mean over the view never names an unoffered material.');
    $this->assertSame(['materials_x'], $offered->getCacheableMetadata()->getCacheTags());

    // Legality is the full roster, unchanged.
    $this->assertSame($site, $roster->get(MaterialKind::Variant, 'container_site'));
    $this->assertSame($site, $roster->bySpelling(MaterialRecord::attrSlot('kind:container', 'width'), 'site'));
    $this->assertSame($secret, $roster->bySpelling(MaterialRecord::SLOT_STENCIL, 'secret'));
    $this->assertTrue($roster->applicable($site, Address::fromString('kind:container'), top_level: TRUE));

    // The band verdict rides the full roster on both views.
    $this->assertTrue($roster->isBand($band));
    $this->assertTrue($offered->isBand($band), 'An unoffered root-only wearing still makes the stencil a band.');
    $this->assertSame(['container_site' => $site], $offered->rootOnlyWears($band));
    $this->assertFalse($offered->applicable($band, Address::fromString('kind:container'), top_level: FALSE));
  }

  /**
   * Did-you-mean ranks by id/label similarity and renders one way.
   */
  public function testDidYouMean(): void {
    $roster = $this->builder()->build();

    // A label fragment and a squashed id both find the swatch.
    $this->assertSame(['lime_green'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Swatch, 'limegreen')));
    $this->assertSame(['lime_green'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Swatch, 'Lime')));
    $this->assertSame(
      'Did you mean this swatch: lime_green "Lime green" (var(--swatch-lime_green))?',
      $roster->didYouMean(MaterialKind::Swatch, 'limegreen'),
    );

    // Kinds are separate vocabularies: the swatch is no variant.
    $this->assertSame([], $roster->candidates(MaterialKind::Variant, 'limegreen'));
    $this->assertSame('', $roster->didYouMean(MaterialKind::Variant, 'limegreen'));
    $this->assertSame('', $roster->didYouMean(MaterialKind::Variant, ''));

    // Ranking: the closest first, unrelated names never offered.
    $this->assertSame(['button'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Variant, 'buton')));
    $this->assertSame(
      'Did you mean this variant: button "Button" (class="button"): Dress the text block\'s link as a solid button.?',
      $roster->didYouMean(MaterialKind::Variant, 'buton'),
    );
    $this->assertSame(['container_full', 'block_full'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Variant, 'full')), 'Containment counts as a match on the JSON door (kind-wide: both scopes\' full); unrelated names are never offered.');

    // One formatter: id "Label" (spelling as written): description.
    $this->assertSame(
      'container_full "Full width" (width="full"): Let the container span the full page width.; hero "Hero" (<pb-stencil name="hero">): Single-column hero banner with a heading.',
      MaterialsRoster::describe([
        $roster->get(MaterialKind::Variant, 'container_full'),
        $roster->get(MaterialKind::Stencil, 'hero'),
      ]),
    );
    $this->assertSame(
      'Did you mean this stencil: hero "Hero" (<pb-stencil name="hero">): Single-column hero banner with a heading.?',
      $roster->didYouMean(MaterialKind::Stencil, 'Hro'),
    );
  }

  /**
   * Slot-aware did-you-mean: what CAN be written there, across kinds.
   *
   * A reference that failed in a slot is corrected against every record
   * spellable in that slot, scored over its spellings too, so the custom
   * property a writer named ("--swatch-limegreen") finds the swatch by
   * its var() spelling and a var() typo never gets a class offered.
   */
  public function testDidYouMeanInSlot(): void {
    $roster = $this->builder()->build();
    $ids = static fn (array $records): array => array_map(static fn (MaterialRecord $r): string => $r->id, $records);

    $this->assertSame(['lime_green'], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_STYLE, '--swatch-limegreen')));
    $this->assertSame('Did you mean this swatch: lime_green "Lime green" (var(--swatch-lime_green))?', $roster->didYouMeanInSlot(MaterialRecord::SLOT_STYLE, '--swatch-limegreen'));
    $this->assertSame(['color/accent'], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_STYLE, '--color-acent')));
    $this->assertSame([], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_STYLE, 'button')), 'A class is no style-slot candidate.');
    $this->assertSame(['button'], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_CLASS, 'buton')));
    $this->assertSame(['container_full'], $ids($roster->candidatesInSlot(MaterialRecord::attrSlot('kind:container', 'width'), 'ful')), 'Candidates are the scope\'s own: the block kind\'s full is no container candidate.');
    $this->assertSame(['block_full'], $ids($roster->candidatesInSlot(MaterialRecord::attrSlot('kind:block', 'width'), 'ful')));
    $this->assertSame([], $ids($roster->candidatesInSlot(MaterialRecord::attrSlot('kind:container', 'height'), 'full')), 'An undeclared axis offers nothing.');
    $this->assertSame(['hero'], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_STENCIL, 'hero-band')));
    $this->assertSame(['fade_in'], $ids($roster->candidatesInSlot(MaterialRecord::SLOT_ANIMATION, 'motion-fadein')));
    $this->assertSame('Did you mean this animation: fade_in "Fade in" (animation-name: motion-fade_in): Fade the element in from transparent.?', $roster->didYouMeanInSlot(MaterialRecord::SLOT_ANIMATION, 'motion-fadein'));
    $this->assertSame('', $roster->didYouMeanInSlot(MaterialRecord::SLOT_STENCIL, 'zzz'));

    // Mixed kinds in one slot phrase as materials.
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Token, id: 'color/lime', label: 'lime', spellings: ['style' => 'var(--color-lime)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_green', label: 'Lime green', spellings: ['style' => 'var(--swatch-lime_green)']),
    ];
    $this->builder()->reset();
    $roster = $this->builder()->build();
    $this->assertStringStartsWith('Did you mean one of these materials: ', $roster->didYouMeanInSlot(MaterialRecord::SLOT_STYLE, 'lime'));
  }

  /**
   * Several candidates rank best-first, limited, in the plural phrasing.
   */
  public function testDidYouMeanRanking(): void {
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_light', label: 'Lime light', spellings: ['style' => 'var(--swatch-lime_light)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_green', label: 'Lime green', spellings: ['style' => 'var(--swatch-lime_green)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'brand_red', label: 'Brand red', spellings: ['style' => 'var(--swatch-brand_red)']),
    ];
    $roster = $this->builder()->build();

    // The closer id wins over the earlier one; the unrelated swatch is out.
    $this->assertSame(['lime_green', 'lime_light'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Swatch, 'limegreen')));
    $this->assertSame(
      'Did you mean one of these swatches: lime_green "Lime green" (var(--swatch-lime_green)); lime_light "Lime light" (var(--swatch-lime_light))?',
      $roster->didYouMean(MaterialKind::Swatch, 'limegreen'),
    );
    $this->assertSame(['lime_green'], array_map(static fn (MaterialRecord $r): string => $r->id, $roster->candidates(MaterialKind::Swatch, 'limegreen', 1)));
  }

  /**
   * The builder memoizes per request, resets on demand, merges metadata.
   */
  public function testMemoResetAndCacheMetadata(): void {
    $builder = $this->builder();
    $first = $builder->build();
    $this->assertSame($first, $builder->build(), 'A second build in the same request is the memoized roster.');
    $this->assertSame(1, TestMaterialsProvider::$builds);

    $builder->reset();
    $second = $builder->build();
    $this->assertNotSame($first, $second, 'reset() drops the memo.');
    $this->assertSame(2, TestMaterialsProvider::$builds);

    $metadata = $second->getCacheableMetadata();
    $this->assertContains('materials_test_list', $metadata->getCacheTags());
    $this->assertContains('config:daedalus_blueprint.settings', $metadata->getCacheTags(), 'The partition rides the active design system pointer.');
    $this->assertContains('user.permissions', $metadata->getCacheContexts());
    $this->assertNotContains('workspace', $metadata->getCacheContexts(), 'No workspaces module, no workspace context.');
  }

  /**
   * With Workspaces installed the roster varies by workspace.
   */
  public function testWorkspaceCacheContext(): void {
    $this->enableModules(['path_alias', 'workspaces']);
    $this->installEntitySchema('workspace');
    $this->installEntitySchema('path_alias');
    $this->installSchema('workspaces', ['workspace_association']);

    $roster = $this->builder()->build();
    $this->assertContains('workspace', $roster->getCacheableMetadata()->getCacheContexts());
    $this->assertContains('materials_test_list', $roster->getCacheableMetadata()->getCacheTags());
  }

  /**
   * The roster keeps global records and the active design system's.
   *
   * A provider serves every record it has; the builder partitions once,
   * so every consumer of the roster offers one design system at a time.
   * members() is the unpartitioned read the unit's guards use.
   */
  public function testPartitionByDesignSystem(): void {
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    TestMaterialsProvider::$records = [
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'ink',
        label: 'Ink',
        spellings: ['style' => 'var(--swatch-ink)'],
      ),
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'aurora_glow',
        label: 'Aurora glow',
        spellings: ['style' => 'var(--swatch-aurora_glow)'],
        designSystem: 'aurora',
      ),
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'borealis_glow',
        label: 'Borealis glow',
        spellings: ['style' => 'var(--swatch-borealis_glow)'],
        designSystem: 'borealis',
      ),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'band',
        label: 'Band',
        spellings: ['class' => 'band'],
        scope: Address::fromString('kind:container'),
        designSystem: 'aurora',
      ),
    ];
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);

    // No active system: global records only.
    $roster = $this->builder()->build();
    $this->assertSame(['ink'], array_keys($roster->swatches()));
    $this->assertSame([], array_keys($roster->variants()));
    $this->assertContains('config:daedalus_blueprint.settings', $roster->getCacheableMetadata()->getCacheTags());

    // Aurora active: its members join the global ones; borealis's stay out.
    $active->activate('aurora');
    $roster = $this->builder()->build();
    $this->assertSame(['ink', 'aurora_glow'], array_keys($roster->swatches()));
    $this->assertSame(['band'], array_keys($roster->variants()));
    $glow = $roster->get(MaterialKind::Swatch, 'aurora_glow');
    $this->assertSame('aurora', $glow->designSystem);
    $this->assertFalse($glow->isGlobal());
    $this->assertSame('aurora', $glow->toArray()['design_system']);
    $this->assertNull($roster->bySpelling(MaterialRecord::SLOT_STYLE, 'var(--swatch-borealis_glow)'), 'An inactive system\'s spelling is not on the index.');

    // Switching drops the memo and repartitions.
    $active->activate('borealis');
    $roster = $this->builder()->build();
    $this->assertSame(['ink', 'borealis_glow'], array_keys($roster->swatches()));
    $this->assertSame([], array_keys($roster->variants()));

    // The unpartitioned read sees the inactive system's members.
    $this->assertSame(['aurora_glow', 'band'], array_map(static fn (MaterialRecord $r): string => $r->id, $this->builder()->members('aurora')));

    // A system's own partition (the validators' member universe): the
    // global records plus that system's, whichever is active; memoized
    // beside the active roster and dropped by the same reset.
    $aurora = $this->builder()->buildFor('aurora');
    $this->assertSame(['ink', 'aurora_glow'], array_keys($aurora->swatches()));
    $this->assertSame(['band'], array_keys($aurora->variants()));
    $this->assertSame($aurora, $this->builder()->buildFor('aurora'));
    $this->assertNotSame($aurora, $this->builder()->build());
    $this->assertSame(['ink', 'borealis_glow'], array_keys($this->builder()->buildFor('borealis')->swatches()));
    $this->builder()->reset();
    $this->assertNotSame($aurora, $this->builder()->buildFor('aurora'));
    $this->assertSame([], $this->builder()->members('nothing'));

    // The empty string is no design system: an id or NULL, loudly.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The design system of swatch "x" is an id or NULL (global), never the empty string.');
    new MaterialRecord(kind: MaterialKind::Swatch, id: 'x', label: 'X', designSystem: '');
  }

  /**
   * Two records of one kind sharing an id are refused.
   */
  public function testDuplicateIdRefused(): void {
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_green', label: 'Lime green', spellings: ['style' => 'var(--swatch-lime_green)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_green', label: 'Lime green again', spellings: ['style' => 'var(--swatch-lime_green2)']),
    ];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Two swatch records share the id "lime_green".');
    $this->builder()->build();
  }

  /**
   * Two records spelling the same thing in one slot are refused.
   */
  public function testDuplicateSpellingRefused(): void {
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Token, id: 'color/swatch-x', label: 'swatch-x', spellings: ['style' => 'var(--swatch-x)']),
      new MaterialRecord(kind: MaterialKind::Swatch, id: 'x', label: 'X', spellings: ['style' => 'var(--swatch-x)']),
    ];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Token "color/swatch-x" and swatch "x" both spell "var(--swatch-x)" in the style slot.');
    $this->builder()->build();
  }

  /**
   * An axis attribute value partitions per scope: two scopes may share it.
   *
   * Block_fade_in and container_fade_in both spelling animation="fade_in"
   * is no collision: the slot carries the scope, so the two are two
   * spellings and the roster builds; the same scope spelling one value
   * twice is still refused, in that scope's slot.
   */
  public function testAttributeSpellingsPartitionByScope(): void {
    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'block_fade_in', label: 'Fade in', group: 'animation', spellings: [MaterialRecord::attrSlot('kind:block', 'animation') => 'fade_in'], scope: Address::fromString('kind:block')),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_fade_in', label: 'Fade in', group: 'animation', spellings: [MaterialRecord::attrSlot('kind:container', 'animation') => 'fade_in'], scope: Address::fromString('kind:container')),
    ];
    $roster = $this->builder()->build();
    $this->assertSame('block_fade_in', $roster->bySpelling('attr:kind:block:animation', 'fade_in')?->id);
    $this->assertSame('container_fade_in', $roster->bySpelling('attr:kind:container:animation', 'fade_in')?->id);
    $this->assertSame(['fade_in'], array_keys($roster->spellings('attr:kind:block:animation')));

    TestMaterialsProvider::$records = [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'block_fade_in', label: 'Fade in', group: 'animation', spellings: [MaterialRecord::attrSlot('kind:block', 'animation') => 'fade_in'], scope: Address::fromString('kind:block')),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'block_fade', label: 'Fade', group: 'animation', spellings: [MaterialRecord::attrSlot('kind:block', 'animation') => 'fade_in'], scope: Address::fromString('kind:block')),
    ];
    $this->builder()->reset();
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Variant "block_fade_in" and variant "block_fade" both spell "fade_in" in the attr:kind:block:animation slot.');
    $this->builder()->build();
  }

  /**
   * An attribute slot must name the record's own scope, well formed.
   *
   * The one validation path of the partition: the record's scope is the
   * truth and the slot restates it, so a provider cannot spell a variant
   * into another scope's attribute, and the unscoped attr:{name} of the
   * site-wide slot is refused rather than indexed where no consumer asks.
   */
  public function testAttributeSlotNamesTheRecordScope(): void {
    $refused = [
      'another scope' => [
        [MaterialRecord::attrSlot('kind:container', 'width') => 'full'],
        'kind:block',
        'names scope "kind:container", but the record\'s scope is "kind:block"',
      ],
      'no scope' => [
        [MaterialRecord::attrSlot('kind:container', 'width') => 'full'],
        NULL,
        'but the record\'s scope is unset',
      ],
      'unscoped' => [
        ['attr:width' => 'full'],
        'kind:container',
        'is malformed; an axis attribute slot is attr:{scope}:{attribute}',
      ],
      'empty attribute' => [['attr:kind:container:' => 'full'], 'kind:container', 'is malformed'],
    ];
    foreach ($refused as $case => [$spellings, $scope, $message]) {
      try {
        new MaterialRecord(kind: MaterialKind::Variant, id: 'x', label: 'X', spellings: $spellings, scope: $scope === NULL ? NULL : Address::fromString($scope));
        $this->fail("$case was accepted.");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
      }
    }
  }

  /**
   * A record refuses an unknown origin and empty spellings.
   */
  public function testRecordGuards(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown material origin "robot"');
    new MaterialRecord(kind: MaterialKind::Swatch, id: 'x', label: 'X', origin: 'robot');
  }

}
