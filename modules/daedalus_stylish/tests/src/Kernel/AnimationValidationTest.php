<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the animation validation spine.
 *
 * Three seams, one engine:
 * - validateAnimationSteps(): the steps grammar every animation write path
 *   converges on: an offset-keyed map (from/to/N%, one canonical spelling
 *   per offset, at least one step), each declaration through the universal
 *   vocabulary (every recognized property declarable, no element to be
 *   undeclared on), animation-* refused inside a keyframe except the
 *   per-step easing, media refused, var() references resolving as
 *   anywhere.
 * - The reference check: a keyframes name in animation-name or the
 *   animation shorthand must spell a PUBLISHED animation on strict sites
 *   (STYLE_ANIMATION_NOT_PUBLISHED, the swatch check's sibling; a plain
 *   ident is a theme keyframes the closed world does not curate), and
 *   passes on permissive ones (inert, never unsafe).
 * - The grants: motion reaches blocks and prose links through daedalus_stylish's
 *   own declarations, so the reference check has somewhere to run.
 *
 * The roster is daedalus_stylish's own provider's: a minted daedalus_animation
 * (drift, spelled motion-drift), the active skin's color/accent token
 * and a published swatch (lime_green) are the validator's universe.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationValidationTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  protected const UUID = '11111111-1111-1111-1111-111111111111';

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
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_animation');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'entity' => [
        'property_groups' => [
          'background' => [],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    // The material universe: one published animation, one token, one
    // swatch, all read into the roster by daedalus_stylish's own provider.
    $animation = DaedalusStylishAnimation::create(['label' => 'Drift', 'machine_name' => 'drift']);
    $animation->setSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);
    $animation->save();
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setTokens(['color' => ['accent' => '#1a1a2e']])->save();
    $this->activateSkin($skin);
    $swatch = DaedalusStylishSwatch::create(['label' => 'Lime green', 'kind' => 'color']);
    $swatch->setSwatchValue('#4dff8c');
    $swatch->save();
  }

  /**
   * Validates animation steps.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateSteps(mixed $steps): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validateAnimationSteps($steps, ['theme' => self::THEME]);
  }

  /**
   * Validates a block style map carrying one default declaration.
   */
  protected function validateBlockStyle(string $property, string $value): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validate([
      'block:' . self::UUID => ['default' => [$property => $value]],
    ], ['theme' => self::THEME]);
  }

  /**
   * Asserts one error with the expected code and path.
   */
  protected function assertSingleError(array $errors, string $code, ?string $path = NULL): BlueprintError {
    $this->assertCount(1, $errors, implode(' | ', array_map(static fn (BlueprintError $e) => $e->getCode() . '@' . $e->getPath(), $errors)));
    $this->assertSame($code, $errors[0]->getCode());
    if ($path !== NULL) {
      $this->assertSame($path, $errors[0]->getPath());
    }
    return $errors[0];
  }

  /**
   * The steps shape: an offset-keyed map with one canonical offset each.
   */
  public function testStepsShape(): void {
    $this->assertSame([], $this->validateSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]));
    // Percentages, an empty keyframe, a wide-tier property (the universal
    // vocabulary) and the per-step easing all pass.
    $this->assertSame([], $this->validateSteps([
      '0%' => ['opacity' => '0', 'transform' => 'translateY(1rem)', 'animation-timing-function' => 'ease-out'],
      '50%' => [],
      '100%' => ['opacity' => '1', 'transform' => 'none'],
    ]));

    // Not a keyframes map: a string, an empty map, a list.
    $this->assertSingleError($this->validateSteps('fade'), 'INVALID_ANIMATION_STEPS', 'steps');
    $this->assertSingleError($this->validateSteps([]), 'INVALID_ANIMATION_STEPS', 'steps');
    $this->assertSingleError($this->validateSteps([['opacity' => '0']]), 'INVALID_ANIMATION_STEPS', 'steps');
    // A step that is not a property map.
    $this->assertSingleError($this->validateSteps(['0%' => 'opacity: 0']), 'INVALID_ANIMATION_STEPS', 'steps.0%');
    $this->assertSingleError($this->validateSteps(['0%' => ['opacity', '0']]), 'INVALID_ANIMATION_STEPS', 'steps.0%');

    // Offsets: percentages in range or from/to; nothing else.
    $this->assertSingleError($this->validateSteps(['50' => ['opacity' => '0']]), 'INVALID_ANIMATION_OFFSET', 'steps.50');
    $this->assertSingleError($this->validateSteps(['150%' => ['opacity' => '0']]), 'INVALID_ANIMATION_OFFSET', 'steps.150%');
    $this->assertSingleError($this->validateSteps(['middle' => ['opacity' => '0']]), 'INVALID_ANIMATION_OFFSET', 'steps.middle');
    $this->assertSingleError($this->validateSteps(['0%, 100%' => ['opacity' => '0']]), 'INVALID_ANIMATION_OFFSET', 'steps.0%, 100%');
    // Two spellings of one offset collide in storage, so they are refused.
    $error = $this->assertSingleError($this->validateSteps(['from' => ['opacity' => '0'], '0%' => ['opacity' => '1']]), 'DUPLICATE_ANIMATION_OFFSET', 'steps.0%');
    $this->assertSame('0%', $error->getContext()['canonical']);
  }

  /**
   * Step declarations: the property spine, plus the keyframe's own refusals.
   */
  public function testStepDeclarations(): void {
    $this->assertSingleError($this->validateSteps(['0%' => ['opacity' => 'lots']]), 'INVALID_STYLE_VALUE', 'steps.0%.opacity');
    $this->assertSingleError($this->validateSteps(['0%' => ['bling' => 'lots']]), 'INVALID_STYLE_PROPERTY', 'steps.0%.bling');
    // Value hygiene guards the compiled at-rule.
    $this->assertSingleError($this->validateSteps(['0%' => ['opacity' => '0; color: red']]), 'INVALID_STYLE_VALUE', 'steps.0%.opacity');

    // animation-* does not apply inside a keyframe, the shorthand
    // included; animation-timing-function alone is a keyframe property.
    // The trigger pair (the WHEN of the animation) is the wearer's too.
    foreach ([
      'animation',
      'animation-name',
      'animation-duration',
      'animation-fill-mode',
      'animation-trigger',
      'timeline-trigger',
    ] as $property) {
      $this->assertSingleError($this->validateSteps(['0%' => [$property => 'none']]), 'INVALID_ANIMATION_STEP_PROPERTY', "steps.0%.$property");
    }
    $this->assertSame([], $this->validateSteps(['0%' => ['animation-timing-function' => 'steps(4, end)']]));

    // Media never rides a keyframe: no property definition resolves it.
    $this->assertSingleError($this->validateSteps(['0%' => ['background-image' => "url('media:" . self::UUID . "')"]]), 'INVALID_STYLE_VALUE', 'steps.0%.background-image');
  }

  /**
   * Step values resolve var() as anywhere; strict opens every property.
   */
  public function testStepReferencesStrict(): void {
    // Permissive: unresolved references are inert.
    $this->assertSame([], $this->validateSteps(['0%' => ['color' => 'var(--color-missing)']]));

    $this->enforce('strict');
    $this->assertSame([], $this->validateSteps([
      '0%' => ['color' => 'var(--color-accent)', 'background-color' => 'var(--swatch-lime_green)'],
    ]));
    $this->assertSingleError($this->validateSteps(['0%' => ['color' => 'var(--color-missing)']]), 'STYLE_TOKEN_NOT_MINTED', 'steps.0%.color');
    $this->assertSingleError($this->validateSteps(['0%' => ['background-color' => 'var(--swatch-missing)']]), 'STYLE_SWATCH_NOT_PUBLISHED', 'steps.0%.background-color');
    // The closed world closes ELEMENTS; a keyframe has none, so every
    // recognized property stays declarable (no STYLE_PROPERTY_NOT_DECLARED).
    $this->assertSame([], $this->validateSteps([
      '0%' => ['transform' => 'translateY(1rem)', 'font-size' => '2rem', 'opacity' => '0'],
    ]));
  }

  /**
   * Style values: keyframes names error strict, pass permissive.
   */
  public function testStyleValueReferenceCheck(): void {
    // Permissive: unresolved names are inert, never unsafe, whether a
    // missing site animation or a theme's own keyframes.
    $this->assertSame([], $this->validateBlockStyle('animation-name', 'motion-nope'));
    $this->assertSame([], $this->validateBlockStyle('animation-name', 'spin'));
    $this->assertSame([], $this->validateBlockStyle('animation', 'spin 1s infinite linear'));

    $this->enforce('strict');
    // The published animation resolves in every spelling position, and
    // `none` is never a reference.
    $this->assertSame([], $this->validateBlockStyle('animation-name', 'motion-drift'));
    $this->assertSame([], $this->validateBlockStyle('animation-name', 'motion-drift, none'));
    $this->assertSame([], $this->validateBlockStyle('animation-name', 'none'));
    $this->assertSame([], $this->validateBlockStyle('animation', 'motion-drift 600ms ease-out both'));
    $this->assertSame([], $this->validateBlockStyle('animation', '600ms cubic-bezier(0.4, 0, 0.2, 1) motion-drift'));
    $this->assertSame([], $this->validateBlockStyle('animation', 'none'));
    // Other motion values carry no names: a trigger's dashed ident names
    // a trigger, never a keyframes, so the pair passes strict untouched.
    $this->assertSame([], $this->validateBlockStyle('animation-duration', '600ms'));
    $this->assertSame([], $this->validateBlockStyle('timeline-trigger', '--in-view view() entry 20%'));
    $this->assertSame([], $this->validateBlockStyle('animation-trigger', '--in-view play-once'));
    $this->assertSame([], $this->validateBlockStyle('animation-trigger', 'none'));

    // A motion-{name} ident naming no published animation.
    $error = $this->assertSingleError($this->validateBlockStyle('animation-name', 'motion-nope'), 'STYLE_ANIMATION_NOT_PUBLISHED', 'block:' . self::UUID . '.default.animation-name');
    // The fixture's own animation beside the suite's shipped ones, which
    // the activation in setUp landed (every activation syncs every
    // enabled module).
    $this->assertSame(['drift', 'fade_in', 'pulse', 'rise', 'scale_in', 'spin'], $error->getContext()['published_animations']);
    // The hint pairs each published animation's spelling with its label:
    // the model patches from the corrective alone.
    $this->assertStringContainsString('motion-drift (Drift)', $error->getHint());
    // A plain ident: a theme keyframes the closed world does not curate.
    $error = $this->assertSingleError($this->validateBlockStyle('animation-name', 'spin'), 'STYLE_ANIMATION_NOT_PUBLISHED');
    $this->assertStringContainsString('not a site animation', $error->getMessage());
    $this->assertSingleError($this->validateBlockStyle('animation', 'spin 1s infinite linear'), 'STYLE_ANIMATION_NOT_PUBLISHED');
    // One error per unresolved name, none for the resolved one.
    $errors = $this->validateBlockStyle('animation-name', 'motion-drift, motion-nope, spin');
    $this->assertCount(2, $errors);

    // The closed world still closes elements: motion is granted on blocks
    // (daedalus_stylish's own declaration), not on an entity declaring only
    // background.
    $this->assertSingleError($this->container->get('daedalus_stylish.style_map_validator')->validate([
      'entity:' . self::UUID => ['default' => ['animation-name' => 'motion-drift']],
    ], ['theme' => self::THEME]), 'STYLE_PROPERTY_NOT_DECLARED');
  }

  /**
   * The grants: motion reaches blocks and prose links.
   */
  public function testGrants(): void {
    // The grants under test are daedalus_stylish's own site declaration (the base
    // vocabulary), which the control fixture's injected site replaces;
    // withdraw the injection so the shipped declaration layers as on a
    // real site.
    $this->container->get('state')->delete('daedalus_stylish_control_test.site');
    $guide = $this->container->get('daedalus_stylish.style_guide');
    $guide->resetCache();
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $roster = $this->container->get('daedalus_stylish.vocabulary_roster');
    $this->assertContains('motion', $roster->build(Address::kind('block'), self::THEME)->groups());
    // Effects (transform, opacity) is granted beside motion: the hover
    // axis's Lift moves with a transform, an entrance override fades.
    $this->assertContains('effects', $roster->build(Address::kind('block'), self::THEME)->groups());
    // Links alone among the prose tags carry motion (the one prose tag
    // with a state worth transitioning); paragraphs keep their box and
    // type groups.
    $this->assertArrayHasKey('motion', $guide->baseTagElement('a', self::THEME)['property_groups']);
    $this->assertArrayNotHasKey('motion', $guide->baseTagElement('p', self::THEME)['property_groups']);
    // The skin lane and the element tag tier read the same declaration.
    $this->assertSame([], $validator->validateDefaults([
      'base:a' => ['' => ['default' => ['transition' => 'color 150ms ease-out'], 'hover' => ['color' => '#e94560']]],
    ], ['theme' => self::THEME]));
    $this->enforce('strict');
    $this->assertSame([], $validator->validate([
      'block:' . self::UUID . '::a' => ['default' => ['transition' => 'color 150ms ease-out']],
    ], ['theme' => self::THEME]));
    $this->assertSingleError($validator->validate([
      'block:' . self::UUID . '::p' => ['default' => ['transition' => 'color 150ms ease-out']],
    ], ['theme' => self::THEME]), 'STYLE_PROPERTY_NOT_DECLARED');
  }

}
