<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the skin content entity and the token validation seam.
 *
 * Skins host TOKENS as data ({scale: {name: {value, description,
 * offered}}}) against the scales the site declares in code; every write
 * path (the daedalus_blueprint field handler, the admin form, the disk
 * lane) converges on StyleMapValidator::validateTokens() over the
 * canonical record shape, and every authored door accepts a bare value as
 * shorthand for its record (SkinTokensItem::canonicalizeTokens()). Which
 * skin the site wears is the active design system's call
 * (SkinManagerTest).
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
    $this->installEntitySchema('daedalus_font');
    // The skin save's token usage chokepoint (a token may reference
    // another token: accent-hover below).
    $this->installSchema('daedalus_stylish', ['daedalus_token_usage', 'daedalus_font_usage']);
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
   * Validates tokens against the declared scales, through the door.
   *
   * The seam validates the canonical shape; like every authored door this
   * canonicalizes first, so the fixtures may spell bare values.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateTokens(array $tokens): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validateTokens(SkinTokensItem::canonicalizeTokens($tokens), ['theme' => self::THEME]);
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
   *
   * Tokens store as records: the setter is an authored door, so a bare
   * value lands as {value, description, offered} with the defaults filled
   * and a record lands as written.
   */
  public function testSkinEntity(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora Dark']);
    $accent = ['default' => '#1a1a2e', 'dark' => '#e8e8f0'];
    $tokens = [
      'space' => ['4' => ['value' => '1rem', 'description' => '', 'offered' => TRUE]],
      'color' => [
        'accent' => ['value' => $accent, 'description' => 'The one hue.', 'offered' => FALSE],
      ],
    ];
    $skin->setPackageTokens([
      'space' => ['4' => '1rem'],
      'color' => [
        'accent' => ['value' => $accent, 'description' => 'The one hue.', 'offered' => FALSE],
      ],
    ])->save();

    $loaded = DaedalusStylishSkin::load($skin->id());
    $this->assertSame('aurora_dark', $loaded->getMachineName());
    $this->assertSame($tokens, $loaded->packageTokens());
    $this->assertSame([
      'space' => ['4' => '1rem'],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
    ], SkinTokensItem::values($tokens));
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
        'color' => [
          'accent' => [
            'offered' => FALSE,
            'description' => 'The one hue.',
            'value' => ['dark' => '#e8e8f0', 'default' => '#1a1a2e'],
          ],
        ],
        'space' => ['4' => ['offered' => TRUE, 'value' => '1rem', 'description' => '']],
      ],
    ];
    $this->assertFalse($loaded->hasNewDefaults($reordered));
    $loaded->setPackageTokens(['space' => ['4' => '2rem']]);
    $this->assertTrue($loaded->isModified());
    $loaded->setPackageTokens($tokens);
    $this->assertFalse($loaded->isModified());
    // A record fact is content too: the same value with a description is
    // a modification.
    $loaded->setPackageTokens(['space' => ['4' => ['value' => '1rem', 'description' => 'The base step.']]] + $tokens);
    $this->assertTrue($loaded->isModified());
    $loaded->setPackageTokens($tokens);
    $loaded->setPackageDefaults(['kind:container' => ['' => ['default' => ['padding' => '2rem']]]]);
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
    $this->assertSingleError($this->validateTokens(['space' => ['4' => 'red']]), 'INVALID_STYLE_VALUE', 'space.4.value');
    $this->assertSingleError($this->validateTokens(['weight' => ['bold' => 'bold']]), 'INVALID_STYLE_VALUE', 'weight.bold.value');
    $this->assertSingleError($this->validateTokens(['color' => ['accent' => '12']]), 'INVALID_STYLE_VALUE', 'color.accent.value');
    $this->assertSingleError($this->validateTokens(['align' => ['main' => 'left']]), 'INVALID_STYLE_VALUE', 'align.main.value');
    // Value hygiene: braces and semicolons never pass, no matter the shape.
    $this->assertSingleError($this->validateTokens(['transition' => ['fast' => 'fast; }']]), 'INVALID_STYLE_VALUE', 'transition.fast.value');
    // Media references are property machinery, not token values.
    $this->assertSingleError($this->validateTokens(['transition' => ['fast' => "url('media:abc')"]]), 'INVALID_STYLE_VALUE', 'transition.fast.value');
  }

  /**
   * A condition-keyed value requires its default entry.
   */
  public function testConditionedValueRequiresDefault(): void {
    $this->assertSingleError($this->validateTokens(['color' => ['accent' => ['dark' => '#fff']]]), 'TOKEN_DEFAULT_REQUIRED', 'color.accent.value');
  }

  /**
   * A token record carries value, description and offered, nothing else.
   *
   * The seam validates the canonical shape: a record's facts are typed,
   * an unknown key is refused (the store never grows a fourth fact by
   * accident), and an entry that is no record at all is refused with the
   * shorthand named in the corrective.
   */
  public function testRecordValidation(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame([], $this->validateTokens([
      'space' => ['4' => ['value' => '1rem', 'description' => 'The base step.', 'offered' => FALSE]],
    ]));
    $this->assertSingleError($this->validateTokens([
      'space' => ['4' => ['value' => '1rem', 'stops' => TRUE]],
    ]), 'INVALID_TOKEN_RECORD', 'space.4');
    $this->assertSingleError($this->validateTokens([
      'space' => ['4' => ['value' => '1rem', 'description' => ['no']]],
    ]), 'INVALID_TOKEN_RECORD', 'space.4.description');
    $this->assertSingleError($this->validateTokens([
      'space' => ['4' => ['value' => '1rem', 'offered' => 'yes']],
    ]), 'INVALID_TOKEN_RECORD', 'space.4.offered');
    // The seam itself admits records only: a bare value reaching it
    // un-canonicalized is a door that skipped its move.
    $errors = $validator->validateTokens(['space' => ['4' => '1rem']], ['theme' => self::THEME]);
    $this->assertSingleError($errors, 'INVALID_TOKEN_RECORD', 'space.4');
    $this->assertStringContainsString('shorthand', $errors[0]->getHint());
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
    ]), 'INVALID_STYLE_CONDITION', 'color.accent.value.sideways');
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'narrow+medium' => '#222']],
    ]), 'STYLE_CONDITION_AXIS_CONFLICT', 'color.accent.value.narrow+medium');
    // Interaction states are per-element facts; they cannot condition a
    // token definition.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'hover' => '#222']],
    ]), 'TOKEN_CONDITION_AXIS_INVALID', 'color.accent.value.hover');
    // Two spellings of one compound would merge on canonicalization.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'narrow+dark' => '#222', 'dark+narrow' => '#333']],
    ]), 'DUPLICATE_STYLE_CONDITION', 'color.accent.value.dark+narrow');
    // Conditions do not nest.
    $this->assertSingleError($this->validateTokens([
      'color' => ['accent' => ['default' => '#111', 'dark' => ['default' => '#222']]],
    ]), 'INVALID_TOKEN_VALUE', 'color.accent.value.dark');
  }

  /**
   * A value that is neither scalar nor a condition map is rejected.
   */
  public function testInvalidValueShapeFails(): void {
    $this->assertSingleError($this->validateTokens(['space' => ['4' => ['1rem', '2rem']]]), 'INVALID_TOKEN_VALUE', 'space.4.value');
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
    // same normalization the style map write paths apply, inside the
    // record a bare value canonicalizes to.
    $result = $handler->deserialize(['color' => ['accent' => ['default' => '#111', 'dark+narrow' => '#222']]], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertSame([
      'color' => [
        'accent' => ['value' => ['default' => '#111', 'narrow+dark' => '#222'], 'description' => '', 'offered' => TRUE],
      ],
    ], json_decode($result->getValue(), TRUE));

    // A record lands as written, its keys in stored order.
    $result = $handler->deserialize([
      'space' => ['4' => ['offered' => FALSE, 'value' => '1rem', 'description' => 'The base step.']],
    ], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertSame([
      'space' => ['4' => ['value' => '1rem', 'description' => 'The base step.', 'offered' => FALSE]],
    ], json_decode($result->getValue(), TRUE));
    $result = $handler->deserialize(['space' => ['4' => ['value' => '1rem', 'stops' => TRUE]]], $definition);
    $this->assertSame('fields.tokens.space.4', $result->getErrors()[0]->getPath());

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
