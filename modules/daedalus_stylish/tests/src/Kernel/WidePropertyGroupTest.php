<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generated wide-tier property group surface.
 *
 * The wide tier (data/wide-properties.json read by WidePropertyGroupDeriver
 * into one group per CSS spec area) grows the recognized-property
 * vocabulary to every standard CSS property while changing nothing about
 * the spine's posture: curated groups keep precedence where they overlap,
 * strict mode stays declared-groups-only (a strict site opens a wide-tier
 * group by declaring it, exactly like a curated one), and 'any'-typed
 * definitions still pass through the value-hygiene and script-scheme
 * gates. The guide in play comes from
 * daedalus_stylish_wide_guide_test: sections declare wide:filter_effects
 * (unconstrained) and wide:scroll_snap (scroll-margin palette-locked).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class WidePropertyGroupTest extends KernelTestBase {

  use DesignSystemActivationTrait;
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
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_wide_guide_test',
  ];

  /**
   * The validator under test.
   */
  protected StyleMapValidator $validator;

  /**
   * A valid uuid to build keys with.
   */
  protected string $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Activating a design system (the enforcement door) recompiles the
    // delivery files, which read the four material storages.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Collects error codes keyed by path suffix.
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * Open site: wide-tier properties validate through the one spine.
   */
  public function testWideTierValidation(): void {
    // An 'any' definition: name membership plus hygiene, grammar left to
    // the client and the browser.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['backdrop-filter' => 'blur(4px)']],
    ]);
    $this->assertSame([], $errors);

    // Typed wide definitions run the full typed machinery: a keyword list
    // accepts combinations, a dimension list validates per component and
    // rejects garbage.
    $errors = $this->validator->validate([
      "block:{$this->uuid}" => [
        'default' => [
          'overscroll-behavior' => 'contain auto',
          'scroll-margin' => '1rem, 2rem',
        ],
      ],
    ]);
    $this->assertSame([], $errors);
    $codes = $this->codes($this->validator->validate([
      "block:{$this->uuid}" => ['default' => ['scroll-margin' => 'far']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:{$this->uuid}.default.scroll-margin"]);

    // Guide constraints bind on generated definitions like on curated ones.
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['scroll-margin' => '2rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_NOT_ALLOWED'], $codes["container:{$this->uuid}.default.scroll-margin"]);
    $this->assertSame([], $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['scroll-margin' => '1rem']],
    ]));

    // Truly unrecognized names still reject.
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['bling' => 'lots']],
    ]));
    $this->assertSame(['INVALID_STYLE_PROPERTY'], $codes["container:{$this->uuid}.default.bling"]);
  }

  /**
   * Open mode demotes the guide's allowed lists to offer-shaping.
   *
   * The constraint that binds on guarded sites (see
   * testWideTierValidation()) stops binding; recognition and grammar
   * stay the whole law.
   */
  public function testOpenModeDemotesAllowedConstraint(): void {
    $this->enforce('open');
    // A grammatical value outside the allowed list passes.
    $this->assertSame([], $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['scroll-margin' => '2rem']],
    ]));
    // Grammar still rejects, and unrecognized names still reject.
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['scroll-margin' => 'far', 'bling' => 'lots']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["container:{$this->uuid}.default.scroll-margin"]);
    $this->assertSame(['INVALID_STYLE_PROPERTY'], $codes["container:{$this->uuid}.default.bling"]);
  }

  /**
   * The 'any' tier still runs the hygiene and script-scheme gates.
   */
  public function testAnyTierHygieneEnforced(): void {
    $key = "container:{$this->uuid}";
    // Value hygiene: no declarations or markup smuggled into a value.
    $codes = $this->codes($this->validator->validate([
      $key => ['default' => ['backdrop-filter' => 'blur(4px); background: url(evil)']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["$key.default.backdrop-filter"]);
    // Script schemes and expression() reject; these values reach a <style>
    // element, so the scan is the enforcement, not the grammar.
    $codes = $this->codes($this->validator->validate([
      $key => ['default' => ['transform' => 'expression(alert(1))']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["$key.default.transform"]);
    $codes = $this->codes($this->validator->validate([
      $key => ['default' => ['backdrop-filter' => 'url(javascript:alert(1))']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["$key.default.backdrop-filter"]);
    // A safe value the server cannot grammar-match passes: ungrammatical
    // is inert in the browser, never unsafe.
    $this->assertSame([], $this->validator->validate([
      $key => ['default' => ['transform' => 'translateX(10px) rotate(3deg)']],
    ]));
  }

  /**
   * Curated ownership beats the wide tier for a shadowed property.
   *
   * The Motion group curates animation-* and transition-* (weight 80)
   * away from the wide Animations and Transitions derivatives (weight
   * 100, first in (weight, id) order wins): the curated definition is the
   * one that validates (animation-name is a typed keyframes name where the
   * wide copy was an 'any' pass-through), and the shadowed wide copies
   * stay in the data file without effect. No exclusion step exists; this
   * pins the precedence for the one pair whose grammar changed.
   */
  public function testCuratedBeatsWide(): void {
    $manager = $this->container->get('plugin.manager.daedalus_stylish_property_group');
    $this->assertSame('motion', $manager->getGroupIdForProperty('animation-name'));
    $this->assertSame('motion', $manager->getGroupIdForProperty('transition-duration'));
    $this->assertArrayHasKey('animation-name', $manager->createInstance('wide:animations')->getProperties());
    $this->assertSame('any', $manager->createInstance('wide:animations')->getProperties()['animation-name']['type']);
    // The trigger shorthands too: the wide tier carries them (mdn-data's
    // draft snapshot), the curated Motion group owns them.
    $this->assertArrayHasKey('animation-trigger', $manager->createInstance('wide:animations')->getProperties());
    $this->assertSame('motion', $manager->getGroupIdForProperty('animation-trigger'));
    $this->assertSame('motion', $manager->getGroupIdForProperty('timeline-trigger'));
    // Effects takes transform from the wide Transforms derivative and
    // opacity from the wide Color one; the shadowed copies stay in the
    // data file without effect.
    $this->assertArrayHasKey('transform', $manager->createInstance('wide:transforms')->getProperties());
    $this->assertArrayHasKey('opacity', $manager->createInstance('wide:color')->getProperties());
    $this->assertSame('effects', $manager->getGroupIdForProperty('transform'));
    $this->assertSame('effects', $manager->getGroupIdForProperty('opacity'));
    // Border curates the four outline longhands away from the wide Basic
    // User Interface derivative; the shadowed copies stay in the data file
    // and the `outline` shorthand stays wide beside `border`.
    $wide_ui = $manager->createInstance('wide:basic_user_interface')->getProperties();
    foreach (['outline-width', 'outline-style', 'outline-color', 'outline-offset'] as $name) {
      $this->assertArrayHasKey($name, $wide_ui);
      $this->assertSame('border', $manager->getGroupIdForProperty($name));
    }
    $this->assertSame('wide:basic_user_interface', $manager->getGroupIdForProperty('outline'));
    // GeneratedContent curates `content` (the existence switch of a
    // generated box, closed to `none | ""`) away from the wide Generated
    // Content derivative, whose 'any' copy would have admitted every
    // string; `quotes` stays wide beside it. The curated group is
    // pseudo-only: granted at every pseudo-element landing and nowhere
    // else, so it is never declared on a real box.
    $wide_content = $manager->createInstance('wide:generated_content')->getProperties();
    $this->assertArrayHasKey('content', $wide_content);
    $this->assertSame('any', $wide_content['content']['type']);
    $this->assertSame('generated_content', $manager->getGroupIdForProperty('content'));
    $this->assertSame('wide:generated_content', $manager->getGroupIdForProperty('quotes'));
    $this->assertSame(['generated_content'], $manager->getPseudoGroups());
    $this->assertTrue($manager->isPseudoOnly('generated_content'));
    $this->assertFalse($manager->isPseudoOnly('wide:generated_content'));
    $this->assertFalse($manager->isPseudoOnly('background'));
    $this->assertFalse($manager->isPseudoOnly('nope'));

    // The curated grammar is in force: the wide 'any' copy would have
    // passed a value that is not a keyframes name.
    $codes = $this->codes($this->validator->validate([
      "block:{$this->uuid}" => ['default' => ['animation-name' => '2fast']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:{$this->uuid}.default.animation-name"]);
    $this->assertSame([], $this->validator->validate([
      "block:{$this->uuid}" => [
        'default' => [
          'animation-name' => 'motion-fade_in, none',
          'animation-fill-mode' => 'forwards, both',
          'transition-duration' => '150ms, 2s',
          'timeline-trigger' => '--in-view view() entry 20%',
          'animation-trigger' => '--in-view play-once',
        ],
      ],
    ]));
    // Opacity's 0..1 rides the curated definition, where the wide copy
    // (a signed dimension|number) would have passed 1.5.
    $codes = $this->codes($this->validator->validate([
      "block:{$this->uuid}" => ['default' => ['opacity' => '1.5']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["block:{$this->uuid}.default.opacity"]);
    $this->assertSame([], $this->validator->validate([
      "block:{$this->uuid}" => ['default' => ['opacity' => '0.5', 'transform' => 'translateY(-4px)']],
    ]));
    // The curated content grammar is in force where the wide copy would
    // have passed hygiene: a string is refused on the box, and content on
    // a real box is refused outright (the wide copy would have admitted
    // it as any recognized property).
    $codes = $this->codes($this->validator->validate([
      "block:{$this->uuid}::&::before" => ['default' => ['content' => '"hi"']],
      "block:{$this->uuid}" => ['default' => ['content' => '""']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:{$this->uuid}::&::before.default.content"]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["block:{$this->uuid}.default.content"]);
    $this->assertSame([], $this->validator->validate([
      "container:{$this->uuid}::&::before" => ['default' => ['content' => '""', 'backdrop-filter' => 'blur(4px)', 'scroll-margin' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}::&::before" => ['default' => ['scroll-margin' => '2rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_NOT_ALLOWED'], $codes["container:{$this->uuid}::&::before.default.scroll-margin"], 'The derived box carries the host\'s wide-tier constraint.');
    // The curated outline grammar is in force: the wide copy typed
    // outline-width as signed, the curated one is unsigned; the offset is
    // signed on both, and the shorthand still validates as wide 'any'.
    $codes = $this->codes($this->validator->validate([
      "block:{$this->uuid}" => ['default' => ['outline-width' => '-1px', 'outline-style' => 'wavy']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:{$this->uuid}.default.outline-width"]);
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:{$this->uuid}.default.outline-style"]);
    $this->assertSame([], $this->validator->validate([
      "block:{$this->uuid}" => [
        'default' => [
          'outline-width' => 'thin',
          'outline-style' => 'auto',
          'outline-color' => 'currentColor',
          'outline-offset' => '-2px',
          'outline' => '2px solid blue',
        ],
      ],
    ]));
  }

  /**
   * Strict mode is unchanged: declared groups only, wide tier included.
   */
  public function testStrictModeUnchanged(): void {
    $this->enforce('strict');

    // The section declares wide:filter_effects, so its properties are open.
    $this->assertSame([], $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['backdrop-filter' => 'blur(4px)']],
    ]));

    // A recognized property whose group is NOT declared still refuses —
    // wide-tier recognition does not widen a strict site.
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['font-size' => '1.5rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["container:{$this->uuid}.default.font-size"]);

    // An element declaring nothing refuses wide-tier properties too.
    $codes = $this->codes($this->validator->validate([
      "entity:{$this->uuid}" => ['default' => ['backdrop-filter' => 'blur(4px)']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["entity:{$this->uuid}.default.backdrop-filter"]);

    // Unrecognized stays its own error.
    $codes = $this->codes($this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['bling' => 'lots']],
    ]));
    $this->assertSame(['INVALID_STYLE_PROPERTY'], $codes["container:{$this->uuid}.default.bling"]);
  }

}
