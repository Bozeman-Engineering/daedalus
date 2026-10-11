<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\SkinConformance;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests the token contract: the guide's grammar and the skin's conformance.
 *
 * The site declaration's token_contract ({scale: [names]}) is the token
 * names a design system promises. The guide build proves the guide's half of it (a
 * scale whose stops are curated declares a contract, and every stop is
 * a contract name: guide code naming guide code, so a fault is a build
 * error); SkinConformance answers the skin's half (whether one skin
 * mints every contract name, and what it lacks), skin data that never
 * bricks the guide. The delivery-time drift log the client payload used
 * to write for a curated stop the skin did not mint is gone with it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class TokenContractTest extends KernelTestBase {

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
    'daedalus_stylish_control_test',
  ];

  /**
   * The style guide under test.
   */
  protected StyleGuide $styleGuide;

  /**
   * The conformance judge under test.
   */
  protected SkinConformance $conformance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions, which mirror references into the usage tables and
    // switch to an administrative account.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
    $this->conformance = $this->container->get('daedalus_stylish.skin_conformance');
  }

  /**
   * Sets the site declaration under test (replacing the lane) and rebuilds.
   */
  protected function declareSite(array $site): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.site', $site);
    $this->styleGuide->resetCache();
  }

  /**
   * A site declaring the space and color scales plus the given sections.
   */
  protected function declareWithScales(array $site): void {
    $this->declareSite([
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ] + $site);
  }

  /**
   * Layers kind declarations under test over the shipped ones.
   */
  protected function declareKinds(array $kinds): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', $kinds);
    $this->styleGuide->resetCache();
  }

  /**
   * A declared contract serves normalized: string names, authoring order.
   *
   * A numeric name like 2 arrives from YAML as an int; the served
   * contract spells it as the skin does, so consumers compare strings.
   */
  public function testContractServes(): void {
    $this->declareWithScales([
      'token_contract' => [
        'space' => [2, '4', '16'],
        'color' => ['accent', 'accent-contrast'],
      ],
    ]);
    $this->assertSame([
      'space' => ['2', '4', '16'],
      'color' => ['accent', 'accent-contrast'],
    ], $this->styleGuide->getTokenContract(self::THEME));

    $this->declareWithScales([]);
    $this->assertSame([], $this->styleGuide->getTokenContract(self::THEME), 'No contract declared: an empty map.');
  }

  /**
   * The contract is site vocabulary: a kind declaring one is misplaced.
   */
  public function testContractOnKindIsMisplaced(): void {
    $this->declareWithScales([]);
    $this->declareKinds(['block' => ['token_contract' => ['space' => ['2']]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/kind "block" declares token_contract, but the token contract is site vocabulary/');
    $this->styleGuide->getKind('block', self::THEME);
  }

  /**
   * A contract on an undeclared scale fails the build with the declared set.
   */
  public function testContractOnUndeclaredScaleFails(): void {
    $this->declareWithScales(['token_contract' => ['radius' => ['sm']]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/token contract on the "radius" scale names a scale that is not declared \(declared scales: space, color\)/');
    $this->styleGuide->getTokenContract(self::THEME);
  }

  /**
   * The contract's grammar: a map of non-empty lists of token names, once.
   */
  public function testContractGrammar(): void {
    $this->declareWithScales(['token_contract' => ['space']]);
    try {
      $this->styleGuide->getTokenContract(self::THEME);
      $this->fail('A list is not a contract map.');
    }
    catch (StyleGuideException $e) {
      $this->assertStringContainsString('token_contract declaration of the site must be a non-empty map of token name lists keyed by scale machine name', $e->getMessage());
    }

    $this->declareWithScales([
      'token_contract' => [
        'space' => [],
        'color' => ['accent', 'Accent Hover', 'accent', ['nested']],
      ],
    ]);
    try {
      $this->styleGuide->getTokenContract(self::THEME);
      $this->fail('An empty list, a misspelled name, a repeat and a non-scalar each fail.');
    }
    catch (StyleGuideException $e) {
      $message = $e->getMessage();
      $this->assertStringContainsString('token contract on the "space" scale must be a non-empty list of the token names', $message);
      $this->assertStringContainsString('token contract on the "color" scale lists \'Accent Hover\', which is not a token name', $message);
      $this->assertStringContainsString('token contract on the "color" scale lists "accent" twice', $message);
      $this->assertMatchesRegularExpression('/lists array \(.*\), which is not a token name/s', $message);
    }
  }

  /**
   * Curating stops on a scale without its contract fails the build.
   *
   * The corrective names the section to declare, on the scale curated,
   * wherever the stops sit: a node constraint, a part, and the
   * dimensions the drift log used to walk beyond the scale check (block
   * plugins, instance namespaces). The site's own constraint_defaults
   * never curate stops (a build error of its own,
   * StyleGuideConstraintDefaultsTest::testSiteWideStopsRefused()).
   */
  public function testStopsRequireTheScalesContract(): void {
    $this->declareWithScales([
      'token_contract' => ['color' => ['accent']],
      'constraint_defaults' => ['gap' => ['scale' => 'space']],
    ]);
    $this->declareKinds([
      'block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => ['2', '4']]]],
          // A stop on a contracted scale beside it passes.
          'text_color' => ['constraints' => ['color' => ['scale' => 'color', 'stops' => ['accent']]]],
        ],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.block_plugins', [
      'system_branding_block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['gap' => ['scale' => 'space', 'stops' => ['2']]]],
        ],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.instances', [
      '.s-*' => [
        'label' => 'Spans',
        'tags' => ['span' => 'Span'],
        'property_groups' => [
          'spacing' => ['constraints' => ['margin-top' => ['scale' => 'space', 'stops' => ['2']]]],
        ],
      ],
    ]);
    $this->styleGuide->resetCache();
    try {
      $this->styleGuide->getKind('block', self::THEME);
      $this->fail('Stops on an uncontracted scale fail the build.');
    }
    catch (StyleGuideException $e) {
      $message = $e->getMessage();
      $expected = 'curates stops on the "space" scale, which has no token contract; a guide that curates a scale\'s stops declares the token names it promises under the site declaration\'s token_contract section (token_contract: {space: [names]})';
      $this->assertStringContainsString('The constraint on property "padding" of kind "block" ' . $expected, $message);
      $this->assertStringContainsString('The constraint on property "gap" of block plugin "system_branding_block" ' . $expected, $message, 'block plugins are walked');
      $this->assertStringContainsString('The constraint on property "margin-top" of instance namespace ".s-*" ' . $expected, $message, 'instance namespaces are walked');
      $this->assertStringNotContainsString('property "color"', $message, 'a stop within its scale\'s contract is not an error');
    }

    // Declaring the contract clears every one of them.
    $this->declareWithScales([
      'token_contract' => ['color' => ['accent'], 'space' => ['2', '4']],
      'constraint_defaults' => ['gap' => ['scale' => 'space']],
    ]);
    $this->assertSame(['2', '4'], $this->styleGuide->getKind('block', self::THEME)['property_groups']['spacing']['constraints']['padding']['stops']);
    $this->assertSame(['scale' => 'space'], $this->styleGuide->getConstraintDefaults(self::THEME)['gap']);
  }

  /**
   * A stop outside the scale's contract fails the build, naming the strays.
   *
   * A numeric stop and a numeric contract name compare as strings, so
   * YAML's int spelling of 4 is the contract's '4'.
   */
  public function testStopsNameContractNamesOnly(): void {
    $this->declareWithScales(['token_contract' => ['space' => ['2', '4', '8']]]);
    $this->declareKinds([
      'block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => ['2', 4, '16', 'xl']]]],
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/stops of the constraint on property "padding" of kind "block" name 16, xl, which the "space" scale\'s token contract does not promise \(contract names: 2, 4, 8\); add the name to the contract or drop the stop/');
    $this->styleGuide->getKind('block', self::THEME);
  }

  /**
   * A malformed stops list is reported once, never also as strays.
   */
  public function testMalformedStopsAreOneError(): void {
    $this->declareWithScales(['token_contract' => ['space' => ['2']]]);
    $this->declareKinds([
      'block' => [
        'property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => ['']]]]],
      ],
    ]);
    try {
      $this->styleGuide->getKind('block', self::THEME);
      $this->fail('An empty stop name fails the grammar.');
    }
    catch (StyleGuideException $e) {
      $this->assertStringContainsString('must be a non-empty list of token names', $e->getMessage());
      $this->assertStringNotContainsString('does not promise', $e->getMessage());
    }
  }

  /**
   * The judge names what a skin lacks, reading the merged layers.
   *
   * A site-minted token honors the contract as a shipped one does (the
   * merge is the read); a scale the skin fully mints is absent from the
   * verdict; no contract means every skin conforms.
   */
  public function testSkinConformance(): void {
    $this->declareWithScales([
      'token_contract' => ['space' => ['2', '4', '16'], 'color' => ['accent', 'text']],
    ]);
    $skin = DaedalusStylishSkin::create(['label' => 'Test', 'machine_name' => 'test', 'status' => TRUE]);
    $skin->setPackageTokens([
      'space' => ['2' => '0.5rem', '8' => '2rem'],
      'color' => ['accent' => '#f00', 'text' => '#111'],
    ]);
    $skin->save();
    $this->assertSame(['space' => ['4', '16']], $this->conformance->lacks($skin, self::THEME), 'The verdict names the missing names per scale, in contract order, and omits a scale fully minted.');
    $this->assertFalse($this->conformance->conforms($skin, self::THEME));

    // The site layer mints the rest: the merge is what the judge reads.
    $skin->setSiteTokens(['space' => ['4' => '1rem', '16' => '4rem']]);
    $this->assertSame([], $this->conformance->lacks($skin, self::THEME));
    $this->assertTrue($this->conformance->conforms($skin, self::THEME), 'A site-minted token honors the contract.');

    // A scale the skin does not mint at all lacks every contract name.
    $bare = DaedalusStylishSkin::create(['label' => 'Bare', 'machine_name' => 'bare', 'status' => TRUE]);
    $bare->setPackageTokens(['space' => ['2' => '0.5rem', '4' => '1rem', '16' => '4rem']]);
    $this->assertSame(['color' => ['accent', 'text']], $this->conformance->lacks($bare, self::THEME));

    $this->declareWithScales([]);
    $this->assertTrue($this->conformance->conforms($bare, self::THEME), 'No contract: every skin conforms.');
  }

  /**
   * The payload build no longer logs drift; conformance is the judge's.
   *
   * A curated stop the worn skin does not mint used to warn on the
   * daedalus_stylish channel at every payload rebuild. The contract turns
   * that into the judge's verdict about the skin, so the build is quiet.
   */
  public function testPayloadBuildLogsNoDrift(): void {
    $lines = [];
    $this->container->get('logger.factory')->addLogger(new class ($lines) implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * The test's log lines, aliased.
       */
      protected array $sink;

      public function __construct(array &$sink) {
        $this->sink = &$sink;
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if ($context['channel'] === 'daedalus_stylish') {
          $this->sink[] = (string) $message;
        }
      }

    });
    $this->declareWithScales(['token_contract' => ['space' => ['2', '16']]]);
    $this->declareKinds([
      'block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['padding' => ['scale' => 'space', 'stops' => ['2', '16']]]],
        ],
      ],
    ]);
    $skin = DaedalusStylishSkin::create(['label' => 'Test', 'machine_name' => 'test', 'status' => TRUE]);
    $skin->setPackageTokens(['space' => ['2' => '0.5rem']])->save();
    $this->activateSkin($skin);
    $this->assertSame(['space' => ['16']], $this->conformance->lacks($skin, self::THEME), 'The worn skin lacks a curated stop.');
    // The activation syncs every enabled module and logs the outcomes on
    // the same channel: the sink reads the payload build alone.
    $lines = [];
    $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame([], $lines, 'The payload build logs nothing about the lack.');
  }

}
