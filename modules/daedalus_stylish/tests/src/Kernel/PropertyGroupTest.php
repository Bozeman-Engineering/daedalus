<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\Plugin\PropertyGroup\GeneratedContent;
use Drupal\daedalus_stylish\PropertyGroupBase;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\daedalus_stylish\PropertyGroupPluginManager;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests property group discovery and validation.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PropertyGroupTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'user',
    'field',
    'file',
    'image',
    'media',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The property group plugin manager under test.
   */
  protected PropertyGroupPluginManager $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->manager = $this->container->get('plugin.manager.daedalus_stylish_property_group');
  }

  /**
   * Discovery: shipped groups exist, ordered by weight; ownership resolves.
   */
  public function testDiscoveryAndOwnership(): void {
    // Effects and Motion share weight 80; the id tie-break seats Effects
    // (what a treatment moves) before Motion (how it moves).
    $curated = ['spacing', 'typography', 'background', 'border', 'shadow', 'sizing', 'layout', 'effects', 'motion', 'generated_content'];
    $groups = $this->manager->getGroups();
    // Curated groups first (weights 10-90); the generated wide tier
    // (weight 100, one derivative per CSS spec area) sorts after them all.
    $this->assertSame($curated, array_slice(array_keys($groups), 0, 10));
    $generated = array_slice(array_keys($groups), 10);
    $this->assertNotEmpty($generated);
    foreach ($generated as $id) {
      $this->assertStringStartsWith('wide:', $id);
      $this->assertTrue((bool) ($groups[$id]->getPluginDefinition()['generated'] ?? FALSE));
    }
    $this->assertSame($curated, array_keys($this->manager->getCuratedGroups()));
    // The pseudo-only groups are the ones a real element may not name.
    $this->assertSame(['generated_content'], $this->manager->getPseudoGroups());
    $real = array_values(array_diff(array_keys($groups), ['generated_content']));
    $this->assertSame($real, array_keys($this->manager->getElementGroups()));

    $this->assertSame('spacing', $this->manager->getGroupIdForProperty('padding'));
    $this->assertSame('typography', $this->manager->getGroupIdForProperty('font-size'));
    // Text color is Typography's; Background owns surface colors.
    $this->assertSame('typography', $this->manager->getGroupIdForProperty('color'));
    $this->assertSame('background', $this->manager->getGroupIdForProperty('background-color'));
    // background-clip is curated into Background (the gradient-text
    // recipe's clip write must be legal wherever background is declared),
    // winning over the wide tier's Backgrounds and Borders derivative.
    $this->assertSame('background', $this->manager->getGroupIdForProperty('background-clip'));
    $this->assertSame('border', $this->manager->getGroupIdForProperty('border-radius'));
    // The outline longhands are curated into Border (the border's twin in
    // grammar), winning over the wide tier's Basic User Interface
    // derivative; the `outline` shorthand stays wide like `border` does.
    $this->assertSame('border', $this->manager->getGroupIdForProperty('outline-width'));
    $this->assertSame('border', $this->manager->getGroupIdForProperty('outline-style'));
    $this->assertSame('border', $this->manager->getGroupIdForProperty('outline-color'));
    $this->assertSame('border', $this->manager->getGroupIdForProperty('outline-offset'));
    $this->assertSame('wide:basic_user_interface', $this->manager->getGroupIdForProperty('outline'));
    $this->assertSame('wide:backgrounds_and_borders', $this->manager->getGroupIdForProperty('border'));
    $this->assertSame('shadow', $this->manager->getGroupIdForProperty('box-shadow'));
    $this->assertSame('sizing', $this->manager->getGroupIdForProperty('max-width'));
    $this->assertSame('layout', $this->manager->getGroupIdForProperty('display'));
    // Where the generated tier overlaps a curated group, the curated group
    // wins by weight — its constraints, controls, and AI guidance stay
    // authoritative.
    $this->assertSame('layout', $this->manager->getGroupIdForProperty('align-content'));
    $this->assertSame('motion', $this->manager->getGroupIdForProperty('animation-name'));
    $this->assertSame('motion', $this->manager->getGroupIdForProperty('transition'));
    // The two trigger shorthands (the WHEN of an animation) are curated
    // into Motion; their longhands stay with the wide tier until the
    // draft's names settle.
    $this->assertSame('motion', $this->manager->getGroupIdForProperty('timeline-trigger'));
    $this->assertSame('motion', $this->manager->getGroupIdForProperty('animation-trigger'));
    $this->assertSame('wide:animations', $this->manager->getGroupIdForProperty('timeline-trigger-source'));
    // Effects curates the transforms and opacity away from the wide
    // Transforms and Color derivatives; filters and blend modes stay wide.
    $this->assertSame('effects', $this->manager->getGroupIdForProperty('transform'));
    $this->assertSame('effects', $this->manager->getGroupIdForProperty('translate'));
    $this->assertSame('effects', $this->manager->getGroupIdForProperty('opacity'));
    $this->assertSame('wide:transforms', $this->manager->getGroupIdForProperty('transform-origin'));
    $this->assertSame('wide:filter_effects', $this->manager->getGroupIdForProperty('filter'));
    // A property only the wide tier recognizes resolves to its spec-area
    // derivative.
    $this->assertSame('wide:filter_effects', $this->manager->getGroupIdForProperty('backdrop-filter'));
    $this->assertNull($this->manager->getGroupIdForProperty('not-a-property'));
  }

  /**
   * Structural validation from property definitions, no constraints.
   */
  public function testStructuralValidation(): void {
    $spacing = $this->manager->createInstance('spacing');
    $typography = $this->manager->createInstance('typography');

    // Dimensions.
    $this->assertValid($spacing, 'padding', '2rem');
    $this->assertValid($spacing, 'padding', '0');
    $this->assertValid($spacing, 'padding', '1rem 2rem 1rem 2rem');
    $this->assertCode('INVALID_STYLE_VALUE', $spacing, 'padding', '1rem 2rem 1rem 2rem 3rem');
    $this->assertCode('INVALID_STYLE_VALUE', $spacing, 'padding', 'big');
    // Unitless non-zero.
    $this->assertCode('INVALID_STYLE_VALUE', $spacing, 'padding', '2');
    // Negative values: margins yes, padding no.
    $this->assertValid($spacing, 'margin-top', '-1rem');
    $this->assertCode('INVALID_STYLE_VALUE', $spacing, 'padding-top', '-1rem');
    // Component keywords.
    $this->assertValid($spacing, 'margin', '0 auto');
    $this->assertCode('INVALID_STYLE_VALUE', $spacing, 'padding', '0 auto');

    // Pass-throughs: var(), calc(), CSS-wide keywords.
    $this->assertValid($spacing, 'padding', 'var(--space-m)');
    $this->assertValid($spacing, 'padding', 'calc(2rem + 5px)');
    $this->assertValid($spacing, 'padding', 'inherit');

    // CSS math functions are unsupported values; the
    // corrective names the rule rather than misreporting the value's inner
    // whitespace as "too many values" (the measured clamp() confusion).
    $errors = $typography->validate('font-size', 'clamp(2.25rem, 5vw, 3.75rem)');
    $this->assertNotEmpty($errors);
    $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode());
    $this->assertSame('Use a token or a single length; clamp()/calc() are not supported.', $errors[0]->getHint());
    // The spaceless spelling lands on the same corrective, not the generic
    // "not a valid value" tail.
    $errors = $typography->validate('font-size', 'clamp(1rem,2vw,3rem)');
    $this->assertSame('Use a token or a single length; clamp()/calc() are not supported.', $errors[0]->getHint());

    // Numbers with keywords.
    $this->assertValid($typography, 'font-weight', '400');
    $this->assertValid($typography, 'font-weight', 'bold');
    $this->assertCode('INVALID_STYLE_VALUE', $typography, 'font-weight', 'heavy');
    // Dimension-or-number.
    $this->assertValid($typography, 'line-height', '1.5');
    $this->assertValid($typography, 'line-height', '24px');

    // Keywords.
    $this->assertValid($typography, 'text-align', 'center');
    $this->assertCode('INVALID_STYLE_VALUE', $typography, 'text-align', 'middle');

    // Colors.
    $this->assertValid($typography, 'color', '#1a1a2e');
    $this->assertValid($typography, 'color', 'rgb(26 26 46)');
    $this->assertValid($typography, 'color', 'rebeccapurple');
    $this->assertCode('INVALID_STYLE_VALUE', $typography, 'color', '#12345');

    // Value hygiene: no declarations smuggled into a value.
    $this->assertCode('INVALID_STYLE_VALUE', $typography, 'color', 'red; background: url(evil)');

    // A property the group does not own.
    $this->assertCode('INVALID_STYLE_PROPERTY', $spacing, 'font-size', '1rem');
  }

  /**
   * Structural validation across the remaining shipped groups.
   */
  public function testRemainingGroups(): void {
    $background = $this->manager->createInstance('background');
    $border = $this->manager->createInstance('border');
    $shadow = $this->manager->createInstance('shadow');
    $sizing = $this->manager->createInstance('sizing');
    $layout = $this->manager->createInstance('layout');
    $effects = $this->manager->createInstance('effects');

    // Background: color, keyword, and dimension-with-keywords properties.
    $this->assertValid($background, 'background-color', '#16213e');
    $this->assertValid($background, 'background-repeat', 'no-repeat');
    $this->assertCode('INVALID_STYLE_VALUE', $background, 'background-repeat', 'sideways');
    $this->assertValid($background, 'background-position', 'center top');
    $this->assertValid($background, 'background-position', '50% 50%');
    $this->assertValid($background, 'background-size', 'cover');
    $this->assertValid($background, 'background-size', '100% auto');
    // background-clip: the curated keyword set, 'text' (the gradient-text
    // recipe's write) included; the wide tier's extra 'border-area' keyword
    // is out: the curated definition is the authority now.
    $this->assertValid($background, 'background-clip', 'text');
    $this->assertValid($background, 'background-clip', 'border-box');
    $this->assertValid($background, 'background-clip', 'padding-box');
    $this->assertValid($background, 'background-clip', 'content-box');
    $this->assertCode('INVALID_STYLE_VALUE', $background, 'background-clip', 'border-area');

    // 'text' type: multi-part values pass hygiene plus the script scan.
    $this->assertValid($background, 'background-image', 'linear-gradient(120deg, #1a1a2e, #16213e)');
    $this->assertValid($background, 'background-image', 'url(/sites/default/files/hero.png)');
    $this->assertCode('INVALID_STYLE_VALUE', $background, 'background-image', 'url(javascript:alert(1))');
    $this->assertCode('INVALID_STYLE_VALUE', $shadow, 'box-shadow', 'expression(alert(1))');
    $this->assertValid($shadow, 'box-shadow', '0 2px 8px rgb(0 0 0 / 0.25), inset 0 0 2px #fff');
    $this->assertValid($shadow, 'text-shadow', 'none');

    // Effects: the transforms are 'text' (csstree's grammar client-side);
    // opacity's 0..1 is the definition's own range, checked with no guide
    // constraint at all, and 'none' is no opacity.
    $this->assertValid($effects, 'transform', 'translateY(-4px) scale(1.02)');
    $this->assertValid($effects, 'transform', 'none');
    $this->assertCode('INVALID_STYLE_VALUE', $effects, 'transform', 'expression(alert(1))');
    $this->assertValid($effects, 'rotate', '3deg');
    $this->assertValid($effects, 'scale', '1.02');
    $this->assertValid($effects, 'translate', '0 -4px');
    $this->assertValid($effects, 'opacity', '0');
    $this->assertValid($effects, 'opacity', '.5');
    $this->assertValid($effects, 'opacity', '1');
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $effects, 'opacity', '1.5');
    $this->assertCode('INVALID_STYLE_VALUE', $effects, 'opacity', '-0.1');
    $this->assertCode('INVALID_STYLE_VALUE', $effects, 'opacity', 'none');
    // A guide block layers over the definition's range per key.
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $effects, 'opacity', '0.9', ['max' => '0.8']);
    $this->assertValid($effects, 'opacity', 'var(--dim)');

    // Border: shorthand arity, per-side longhands, keyword widths.
    $this->assertValid($border, 'border-width', '1px 2px');
    $this->assertValid($border, 'border-width', 'thin');
    $this->assertValid($border, 'border-top-style', 'dashed');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'border-style', 'wavy');
    $this->assertValid($border, 'border-color', 'rebeccapurple');
    $this->assertValid($border, 'border-radius', '8px 8px 0 0');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'border-top-left-radius', '-4px');

    // Outline: the border's twin with no sides. Keyword widths, unsigned;
    // `auto` joins the style set and `hidden` (border-only) leaves it; the
    // offset is signed and single-valued.
    $this->assertValid($border, 'outline-width', '2px');
    $this->assertValid($border, 'outline-width', 'thick');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-width', '-1px');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-width', '1px 2px');
    $this->assertValid($border, 'outline-style', 'auto');
    $this->assertValid($border, 'outline-style', 'dashed');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-style', 'wavy');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-style', 'hidden');
    $this->assertValid($border, 'outline-color', 'rebeccapurple');
    $this->assertValid($border, 'outline-color', 'var(--accent)');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-color', '#12345');
    $this->assertValid($border, 'outline-offset', '-4px');
    $this->assertValid($border, 'outline-offset', '0.25rem');
    $this->assertValid($border, 'outline-offset', '0');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-offset', '1px 2px');
    $this->assertCode('INVALID_STYLE_VALUE', $border, 'outline-offset', 'far');

    // Sizing: keywords per property, max keywords differ.
    $this->assertValid($sizing, 'width', '50%');
    $this->assertValid($sizing, 'width', 'fit-content');
    $this->assertValid($sizing, 'max-width', 'none');
    $this->assertCode('INVALID_STYLE_VALUE', $sizing, 'width', 'none');
    $this->assertCode('INVALID_STYLE_VALUE', $sizing, 'height', 'tall');
    $this->assertValid($sizing, 'overflow', 'hidden');
    $this->assertValid($sizing, 'aspect-ratio', '16 / 9');

    // Layout: keywords, numbers (signed and not), insets.
    $this->assertValid($layout, 'display', 'flex');
    $this->assertCode('INVALID_STYLE_VALUE', $layout, 'display', 'flexbox');
    $this->assertValid($layout, 'flex-grow', '1');
    $this->assertCode('INVALID_STYLE_VALUE', $layout, 'flex-grow', '-1');
    $this->assertValid($layout, 'order', '-1');
    $this->assertValid($layout, 'z-index', 'auto');
    $this->assertValid($layout, 'top', '-10px');
    $this->assertValid($layout, 'grid-template-columns', 'repeat(3, minmax(0, 1fr))');

    // Constraints run identically: palettes and ranges.
    $palette = ['allowed' => ['var(--surface)', '#16213e']];
    $this->assertValid($background, 'background-color', 'var(--surface)', $palette);
    $this->assertCode('STYLE_VALUE_NOT_ALLOWED', $background, 'background-color', '#fff', $palette);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $border, 'border-width', '10px', ['max' => '4px']);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $sizing, 'max-width', '90rem', ['max' => '80rem']);
  }

  /**
   * Constraint enforcement: allowed, min/max, units, comparability.
   */
  public function testConstraints(): void {
    $spacing = $this->manager->createInstance('spacing');
    $typography = $this->manager->createInstance('typography');

    // "allowed" is exhaustive and verbatim — the palette mechanism.
    $fonts = ['allowed' => ['Inter', 'Georgia']];
    $this->assertValid($typography, 'font-family', 'Georgia', $fonts);
    $this->assertCode('STYLE_VALUE_NOT_ALLOWED', $typography, 'font-family', 'arial', $fonts);
    $palette = ['allowed' => ['var(--brand)', '#1a1a2e']];
    $this->assertValid($typography, 'color', 'var(--brand)', $palette);
    $this->assertCode('STYLE_VALUE_NOT_ALLOWED', $typography, 'color', 'var(--other)', $palette);

    // min/max with px/rem/em/pt conversion.
    $max = ['max' => '2rem'];
    $this->assertValid($typography, 'font-size', '1.5rem', $max);
    $this->assertValid($typography, 'font-size', '24px', $max);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $typography, 'font-size', '3rem', $max);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $typography, 'font-size', '40px', $max);
    $this->assertCode('STYLE_VALUE_BELOW_MIN', $typography, 'font-size', '10px', ['min' => '0.75rem']);
    // Shorthand components are each checked.
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $spacing, 'padding', '1rem 5rem', ['max' => '4rem']);
    // Incomparable units are a violation, not a bypass.
    $this->assertCode('STYLE_VALUE_NOT_COMPARABLE', $typography, 'font-size', '150%', $max);
    // var()/calc() skip range checks (pass-through).
    $this->assertValid($typography, 'font-size', 'var(--step-2)', $max);
    // Unitless numbers compare to unitless bounds.
    $this->assertValid($typography, 'line-height', '1.4', ['max' => '2']);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $typography, 'line-height', '3', ['max' => '2']);

    // Units constraint.
    $rem_only = ['units' => ['rem']];
    $this->assertValid($spacing, 'padding', '2rem', $rem_only);
    $this->assertCode('STYLE_UNIT_NOT_ALLOWED', $spacing, 'padding', '32px', $rem_only);
  }

  /**
   * The Motion group: keyframes names, timings, and the typed longhands.
   */
  public function testMotionGroup(): void {
    $motion = $this->manager->createInstance('motion');

    // animation-name: the keyframes-name type is the ident grammar plus
    // `none`, comma-listed; which animation a name resolves to is the
    // validator's question, not the group's.
    $this->assertValid($motion, 'animation-name', 'motion-fade_in');
    $this->assertValid($motion, 'animation-name', 'none');
    $this->assertValid($motion, 'animation-name', 'motion-fade_in, spin');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-name', '2fast');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-name', 'default');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-name', '--custom');
    $errors = $motion->validate('animation-name', '"quoted"');
    $this->assertStringContainsString('not a keyframes name', $errors[0]->getMessage());

    // Shorthands are text: hygiene and the script scan only.
    $this->assertValid($motion, 'animation', 'motion-fade_in 600ms ease-out both');
    $this->assertValid($motion, 'transition', 'color 150ms ease-out, background-color 150ms');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'transition', 'expression(alert(1))');

    // Timings: time dimensions, listed; delays may be negative, durations
    // not.
    $this->assertValid($motion, 'animation-duration', '600ms, 1.5s');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-duration', '-1s');
    $this->assertValid($motion, 'animation-delay', '-200ms');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'transition-duration', 'fast');

    // Easing: keywords and function notation, listed.
    $this->assertValid($motion, 'animation-timing-function', 'ease-in-out, cubic-bezier(0.4, 0, 0.2, 1)');
    $this->assertValid($motion, 'transition-timing-function', 'steps(4, end)');

    // Counts and the keyword longhands.
    $this->assertValid($motion, 'animation-iteration-count', '2, infinite');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-iteration-count', 'forever');
    $this->assertValid($motion, 'animation-fill-mode', 'forwards, both');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-direction', 'sideways');
    $this->assertValid($motion, 'transition-property', 'opacity, transform');
    $this->assertValid($motion, 'transition-property', 'all');
    $this->assertValid($motion, 'transition-behavior', 'allow-discrete');

    // Triggers: text-shaped (the draft's grammar is the client's lint),
    // `none` the one keyword, ordered after animation-composition so the
    // advanced Motion section reads look first, then when.
    $properties = array_keys($motion->getProperties());
    $this->assertSame(['animation-composition', 'timeline-trigger', 'animation-trigger'], array_slice($properties, -3));
    $this->assertSame('text', $motion->getProperties()['timeline-trigger']['type']);
    $this->assertSame(['none'], $motion->getProperties()['animation-trigger']['keywords']);
    $this->assertValid($motion, 'timeline-trigger', '--in-view view() entry 20%');
    $this->assertValid($motion, 'timeline-trigger', '--in-view view() contain 0%');
    $this->assertValid($motion, 'timeline-trigger', 'none');
    $this->assertValid($motion, 'animation-trigger', '--in-view play-once');
    $this->assertValid($motion, 'animation-trigger', '--in-view replay reset');
    $this->assertValid($motion, 'animation-trigger', 'none');
    $this->assertCode('INVALID_STYLE_VALUE', $motion, 'animation-trigger', 'expression(alert(1))');
  }

  /**
   * The wide-tier definition shapes: list flag, dimension|number, ident.
   *
   * Motion is the one curated consumer of these shapes; the throwaway
   * group below keeps their own coverage independent of any curated
   * definition, since the generated tier is their bulk consumer.
   */
  public function testListIdentAndUnionShapes(): void {
    $group = $this->wideShapesGroup();

    // A list dimension: comma and space separated repetition, unbounded.
    $this->assertValid($group, 'transition-duration', '0.3s');
    $this->assertValid($group, 'transition-duration', '0.3s, 1s, 250ms');
    $this->assertValid($group, 'transition-duration', '0.3s 1s');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'transition-duration', 'fast, 1s');
    // Malformed lists: empty comma segments are the list's own error.
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'transition-duration', '0.3s,, 1s');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'transition-duration', ', 0.3s');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'transition-duration', '0.3s,');
    // Constraints check each component; same-unit bounds compare.
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $group, 'transition-duration', '0.2s, 1s', ['max' => '0.5s']);
    // Pass-throughs still run on the whole value.
    $this->assertValid($group, 'transition-duration', 'var(--speed), 1s');
    $this->assertValid($group, 'transition-duration', 'inherit');

    // A list keyword; function notation stays one component (one error, not
    // a per-argument explosion).
    $this->assertValid($group, 'transition-timing-function', 'ease, linear');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'transition-timing-function', 'ease, bouncy');
    $errors = $group->validate('transition-timing-function', 'cubic-bezier(0.4, 0, 0.2, 1)');
    $this->assertCount(1, $errors);

    // A list number.
    $this->assertValid($group, 'animation-iteration-count', '2, infinite');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'animation-iteration-count', '2, always');

    // Ident: identifier grammar, reserved words, keyword rider.
    $this->assertValid($group, 'view-transition-name', 'hero-image');
    $this->assertValid($group, 'view-transition-name', '_private');
    $this->assertValid($group, 'view-transition-name', 'none');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'view-transition-name', 'default');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'view-transition-name', '2fast');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'view-transition-name', '--custom');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'view-transition-name', 'two words');
    $this->assertValid($group, 'will-change', 'opacity, transform');
    $this->assertValid($group, 'will-change', 'auto');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'will-change', 'opacity, 9lives');

    // The dimension|number union: bare numbers and dimensions both pass,
    // negativity still gated.
    $this->assertValid($group, 'tab-size', '4');
    $this->assertValid($group, 'tab-size', '2rem');
    $this->assertCode('INVALID_STYLE_VALUE', $group, 'tab-size', '-3');
    $this->assertValid($group, 'tab-size', '1.5', ['max' => '2']);
    $this->assertCode('STYLE_VALUE_ABOVE_MAX', $group, 'tab-size', '3', ['max' => '2']);
  }

  /**
   * Builds a throwaway group exercising the wide-tier definition shapes.
   */
  protected function wideShapesGroup(): object {
    return new class([], 'wide_shapes', ['label' => 'Wide shapes'], $this->container->get('entity_type.manager'), $this->container->get('entity.repository')) extends PropertyGroupBase {

      /**
       * {@inheritdoc}
       */
      public function getProperties(): array {
        return [
          'transition-duration' => ['label' => 'Transition duration', 'type' => 'dimension', 'list' => TRUE],
          'transition-timing-function' => [
            'label' => 'Timing function',
            'type' => 'keyword',
            'list' => TRUE,
            'keywords' => ['ease', 'linear', 'ease-in', 'ease-out'],
          ],
          'animation-iteration-count' => [
            'label' => 'Iteration count',
            'type' => 'number',
            'list' => TRUE,
            'keywords' => ['infinite'],
          ],
          'will-change' => ['label' => 'Will change', 'type' => 'ident', 'list' => TRUE, 'keywords' => ['auto']],
          'view-transition-name' => ['label' => 'View transition name', 'type' => 'ident', 'keywords' => ['none']],
          'tab-size' => ['label' => 'Tab size', 'type' => 'dimension', 'number' => TRUE],
        ];
      }

    };
  }

  /**
   * The generated box's existence switch: "", none, or one media token.
   *
   * The grammar of content (GeneratedContent): the two keywords, or
   * exactly one media reference as the whole value, which the base
   * class's media gates judge before it lands on the keyword grammar
   * as none. Everything else, a token beside anything included, is
   * refused with the three spellings. The property carries the media
   * flag, so it is media-capable at the manager, the one seam every
   * media consumer reads.
   */
  public function testGeneratedContent(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://box.png');
    $file = File::create(['uri' => 'public://box.png', 'filename' => 'box.png']);
    $file->save();
    $media = Media::create(['bundle' => $image_type->id(), 'name' => 'Box', $source_field => ['target_id' => $file->id()]]);
    $media->save();
    $file_type = $this->createMediaType('file');
    $document = Media::create(['bundle' => $file_type->id(), 'name' => 'Document']);
    $document->save();
    $token = MediaToken::build($media->uuid());

    $group = $this->manager->createInstance('generated_content');
    $this->assertSame(['content'], array_keys($group->getProperties()));
    $this->assertTrue($this->manager->isMediaCapable('content'));
    $this->assertSame(['""', 'none'], $group->getProperties()['content']['keywords']);

    // The three accepts, trimmed, the keyword case-insensitive, the token
    // in either quoting.
    $this->assertValid($group, 'content', '""');
    $this->assertValid($group, 'content', 'none');
    $this->assertValid($group, 'content', ' NONE ');
    $this->assertValid($group, 'content', $token);
    $this->assertValid($group, 'content', " url(media:{$media->uuid()}) ");
    $this->assertValid($group, 'content', $token, ['image_style' => 'thumbnail']);

    // One token as the whole value, nothing beside it: two tokens, a
    // token beside text or a keyword, a layer list, image-set() and the
    // alt form are all refused with content's own corrective.
    foreach ([
      "$token, $token",
      "$token $token",
      "\"x\" $token",
      "none $token",
      "$token / \"alt\"",
      "image-set($token 1x)",
      "linear-gradient(#000, #fff), $token",
      'url(x.svg)',
      'url("x.png")',
      'linear-gradient(#000, #fff)',
      '"hi"',
      "''",
      'attr(title)',
      'counter(item)',
      'open-quote',
      'normal',
      'var(--x)',
      'inherit',
      'none none',
    ] as $value) {
      $errors = $group->validate('content', $value);
      $this->assertCount(1, $errors, $value);
      $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode(), $value);
      $this->assertSame(['keywords' => GeneratedContent::VALUES], $errors[0]->getContext(), $value);
      $this->assertStringContainsString('one media reference', $errors[0]->getHint(), $value);
      $this->assertStringContainsString('Text belongs in a field', $errors[0]->getHint(), $value);
    }

    // The one token is judged by the base class's media gates: a payload
    // that is no uuid, a media that does not exist, a media whose source
    // is no image.
    $this->assertCode('INVALID_MEDIA_REFERENCE', $group, 'content', "url('media:not-a-uuid')");
    $this->assertCode('MEDIA_NOT_FOUND', $group, 'content', MediaToken::build('11111111-1111-1111-1111-111111111111'));
    $this->assertCode('MEDIA_NOT_IMAGE', $group, 'content', MediaToken::build($document->uuid()));
  }

  /**
   * Asserts a value validates cleanly.
   */
  protected function assertValid(object $group, string $property, string $value, array $constraints = []): void {
    $errors = $group->validate($property, $value, $constraints);
    $this->assertSame([], array_map(static fn ($e) => $e->toArray(), $errors), "$property: $value should be valid");
  }

  /**
   * Asserts validation fails with the given code.
   */
  protected function assertCode(string $code, object $group, string $property, string $value, array $constraints = []): void {
    $errors = $group->validate($property, $value, $constraints);
    $this->assertNotEmpty($errors, "$property: $value should be invalid");
    $this->assertSame($code, $errors[0]->getCode(), "$property: $value should fail with $code");
    $this->assertSame($property, $errors[0]->getPath());
    $this->assertNotSame('', $errors[0]->getHint(), 'Errors carry a hint');
  }

}
