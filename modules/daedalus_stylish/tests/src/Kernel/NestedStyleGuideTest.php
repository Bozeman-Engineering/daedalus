<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\DeclarationException;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the nested declaration grammar through daedalus_stylish's guide projection.
 *
 * Parts nest (a part declaration carries its own elements: map) and declare
 * contains: slots naming which model children mount inside them, in every
 * declaration dimension (kinds, block types, layouts). Style map keys stay
 * flat ({key}::.class), so lookups search the whole tree (findElement/
 * flattenElements) and a class must be unique within one declaration tree.
 * The contains grammar and the uniqueness rule are enforced loudly at the
 * declaration spine (daedalus_ui's PageBuilderDeclarations, throwing
 * DeclarationException) when the declarations build; control bindings stay
 * daedalus_stylish's own validation (StyleGuideException). The fixture declarations
 * come from daedalus_stylish_nested_test: a field kind shell (.field__items >
 * .field__item contains value), a card block type (.block__content contains
 * fields > .card__header contains field:field_title > .card__kicker,
 * .card__body), and a layout_onecol shell (.band-content
 * contains regions).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class NestedStyleGuideTest extends KernelTestBase {

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
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_nested_test',
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
    // The client payload serves the published swatches, so every build()
    // consumer needs the swatch entity schema.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
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
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
  }

  /**
   * Layers declarations over a guide dimension and rebuilds the guide.
   *
   * The daedalus_stylish_nested_test alter hook merges state-injected declarations as
   * the highest layer, so failure cases never live in YAML that would break
   * every other guide build.
   */
  protected function layer(string $dimension, array $declarations): void {
    $state = $this->container->get('state');
    $key = "daedalus_stylish_nested_test.$dimension";
    $state->set($key, array_merge($state->get($key, []), $declarations));
    $this->styleGuide->resetCache();
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
   * Nested declarations parse as authored in all three dimensions.
   */
  public function testNestedDeclarationsParse(): void {
    // Block type: the tree nests as authored, contains slots included.
    $card = $this->styleGuide->getBlockType('card', self::THEME);
    $content = $card['elements']['.block__content'];
    $this->assertSame('fields', $content['contains']);
    $this->assertSame('field:field_title', $content['elements']['.card__header']['contains']);
    $this->assertSame(['field:field_summary'], $content['elements']['.card__body']['contains']);

    // Kind: the field shell nests .field__item under .field__items.
    $field = $this->styleGuide->getKind('field', self::THEME);
    $this->assertSame('value', $field['elements']['.field__items']['elements']['.field__item']['contains']);

    // Layout: the container part carries the regions slot.
    $layout = $this->styleGuide->getLayout('layout_onecol', self::THEME);
    $this->assertSame('regions', $layout['elements']['.band-content']['contains']);
  }

  /**
   * The findElement() finds a part at any depth; flattenElements() lists all.
   */
  public function testTreeHelpers(): void {
    $card = $this->styleGuide->getBlockType('card', self::THEME);

    // Any depth: root child, one level down, two levels down.
    $this->assertSame('Content', PageBuilderDeclarations::findElement($card, '.block__content')['label']);
    $this->assertSame(['constraints' => ['font-size' => ['max' => '2rem']]], PageBuilderDeclarations::findElement($card, '.card__header')['property_groups']['typography']);
    $this->assertSame('Kicker', PageBuilderDeclarations::findElement($card, '.card__kicker')['label']);
    $this->assertNull(PageBuilderDeclarations::findElement($card, '.card__nope'));

    // Depth-first, parents before children, every depth under its own key.
    $this->assertSame(
      ['.block__content', '.card__header', '.card__kicker', '.card__body'],
      array_keys(PageBuilderDeclarations::flattenElements($card))
    );

    // The flat shape is the degenerate case: one level, no slots — the
    // helpers behave exactly like a direct elements[] lookup.
    $flat = ['elements' => ['.a' => ['label' => 'A'], '.b' => ['label' => 'B']]];
    $this->assertSame('A', PageBuilderDeclarations::findElement($flat, '.a')['label']);
    $this->assertNull(PageBuilderDeclarations::findElement($flat, '.c'));
    $this->assertSame(['.a', '.b'], array_keys(PageBuilderDeclarations::flattenElements($flat)));

    // No elements at all: nothing found, nothing flattened.
    $this->assertNull(PageBuilderDeclarations::findElement([], '.a'));
    $this->assertSame([], PageBuilderDeclarations::flattenElements(['property_groups' => []]));
  }

  /**
   * Sub-key validation resolves nested parts wherever they sit in the tree.
   */
  public function testSubKeyValidationAgainstNestedParts(): void {
    // Scope seam: the nested header's own constraint enforces (2rem cap),
    // the deeper kicker validates cleanly through its own declaration.
    $codes = $this->codes($this->validator->validateAt([
      '::.card__header' => ['default' => ['font-size' => '2.5rem']],
      '::.card__kicker' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('block_type:card')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::.card__header.default.font-size']);
    $this->assertArrayNotHasKey('::.card__kicker.default.font-size', $codes);

    // Full-map seam: a field key drills into the field kind's nested shell.
    $uuid = '11111111-1111-1111-1111-111111111111';
    $codes = $this->codes($this->validator->validate([
      "field:$uuid:field_body::.field__item" => ['default' => ['padding' => '2rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.field__item.default.padding"]);
  }

  /**
   * Strict-mode corrective payloads list the FLATTENED tree.
   */
  public function testStrictCorrectivePayloadsFlatten(): void {
    $this->enforce('strict');

    // Scope seam.
    $errors = $this->validator->validateAt([
      '::.mystery' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('block_type:card'));
    $this->assertSame('STYLE_ELEMENT_NOT_DECLARED', $errors[0]->getCode());
    $this->assertSame(
      ['.block__content', '.card__header', '.card__kicker', '.card__body'],
      $errors[0]->getContext()['declared_elements']
    );

    // Full-map seam, against the nested field kind shell. The .field__label
    // part comes from the shipped shell (daedalus_ui.page_builder.yml),
    // which the fixture's constraints layer over.
    $uuid = '11111111-1111-1111-1111-111111111111';
    $errors = $this->validator->validate([
      "field:$uuid:field_body::.mystery" => ['default' => ['padding' => '1rem']],
    ]);
    $this->assertSame('STYLE_ELEMENT_NOT_DECLARED', $errors[0]->getCode());
    $this->assertSame(['.field__label', '.field__items', '.field__item'], $errors[0]->getContext()['declared_elements']);
  }

  /**
   * A control binds a part found anywhere in the tree — and only there.
   */
  public function testControlBoundToNestedPart(): void {
    // The card YAML binds header_width to .card__header, a nested part; the
    // guide builds and the control parses as authored.
    $card = $this->styleGuide->getBlockType('card', self::THEME);
    $this->assertSame([
      'label' => 'Header width',
      'widget' => 'width',
      'element' => '.card__header',
    ], $card['controls']['header_width']);

    // A part absent from the whole tree still fails the build.
    $this->layer('block_types', [
      'card' => [
        'controls' => [
          'broken' => ['label' => 'Broken', 'widget' => 'width', 'element' => '.card__nope'],
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "card" binds element "\.card__nope", which is not a declared part/');
    $this->styleGuide->getBlockType('card', self::THEME);
  }

  /**
   * A class declared twice in one tree fails the build.
   */
  public function testDuplicateClassInTree(): void {
    $this->layer('block_types', [
      'dupe' => [
        'elements' => [
          '.wrap' => ['elements' => ['.quote' => ['label' => 'Nested']]],
          '.quote' => ['label' => 'Flat'],
        ],
      ],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/part "\.quote" of block type "dupe" is declared more than once in the elements tree/');
    $this->styleGuide->getBlockType('dupe', self::THEME);
  }

  /**
   * A cross-layer merge collision is the same duplicate, caught effectively.
   *
   * The universal block kind declares .chrome flat; a block type re-declares
   * it nested under .wrap. Each layer is coherent alone, but the EFFECTIVE
   * declaration (the merge getBlockType() serves) carries the class in two
   * positions — the build fails instead of shipping an ambiguous tree.
   */
  public function testCrossLayerMergeCollision(): void {
    $this->layer('kinds', [
      'block' => ['elements' => ['.chrome' => ['label' => 'Chrome']]],
    ]);
    $this->layer('block_types', [
      'collide' => ['elements' => ['.wrap' => ['elements' => ['.chrome' => ['label' => 'Nested chrome']]]]],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/part "\.chrome" of block type "collide" is declared more than once/');
    $this->styleGuide->getBlockType('collide', self::THEME);
  }

  /**
   * A contains slot that is neither token nor token list fails the build.
   */
  public function testMalformedContains(): void {
    $this->layer('block_types', [
      'broken' => ['elements' => ['.wrap' => ['contains' => ['fields' => TRUE]]]],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/contains slot of part "\.wrap" in block type "broken" must be a slot token or a list of slot tokens/');
    $this->styleGuide->getBlockType('broken', self::THEME);
  }

  /**
   * An unknown contains token fails the build.
   */
  public function testUnknownContainsToken(): void {
    $this->layer('block_types', [
      'broken' => ['elements' => ['.wrap' => ['contains' => 'sparkles']]],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/uses "sparkles", which is not a slot token; tokens are regions, fields, sections, value, field:NAME, or region:NAME/');
    $this->styleGuide->getBlockType('broken', self::THEME);
  }

  /**
   * Two slots claiming the same named child fail the build.
   */
  public function testChildClaimedTwice(): void {
    $this->layer('block_types', [
      'broken' => [
        'elements' => [
          '.head' => ['contains' => 'field:field_title'],
          '.tail' => ['contains' => ['field:field_title']],
        ],
      ],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/claims "field:field_title", which the part "\.head" already claims; a child mounts in exactly one slot/');
    $this->styleGuide->getBlockType('broken', self::THEME);
  }

  /**
   * Two generic buckets of one child kind in a tree fail the build.
   */
  public function testTwoGenericBuckets(): void {
    $this->layer('block_types', [
      'broken' => [
        'elements' => [
          '.outer' => ['contains' => 'fields', 'elements' => ['.inner' => ['contains' => 'fields']]],
        ],
      ],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/declares a second generic "fields" bucket, but the part "\.outer" already declares one/');
    $this->styleGuide->getBlockType('broken', self::THEME);
  }

  /**
   * Kind-impossible tokens fail the build in each dimension.
   */
  public function testKindImpossibleTokens(): void {
    // A block shell can never contain regions.
    $this->layer('block_types', [
      'broken' => ['elements' => ['.wrap' => ['contains' => 'regions']]],
    ]);
    try {
      $this->styleGuide->getBlockType('broken', self::THEME);
      $this->fail('Expected a DeclarationException.');
    }
    catch (DeclarationException $e) {
      $this->assertMatchesRegularExpression('/uses "regions", which is impossible in a block tree \(a block may contain: fields, field:NAME\)/', $e->getMessage());
    }
  }

  /**
   * A layout shell cannot contain fields or value.
   */
  public function testKindImpossibleTokenInLayout(): void {
    $this->layer('layouts', [
      'layout_broken' => ['elements' => ['.wrap' => ['contains' => 'value']]],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/in layout "layout_broken" uses "value", which is impossible in a container tree \(a container may contain: regions, region:NAME\)/');
    $this->styleGuide->getLayout('layout_broken', self::THEME);
  }

  /**
   * A region shell has no contains vocabulary at all.
   */
  public function testRegionHasNoContainsVocabulary(): void {
    $this->layer('kinds', [
      'region' => ['elements' => ['.region__inner' => ['contains' => 'fields']]],
    ]);
    $this->expectException(DeclarationException::class);
    $this->expectExceptionMessageMatches('/in kind "region" uses "fields", which is impossible in a region tree \(a region has no contains vocabulary; its children mount at the root\)/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * The tree ships once, in the anatomy fragment; the guide flattens.
   *
   * The AI schema splits the one declaration in two projections: the
   * bundle schema's anatomy fragment (daedalus_ui's daedalus_blueprint
   * context feed) carries the tree with contains: slots verbatim, and
   * the stylable section (StyleMapFieldHandler::rosters() over
   * addressesUnder(), rendered by VocabularyProse) carries ONLY what each
   * place may style, as prose entries at flat labels keyed the same way
   * style-map keys are flat.
   */
  public function testSchemaTreeShipsAsAnatomy(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    // The anatomy fragment: the whole tree, contains slots verbatim
    // (scalar and list), in every declaration dimension.
    $context = $this->container->get('daedalus_blueprint.context_resolver')
      ->getContext('entity_test', 'entity_test');
    $content = $context['block_types']['card']['anatomy']['elements']['.block__content'];
    $this->assertSame('fields', $content['contains']);
    $header = $content['elements']['.card__header'];
    $this->assertSame('field:field_title', $header['contains']);
    $this->assertSame('Kicker', $header['elements']['.card__kicker']['label']);
    $this->assertSame(['field:field_summary'], $content['elements']['.card__body']['contains']);
    $items = $context['anatomy']['kinds']['field']['elements']['.field__items'];
    $this->assertSame('value', $items['elements']['.field__item']['contains']);
    $this->assertSame('regions', $context['layouts']['layout_onecol']['anatomy']['elements']['.band-content']['contains']);
    // A part with no slot has no contains key at all (projection, not
    // NULL noise).
    $this->assertArrayNotHasKey('contains', $header['elements']['.card__kicker']);

    // The style guide: flat labels in the map's own key grammar carrying
    // prose phrases, no tree vocabulary. The fixture withdraws the suite's
    // dimension and type bindings in its site section (the one binding
    // line left is daedalus_stylish's shipped font-family -> font scale), so each
    // constraint block reads as its own node's deviations line at that
    // node's flat label; a cap authored on a shorthand reads as one phrase
    // per served value space, the family expanded at the source.
    $this->enforce('strict');
    $definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[DaedalusStylishFieldManager::FIELD_NAME];
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);
    $guide = $handler->getSchemaAdditions($definition)['style_guide'];
    $this->assertSame(['font-family' => 'font values'], $guide['bindings']);
    $this->assertSame([
      'kinds.field::.field__item' => 'padding at most 1rem; padding-top at most 1rem; padding-right at most 1rem; padding-bottom at most 1rem; padding-left at most 1rem',
      'block_types.card::.card__header' => 'font-size at most 2rem',
      'layouts.layout_onecol::.band-content' => 'max-width at most 80rem',
    ], $guide['deviations']);
    // The stylable section is {guidance, entries} and nothing else: no
    // per-element tree, no set or constraint facts beside the phrases.
    $this->assertSame(['guidance', 'entries'], array_keys($guide['stylable']));
    $entries = $guide['stylable']['entries'];
    // Every entry is a prose phrase at a flat label: a root
    // (kinds.block, block_types.card, layouts.layout_onecol), one part
    // suffix at most ("::.class-name"), an optional children suffix
    // ("::> *"). A nested part is labeled by its own class alone, never by
    // its ancestry, exactly as the style map keys it.
    foreach ($entries as $label => $phrase) {
      $this->assertIsString($phrase, $label);
      $this->assertMatchesRegularExpression('/^(kinds|block_types|block_plugins|layouts)\.[a-z0-9_]+(::\.[a-z0-9_-]+)?(::> \*)?$/', $label);
    }
    // The card refines its kind (typography on top of the block's
    // spacing), so its root and each of its four parts are taught; the
    // header's phrase names the typography it grants, and the kicker
    // (nested under the header in the tree) is labeled flat.
    $this->assertStringStartsWith('the kinds.block roster, plus ', $entries['block_types.card']);
    $this->assertStringContainsString('font-size', $entries['block_types.card']);
    $this->assertStringContainsString('font-size', $entries['block_types.card::.card__header']);
    $this->assertArrayHasKey('block_types.card::.block__content', $entries);
    $this->assertArrayHasKey('block_types.card::.card__body', $entries);
    $this->assertArrayHasKey('block_types.card::.card__kicker', $entries);
    $this->assertArrayNotHasKey('block_types.card::.card__header::.card__kicker', $entries);
    // The field kind's nested item and the layout's container are taught
    // the same flat way in their own dimensions.
    $this->assertArrayHasKey('kinds.field::.field__item', $entries);
    $this->assertStringContainsString('max-width', $entries['layouts.layout_onecol::.band-content']);
  }

  /**
   * The client payload ships declarations nested as-authored.
   *
   * ClientStyleGuide keeps the elements tree structure (only the styling
   * refs factor to sets/constraints), so the tree and its contains slots
   * reach declarationForKey()/partsForKey() untouched.
   */
  public function testClientPayloadShipsTree(): void {
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $content = $payload['blockTypes']['card']['elements']['.block__content'];
    $this->assertSame('fields', $content['contains']);
    $this->assertSame('field:field_title', $content['elements']['.card__header']['contains']);
    $this->assertSame('value', $payload['kinds']['field']['elements']['.field__items']['elements']['.field__item']['contains']);
    $this->assertSame('regions', $payload['layouts']['layout_onecol']['elements']['.band-content']['contains']);
  }

  /**
   * Layers the '.s-*' pattern part onto the field kind, with constraints.
   */
  protected function layerFieldPattern(): void {
    $this->layer('kinds', [
      'field' => [
        'elements' => [
          '.s-*' => [
            'label' => 'Text element',
            'property_groups' => [
              'typography' => [
                'constraints' => [
                  'font-size' => ['max' => '2rem'],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
  }

  /**
   * A concrete instance part resolves through its pattern declaration.
   *
   * The open-key seam: a minted identity class ('.s-a3') is never declared
   * concretely, so exact lookups miss it and the '.s-*' pattern part
   * answers instead. Exact declarations always win over the pattern, keys
   * stay open only where the pattern is declared, and the pattern's
   * property groups keep properties closed-world (constraints enforce).
   */
  public function testPatternPartLookup(): void {
    $this->layerFieldPattern();
    $field = $this->styleGuide->getKind('field', self::THEME);

    // findElement() stays exact; findPatternElement() covers the namespace.
    $this->assertNull(PageBuilderDeclarations::findElement($field, '.s-a3'));
    $this->assertSame('Text element', PageBuilderDeclarations::findPatternElement($field, '.s-a3')['label']);
    // Outside the namespace, or the bare prefix: no match.
    $this->assertNull(PageBuilderDeclarations::findPatternElement($field, '.sx-a3'));
    $this->assertNull(PageBuilderDeclarations::findPatternElement($field, '.s-'));
    // An exactly declared part is untouched by the pattern lookup order.
    $this->assertSame('Item', PageBuilderDeclarations::findElement($field, '.field__item')['label']);

    // Full-map validation resolves the pattern declaration: its
    // constraints enforce on any minted class.
    $uuid = '11111111-1111-1111-1111-111111111111';
    $codes = $this->codes($this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '3rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes["field:$uuid:field_body::.s-a3.default.font-size"]);

    // Strict mode: the pattern OPENS the key namespace (a minted class
    // validates without its own declaration) while properties stay
    // closed-world under the pattern's groups; a part outside the
    // namespace still fails as undeclared.
    $this->enforce('strict');
    $this->assertSame([], $this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['font-size' => '1rem']],
    ]));
    $codes = $this->codes($this->validator->validate([
      "field:$uuid:field_body::.s-a3" => ['default' => ['padding' => '1rem']],
      "field:$uuid:field_body::.mystery" => ['default' => ['font-size' => '1rem']],
    ]));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes["field:$uuid:field_body::.s-a3.default.padding"]);
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes["field:$uuid:field_body::.mystery"]);
  }

  /**
   * Instance parts are rejected in variant styles, even where declared.
   *
   * The server half of the capture exclusion (captureSources in
   * variant-scope.js): identity classes are minted per content instance,
   * so a variant carrying '::.s-xx' styles would be inert or lying on
   * every other element. The gate fires regardless of the pattern
   * declaration; declared non-pattern parts keep validating as before.
   */
  public function testInstancePartsStayOutOfVariants(): void {
    $this->layerFieldPattern();
    $codes = $this->codes($this->validator->validateAt([
      '::.s-a3' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:field')));
    $this->assertSame(['STYLE_INSTANCE_PART_IN_VARIANT'], $codes['::.s-a3']);

    // The declared shell parts of the same scope still travel.
    $this->assertSame([], $this->validator->validateAt([
      '::.field__item' => ['default' => ['padding' => '1rem']],
    ], Address::fromString('kind:field')));
  }

  /**
   * Pattern grammar: misplaced stars and shell vocabulary fail the build.
   */
  public function testPatternPartGrammar(): void {
    // A star anywhere but trailing in a class-shaped key is a typo, not a
    // pattern.
    $this->layer('block_types', [
      'broken' => ['elements' => ['.s*x' => ['label' => 'Broken']]],
    ]);
    try {
      $this->styleGuide->getBlockType('broken', self::THEME);
      $this->fail('Expected a DeclarationException.');
    }
    catch (DeclarationException $e) {
      $this->assertMatchesRegularExpression('/part "\.s\*x" of block type "broken" is not a valid pattern part key/', $e->getMessage());
    }

    // A pattern names a class namespace, not a concrete wrapper: the shell
    // vocabulary (contains, nested elements) cannot apply.
    $this->layer('block_types', [
      'broken' => [
        'elements' => [
          '.s*x' => NULL,
          '.p-*' => ['contains' => 'fields', 'elements' => ['.inner' => ['label' => 'Inner']]],
        ],
      ],
    ]);
    try {
      $this->styleGuide->getBlockType('broken', self::THEME);
      $this->fail('Expected a DeclarationException.');
    }
    catch (DeclarationException $e) {
      $this->assertMatchesRegularExpression('/pattern part "\.p-\*" of block type "broken" declares a contains slot/', $e->getMessage());
      $this->assertMatchesRegularExpression('/pattern part "\.p-\*" of block type "broken" declares nested elements/', $e->getMessage());
    }
  }

}
