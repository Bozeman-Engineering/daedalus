<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore dispaly
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the declared defaults tier: grammar, ownership, the build check.
 *
 * A declaration may ship `defaults` for exactly the scope it declares:
 * the literal box facts its markup needs, in the skin defaults grammar.
 * Declared defaults follow markup ownership: a block type, block plugin,
 * or layout declaration defaults its root and parts; a composed kind its
 * root too; any other kind only the parts its own declaration declares;
 * the site nothing. The guide collects them per scope (declaredDefaults(),
 * kind scopes first) and validates each at build through the one defaults
 * path in the EMPTY minted universe, so a declaration names no token
 * whatever skin is active and whatever enforcement the site runs.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DeclaredDefaultsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * The theme restating one property of the module's declared default.
   */
  protected const OVERRIDE_THEME = 'daedalus_stylish_declared_defaults_test_theme';

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
    'daedalus_stylish_control_test',
    'daedalus_stylish_declared_defaults_test',
  ];

  /**
   * The style guide under test.
   */
  protected StyleGuide $styleGuide;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The validator resolves references through the materials roster,
    // whose daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * The fixture's declared defaults, as authored.
   */
  protected function imageDefaults(): array {
    return [
      '' => ['default' => ['display' => 'block', 'width' => 'fit-content']],
      '::.image-block-image' => ['default' => ['display' => 'block']],
    ];
  }

  /**
   * Enables the invalid fixture and rebuilds the guide.
   */
  protected function enableInvalidFixture(): void {
    $this->enableModules(['daedalus_stylish_declared_defaults_test_invalid']);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->styleGuide->resetCache();
  }

  /**
   * Reads the guide expecting the build to fail, returning its message.
   */
  protected function buildErrors(): string {
    try {
      $this->styleGuide->getBlockType('image', self::THEME);
    }
    catch (StyleGuideException $exception) {
      return $exception->getMessage();
    }
    $this->fail('The invalid fixture must fail the guide build.');
  }

  /**
   * Defaults are collected per declaring scope and exposed on each read.
   */
  public function testCollectedPerScope(): void {
    $this->assertSame($this->imageDefaults(), $this->styleGuide->getBlockType('image', self::THEME)['defaults']);
    $layout_defaults = [
      '' => ['default' => ['display' => 'grid'], 'narrow' => ['display' => 'block']],
      '::.band-inner' => ['default' => ['max-width' => '80rem']],
    ];
    $this->assertSame($layout_defaults, $this->styleGuide->getLayout('layout_onecol', self::THEME)['defaults']);
    $plugin_defaults = ['' => ['default' => ['display' => 'flex']]];
    $this->assertSame($plugin_defaults, $this->styleGuide->getBlockPlugin('fancy_menu', self::THEME)['defaults']);
    // A derivative resolves to its base plugin's declaration.
    $this->assertSame($plugin_defaults, $this->styleGuide->getBlockPlugin('fancy_menu:main', self::THEME)['defaults']);

    // The tier's one read: every declaring refinement by scope, in
    // delivery order (block plugins, block types, layouts).
    $this->assertSame([
      'block_plugin:fancy_menu' => $plugin_defaults,
      'block_type:image' => $this->imageDefaults(),
      'layout:layout_onecol' => $layout_defaults,
    ], $this->styleGuide->declaredDefaults(self::THEME));

    // Undeclared refinements carry no defaults: the kind underneath
    // declares none here, so the key is absent, never inherited.
    $this->assertArrayNotHasKey('defaults', $this->styleGuide->getBlockType('undeclared', self::THEME));
    $this->assertArrayNotHasKey('defaults', $this->styleGuide->getKind('block', self::THEME));
  }

  /**
   * A theme restating a module's default merges per property.
   *
   * The theme owns the markup model, so the spine's layering is the one
   * override doorway: maps merge recursively, so display changes and
   * width survives; the part entry is untouched.
   */
  public function testThemeOverrideMergesPerProperty(): void {
    $this->container->get('theme_installer')->install([self::OVERRIDE_THEME]);
    $this->styleGuide->resetCache();

    $expected = $this->imageDefaults();
    $expected['']['default']['display'] = 'inline-block';
    $this->assertSame($expected, $this->styleGuide->getBlockType('image', self::OVERRIDE_THEME)['defaults']);
    $this->assertSame($expected, $this->styleGuide->declaredDefaults(self::OVERRIDE_THEME)['block_type:image']);
    // Theme-aware: another theme still reads the module's own defaults.
    $this->assertSame($this->imageDefaults(), $this->styleGuide->declaredDefaults(self::THEME)['block_type:image']);
  }

  /**
   * A token reference fails the build whatever the site runs or wears.
   *
   * Declared defaults validate in the EMPTY minted universe on the
   * declared lane: an active skin minting the very token and an open
   * enforcement mode (where per-entity writes pass unminted references
   * through) change nothing, because build errors judge code, never data.
   */
  public function testTokenReferenceFailsTheBuild(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);
    $this->enforce('open');
    $this->enableInvalidFixture();

    $message = $this->buildErrors();
    $this->assertStringContainsString('The defaults declaration of block type "image" is invalid at "block_type:image.default.margin": "--space-4" is in the "space" scale\'s namespace', $message);
    $this->assertStringContainsString('a token-bearing default belongs on the skin\'s "block_type:image" defaults', $message);
  }

  /**
   * A slot-path key fails the build: defaults keys are element-only.
   */
  public function testSlotPathFails(): void {
    $this->enableInvalidFixture();
    $message = $this->buildErrors();
    $this->assertStringContainsString('The defaults declaration of layout "layout_onecol" is invalid at "layout:layout_onecol.container(1)": "container(1)" is a slot path', $message);
  }

  /**
   * A non-composed kind's root and the site are outside the ownership law.
   *
   * The block kind's root is whatever block template is in play, so a ''
   * key there is a misplacement naming the rule (and the skin as the
   * remedy); the site renders nothing at all.
   */
  public function testTemplatedKindRootAndSiteDeclarationsFail(): void {
    $this->enableInvalidFixture();
    $message = $this->buildErrors();
    $this->assertStringContainsString('The kind "block" declares root defaults (the \'\' key), but declared defaults follow markup ownership: a kind\'s root comes from whatever template is in play, so only a composed kind (composed: true, rendering its own root) may default it. A templated kind\'s look is the site\'s: put kind-wide root defaults on the skin\'s "kind:block" defaults.', $message);
    $this->assertStringContainsString('The site declares defaults, but declared defaults follow markup ownership and the site renders no markup of its own.', $message);
    // The refused root key is dropped before the grammar check: no second
    // error is reported for the same key.
    $this->assertStringNotContainsString('of kind "block" is invalid', $message);
  }

  /**
   * A composed kind renders its own root, so it may default it.
   *
   * The container kind declared composed carries any relative key; its
   * scope leads the tier (kind before refinement, the delivery rank the
   * stylesheet and the stamping demand follow) and rides the kind read.
   */
  public function testComposedKindRootDefaultsAreOwned(): void {
    $container_defaults = ['' => ['default' => ['display' => 'grid']]];
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => $container_defaults],
    ]);
    $this->styleGuide->resetCache();
    $this->assertSame($container_defaults, $this->styleGuide->getKind('container', self::THEME)['defaults']);
    $this->assertSame([
      'kind:container' => $container_defaults,
      'block_plugin:fancy_menu' => ['' => ['default' => ['display' => 'flex']]],
      'block_type:image' => $this->imageDefaults(),
      'layout:layout_onecol' => [
        '' => ['default' => ['display' => 'grid'], 'narrow' => ['display' => 'block']],
        '::.band-inner' => ['default' => ['max-width' => '80rem']],
      ],
    ], $this->styleGuide->declaredDefaults(self::THEME));

    // Ownership widens the KEYS, never the posture: the composed root's
    // defaults are still token-free code checked in the empty universe.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['gap' => 'var(--space-4)']]]],
    ]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/The defaults declaration of kind "container" is invalid at "kind:container.default.gap": "--space-4" is in the "space" scale\'s namespace/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Any kind may default the anatomy it declares, and only that.
   *
   * The templated entity kind declares a part a module renders under it
   * (the layout root) and defaults it; a '::.part' key for an element it
   * does not declare, or its root, is refused by the rule.
   */
  public function testKindElementDefaultsFollowItsDeclaration(): void {
    $layout_root = ['::.daedalus-layout-layout' => ['default' => ['display' => 'grid']]];
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'entity' => [
        'elements' => ['.daedalus-layout-layout' => ['label' => 'Layout', 'tag' => 'div']],
        'defaults' => $layout_root,
      ],
    ]);
    $this->styleGuide->resetCache();
    $this->assertSame($layout_root, $this->styleGuide->getKind('entity', self::THEME)['defaults']);
    $this->assertSame($layout_root, $this->styleGuide->declaredDefaults(self::THEME)['kind:entity']);
    $this->assertSame('kind:entity', array_key_first($this->styleGuide->declaredDefaults(self::THEME)));

    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'entity' => [
        'elements' => ['.daedalus-layout-layout' => ['label' => 'Layout', 'tag' => 'div']],
        'defaults' => [
          '' => ['default' => ['display' => 'grid']],
          '::.daedalus-layout-layout' => ['default' => ['display' => 'grid']],
          '::.not-declared' => ['default' => ['display' => 'grid']],
          '::.daedalus-layout-layout > *' => ['default' => ['display' => 'grid']],
        ],
      ],
    ]);
    $this->styleGuide->resetCache();
    try {
      $this->styleGuide->getKind('entity', self::THEME);
      $this->fail('A templated kind defaulting its root or an undeclared part must fail the guide build.');
    }
    catch (StyleGuideException $exception) {
      $message = $exception->getMessage();
    }
    $this->assertStringContainsString('The kind "entity" declares root defaults (the \'\' key), but declared defaults follow markup ownership', $message);
    $this->assertStringContainsString('The kind "entity" declares defaults at "::.not-declared", but declared defaults follow markup ownership: a kind that is not composed owns only the anatomy it declares', $message);
    $this->assertStringContainsString('The kind "entity" declares defaults at "::.daedalus-layout-layout > *", but declared defaults follow markup ownership', $message);
    $this->assertStringNotContainsString('"::.daedalus-layout-layout",', $message, 'The declared part\'s key is owned and passes.');
  }

  /**
   * The section is a non-empty map, or absent.
   */
  public function testNonMapDefaultsFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', ['media' => ['defaults' => []]]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/The defaults declaration of block type "media" must be a non-empty map of element-relative styles/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * The lane's property posture is fixed by code, not by enforcement.
   *
   * Strict enforcement closes the property world for per-entity writes;
   * a declaration shipping a root box fact the block type grants to no
   * editor (display on a root with no layout group) still builds, and an
   * unrecognized property still fails: recognition and grammar are the
   * code-vs-code facts.
   */
  public function testPostureIsEnforcementIndependent(): void {
    $this->enforce('strict');
    $this->styleGuide->resetCache();
    $this->assertSame($this->imageDefaults(), $this->styleGuide->getBlockType('image', self::THEME)['defaults']);

    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'media' => ['defaults' => ['' => ['default' => ['dispaly' => 'block']]]],
    ]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/The defaults declaration of block type "media" is invalid at "block_type:media.default.dispaly": "dispaly" is not a recognized style property/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

}
