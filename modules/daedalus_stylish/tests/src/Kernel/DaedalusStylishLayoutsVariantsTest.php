<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\daedalus_stylish_layouts\ContainerMigration;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_stylish_layouts shipped styling: axes, variants, defaults.
 *
 * The container is ONE box, so both axes style the container ROOT (''):
 * - container_layout: mode + share. ONE count-independent row variant
 *   (flex on the root, the skin's gutter gap, stacking on the "narrow"
 *   width-band condition (the page-level band, measured at the one page
 *   query root), equal fill on the '::>' children pseudo-part; ratios are
 *   per-child flex-grow overrides).
 * - width: the constrained content width as a padding-inline stop
 *   (`max(gutter, calc((100% - W) / 2))`) on the root itself — the band
 *   stays full-bleed, the content column centers, and a gutter comes free
 *   below the max. The default is the token-referencing Site width
 *   (--layout-max-width and --space-gutter), so page bands, header and
 *   footer share one number; the px stops are explicit overrides.
 * - animation: the entrance family (container_fade_in and friends), the
 *   block family's twin; ShippedMotionTest pins its face beside the block
 *   half, this file pins that the axis reaches the container.
 * All families are scoped to the container KIND: with one layout, these
 * are container-wide facts. The count-keyed proportions family (split_*)
 * retired with the mode + share migration.
 *
 * Importing the real MODULE.daedalus_stylish.variants.yml against the real layout
 * declarations exercises the whole chain: guide build (variant_axes +
 * default_variants validation), the scope-only styles seam, and the axis
 * vocabulary check in DaedalusStylishVariant::preSave().
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishLayoutsVariantsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    // The container entrance variants reference motion-* names, which a
    // variant save mirrors into the animation usage table.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // The importer switches to an administrative account (shipped variants
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
    \Drupal::service('module_installer')->install(['daedalus_stylish_layouts']);
  }

  /**
   * Loads a variant by machine name, bypassing access.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariantInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * The container layout declares its axes and the shipped defaults.
   *
   * Its axes and one-box root come from the UNIVERSAL container kind
   * declaration (daedalus_stylish_layouts.page_builder.yml), its defaults
   * reference the kind-scoped families. The container_layout axis has no
   * birth default: a container at any depth is born stacked, and Columns
   * (the row variant) is the deliberate gesture.
   */
  public function testLayoutAxesAndDefaults(): void {
    $guide = \Drupal::service('daedalus_stylish.style_guide');
    $declaration = $guide->getLayout('daedalus_stylish_container');
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], StyleGuide::axes($declaration));
    $this->assertSame(['width' => 'container_site'], $declaration['default_variants'] ?? []);
    // Both depth projections carry only the width entry (which the
    // root-only AXIS then skips on nested placement); no row at birth.
    $this->assertSame(['width' => 'container_site'], StyleGuide::defaultVariants($declaration));
    $this->assertSame(['width' => 'container_site'], StyleGuide::defaultVariants($declaration, TRUE));
    // The declared defaults name shipped variants on their own axes,
    // scoped to the container kind so they match any container.
    $variant = $this->loadVariant('container_site');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertSame('width', $variant->getAxis());
    $this->assertSame('kind:container', $variant->getScope());
    $row = $this->loadVariant('row');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $row);
    $this->assertSame('container_layout', $row->getAxis());
    $this->assertSame('kind:container', $row->getScope());
    // The container ROOT backs every axis's properties: layout for the
    // arrangement variant (display, gap), sizing and spacing for the
    // width variants, motion for the entrances, effects and shadow for
    // the hover axis's Lift — the one box carries every group the widgets
    // and the shipped materials write.
    $groups = $declaration['property_groups'] ?? [];
    foreach (['layout', 'sizing', 'spacing', 'background', 'motion', 'effects', 'shadow'] as $group) {
      $this->assertArrayHasKey($group, $groups, "container root lacks $group");
    }
    // The universal container kind itself carries the shared shape: the
    // composed one-box root (forms, no parts) and the '::>' children
    // addressable, so the kind-scoped variants validate against it.
    $kind = $guide->getKind('container');
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], StyleGuide::axes($kind));
    $this->assertSame(['width'], StyleGuide::rootOnlyAxes($kind));
    $this->assertArrayNotHasKey('elements', $kind);
    $this->assertTrue($kind['composed'] ?? FALSE);
    $this->assertSame(
      ['section', 'div', 'section', 'div'],
      array_column($kind['root']['forms'] ?? [], 'tag'),
    );
    $this->assertArrayHasKey('property_groups', $kind['children'] ?? []);
  }

  /**
   * The proportions family is retired: no split_* variants ship or sync.
   *
   * Mode + share moved the ratio fact onto the children (flex-grow under
   * the row arrangement), so the count-keyed split_* track variants are
   * gone from disk and the proportions axis is off the container
   * declaration - a variant claiming it can no longer save.
   */
  public function testProportionsFamilyRetired(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $split = $storage->loadByProperties(['machine_name' => array_keys(ContainerMigration::SPLIT_GROWS)]);
    $this->assertSame([], $split, 'No split_* variant imports.');
    $proportions = array_filter(
      $storage->loadMultiple(),
      fn (DaedalusStylishVariant $variant) => $variant->getAxis() === 'proportions',
    );
    $this->assertSame([], $proportions, 'No variant lives on the retired proportions axis.');
  }

  /**
   * The container_layout axis ships ONE count-independent row variant.
   *
   * Mode + share: the row variant carries everything shared about columns
   * (flex on the container root, the gutter gap, narrow stacking,
   * equal fill on the root's direct children via the '::>' children
   * pseudo-part), so equal columns at every count are one worn reference
   * with zero per-instance CSS, and a child added later joins at grow 1
   * purely by CSS. Unequal ratios are per-child flex-grow overrides
   * written by the layout widget; no track string exists anywhere, so
   * nothing goes stale as children come and go. Authored on the ROOT: the
   * container is one box at every depth, so the selected element IS the
   * flex parent.
   */
  public function testRowVariant(): void {
    $variant = $this->loadVariant('row');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertSame('container_layout', $variant->getAxis());
    $this->assertSame('kind:container', $variant->getScope());
    $styles = $variant->getStyles();
    $this->assertSame(['', '::>'], array_keys($styles));
    $root = $styles[''];
    $this->assertSame('flex', $root['default']['display']);
    $this->assertSame('var(--space-gutter)', $root['default']['gap']);
    // Desktop-first stacking on the "narrow" width band (the page-level
    // band: rows stack when the page is narrow, like a media query).
    $this->assertSame(['flex-direction' => 'column'], $root['narrow']);
    // Equal fill on the children pseudo-part, spelled as the flex
    // longhands (the curated Layout group ships no `flex` shorthand):
    // `flex: 1 1 0` plus the min-width guard so content cannot force a
    // column wider than its share.
    $children = $styles['::>'];
    $this->assertSame([
      'flex-grow' => '1',
      'flex-shrink' => '1',
      'flex-basis' => '0',
      'min-width' => '0',
    ], $children['default']);
    // Stacked children opt out of flexing entirely (`flex: none` as
    // longhands): grows must never act on stacked heights.
    $this->assertSame([
      'flex-grow' => '0',
      'flex-shrink' => '0',
      'flex-basis' => 'auto',
    ], $children['narrow']);
    // Mode + share's no-stale-facts rule: no track string anywhere, and
    // the arrangement axis never claims the content width (that is the
    // width axis's fact).
    foreach ($styles as $key => $entry) {
      foreach ($entry as $condition => $properties) {
        $this->assertArrayNotHasKey('grid-template-columns', $properties, "$key $condition");
        $this->assertArrayNotHasKey('max-width', $properties, "$key $condition");
        $this->assertArrayNotHasKey('padding-inline', $properties, "$key $condition");
      }
    }
  }

  /**
   * The shipped row variant compiles into the shared stylesheet.
   *
   * The install-time import is the compile trigger, so this locks the
   * container_layout axis's whole live delivery chain: the root rule for
   * the flex facts (':scope' at the variant-class scope roots), the '::>'
   * children rule with the child-shifted donut, the narrow blocks in the
   * container-query wrapper, and per-child overrides beating equal fill
   * on scope proximity (the children rule stays at ':scope' weight).
   */
  public function testRowVariantCompiles(): void {
    $uri = \Drupal::state()->get(VariantStylesheet::STATE_KEY);
    $this->assertIsString($uri);
    $css = file_get_contents($uri);
    $variant = $this->loadVariant('row');
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--row, .daedalus-stylish-variant--{$variant->uuid()})", $css);
    // The root's own rule: the flex mode and the gutter gap, printed as
    // the token reference (a strict law binding gap to the space scale
    // accepts the minted reference verbatim; a literal it would withhold).
    $this->assertStringContainsString('display: flex;', $css);
    $this->assertStringContainsString('gap: var(--space-gutter);', $css);
    $this->assertStringContainsString('flex-direction: column;', $css);
    // The children rule: ':scope > *' with the child-shifted donut, equal
    // fill.
    $this->assertStringContainsString(':scope > * {', $css);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *)', $css);
    $this->assertStringContainsString('flex-grow: 1;', $css);
    $this->assertStringContainsString('min-width: 0;', $css);
    // Narrow: stacked children opt out of flexing (`flex: none`).
    $this->assertStringContainsString('flex-basis: auto;', $css);
  }

  /**
   * Width variants ship at every stop as padding-inline on the root.
   */
  public function testWidthVariantsPerLayout(): void {
    $stops = ['site', 'sm', 'md', 'lg', 'xl', '2xl', 'full'];
    // The kind-scoped width family serves any container with these stops.
    foreach ($stops as $stop) {
      $variant = $this->loadVariant("container_{$stop}");
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, "container_{$stop}");
      $this->assertSame('width', $variant->getAxis());
      $this->assertSame('kind:container', $variant->getScope());
      $properties = $variant->getStyles()['']['default'];
      $this->assertArrayHasKey('padding-inline', $properties);
      // One box: width is an inset stop, never a second element's cap or
      // a display fact.
      $this->assertArrayNotHasKey('max-width', $properties);
      $this->assertArrayNotHasKey('display', $properties);
      $this->assertArrayNotHasKey('grid-template-columns', $properties);
      // Every stop keeps the gutter floor.
      $this->assertStringContainsString('var(--space-gutter)', $properties['padding-inline']);
    }
    // Site width is the token stop: the skin's one content-width number.
    $site = $this->loadVariant('container_site')->getStyles()['']['default']['padding-inline'];
    $this->assertStringContainsString('var(--layout-max-width)', $site);
    // Full width keeps only the gutter inset.
    $this->assertSame('var(--space-gutter)', $this->loadVariant('container_full')->getStyles()['']['default']['padding-inline']);
  }

  /**
   * The shipped width family's roster order is widest first.
   *
   * The width variants ship sort weights (rosters sort weight-then-label,
   * lighter first) ordering the family Full width, Site width, then the
   * px stops down to Small: the size scale, not the alphabetical accident
   * of the labels. The client store and the Materials list preserve the
   * roster's order, so the roster chokepoint IS the browser-visible order.
   */
  public function testWidthFamilyRosterOrder(): void {
    $weights = [
      'container_full' => 10,
      'container_site' => 15,
      'container_2xl' => 20,
      'container_xl' => 30,
      'container_lg' => 40,
      'container_md' => 50,
      'container_sm' => 60,
    ];
    foreach ($weights as $machine_name => $weight) {
      $this->assertSame($weight, $this->loadVariant($machine_name)->getWeight(), $machine_name);
    }
    $roster = array_keys(array_filter(
      \Drupal::service('daedalus_blueprint.materials_roster_builder')->build()->variants(),
      static fn (MaterialRecord $record): bool => $record->group === 'width',
    ));
    $this->assertSame(array_keys($weights), $roster);
  }

  /**
   * Stored variants move from the retired arrangement axis, row relabels.
   *
   * The sync lane cannot deliver the rename (the source hash covers styles
   * only, so a label/axis-only disk change reads UNCHANGED), so the
   * post_update migration moves every stored arrangement-axis variant to
   * container_layout and relabels the shipped row "Columns" — but only
   * where the label still IS "Row", so a user rename survives. Fixtures
   * write the stale axis straight to the base table: preSave() validates
   * against the CURRENT vocabulary, exactly why stale data can only exist
   * as pre-rename rows.
   */
  public function testContainerLayoutAxisMigration(): void {
    // A user variant on the axis, renamed by its owner.
    $mine = DaedalusStylishVariant::create([
      'label' => 'My columns',
      'machine_name' => 'my_columns',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
      'styles' => ['' => ['default' => ['gap' => '8px']]],
    ]);
    $mine->save();

    // Devolve both to the pre-rename state, bypassing preSave().
    $database = \Drupal::database();
    $database->update('daedalus_variant')
      ->fields(['axis' => 'arrangement'])
      ->condition('machine_name', ['row', 'my_columns'], 'IN')
      ->execute();
    $database->update('daedalus_variant')
      ->fields(['label' => 'Row'])
      ->condition('machine_name', 'row')
      ->execute();
    $database->update('daedalus_variant_revision')
      ->fields(['label' => 'Row'])
      ->condition('id', $this->loadVariant('row')->id())
      ->execute();

    $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateContainerLayoutAxis();
    $this->assertNotEmpty($lines);

    $row = $this->loadVariant('row');
    $this->assertSame('container_layout', $row->getAxis());
    $this->assertSame('Columns', $row->label());
    $mine = $this->loadVariant('my_columns');
    $this->assertSame('container_layout', $mine->getAxis());
    $this->assertSame('My columns', $mine->label());

    // Idempotent: a second run finds nothing on the retired axis.
    $this->assertSame([], \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateContainerLayoutAxis());
  }

}
