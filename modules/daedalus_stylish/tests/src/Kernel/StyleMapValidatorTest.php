<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore buton dispaly divv
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\ConditionClause;
use Drupal\daedalus_blueprint\Materials\EmitGate;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * Tests style-map resolution through the spine's instance namespaces.
 *
 * The one part-resolution rule: exact part, then the owner's own pattern
 * part, then the site instance namespaces (the spine's `instances:` lane).
 * Minted identity carriers are content whose ownership varies by render
 * shape — a COMPOSED block's carriers mount on its own markup and style at
 * `block:{uuid}::.s-x`, an open render's carriers ride the owning field's
 * key — so the instance declaration must answer for BOTH owner shapes
 * without either owner declaring anything, and its property groups keep
 * minted classes closed-world (constraints enforce, strict rejects
 * undeclared properties). Owner declarations still win: the namespaces are
 * the fallback vocabulary, never an override.
 *
 * The fixture namespaces layer through daedalus_stylish_nested_test's
 * state-driven alter hook, the same lane its nested-grammar fixtures use.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleMapValidatorTest extends KernelTestBase {

  use HostTrait;
  use DesignSystemActivationTrait;

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
    'text',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'entity_test',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    'daedalus_stylish_nested_test',
  ];

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The style map validator under test.
   */
  protected StyleMapValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // All three material storages: the roster reads them on every build,
    // and activating a design system (the enforcement door) recompiles
    // the delivery files over the same four.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-*, var() and family references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installEntitySchema('user');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
  }

  /**
   * Layers declarations over a guide dimension and rebuilds the guide.
   */
  protected function layer(string $dimension, array $declarations): void {
    $state = $this->container->get('state');
    $key = "daedalus_stylish_nested_test.$dimension";
    $state->set($key, array_merge($state->get($key, []), $declarations));
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Collects error codes keyed by path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors from the validator.
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The declared instance namespace for these cases.
   */
  protected function layerInstanceNamespace(): void {
    $this->layer('instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph', 'h2' => 'Heading 2', 'li' => 'List item'],
        'property_groups' => [
          'typography' => [
            'constraints' => [
              'font-size' => ['max' => '2rem'],
            ],
          ],
        ],
      ],
    ]);
  }

  /**
   * Instance parts resolve on every owner shape, accepted and typed.
   *
   * A block-key instance part (`block:{uuid}::.s-x`, the composed-carrier
   * shape) resolves through the namespace with NO block declaration
   * carrying a pattern part, and a field-key part (the open-render shape)
   * keeps resolving with the retired field-kind pattern declaration gone:
   * the same write is accepted when constrained-legal and rejected when
   * over the namespace's constraint, on both keys.
   */
  public function testInstancePartsResolveOnBothOwnerShapes(): void {
    $this->layerInstanceNamespace();
    $uuid = self::UUID;

    // Block-key part write accepted + typed (the composed-carrier shape).
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::.s-a3" => ['default' => ['font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::.s-a3" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["block:$uuid::.s-a3.default.font-size"]);

    // Field-key part still resolving (the open-render shape), through the
    // same one declaration.
    $this->assertSame([], $this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.s-a3.default.font-size"]);
  }

  /**
   * Strict mode: the namespace opens the key, properties stay closed.
   *
   * A minted class validates with no owner declaration on either key
   * shape, properties outside the namespace's groups are rejected, and a
   * part outside every declared namespace still fails as undeclared.
   */
  public function testStrictModeClosedWorld(): void {
    $this->layerInstanceNamespace();
    $this->enforce('strict');
    $uuid = self::UUID;

    $this->assertSame([], $this->validator->validate([
      "block:$uuid::.s-a3" => ['default' => ['font-size' => '1rem']],
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '1rem']],
    ]));

    $codes = $this->codes($this->validator->validate([
      "block:$uuid::.s-a3" => ['default' => ['padding' => '1rem']],
      "block:$uuid::.mystery" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["block:$uuid::.s-a3.default.padding"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["block:$uuid::.mystery"]);
  }

  /**
   * The resolution order: owner declarations win over the namespaces.
   *
   * An owner's own pattern part (here on the field kind) answers before
   * the site instance namespaces, so its tighter constraint governs the
   * field key while the block key — no owner pattern — still resolves
   * through the namespace's looser one.
   */
  public function testOwnerPatternWinsOverInstanceNamespace(): void {
    $this->layerInstanceNamespace();
    $this->layer('kinds', [
      'field' => [
        'elements' => [
          '.s-*' => [
            'label' => 'Owner text element',
            'property_groups' => [
              'typography' => [
                'constraints' => [
                  'font-size' => ['max' => '1rem'],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    $uuid = self::UUID;

    // The field key resolves the owner pattern: 1.5rem breaks its 1rem
    // max, which the namespace's 2rem max would have allowed.
    $codes = $this->codes($this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '1.5rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.s-a3.default.font-size"]);

    // The block key has no owner pattern, so the namespace still governs.
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::.s-a3" => ['default' => ['font-size' => '1.5rem']],
    ]));
  }

  /**
   * Constrains the shipped base section for the tag-tier cases.
   *
   * Layers over daedalus_stylish's own base declaration (the kernel site
   * discovers daedalus_stylish.page_builder.yml): p gains a font-size ceiling,
   * and em (a legal BASE_TAGS tag the shipped section does not declare) stays
   * undeclared.
   */
  protected function layerBase(): void {
    $this->layer('site', [
      'base' => [
        'p' => [
          'property_groups' => [
            'typography' => [
              'constraints' => [
                'font-size' => ['max' => '2rem'],
              ],
            ],
          ],
        ],
      ],
    ]);
  }

  /**
   * Layers a base section declaring p (as layerBase()) and a (color).
   *
   * For the admission tests: a declared link tag beside the paragraph,
   * so a qualifier on `a` is judged on strict rather than the tag.
   */
  protected function layerBaseAndLinks(): void {
    $this->layer('site', [
      'base' => [
        'p' => [
          'property_groups' => [
            'typography' => [
              'constraints' => [
                'font-size' => ['max' => '2rem'],
              ],
            ],
          ],
        ],
        'a' => ['property_groups' => ['color' => []]],
      ],
    ]);
  }

  /**
   * Tag-tier keys resolve through the site's base section, on any owner.
   *
   * A "{key}::p" key (sub without the leading dot) is the element-scoped
   * tag tier: it validates against base.{tag}.property_groups on both
   * owner shapes (block key and field key), constraints enforcing exactly
   * as on a minted part, and an undeclared tag is rejected in EVERY
   * enforcement mode with a corrective naming the declared base tags.
   * Base is the one declaration of a prose tag's styleability: a tag the
   * instance lane mints but base does not declare is no target, and a
   * tag base declares that never mints (links) is.
   */
  public function testTagTierResolvesFromBase(): void {
    $this->layerInstanceNamespace();
    $this->layerBase();
    $uuid = self::UUID;

    // Both owner shapes accept a declared base tag, typed by base's
    // constraint. li is a declared base tag like any other, so
    // "{key}::li" is plain grammar; a is styleable without ever minting.
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::p" => ['default' => ['font-size' => '1rem']],
      "field:$uuid:field_body::h2" => ['default' => ['font-size' => '1.5rem']],
      "block:$uuid::li" => ['default' => ['font-size' => '1rem']],
      "field:$uuid:field_body::li" => ['default' => ['font-size' => '1rem']],
      "block:$uuid::a" => ['default' => ['color' => 'red']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::p" => ['default' => ['font-size' => '3rem']],
      "field:$uuid:field_body::p" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["block:$uuid::p.default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::p.default.font-size"]);

    // The widened block-level tags accept the callout writes a minted
    // part makes (background + border), so no tag-tier write regressed.
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::p" => ['default' => ['background-color' => 'red', 'border-radius' => '4px']],
      "block:$uuid::blockquote" => ['default' => ['background-color' => 'red']],
    ]));

    // An undeclared tag follows the grant posture like every other
    // vocabulary: on the default (guarded) posture it is admitted
    // grant-less, grammar-checked only, whether BASE_TAGS lists it (em)
    // or not (div); the instance lane's identity tags never widen the
    // tier (em stays grant-less: no base constraint binds). A tag the
    // browser does not know is a typo in every mode, the tier's nearest
    // names suggested.
    $this->layer('instances', ['.s-*' => ['tags' => ['em' => 'Emphasis']]]);
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::em" => ['default' => ['font-size' => '3rem']],
      "block:$uuid::div" => ['default' => ['font-size' => '1rem']],
    ]));
    $errors = $this->validator->validate([
      "block:$uuid::divv" => ['default' => ['font-size' => '1rem']],
    ]);
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $this->codes($errors)["block:$uuid::divv"]);
    $this->assertSame('tag', $errors[0]->getContext()['kind']);
    $this->assertContains('div', $errors[0]->getContext()['similar']);
    $this->assertStringContainsString('Did you mean', $errors[0]->getHint());
    // Strict refuses the undeclared tag naming the declared base tags.
    $this->enforce('strict');
    $errors = $this->validator->validate([
      "block:$uuid::em" => ['default' => ['font-size' => '1rem']],
      "block:$uuid::div" => ['default' => ['font-size' => '1rem']],
      "block:$uuid::divv" => ['default' => ['font-size' => '1rem']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["block:$uuid::em"]);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["block:$uuid::div"]);
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["block:$uuid::divv"]);
    $this->assertSame($this->container->get('daedalus_stylish.style_guide')->baseTags(self::THEME), $errors[0]->getContext()['declared_tags']);
    $this->assertContains('a', $errors[0]->getContext()['declared_tags']);
    $this->assertNotContains('em', $errors[0]->getContext()['declared_tags']);
    $this->assertStringContainsString('declared base tags may be named:', $errors[0]->getHint());
  }

  /**
   * Admission by mode is one rule for every vocabulary: the tag joins it.
   *
   * A tag the site's base section does not declare (nav, span, button:
   * none of them BASE_TAGS entries) is refused on a strict site
   * (STYLE_TAG_NOT_DECLARED) and admitted grant-less on an open or
   * guarded one, in a chain, inside a `:has()` argument and as a box's
   * host alike; a word the browser does not know is refused in every
   * mode with the tier's nearest names (STYLE_SELECTOR_UNKNOWN).
   */
  public function testTagAdmissionByMode(): void {
    $this->layerBaseAndLinks();
    $this->layer('block_types', [
      'text' => ['property_groups' => ['spacing' => []]],
    ]);
    $uuid = self::UUID;
    $map = [
      "block:$uuid::nav" => ['default' => ['display' => 'flex']],
      "block:$uuid::nav a" => ['default' => ['color' => 'red']],
      "block:$uuid::> d-text:has(button)" => ['default' => ['padding' => '1rem']],
      "block:$uuid::span::before" => ['default' => ['content' => '""']],
      "block:$uuid::p span" => ['default' => ['color' => 'red']],
    ];
    foreach (['guarded', 'open'] as $mode) {
      $this->enforce($mode);
      $this->assertSame([], $this->validator->validate($map), "$mode admits a tag the browser knows, grant-less.");
      // Grant-less means grammar-checked: the value grammar still holds.
      $codes = $this->codes($this->validator->validate([
        "block:$uuid::nav" => ['default' => ['display' => 'sideways']],
      ]));
      $this->assertSame(['INVALID_STYLE_VALUE'], $codes["block:$uuid::nav.default.display"]);
      $errors = $this->validator->validate([
        "block:$uuid::navv" => ['default' => ['display' => 'flex']],
        "block:$uuid::> d-text:has(buton)" => ['default' => ['padding' => '1rem']],
      ]);
      $codes = $this->codes($errors);
      $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["block:$uuid::navv"], "$mode refuses a tag the browser does not know.");
      $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["block:$uuid::> d-text:has(buton)"]);
      $this->assertSame(['nav'], array_slice($errors[0]->getContext()['similar'], 0, 1));
      $this->assertContains('button', $errors[1]->getContext()['similar']);
    }
    $this->enforce('strict');
    $codes = $this->codes($this->validator->validate($map + [
      "block:$uuid::navv" => ['default' => ['display' => 'flex']],
    ]));
    foreach (array_keys($map) as $key) {
      $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes[$key], "strict refuses $key");
    }
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["block:$uuid::navv"]);
  }

  /**
   * Literal condition clauses are admitted by mode, references everywhere.
   *
   * The WHEN side of the one admission rule: a published reference
   * ("hover", "narrow") is admitted in every mode; a literal the browser
   * knows (a feature query with its at-rule, a supports test, an
   * unpublished pseudo-class) is admitted on open and guarded and refused
   * on strict (INVALID_STYLE_CONDITION naming the published references
   * on its axis, the closed world); a literal the browser does not know
   * (a feature or value outside the wide condition tier, a pseudo-class
   * outside the selector tier, a supports declaration naming no
   * recognized property) is refused in every mode with the nearest
   * names; a position pseudo-class is selector text; a prefixless query
   * is corrected toward its at-rule spellings, on strict toward the
   * references; one clause per axis holds for literals too, and a
   * literal's axis is its feature's (a token value takes a literal width
   * band, never a pointer literal).
   */
  public function testLiteralConditionAdmissionByMode(): void {
    $uuid = self::UUID;
    $entry = static fn (array $conditions): array => ["entity:$uuid" => array_map(static fn (): array => ['padding' => '1rem'], array_flip($conditions))];
    $conditionCodes = fn (array $conditions): array => array_filter(
      $this->codes($this->validator->validate($entry($conditions))),
      static fn (string $path): bool => !str_ends_with($path, '.padding'),
      ARRAY_FILTER_USE_KEY,
    );
    $known = [
      '@media (hover: hover)',
      '@media (min-width: 900px)',
      '@container (min-width: 500px)',
      '@container (inline-size: 40em)',
      '@media (prefers-contrast: more)',
      '@media screen and (orientation: landscape)',
      '@media (color)',
      '@supports (display: grid)',
      '@supports not (display: grid) and selector(:has(a))',
      '@supports (--x: 1)',
      ':checked',
      ':visited',
      '@container (min-width: 500px)+hover',
      'narrow+@media (prefers-contrast: more)',
    ];
    $typos = [
      '@media (hovre: hover)' => ['hover'],
      '@media (hover: sometimes)' => [],
      '@media (min-width: wide)' => [],
      '@container (prefers-color-scheme: dark)' => [],
      '@container (min-width)' => [],
      '@supports (dispaly: grid)' => ['display'],
      '@supports foo' => [],
      ':hovre' => ['hover'],
      ':first-child' => [],
      ':not' => [],
    ];
    foreach (['guarded', 'open'] as $mode) {
      $this->enforce($mode);
      $this->assertSame([], $conditionCodes($known), "$mode admits every literal the browser knows.");
      // A literal spelling a published wrapper is that reference, so it
      // collides with the name as a duplicate, never as a second clause.
      $this->assertSame(['DUPLICATE_STYLE_CONDITION'], $conditionCodes(['narrow', '@container (max-width: 767px)'])["entity:$uuid.@container (max-width: 767px)"]);
      foreach ($typos as $condition => $similar) {
        $errors = $this->validator->validate($entry([$condition]));
        $this->assertCount(1, $errors, "$mode refuses \"$condition\" once");
        $this->assertSame('INVALID_STYLE_CONDITION', $errors[0]->getCode(), "$mode refuses \"$condition\"");
        $this->assertSame("entity:$uuid.$condition", $errors[0]->getPath());
        if ($similar !== []) {
          $this->assertSame($similar, array_slice($errors[0]->getContext()['similar'], 0, 1), "\"$condition\" suggests the nearest name");
        }
      }
      $errors = $this->validator->validate($entry([':first-child']));
      $this->assertStringContainsString('selector text', $errors[0]->getMessage());
      $this->assertStringContainsString('::&:first-child', $errors[0]->getHint());
      $errors = $this->validator->validate($entry(['@container (prefers-color-scheme: dark)']));
      $this->assertStringContainsString('"prefers-color-scheme" is a @media feature', $errors[0]->getHint());
      $errors = $this->validator->validate($entry(['@media (hover: sometimes)']));
      $this->assertSame(['none', 'hover'], $errors[0]->getContext()['valid_values']);
      // A prefixless query names no at-rule: corrected toward both.
      $errors = $this->validator->validate($entry(['(min-width: 900px)']));
      $this->assertSame(['INVALID_STYLE_CONDITION'], array_map(static fn ($error) => $error->getCode(), $errors));
      $this->assertSame(['@container (min-width: 900px)', '@media (min-width: 900px)'], $errors[0]->getContext()['spellings']);
      $this->assertStringContainsString('narrow, medium', $errors[0]->getHint());
      // One clause per axis holds for literals: a literal's axis is its
      // feature's, a published sibling's where one tests the feature.
      $codes = $conditionCodes([
        '@media (hover: hover)+@media (hover: none)',
        'narrow+@container (min-width: 500px)',
        '@media (prefers-color-scheme: light)+dark',
      ]);
      $this->assertSame(['STYLE_CONDITION_AXIS_CONFLICT'], $codes["entity:$uuid.@media (hover: hover)+@media (hover: none)"]);
      $this->assertSame(['STYLE_CONDITION_AXIS_CONFLICT'], $codes["entity:$uuid.narrow+@container (min-width: 500px)"]);
      $this->assertSame(['STYLE_CONDITION_AXIS_CONFLICT'], $codes["entity:$uuid.@media (prefers-color-scheme: light)+dark"]);
      // A token value varies on the width, scheme and motion axes: a
      // literal width band is admitted, a pointer literal is not.
      $this->assertTrue($this->validator->fitsTokenShape([
        'default' => '#111',
        '@container (min-width: 500px)' => '#222',
        '@media (prefers-color-scheme: light)' => '#333',
      ], ['shape' => 'color']), "$mode admits a literal on a token axis");
      $this->assertFalse($this->validator->fitsTokenShape(['default' => '#111', '@media (hover: hover)' => '#222'], ['shape' => 'color']), "$mode refuses a pointer literal on a token value");
    }
    $this->enforce('strict');
    $this->assertSame([], $conditionCodes(['hover', 'narrow', 'narrow+dark']), 'strict admits every reference.');
    $this->assertSame([], $conditionCodes(['@container (max-width: 767px)', ':hover+dark']), 'strict admits a reference however it is spelled: a literal equal to a published wrapper is that reference.');
    $codes = $conditionCodes($known);
    foreach ($known as $condition) {
      $this->assertSame(['INVALID_STYLE_CONDITION'], $codes["entity:$uuid.$condition"], "strict refuses the literal \"$condition\"");
    }
    $errors = $this->validator->validate($entry(['@media (min-width: 900px)']));
    $this->assertSame(['narrow', 'medium'], $errors[0]->getContext()['valid_conditions'], 'strict names the references on the literal\'s axis');
    $this->assertStringContainsString('narrow, medium', $errors[0]->getHint());
    $errors = $this->validator->validate($entry(['(min-width: 900px)']));
    $this->assertSame(['narrow', 'medium'], $errors[0]->getContext()['valid_conditions'], 'strict names the references for a prefixless query too');
    $this->assertStringContainsString('narrow, medium', $errors[0]->getHint());
    $errors = $this->validator->validate($entry(['@media (hover: hover)']));
    $this->assertContains('narrow', $errors[0]->getContext()['valid_conditions'], 'an axis nothing is published on names every reference');
    foreach (array_keys($typos) as $condition) {
      $this->assertSame(['INVALID_STYLE_CONDITION'], $conditionCodes([$condition])["entity:$uuid.$condition"], "strict refuses the typo \"$condition\"");
    }
  }

  /**
   * A literal condition the emit gate refuses is an error in every mode.
   *
   * The printer drops a block whose condition text the emit gate refuses
   * (EmitGate::admits()), so the validator asks the same question of a
   * literal at write time: text after the query, a mark no condition
   * carries, an unclosed parenthesis or string, a function that loads a
   * file. The grammar still reads each as a literal (the verdict is the
   * validator's), and every literal the validator admits is one the gate
   * admits.
   */
  public function testLiteralConditionTheEmitGateRefuses(): void {
    $uuid = self::UUID;
    $table = $this->container->get('plugin.manager.daedalus_stylish_condition_type')->getConditions();
    $refused = [
      '@supports (display: grid){}</style><b>',
      '@supports (display: grid) { }',
      '@supports (display: grid); a',
      '@supports (display: grid) /* x */',
      '@supports (display: grid) \\7b',
      '@supports (background: url(//example.com/a.png))',
      '@supports (content: "a)',
      '@media (hover: hover) {}</style>',
      '@media (hover: hover) x<y',
      '@container (min-width: 500px) a;b',
      '@container (min-width: 500px){}',
    ];
    foreach (['guarded', 'open', 'strict'] as $mode) {
      $this->enforce($mode);
      foreach ($refused as $condition) {
        $clause = ConditionClause::clause($condition, $table);
        $this->assertSame(ConditionClause::LITERAL, $clause['kind'], "the grammar reads \"$condition\" as a literal");
        $this->assertFalse(EmitGate::admits($clause['css']), "the emit gate refuses \"$condition\"");
        $errors = $this->validator->validate(["entity:$uuid" => [$condition => ['padding' => '1rem']]]);
        $this->assertCount(1, $errors, "$mode refuses \"$condition\" once");
        $this->assertSame('INVALID_STYLE_CONDITION', $errors[0]->getCode());
        $this->assertSame("entity:$uuid.$condition", $errors[0]->getPath());
        $this->assertStringContainsString('not text a condition can print', $errors[0]->getMessage(), "$mode says why \"$condition\" is refused");
        // In a compound, and as a token value's condition, alike.
        $errors = $this->validator->validate(["entity:$uuid" => ["hover+$condition" => ['padding' => '1rem']]]);
        $this->assertSame(['INVALID_STYLE_CONDITION'], array_map(static fn ($error) => $error->getCode(), $errors), "$mode refuses \"$condition\" in a compound");
        $this->assertFalse($this->validator->fitsTokenShape(['default' => '#111', $condition => '#222'], ['shape' => 'color']), "$mode refuses \"$condition\" on a token value");
      }
    }
    // What the validator admits, the gate admits: no admitted literal is
    // dropped at print time.
    $this->enforce('guarded');
    $admitted = [
      '@media (hover: hover)',
      '@container (min-width: 500px)',
      '@supports not (display: grid) and selector(:has(a))',
      '@supports (--x: 1)',
      ':checked',
    ];
    foreach ($admitted as $condition) {
      $this->assertSame([], $this->validator->validate(["entity:$uuid" => [$condition => ['padding' => '1rem']]]));
      $this->assertTrue(EmitGate::admits(ConditionClause::clause($condition, $table)['css']), "the emit gate admits \"$condition\"");
    }
  }

  /**
   * An attribute value escapes the gated marks, or the key is refused.
   *
   * The selector grammar prints ";", "{", "}", "<" and ">" inside an
   * attribute value as hex escapes, so the canonical key carries none raw
   * and its selector passes the emit gate; a key spelling one raw is not
   * canonical and is refused as a key at write time.
   */
  public function testAttributeValueMarksAreEscapedOrRefused(): void {
    $this->layerBaseAndLinks();
    $uuid = self::UUID;
    $this->enforce('guarded');
    foreach (['</style>', 'a;b', 'a{b}', 'a>b'] as $value) {
      $raw = "container:$uuid::p[title=\"$value\"]";
      $errors = $this->validator->validate([$raw => ['default' => ['font-size' => '1rem']]]);
      $this->assertSame(['INVALID_STYLE_KEY'], array_map(static fn ($error) => $error->getCode(), $errors), "a raw \"$value\" in an attribute value is refused");
      $this->assertSame($raw, $errors[0]->getPath());
      $selector = RelativeSelector::parse("p[title=\"$value\"]");
      $this->assertNotNull($selector, 'the grammar reads the raw spelling');
      $canonical = "container:$uuid::" . $selector->print();
      $this->assertNotSame($raw, $canonical);
      $this->assertTrue(EmitGate::admitsSelector($selector->print()), 'the canonical spelling passes the emit gate');
      $this->assertSame([], $this->validator->validate([$canonical => ['default' => ['font-size' => '1rem']]]), "the canonical spelling of \"$value\" is admitted");
    }
  }

  /**
   * Class and pseudo-class qualifiers are admitted by mode on every compound.
   *
   * A class qualifier is a declared part where one matches (every mode),
   * else a markup class: refused on strict (STYLE_CLASS_NOT_DECLARED
   * naming the declared parts), admitted on open and guarded ON THE
   * COMPOUND'S OWN LANDING (the landing's constraint still binds, since a
   * qualifier never moves the landing). A pseudo-class qualifier is a
   * published condition where one is spelled by it (every mode, in a
   * chain, on the anchor, inside `:not()` and `:has()`), else a state the
   * browser knows: refused on strict (STYLE_STATE_NOT_DECLARED naming the
   * published states), admitted on open and guarded; a pseudo-class the
   * browser does not know is refused everywhere with the nearest names.
   */
  public function testQualifierAdmissionByMode(): void {
    $this->layerBaseAndLinks();
    $this->layer('block_types', [
      'text' => [
        'property_groups' => ['typography' => ['constraints' => ['font-size' => ['max' => '1.5rem']]]],
        'elements' => ['.link' => ['label' => 'Link', 'property_groups' => ['typography' => []]]],
      ],
    ]);
    $uuid = self::UUID;
    $published = [
      "container:$uuid::p:hover a" => ['default' => ['color' => 'red']],
      "container:$uuid::&:hover > p" => ['default' => ['color' => 'red']],
      "container:$uuid::> d-text:not(:hover)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(a:focus-visible)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::p:focus-within" => ['default' => ['font-size' => '1rem']],
    ];
    $declared_part = [
      "container:$uuid::> d-text.link" => ['default' => ['font-size' => '1rem']],
    ];
    $markup = [
      "container:$uuid::p.active" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::&.open > p" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text.featured" => ['default' => ['font-size' => '1rem']],
    ];
    // A lone class inside `:not()` is a class PART compound by the
    // grammar (`:not(.active)` negates the part), judged by the part
    // posture: admitted by mode, strict naming the declared parts.
    $negated_part = [
      "container:$uuid::p:not(.active)" => ['default' => ['font-size' => '1rem']],
    ];
    $states = [
      "container:$uuid::a:visited" => ['default' => ['color' => 'red']],
      "container:$uuid::p:has(a:visited)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::&:focus-within:not(:disabled) > p" => ['default' => ['font-size' => '1rem']],
    ];
    $typos = [
      "container:$uuid::a:hovre" => ['default' => ['color' => 'red']],
      "container:$uuid::p:not(:hovered) a" => ['default' => ['color' => 'red']],
    ];
    foreach (['guarded', 'open'] as $mode) {
      $this->enforce($mode);
      $this->assertSame([], $this->validator->validate($published + $declared_part + $markup + $negated_part + $states), "$mode admits every qualifier the browser knows.");
      // The landing's constraint binds through the qualifier where
      // constraints are law (guarded; open demotes them to offers):
      // p.active is still base.p (font-size at most 2rem), d-text.featured
      // still the text type (1.5rem), the anchor's state still the
      // owner's chain.
      $bound = $mode === 'guarded' ? ['STYLE_VALUE_ABOVE_MAX'] : NULL;
      $codes = $this->codes($this->validator->validate([
        "container:$uuid::p.active" => ['default' => ['font-size' => '3rem']],
        "container:$uuid::> d-text.featured" => ['default' => ['font-size' => '1.75rem']],
        "container:$uuid::p:not(:hover)" => ['default' => ['font-size' => '3rem']],
        "container:$uuid::&.open > d-text:visited" => ['default' => ['font-size' => '1.75rem']],
      ]));
      $this->assertSame($bound, $codes["container:$uuid::p.active.default.font-size"] ?? NULL, "$mode: the qualified compound keeps its landing's grant");
      $this->assertSame($bound, $codes["container:$uuid::> d-text.featured.default.font-size"] ?? NULL);
      $this->assertSame($bound, $codes["container:$uuid::p:not(:hover).default.font-size"] ?? NULL);
      $this->assertSame($bound, $codes["container:$uuid::&.open > d-text:visited.default.font-size"] ?? NULL);
      $errors = $this->validator->validate($typos);
      $codes = $this->codes($errors);
      $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["container:$uuid::a:hovre"], "$mode refuses a pseudo-class the browser does not know.");
      $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["container:$uuid::p:not(:hovered) a"]);
      $this->assertSame('pseudo-class', $errors[0]->getContext()['kind']);
      $this->assertSame(['hover'], array_slice($errors[0]->getContext()['similar'], 0, 1));
      $this->assertStringContainsString('Did you mean :hover', $errors[0]->getHint());
      $this->assertContains('hover', $errors[1]->getContext()['similar']);
    }
    $this->enforce('strict');
    $this->assertSame([], $this->validator->validate($published + $declared_part), 'strict admits a published state on any compound and a class that is a declared part.');
    $errors = $this->validator->validate($markup + $states + $typos);
    $codes = $this->codes($errors);
    foreach (array_keys($markup) as $key) {
      $this->assertSame(['STYLE_CLASS_NOT_DECLARED'], $codes[$key], "strict refuses the markup class in $key");
    }
    foreach (array_keys($states) as $key) {
      $this->assertSame(['STYLE_STATE_NOT_DECLARED'], $codes[$key], "strict refuses the unpublished state in $key");
    }
    foreach (array_keys($typos) as $key) {
      $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes[$key], "strict refuses the typo in $key");
    }
    $this->assertStringContainsString('".active" class on "container:' . $uuid . '"', $errors[0]->getMessage());
    $this->assertStringContainsString('must be a declared part', $errors[0]->getHint());
    $this->assertContains('.link', $errors[2]->getContext()['declared_elements'], 'the text type\'s parts are what d-text.featured is corrected against');
    $state = $errors[count($markup)];
    $this->assertSame(['hover', 'focus', 'focus-within', 'focus-visible', 'active'], $state->getContext()['published_states']);
    $this->assertStringContainsString(':visited', $state->getMessage());
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $this->codes($this->validator->validate($negated_part))["container:$uuid::p:not(.active)"]);
  }

  /**
   * Attribute qualifiers are admitted by mode on every compound.
   *
   * A predicate on the markup, never a declaration: strict refuses it
   * (STYLE_ATTRIBUTE_NOT_DECLARED naming the attribute), open and guarded
   * admit it on the compound's own landing, whichever operator, value or
   * flag it carries, and wherever it stands (the anchor, a chain, a
   * `:not()`, a `:has()` argument).
   */
  public function testAttributeQualifierAdmission(): void {
    $this->layerBaseAndLinks();
    $uuid = self::UUID;
    $map = [
      "container:$uuid::p[aria-expanded=\"true\"] > a" => ['default' => ['color' => 'red']],
      "container:$uuid::a[href^=\"http\"]::after" => ['default' => ['content' => '""']],
      "container:$uuid::&[data-open] p" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::p:not([hidden])" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-container:has(a[target=\"_blank\" i])" => ['default' => ['padding' => '1rem']],
    ];
    foreach (['guarded', 'open'] as $mode) {
      $this->enforce($mode);
      $this->assertSame([], $this->validator->validate($map), "$mode admits attribute predicates.");
      // The landing's constraint binds through the attribute where
      // constraints are law (guarded; open demotes them to offers).
      $codes = $this->codes($this->validator->validate([
        "container:$uuid::p[aria-current=\"page\"]" => ['default' => ['font-size' => '3rem']],
      ]));
      $this->assertSame($mode === 'guarded' ? ['STYLE_VALUE_ABOVE_MAX'] : NULL, $codes["container:$uuid::p[aria-current=\"page\"].default.font-size"] ?? NULL, "$mode: the landing's constraint binds through the attribute");
    }
    $this->enforce('strict');
    $errors = $this->validator->validate($map);
    $codes = $this->codes($errors);
    foreach (array_keys($map) as $key) {
      $this->assertSame(['STYLE_ATTRIBUTE_NOT_DECLARED'], $codes[$key], "strict refuses $key");
    }
    $this->assertSame('aria-expanded', $errors[0]->getContext()['attribute']);
    $this->assertStringContainsString('[aria-expanded]', $errors[0]->getMessage());
  }

  /**
   * Strict mode: tag tiers stay closed-world under base's groups.
   */
  public function testTagTierStrictModeClosedWorld(): void {
    $this->layerInstanceNamespace();
    $this->layerBase();
    $this->enforce('strict');
    $uuid = self::UUID;

    $this->assertSame([], $this->validator->validate([
      "block:$uuid::p" => ['default' => ['font-size' => '1rem', 'padding' => '1rem']],
    ]));
    // Shadow is not among base.p's groups: refused under strict even
    // though the instance namespace could carry it for minted parts.
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::p" => ['default' => ['box-shadow' => 'none']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["block:$uuid::p.default.box-shadow"]);
  }

  /**
   * Strict mode: the namespace's children section opens the "::> *" write.
   *
   * A "{key}::.s-x > *" key (one minted element's direct children — the
   * list-items gesture) follows the children posture through the instance
   * namespace exactly as through an owner declaration: strict rejects it
   * while the namespace declares no children section, and accepts it,
   * typed by the children groups, once one is declared.
   */
  public function testInstanceChildrenUnderStrict(): void {
    $this->layerInstanceNamespace();
    $this->enforce('strict');
    $uuid = self::UUID;

    // No children section on the namespace: strict rejects the write.
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::.s-a3 > *" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertSame(['STYLE_CHILDREN_NOT_DECLARED'], $codes["block:$uuid::.s-a3 > *"]);

    // With one declared, the write is accepted and stays closed-world
    // under the children groups, on both owner shapes.
    $this->layer('instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph', 'h2' => 'Heading 2'],
        'property_groups' => [
          'typography' => [],
        ],
        'children' => [
          'property_groups' => [
            'typography' => [
              'constraints' => [
                'font-size' => ['max' => '2rem'],
              ],
            ],
          ],
        ],
      ],
    ]);
    $this->assertSame([], $this->validator->validate([
      "block:$uuid::.s-a3 > *" => ['default' => ['font-size' => '1rem']],
      "field:$uuid:field_body::.s-a3 > *" => ['default' => ['font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::.s-a3 > *" => ['default' => ['padding' => '1rem']],
      "field:$uuid:field_body::.s-a3 > *" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["block:$uuid::.s-a3 > *.default.padding"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.s-a3 > *.default.font-size"]);
  }

  /**
   * Variant styles accept tag and type selectors; instance parts stay out.
   *
   * A structural relation transfers with the look: a "::p" relative key
   * validates against the base tag, "::> d-text" against the type; an
   * instance part is bound to one content's minted identities and is
   * refused wherever it stands in a selector.
   */
  public function testTagAndTypeSelectorsInVariantStyles(): void {
    $this->layerInstanceNamespace();
    $this->layerBase();
    $this->assertSame([], $this->validator->validateAt([
      '::p' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:block')));
    $codes = $this->codes($this->validator->validateAt([
      '::.s-a3' => ['default' => ['font-size' => '1rem']],
      '::> d-text .s-a3' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_INSTANCE_PART_IN_VARIANT'], $codes['::.s-a3']);
    $this->assertSame(['STYLE_INSTANCE_PART_IN_VARIANT'], $codes['::> d-text .s-a3']);
  }

  /**
   * Sibling and qualified compounds resolve by the roster walk.
   *
   * A sibling compound resolves where its predecessor did (`> p + *` on
   * the owner's children, `> d-text .link ~ .link` on the same part); a
   * qualifier is vocabulary to judge and never moves the landing (`> d-
   * text:has(a)` is typed by the text type, not by base.a; a `:has()`
   * argument's parts resolve against the qualified type; a `:not()`
   * compound is judged where the qualified compound stands); the first
   * undeclared compound anywhere ends the walk with the existing
   * correctives naming it, the tag one in every enforcement mode.
   */
  public function testRelationsResolveByTheWalk(): void {
    $this->layerBase();
    $this->layer('kinds', [
      'container' => [
        'property_groups' => ['spacing' => []],
        'elements' => ['.section' => ['label' => 'Section', 'property_groups' => ['spacing' => []]]],
        'children' => [
          'property_groups' => [
            'spacing' => [
              'properties' => ['margin-top', 'margin-bottom'],
              'constraints' => ['margin-top' => ['max' => '2rem']],
            ],
          ],
        ],
      ],
    ]);
    $this->layer('block_types', [
      'text' => [
        'property_groups' => ['typography' => ['constraints' => ['font-size' => ['max' => '1.5rem']]]],
        'elements' => ['.link' => ['label' => 'Link', 'property_groups' => ['typography' => []]]],
      ],
      'image' => ['property_groups' => ['spacing' => []]],
    ]);
    $this->enforce('strict');
    $uuid = self::UUID;

    $this->assertSame([], $this->validator->validate([
      "container:$uuid::> p + *" => ['default' => ['margin-top' => '1rem']],
      "container:$uuid::> p ~ p" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-container > p + p" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text .link ~ .link" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::.section + .section" => ['default' => ['padding' => '0']],
      "container:$uuid::> d-text:has(a)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(.link)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(+ d-image)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-container:has(> d-image)" => ['default' => ['padding' => '1rem']],
      "container:$uuid::> d-container:has(> *)" => ['default' => ['padding' => '1rem']],
      "container:$uuid::> *:not(d-image)" => ['default' => ['margin-bottom' => '1rem']],
      "container:$uuid::> d-text:not(:has(a))" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(a):not(.section)" => ['default' => ['font-size' => '1rem']],
    ]));

    // The landing types the write: the sibling `*` by the children
    // section's constraint (margin-top at most 2rem), the qualified type
    // by its own (font-size at most 1.5rem; base.p allows 2rem and base.a
    // is unconstrained, so neither the predecessor nor the argument
    // answered), a sibling tag by base.p.
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::> p + *" => ['default' => ['margin-top' => '3rem']],
      "container:$uuid::> *:not(d-image)" => ['default' => ['margin-top' => '3rem']],
      "container:$uuid::> d-text:has(a)" => ['default' => ['font-size' => '1.75rem']],
      "container:$uuid::> d-text:not(:has(a))" => ['default' => ['font-size' => '1.75rem']],
      "container:$uuid::> p + p" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> p + *.default.margin-top"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> *:not(d-image).default.margin-top"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> d-text:has(a).default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> d-text:not(:has(a)).default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> p + p.default.font-size"]);

    // An undeclared compound inside an argument ends the walk with the
    // existing correctives: a part against the qualified type's parts, a
    // type, the children section, and a tag in every mode.
    $errors = $this->validator->validate([
      "container:$uuid::> d-text:has(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> *:not(d-video)" => ['default' => ['margin-top' => '1rem']],
      "container:$uuid::> d-text:has(> *)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:not(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(a) + d-text:has(em)" => ['default' => ['font-size' => '1rem']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::> d-text:has(.nope)"]);
    $this->assertContains('.link', $errors[0]->getContext()['declared_elements']);
    $this->assertNotContains('.section', $errors[0]->getContext()['declared_elements']);
    $this->assertSame(['STYLE_TYPE_NOT_DECLARED'], $codes["container:$uuid::> *:not(d-video)"]);
    $this->assertSame(['STYLE_CHILDREN_NOT_DECLARED'], $codes["container:$uuid::> d-text:has(> *)"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::> d-text:not(.nope)"]);
    $this->assertContains('.section', $errors[3]->getContext()['declared_elements']);
    $this->assertNotContains('.link', $errors[3]->getContext()['declared_elements']);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["container:$uuid::> d-text:has(a) + d-text:has(em)"]);
    // An open site admits the undeclared part and the undeclared tag
    // alike (grammar-only); a tag the browser does not know is still a
    // typo there.
    $this->enforce('open');
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::> d-text:has(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(em)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> d-text:has(emm)" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertArrayNotHasKey("container:$uuid::> d-text:has(.nope)", $codes);
    $this->assertArrayNotHasKey("container:$uuid::> d-text:has(em)", $codes);
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["container:$uuid::> d-text:has(emm)"]);
  }

  /**
   * A generated box validates at its host's derived or declared grant.
   *
   * Existence is universal, so a pseudo-element key is grammar on every
   * site; its vocabulary is declared-or-derived. Guarded and open sites
   * accept the derivation (the host's groups plus content) and the
   * declared section alike, typed by the landing; strict refuses an
   * undeclared box (STYLE_PSEUDO_NOT_DECLARED) and closes a declared one
   * to its section; an undeclared host is corrected before the box; the
   * content grammar is closed to `none | ""` everywhere; content on a
   * real box is refused in every mode (STYLE_PROPERTY_PSEUDO_ONLY); a box
   * captures no element scope, so no variant wears at it; the variant
   * and defaults lanes take the same keys.
   */
  public function testPseudoElements(): void {
    $this->layerBase();
    $this->layer('kinds', [
      'container' => [
        'property_groups' => ['spacing' => ['constraints' => ['padding-top' => ['max' => '2rem']]]],
        'elements' => ['.section' => ['label' => 'Section', 'property_groups' => ['spacing' => []]]],
        'children' => ['property_groups' => ['spacing' => []]],
        'pseudo' => [
          'before' => ['property_groups' => ['background' => []]],
          // A declared section for a pseudo-element with a vocabulary of
          // its own: the section grants, the browser's allowlist still
          // bounds (the strict block below).
          'selection' => ['property_groups' => ['typography' => []]],
        ],
      ],
    ]);
    $this->layer('block_types', [
      'text' => [
        'property_groups' => ['typography' => ['constraints' => ['font-size' => ['max' => '1.5rem']]]],
      ],
    ]);
    $uuid = self::UUID;

    // Guarded: the declared box, the derived boxes and their landings'
    // constraints; content on every box.
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::before" => ['default' => ['content' => '""', 'background-color' => 'gold']],
      "container:$uuid::&::after" => ['default' => ['content' => 'none', 'padding-top' => '1rem']],
      "container:$uuid::.section::after" => ['default' => ['content' => '""', 'padding' => '1rem']],
      "container:$uuid::> *::before" => ['default' => ['content' => '""', 'margin-top' => '1rem']],
      "container:$uuid::> d-text::before" => [
        'default' => ['content' => '""', 'font-size' => '1rem'],
        'hover' => ['font-size' => '1.25rem'],
      ],
      "container:$uuid::p::after" => ['default' => ['content' => '""', 'font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['padding-top' => '3rem']],
      "container:$uuid::> d-text::before" => ['default' => ['font-size' => '3rem']],
      "container:$uuid::p::after" => ['default' => ['font-size' => '3rem']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
      "container:$uuid::em::before" => ['default' => ['content' => '""']],
      "container:$uuid::emm::before" => ['default' => ['content' => '""']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::&::after.default.padding-top"], 'The host\'s constraint types the derived box.');
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> d-text::before.default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::p::after.default.font-size"]);
    $this->assertArrayNotHasKey("container:$uuid::.nope::before", $codes, 'An undeclared part is grammar on a guarded site, its box with it.');
    $this->assertArrayNotHasKey("container:$uuid::em::before", $codes, 'An undeclared tag the browser knows is grammar on a guarded site (admission by mode), its box with it.');
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["container:$uuid::emm::before"], 'A tag the browser does not know is a typo in every mode, box or not.');

    // The content grammar: "", none, or exactly one media reference as
    // the whole value, nothing else, wherever the box is. The token rides
    // the same media machinery as background-image (the base class's
    // gates judge it), so a missing media is the media corrective, not
    // content's.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'image', 'file', 'media']);
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://box.png');
    $file = File::create(['uri' => 'public://box.png', 'filename' => 'box.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $image_type->id(),
      'name' => 'Box',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    $token = MediaToken::build($media->uuid());
    $this->assertSame([], $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => $token]]]));
    $this->assertSame([], $this->validator->validate(["container:$uuid::> d-text::before" => ['default' => ['content' => " url(media:{$media->uuid()}) "]]]));
    $this->assertSame([], $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => ' NONE ']]]));
    foreach ([
      '"hi"',
      'attr(title)',
      'counter(item)',
      'open-quote',
      'url(x.png)',
      'url(x.svg)',
      'linear-gradient(#000, #fff)',
      "$token, $token",
      "\"x\" $token",
      "none $token",
      "$token / \"alt\"",
      'var(--x)',
      'inherit',
      'normal',
      "''",
    ] as $value) {
      $errors = $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => $value]]]);
      $this->assertCount(1, $errors, $value);
      $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode(), $value);
      $this->assertSame("container:$uuid::&::before.default.content", $errors[0]->getPath(), $value);
      $this->assertSame(['""', 'none'], $errors[0]->getContext()['keywords'], $value);
      $this->assertStringContainsString('one media reference', $errors[0]->getHint(), $value);
      $this->assertStringContainsString('Text belongs in a field', $errors[0]->getHint(), $value);
    }
    $errors = $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => "url('media:11111111-1111-1111-1111-111111111111')"]]]);
    $this->assertCount(1, $errors);
    $this->assertSame('MEDIA_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame("container:$uuid::&::before.default.content", $errors[0]->getPath());

    // The box slot takes any pseudo-element the browser knows: a table
    // pseudo-element (::marker, ::placeholder, ::selection, ::first-letter,
    // ::first-line) is styled with the properties its specification
    // allows, in every mode (the browser ignores the rest), the refusal
    // naming the allowlist; its host's grammar and admission hold as for
    // a box; content is the generated boxes' alone; one the browser does
    // not know is a typo everywhere, the nearest names suggested.
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::marker" => [
        'default' => ['color' => 'gold', 'font-size' => '1rem'],
        'hover' => ['color' => 'red'],
      ],
      "container:$uuid::> d-text::first-letter" => ['default' => ['font-size' => '1rem', 'float' => 'left']],
      "container:$uuid::p::selection" => ['default' => ['background-color' => 'gold']],
      "container:$uuid::&::backdrop" => ['default' => ['padding-top' => '1rem']],
    ]));
    $errors = $this->validator->validate([
      "container:$uuid::&::marker" => ['default' => ['padding-top' => '1rem']],
      "container:$uuid::p::selection" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::&::marker " => ['default' => ['color' => 'gold']],
      "container:$uuid::> d-text::marker" => ['default' => ['content' => '""']],
      "container:$uuid::&::markr" => ['default' => ['color' => 'gold']],
      "container:$uuid::&::part(x)" => ['default' => ['color' => 'gold']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_PROPERTY_NOT_ON_PSEUDO'], $codes["container:$uuid::&::marker.default.padding-top"]);
    $this->assertSame('"padding-top" does not apply to ::marker.', $errors[0]->getMessage());
    $this->assertSame('marker', $errors[0]->getContext()['pseudo']);
    $this->assertContains('color', $errors[0]->getContext()['allowed']);
    $this->assertNotContains('padding-top', $errors[0]->getContext()['allowed']);
    $this->assertStringStartsWith('https://drafts.csswg.org/', $errors[0]->getContext()['spec']);
    $this->assertStringContainsString('The browser styles ::marker with these properties only', $errors[0]->getHint());
    $this->assertStringContainsString('color, ', $errors[0]->getHint());
    $this->assertSame(['STYLE_PROPERTY_NOT_ON_PSEUDO'], $codes["container:$uuid::p::selection.default.font-size"]);
    $this->assertSame(['INVALID_STYLE_KEY'], $codes["container:$uuid::&::marker "]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["container:$uuid::> d-text::marker.default.content"]);
    $this->assertSame('"content" applies to a generated box only, and ::marker is no generated box.', $errors[3]->getMessage());
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $codes["container:$uuid::&::markr"]);
    $this->assertSame('"::markr" is not a pseudo-element the browser knows.', $errors[4]->getMessage());
    $this->assertSame(['word' => 'markr', 'kind' => 'pseudo-element', 'similar' => ['marker']], array_intersect_key($errors[4]->getContext(), [
      'word' => 1,
      'kind' => 1,
    ]) + ['similar' => array_slice($errors[4]->getContext()['similar'], 0, 1)]);
    $this->assertStringStartsWith('Did you mean ::marker?', $errors[4]->getHint());
    $this->assertSame(['INVALID_STYLE_KEY'], $codes["container:$uuid::&::part(x)"]);

    // `content` on a real box is refused in every mode, before any grant.
    foreach (['', '::.section', '::> *', '::> d-text', '::p'] as $suffix) {
      $errors = $this->validator->validate([
        "container:$uuid$suffix" => ['default' => ['content' => '""', 'padding-top' => '1rem']],
      ]);
      $this->assertCount(1, $errors, $suffix);
      $this->assertSame('STYLE_PROPERTY_PSEUDO_ONLY', $errors[0]->getCode(), $suffix);
      $this->assertSame("container:$uuid$suffix.default.content", $errors[0]->getPath(), $suffix);
      $this->assertSame(['before', 'after'], $errors[0]->getContext()['pseudo_elements'], $suffix);
      $this->assertStringContainsString('"{key}::&::before"', $errors[0]->getHint(), $suffix);
    }

    // No element scope: nothing wears at a box, on either lane.
    $this->assertNull($this->validator->captureScope("container:$uuid::&::before"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> d-text::before"));
    $this->assertNull($this->validator->captureScope("container:$uuid::p::after"));
    DaedalusStylishVariant::create(['label' => 'text_soft', 'machine_name' => 'text_soft', 'scope' => 'block_type:text'])->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $errors = $this->validator->validate([
      "container:$uuid::> d-text::before" => ['variants' => [['variant' => 'text_soft', 'fragment' => '']]],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertNull($errors[0]->getContext()['element_scope']);
    $this->assertStringContainsString('lands on no element scope', $errors[0]->getMessage());
    $this->assertStringContainsString('a generated box ("::&::before"', $errors[0]->getHint());
    $errors = $this->validator->validateAt([
      '::> d-text::before' => ['variants' => [['variant' => 'text_soft', 'fragment' => '']]],
    ], Address::fromString('kind:container'));
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertNull($errors[0]->getContext()['element_scope']);

    // The variant lane and the defaults lane take the same keys, typed
    // the same way.
    $this->assertSame([], $this->validator->validateAt([
      '::&::before' => ['default' => ['content' => '""', 'background-color' => 'gold']],
      '::> d-text::after' => ['default' => ['content' => '""', 'font-size' => '1rem']],
      'container(1)::&::after' => ['default' => ['content' => 'none']],
    ], Address::fromString('kind:container')));
    $codes = $this->codes($this->validator->validateAt([
      '::> d-text::after' => ['default' => ['content' => '"x"', 'font-size' => '3rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes['::> d-text::after.default.content']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::> d-text::after.default.font-size']);
    $this->assertSame([], $this->validator->validateDefaults([
      'kind:container' => ['::&::before' => ['default' => ['content' => '""', 'background-color' => 'gold']]],
      'base:p' => ['::&::after' => ['default' => ['content' => '""', 'font-size' => '1rem']]],
    ]));
    $codes = $this->codes($this->validator->validateDefaults([
      'base:p' => ['::&::after' => ['default' => ['content' => 'attr(x)']]],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes['base:p::&::after.default.content']);

    // Strict: the declared box is closed to its section (content always
    // in), the derived boxes are refused naming the section to write, an
    // undeclared host is corrected first, and content on a real box
    // keeps its own refusal.
    $this->enforce('strict');
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::before" => ['default' => ['content' => '""', 'background-color' => 'gold']],
    ]));
    $errors = $this->validator->validate([
      "container:$uuid::&::before" => ['default' => ['padding-top' => '1rem']],
      "container:$uuid::&::after" => ['default' => ['content' => '""']],
      "container:$uuid::.section::after" => ['default' => ['content' => '""']],
      "container:$uuid::> d-text::before" => ['default' => ['content' => '""']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
      "container:$uuid" => ['default' => ['content' => '""']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["container:$uuid::&::before.default.padding-top"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::&::after"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::.section::after"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::> d-text::before"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::.nope::before"]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["container:$uuid.default.content"]);
    $this->assertSame('The style guide does not declare the ::after pseudo-element of "container:' . $uuid . '".', $errors[1]->getMessage());
    $this->assertSame('after', $errors[1]->getContext()['pseudo']);
    $this->assertStringContainsString('{pseudo: {after: {property_groups: {…}}}}', $errors[1]->getHint());
    $this->assertSame('The style guide does not declare the ::after pseudo-element of ".section".', $errors[2]->getMessage());
    $this->assertSame('The style guide does not declare the ::before pseudo-element of "> d-text".', $errors[3]->getMessage());
    $this->assertContains('.section', $errors[4]->getContext()['declared_elements']);
    // The corrective of a declared box teaches its own grant.
    $this->assertSame(['background', 'generated_content'], $errors[0]->getContext()['declared_groups']);
    $this->assertContains('content', $errors[0]->getContext()['declared_properties']);
    $this->assertNotContains('padding-top', $errors[0]->getContext()['declared_properties']);

    // Strict: an undeclared table pseudo-element is refused like a
    // derived box (the corrective names the section); a declared one is
    // closed to its section, and the browser's allowlist refuses before
    // the grant is even asked.
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::selection" => ['default' => ['color' => 'gold']],
    ]));
    $codes = $this->codes($errors = $this->validator->validate([
      "container:$uuid::&::marker" => ['default' => ['color' => 'gold']],
      "container:$uuid::&::selection" => ['default' => ['padding-top' => '1rem', 'font-size' => '1rem']],
    ]));
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::&::marker"]);
    $this->assertStringContainsString('The browser knows the ::marker pseudo-element, but this site enforces its style guide', $errors[0]->getHint());
    $this->assertStringContainsString('{pseudo: {marker: {property_groups: {…}}}}', $errors[0]->getHint());
    $this->assertSame(['STYLE_PROPERTY_NOT_ON_PSEUDO'], $codes["container:$uuid::&::selection.default.padding-top"], 'The browser\'s law, before the grant.');
    $this->assertSame(['STYLE_PROPERTY_NOT_ON_PSEUDO'], $codes["container:$uuid::&::selection.default.font-size"], 'Declared typography cannot widen what the browser styles on a highlight.');

    // Open: the derivation is granted, the undeclared part is grammar, the
    // content grammar and the real-box refusal hold; an undeclared tag's
    // marker is grammar-only too, its color admitted and its padding
    // refused by the browser's own law.
    $this->enforce('open');
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['content' => '""', 'padding-top' => '3rem']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
      "container:$uuid::li::marker" => ['default' => ['color' => 'gold']],
    ]));
    $codes = $this->codes($errors = $this->validator->validate([
      "container:$uuid::li::marker" => ['default' => ['padding' => '1rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_ON_PSEUDO'], $codes["container:$uuid::li::marker.default.padding"]);
    $this->assertStringContainsString('color', $errors[0]->getHint());
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['content' => '"x"']],
      "container:$uuid::.section" => ['default' => ['content' => '""']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["container:$uuid::&::after.default.content"]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["container:$uuid::.section.default.content"]);
  }

  /**
   * A nested container is granted exactly what the section is.
   *
   * Placement curates offers and birth, never legality: the validator
   * resolves one declaration per layout for any placement, so with the
   * host entity in context (both placements locatable) an allowlist
   * refuses the same properties on the section and on the container nested
   * inside it, both correctives teach the same roster, and the whole
   * group grants the shorthand and the inline sides to both. Without the
   * host in context the answer is the same again.
   */
  public function testGrantIsTheSameAtEveryDepth(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $this->layer('kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-bottom', 'padding-inline', 'row-gap', 'column-gap']],
        ],
      ],
    ]);
    $this->enforce('strict');

    // One root section nesting one container (sections all the way down).
    $root_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $nested_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $nested = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $nested_uuid]]);
    $root = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $root_uuid]]);
    $root->appendComponent(SectionBlock::createComponent($nested, 'content'));
    $entity = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$root]]);
    $entity->save();
    $context = ['host' => $this->host($entity)];

    // The allowlist refuses the shorthand and the inline sides at both
    // depths and grants the declared side to both.
    $entries = [
      "container:$root_uuid" => ['default' => ['padding' => '2rem', 'padding-left' => '1rem', 'padding-top' => '1rem']],
      "container:$nested_uuid" => [
        'default' => ['padding' => '2rem', 'padding-left' => '1rem', 'padding-top' => '1rem'],
      ],
    ];
    $codes = $this->codes($this->validator->validate($entries, $context));
    foreach ([$root_uuid, $nested_uuid] as $uuid) {
      $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["container:$uuid.default.padding"]);
      $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["container:$uuid.default.padding-left"]);
      $this->assertArrayNotHasKey("container:$uuid.default.padding-top", $codes);
    }

    // Both correctives teach the one roster, and the shorthand's miss
    // names the sides the family rule found missing (the grant carries
    // gap, derived from its two longhands, and never the padding
    // shorthand).
    $root_errors = $this->validator->validate(["container:$root_uuid" => ['default' => ['padding' => '2rem']]], $context);
    $nested_errors = $this->validator->validate(["container:$nested_uuid" => ['default' => ['padding' => '2rem']]], $context);
    $this->assertSame($root_errors[0]->getContext()['declared_properties'], $nested_errors[0]->getContext()['declared_properties']);
    $this->assertNotContains('padding', $root_errors[0]->getContext()['declared_properties']);
    $this->assertContains('gap', $root_errors[0]->getContext()['declared_properties']);
    $this->assertSame($root_errors[0]->getHint(), $nested_errors[0]->getHint());
    $this->assertStringStartsWith('"padding" sets padding-top, padding-right, padding-bottom and padding-left and is granted only where all of them are; padding-right and padding-left are not granted here. Did you mean padding-top', $root_errors[0]->getHint());

    // No host in context: the same answer (depth never entered).
    $this->assertSame($codes, $this->codes($this->validator->validate($entries)));

    // The whole group grants the shorthand and the inline sides to both.
    $this->layer('kinds', ['container' => ['property_groups' => ['spacing' => []]]]);
    $this->assertSame([], $this->codes($this->validator->validate($entries, $context)));
  }

  /**
   * Writing a property an axis owns warns, names the axis, and never blocks.
   *
   * The `owns` qualifier is doctrine on the declaration (the properties
   * the axis's values write); the element door answers a direct write of
   * one with STYLE_PROPERTY_AXIS_OWNED at the property's path, naming the
   * axis, its label and the attribute the dialect wears it as, beside an
   * empty error list. An unowned property in the same block is silent,
   * and the children pseudo-part (not the element's own box) is too.
   */
  public function testOwnedPropertyWarns(): void {
    $this->layer('kinds', [
      'container' => [
        'property_groups' => ['spacing' => []],
        'variant_axes' => [
          ['width' => ['depth' => 'root', 'label' => 'Width', 'owns' => ['padding-left', 'padding-right']]],
          'flow',
        ],
        'children' => ['property_groups' => ['spacing' => []]],
      ],
    ]);
    $uuid = self::UUID;
    $warnings = [];
    $errors = $this->validator->validate([
      "container:$uuid" => [
        'default' => ['padding-left' => '1rem', 'padding-top' => '1rem'],
        'narrow' => ['padding-right' => '0'],
      ],
      "container:$uuid::> *" => ['default' => ['padding-left' => '1rem']],
    ], [], $warnings);
    $this->assertSame([], $errors, 'An owned write is legal.');
    $this->assertSame(["container:$uuid.default.padding-left", "container:$uuid.narrow.padding-right"], array_map(static fn ($warning) => $warning->getPath(), $warnings));
    $this->assertSame(['STYLE_PROPERTY_AXIS_OWNED', 'STYLE_PROPERTY_AXIS_OWNED'], array_map(static fn ($warning) => $warning->getCode(), $warnings));
    $this->assertSame('"padding-left" is owned by the width axis ("Width") on this element (the axis\'s values write it); the write landed, but wear the axis instead: width="…" in the page language, or a variant reference on the "width" axis.', $warnings[0]->getMessage());

    // The same write under strict law: still legal, still one warning per
    // owned property; a caller without a warning lane may omit it.
    $this->enforce('strict');
    $this->assertSame([], $this->validator->validate(["container:$uuid" => ['default' => ['padding-left' => '1rem']]]));

    // An axis owning nothing warns on nothing.
    $this->layer('kinds', ['container' => ['variant_axes' => ['flow']]]);
    $warnings = [];
    $this->validator->validate(["container:$uuid" => ['default' => ['padding-left' => '1rem']]], [], $warnings);
    $this->assertSame([], $warnings);
  }

  /**
   * A root-only axis's owned property is the recipe on a nested container.
   *
   * The warning learns depth: width is root-only (neither offered nor
   * wearable nested), so `max-width` written on a container the locator
   * places inside another section's region is the recorded recipe (a
   * full-width section with a nested container carrying its own max-width),
   * not a miss, and warns nothing. The same write on the root section still
   * warns STYLE_PROPERTY_AXIS_OWNED naming width, and a property an
   * every-depth axis owns (`padding-top` under rhythm here) warns at both
   * depths. A key the locator cannot place (no host in context) keeps the
   * ungated posture and warns as root.
   */
  public function testOwnedPropertyWarningLearnsDepth(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $this->layer('kinds', [
      'container' => [
        'property_groups' => ['spacing' => [], 'sizing' => []],
        'variant_axes' => [
          ['width' => ['depth' => 'root', 'label' => 'Width', 'owns' => ['max-width', 'padding-left', 'padding-right']]],
          ['rhythm' => ['label' => 'Rhythm', 'owns' => ['padding-top', 'padding-bottom']]],
        ],
      ],
    ]);

    // One root section nesting one container (sections all the way down).
    $root_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $nested_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $nested = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $nested_uuid]]);
    $root = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $root_uuid]]);
    $root->appendComponent(SectionBlock::createComponent($nested, 'content'));
    $entity = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$root]]);
    $entity->save();
    $context = ['host' => $this->host($entity)];
    $block = ['default' => ['max-width' => '60rem', 'padding-top' => '2rem']];
    $paths = static fn (array $warnings): array => array_map(static fn ($warning) => $warning->getPath(), $warnings);

    // Nested: the root-only axis's property is the recipe, silent; the
    // every-depth axis still owns its property.
    $warnings = [];
    $this->assertSame([], $this->validator->validate(["container:$nested_uuid" => $block], $context, $warnings));
    $this->assertSame(["container:$nested_uuid.default.padding-top"], $paths($warnings));
    $this->assertSame('STYLE_PROPERTY_AXIS_OWNED', $warnings[0]->getCode());
    $this->assertStringContainsString('owned by the rhythm axis ("Rhythm")', $warnings[0]->getMessage());

    // Root: both writes warn, max-width naming width.
    $warnings = [];
    $this->assertSame([], $this->validator->validate(["container:$root_uuid" => $block], $context, $warnings));
    $this->assertSame(["container:$root_uuid.default.max-width", "container:$root_uuid.default.padding-top"], $paths($warnings));
    $this->assertSame(['STYLE_PROPERTY_AXIS_OWNED', 'STYLE_PROPERTY_AXIS_OWNED'], array_map(static fn ($warning) => $warning->getCode(), $warnings));
    $this->assertStringContainsString('"max-width" is owned by the width axis ("Width")', $warnings[0]->getMessage());
    $this->assertStringContainsString('width="…"', $warnings[0]->getMessage());

    // No host in context: depth never entered, the nested key warns as
    // root.
    $warnings = [];
    $this->validator->validate(["container:$nested_uuid" => $block], [], $warnings);
    $this->assertSame(["container:$nested_uuid.default.max-width", "container:$nested_uuid.default.padding-top"], $paths($warnings));
  }

  /**
   * Wearing a variant the style guide withholds is refused by name.
   *
   * A published variant whose values the current law refuses leaves the
   * roster (VariantConformance), so a host map referencing it does not
   * resolve; the refusal names the cause, STYLE_VARIANT_NONCONFORMING
   * rather than the plain not-found, and carries the verdict (each
   * refused value at its path in the variant's styles), the law it was
   * judged under and the offered alternatives that apply to the element.
   * The same reference passes under the law that accepts the values, with
   * no write in between, and an unknown name stays not-found.
   */
  public function testNonconforming(): void {
    $this->installEntitySchema('user');
    // The container kind grants spacing only: a typography literal on a
    // container variant is legal under guarded law and refused under
    // strict law (the closed world).
    $this->layer('kinds', ['container' => ['property_groups' => ['spacing' => []]]]);
    $this->enforce('strict');
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $raw = DaedalusStylishVariant::create(['label' => 'Raw', 'machine_name' => 'raw', 'scope' => 'kind:container']);
    $raw->setStyles(['' => ['default' => ['font-size' => '2rem']]])->save();
    $wearing = static fn (string $reference): array => [
      'container:' . self::UUID => ['variants' => [['variant' => $reference, 'fragment' => '']]],
    ];

    // The conforming variant wears; the withheld one is refused by name.
    $this->assertSame([], $this->validator->validate($wearing('tidy')));
    $errors = $this->validator->validate($wearing('raw'));
    $this->assertCount(1, $errors);
    $error = $errors[0];
    $this->assertSame('STYLE_VARIANT_NONCONFORMING', $error->getCode());
    $this->assertSame('container:' . self::UUID . '.variants.0', $error->getPath());
    $this->assertSame('The "raw" variant does not conform to the site\'s style guide: default.font-size: The style guide does not declare "font-size" as stylable on this element.', $error->getMessage());
    $context = $error->getContext();
    $this->assertSame('raw', $context['variant']);
    $this->assertSame('strict', $context['enforcement']);
    $this->assertCount(1, $context['refused']);
    $this->assertSame('default.font-size', $context['refused'][0]['path']);
    $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $context['refused'][0]['code']);
    $this->assertSame('raw', $context['reference']);
    $this->assertSame(['tidy'], array_column($context['candidates'], 'id'));
    $this->assertStringContainsString('tidy "Tidy"', $error->getHint());
    $this->assertStringContainsString('"raw" is withheld until it does', $error->getHint());
    $this->assertStringContainsString('update_material with kind variant', $error->getHint());

    // The uuid spelling names the same case, the context carrying the
    // machine name; an unknown reference stays the plain not-found.
    $errors = $this->validator->validate($wearing((string) $raw->uuid()));
    $this->assertSame('STYLE_VARIANT_NONCONFORMING', $errors[0]->getCode());
    $this->assertSame('raw', $errors[0]->getContext()['variant']);
    $this->assertSame((string) $raw->uuid(), $errors[0]->getContext()['reference']);
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $this->codes($this->validator->validate($wearing('no_such_variant')))['container:' . self::UUID . '.variants.0']);

    // Guarded law accepts the literal: offered again, the wearing passes.
    // The builder's memo is not law-keyed; the reset is the request
    // boundary a real site crosses before reading the roster again.
    $this->enforce('guarded');
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->assertSame([], $this->validator->validate($wearing('raw')));
  }

  /**
   * A nested reference to a withheld or inactive variant is named as such.
   *
   * A variant's own styles nest references (container(1).variants), and
   * the importer and the save door validate them through the judged
   * roster, so a variant nesting one the style guide withholds used to be
   * refused with a not-found that named a saved variant. The nested path
   * asks the map lane's two questions before not-found: the inactive
   * system's member answers STYLE_VARIANT_DESIGN_SYSTEM_INACTIVE, the
   * withheld one STYLE_VARIANT_NONCONFORMING carrying the verdict's
   * refused triples, each with the nested path's own remedy (inline the
   * properties) and the slot scope's alternatives; an unknown name stays
   * not-found, and a conforming nested reference saves.
   */
  public function testNonconformingNestedReference(): void {
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], [], TRUE);
    $this->layer('kinds', ['container' => ['property_groups' => ['spacing' => []]]]);
    $this->enforce('strict');
    DesignSystem::create(['id' => 'nocturne', 'label' => 'Nocturne'])->save();
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $raw = DaedalusStylishVariant::create(['label' => 'Raw', 'machine_name' => 'raw', 'scope' => 'kind:container']);
    $raw->setStyles(['' => ['default' => ['font-size' => '2rem']]])->save();
    $member = DaedalusStylishVariant::create([
      'label' => 'Nocturne Tidy',
      'machine_name' => 'nocturne_tidy',
      'scope' => 'kind:container',
      'design_system' => 'nocturne',
    ]);
    $member->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();

    // The save door (VariantManager::validateStyles() under saveAsVariant):
    // a global container variant whose nested container wears the
    // reference.
    $manager = $this->container->get('daedalus_stylish.variant_manager');
    $save = static function (string $reference) use ($manager): array {
      try {
        $manager->saveAsVariant('Card', 'container:' . self::UUID, [
          'container(1)' => ['variants' => [['variant' => $reference, 'fragment' => '']]],
        ], global: TRUE);
      }
      catch (VariantValidationException $e) {
        return $e->getErrors();
      }
      return [];
    };
    $at = 'styles.container(1).variants.0';

    // The withheld variant is refused by name, with the verdict.
    $errors = $save('raw');
    $this->assertCount(1, $errors);
    $error = $errors[0];
    $this->assertSame('STYLE_VARIANT_NONCONFORMING', $error->getCode());
    $this->assertSame($at, $error->getPath());
    $this->assertSame('The "raw" variant does not conform to the site\'s style guide: default.font-size: The style guide does not declare "font-size" as stylable on this element.', $error->getMessage());
    $context = $error->getContext();
    $this->assertSame('raw', $context['variant']);
    $this->assertSame('strict', $context['enforcement']);
    $this->assertSame([
      ['path' => 'default.font-size', 'code' => 'STYLE_PROPERTY_NOT_DECLARED'],
    ], array_map(static fn (array $refusal): array => ['path' => $refusal['path'], 'code' => $refusal['code']], $context['refused']));
    $this->assertSame('raw', $context['reference']);
    $this->assertSame(['tidy'], array_column($context['candidates'], 'id'));
    $this->assertStringContainsString('apply to this slot\'s scope ("kind:container"): tidy "Tidy"', $error->getHint());
    $this->assertStringContainsString('"raw" is withheld until it does', $error->getHint());
    $this->assertStringContainsString('or put the properties in this entry directly', $error->getHint());
    $this->assertStringNotContainsString('Detach the reference', $error->getHint());

    // The inactive system's member is named as inactive.
    $errors = $save('nocturne_tidy');
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_DESIGN_SYSTEM_INACTIVE', $errors[0]->getCode());
    $this->assertSame($at, $errors[0]->getPath());
    $this->assertSame('nocturne', $errors[0]->getContext()['design_system']);
    $this->assertSame(['tidy'], array_column($errors[0]->getContext()['candidates'], 'id'));
    $this->assertStringContainsString('apply to this slot\'s scope ("kind:container"): tidy "Tidy"', $errors[0]->getHint());
    $this->assertStringContainsString('or put the properties in this entry directly', $errors[0]->getHint());

    // An unknown name stays the plain not-found; the conforming one saves.
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $this->codes($save('no_such_variant'))[$at]);
    $this->assertSame([], $save('tidy'));
    $this->assertNotNull($this->container->get('daedalus_stylish.variant_repository')->resolve('card'));
  }

  /**
   * The map lane accepts a reference's provenance, and the read carries it.
   *
   * A reference a composition variant's apply distributed onto an element
   * carries "via", the distributing variant's machine name, so a later
   * switch or removal of that composition can reap exactly what it put
   * there and nothing the editor applied by hand. The grammar asks only
   * that it be a non-empty string: a "via" naming no saved variant is
   * inert, never an error (nothing reaps by it; the wear reads as the
   * element's own). entryRefs(), the one structured read, returns the
   * origin beside the two keys, NULL when the reference carries none.
   */
  public function testMapLaneAcceptsProvenance(): void {
    $this->installEntitySchema('user');
    $this->layer('kinds', ['container' => ['property_groups' => ['spacing' => []]]]);
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $card = DaedalusStylishVariant::create([
      'label' => 'Card Left',
      'machine_name' => 'card_left',
      'scope' => 'kind:container',
    ]);
    $card->setStyles(['container(1)' => ['variants' => [['variant' => 'tidy', 'fragment' => '']]]])->save();
    $key = 'container:' . self::UUID;
    $wearing = static fn (array $reference): array => [$key => ['variants' => [$reference]]];

    // A live origin and a stale one both pass: the shape is the whole
    // check.
    $this->assertSame([], $this->validator->validate($wearing([
      'variant' => 'tidy',
      'fragment' => '',
      'via' => 'card_left',
    ])));
    $this->assertSame([], $this->validator->validate($wearing([
      'variant' => 'tidy',
      'fragment' => '',
      'via' => 'no_such_variant',
    ])));
    // The shape gate: absent or a non-empty string, nothing else.
    foreach (['', 5, NULL, ['card_left']] as $via) {
      $errors = $this->validator->validate($wearing(['variant' => 'tidy', 'fragment' => '', 'via' => $via]));
      $this->assertSame(['INVALID_STYLE_ENTRY'], $this->codes($errors)["$key.variants.0"]);
      $this->assertStringContainsString('never by hand', $errors[0]->getHint());
    }
    // The read carries the origin through, NULL where there is none.
    $this->assertSame([
      ['variant' => 'tidy', 'fragment' => '', 'via' => 'card_left'],
      ['variant' => 'card_left', 'fragment' => '', 'via' => NULL],
    ], StyleMapValidator::entryRefs([
      'variants' => [
        ['variant' => 'tidy', 'fragment' => '', 'via' => 'card_left'],
        ['variant' => 'card_left', 'fragment' => ''],
      ],
    ]));
  }

  /**
   * The variant styles lane rejects provenance on a nested reference.
   *
   * Provenance is a placement fact the map records when a composition is
   * applied; a nested reference inside a variant's own styles says what to
   * distribute, never who did, so the key is a shape error there and the
   * hint says why. The same nested reference without it validates.
   */
  public function testVariantLaneRejectsProvenance(): void {
    $this->installEntitySchema('user');
    $this->layer('kinds', ['container' => ['property_groups' => ['spacing' => []]]]);
    $tidy = DaedalusStylishVariant::create(['label' => 'Tidy', 'machine_name' => 'tidy', 'scope' => 'kind:container']);
    $tidy->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $nested = static fn (array $reference): array => ['container(1)' => ['variants' => [$reference]]];

    $errors = $this->validator->validateAt($nested(['variant' => 'tidy', 'fragment' => '', 'via' => 'card_left']), Address::fromString('kind:container'));
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_STYLE_ENTRY', $errors[0]->getCode());
    $this->assertSame('container(1).variants.0', $errors[0]->getPath());
    $this->assertStringContainsString('A nested reference never carries "via"', $errors[0]->getHint());
    $this->assertSame([], $this->validator->validateAt($nested(['variant' => 'tidy', 'fragment' => '']), Address::fromString('kind:container')));
  }

  /**
   * Wear at a rule pairs by the landing, and the two rule refusals stand.
   *
   * A rule key captures the element its last compound names (a type's
   * root address, a tag's base tag), so a text variant wears at
   * "::> d-text" and a container variant is refused there with the
   * text-scoped candidates; a part, the children and a subjectless
   * compound land on no element scope and wear nothing; a composition is
   * refused at any rule by its keys. keyAddress() keeps answering the
   * full address (the stack's and the media resolver's read).
   */
  public function testWearAtRule(): void {
    $this->layer('site', [
      'base' => [
        'p' => ['property_groups' => ['typography' => []]],
        'a' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->layer('kinds', [
      'container' => [
        'property_groups' => ['spacing' => []],
        'children' => ['property_groups' => ['spacing' => []]],
      ],
    ]);
    $this->layer('block_types', [
      'text' => [
        'property_groups' => ['typography' => []],
        'elements' => ['.link' => ['label' => 'Link', 'property_groups' => ['typography' => []]]],
      ],
    ]);
    foreach ([
      ['text_soft', 'block_type:text'],
      ['section', 'kind:container'],
      ['link_style', 'base:a'],
    ] as [$name, $scope]) {
      $variant = DaedalusStylishVariant::create(['label' => $name, 'machine_name' => $name, 'scope' => $scope]);
      $variant->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    }
    $card = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:container']);
    $card->setStyles([
      '' => ['default' => ['padding' => '1rem']],
      'container(1)' => ['default' => ['padding' => '0']],
      'block(text,1)::.link' => ['default' => ['color' => 'red']],
    ])->save();
    $uuid = self::UUID;
    $wearing = static fn (string $key, string $reference): array => [
      $key => ['variants' => [['variant' => $reference, 'fragment' => '']]],
    ];

    // The capture: an element its own root, a rule its landing, and
    // nothing where the targets are no element scope.
    $this->assertSame('kind:container', (string) $this->validator->captureScope("container:$uuid"));
    $this->assertSame('block_type:text', (string) $this->validator->captureScope("container:$uuid::> d-text"));
    $this->assertSame('kind:container', (string) $this->validator->captureScope("container:$uuid::> d-text > d-container"));
    $this->assertSame('base:a', (string) $this->validator->captureScope("container:$uuid::a"));
    $this->assertSame('base:p', (string) $this->validator->captureScope("container:$uuid::> p + p"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> d-text .link"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> *"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> *:not(d-image)"));
    $this->assertNull($this->validator->captureScope('bogus'));
    $this->assertSame('kind:container::> d-text', (string) $this->validator->keyAddress("container:$uuid::> d-text"));
    $this->assertSame('kind:container', (string) $this->validator->keyAddress("container:$uuid"));

    // Pairing by the landing: the text variant wears at the text rule,
    // the link treatment at the anchor rule; the container variant is
    // refused at both with the candidates that DO pair at the rule.
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid::> d-text", 'text_soft')));
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid::a", 'link_style')));
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid", 'section')));
    $errors = $this->validator->validate($wearing("container:$uuid::> d-text", 'section'));
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame("container:$uuid::> d-text.variants.0", $errors[0]->getPath());
    $this->assertSame('block_type:text', $errors[0]->getContext()['element_scope']);
    // The candidates by the landing: the text variant and the tag
    // treatment (a base:{tag} scope pairs with every element address; the
    // tag itself is the offer-time gate), never the container's.
    $this->assertSame(['link_style', 'text_soft'], array_column($errors[0]->getContext()['candidates'], 'id'));
    $this->assertStringContainsString('a rule wears one for its landing', $errors[0]->getHint());
    $errors = $this->validator->validate($wearing("container:$uuid::a", 'section'));
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame('base:a', $errors[0]->getContext()['element_scope']);
    $this->assertSame(['link_style'], array_column($errors[0]->getContext()['candidates'], 'id'));
    // The element side is unchanged: a text variant never wears on the
    // container itself.
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->codes($this->validator->validate($wearing("container:$uuid", 'text_soft')))["container:$uuid.variants.0"]);

    // No element scope: a part, the children, a subjectless compound.
    foreach (['> d-text .link', '> *', '> *:not(d-image)'] as $selector) {
      $errors = $this->validator->validate($wearing("container:$uuid::$selector", 'text_soft'));
      $this->assertCount(1, $errors, $selector);
      $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode(), $selector);
      $this->assertNull($errors[0]->getContext()['element_scope'], $selector);
      $this->assertSame([], $errors[0]->getContext()['candidates'], $selector);
      $this->assertStringContainsString('lands on no element scope', $errors[0]->getMessage(), $selector);
      $this->assertStringContainsString('land on no element scope, so no variant pairs there', $errors[0]->getHint(), $selector);
    }

    // A composition is refused at any rule by its keys, before scope: at
    // its own family's rule and at a foreign one alike.
    foreach (['> d-container', '> d-text'] as $selector) {
      $errors = $this->validator->validate($wearing("container:$uuid::$selector", 'card'));
      $this->assertCount(1, $errors, $selector);
      $this->assertSame('STYLE_VARIANT_COMPOSITION_AT_RULE', $errors[0]->getCode(), $selector);
      $this->assertSame(['container(1)', 'block(text,1)'], $errors[0]->getContext()['slot_paths'], $selector);
      $this->assertStringContainsString('a rule wears flat variants only', $errors[0]->getMessage(), $selector);
    }
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid", 'card')));

    // The not-found corrective lists the candidates by the landing too.
    $errors = $this->validator->validate($wearing("container:$uuid::> d-text", 'ghost'));
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame(['link_style', 'text_soft'], array_column($errors[0]->getContext()['candidates'], 'id'));

    // The variant styles lane pairs a rule entry's nested references by
    // the same landing (a captured rule's wear travels as refs): the
    // text variant nests at a container variant's "::> d-text" entry
    // and at a slot's rule, the link treatment at "::a", the container
    // variant is refused at the text rule with the text candidates, and
    // a rule landing on no element scope wears nothing.
    $nested = static fn (string $relative, string $reference): array => [
      $relative => ['variants' => [['variant' => $reference, 'fragment' => '']]],
    ];
    $at = Address::fromString('kind:container');
    $this->assertSame([], $this->validator->validateAt($nested('::> d-text', 'text_soft'), $at));
    $this->assertSame([], $this->validator->validateAt($nested('container(1)::> d-text', 'text_soft'), $at));
    $this->assertSame([], $this->validator->validateAt($nested('::a', 'link_style'), $at));
    $errors = $this->validator->validateAt($nested('::> d-text', 'section'), $at);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame('::> d-text.variants.0', $errors[0]->getPath());
    $this->assertSame('block_type:text', $errors[0]->getContext()['element_scope']);
    $this->assertSame(['link_style', 'text_soft'], array_column($errors[0]->getContext()['candidates'], 'id'));
    $errors = $this->validator->validateAt($nested('::> *', 'text_soft'), $at);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertNull($errors[0]->getContext()['element_scope']);
    $this->assertStringContainsString('"::> *" lands on no element scope', $errors[0]->getMessage());
    $errors = $this->validator->validateAt($nested('::> *', 'ghost'), $at);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertStringContainsString('No variant applies at this rule ("::> *")', $errors[0]->getHint());
  }

  /**
   * The gradient shape: layers of gradient functions or var() references.
   *
   * A gradient-shaped scale's token validates through the one grammar a
   * gradient-typed property shares (PropertyGroupBase::validateGradient()):
   * the hygiene gate, CSS-wide keywords pass, every comma-separated layer a
   * gradient function (linear, radial, conic, their repeating- forms) or a
   * var() reference to another named value. A var() stop inside a layer
   * does not excuse the layer from being a gradient; url(), a color,
   * calc(), a trailing word, a malformed list and a script scheme refuse
   * at the token's value path, a condition's at its key beneath it.
   */
  public function testGradientShape(): void {
    $this->layer('site', ['token_scales' => ['gradient' => ['label' => 'Gradient', 'shape' => 'gradient']]]);
    $validate = fn (mixed $value): array => $this->validator->validateTokens(
      SkinTokensItem::canonicalizeTokens(['gradient' => ['veil' => $value]]),
      ['theme' => self::THEME],
    );

    foreach ([
      'radial-gradient(circle at 30% 20%, #6b2d5c, #1a1a2e)',
      'linear-gradient(135deg, #1a1a2e, #e94560)',
      'conic-gradient(from 90deg at 50% 50%, red, blue)',
      'repeating-linear-gradient(45deg, #000 0 10px, #fff 10px 20px)',
      'repeating-radial-gradient(circle, #000 0 10px, #fff 10px 20px)',
      'repeating-conic-gradient(from 0deg, #000 0 10deg, #fff 10deg 20deg)',
      'linear-gradient(#000, #fff), radial-gradient(circle, red, blue)',
      'linear-gradient(var(--color-accent), transparent)',
      'var(--gradient-plum-veil)',
      'var(--gradient-plum-veil), linear-gradient(#000, #fff)',
      'inherit',
      ['default' => 'linear-gradient(#000, #fff)', 'dark' => 'linear-gradient(#fff, #000)'],
    ] as $value) {
      $this->assertSame([], $validate($value), json_encode($value));
    }

    foreach ([
      'url(x.png)',
      'url(x.png), linear-gradient(#000, #fff)',
      '#1a1a2e',
      'red',
      'calc(1px + 2px)',
      'linear-gradient(#000, #fff',
      'linear-gradient(#000, #fff),',
      ', linear-gradient(#000, #fff)',
      'linear-gradient(#000, #fff), , var(--x)',
      'linear-gradient(#000, #fff) solid',
      'linear-gradient(#000, #fff) radial-gradient(red, blue)',
      'javascript:alert(1)',
    ] as $value) {
      $this->assertSame(['gradient.veil.value' => ['INVALID_STYLE_VALUE']], $this->codes($validate($value)), $value);
    }
    $this->assertSame(
      ['gradient.veil.value.dark' => ['INVALID_STYLE_VALUE']],
      $this->codes($validate(['default' => 'linear-gradient(#000, #fff)', 'dark' => 'red'])),
    );
    // Media stays with the style value composing the gradient, never
    // inside the named gradient.
    $this->assertSame(['gradient.veil.value' => ['INVALID_STYLE_VALUE']], $this->codes($validate("url('media:" . self::UUID . "')")));
  }

  /**
   * A value the printer would not print is refused where it is written.
   *
   * The printer drops a declaration whose value the emit gate refuses
   * (EmitGate::admits()), with no message, so every write door asks the
   * gate of a value the grammar accepts and answers INVALID_STYLE_VALUE
   * with a hint naming the part that does not print: a page map, a
   * variant's styles, a skin's defaults, a keyframe, a token value. A
   * value at rest is not asked: the landing lane (the style fields'
   * constraint at every Save) reports no violation, and the conformance
   * verdict keeps offering a stored variant that carries one. What the
   * gate admits stays accepted.
   */
  public function testValueThePrinterDropsIsRefusedWhereWritten(): void {
    $uuid = self::UUID;
    $container = Address::fromString('kind:container');
    $unprintable = [
      [
        'background-image',
        'url(https://example.com/a.png)',
        'url(https://example.com/a.png) names a file by its address',
      ],
      ['filter', 'url(#blur)', 'url(#blur) names a file by its address'],
      ['width', 'calc(100% - 2rem', 'is left open'],
      ['color', 'var(--c) /* note */', 'A comment (/* */) does not print'],
      ['background-image', 'image-set("a.png" 1x)', 'image-set() fetches the file its argument names'],
      ['font-family', 'Font\ Name, serif', 'A backslash escape outside a quoted string does not print'],
    ];
    foreach ($unprintable as [$property, $value, $names]) {
      $this->assertFalse(EmitGate::admits($value), "the emit gate refuses \"$value\"");
      $map = ["entity:$uuid" => ['default' => [$property => $value]]];
      $styles = ['' => ['default' => [$property => $value]]];

      // The page map's door.
      $errors = $this->validator->validate($map);
      $this->assertCount(1, $errors, "$property: $value is refused once");
      $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode());
      $this->assertSame("entity:$uuid.default.$property", $errors[0]->getPath());
      $this->assertSame(sprintf('"%s" is not a value the stylesheet can print for %s.', $value, $property), $errors[0]->getMessage());
      $this->assertStringContainsString($names, $errors[0]->getHint(), "the hint names what does not print in \"$value\"");
      // A variant's styles and a skin's defaults.
      $this->assertSame(["default.$property" => ['INVALID_STYLE_VALUE']], $this->codes($this->validator->validateAt($styles, $container)), "$property: $value in a variant's styles");
      $this->assertSame(["kind:container.default.$property" => ['INVALID_STYLE_VALUE']], $this->codes($this->validator->validateDefaults(['kind:container' => $styles])), "$property: $value in a skin's defaults");

      // At rest the same value is no violation, on either stored shape.
      $this->assertSame([], $this->validator->withLandingLane(fn (): array => $this->validator->validate($map)), "$property: $value at rest in a page map");
      $this->assertSame([], $this->validator->withLandingLane(fn (): array => $this->validator->validateAt($styles, $container)), "$property: $value at rest in a variant");
    }
    // The lane ends with its closure: the next write is asked again.
    $this->assertCount(1, $this->validator->validate(["entity:$uuid" => ['default' => ['filter' => 'url(#blur)']]]));

    // A keyframe's value and a token's value pass the same question.
    $this->assertSame(
      ['steps.0%.width' => ['INVALID_STYLE_VALUE']],
      $this->codes($this->validator->validateAnimationSteps([
        '0%' => ['width' => 'calc(100% - 2rem'],
        '100%' => ['width' => '100%'],
      ])),
    );
    $this->layer('site', ['token_scales' => ['color' => ['label' => 'Color', 'shape' => 'color']]]);
    $tokens = fn (mixed $value): array => $this->validator->validateTokens(
      SkinTokensItem::canonicalizeTokens(['color' => ['ink' => $value]]),
      ['theme' => self::THEME],
    );
    $errors = $tokens('var(--c) /* note */');
    $this->assertSame(['color.ink.value' => ['INVALID_STYLE_VALUE']], $this->codes($errors));
    $this->assertStringContainsString('A comment (/* */) does not print', $errors[0]->getHint());
    $this->assertSame(
      ['color.ink.value.dark' => ['INVALID_STYLE_VALUE']],
      $this->codes($tokens(['default' => '#111', 'dark' => 'var(--c'])),
    );
    $this->assertSame([], $tokens('var(--c, #111)'));

    // The verdict judges what is stored: a variant carrying such a value
    // stays offered, and the page wearing it stays writable.
    $stored = DaedalusStylishVariant::create([
      'label' => 'Blurred',
      'machine_name' => 'blurred',
      'scope' => 'kind:container',
    ]);
    $stored->setStyles(['' => ['default' => ['padding' => '1rem', 'filter' => 'url(#blur)']]])->save();
    $this->assertSame([], $this->validator->validate([
      "container:$uuid" => ['variants' => [['variant' => 'blurred', 'fragment' => '']]],
    ]));

    // What the gate admits stays accepted: a media reference, a gradient,
    // a function with its own parentheses, a reference with a fallback.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'image', 'file', 'media']);
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://hero.png');
    $file = File::create(['uri' => 'public://hero.png', 'filename' => 'hero.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $image_type->id(),
      'name' => 'Hero',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    $token = MediaToken::build($media->uuid());
    foreach ([
      ['background-image', $token],
      ['background-image', "linear-gradient(rgb(0 0 0 / 0.6), rgb(0 0 0 / 0.6)), $token"],
      ['background-image', 'linear-gradient(135deg, #1a1a2e, #e94560)'],
      // On a property whose grammar takes it: a dimension property
      // (width) refuses clamp() by its own rule, before any gate.
      ['grid-template-columns', 'repeat(auto-fit, minmax(clamp(10rem, 30vw, 20rem), 1fr))'],
      ['width', 'calc(100% - 2rem)'],
      ['padding', 'var(--x, 1rem)'],
      ['font-family', '"Font \"Name\"", serif'],
    ] as [$property, $value]) {
      $this->assertTrue(EmitGate::admits($value), "the emit gate admits \"$value\"");
      $this->assertSame([], $this->validator->validate(["entity:$uuid" => ['default' => [$property => $value]]]), "$property: $value is accepted");
      $this->assertSame([], $this->validator->validateAt(['' => ['default' => [$property => $value]]], $container), "$property: $value is accepted in a variant");
    }
  }

}
