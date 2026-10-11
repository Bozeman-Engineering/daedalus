<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests default_variants declarations: grammar validation at guide build.
 *
 * The default_variants maps axis => variant machine name per scope, one default
 * per axis of the scope's vocabulary; an entry may instead be the
 * depth-qualified map {variant: name, depth: root} (container-family only),
 * scoping the default to top-level sections while the axis stays offered at
 * every depth. The guide build validates the grammar
 * and the axis vocabulary loudly (like variant_axes and controls); whether
 * each named variant exists, is published, and matches the scope and axis
 * is checked at placement time by ElementStylesSubscriber, degrading
 * silently — the build cannot know what variants an environment holds. The
 * pre-axes singular default_variant key is dead grammar and fails the
 * build with a corrective.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideDefaultsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'user',
    'field',
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
    'daedalus_stylish_control_test',
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
    // The dialect catalog reads the materials roster, whose daedalus_stylish
    // provider reads all four storages.
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * Birth defaults and the skin's dressed tags reach the dialect catalog.
   *
   * The catalog's birthDefaults() answers per placement scope (the
   * container layout with the kind's defaults layered under, each
   * declared block type), keyed by the attribute the element's tag
   * speaks and resolved for a top-level placement (a root-qualified
   * default counts); dressedTags() lists the base tags the active
   * published skin's defaults dress. Both are daedalus_stylish's contributions
   * through the build event, read on the live builder.
   */
  public function testBirthDefaultsAndDressedTagsReachTheCatalog(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => [
        'default_variants' => [
          'container_layout' => 'columns',
          'width' => ['variant' => 'container_site', 'depth' => StyleGuide::DEPTH_ROOT],
        ],
      ],
    ]);
    $this->layerOverMedia([
      'variant_axes' => ['media_proportions'],
      'default_variants' => ['style' => 'media_card', 'media_proportions' => 'media_wide'],
    ]);
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->setPackageDefaults([
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
      'kind:block' => ['' => ['default' => ['margin-top' => '1rem']]],
      'base:p' => ['' => ['default' => ['line-height' => '1.6']]],
      'base:a' => [],
    ]);
    $skin->setPublished()->save();
    $this->activateSkin($skin);

    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $this->assertSame('daedalus_stylish_container', $catalog->containerLayoutId());
    $this->assertSame(['layout' => 'columns', 'width' => 'container_site'], $catalog->birthDefaults(Address::layout('daedalus_stylish_container')), 'The layout answers the kind\'s defaults, spelled on the container tag.');
    $this->assertSame(['style' => 'media_card', 'proportions' => 'media_wide'], $catalog->birthDefaults(Address::blockType('media')), 'A block type\'s axis spells minus the type\'s prefix.');
    $this->assertSame([], $catalog->birthDefaults(Address::blockType('undeclared')));
    $this->assertSame(['h2', 'p'], $catalog->dressedTags(), 'Base scopes with entries, in the skin\'s order; element scopes and empty scopes are not tags.');

    // No active system, so no active skin: no dressed tags; the birth
    // defaults are law and stay.
    $this->deactivateDesignSystem();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $this->assertSame([], $catalog->dressedTags());
    $this->assertSame(['layout' => 'columns', 'width' => 'container_site'], $catalog->birthDefaults(Address::layout('daedalus_stylish_container')));
  }

  /**
   * Layers a declaration over the media block type and rebuilds the guide.
   */
  protected function layerOverMedia(array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', ['media' => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * A valid map parses, covering the built-in and declared axes.
   */
  public function testValidDefaults(): void {
    $this->layerOverMedia([
      'variant_axes' => ['width'],
      'default_variants' => ['style' => 'media_card', 'width' => 'media_wide'],
    ]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame(['style' => 'media_card', 'width' => 'media_wide'], $media['default_variants']);
  }

  /**
   * The axis vocabulary is the EFFECTIVE one: kind-layer axes count.
   */
  public function testKindLayerAxisIsValid(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', ['block' => ['variant_axes' => ['width']]]);
    $this->layerOverMedia(['default_variants' => ['width' => 'media_wide']]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame(['width' => 'media_wide'], $media['default_variants']);
  }

  /**
   * The default_variants must be a map, not a scalar or a list.
   */
  public function testNonMapDefaultsFails(): void {
    $this->layerOverMedia(['default_variants' => 'media_card']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/default_variants declaration of block type "media" must be a non-empty map of axis => variant machine name/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A list is a mistake: every default names its axis.
   */
  public function testListDefaultsFails(): void {
    $this->layerOverMedia(['default_variants' => ['media_card']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/must be a non-empty map of axis => variant machine name/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * An empty map is a mistake, not "no defaults": omit the key instead.
   */
  public function testEmptyDefaultsFails(): void {
    $this->layerOverMedia(['default_variants' => []]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/must be a non-empty map of axis => variant machine name/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * An axis outside the scope's vocabulary fails the build.
   */
  public function testUnknownAxisFails(): void {
    $this->layerOverMedia(['default_variants' => ['width' => 'media_wide']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/names axis \'width\', which is not in the scope\'s axis vocabulary \(style, animation, hover\)/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Default values are variant machine names.
   */
  public function testMalformedReferenceFails(): void {
    $this->layerOverMedia(['default_variants' => ['style' => 'Media Card']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/maps axis \'style\' to \'Media Card\', which is not a variant machine name/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * The pre-axes singular default_variant key is dead grammar.
   */
  public function testSingularDefaultVariantFails(): void {
    $this->layerOverMedia(['default_variant' => 'media_card']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/block type "media" declares default_variant, which no longer exists; declare default_variants/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Kind declarations validate their defaults too.
   */
  public function testKindDefaultsValidate(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['default_variants' => ['girth' => 'section_wide']],
    ]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/default_variants declaration of kind "container" names axis \'girth\'/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Layers a declaration over the container kind and rebuilds the guide.
   */
  protected function layerOverContainer(array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', ['container' => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * A depth-qualified default parses on container-family declarations.
   *
   * The projection resolves it for placement: present at root, absent
   * nested — while an unqualified entry resolves at every depth.
   */
  public function testQualifiedRootDefault(): void {
    $this->layerOverContainer([
      'variant_axes' => ['width', 'flow'],
      'default_variants' => [
        'width' => 'section_wide',
        'flow' => ['variant' => 'section_row', 'depth' => 'root'],
      ],
    ]);
    $container = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['variant' => 'section_row', 'depth' => 'root'], $container['default_variants']['flow']);
    $this->assertSame(['width' => 'section_wide', 'flow' => 'section_row'], StyleGuide::defaultVariants($container));
    $this->assertSame(['width' => 'section_wide'], StyleGuide::defaultVariants($container, TRUE));
  }

  /**
   * The depth qualifier is a category error outside the container family.
   */
  public function testQualifiedDefaultOnBlockTypeFails(): void {
    $this->layerOverMedia([
      'variant_axes' => ['width'],
      'default_variants' => ['width' => ['variant' => 'media_wide', 'depth' => 'root']],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/sets the depth of axis \'width\', but the depth qualifier applies only to container-family declarations/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A qualified entry's keys are exactly "variant" and "depth".
   */
  public function testQualifiedDefaultUnknownKeyFails(): void {
    $this->layerOverContainer([
      'variant_axes' => ['flow'],
      'default_variants' => ['flow' => ['variant' => 'section_row', 'depth' => 'root', 'scope' => 'oops']],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/qualifies axis \'flow\' with unknown key\(s\) \'scope\'/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * A qualified entry must name its variant.
   */
  public function testQualifiedDefaultWithoutVariantFails(): void {
    $this->layerOverContainer([
      'variant_axes' => ['flow'],
      'default_variants' => ['flow' => ['depth' => 'root']],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/qualifies axis \'flow\' without naming its variant/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The map form exists to carry the qualifier: no depth, no map.
   */
  public function testQualifiedDefaultWithoutDepthFails(): void {
    $this->layerOverContainer([
      'variant_axes' => ['flow'],
      'default_variants' => ['flow' => ['variant' => 'section_row']],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/qualifies axis \'flow\' without a depth/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The only depth value is "root".
   */
  public function testQualifiedDefaultBadDepthFails(): void {
    $this->layerOverContainer([
      'variant_axes' => ['flow'],
      'default_variants' => ['flow' => ['variant' => 'section_row', 'depth' => 'nested']],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/sets the depth of axis \'flow\' to \'nested\'; the only depth value is "root"/');
    $this->styleGuide->getKind('container', self::THEME);
  }

}
