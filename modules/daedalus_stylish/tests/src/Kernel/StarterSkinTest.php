<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the starter package as the blank canvas a site builds off.
 *
 * daedalus_stylish_starter_skin packages the starter design system: the unit as
 * install config (open, its own skin, the module as package), no guide
 * fragment (the suite's bindings are the whole offer), and one skin
 * minting a neutral placeholder token on every scale the suite declares
 * with base-tag defaults that reference tokens only. The class proves
 * the package the way a site meets it: installed and activated, the
 * system ships open and the guide builds clean with the suite's own
 * bindings served unchanged; every property the suite binds resolves
 * to a scale the skin mints with at least one token (the bindings
 * define the minimum skin, with no fallback), and the suite's globals
 * land under the starter with nothing failed (row's gutter reference
 * resolves); and the skin's own defaults validate empty under strict,
 * so a site that builds a law posture off the starter starts from a
 * conforming skin.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StarterSkinTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * The module packaging the starter design system.
   */
  protected const PACKAGE = 'daedalus_stylish_starter_skin';

  /**
   * The design system the package ships.
   */
  protected const SYSTEM = 'starter';

  /**
   * The properties the suite binds (daedalus_stylish.page_builder.yml).
   */
  protected const SUITE_BOUND = ['font-family', 'font-size', 'font-weight', 'line-height', 'padding', 'gap', 'border-radius'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'block',
    'contextual',
    'layout_discovery',
    'layout_builder',
    'user',
    'field',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  protected StyleGuide $styleGuide;

  protected StyleMapValidator $validator;

  /**
   * {@inheritdoc}
   *
   * The site is met the way a site enabling the starter meets it: the
   * package is enabled and its system activated (the activation syncs
   * the package's skin under its own law), then the suite's globals
   * (daedalus_stylish's and daedalus_stylish_layouts' variants and animations) land on a
   * site whose only skin is the starter. Nothing lands first under
   * another system: the starter ships open, so the suite's literal-timed
   * motion variants commit, and it mints the gutter token row spells.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish', 'daedalus_blueprint']);
    // The importers switch to an administrative account (shipped
    // definitions are code, not user input); give the switcher one.
    $this->setUpCurrentUser([], [], TRUE);
    $this->enableModules([self::PACKAGE]);
    $this->installConfig([self::PACKAGE]);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate(self::SYSTEM);
    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->styleGuide->resetCache();
  }

  /**
   * The package activates open, its skin lands, and the guide builds.
   *
   * With no guide fragment of its own, the served bindings are exactly
   * the suite's: no stops curation, no package binding, nothing
   * withdrawn.
   */
  public function testActivatedSystemShipsOpenAndBuilds(): void {
    $this->assertSame(self::SYSTEM, $this->container->get(ActiveDesignSystem::SERVICE_ID)->activeId(), 'the package\'s system is the active one');
    $this->assertSame('open', $this->styleGuide->enforcement(), 'the starter system ships enforcement: open');
    $this->assertFalse($this->styleGuide->propertiesClosed());
    $this->assertSame(self::SYSTEM, $this->container->get('daedalus_stylish.skin_manager')->activeSkinName(), 'the system\'s default skin is the package\'s skin');

    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $this->assertNotNull($skin, 'the starter skin landed on activation');
    $this->assertSame(self::SYSTEM, $skin->getDesignSystem(), 'the skin is a member of the package\'s system');
    $this->assertSame(self::PACKAGE, $skin->getProvider());
    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync([self::PACKAGE]);
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->report('skin')->outcomes()[self::PACKAGE][self::SYSTEM] ?? NULL, 'a second sync answers unchanged for the skin');

    try {
      $definitions = $this->styleGuide->getDefinitions(self::THEME);
    }
    catch (StyleGuideException $e) {
      $this->fail('the layered guide builds clean with the starter active: ' . $e->getMessage());
    }
    $this->assertSame(['color', 'font', 'text', 'weight', 'leading', 'tracking', 'space', 'radius', 'transition', 'layout'], array_keys($definitions['site']['token_scales']), 'the starter declares no scale of its own: the suite\'s ten are the guide\'s');

    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    $this->assertSame(['scale' => 'space', 'allowed' => ['0']], $served['padding'], 'the served padding block is the suite\'s, uncurated');
    $this->assertSame($served['padding'], $served['padding-left'], 'the suite\'s block reaches the family\'s sides');
    $this->assertSame(['scale' => 'text'], $served['font-size']);
    $this->assertArrayNotHasKey('color', $served, 'the starter binds nothing of its own: color stays a package binding');
    $this->assertArrayNotHasKey('letter-spacing', $served, 'the starter binds nothing of its own: tracking stays a package binding');
  }

  /**
   * Every bound property resolves to a scale the starter mints.
   *
   * Every declared scale carries at least one token, so no basic row is
   * an empty closed space; the served map binds the suite's seven
   * properties and each block's scale is minted.
   */
  public function testBoundPropertiesResolveToMintedScales(): void {
    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $this->assertNotNull($skin);
    $tokens = $skin->getTokens();
    foreach ($this->styleGuide->getTokenScales(self::THEME) as $scale => $declaration) {
      $this->assertNotEmpty($tokens[$scale] ?? [], "the starter mints the declared $scale scale");
    }
    foreach (['bg', 'surface', 'text', 'border', 'accent'] as $role) {
      $this->assertSame(['default', 'dark'], array_keys($tokens['color'][$role]), "the $role color role carries its dark pair");
    }
    $this->assertSame(['sans-serif', 'serif', 'mono'], array_keys($tokens['font']));
    $this->assertSame(['caption', 'body', 'lead', 'title', 'headline', 'display'], array_keys($tokens['text']));
    $this->assertSame(['sm', 'md', 'lg', 'xl', 'gutter'], array_keys($tokens['space']));
    $this->assertArrayHasKey('max-width', $tokens['layout']);

    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    $bound = [];
    foreach ($served as $property => $block) {
      if (isset($block['scale'])) {
        $bound[] = $property;
        $this->assertNotEmpty($tokens[$block['scale']] ?? [], "$property binds to the {$block['scale']} scale, which the starter mints");
      }
    }
    foreach (self::SUITE_BOUND as $property) {
      $this->assertContains($property, $bound, "the suite's $property binding is served under the starter");
    }
  }

  /**
   * The skin's defaults validate empty under strict and speak tokens only.
   *
   * The starter ships open; a site that builds a law posture off it
   * switches the system's enforcement, which is what this does, so the
   * defaults must already conform (a skin's defaults are judged by the
   * site's bindings like any style map). Every value a default carries
   * is a var() naming a minted token on its scale, the zero literal the
   * None gesture commits, or a CSS keyword: never a raw dimension or
   * color, so the whole site restyles by re-valuing tokens.
   */
  public function testDefaultsConformUnderStrictOnTokensOnly(): void {
    $this->enforce('strict');
    $this->styleGuide->resetCache();
    $this->assertSame('strict', $this->styleGuide->enforcement(), 'the starter system is switched to strict enforcement');
    $this->assertSame(self::SYSTEM, $this->container->get(ActiveDesignSystem::SERVICE_ID)->activeId(), 'the mode is written on the starter system itself');

    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $this->assertNotNull($skin);
    $tokens = $skin->getTokens();
    $defaults = $skin->getDefaults();
    foreach (['base:h1', 'base:h2', 'base:h3', 'base:h4', 'base:h5', 'base:h6', 'base:p', 'base:li', 'base:a', 'base:blockquote', 'base:code'] as $scope) {
      $this->assertArrayHasKey($scope, $defaults, "the starter carries a $scope default");
    }

    $errors = $this->validator->validateDefaults($defaults, ['theme' => self::THEME, 'skin_tokens' => $tokens]);
    $this->assertSame([], array_map(static fn (BlueprintError $error): string => $error->getPath() . ' [' . $error->getCode() . ']: ' . $error->getMessage(), $errors), 'the starter skin\'s defaults validate empty under strict law');

    $values = [];
    foreach ($defaults as $scope => $styles) {
      foreach ($styles as $relative => $entry) {
        foreach ($entry as $condition => $properties) {
          foreach ($properties as $property => $value) {
            $values["$scope$relative.$condition.$property"] = $value;
          }
        }
      }
    }
    $this->assertNotEmpty($values);
    foreach ($values as $at => $value) {
      if (preg_match('/^var\\(--([a-z]+)-([a-z0-9-]+)\\)$/', $value, $match)) {
        $this->assertArrayHasKey($match[1], $tokens, "$at references the minted {$match[1]} scale");
        $this->assertArrayHasKey($match[2], $tokens[$match[1]], "$at names a minted {$match[1]} token");
        continue;
      }
      $this->assertMatchesRegularExpression('/^(0|[a-z-]+)$/', $value, "$at is a token reference, the zero literal or a keyword, never a raw value");
    }
  }

}
