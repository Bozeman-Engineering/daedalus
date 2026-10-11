<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the swatch validation spine.
 *
 * Three seams, one engine:
 * - validateSwatchValue(): the value grammar every swatch write path
 *   converges on — kind-dispatched scalar shapes (color, gradient via
 *   csstree-informed structural checks matching the wide-tier posture),
 *   condition keying on the token axes with a required default, and
 *   strict-site var() reference checks.
 * - The reserved namespace: --swatch-{name} belongs to swatches alone, so
 *   the guide build refuses any scale prefix equal to or extending
 *   "swatch", and the derivation helpers are the one naming rule.
 * - The reference check: a var(--swatch-*) in a style value or a skin
 *   token value must name a PUBLISHED swatch on strict sites (the
 *   STYLE_TOKEN_NOT_MINTED sibling, STYLE_SWATCH_NOT_PUBLISHED), and
 *   passes on permissive ones (inert, never unsafe).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchValidationTest extends KernelTestBase {

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
    'file',
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
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    // The validator's universes are the materials roster's, whose daedalus_stylish
    // provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
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
  }

  /**
   * Declares token scales on the site and rebuilds the guide.
   */
  protected function declareScales(array $scales): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.site', ['token_scales' => $scales]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Activates a skin minting an accent color token.
   */
  protected function activateAccentSkin(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Site skin']);
    $skin->setPackageTokens(['color' => ['accent' => '#1a1a2e']])->save();
    $this->activateSkin($skin);
  }

  /**
   * Mints a published swatch.
   */
  protected function mintSwatch(string $name, string $kind = 'color', string|array $value = '#e94560'): DaedalusStylishSwatch {
    $swatch = DaedalusStylishSwatch::create(['label' => $name, 'machine_name' => $name, 'kind' => $kind]);
    $swatch->setSwatchValue($value);
    $swatch->save();
    return $swatch;
  }

  /**
   * Validates a swatch value.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateValue(string $kind, mixed $value): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validateSwatchValue($kind, $value, ['theme' => self::THEME]);
  }

  /**
   * Asserts one error with the expected code.
   */
  protected function assertSingleError(array $errors, string $code): BlueprintError {
    $this->assertCount(1, $errors, implode(' | ', array_map(static fn (BlueprintError $e) => $e->getCode() . '@' . $e->getPath(), $errors)));
    $this->assertSame($code, $errors[0]->getCode());
    return $errors[0];
  }

  /**
   * The color kind: the shared color scalar shape, var() aliases pass.
   */
  public function testColorKind(): void {
    $this->assertSame([], $this->validateValue('color', '#e94560'));
    $this->assertSame([], $this->validateValue('color', 'oklch(0.7 0.15 20)'));
    $this->assertSame([], $this->validateValue('color', 'rebeccapurple'));
    // A color swatch may alias a skin token or another swatch (permissive
    // site: no reference check).
    $this->assertSame([], $this->validateValue('color', 'var(--color-accent)'));

    $this->assertSingleError($this->validateValue('color', '12px solid'), 'INVALID_STYLE_VALUE');
    // Value hygiene: the ;{}<> ban guards the compiled stylesheet.
    $this->assertSingleError($this->validateValue('color', 'red;} body {background: lime'), 'INVALID_STYLE_VALUE');
  }

  /**
   * The gradient kind: every layer a gradient function, csstree-informed.
   */
  public function testGradientKind(): void {
    $this->assertSame([], $this->validateValue('gradient', 'linear-gradient(135deg, #1a1a2e, #e94560)'));
    $this->assertSame([], $this->validateValue('gradient', 'REPEATING-CONIC-GRADIENT(from 0deg, gold 0deg 15deg, black 15deg 30deg)'));
    $this->assertSame([], $this->validateValue('gradient', 'radial-gradient(circle at top, oklch(0.9 0.02 250), transparent)'));
    // Comma layers: a scrim pair is one value.
    $this->assertSame([], $this->validateValue('gradient', 'linear-gradient(#00000080, transparent), linear-gradient(90deg, red, blue)'));
    // var() stops (a skin swap recolors the gradient) and var() layers (a
    // gradient swatch may alias another named value) pass structurally.
    $this->assertSame([], $this->validateValue('gradient', 'linear-gradient(120deg, var(--color-accent), #fff)'));
    $this->assertSame([], $this->validateValue('gradient', 'var(--swatch-other)'));

    // Not gradients: plain colors, url() layers (media composes in the
    // style value, never inside the named gradient), malformed nesting.
    $this->assertSingleError($this->validateValue('gradient', 'red'), 'INVALID_STYLE_VALUE');
    $this->assertSingleError($this->validateValue('gradient', 'linear-gradient(red, blue), url(shady.png)'), 'INVALID_STYLE_VALUE');
    $this->assertSingleError($this->validateValue('gradient', 'linear-gradient(red, blue'), 'INVALID_STYLE_VALUE');
    $this->assertSingleError($this->validateValue('gradient', 'linear-gradient(red, blue),'), 'INVALID_STYLE_VALUE');
    $this->assertSingleError($this->validateValue('gradient', "url('media:" . self::UUID . "')"), 'INVALID_STYLE_VALUE');
  }

  /**
   * Condition keying rides the token grammar: axes, default, dedupe.
   */
  public function testConditionKeyedValues(): void {
    $this->assertSame([], $this->validateValue('color', ['default' => '#1a1a2e', 'dark' => '#e8e8f0']));
    $this->assertSame([], $this->validateValue('gradient', [
      'default' => 'linear-gradient(#fff, #eee)',
      'dark' => 'linear-gradient(#111, #000)',
    ]));

    $this->assertSingleError($this->validateValue('color', ['dark' => '#e8e8f0']), 'SWATCH_DEFAULT_REQUIRED');
    // Interaction states are per-element facts; token axes only.
    $this->assertSingleError($this->validateValue('color', ['default' => '#111', 'hover' => '#222']), 'TOKEN_CONDITION_AXIS_INVALID');
    $this->assertSingleError($this->validateValue('color', ['default' => '#111', 'sideways' => '#222']), 'INVALID_STYLE_CONDITION');
    $this->assertSingleError($this->validateValue('color', ['default' => '#111', 'dark' => ['nested' => 'no']]), 'INVALID_SWATCH_VALUE');
    // The per-condition scalar still dispatches to the kind.
    $this->assertSingleError($this->validateValue('gradient', ['default' => 'not-a-gradient']), 'INVALID_STYLE_VALUE');
    // Non-value shapes and unknown kinds get their own correctives.
    $this->assertSingleError($this->validateValue('color', ['#111', '#222']), 'INVALID_SWATCH_VALUE');
    $error = $this->assertSingleError($this->validateValue('sparkle', '#111'), 'SWATCH_KIND_UNKNOWN');
    $this->assertEqualsCanonicalizing(['color', 'gradient'], $error->getContext()['valid_kinds']);
  }

  /**
   * Strict sites resolve a swatch value's own var() references.
   */
  public function testSwatchValueReferencesStrict(): void {
    $this->activateAccentSkin();
    $this->mintSwatch('brand');
    $this->enforce('strict');

    $this->assertSame([], $this->validateValue('gradient', 'linear-gradient(var(--color-accent), var(--swatch-brand))'));
    $this->assertSingleError($this->validateValue('gradient', 'linear-gradient(var(--color-missing), #fff)'), 'STYLE_TOKEN_NOT_MINTED');
    $error = $this->assertSingleError($this->validateValue('gradient', 'linear-gradient(var(--swatch-missing), #fff)'), 'STYLE_SWATCH_NOT_PUBLISHED');
    $this->assertSame(['brand'], $error->getContext()['published_swatches']);
    // The hint pairs each published swatch with its value: the model
    // patches from the corrective alone, so only the values let it pick
    // the swatch that preserves the rejected reference's intent.
    $this->assertStringContainsString('var(--swatch-brand) = #e94560', $error->getHint());
    $this->assertSame('value', $error->getPath());
  }

  /**
   * The reserved namespace: no scale may claim or extend "swatch".
   */
  public function testReservedPrefixGuard(): void {
    // A scale NAMED swatch (prefix defaults to the name) and a scale whose
    // declared prefix extends the namespace both fail the build loudly.
    foreach ([
      ['swatch' => ['label' => 'Swatch', 'shape' => 'color']],
      ['brand' => ['label' => 'Brand', 'shape' => 'color', 'prefix' => 'swatch']],
      ['brand' => ['label' => 'Brand', 'shape' => 'color', 'prefix' => 'swatch-brand']],
    ] as $scales) {
      $this->declareScales($scales);
      try {
        $this->container->get('daedalus_stylish.style_guide')->getTokenScales(self::THEME);
        $this->fail('A scale claimed the reserved swatch namespace.');
      }
      catch (StyleGuideException $e) {
        $this->assertStringContainsString('reserved swatch namespace', $e->getMessage());
      }
    }
    // Non-extending neighbors stay legal: "sw" derives --sw-, which
    // --swatch-… never extends hyphen-wise.
    $this->declareScales(['sw' => ['label' => 'Sw', 'shape' => 'color']]);
    $this->assertArrayHasKey('sw', $this->container->get('daedalus_stylish.style_guide')->getTokenScales(self::THEME));
  }

  /**
   * The derivation helpers are the one naming rule, both directions.
   */
  public function testDerivationHelpers(): void {
    $this->assertSame('--swatch-hero-fade', StyleGuide::swatchVariable('hero-fade'));
    $this->assertSame('hero-fade', StyleGuide::swatchReference('--swatch-hero-fade'));
    $this->assertNull(StyleGuide::swatchReference('--color-accent'));
    $this->assertNull(StyleGuide::swatchReference('--swatch-'));
    // The token spine never claims the swatch namespace: no declared scale
    // can carry the prefix, so reverse token lookup passes it by.
    $scales = $this->container->get('daedalus_stylish.style_guide')->getTokenScales(self::THEME);
    $this->assertNull(StyleGuide::tokenReference('--swatch-hero', $scales));
  }

  /**
   * Style values: var(--swatch-*) errors strict, passes permissive.
   */
  public function testStyleValueReferenceCheck(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->mintSwatch('hero-fade', 'gradient', 'linear-gradient(#1a1a2e, #e94560)');
    $map = ['entity:' . self::UUID => ['default' => ['background-image' => 'var(--swatch-hero-fade)']]];
    $missing = ['entity:' . self::UUID => ['default' => ['background-image' => 'var(--swatch-nope)']]];

    // Permissive: unresolved swatch references are inert, never unsafe.
    $this->assertSame([], $validator->validate($missing, ['theme' => self::THEME]));

    $this->enforce('strict');
    $this->assertSame([], $validator->validate($map, ['theme' => self::THEME]));
    $this->assertSingleError($validator->validate($missing, ['theme' => self::THEME]), 'STYLE_SWATCH_NOT_PUBLISHED');

    // Publication is the gate, not existence: all published swatches are
    // live, so an unpublished swatch is not referenceable.
    $this->mintSwatch('draft')->setUnpublished()->save();
    $draft = ['entity:' . self::UUID => ['default' => ['background-image' => 'var(--swatch-draft)']]];
    $this->assertSingleError($validator->validate($draft, ['theme' => self::THEME]), 'STYLE_SWATCH_NOT_PUBLISHED');

    // The reserved namespace is checked even on sites declaring no token
    // scales at all — it belongs to swatches, not to any scale.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', []);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->assertSingleError($validator->validate($missing, ['theme' => self::THEME]), 'STYLE_SWATCH_NOT_PUBLISHED');
  }

  /**
   * Skin token values: swatch references check like token references.
   */
  public function testTokenValueReferenceCheck(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->mintSwatch('brand');
    $this->enforce('strict');

    // A skin token may reference a published swatch...
    $this->assertSame([], $validator->validateTokens(SkinTokensItem::canonicalizeTokens(['color' => ['accent' => 'var(--swatch-brand)']]), ['theme' => self::THEME]));
    // ...but never an unpublished or missing one.
    $errors = $validator->validateTokens(SkinTokensItem::canonicalizeTokens(['color' => ['accent' => 'var(--swatch-nope)']]), ['theme' => self::THEME]);
    $error = $this->assertSingleError($errors, 'STYLE_SWATCH_NOT_PUBLISHED');
    $this->assertSame('color.accent.value', $error->getPath());
  }

}
