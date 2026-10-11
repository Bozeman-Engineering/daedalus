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
use Drupal\daedalus_stylish\MediaToken;
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
 * The fixture namespaces layer through daedalus_stylish_nested_test's state-driven
 * alter hook, the same lane its nested-grammar fixtures use.
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
    // All four material storages: the roster reads them on every build,
    // and activating a design system (the enforcement door) recompiles
    // the delivery files over the same four.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
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
   * validates against the base tag, "::> pb-text" against the type; an
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
      '::> pb-text .s-a3' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_INSTANCE_PART_IN_VARIANT'], $codes['::.s-a3']);
    $this->assertSame(['STYLE_INSTANCE_PART_IN_VARIANT'], $codes['::> pb-text .s-a3']);
  }

  /**
   * Sibling and qualified compounds resolve by the roster walk.
   *
   * A sibling compound resolves where its predecessor did (`> p + *` on
   * the owner's children, `> pb-text .link ~ .link` on the same part); a
   * qualifier is vocabulary to judge and never moves the landing (`> pb-
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
        'elements' => ['.band' => ['label' => 'Band', 'property_groups' => ['spacing' => []]]],
        'children' => ['property_groups' => ['spacing' => ['properties' => ['margin-top', 'margin-bottom'], 'constraints' => ['margin-top' => ['max' => '2rem']]]]],
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
      "container:$uuid::> pb-container > p + p" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text .link ~ .link" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::.band + .band" => ['default' => ['padding' => '0']],
      "container:$uuid::> pb-text:has(a)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:has(.link)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:has(+ pb-image)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-container:has(> pb-image)" => ['default' => ['padding' => '1rem']],
      "container:$uuid::> pb-container:has(> *)" => ['default' => ['padding' => '1rem']],
      "container:$uuid::> *:not(pb-image)" => ['default' => ['margin-bottom' => '1rem']],
      "container:$uuid::> pb-text:not(:has(a))" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:has(a):not(.band)" => ['default' => ['font-size' => '1rem']],
    ]));

    // The landing types the write: the sibling `*` by the children
    // section's constraint (margin-top at most 2rem), the qualified type
    // by its own (font-size at most 1.5rem; base.p allows 2rem and base.a
    // is unconstrained, so neither the predecessor nor the argument
    // answered), a sibling tag by base.p.
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::> p + *" => ['default' => ['margin-top' => '3rem']],
      "container:$uuid::> *:not(pb-image)" => ['default' => ['margin-top' => '3rem']],
      "container:$uuid::> pb-text:has(a)" => ['default' => ['font-size' => '1.75rem']],
      "container:$uuid::> pb-text:not(:has(a))" => ['default' => ['font-size' => '1.75rem']],
      "container:$uuid::> p + p" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> p + *.default.margin-top"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> *:not(pb-image).default.margin-top"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> pb-text:has(a).default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> pb-text:not(:has(a)).default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> p + p.default.font-size"]);

    // An undeclared compound inside an argument ends the walk with the
    // existing correctives: a part against the qualified type's parts, a
    // type, the children section, and a tag in every mode.
    $errors = $this->validator->validate([
      "container:$uuid::> pb-text:has(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> *:not(pb-video)" => ['default' => ['margin-top' => '1rem']],
      "container:$uuid::> pb-text:has(> *)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:not(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:has(a) + pb-text:has(em)" => ['default' => ['font-size' => '1rem']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::> pb-text:has(.nope)"]);
    $this->assertContains('.link', $errors[0]->getContext()['declared_elements']);
    $this->assertNotContains('.band', $errors[0]->getContext()['declared_elements']);
    $this->assertSame(['STYLE_TYPE_NOT_DECLARED'], $codes["container:$uuid::> *:not(pb-video)"]);
    $this->assertSame(['STYLE_CHILDREN_NOT_DECLARED'], $codes["container:$uuid::> pb-text:has(> *)"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::> pb-text:not(.nope)"]);
    $this->assertContains('.band', $errors[3]->getContext()['declared_elements']);
    $this->assertNotContains('.link', $errors[3]->getContext()['declared_elements']);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["container:$uuid::> pb-text:has(a) + pb-text:has(em)"]);
    $this->enforce('open');
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::> pb-text:has(.nope)" => ['default' => ['font-size' => '1rem']],
      "container:$uuid::> pb-text:has(em)" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertArrayNotHasKey("container:$uuid::> pb-text:has(.nope)", $codes);
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["container:$uuid::> pb-text:has(em)"]);
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
        'elements' => ['.band' => ['label' => 'Band', 'property_groups' => ['spacing' => []]]],
        'children' => ['property_groups' => ['spacing' => []]],
        'pseudo' => ['before' => ['property_groups' => ['background' => []]]],
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
      "container:$uuid::.band::after" => ['default' => ['content' => '""', 'padding' => '1rem']],
      "container:$uuid::> *::before" => ['default' => ['content' => '""', 'margin-top' => '1rem']],
      "container:$uuid::> pb-text::before" => ['default' => ['content' => '""', 'font-size' => '1rem'], 'hover' => ['font-size' => '1.25rem']],
      "container:$uuid::p::after" => ['default' => ['content' => '""', 'font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['padding-top' => '3rem']],
      "container:$uuid::> pb-text::before" => ['default' => ['font-size' => '3rem']],
      "container:$uuid::p::after" => ['default' => ['font-size' => '3rem']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
      "container:$uuid::em::before" => ['default' => ['content' => '""']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::&::after.default.padding-top"], 'The host\'s constraint types the derived box.');
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::> pb-text::before.default.font-size"]);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["container:$uuid::p::after.default.font-size"]);
    $this->assertArrayNotHasKey("container:$uuid::.nope::before", $codes, 'An undeclared part is grammar on a guarded site, its box with it.');
    $this->assertSame(['STYLE_TAG_NOT_DECLARED'], $codes["container:$uuid::em::before"], 'An undeclared tag is refused in every mode, box or not.');

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
    $media = Media::create(['bundle' => $image_type->id(), 'name' => 'Box', $source_field => ['target_id' => $file->id()]]);
    $media->save();
    $token = MediaToken::build($media->uuid());
    $this->assertSame([], $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => $token]]]));
    $this->assertSame([], $this->validator->validate(["container:$uuid::> pb-text::before" => ['default' => ['content' => " url(media:{$media->uuid()}) "]]]));
    $this->assertSame([], $this->validator->validate(["container:$uuid::&::before" => ['default' => ['content' => ' NONE ']]]));
    foreach (['"hi"', 'attr(title)', 'counter(item)', 'open-quote', 'url(x.png)', 'url(x.svg)', 'linear-gradient(#000, #fff)', "$token, $token", "\"x\" $token", "none $token", "$token / \"alt\"", 'var(--x)', 'inherit', 'normal', "''"] as $value) {
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

    // content on a real box is refused in every mode, before any grant.
    foreach (['', '::.band', '::> *', '::> pb-text', '::p'] as $suffix) {
      $errors = $this->validator->validate(["container:$uuid$suffix" => ['default' => ['content' => '""', 'padding-top' => '1rem']]]);
      $this->assertCount(1, $errors, $suffix);
      $this->assertSame('STYLE_PROPERTY_PSEUDO_ONLY', $errors[0]->getCode(), $suffix);
      $this->assertSame("container:$uuid$suffix.default.content", $errors[0]->getPath(), $suffix);
      $this->assertSame(['before', 'after'], $errors[0]->getContext()['pseudo_elements'], $suffix);
      $this->assertStringContainsString('"{key}::&::before"', $errors[0]->getHint(), $suffix);
    }

    // No element scope: nothing wears at a box, on either lane.
    $this->assertNull($this->validator->captureScope("container:$uuid::&::before"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> pb-text::before"));
    $this->assertNull($this->validator->captureScope("container:$uuid::p::after"));
    DaedalusStylishVariant::create(['label' => 'text_soft', 'machine_name' => 'text_soft', 'scope' => 'block_type:text'])->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $errors = $this->validator->validate(["container:$uuid::> pb-text::before" => ['variants' => [['variant' => 'text_soft', 'fragment' => '']]]]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertNull($errors[0]->getContext()['element_scope']);
    $this->assertStringContainsString('lands on no element scope', $errors[0]->getMessage());
    $this->assertStringContainsString('a generated box ("::&::before"', $errors[0]->getHint());
    $errors = $this->validator->validateAt(['::> pb-text::before' => ['variants' => [['variant' => 'text_soft', 'fragment' => '']]]], Address::fromString('kind:container'));
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertNull($errors[0]->getContext()['element_scope']);

    // The variant lane and the defaults lane take the same keys, typed
    // the same way.
    $this->assertSame([], $this->validator->validateAt([
      '::&::before' => ['default' => ['content' => '""', 'background-color' => 'gold']],
      '::> pb-text::after' => ['default' => ['content' => '""', 'font-size' => '1rem']],
      'container(1)::&::after' => ['default' => ['content' => 'none']],
    ], Address::fromString('kind:container')));
    $codes = $this->codes($this->validator->validateAt([
      '::> pb-text::after' => ['default' => ['content' => '"x"', 'font-size' => '3rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes['::> pb-text::after.default.content']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::> pb-text::after.default.font-size']);
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
      "container:$uuid::.band::after" => ['default' => ['content' => '""']],
      "container:$uuid::> pb-text::before" => ['default' => ['content' => '""']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
      "container:$uuid" => ['default' => ['content' => '""']],
    ]);
    $codes = $this->codes($errors);
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["container:$uuid::&::before.default.padding-top"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::&::after"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::.band::after"]);
    $this->assertSame(['STYLE_PSEUDO_NOT_DECLARED'], $codes["container:$uuid::> pb-text::before"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["container:$uuid::.nope::before"]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["container:$uuid.default.content"]);
    $this->assertSame('The style guide does not declare the ::after pseudo-element of "container:' . $uuid . '".', $errors[1]->getMessage());
    $this->assertSame('after', $errors[1]->getContext()['pseudo']);
    $this->assertStringContainsString('{pseudo: {after: {property_groups: {…}}}}', $errors[1]->getHint());
    $this->assertSame('The style guide does not declare the ::after pseudo-element of ".band".', $errors[2]->getMessage());
    $this->assertSame('The style guide does not declare the ::before pseudo-element of "> pb-text".', $errors[3]->getMessage());
    $this->assertContains('.band', $errors[4]->getContext()['declared_elements']);
    // The corrective of a declared box teaches its own grant.
    $this->assertSame(['background', 'generated_content'], $errors[0]->getContext()['declared_groups']);
    $this->assertContains('content', $errors[0]->getContext()['declared_properties']);
    $this->assertNotContains('padding-top', $errors[0]->getContext()['declared_properties']);

    // Open: the derivation is granted, the undeclared part is grammar, the
    // content grammar and the real-box refusal hold.
    $this->enforce('open');
    $this->assertSame([], $this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['content' => '""', 'padding-top' => '3rem']],
      "container:$uuid::.nope::before" => ['default' => ['content' => '""']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "container:$uuid::&::after" => ['default' => ['content' => '"x"']],
      "container:$uuid::.band" => ['default' => ['content' => '""']],
    ]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes["container:$uuid::&::after.default.content"]);
    $this->assertSame(['STYLE_PROPERTY_PSEUDO_ONLY'], $codes["container:$uuid::.band.default.content"]);
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


  /**
   * Wear at a rule pairs by the landing, and the two rule refusals stand.
   *
   * A rule key captures the element its last compound names (a type's
   * root address, a tag's base tag), so a text variant wears at
   * "::> pb-text" and a container variant is refused there with the
   * text-scoped candidates; a part, the children and a subjectless
   * compound land on no element scope and wear nothing; a composition is
   * refused at any rule by its keys. keyAddress() keeps answering the
   * full address (the stack's and the media resolver's read).
   */
  public function testWearAtARule(): void {
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
      ['band', 'kind:container'],
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
    $wearing = static fn (string $key, string $reference): array => [$key => ['variants' => [['variant' => $reference, 'fragment' => '']]]];

    // The capture: an element its own root, a rule its landing, and
    // nothing where the targets are no element scope.
    $this->assertSame('kind:container', (string) $this->validator->captureScope("container:$uuid"));
    $this->assertSame('block_type:text', (string) $this->validator->captureScope("container:$uuid::> pb-text"));
    $this->assertSame('kind:container', (string) $this->validator->captureScope("container:$uuid::> pb-text > pb-container"));
    $this->assertSame('base:a', (string) $this->validator->captureScope("container:$uuid::a"));
    $this->assertSame('base:p', (string) $this->validator->captureScope("container:$uuid::> p + p"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> pb-text .link"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> *"));
    $this->assertNull($this->validator->captureScope("container:$uuid::> *:not(pb-image)"));
    $this->assertNull($this->validator->captureScope('bogus'));
    $this->assertSame('kind:container::> pb-text', (string) $this->validator->keyAddress("container:$uuid::> pb-text"));
    $this->assertSame('kind:container', (string) $this->validator->keyAddress("container:$uuid"));

    // Pairing by the landing: the text variant wears at the text rule,
    // the link treatment at the anchor rule; the container variant is
    // refused at both with the candidates that DO pair at the rule.
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid::> pb-text", 'text_soft')));
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid::a", 'link_style')));
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid", 'band')));
    $errors = $this->validator->validate($wearing("container:$uuid::> pb-text", 'band'));
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame("container:$uuid::> pb-text.variants.0", $errors[0]->getPath());
    $this->assertSame('block_type:text', $errors[0]->getContext()['element_scope']);
    // The candidates by the landing: the text variant and the tag
    // treatment (a base:{tag} scope pairs with every element address; the
    // tag itself is the offer-time gate), never the container's.
    $this->assertSame(['link_style', 'text_soft'], array_column($errors[0]->getContext()['candidates'], 'id'));
    $this->assertStringContainsString('a rule wears one for its landing', $errors[0]->getHint());
    $errors = $this->validator->validate($wearing("container:$uuid::a", 'band'));
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame('base:a', $errors[0]->getContext()['element_scope']);
    $this->assertSame(['link_style'], array_column($errors[0]->getContext()['candidates'], 'id'));
    // The element side is unchanged: a text variant never wears on the
    // container itself.
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->codes($this->validator->validate($wearing("container:$uuid", 'text_soft')))["container:$uuid.variants.0"]);

    // No element scope: a part, the children, a subjectless compound.
    foreach (['> pb-text .link', '> *', '> *:not(pb-image)'] as $selector) {
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
    foreach (['> pb-container', '> pb-text'] as $selector) {
      $errors = $this->validator->validate($wearing("container:$uuid::$selector", 'card'));
      $this->assertCount(1, $errors, $selector);
      $this->assertSame('STYLE_VARIANT_COMPOSITION_AT_RULE', $errors[0]->getCode(), $selector);
      $this->assertSame(['container(1)', 'block(text,1)'], $errors[0]->getContext()['slot_paths'], $selector);
      $this->assertStringContainsString('a rule wears flat variants only', $errors[0]->getMessage(), $selector);
    }
    $this->assertSame([], $this->validator->validate($wearing("container:$uuid", 'card')));

    // The not-found corrective lists the candidates by the landing too.
    $errors = $this->validator->validate($wearing("container:$uuid::> pb-text", 'ghost'));
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame(['link_style', 'text_soft'], array_column($errors[0]->getContext()['candidates'], 'id'));

    // The variant styles lane pairs a rule entry's nested references by
    // the same landing (a captured rule's wear travels as refs): the
    // text variant nests at a container variant's "::> pb-text" entry
    // and at a slot's rule, the link treatment at "::a", the container
    // variant is refused at the text rule with the text candidates, and
    // a rule landing on no element scope wears nothing.
    $nested = static fn (string $relative, string $reference): array => [$relative => ['variants' => [['variant' => $reference, 'fragment' => '']]]];
    $at = Address::fromString('kind:container');
    $this->assertSame([], $this->validator->validateAt($nested('::> pb-text', 'text_soft'), $at));
    $this->assertSame([], $this->validator->validateAt($nested('container(1)::> pb-text', 'text_soft'), $at));
    $this->assertSame([], $this->validator->validateAt($nested('::a', 'link_style'), $at));
    $errors = $this->validator->validateAt($nested('::> pb-text', 'band'), $at);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $this->assertSame('::> pb-text.variants.0', $errors[0]->getPath());
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

}
