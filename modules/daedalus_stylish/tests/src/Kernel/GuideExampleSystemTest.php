<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the guide_example package as the strict reference design system.
 *
 * daedalus_stylish_guide_example packages the guide_example design system: the
 * unit as install config (strict, its own skin, the module as package),
 * a skin minting every scale the layered guide declares, a guide whose
 * site section carries only deltas over the suite's bindings, per-block-
 * type and per-layout files, and variants, swatches and animations as
 * members. The class proves the package the way a site meets it:
 * installed and activated, the guide builds clean under strict, every
 * property the suite or the package binds resolves to a scale the skin
 * mints (the bindings define the minimum skin, with no fallback), the
 * skin's own defaults validate under the site's law, the activation
 * lands every shipped member with nothing failed, and every shipped
 * variant conforms under the law it ships (the roster offers it). The
 * suite's literal-timed entrance and hover variants are withheld under
 * this system by construction (a strict site whose timing is law
 * refuses a literal duration); the package's on-guide twins stand in.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class GuideExampleSystemTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * The module packaging the guide_example design system.
   */
  protected const PACKAGE = 'daedalus_stylish_guide_example';

  /**
   * The design system the package ships.
   */
  protected const SYSTEM = 'guide_example';

  /**
   * The suite's globals a strict timing law withholds under this system.
   *
   * Each carries a literal duration or easing (so it resolves on any
   * skin), or the transition shorthand the package withdraws; the
   * package ships its on-guide twins.
   */
  protected const SUITE_LITERAL_MOTION = [
    'block_fade_in',
    'block_lift',
    'block_rise',
    'block_scale_in',
    'container_fade_in',
    'container_lift',
    'container_rise',
    'container_scale_in',
  ];

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
   * The site is met in the order a real one is built, and the order the
   * law scenarios take: the recipe's open reference (olivero) is the
   * active system while the suite's own globals (daedalus_stylish's and
   * daedalus_stylish_layouts' variants and animations) land, since a site always
   * has a skin and the suite's bindings make a skinless site refuse
   * every token reference; then the package is enabled after the schemas
   * exist and the site switches to its system the way a recipe's
   * activation action does it: the activation syncs the package under
   * its own law, which is what lands its members (the importers defer a
   * package whole while its system is inactive).
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
    $this->enableModules(['daedalus_stylish_olivero_skin']);
    $this->installConfig(['daedalus_stylish_olivero_skin']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('olivero');
    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->enableModules([self::PACKAGE]);
    $this->installConfig([self::PACKAGE]);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate(self::SYSTEM);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->styleGuide->resetCache();
  }

  /**
   * The shipped definitions of one kind, as the package's file spells them.
   *
   * @return array<string, array>
   *   Machine name => definition.
   */
  protected function shipped(string $kind): array {
    $path = $this->container->get('extension.list.module')->getPath(self::PACKAGE);
    return Yaml::decode(file_get_contents(sprintf('%s/%s.daedalus_stylish.%s.yml', $path, self::PACKAGE, $kind)));
  }

  /**
   * The package activates as the strict reference and its guide builds.
   */
  public function testActivatedSystemBuildsUnderStrict(): void {
    $this->assertSame(self::SYSTEM, $this->container->get(ActiveDesignSystem::SERVICE_ID)->activeId(), 'the package\'s system is the active one');
    $this->assertSame('strict', $this->styleGuide->enforcement(), 'the guide_example system ships enforcement: strict');
    $this->assertTrue($this->styleGuide->propertiesClosed());
    $this->assertSame(self::SYSTEM, $this->container->get('daedalus_stylish.skin_manager')->activeSkinName(), 'the system\'s default skin is the package\'s skin');

    try {
      $definitions = $this->styleGuide->getDefinitions(self::THEME);
    }
    catch (StyleGuideException $e) {
      $this->fail('the layered guide builds clean with the package active: ' . $e->getMessage());
    }
    $scales = array_keys($definitions['site']['token_scales']);
    foreach (['color', 'font', 'text', 'weight', 'leading', 'tracking', 'space', 'radius', 'transition', 'layout', 'shadow', 'duration', 'easing', 'stroke'] as $scale) {
      $this->assertContains($scale, $scales, "the guide declares the $scale scale");
    }

    // The package's files reach the guide: the text block's button part
    // and its axis, the image block's birth default, the layout's
    // depth-qualified surface and rhythm defaults, the instances lane's
    // in-body ramp, and the extra prose tags.
    $text = $this->styleGuide->getBlockType('text', self::THEME);
    $this->assertSame(['animation', 'hover', 'button'], array_map(static fn (array|string $axis): string => is_array($axis) ? array_key_first($axis) : $axis, $text['variant_axes']), 'the text block re-lists the universal axes beside its button axis');
    $this->assertSame(['caption', 'body', 'title-3'], $text['elements']['.text-block-link']['property_groups']['typography']['constraints']['font-size']['stops']);
    $this->assertSame('.text-block-link', $text['controls']['button_fill']['element']);
    $this->assertSame(['style' => 'guide_image_rounded'], $this->styleGuide->getBlockType('image', self::THEME)['default_variants']);
    $this->assertFalse($this->styleGuide->getBlockType('formatted_text', self::THEME)['controls']['position']);
    $layout = $this->styleGuide->getLayout('daedalus_stylish_container', self::THEME);
    $this->assertSame('container_site', $layout['default_variants']['width'], 'the suite\'s width default stands beside the package\'s');
    $this->assertSame(['variant' => 'guide_band_plain', 'depth' => 'root'], $layout['default_variants']['surface']);
    $this->assertSame(['variant' => 'guide_rhythm_standard', 'depth' => 'root'], $layout['default_variants']['rhythm']);
    $this->assertSame(['title-2', 'title-3', 'body', 'caption'], $this->styleGuide->getInstances(self::THEME)['.s-*']['property_groups']['typography']['constraints']['font-size']['stops']);
    foreach (['strong', 'em', 'small', 'hr', 'figure', 'figcaption'] as $tag) {
      $this->assertNotNull($this->styleGuide->baseTagElement($tag, self::THEME), "the package opens the $tag prose tag");
    }
  }

  /**
   * Every bound property resolves to a scale the package's skin mints.
   *
   * The suite's seven bindings and the package's deltas are one served
   * map; each block's scale must carry at least one minted token on the
   * active skin, since a bound scale minting nothing is a closed empty
   * row with no fallback by design. The package's stops merge over the
   * suite's blocks and its shorthand bindings reach their families.
   */
  public function testBoundPropertiesResolveToMintedScales(): void {
    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $this->assertNotNull($skin);
    $tokens = $skin->getTokens();
    foreach ($this->styleGuide->getTokenScales(self::THEME) as $scale => $declaration) {
      $this->assertNotEmpty($tokens[$scale] ?? [], "the skin mints the declared $scale scale");
    }

    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    $bound = [];
    foreach ($served as $property => $block) {
      if (isset($block['scale'])) {
        $bound[] = $property;
        $this->assertNotEmpty($tokens[$block['scale']] ?? [], "$property binds to the {$block['scale']} scale, which the skin mints");
        foreach ($block['stops'] ?? [] as $stop) {
          $this->assertArrayHasKey($stop, $tokens[$block['scale']], "the $property stop $stop is a minted {$block['scale']} token");
        }
      }
    }
    foreach (['font-family', 'font-size', 'font-weight', 'line-height', 'padding', 'gap', 'border-radius'] as $property) {
      $this->assertContains($property, $bound, "the suite's $property binding is served under the package");
    }
    foreach (['letter-spacing', 'color', 'border-color', 'margin', 'border-width', 'box-shadow', 'transition-duration', 'animation-duration', 'transition-timing-function', 'animation-timing-function', 'max-width'] as $property) {
      $this->assertContains($property, $bound, "the package's $property binding is served");
    }

    $stops = ['2', '4', '6', '8', '12', '16'];
    $this->assertSame(['scale' => 'space', 'allowed' => ['0'], 'stops' => $stops], $served['padding'], 'the package\'s stops merge over the suite\'s scale and allowed literal');
    $this->assertSame($served['padding'], $served['padding-left'], 'the merged block reaches the family\'s sides');
    $this->assertSame(['scale' => 'text', 'stops' => ['display', 'title-1', 'title-2', 'title-3', 'body', 'caption']], $served['font-size']);
    $this->assertSame(['scale' => 'space', 'allowed' => ['0', 'auto'], 'stops' => $stops], $served['margin-top'], 'the package\'s margin binding expands to its sides');
    $this->assertSame(['scale' => 'stroke', 'allowed' => ['0']], $served['border-left-width'], 'the stroke binding expands to its sides');
    $this->assertSame(['scale' => 'color', 'allowed' => ['transparent', 'currentColor']], $served['border-top-color'], 'the border-color binding expands to its sides');
    $this->assertSame(['allowed' => ['1', '2', '3', 'infinite']], $served['animation-iteration-count'], 'a bare allowed list is a closed value space');
    $this->assertSame(['min' => '0', 'max' => '10'], $served['z-index']);
    $this->assertArrayNotHasKey('background-color', $served, 'backgrounds stay unbound so swatches stay legal');
    $this->assertArrayNotHasKey('opacity', $served, 'opacity stays unbound so the entrances\' opacity 0 steps validate');

    $defaults = $this->validator->validateDefaults($skin->getDefaults(), ['theme' => self::THEME, 'skin_tokens' => $tokens]);
    $this->assertSame([], array_map(static fn ($error): string => $error->getPath() . ' [' . $error->getCode() . ']: ' . $error->getMessage(), $defaults), 'the skin\'s own defaults validate under the site\'s strict law');
  }

  /**
   * The activation lands every shipped member, and every variant conforms.
   *
   * The activation in setUp synced the package; a sync by hand answers
   * unchanged for every shipped definition of every kind with nothing
   * failed and nothing deferred. Every shipped variant is offered by the
   * roster under the law it ships, so none is withheld; the only withheld
   * variants on the site are the suite's literal-timed globals.
   */
  public function testShippedMembersLandAndConform(): void {
    $sync = $this->container->get('daedalus_stylish.shipped_sync');
    assert($sync instanceof ShippedSync);
    $report = $sync->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $shipped = [];
    foreach (['animations' => 'animation', 'swatches' => 'swatch', 'skins' => 'skin', 'variants' => 'variant'] as $file => $kind) {
      $names = array_keys($this->shipped($file));
      $shipped[$kind] = $names;
      $this->assertNotEmpty($names, "the package ships $file");
      $outcomes = $report->report($kind)->outcomes()[self::PACKAGE] ?? [];
      $this->assertSame(['daedalus_stylish_olivero_skin' => 'olivero'], $report->report($kind)->deferred(), "$kind: the package syncs under its own active law while the other reference system's package is deferred whole");
      foreach ($names as $name) {
        $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $outcomes[$name] ?? NULL, "$kind $name landed on activation and is unchanged on a second sync");
        $storage = $this->container->get('entity_type.manager')->getStorage("daedalus_$kind");
        $entities = $storage->loadByProperties(['machine_name' => $name]);
        $entity = reset($entities);
        $this->assertNotFalse($entity, "$kind $name exists");
        $this->assertSame(self::SYSTEM, $entity->getDesignSystem(), "$kind $name is a member of the package's system");
        $this->assertSame(self::PACKAGE, $entity->getProvider());
      }
    }

    $conformance = $this->container->get('daedalus_stylish.variant_conformance');
    assert($conformance instanceof VariantConformance);
    $withheld = $conformance->withheld();
    $refused = [];
    foreach (array_intersect_key($withheld, array_flip($shipped['variant'])) as $name => $errors) {
      $refused[$name] = array_map(static fn ($error): string => $error->getPath() . ' [' . $error->getCode() . ']: ' . $error->getMessage(), $errors);
    }
    $this->assertSame([], $refused, 'every shipped variant conforms under the strict law it ships');
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    foreach ($shipped['variant'] as $name) {
      $this->assertTrue($roster->has(MaterialKind::Variant, $name), "the roster offers $name");
    }
    foreach ($shipped['swatch'] as $name) {
      $this->assertTrue($roster->has(MaterialKind::Swatch, $name), "the roster offers $name");
    }
    foreach ($shipped['animation'] as $name) {
      $this->assertTrue($roster->has(MaterialKind::Animation, $name), "the roster offers $name");
    }
    foreach (['row', 'container_site', 'container_full'] as $name) {
      $this->assertTrue($roster->has(MaterialKind::Variant, $name), "the suite's $name conforms under the package's law (the layout's width default rides it)");
    }
    $withheld_names = array_keys($withheld);
    sort($withheld_names);
    $this->assertSame(self::SUITE_LITERAL_MOTION, $withheld_names, 'the only withheld variants are the suite\'s literal-timed motion globals, whose on-guide twins the package ships');
  }

}
