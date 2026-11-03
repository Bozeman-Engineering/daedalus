<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a skin package's shipped motion and the motion axes.
 *
 * The suite ships no motion of its own: the starter skin
 * (daedalus_stylish_starter_skin, the olivero skin's twin) carries five
 * animations in its skins file (`animations:` beside its tokens and
 * defaults) and a variants file wearing three of them on the animation
 * axis, one look per animation on the universal block kind and its twin
 * on the universal container kind, every one playing when its wearer
 * scrolls into view through the Motion group's trigger pair, plus one
 * hover-feel look per kind on the hover axis carrying its own `hover`
 * block. The package is met the way a site meets it: enabled and its
 * system activated, the sync landing the skin (its motion with it) and
 * then its variants under the skin's own law. Everything imports through
 * the same spine validation AI- and UI-minted materials get, with literal
 * values only, and each variant carries its own reduced-motion unset (a
 * skin's bottom-layer `animation: none` could never beat a variant's
 * animation-name). The axes are declared once per kind in the suite (the
 * universal block kind reaches every block type; the universal container
 * kind every container), so a package that mints no motion shows an
 * empty row and nothing defaults at birth.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ShippedMotionTest extends KernelTestBase {

  use UserCreationTrait;

  protected const PACKAGE = 'daedalus_stylish_starter_skin';

  protected const SYSTEM = 'starter';

  /**
   * The skin's animations, in stored order.
   */
  protected const ANIMATIONS = ['fade_in', 'rise', 'scale_in', 'pulse', 'spin'];

  /**
   * The shipped entrance variants, keyed by machine name: [scope, animation].
   *
   * The two families are twins: one look per animation on each kind, so
   * a section and the blocks inside it speak one motion vocabulary.
   */
  protected const VARIANTS = [
    'starter_fade_in' => ['kind:block', 'fade_in'],
    'starter_rise' => ['kind:block', 'rise'],
    'starter_scale_in' => ['kind:block', 'scale_in'],
    'starter_fade_in_section' => ['kind:container', 'fade_in'],
    'starter_rise_section' => ['kind:container', 'rise'],
    'starter_scale_in_section' => ['kind:container', 'scale_in'],
  ];

  /**
   * The shipped hover-axis variants, keyed by machine name: the scope.
   */
  protected const LIFTS = [
    'starter_lift' => 'kind:block',
    'starter_lift_section' => 'kind:container',
  ];

  /**
   * The in-view trigger pair every shipped entrance carries in `default`.
   */
  protected const TIMELINE_TRIGGER = '--in-view view() entry 20% exit 100%';

  protected const ANIMATION_TRIGGER = '--in-view play-once';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    // A reference-carrying save mirrors its references into the usage
    // tables (the delete-in-use grade reads them): the entrances' motion-*
    // names and the skin's var() token references.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish', 'daedalus_blueprint']);
    // The importers switch to an administrative account (shipped materials
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
    $this->enableModules([self::PACKAGE]);
    $this->installConfig([self::PACKAGE]);
    // The activation syncs every enabled module under the starter's law:
    // the skin (its motion with it), then the variants wearing it.
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate(self::SYSTEM);
    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
  }

  /**
   * The active skin, fresh from storage.
   */
  protected function skin(): DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => self::SYSTEM]);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    return $skin;
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
   * The five animations ride the skin, roster and compile.
   */
  public function testShippedAnimationsRideTheSkin(): void {
    $skin = $this->skin();
    $this->assertSame(self::PACKAGE, $skin->getProvider());
    $animations = $skin->animations();
    $this->assertSame(self::ANIMATIONS, array_keys($animations), 'The skin carries the five, in the file\'s order.');
    $this->assertSame([], $skin->siteAnimations(), 'The package layer is the disk\'s; nothing of the site over it.');
    foreach ($animations as $name => $record) {
      $this->assertSame(['steps', 'description', 'offered'], array_keys($record), $name);
      $this->assertNotSame('', $record['description'], "$name teaches the AI when to reach for it");
      $this->assertTrue($record['offered'], $name);
      // Literal steps only: a shipped animation resolves on any site.
      $this->assertStringNotContainsString('var(', json_encode($record['steps']), $name);
    }
    // from/to spellings store canonical, sorted.
    $this->assertSame(['0%', '100%'], array_keys($animations['fade_in']['steps']));
    $this->assertSame(['0%', '50%', '100%'], array_keys($animations['pulse']['steps']));

    // The roster serves the skin's animations (what motion-* validates
    // against on a strict site, what the skin file compiles), in the
    // skin's order, shipped and updatable.
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(self::ANIMATIONS, array_keys($roster->animations()));
    foreach (self::ANIMATIONS as $name) {
      $record = $roster->animations()[$name];
      $this->assertSame(['animation' => StyleGuide::animationName($name)], $record->spellings);
      $this->assertSame('shipped', $record->origin, $name);
      $this->assertTrue($record->updatable, "$name: a consuming site's edit lands in the skin's site layer");
      $this->assertSame(self::SYSTEM, $record->designSystem, "$name is its skin's, a member of the starter system");
    }

    // The compiled skin file carries every keyframes block after the
    // tokens and before the defaults.
    $uri = \Drupal::state()->get(SkinStylesheet::STATE_KEY);
    $this->assertIsString($uri);
    $css = file_get_contents($uri);
    foreach (self::ANIMATIONS as $name) {
      $this->assertStringContainsString('@keyframes ' . StyleGuide::animationName($name) . ' {', $css, $name);
    }
    $this->assertStringContainsString('transform: translateY(1rem);', $css);
    $this->assertStringContainsString('transform: rotate(360deg);', $css);
    $this->assertLessThan(strpos($css, '@keyframes motion-fade_in'), strpos($css, '--space-gutter:'), 'The tokens lead the keyframes.');
    $this->assertGreaterThan(strpos($css, '@keyframes motion-spin'), strpos($css, ':where(h1)') ?: PHP_INT_MAX, 'The keyframes lead the defaults.');
  }

  /**
   * Every shipped entrance variant validates against the skin and wears its motion.
   *
   * The import IS the validation: each definition rode the blueprint
   * deserializer through the variant styles field handler against the
   * real kind declarations, naming an animation the skin carries. The
   * variant's face is then pinned: the animation axis, a literal timing
   * on the longhands, the skin's animation as the name, the in-view
   * trigger pair (so an entrance below the fold waits for its wearer to
   * scroll in, and one above it still plays at load), and the variant's
   * own reduced-motion unset (which already kills the triggered animation,
   * so the trigger needs no unset of its own).
   */
  public function testShippedVariantsWearTheSkinsMotion(): void {
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    foreach (self::VARIANTS as $machine_name => [$scope, $animation]) {
      $variant = $this->loadVariant($machine_name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, $machine_name);
      $this->assertTrue($variant->isPublished(), $machine_name);
      $this->assertTrue($variant->isShipped(), $machine_name);
      $this->assertSame(self::PACKAGE, $variant->getProvider(), $machine_name);
      $this->assertSame(self::SYSTEM, $variant->getDesignSystem(), $machine_name);
      $this->assertSame('animation', $variant->getAxis(), $machine_name);
      $this->assertSame($scope, $variant->getScope(), $machine_name);
      $this->assertNotSame('', $variant->getDescription(), $machine_name);

      $styles = $variant->getStyles();
      $this->assertSame([''], array_keys($styles), "$machine_name styles the root only");
      $this->assertStringNotContainsString('var(', json_encode($styles), "$machine_name depends on no token");
      $root = $styles[''];
      $this->assertSame(['default', 'reduced-motion'], array_keys($root), $machine_name);
      // The longhands carry the entrance (one fact per advanced row); the
      // name is the skin's animation's motion-* spelling.
      $this->assertSame(StyleGuide::animationName($animation), $root['default']['animation-name'], $machine_name);
      $this->assertMatchesRegularExpression('/^\d+ms$/', $root['default']['animation-duration'], $machine_name);
      $this->assertSame('both', $root['default']['animation-fill-mode'], $machine_name);
      $this->assertArrayNotHasKey('animation', $root['default'], "$machine_name spells the default as longhands");
      // The WHEN is the trigger pair, both naming the one `--in-view`
      // trigger so an override of either keeps them bound.
      $this->assertSame(self::TIMELINE_TRIGGER, $root['default']['timeline-trigger'], $machine_name);
      $this->assertSame(self::ANIMATION_TRIGGER, $root['default']['animation-trigger'], $machine_name);
      // The setter unsets itself: skin defaults are the bottom layer, so
      // only the variant can honor reduced motion for what it set.
      $this->assertSame(['animation' => 'none'], $root['reduced-motion'], $machine_name);
    }

    // The usage mirror sees every reference: each entrance animation is
    // worn by its block variant and its container twin, so the
    // delete-in-use grade guards it.
    $usage = \Drupal::service('daedalus_stylish.skin_material_usage');
    foreach (['fade_in', 'rise', 'scale_in'] as $name) {
      $this->assertSame(2, $usage->countReferencesTo(StyleGuide::animationName($name)), $name);
    }
    foreach (['pulse', 'spin'] as $name) {
      $this->assertSame(0, $usage->countReferencesTo(StyleGuide::animationName($name)), $name);
    }

    // The roster serves both families on the one axis, each spelled as
    // its own tag's animation attribute value (the label, lowercased and
    // hyphenated) in its SCOPE's slot: the same value on two tags is two
    // spellings, so `animation="fade-in"` reads as this family on a block
    // and as its twin on a container.
    $variants = $roster->variants();
    $values = ['fade_in' => 'fade-in', 'rise' => 'rise', 'scale_in' => 'scale-in'];
    foreach (self::VARIANTS as $machine_name => [$scope, $animation]) {
      $this->assertSame('animation', $variants[$machine_name]->group, $machine_name);
      $this->assertSame(["attr:$scope:animation" => $values[$animation]], $variants[$machine_name]->spellings, $machine_name);
    }
    foreach (['kind:block' => 'starter_fade_in', 'kind:container' => 'starter_fade_in_section'] as $scope => $expected) {
      $spelled = array_filter($variants, static fn ($record): bool => ($record->spellings["attr:$scope:animation"] ?? NULL) === 'fade-in');
      $this->assertSame([$expected], array_keys($spelled), "One look name, one record in $scope's slot.");
    }

    // The compiled variant stylesheet delivers the entrance, its trigger
    // pair with the runtime's markers beside them, the polyfill hold
    // (`@supports not`, skipped natively) and the reduced-motion unset
    // under the preference media query.
    $css = file_get_contents(\Drupal::state()->get(VariantStylesheet::STATE_KEY));
    foreach (self::VARIANTS as $machine_name => [, $animation]) {
      $this->assertStringContainsString(".daedalus-stylish-variant--$machine_name", $css, $machine_name);
      $this->assertStringContainsString('animation-name: ' . StyleGuide::animationName($animation) . ';', $css, $machine_name);
    }
    // Line-anchored: the marker line carries the property line as a
    // substring, so each spelling is counted on its own line.
    $this->assertSame(count(self::VARIANTS), preg_match_all('/^\s*timeline-trigger: ' . preg_quote(self::TIMELINE_TRIGGER, '/') . ';$/m', $css));
    $this->assertSame(count(self::VARIANTS), preg_match_all('/^\s*--daedalus-stylish-timeline-trigger: ' . preg_quote(self::TIMELINE_TRIGGER, '/') . ';$/m', $css));
    $this->assertSame(count(self::VARIANTS), preg_match_all('/^\s*animation-trigger: ' . preg_quote(self::ANIMATION_TRIGGER, '/') . ';$/m', $css));
    $this->assertSame(count(self::VARIANTS), preg_match_all('/^\s*--daedalus-stylish-animation-trigger: ' . preg_quote(self::ANIMATION_TRIGGER, '/') . ';$/m', $css));
    $this->assertSame(count(self::VARIANTS), substr_count($css, '@supports not (animation-trigger: none)'));
    $this->assertStringContainsString(':not([data-daedalus-stylish-triggered])', $css);
    $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    $this->assertStringContainsString('animation: none;', $css);
  }

  /**
   * The hover family: one Lift per kind, carrying its own hover block.
   *
   * A wear is unconditioned by construction (the reference list is a
   * sibling of the condition keys), so hover FEEL ships as material on
   * its own axis with the WHEN inside: the rest state's transition under
   * `default`, the moved state under `hover`, and the variant's own
   * reduced-motion unset for what it set. The properties it writes
   * (transition, transform, box-shadow) are exactly why the kinds grant
   * motion, effects and shadow. It references no animation, so the usage
   * mirror never sees it, and it spells as its scope's `hover` attribute.
   */
  public function testShippedHoverFamily(): void {
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $css = file_get_contents(\Drupal::state()->get(VariantStylesheet::STATE_KEY));
    foreach (self::LIFTS as $machine_name => $scope) {
      $variant = $this->loadVariant($machine_name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, $machine_name);
      $this->assertTrue($variant->isPublished(), $machine_name);
      $this->assertTrue($variant->isShipped(), $machine_name);
      $this->assertSame(self::PACKAGE, $variant->getProvider(), $machine_name);
      $this->assertSame('hover', $variant->getAxis(), $machine_name);
      $this->assertSame($scope, $variant->getScope(), $machine_name);
      $this->assertSame('Lift', $variant->label(), $machine_name);
      $this->assertNotSame('', $variant->getDescription(), $machine_name);

      $styles = $variant->getStyles();
      $this->assertSame([''], array_keys($styles), "$machine_name styles the root only");
      $this->assertStringNotContainsString('var(', json_encode($styles), "$machine_name depends on no token");
      $root = $styles[''];
      $this->assertSame(['default', 'hover', 'reduced-motion'], array_keys($root), $machine_name);
      $this->assertSame(['transition' => 'transform 150ms ease-out, box-shadow 150ms ease-out'], $root['default'], $machine_name);
      $this->assertSame(['transform' => 'translateY(-4px)', 'box-shadow' => '0 8px 24px rgb(0 0 0 / 0.12)'], $root['hover'], $machine_name);
      $this->assertSame(['transition' => 'none', 'transform' => 'none'], $root['reduced-motion'], $machine_name);
      $this->assertStringNotContainsString('motion-', json_encode($styles), "$machine_name wears no animation");

      $this->assertSame('hover', $roster->variants()[$machine_name]->group, $machine_name);
      $this->assertSame(["attr:$scope:hover" => 'lift'], $roster->variants()[$machine_name]->spellings, $machine_name);

      $this->assertStringContainsString(".daedalus-stylish-variant--$machine_name", $css, $machine_name);
    }
    $this->assertSame(2, substr_count($css, 'transform: translateY(-4px);'));
    $this->assertStringContainsString(':hover', $css);
    // One look name, one record per scope's slot.
    foreach (self::LIFTS as $expected => $scope) {
      $spelled = array_filter($roster->variants(), static fn ($record): bool => ($record->spellings["attr:$scope:hover"] ?? NULL) === 'lift');
      $this->assertSame([$expected], array_keys($spelled), $scope);
    }
  }

  /**
   * The animation and hover axes are effective on every block.
   *
   * Declared once, on the universal block kind (daedalus_stylish.page_builder.yml)
   * with the "Animation" and "Hover" labels, so the wear bar's rows, the
   * AI's axis vocabulary and the variant entity's own axis check all
   * resolve them on every block type; nothing defaults at birth (a block
   * is born still).
   */
  public function testAxisEffectiveOnBlock(): void {
    $guide = \Drupal::service('daedalus_stylish.style_guide');
    $validator = \Drupal::service('daedalus_stylish.style_map_validator');

    $block = $guide->getKind('block');
    $this->assertSame(['style', 'animation', 'hover'], StyleGuide::axes($block));
    $this->assertSame(['animation' => 'Animation', 'hover' => 'Hover'], StyleGuide::axisLabels($block));
    $this->assertSame([], StyleGuide::rootOnlyAxes($block));
    $this->assertArrayNotHasKey('default_variants', $block);
    // Every block type inherits the kind's list (no shipped block type
    // authors its own variant_axes).
    $this->assertSame(['style', 'animation', 'hover'], StyleGuide::axes($guide->getBlockType('basic')));
    $this->assertSame(['style', 'animation', 'hover'], $validator->scopeAxes('kind:block'));
    $this->assertSame(['style', 'animation', 'hover'], $validator->scopeAxes('block_type:basic'));

    // The client payload carries each label once, site-wide, and the
    // effective vocabulary on the kind.
    $payload = \Drupal::service('daedalus_stylish.client_style_guide')->build('stark');
    $this->assertSame('Animation', $payload['axisLabels']['animation']);
    $this->assertSame('Hover', $payload['axisLabels']['hover']);
    $this->assertSame(['style', 'animation', 'hover'], array_keys($payload['kinds']['block']['axes']));
    $this->assertSame([], array_keys(array_filter($payload['kinds']['block']['axes'], static fn (array $axis): bool => $axis['root_only'])));
  }

  /**
   * The animation and hover axes are effective on every container.
   *
   * Declared once, on the universal container kind
   * (daedalus_stylish_layouts.page_builder.yml) after width and Layout, offered at
   * every depth (width stays the one root-only axis) and defaulting
   * nothing at birth: the container layout's default_variants still name
   * the width stop alone, so a section is born still and an entrance or
   * a hover feel is the deliberate gesture.
   */
  public function testAxisEffectiveOnContainer(): void {
    $guide = \Drupal::service('daedalus_stylish.style_guide');
    $validator = \Drupal::service('daedalus_stylish.style_map_validator');

    $kind = $guide->getKind('container');
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], StyleGuide::axes($kind));
    $this->assertSame(['container_layout' => 'Layout', 'animation' => 'Animation', 'hover' => 'Hover'], StyleGuide::axisLabels($kind));
    $this->assertSame(['width'], StyleGuide::rootOnlyAxes($kind));
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], $validator->scopeAxes('kind:container'));
    // The one layout inherits the kind's list and defaults width only.
    $layout = $guide->getLayout('daedalus_stylish_container');
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], StyleGuide::axes($layout));
    $this->assertSame(['width' => 'container_site'], StyleGuide::defaultVariants($layout));
    $this->assertSame(['width' => 'container_site'], StyleGuide::defaultVariants($layout, TRUE));

    $payload = \Drupal::service('daedalus_stylish.client_style_guide')->build('stark');
    $this->assertSame('Animation', $payload['axisLabels']['animation']);
    $this->assertSame('Hover', $payload['axisLabels']['hover']);
    $this->assertSame(['style', 'width', 'container_layout', 'animation', 'hover'], array_keys($payload['kinds']['container']['axes']));
    $this->assertSame(['width'], array_keys(array_filter($payload['kinds']['container']['axes'], static fn (array $axis): bool => $axis['root_only'])));
  }

}
