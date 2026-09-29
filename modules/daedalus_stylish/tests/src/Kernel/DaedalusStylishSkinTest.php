<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the skin content entity and the token validation seam.
 *
 * Skins host token VALUES as data ({scale: {name: value}}) against the
 * scales the site declares in code; every write path (the daedalus_blueprint
 * field handler, the admin form, the disk lane) converges on
 * StyleMapValidator::validateTokens(). Which skin the site wears is the
 * active design system's call (SkinManagerTest).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishSkinTest extends KernelTestBase {

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
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installConfig(['daedalus_stylish']);
    // The scales tokens validate against: one per shape.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'weight' => ['label' => 'Weight', 'shape' => 'number'],
        'align' => ['label' => 'Alignment', 'shape' => 'keyword', 'keywords' => ['start', 'center', 'end']],
        'transition' => ['label' => 'Transition', 'shape' => 'text'],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Validates tokens against the declared scales.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateTokens(array $tokens): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validateTokens($tokens, ['theme' => self::THEME]);
  }

  /**
   * Asserts one error with the expected code and path.
   */
  protected function assertSingleError(array $errors, string $code, string $path): void {
    $this->assertCount(1, $errors, implode(' | ', array_map(static fn (BlueprintError $e) => $e->getCode() . '@' . $e->getPath(), $errors)));
    $this->assertSame($code, $errors[0]->getCode());
    $this->assertSame($path, $errors[0]->getPath());
  }

  /**
   * A skin is revisionable, publishable content with a stable machine name.
   */
  public function testSkinEntity(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora Dark']);
    $tokens = [
      'space' => ['4' => '1rem'],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
    ];
    $skin->setTokens($tokens)->save();

    $loaded = DaedalusStylishSkin::load($skin->id());
    $this->assertSame('aurora_dark', $loaded->getMachineName());
    $this->assertSame($tokens, $loaded->getTokens());
    $this->assertTrue($loaded->isPublished());
    $this->assertNull($loaded->getProvider());
    $this->assertFalse($loaded->isShipped());
    $this->assertFalse($loaded->isModified());

    // Machine names are unique: a colliding label gets a suffix.
    $second = DaedalusStylishSkin::create(['label' => 'Aurora Dark']);
    $second->save();
    $this->assertSame('aurora_dark_2', $second->getMachineName());

    // The shipped-source lane: one hash, two comparisons. The shipped
    // payload covers both tiers under stable keys ({tokens, defaults}).
    $loaded->setShippedSource('example_recipe', ['tokens' => $tokens, 'defaults' => []])->save();
    $this->assertTrue($loaded->isShipped());
    $this->assertFalse($loaded->isModified());
    $reordered = [
      'defaults' => [],
      'tokens' => [
        'color' => ['accent' => ['dark' => '#e8e8f0', 'default' => '#1a1a2e']],
        'space' => ['4' => '1rem'],
      ],
    ];
    $this->assertFalse($loaded->hasNewDefaults($reordered));
    $loaded->setTokens(['space' => ['4' => '2rem']]);
    $this->assertTrue($loaded->isModified());
    $loaded->setTokens($tokens);
    $this->assertFalse($loaded->isModified());
    $loaded->setDefaults(['kind:container' => ['' => ['default' => ['padding' => '2rem']]]]);
    $this->assertTrue($loaded->isModified());
  }

  /**
   * Valid tokens of every shape pass, including var() references.
   */
  public function testValidTokens(): void {
    $this->assertSame([], $this->validateTokens([
      'space' => ['4' => '1rem', '0' => '0', 'tight' => '-0.25rem'],
      'color' => [
        'accent' => ['default' => '#1a1a2e', 'dark' => 'oklch(0.85 0.1 250)'],
        'accent-hover' => 'var(--color-accent)',
      ],
      'weight' => ['bold' => '700'],
      'align' => ['main' => 'center'],
      'transition' => ['fast' => '150ms ease'],
    ]));
  }

  /**
   * Minting a token on an undeclared scale is an error, never a new scale.
   */
  public function testUnknownScaleFails(): void {
    $errors = $this->validateTokens(['radius' => ['sm' => '0.25rem']]);
    $this->assertSingleError($errors, 'TOKEN_SCALE_UNKNOWN', 'radius');
    $this->assertSame(['space', 'color', 'weight', 'align', 'transition'], $errors[0]->getContext()['valid_scales']);
  }

  /**
   * A scale entry must be a map of token names to values.
   */
  public function testNonMapScaleEntryFails(): void {
    $this->assertSingleError($this->validateTokens(['space' => ['1rem', '2rem']]), 'INVALID_TOKEN_SCALE_ENTRY', 'space');
  }

  /**
   * Token names are lowercase idents: they become the custom property tail.
   */
  public function testInvalidTokenNameFails(): void {
    $this->assertSingleError($this->validateTokens(['color' => ['Accent' => '#fff']]), 'INVALID_TOKEN_NAME', 'color.Accent');
    $this->assertSingleError($this->validateTokens(['color' => ['-accent' => '#fff']]), 'INVALID_TOKEN_NAME', 'color.-accent');
  }

  /**
   * Values validate against their scale's shape via the scalar paths.
   */
  public function testShapeViolations(): void {
    $this->assertSingleError($this->validateTokens(['space' => ['4' => 'red']]), 'INVALID_STYLE_VALUE', 'space.4');
    $this->assertSingleError($this->validateTokens(['weight' => ['bold' => 'bold']]), 'INVALID_STYLE_VALUE', 'weight.bold');
    $this->assertSingleError($this->validateTokens(['color' => ['accent' => '12']]), 'INVALID_STYLE_VALUE', 'color.accent');
    $this->assertSingleError($this->validateTokens(['align' => ['main' => 'left']]), 'INVALID_STYLE_VALUE', 'align.main');
    // Value hygiene: braces and semicolons never pass, no matter the shape.
    $this->assertSingleError($this->validateTokens(['transition' => ['fast' => 'fast; }']]), 'INVALID_STYLE_VALUE', 'transition.fast');
    // Media references are property machinery, not token values.
    $this->assertSingleError($this->validateTokens(['transition' => ['fast' => "url('media:abc')"]]), 'INVALID_STYLE_VALUE', 'transition.fast');
  }

  /**
   * A condition-keyed value requires its default entry.
   */
  public function testConditionedValueRequiresDefault(): void {
    $this->assertSingleError($this->validateTokens(['color' => ['accent' => ['dark' => '#fff']]]), 'TOKEN_DEFAULT_REQUIRED', 'color.accent');
  }

  /**
   * Condition keys use the style map vocabulary, restricted to token axes.
   */
  public function testConditionValidation(): void {
    // The full compound grammar rides along: any part order, one condition
    // per axis.
    $this->assertSame([], $this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'narrow+dark' => '#222', 'medium' => '#333']],
    ]));
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'sideways' => '#222']],
    ]), 'INVALID_STYLE_CONDITION', 'color.accent.sideways');
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'narrow+medium' => '#222']],
    ]), 'STYLE_CONDITION_AXIS_CONFLICT', 'color.accent.narrow+medium');
    // Interaction states are per-element facts; they cannot condition a
    // token definition.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'hover' => '#222']],
    ]), 'TOKEN_CONDITION_AXIS_INVALID', 'color.accent.hover');
    // Two spellings of one compound would merge on canonicalization.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'narrow+dark' => '#222', 'dark+narrow' => '#333']],
    ]), 'DUPLICATE_STYLE_CONDITION', 'color.accent.dark+narrow');
    // Conditions do not nest.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'dark' => ['default' => '#222']]],
    ]), 'INVALID_TOKEN_VALUE', 'color.accent.dark');
  }

  /**
   * A value that is neither scalar nor a condition map is rejected.
   */
  public function testInvalidValueShapeFails(): void {
    $this->assertSingleError($this->validateTokens(['space' => ['4' => ['1rem', '2rem']]]), 'INVALID_TOKEN_VALUE', 'space.4');
  }

  /**
   * The blueprint lane: same engine, canonical storage, re-rooted errors.
   */
  public function testFieldHandler(): void {
    $handler = $this->container->get('daedalus_stylish.field_handler.skin_tokens');
    $skin = DaedalusStylishSkin::create(['label' => 'Test']);
    $definition = $skin->getFieldDefinition('tokens');
    $this->assertTrue($handler->applies($definition));

    // Compound conditions store canonically (axis-declaration order), the
    // same normalization the style map write paths apply.
    $result = $handler->deserialize(['color' => ['accent' => ['default' => '#111', 'dark+narrow' => '#222']]], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertSame(['color' => ['accent' => ['default' => '#111', 'narrow+dark' => '#222']]], json_decode($result->getValue(), TRUE));

    // An empty map short-circuits at the base handler (empty in, empty
    // out), like every other handler; the item's setStyleMap() empties it.
    $this->assertFalse($handler->deserialize([], $definition)->hasErrors());

    // Errors re-root onto the field's path space.
    $result = $handler->deserialize(['radius' => ['sm' => '0.25rem']], $definition);
    $this->assertTrue($result->hasErrors());
    $this->assertSame('fields.tokens.radius', $result->getErrors()[0]->getPath());
    $this->assertSame('TOKEN_SCALE_UNKNOWN', $result->getErrors()[0]->getCode());

    // A non-map payload is rejected before the engine runs.
    $this->assertSame('INVALID_SKIN_TOKENS', $handler->deserialize('nope', $definition)->getErrors()[0]->getCode());

    // The AI schema serves the scales with prefixes resolved and the
    // token-axis condition vocabulary.
    $additions = $handler->getSchemaAdditions($definition);
    $this->assertSame(['space', 'color', 'weight', 'align', 'transition'], array_keys($additions['token_scales']['scales']));
    $this->assertSame('space', $additions['token_scales']['scales']['space']['prefix']);
    $this->assertSame(['start', 'center', 'end'], $additions['token_scales']['scales']['align']['keywords']);
    $this->assertSame(['default', 'narrow', 'medium', 'dark', 'reduced-motion'], array_keys($additions['token_scales']['conditions']));
  }

}
