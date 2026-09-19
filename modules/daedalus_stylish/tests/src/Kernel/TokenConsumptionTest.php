<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore blorp
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests token CONSUMPTION: var() references and scale constraints.
 *
 * The delivery half compiles tokens to custom properties; this covers
 * the write side. Style values reference tokens as literal
 * var(--{prefix}-{name}); the validator resolves each reference to its scale
 * (StyleGuide::tokenReference() — prefixes are unique and non-extending by
 * grammar) and checks it against the ACTIVE skin's minted tokens: pass on
 * permissive sites (an unresolved var is inert, never unsafe; the client lints
 * it as a warning), error on strict ones. A scale-referencing constraint
 * ({scale: space}) closes a property's value space to the scale's minted tokens
 * as var() references plus any allowed entries beside it, while constraints are
 * law (guarded and strict); open mode demotes every constraint to
 * offer-shaping. Token values may reference tokens of the same skin, checked
 * against the submitted map on strict sites. The client payload serves the
 * active skin's tokens so pickers, autocomplete, and the lint share the
 * server's vocabulary.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class TokenConsumptionTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The client payload serves the published swatches, so every build()
    // consumer needs the swatch entity schema.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ]);
    // The entity kind's guide: spacing declared, gap constrained to the
    // space scale, padding to the scale plus the "auto" keyword.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'entity' => [
        'property_groups' => [
          'spacing' => [
            'constraints' => [
              'gap' => ['scale' => 'space'],
              'padding' => ['scale' => 'space', 'allowed' => ['auto']],
            ],
          ],
          'background' => [],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setTokens([
      'space' => ['4' => '1rem', 'gutter' => '2.5rem'],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
    ])->save();
    $this->activateSkin($skin);
  }

  /**
   * Validates one entity-keyed style entry.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateStyles(array $properties): array {
    return $this->container->get('daedalus_stylish.style_map_validator')->validate(
      ['entity:' . self::UUID => ['default' => $properties]],
      ['theme' => self::THEME],
    );
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
   * Permissive sites pass every var() reference: unresolved is inert.
   */
  public function testPermissiveReferencesPass(): void {
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--space-4)']));
    // Unminted in a declared namespace: the client warns, the server
    // accepts (token deletion is tolerate-with-warning by design).
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--space-9)']));
    // Outside every declared namespace: not the spine's vocabulary.
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--daedalus-ui-accent)']));
  }

  /**
   * Strict sites reject unminted references in declared namespaces only.
   */
  public function testStrictMintedReferences(): void {
    $this->enforce('strict');
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--space-4)']));
    $this->assertSame([], $this->validateStyles(['background-color' => 'var(--color-accent)']));
    // References resolve inside calc() and compound values too.
    $this->assertSame([], $this->validateStyles(['margin' => 'calc(var(--space-gutter) * 2)']));
    // Out-of-namespace variables still pass: the guide cannot enumerate
    // theme-owned custom properties.
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--daedalus-ui-accent)']));

    $error = $this->assertSingleError($this->validateStyles(['margin' => 'var(--space-9)']), 'STYLE_TOKEN_NOT_MINTED');
    $this->assertSame('entity:' . self::UUID . '.default.margin', $error->getPath());
    $this->assertSame(['4', 'gutter'], $error->getContext()['minted']);
    // The hint pairs each material with its value — the model patches from
    // the corrective alone, so names-only would trade intent for the
    // nearest name.
    $this->assertStringContainsString('var(--space-4) = 1rem, var(--space-gutter) = 2.5rem', $error->getHint());
    $this->assertSingleError($this->validateStyles(['margin' => 'calc(var(--space-9) + 1rem)']), 'STYLE_TOKEN_NOT_MINTED');

    // A condition-keyed minted value reads as ONE token's values, never as
    // part of its name.
    $error = $this->assertSingleError($this->validateStyles(['background-color' => 'var(--color-accent-dark)']), 'STYLE_TOKEN_NOT_MINTED');
    $this->assertStringContainsString('var(--color-accent) = #1a1a2e (dark: #e8e8f0)', $error->getHint());
  }

  /**
   * A scale constraint closes the value space to the scale's tokens.
   */
  public function testScaleConstraint(): void {
    $this->assertSame([], $this->validateStyles(['gap' => 'var(--space-4)']));
    $this->assertSame([], $this->validateStyles(['gap' => 'var(--space-gutter)']));

    // A literal value is off the scale — the corrective lists the legal
    // var() references, each paired with the value it resolves to (the
    // repair for "1rem" should be var(--space-4), not any random token).
    $error = $this->assertSingleError($this->validateStyles(['gap' => '1rem']), 'STYLE_VALUE_NOT_ON_SCALE');
    $this->assertSame(['var(--space-4)', 'var(--space-gutter)'], $error->getContext()['allowed']);
    $this->assertStringContainsString('var(--space-4) = 1rem, var(--space-gutter) = 2.5rem', $error->getHint());
    // So is an unminted token of the right namespace, and a token of the
    // wrong scale.
    $this->assertSingleError($this->validateStyles(['gap' => 'var(--space-9)']), 'STYLE_VALUE_NOT_ON_SCALE');
    $this->assertSingleError($this->validateStyles(['gap' => 'var(--color-accent)']), 'STYLE_VALUE_NOT_ON_SCALE');

    // Allowed entries beside the scale join the closed space — and the
    // hint's materials list (a keyword IS its own value, no pair).
    $this->assertSame([], $this->validateStyles(['padding' => 'auto']));
    $this->assertSame([], $this->validateStyles(['padding' => 'var(--space-4)']));
    $error = $this->assertSingleError($this->validateStyles(['padding' => '2rem']), 'STYLE_VALUE_NOT_ON_SCALE');
    $this->assertStringContainsString('var(--space-gutter) = 2.5rem, auto', $error->getHint());
  }

  /**
   * Open mode demotes every constraint to offer-shaping.
   *
   * The scale closure (allowed entries included) stops binding: any
   * recognized property with a grammatical value passes, and unminted
   * var() references stay inert. Recognition and grammar remain the
   * whole law.
   */
  public function testOpenModeDemotesConstraints(): void {
    $this->enforce('open');
    // Literal values off the scale pass: the constraint shapes offers,
    // never writes. padding's "allowed" list beside its scale demotes
    // with it.
    $this->assertSame([], $this->validateStyles(['gap' => '1rem']));
    $this->assertSame([], $this->validateStyles(['padding' => '2rem']));
    // On-scale references still pass, and unminted ones are inert (the
    // minting check belongs to strict alone).
    $this->assertSame([], $this->validateStyles(['gap' => 'var(--space-4)']));
    $this->assertSame([], $this->validateStyles(['gap' => 'var(--space-9)']));
    // Grammar is still law: gap is a dimension, not a keyword.
    $this->assertSingleError($this->validateStyles(['gap' => 'bouncy']), 'INVALID_STYLE_VALUE');
    // So is recognition: the property vocabulary never opens.
    $this->assertSingleError($this->validateStyles(['blorp' => '1rem']), 'INVALID_STYLE_PROPERTY');
  }

  /**
   * No active skin: nothing is minted, and the correctives say so.
   */
  public function testNoActiveSkin(): void {
    $this->deactivateDesignSystem();
    $error = $this->assertSingleError($this->validateStyles(['gap' => 'var(--space-4)']), 'STYLE_VALUE_NOT_ON_SCALE');
    $this->assertSame([], $error->getContext()['allowed']);
    $this->assertStringContainsString('no tokens are minted', $error->getHint());

    $this->enforce('strict');
    $this->assertSingleError($this->validateStyles(['margin' => 'var(--space-4)']), 'STYLE_TOKEN_NOT_MINTED');
  }

  /**
   * A skin change mid-request refreshes the validator's vocabulary.
   */
  public function testSkinChangeResetsVocabulary(): void {
    $this->enforce('strict');
    $this->assertSingleError($this->validateStyles(['margin' => 'var(--space-8)']), 'STYLE_TOKEN_NOT_MINTED');

    // Minting the token and revalidating in the same request must see the
    // fresh universe (the skin save resets the memo).
    $skin = $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin();
    $skin->setTokens(['space' => ['4' => '1rem', 'gutter' => '2.5rem', '8' => '2rem']])->save();
    $this->assertSame([], $this->validateStyles(['margin' => 'var(--space-8)']));
  }

  /**
   * Token values may reference tokens of the same skin (strict-checked).
   */
  public function testTokenValueReferences(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $tokens = [
      'color' => [
        'accent' => '#1a1a2e',
        'accent-hover' => 'var(--color-accent)',
      ],
    ];
    // Permissive: references pass structurally, minted or not.
    $this->assertSame([], $validator->validateTokens($tokens, ['theme' => self::THEME]));
    $orphan = ['color' => ['accent-hover' => 'var(--color-accent)']];
    $this->assertSame([], $validator->validateTokens($orphan, ['theme' => self::THEME]));

    // Strict: the universe is the SUBMITTED map — the skin's whole set.
    $this->enforce('strict');
    $this->assertSame([], $validator->validateTokens($tokens, ['theme' => self::THEME]));
    $errors = $validator->validateTokens($orphan, ['theme' => self::THEME]);
    $error = $this->assertSingleError($errors, 'STYLE_TOKEN_NOT_MINTED');
    $this->assertSame('color.accent-hover', $error->getPath());
    // The corrective pairs what the submitted map DOES mint on the scale
    // with each token's value: the nearest valid reference is one of these.
    $this->assertSame(['accent-hover'], $error->getContext()['minted']);
    $this->assertStringContainsString('Tokens this skin mints on the "color" scale: var(--color-accent-hover) = var(--color-accent).', $error->getHint());
    // Out-of-namespace variables pass in token values too.
    $this->assertSame([], $validator->validateTokens(['color' => ['accent' => 'var(--brand-accent)']], ['theme' => self::THEME]));
    // Condition-keyed values are checked per condition.
    $conditioned = ['color' => ['accent-hover' => ['default' => '#111111', 'dark' => 'var(--color-accent)']]];
    $errors = $validator->validateTokens($conditioned, ['theme' => self::THEME]);
    $this->assertSame('color.accent-hover.dark', $this->assertSingleError($errors, 'STYLE_TOKEN_NOT_MINTED')->getPath());
  }

  /**
   * The active skin's minted tokens reach the client as materials.
   *
   * They ride the roster's delivery payload beside the blueprint mirror
   * (one record per token, grouped by scale), never the guide payload,
   * and a pointer change rides the materials delta: the old skin's ids
   * leave as removes, the new skin's arrive as upserts.
   */
  public function testClientPayloadTokens(): void {
    $this->assertArrayNotHasKey('tokens', $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME));
    $this->assertContains('daedalus_skin_list', ClientStyleGuide::CACHE_TAGS);

    $tokens = [];
    foreach ($this->container->get('daedalus_blueprint.materials_delivery')->payload()['records'] as $record) {
      if ($record['kind'] === 'token') {
        $tokens[$record['group']][$record['label']] = $record['value'];
      }
    }
    $this->assertSame([
      'space' => ['4' => '1rem', 'gutter' => '2.5rem'],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
    ], $tokens);

    // No active skin: an empty vocabulary, never an error; the pointer
    // change tells the client which ids left.
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $change_set->flush();
    $this->deactivateDesignSystem();
    $records = $this->container->get('daedalus_blueprint.materials_delivery')->payload()['records'];
    $this->assertSame([], array_filter($records, static fn (array $record): bool => $record['kind'] === 'token'));
    $this->assertSame([
      'upsert' => [],
      'remove' => [
        ['kind' => 'token', 'id' => 'space/4'],
        ['kind' => 'token', 'id' => 'space/gutter'],
        ['kind' => 'token', 'id' => 'color/accent'],
      ],
    ], $change_set->flush());
  }

  /**
   * Variant styles get the same checks through the scope-only seam.
   */
  public function testScopeValidationChecksReferences(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->enforce('strict');
    // The universal block kind declares no groups here; use kind:entity,
    // whose injected declaration covers spacing.
    $styles = ['' => ['default' => ['margin' => 'var(--space-9)']]];
    $errors = $validator->validateAt($styles, Address::fromString('kind:entity'), ['theme' => self::THEME]);
    $this->assertSingleError($errors, 'STYLE_TOKEN_NOT_MINTED');
    $styles = ['' => ['default' => ['margin' => 'var(--space-4)']]];
    $this->assertSame([], $validator->validateAt($styles, Address::fromString('kind:entity'), ['theme' => self::THEME]));
  }

}
