<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationStylesheet;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shipped motion materials and the animation axis on a bare site.
 *
 * Daedalus Stylish ships five animations (daedalus_stylish.daedalus_stylish.animations.yml) and two
 * twin entrance families on the animation axis: three variants on the
 * block kind (daedalus_stylish.daedalus_stylish.variants.yml) and the same three looks on
 * the container kind (daedalus_stylish_layouts.daedalus_stylish.variants.yml), every one
 * playing when its wearer scrolls into view through the Motion group's
 * trigger pair (`timeline-trigger: --in-view view() entry 20% exit 100%` +
 * `animation-trigger: --in-view play-once`, the Animation Triggers
 * draft's spelling). Beside them, one hover-feel look per kind on the
 * hover axis (block_lift / container_lift), each carrying its own
 * `hover` block: a wear never carries a condition, so the material owns
 * its WHEN. A bare site (no skin active, so the materials roster carries
 * no tokens) is the proving ground: everything here must import through
 * the same spine validation AI- and UI-minted materials get, with literal
 * values only, and each variant must carry its own reduced-motion unset
 * (a skin's bottom-layer `animation: none` could never beat a variant's
 * animation-name). The axes are declared once per kind (the universal
 * block kind reaches every block type; the universal container kind every
 * container), and the twin families share their attribute spellings
 * because an axis value resolves in its scope's own slot.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ShippedMotionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The shipped animations, in the repository's label order.
   */
  protected const ANIMATIONS = ['fade_in', 'pulse', 'rise', 'scale_in', 'spin'];

  /**
   * The shipped entrance variants, keyed by machine name: [scope, animation].
   *
   * The two families are twins: one look per animation on each kind, so
   * a page band and the blocks inside it speak one motion vocabulary.
   */
  protected const VARIANTS = [
    'block_fade_in' => ['kind:block', 'fade_in'],
    'block_rise' => ['kind:block', 'rise'],
    'block_scale_in' => ['kind:block', 'scale_in'],
    'container_fade_in' => ['kind:container', 'fade_in'],
    'container_rise' => ['kind:container', 'rise'],
    'container_scale_in' => ['kind:container', 'scale_in'],
  ];

  /**
   * The shipped hover-axis variants, keyed by machine name: the scope.
   */
  protected const LIFTS = [
    'block_lift' => 'kind:block',
    'container_lift' => 'kind:container',
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
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
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
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_skin');
    // A reference-carrying variant save mirrors its motion-* names into the
    // usage table (the delete-in-use grade reads it).
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    // The importers switch to an administrative account (shipped materials
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
    // enableModules() skips install hooks: run daedalus_stylish's own install sweep
    // (variants, skins, swatches, animations, in the hook's order) exactly
    // as a site enabling daedalus_stylish would. daedalus_stylish in the batch syncs EVERY
    // enabled module's file, so the container family arrives with it.
    daedalus_stylish_modules_installed(['daedalus_stylish'], FALSE);
  }

  /**
   * Loads an animation by machine name, bypassing access.
   */
  protected function loadAnimation(string $machine_name): ?DaedalusStylishAnimationInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_animation');
    $storage->resetCache();
    $animations = $storage->loadByProperties(['machine_name' => $machine_name]);
    $animation = reset($animations) ?: NULL;
    assert($animation === NULL || $animation instanceof DaedalusStylishAnimationInterface);
    return $animation;
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
   * The five shipped animations import, publish, roster and compile.
   */
  public function testShippedAnimationsImport(): void {
    foreach (self::ANIMATIONS as $name) {
      $animation = $this->loadAnimation($name);
      $this->assertInstanceOf(DaedalusStylishAnimationInterface::class, $animation, $name);
      $this->assertTrue($animation->isPublished(), $name);
      $this->assertTrue($animation->isShipped(), $name);
      $this->assertSame('daedalus_stylish', $animation->getProvider(), $name);
      $this->assertFalse($animation->isModified(), $name);
      $this->assertNotSame('', $animation->getDescription(), "$name teaches the AI when to reach for it");
      // Literal steps only: a shipped animation resolves on any skin.
      $this->assertStringNotContainsString('var(', json_encode($animation->getSteps()), $name);
    }
    // from/to spellings store canonical, sorted.
    $this->assertSame(['0%', '100%'], array_keys($this->loadAnimation('fade_in')->getSteps()));
    $this->assertSame(['0%', '50%', '100%'], array_keys($this->loadAnimation('pulse')->getSteps()));

    // The published set (what the roster serves, what motion-* validates
    // against on a strict site, what the keyframes file compiles) is the
    // whole family, label-ordered, and nothing else.
    $names = static fn (array $animations): array => array_map(static fn ($animation) => $animation->getMachineName(), $animations);
    $this->assertSame(self::ANIMATIONS, $names(\Drupal::service('daedalus_stylish.animation_repository')->publishedAnimations()));
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(self::ANIMATIONS, array_keys($roster->animations()));
    foreach (self::ANIMATIONS as $name) {
      $this->assertSame(['animation' => StyleGuide::animationName($name)], $roster->animations()[$name]->spellings);
    }

    // The compiled keyframes file carries every shipped name.
    $uri = \Drupal::state()->get(AnimationStylesheet::STATE_KEY);
    $this->assertIsString($uri);
    $css = file_get_contents($uri);
    foreach (self::ANIMATIONS as $name) {
      $this->assertStringContainsString('@keyframes ' . StyleGuide::animationName($name) . ' {', $css, $name);
    }
    $this->assertStringContainsString('transform: translateY(1rem);', $css);
    $this->assertStringContainsString('transform: rotate(360deg);', $css);
  }

  /**
   * Every shipped variant validates on a bare site with no token in sight.
   *
   * The import IS the validation: each definition rode the blueprint
   * deserializer through the variant styles field handler against the
   * real kind declarations, on a site whose roster mints no token. The
   * variant's face is then pinned: the animation axis, a literal timing
   * on the longhands, a shipped animation as the name, the in-view
   * trigger pair (so an entrance below the fold waits for its wearer to
   * scroll in, and one above it still plays at load), and the variant's
   * own reduced-motion unset (which already kills the triggered animation,
   * so the trigger needs no unset of its own).
   */
  public function testShippedVariantsValidateWithoutTokens(): void {
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame([], $roster->tokens(), 'A bare site mints no token: nothing here may reference one.');

    foreach (self::VARIANTS as $machine_name => [$scope, $animation]) {
      $variant = $this->loadVariant($machine_name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, $machine_name);
      $this->assertTrue($variant->isPublished(), $machine_name);
      $this->assertTrue($variant->isShipped(), $machine_name);
      $this->assertSame($scope === 'kind:block' ? 'daedalus_stylish' : 'daedalus_stylish_layouts', $variant->getProvider(), $machine_name);
      $this->assertSame('animation', $variant->getAxis(), $machine_name);
      $this->assertSame($scope, $variant->getScope(), $machine_name);
      $this->assertNotSame('', $variant->getDescription(), $machine_name);

      $styles = $variant->getStyles();
      $this->assertSame([''], array_keys($styles), "$machine_name styles the root only");
      $this->assertStringNotContainsString('var(', json_encode($styles), "$machine_name depends on no token");
      $root = $styles[''];
      $this->assertSame(['default', 'reduced-motion'], array_keys($root), $machine_name);
      // The longhands carry the entrance (one fact per advanced row); the
      // name is a shipped animation's motion-* spelling.
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
    $usage = \Drupal::service('daedalus_stylish.animation_usage');
    foreach (['fade_in', 'rise', 'scale_in'] as $name) {
      $this->assertSame(2, $usage->countReferences($this->loadAnimation($name)), $name);
    }
    foreach (['pulse', 'spin'] as $name) {
      $this->assertSame(0, $usage->countReferences($this->loadAnimation($name)), $name);
    }

    // The roster serves both families on the one axis, each spelled as
    // its own tag's animation attribute value (the machine name minus the
    // kind's prefix) in its SCOPE's slot: the same value on two tags is
    // two spellings, so `animation="fade_in"` reads as this family on a
    // block and as its twin on a container.
    $variants = $roster->variants();
    foreach (self::VARIANTS as $machine_name => [$scope, $animation]) {
      $this->assertSame('animation', $variants[$machine_name]->group, $machine_name);
      $this->assertSame(["attr:$scope:animation" => $animation], $variants[$machine_name]->spellings, $machine_name);
    }
    foreach (['kind:block' => 'block_fade_in', 'kind:container' => 'container_fade_in'] as $scope => $expected) {
      $spelled = array_filter($variants, static fn ($record): bool => ($record->spellings["attr:$scope:animation"] ?? NULL) === 'fade_in');
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
      $this->assertSame($scope === 'kind:block' ? 'daedalus_stylish' : 'daedalus_stylish_layouts', $variant->getProvider(), $machine_name);
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
   * the width stop alone, so a page band is born still and an entrance or
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
