<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore siez
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests authored constraint_defaults: grammar, the merge, and enforcement.
 *
 * The site declaration's constraint_defaults section is the site's own
 * site-wide bindings: constraint blocks (CSS property => the same
 * {scale, stops, allowed, min, max, units} grammar node constraints
 * speak), the BASE layer under every node's own blocks. The guide build
 * validates code-vs-code facts loudly (recognized property, declared
 * scale, stops grammar); StyleGuide::constraintDefaults() serves the
 * authored entries expanded over the shorthand families and nothing
 * derived from nodes (a node's block is that node's value space, never a
 * site-wide line); the validator enforces an authored default wherever
 * the property is granted and the node authors no block of its own, per
 * the enforcement mode.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideConstraintDefaultsTest extends KernelTestBase {

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
    'user',
    'field',
    'entity_test',
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
   * The style guide under test.
   */
  protected StyleGuide $styleGuide;

  /**
   * The style map validator under test.
   */
  protected StyleMapValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
  }

  /**
   * Sets the site declaration under test (replacing the layered lane).
   */
  protected function declareSite(array $site): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.site', $site);
    $this->styleGuide->resetCache();
  }

  /**
   * Layers kind declarations under test over the shipped ones.
   */
  protected function declareKinds(array $kinds): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', $kinds);
    $this->styleGuide->resetCache();
  }

  /**
   * A site declaration with the "text" type scale and the given defaults.
   */
  protected function declareDefaults(mixed $defaults): void {
    $this->declareSite([
      'token_scales' => ['text' => ['label' => 'Type scale', 'shape' => 'dimension']],
      'constraint_defaults' => $defaults,
    ]);
  }

  /**
   * Collects error codes keyed by path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors from validateAt().
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * Authored defaults parse and serve in authoring order, families expanded.
   */
  public function testAuthoredDefaultsServe(): void {
    $this->declareSite([
      'token_scales' => [
        'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
      'constraint_defaults' => [
        'font-size' => ['scale' => 'text'],
        'gap' => ['scale' => 'space', 'stops' => ['2', '4', '8']],
        'line-height' => ['min' => '1', 'max' => '2'],
      ],
    ]);
    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    $this->assertSame([
      'font-size' => ['scale' => 'text'],
      'gap' => ['scale' => 'space', 'stops' => ['2', '4', '8']],
      // The gap family's longhands, right after their shorthand.
      'row-gap' => ['scale' => 'space', 'stops' => ['2', '4', '8']],
      'column-gap' => ['scale' => 'space', 'stops' => ['2', '4', '8']],
      'line-height' => ['min' => '1', 'max' => '2'],
    ], $served);
    // The serialization map IS the served map.
    $this->assertSame($served, $this->styleGuide->constraintDefaults(self::THEME));
  }

  /**
   * A site block on a shorthand expands to the family's longhands.
   *
   * One value rule per family: the site's padding block is the block of
   * padding-top, padding-right, padding-bottom and padding-left, gap's
   * of row-gap and column-gap, border-radius's of the four corners, each
   * family's longhands after their shorthand in the served map and the
   * authored order otherwise kept. A longhand authoring its own block
   * keeps it (padding-left's cap wins over the expanded scale binding)
   * in its own authored position. The expansion happens once, at the
   * source: the roster serves the expanded sides as value spaces and
   * the validator enforces them like any authored default, neither
   * expanding for itself.
   */
  public function testFamilyExpansion(): void {
    $padding = ['scale' => 'space', 'allowed' => ['0']];
    $gap = ['scale' => 'space'];
    $radius = ['scale' => 'radius', 'allowed' => ['0']];
    $this->declareSite([
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'radius' => ['label' => 'Radius', 'shape' => 'dimension'],
      ],
      'constraint_defaults' => [
        'padding' => $padding,
        'padding-left' => ['max' => '1rem'],
        'gap' => $gap,
        'border-radius' => $radius,
      ],
    ]);
    $this->assertSame([
      'padding' => $padding,
      'padding-top' => $padding,
      'padding-right' => $padding,
      'padding-bottom' => $padding,
      'padding-left' => ['max' => '1rem'],
      'gap' => $gap,
      'row-gap' => $gap,
      'column-gap' => $gap,
      'border-radius' => $radius,
      'border-top-left-radius' => $radius,
      'border-top-right-radius' => $radius,
      'border-bottom-right-radius' => $radius,
      'border-bottom-left-radius' => $radius,
    ], $this->styleGuide->getConstraintDefaults(self::THEME), 'the served site map carries each family expanded, longhands after their shorthand, a longhand\'s own block in its authored place');

    // The roster reads the expanded map: a granted side carries the
    // shorthand's block, the side with its own block carries that.
    $this->declareKinds(['block' => ['property_groups' => ['spacing' => [], 'border' => []]]]);
    $roster = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::fromString('kind:block'), self::THEME);
    $this->assertSame($padding, $roster->valueSpace('padding-top'), 'the expanded padding block is padding-top\'s value space');
    $this->assertSame(['max' => '1rem'], $roster->valueSpace('padding-left'), 'padding-left\'s own block wins over the expanded one');
    $this->assertSame($gap, $roster->valueSpace('column-gap'), 'the expanded gap block is column-gap\'s value space');
    $this->assertSame($radius, $roster->valueSpace('border-bottom-left-radius'), 'the expanded border-radius block is a corner\'s value space');

    // The validator enforces the expanded side as law (guarded): a
    // literal is off the scale, the allowed zero passes, and the side
    // with its own cap answers to that cap.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding-top' => '13px', 'padding-left' => '13px', 'row-gap' => '0']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    $this->assertSame(['default.padding-top' => ['STYLE_VALUE_NOT_ON_SCALE'], 'default.row-gap' => ['STYLE_VALUE_NOT_ON_SCALE']], $codes, 'the expanded sides are law where the shorthand\'s block is, and padding-left answers to its own cap');
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding-top' => '0', 'border-top-left-radius' => '0']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]), 'the shorthand\'s allowed literal is allowed on its expanded sides');
  }

  /**
   * A site without the section authors no defaults.
   */
  public function testNoDefaultsDeclared(): void {
    $this->declareSite([]);
    $this->assertSame([], $this->styleGuide->getConstraintDefaults(self::THEME));
  }

  /**
   * The shipped site lane binds the seven properties every skin can honor.
   *
   * Daedalus Stylish's own site declaration (daedalus_stylish.page_builder.yml) is the base
   * of every site's constraint defaults: font-family to font, the type
   * trio to text / weight / leading, padding and gap to space and
   * border-radius to radius (the dimension bindings with the zero
   * literal beside them), each on a scale every shipped skin mints, the
   * shorthands served expanded over their families. On a bare site
   * (no package layers, no skin) the map is exactly these, and they are
   * law wherever a grant reaches them (guarded and strict): a literal
   * stack or size is off the scale, and so is a reference to a token the
   * scale does not mint (no skin here, so the closed space is honestly
   * empty; the shipped skins' token defaults pass through
   * ShippedSyncTest, where the minting skin is the one under sync); the
   * allowed zero passes on the shorthand and its expanded sides. Open
   * mode demotes the bindings to offer-shaping like every authored block.
   * Nothing else ships bound: tracking and color are a package's to bind.
   */
  public function testShippedBindings(): void {
    $space = ['scale' => 'space', 'allowed' => ['0']];
    $radius = ['scale' => 'radius', 'allowed' => ['0']];
    $this->assertSame([
      'font-family' => ['scale' => 'font'],
      'font-size' => ['scale' => 'text'],
      'font-weight' => ['scale' => 'weight'],
      'line-height' => ['scale' => 'leading'],
      'padding' => $space,
      'padding-top' => $space,
      'padding-right' => $space,
      'padding-bottom' => $space,
      'padding-left' => $space,
      'gap' => $space,
      'row-gap' => $space,
      'column-gap' => $space,
      'border-radius' => $radius,
      'border-top-left-radius' => $radius,
      'border-top-right-radius' => $radius,
      'border-bottom-right-radius' => $radius,
      'border-bottom-left-radius' => $radius,
    ], $this->styleGuide->getConstraintDefaults(self::THEME), 'the bare site serves the suite\'s seven bindings, the families expanded');
    $this->declareKinds(['block' => ['property_groups' => ['typography' => [], 'spacing' => [], 'border' => [], 'layout' => []]]]);

    $codes = $this->codes($this->validator->validateAt([
      '' => [
        'default' => [
          'font-family' => 'Arial, sans-serif',
          'font-size' => '13px',
          'font-weight' => '600',
          'line-height' => '1.5',
          'padding-top' => '2rem',
          'gap' => '1rem',
          'border-radius' => '4px',
        ],
      ],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    foreach (['font-family', 'font-size', 'font-weight', 'line-height', 'padding-top', 'gap', 'border-radius'] as $property) {
      $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $codes["default.$property"] ?? [], "a literal $property is off the suite's scale on a guarded site");
    }
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['font-family' => 'var(--font-body)', 'padding' => 'var(--space-md)']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $codes['default.font-family'], 'an unminted token is not on the scale either');
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $codes['default.padding'], 'no skin mints, so the closed space is honestly empty: no fallback table stands in');
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '0', 'padding-left' => '0', 'row-gap' => '0', 'border-top-left-radius' => '0']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]), 'the zero literal is allowed on the dimension shorthands and their expanded longhands');

    $this->enforce('open');
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-family' => 'Arial, sans-serif', 'font-size' => '13px', 'padding-top' => '2rem']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
  }

  /**
   * A package's stops: merge over the suite's scale:; null withdraws it.
   *
   * The olivero package restates no suite binding: its site section
   * carries `padding: {stops: [sm, md, lg, xl]}` and the same for gap,
   * and while its design system is active that layer merges over the
   * suite's map (maps merge across providers, daedalus_ui's
   * PageBuilderDeclarations), so padding resolves to the suite's scale
   * and zero literal with the package's stops beside them, expanded to
   * the four sides, and the bindings the package leaves alone (the
   * type trio, border-radius) are served as the suite authored them.
   * A higher layer's `padding: null` withdraws the merged block whole
   * (the family with it, since the suite authors the shorthand alone),
   * reopening padding to the built-in offer while gap keeps its binding.
   */
  public function testPackageStopsMergeOverSuiteBinding(): void {
    // The activation syncs the package's skin through the importers,
    // which switch to an administrative account: give them one.
    $this->setUpCurrentUser([], [], TRUE);
    $this->enableModules(['daedalus_stylish_olivero_skin']);
    $this->installConfig(['daedalus_stylish_olivero_skin']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('olivero');
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->styleGuide->resetCache();

    $merged = ['scale' => 'space', 'allowed' => ['0'], 'stops' => ['sm', 'md', 'lg', 'xl']];
    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    $this->assertSame($merged, $served['padding'], 'the package\'s stops merge over the suite\'s scale and allowed literal');
    $this->assertSame($merged, $served['padding-left'], 'the merged block reaches the family\'s sides');
    $this->assertSame($merged, $served['gap'], 'gap merges the same way');
    $this->assertSame($merged, $served['column-gap'], 'and reaches its longhands');
    $this->assertSame(['scale' => 'radius', 'allowed' => ['0']], $served['border-radius'], 'a binding the package leaves alone is served as the suite authored it');
    $this->assertSame(['scale' => 'text'], $served['font-size']);

    $this->declareKinds(['block' => ['property_groups' => ['spacing' => [], 'layout' => []]]]);
    $rosters = $this->container->get('daedalus_stylish.vocabulary_roster');
    $this->assertSame($merged, $rosters->build(Address::fromString('kind:block'), self::THEME)->valueSpace('padding'), 'the roster serves the merged block as the value space');

    // A layer above the package withdraws the binding whole: padding and
    // its sides leave the map and the roster, gap stays bound, and the
    // validator (guarded, so the suite's blocks are law) accepts the
    // literal padding it refused a moment ago while still refusing the
    // literal gap.
    $this->enforce('guarded');
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem', 'gap' => '2rem']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    $this->assertSame(['default.padding' => ['STYLE_VALUE_NOT_ON_SCALE'], 'default.gap' => ['STYLE_VALUE_NOT_ON_SCALE']], $codes, 'before the withdrawal both literals are off the merged scale');

    $this->container->get('state')->set('daedalus_stylish_control_test.site_layer', ['constraint_defaults' => ['padding' => NULL]]);
    $this->styleGuide->resetCache();
    $served = $this->styleGuide->getConstraintDefaults(self::THEME);
    foreach (['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left'] as $property) {
      $this->assertArrayNotHasKey($property, $served, "padding: null withdraws $property from the served map");
    }
    $this->assertSame($merged, $served['gap'], 'the withdrawal is per key: gap keeps the merged block');
    $roster = $rosters->build(Address::fromString('kind:block'), self::THEME);
    $this->assertSame([], $roster->valueSpace('padding'), 'the roster serves no value space for the withdrawn padding');
    $this->assertSame([], $roster->valueSpace('padding-left'));
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem', 'gap' => '2rem']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    $this->assertSame(['default.gap' => ['STYLE_VALUE_NOT_ON_SCALE']], $codes, 'after the withdrawal the literal padding passes and the literal gap is still off the scale');
  }

  /**
   * Node blocks never become site-wide defaults: only the site's own do.
   *
   * A node block differing from an authored default is a per-node fact
   * (it wins on its node and stays that node's value space), never
   * grounds to withdraw the authored default. Blocks every node agrees
   * on do not hoist either: a site-wide line the validator would not
   * enforce on an unconstrained node taught the wrong prior, so the
   * serialization map carries exactly what the site declares and each
   * node's space rides its own address (the vocabulary roster).
   */
  public function testNodeBlocksNeverHoist(): void {
    $this->declareDefaults(['font-size' => ['scale' => 'text']]);
    $this->declareKinds([
      'block' => [
        'property_groups' => [
          'typography' => [
            'constraints' => [
              // Differs from the authored default: a per-node delta, not a
              // displacement of the site's binding.
              'font-size' => ['max' => '2rem'],
              'letter-spacing' => ['max' => '1px'],
            ],
          ],
          'spacing' => ['constraints' => ['padding' => ['max' => '4rem']]],
        ],
      ],
      'container' => [
        'property_groups' => [
          // Disagrees with block's letter-spacing: conflict, no hoist.
          'typography' => ['constraints' => ['letter-spacing' => ['max' => '2px']]],
          // Agrees with block's padding: hoists.
          'spacing' => ['constraints' => ['padding' => ['max' => '4rem']]],
        ],
      ],
    ]);

    // The authored binding survives the differing node block, and the
    // agreed padding cap stays on its nodes: the map is the site's own.
    $this->assertSame(['font-size' => ['scale' => 'text']], $this->styleGuide->constraintDefaults(self::THEME));
    // Each node's block is its own value space, served per address.
    $rosters = $this->container->get('daedalus_stylish.vocabulary_roster');
    $this->assertSame(['max' => '4rem'], $rosters->build(Address::fromString('kind:block'), self::THEME)->valueSpaces()['padding']);
    $this->assertSame(['max' => '4rem'], $rosters->build(Address::fromString('kind:container'), self::THEME)->valueSpaces()['padding']);
    $this->assertSame(['max' => '2px'], $rosters->build(Address::fromString('kind:container'), self::THEME)->valueSpaces()['letter-spacing']);
  }

  /**
   * The constraint_defaults is site vocabulary: misplacement fails the build.
   */
  public function testMisplacedSectionFails(): void {
    $this->declareKinds([
      'block' => ['constraint_defaults' => ['font-size' => ['max' => '2rem']]],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/kind "block" declares constraint_defaults, but constraint defaults are site vocabulary/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * The section must be a non-empty map keyed by property name.
   */
  public function testNonMapSectionFails(): void {
    $this->declareDefaults(['font-size']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint_defaults declaration of the site must be a non-empty map of constraint blocks/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * Each entry must be a constraint block, not a bare value.
   */
  public function testNonMapBlockFails(): void {
    $this->declareDefaults(['font-size' => '2rem']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint default on property "font-size" must be a non-empty constraint block/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * A default on a property no group recognizes is a typo, caught loudly.
   */
  public function testUnrecognizedPropertyFails(): void {
    $this->declareDefaults(['font-siez' => ['max' => '2rem']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint default on "font-siez" names a property no property group recognizes/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * A scale reference must name a declared scale (code vs code).
   */
  public function testUndeclaredScaleFails(): void {
    $this->declareDefaults(['font-size' => ['scale' => 'type']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint on property "font-size" of the site\'s constraint_defaults references the token scale \'type\', which is not declared/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * Stops is only meaningful beside a scale, exactly like node constraints.
   *
   * The stops NAMES themselves stay unchecked here: they are skin data,
   * answered at delivery (the client drops un-minted names), so naming a
   * token the skin does not mint never fails the build.
   */
  public function testStopsWithoutScaleFails(): void {
    $this->declareDefaults(['font-size' => ['stops' => ['lg', 'xl']]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint on property "font-size" of the site\'s constraint_defaults declares stops without a scale/');
    $this->styleGuide->getConstraintDefaults(self::THEME);
  }

  /**
   * An authored default is law where the property is granted (guarded).
   *
   * The default is the base layer of constraint resolution: a node whose
   * groups grant the property but author no block of their own enforces
   * the site's authored block — here a scale binding with an allowed
   * escape, so the closed value space is checkable without a skin.
   */
  public function testAuthoredDefaultIsLaw(): void {
    $this->declareDefaults(['font-size' => ['scale' => 'text', 'allowed' => ['inherit']]]);
    $this->declareKinds(['block' => ['property_groups' => ['typography' => []]]]);

    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['font-size' => '13px']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $codes['default.font-size']);

    // A member of the authored default's value space passes.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => 'inherit']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
  }

  /**
   * A node's own block wins over the authored default on that node.
   */
  public function testNodeBlockOverridesAuthoredDefault(): void {
    $this->declareDefaults(['font-size' => ['scale' => 'text', 'allowed' => ['inherit']]]);
    $this->declareKinds([
      'block' => [
        'property_groups' => [
          'typography' => ['constraints' => ['font-size' => ['max' => '2rem']]],
        ],
      ],
    ]);
    // 13px violates the authored scale binding but satisfies the node's
    // own block, which wins per node.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => '13px']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
  }

  /**
   * The default binds granted properties only, never undeclared ones.
   *
   * On a guarded site everything undeclared stays open with grammar
   * checks: a site-wide binding does not quietly close a property on
   * nodes whose groups never granted it.
   */
  public function testDefaultNotEnforcedOutsideGrantedGroups(): void {
    $this->declareDefaults(['font-size' => ['scale' => 'text', 'allowed' => ['inherit']]]);
    $this->declareKinds(['container' => ['property_groups' => ['spacing' => []]]]);
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => '13px']],
    ], Address::fromString('kind:container'), ['theme' => self::THEME]));
  }

  /**
   * Open mode demotes authored defaults to offer-shaping, like all law.
   */
  public function testOpenModeIgnoresAuthoredDefaults(): void {
    $this->enforce('open');
    $this->declareDefaults(['font-size' => ['scale' => 'text', 'allowed' => ['inherit']]]);
    $this->declareKinds(['block' => ['property_groups' => ['typography' => []]]]);
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => '13px']],
    ], Address::fromString('kind:block'), ['theme' => self::THEME]));
  }

}
