<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests token_scales declarations: grammar validation and derivation.
 *
 * Token scales are the design-token vocabulary's code half: the site
 * declaration's token_scales section names each scale's label, value shape,
 * and (optionally) the CSS variable prefix its tokens derive under
 * (--{prefix}-{name}, prefix defaulting to the scale machine name). The
 * guide build validates the grammar loudly (like variant_axes), and
 * StyleGuide::tokenVariable() is the one derivation rule every consumer
 * resolves variable names through.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideTokenScalesTest extends KernelTestBase {

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
    // The client payload serves the published swatches, so every build()
    // consumer needs the swatch entity schema.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installEntitySchema('user');
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
   * Sets the site declaration under test (replacing the lane) and rebuilds.
   */
  protected function declareSite(array $site): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.site', $site);
    $this->styleGuide->resetCache();
  }

  /**
   * Declares token scales on the site and rebuilds the guide.
   */
  protected function declareScales(mixed $scales): void {
    $this->declareSite(['token_scales' => $scales]);
  }

  /**
   * A valid declaration parses, and the derivation helpers resolve it.
   */
  public function testScalesVocabulary(): void {
    $this->declareScales([
      'space' => ['label' => 'Space', 'shape' => 'dimension'],
      'color' => ['label' => 'Color', 'shape' => 'color'],
      'weight' => ['label' => 'Weight', 'shape' => 'number'],
      'transition' => ['label' => 'Transition', 'shape' => 'text', 'prefix' => 'trans'],
      'align' => ['label' => 'Alignment', 'shape' => 'keyword', 'keywords' => ['start', 'center', 'end']],
    ]);
    $scales = $this->styleGuide->getTokenScales(self::THEME);
    // Authoring order is picker order.
    $this->assertSame(['space', 'color', 'weight', 'transition', 'align'], array_keys($scales));
    $this->assertSame('dimension', $scales['space']['shape']);
    $this->assertSame(['start', 'center', 'end'], $scales['align']['keywords']);

    // The one derivation rule: --{prefix}-{name}, prefix defaulting to the
    // scale machine name.
    $this->assertSame('space', StyleGuide::tokenPrefix('space', $scales['space']));
    $this->assertSame('--space-4', StyleGuide::tokenVariable('space', $scales['space'], '4'));
    $this->assertSame('--color-accent-hover', StyleGuide::tokenVariable('color', $scales['color'], 'accent-hover'));
    $this->assertSame('trans', StyleGuide::tokenPrefix('transition', $scales['transition']));
    $this->assertSame('--trans-fast', StyleGuide::tokenVariable('transition', $scales['transition'], 'fast'));
  }

  /**
   * A site without token scales has none: no section, no vocabulary.
   *
   * Daedalus Stylish ships ten scales at module level, so "no scales" is a site
   * whose declaration withdraws the section (the fixture replaces the lane).
   */
  public function testNoScalesDeclared(): void {
    $this->declareSite([]);
    $this->assertSame([], $this->styleGuide->getTokenScales(self::THEME));
    $this->assertSame([], $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME)['tokenScales']);
  }

  /**
   * The client payload serves scales with the prefix resolved.
   */
  public function testClientPayloadCarriesTokenScales(): void {
    $this->declareScales([
      'space' => ['label' => 'Space', 'shape' => 'dimension'],
      'transition' => ['label' => 'Transition', 'shape' => 'text', 'prefix' => 'trans'],
      'align' => ['label' => 'Alignment', 'shape' => 'keyword', 'keywords' => ['start', 'end']],
    ]);
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame([
      'space' => ['label' => 'Space', 'shape' => 'dimension', 'prefix' => 'space'],
      'transition' => ['label' => 'Transition', 'shape' => 'text', 'prefix' => 'trans'],
      'align' => ['label' => 'Alignment', 'shape' => 'keyword', 'prefix' => 'align', 'keywords' => ['start', 'end']],
    ], $payload['tokenScales']);
  }

  /**
   * The token_scales must be a non-empty map.
   */
  public function testNonMapScalesFails(): void {
    $this->declareScales(['space']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token_scales declaration of the site must be a non-empty map/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * An empty map is a mistake, not "no scales": omit the section instead.
   */
  public function testEmptyScalesFails(): void {
    $this->declareScales([]);
    // An empty array is indistinguishable from an empty list after YAML
    // decoding, so it fails the same non-empty-map check.
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/must be a non-empty map of scale declarations/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * Scale keys are machine names.
   */
  public function testMalformedScaleKeyFails(): void {
    $this->declareScales(['Type Scale' => ['label' => 'Type', 'shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale key "Type Scale" is not a scale machine name/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * A scale must declare a label.
   */
  public function testMissingLabelFails(): void {
    $this->declareScales(['space' => ['shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "space" must declare a label string/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * A scale's shape comes from the closed shape vocabulary.
   */
  public function testUnknownShapeFails(): void {
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'length']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "space" must declare a shape of: dimension, number, color, keyword, text/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * The keyword shape requires its keywords vocabulary.
   */
  public function testKeywordShapeRequiresKeywords(): void {
    $this->declareScales(['align' => ['label' => 'Alignment', 'shape' => 'keyword']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "align" has the keyword shape, so it must declare keywords/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * Only the keyword shape carries a keywords vocabulary.
   */
  public function testKeywordsOnOtherShapeFails(): void {
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension', 'keywords' => ['auto']]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "space" declares keywords, but only the keyword shape/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * A declared prefix must be a CSS-friendly name segment.
   */
  public function testMalformedPrefixFails(): void {
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension', 'prefix' => 'space-']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "space" must declare its prefix as a CSS-friendly name segment/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * Unknown scale keys fail the build: the grammar is closed.
   */
  public function testUnknownScaleKeyFails(): void {
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension', 'ordered' => TRUE]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "space" has unknown key\(s\) \'ordered\'/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * Two scales cannot share an effective variable prefix.
   */
  public function testDuplicatePrefixFails(): void {
    $this->declareScales([
      'space' => ['label' => 'Space', 'shape' => 'dimension'],
      'gap' => ['label' => 'Gap', 'shape' => 'dimension', 'prefix' => 'space'],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scale "gap" derives CSS variables with the prefix "--space-", which the scale "space" already uses/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * A prefix extending another hyphen-wise is ambiguous and fails.
   *
   * Token names may contain hyphens (--color-accent-hover), so with "text"
   * beside "text-heading" the variable --text-heading-lg would parse as
   * either scale; the grammar refuses the ambiguity at the source.
   */
  public function testExtendingPrefixFails(): void {
    $this->declareScales([
      'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
      'heading' => ['label' => 'Headings', 'shape' => 'dimension', 'prefix' => 'text-heading'],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token scales "text" and "heading" derive the variable prefixes "--text-" and "--text-heading-", one extending the other/');
    $this->styleGuide->getTokenScales(self::THEME);
  }

  /**
   * The token_scales key is site vocabulary; on an element it is an error.
   */
  public function testMisplacedScalesFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'media' => ['token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']]],
    ]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/block type "media" declares token_scales, but token scales are site vocabulary/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Kind declarations cannot carry token_scales either.
   */
  public function testMisplacedScalesOnKindFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']]],
    ]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/kind "container" declares token_scales, but token scales are site vocabulary/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The tokenReference() is the one reverse derivation: prefix match, or NULL.
   */
  public function testTokenReference(): void {
    $this->declareScales([
      'space' => ['label' => 'Space', 'shape' => 'dimension'],
      'transition' => ['label' => 'Transition', 'shape' => 'text', 'prefix' => 'trans'],
    ]);
    $scales = $this->styleGuide->getTokenScales(self::THEME);
    $this->assertSame(['scale' => 'space', 'name' => '4'], StyleGuide::tokenReference('--space-4', $scales));
    $this->assertSame(['scale' => 'space', 'name' => 'accent-hover'], StyleGuide::tokenReference('--space-accent-hover', $scales));
    $this->assertSame(['scale' => 'transition', 'name' => 'fast'], StyleGuide::tokenReference('--trans-fast', $scales));
    // Outside every declared prefix: not a token reference.
    $this->assertNull(StyleGuide::tokenReference('--daedalus-ui-accent', $scales));
    // The bare prefix has no name tail.
    $this->assertNull(StyleGuide::tokenReference('--space', $scales));
    $this->assertNull(StyleGuide::tokenReference('--space-', $scales));
  }

  /**
   * A constraint may reference a declared scale; the build vouches for it.
   */
  public function testScaleConstraintReferencesDeclaredScale(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'block' => ['property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'space']]]]],
    ]);
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension']]);
    $declaration = $this->styleGuide->getKind('block', self::THEME);
    $this->assertSame('space', $declaration['property_groups']['spacing']['constraints']['padding']['scale']);
  }

  /**
   * A constraint naming an undeclared scale fails the build loudly.
   */
  public function testScaleConstraintUnknownScaleFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'block' => ['property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'radius']]]]],
    ]);
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint on property "padding" of kind "block" references the token scale \'radius\', which is not declared \(declared scales: space\)/');
    $this->styleGuide->getKind('block', self::THEME);
  }

  /**
   * Stops beside a scale parse as authored: offer curation is guide code.
   *
   * Entries are token names as the skin spells them; a numeric name
   * arrives from YAML as an int and is legal (consumers compare its
   * string form, as the contract check does).
   */
  public function testStopsCurationParses(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => ['2', 4, '8']]]],
        ],
      ],
    ]);
    // A curated scale declares its contract, and the stops name it
    // (TokenContractTest owns that grammar).
    $this->declareSite([
      'token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']],
      'token_contract' => ['space' => ['2', '4', '8']],
    ]);
    $declaration = $this->styleGuide->getKind('block', self::THEME);
    $this->assertSame(['2', 4, '8'], $declaration['property_groups']['spacing']['constraints']['padding']['stops']);
  }

  /**
   * Stops without a scale fail the build: they curate a scale's offer.
   */
  public function testStopsWithoutScaleFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'block' => ['property_groups' => ['spacing' => ['constraints' => ['padding' => ['stops' => ['2', '4']]]]]],
    ]);
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/constraint on property "padding" of kind "block" declares stops without a scale/');
    $this->styleGuide->getKind('block', self::THEME);
  }

  /**
   * Stops must be a non-empty list of non-empty scalar token names.
   */
  public function testMalformedStopsFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'block' => [
        'property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => []]]]],
      ],
    ]);
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/stops of the constraint on property "padding" of kind "block" must be a non-empty list of token names/');
    $this->styleGuide->getKind('block', self::THEME);
  }

  /**
   * Part declarations are walked too: constraints nest with anatomy.
   */
  public function testScaleConstraintInPartFails(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'testimonial' => [
        'elements' => [
          '.quote' => [
            'label' => 'Quote',
            'property_groups' => ['typography' => ['constraints' => ['font-size' => ['scale' => 'radius']]]],
          ],
        ],
      ],
    ]);
    $this->declareScales(['space' => ['label' => 'Space', 'shape' => 'dimension']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/property "font-size" of part "\.quote" of block type "testimonial" references the token scale \'radius\'/');
    $this->styleGuide->getBlockType('testimonial', self::THEME);
  }

}
