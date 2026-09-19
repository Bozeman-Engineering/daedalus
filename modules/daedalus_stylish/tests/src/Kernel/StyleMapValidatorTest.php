<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

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
 * The fixture namespaces layer through daedalus_stylish_nested_test's state-driven
 * alter hook, the same lane its nested-grammar fixtures use.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleMapValidatorTest extends KernelTestBase {

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
    'block',
    'block_content',
    'entity_test',
    'daedalus_blueprint',
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
    // All four material storages: the roster reads them on every build,
    // and activating a design system (the enforcement door) recompiles
    // the delivery files over the same four.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installEntitySchema('user');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
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
   * Layers over daedalus_stylish's own base declaration (the kernel site discovers
   * daedalus_stylish.page_builder.yml): p gains a font-size ceiling, and em (a
   * legal BASE_TAGS tag the shipped section does not declare) stays
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

    // An undeclared tag rejects on the default (non-strict) posture too:
    // the key is only grammar for declared base tags. em is legal but
    // undeclared; div is outside the vocabulary; the instance lane's
    // identity tags never widen the tier.
    $this->layer('instances', ['.s-*' => ['tags' => ['em' => 'Emphasis']]]);
    $errors = $this->validator->validate([
      "block:$uuid::em" => ['default' => ['font-size' => '1rem']],
      "block:$uuid::div" => ['default' => ['font-size' => '1rem']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["block:$uuid::em"]);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["block:$uuid::div"]);
    $this->assertSame($this->container->get('daedalus_stylish.style_guide')->baseTags(self::THEME), $errors[0]->getContext()['declared_tags']);
    $this->assertContains('a', $errors[0]->getContext()['declared_tags']);
    $this->assertNotContains('em', $errors[0]->getContext()['declared_tags']);
    $this->assertStringContainsString('Declared base tags:', $errors[0]->getHint());
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
   * Strict mode: the namespace's children section opens the "::>" write.
   *
   * A "{key}::.s-x::>" key (one minted element's direct children — the
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
      "block:$uuid::.s-a3::>" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertSame(['STYLE_CHILDREN_NOT_DECLARED'], $codes["block:$uuid::.s-a3::>"]);

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
      "block:$uuid::.s-a3::>" => ['default' => ['font-size' => '1rem']],
      "field:$uuid:field_body::.s-a3::>" => ['default' => ['font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "block:$uuid::.s-a3::>" => ['default' => ['padding' => '1rem']],
      "field:$uuid:field_body::.s-a3::>" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["block:$uuid::.s-a3::>.default.padding"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.s-a3::>.default.font-size"]);
  }

  /**
   * Variant styles reject tag-tier keys (the host-maps-only bound).
   *
   * Tag tiers live in host maps only: the slot-key grammar knows no
   * dotless part, so a "::p" relative key fails as a malformed key, on
   * the same gate that keeps instance parts out of variants.
   */
  public function testTagTierExcludedFromVariantStyles(): void {
    $this->layerInstanceNamespace();
    $codes = $this->codes($this->validator->validateAt([
      '::p' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:block')));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['::p']);
  }

  /**
   * A nested container is granted exactly what the page band is.
   *
   * Placement curates offers and birth, never legality: the validator
   * resolves one declaration per layout for any placement, so with the
   * host entity in context (both placements locatable) an allowlist
   * refuses the same properties on the band and on the container nested
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

    // One root band nesting one container (sections all the way down).
    $root_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $nested_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $nested = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $nested_uuid]]);
    $root = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $root_uuid]]);
    $root->appendComponent(SectionBlock::createComponent($nested, 'content'));
    $entity = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$root]]);
    $entity->save();
    $context = ['entity' => $entity];

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
      "container:$uuid::>" => ['default' => ['padding-left' => '1rem']],
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
   * full-width band with a nested container carrying its own max-width),
   * not a miss, and warns nothing. The same write on the root band still
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

    // One root band nesting one container (sections all the way down).
    $root_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $nested_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $nested = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $nested_uuid]]);
    $root = new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => $root_uuid]]);
    $root->appendComponent(SectionBlock::createComponent($nested, 'content'));
    $entity = EntityTest::create(['name' => 'host', 'layout_builder__layout' => [$root]]);
    $entity->save();
    $context = ['entity' => $entity];
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
    $card = DaedalusStylishVariant::create(['label' => 'Card Left', 'machine_name' => 'card_left', 'scope' => 'kind:container']);
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

}
