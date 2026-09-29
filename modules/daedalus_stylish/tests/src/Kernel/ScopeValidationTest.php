<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the validator's address door (validateAt()) for variant styles.
 *
 * Element-relative variant styles validate against a DECLARED scope with no
 * source element and no host entity — the path disk-shipped variants take at
 * import, AI-authored variants at create, and the variant gestures
 * (VariantManager) converge on. The style guide in play comes from
 * daedalus_stylish_guide_test: section declares spacing with padding max 4rem, block
 * declares spacing, and the testimonial block type declares typography
 * (font-size max 2rem) plus a .testimonial__quote sub-element (font-size max
 * 1.75rem).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ScopeValidationTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

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
    'daedalus_stylish_guide_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The validator under test.
   */
  protected StyleMapValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
  }

  /**
   * Creates and saves a variant for nested-reference cases.
   */
  protected function createVariant(string $machine_name, string $scope, array $styles = []): DaedalusStylishVariantInterface {
    $variant = $this->container->get('entity_type.manager')
      ->getStorage('daedalus_variant')
      ->create([
        'label' => $machine_name,
        'machine_name' => $machine_name,
        'scope' => $scope,
      ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    if ($styles !== []) {
      $variant->setStyles($styles);
    }
    $variant->save();
    return $variant;
  }

  /**
   * Collects error codes keyed by path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors from validateAt().
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * Kind scopes resolve the universal kind declaration.
   */
  public function testKindScope(): void {
    // Valid root styles: a declared constrained property and a condition a
    // plugin provides.
    $this->assertSame([], $this->validator->validateAt([
      '' => [
        'default' => ['padding' => '2rem'],
        'hover' => ['margin' => '1rem'],
      ],
    ], Address::fromString('kind:container')));

    // Guide constraints enforce: the section declaration caps padding at
    // 4rem. Paths are relative to the styles map — no key prefix.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding' => '6rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.padding']);

    // kind:block resolves the universal block declaration.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem']],
    ], Address::fromString('kind:block')));
  }

  /**
   * The '::> *' children pseudo-part validates like a declared part.
   *
   * The container kind declares children (layout), the twocol layout's
   * .band-content part declares its own, so root and
   * part-composed children keys validate against the children section's
   * property groups; malformed suffixes fail the key grammar; strict mode
   * rejects undeclared properties on declared children and the '::> *' key
   * itself on elements declaring none.
   */
  public function testChildrenPseudoPart(): void {
    // The root element's children: declared on the container kind.
    $this->assertSame([], $this->validator->validateAt([
      '::> *' => [
        'default' => ['flex-grow' => '1', 'flex-basis' => '0%'],
        'narrow' => ['flex-grow' => '0'],
      ],
    ], Address::fromString('kind:container')));

    // A part's children ('::.class > *'), on the layout scope's part.
    $this->assertSame([], $this->validator->validateAt([
      '::.band-content > *' => ['default' => ['flex-grow' => '1']],
    ], Address::fromString('layout:layout_twocol_section')));

    // Open site, element without a children declaration: grammar-checked
    // only, like an undeclared part.
    $this->assertSame([], $this->validator->validateAt([
      '::> *' => ['default' => ['flex-grow' => '1']],
    ], Address::fromString('kind:block')));

    // The children suffix never doubles and is always last.
    foreach (['::>::>', '::>::.card', '::>x', '::>', '::.band-content::>'] as $bad) {
      $codes = $this->codes($this->validator->validateAt([
        $bad => ['default' => ['flex-grow' => '1']],
      ], Address::fromString('kind:container')));
      $this->assertSame(['INVALID_STYLE_KEY'], $codes[$bad], "\"$bad\" must fail the key grammar.");
    }

    $this->enforce('strict');

    // Strict: the children declaration is the closed world; a recognized
    // property outside its groups is rejected with the declared set.
    $codes = $this->codes($this->validator->validateAt([
      '::> *' => ['default' => ['flex-grow' => '1', 'font-size' => '2rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes['::> *.default.font-size']);
    $this->assertArrayNotHasKey('::> *.default.flex-grow', $codes);

    // Strict: '::> *' on an element with no children declaration is
    // rejected as a whole.
    $codes = $this->codes($this->validator->validateAt([
      '::> *' => ['default' => ['flex-grow' => '1']],
    ], Address::fromString('kind:block')));
    $this->assertSame(['STYLE_CHILDREN_NOT_DECLARED'], $codes['::> *']);
  }

  /**
   * Block-type scopes resolve per-bundle declarations, parts included.
   */
  public function testBlockTypeScope(): void {
    // The effective declaration layers the universal block kind (spacing)
    // under the testimonial declaration (typography); the declared
    // .testimonial__quote part validates through its own declaration.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => '2rem', 'padding' => '1rem']],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
    ], Address::fromString('block_type:testimonial')));

    // Root and part carry different constraints: 2rem vs 1.75rem caps.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['font-size' => '2.5rem']],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.9rem']],
    ], Address::fromString('block_type:testimonial')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.font-size']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::.testimonial__quote.default.font-size']);

    // An undeclared bundle degrades to the universal block declaration —
    // never an error, so a variant may ship before its block type's guide.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem']],
    ], Address::fromString('block_type:mystery_meat')));
  }

  /**
   * Block plugin scopes resolve per-plugin declarations, parts included.
   */
  public function testBlockPluginScope(): void {
    // The effective declaration layers the universal block kind (spacing)
    // under the fancy_menu declaration (typography, font-size max 1.5rem);
    // the declared .menu-item part validates through its own declaration
    // (max 1.25rem).
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['font-size' => '1.5rem', 'padding' => '1rem']],
      '::.menu-item' => ['default' => ['font-size' => '1.25rem']],
    ], Address::fromString('block_plugin:fancy_menu')));

    // Root and part carry different constraints: 1.5rem vs 1.25rem caps.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['font-size' => '1.75rem']],
      '::.menu-item' => ['default' => ['font-size' => '1.4rem']],
    ], Address::fromString('block_plugin:fancy_menu')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.font-size']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::.menu-item.default.font-size']);

    // An undeclared plugin degrades to the universal block declaration —
    // never an error, so a variant may ship before its plugin's guide.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem']],
    ], Address::fromString('block_plugin:mystery_plugin')));

    // Plugin blocks carry no blueprint fields and no slot space: their
    // variants speak the path-less key space only.
    $codes = $this->codes($this->validator->validateAt([
      'block(text,1)' => ['default' => ['padding' => '1rem']],
    ], Address::fromString('block_plugin:fancy_menu')));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['block(text,1)']);
  }

  /**
   * The block family's two refinements pair with the kind, never each other.
   */
  public function testBlockPluginScopePairing(): void {
    $this->assertTrue(MaterialsRoster::scopesPair('block_plugin:fancy_menu', 'block_plugin:fancy_menu'));
    $this->assertTrue(MaterialsRoster::scopesPair('kind:block', 'block_plugin:fancy_menu'));
    $this->assertTrue(MaterialsRoster::scopesPair('block_plugin:fancy_menu', 'kind:block'));
    $this->assertFalse(MaterialsRoster::scopesPair('block_plugin:fancy_menu', 'block_plugin:other'));
    $this->assertFalse(MaterialsRoster::scopesPair('block_plugin:fancy_menu', 'block_type:fancy_menu'));
    $this->assertFalse(MaterialsRoster::scopesPair('block_type:testimonial', 'block_plugin:fancy_menu'));
    $this->assertFalse(MaterialsRoster::scopesPair('block_plugin:fancy_menu', 'kind:container'));
    $this->assertFalse(MaterialsRoster::scopesPair('layout:layout_onecol', 'block_plugin:fancy_menu'));
  }

  /**
   * Layout scopes resolve per-layout declarations, parts included.
   */
  public function testLayoutScope(): void {
    // The effective declaration layers the universal section kind (spacing,
    // padding max 4rem) under the layout declaration (sizing, max-width max
    // 90rem); the declared .band-content part validates through
    // its own declaration (max-width max 80rem).
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['max-width' => '90rem', 'padding' => '1rem']],
      '::.band-content' => ['default' => ['max-width' => '80rem']],
    ], Address::fromString('layout:layout_twocol_section')));

    // Root and part carry different constraints, and the section kind's
    // padding cap reaches the layout root through the base layer.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['max-width' => '95rem', 'padding' => '6rem']],
      '::.band-content' => ['default' => ['max-width' => '85rem']],
    ], Address::fromString('layout:layout_twocol_section')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.max-width']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.padding']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['::.band-content.default.max-width']);

    // An undeclared layout degrades to the universal section declaration —
    // never an error, so a variant may ship before its layout's guide.
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '2rem']],
    ], Address::fromString('layout:layout_onecol')));
  }

  /**
   * Slot keys validate against the declaration their slot addresses.
   *
   * Per-slot resolution: a container slot is a nested container (universal
   * container kind), a block slot is an instance of its bundle (per-bundle
   * declaration, universal block kind layered underneath), a field slot is
   * a field (universal field kind), and a part suffix resolves inside its
   * SLOT's declared elements.
   */
  public function testSlotDeclarationResolution(): void {
    // The root and its slots carry different declarations: the container
    // root's padding cap (4rem) does not reach the block slot, and the
    // testimonial slot enforces its own bundle constraints (font-size max
    // 2rem; spacing layered from the universal block kind), part cap
    // included (1.75rem).
    $this->assertSame([], $this->validator->validateAt([
      '' => ['default' => ['padding' => '4rem']],
      'block(testimonial,1)' => ['default' => ['font-size' => '2rem', 'padding' => '1rem']],
      'block(testimonial,1)::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
    ], Address::fromString('layout:layout_twocol_section')));

    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding' => '6rem']],
      'block(testimonial,2)' => ['default' => ['font-size' => '2.5rem']],
      'block(testimonial,2)::.testimonial__quote' => ['default' => ['font-size' => '1.9rem']],
    ], Address::fromString('layout:layout_twocol_section')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.padding']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['block(testimonial,2).default.font-size']);
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['block(testimonial,2)::.testimonial__quote.default.font-size']);

    // An undeclared bundle in a block slot degrades to the universal block
    // kind, exactly as an unresolvable placement does.
    $this->assertSame([], $this->validator->validateAt([
      'block(mystery_meat,1)' => ['default' => ['font-size' => '10rem']],
    ], Address::fromString('layout:layout_twocol_section')));

    // Block roots open at their fields; nested compositions recurse
    // through container(n) back into child addressing (containers all the
    // way down).
    $this->assertSame([], $this->validator->validateAt([
      'field(field_quote)' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('block_type:testimonial')));
    $this->assertSame([], $this->validator->validateAt([
      'container(1)/container(2)/block(testimonial,1)' => ['default' => ['font-size' => '1rem']],
      'container(1)' => ['default' => ['padding' => '1rem']],
    ], Address::fromString('layout:layout_twocol_section')));
  }

  /**
   * Strict mode pins each slot to ITS declaration, corrective payloads too.
   */
  public function testSlotStrictResolution(): void {
    $this->enforce('strict');

    // The field slot resolves the field kind (typography, color) — not the
    // testimonial root's declaration, whose layered universal block kind
    // declares spacing: font-size passes on the slot, padding does not.
    $codes = $this->codes($this->validator->validateAt([
      'field(field_quote)' => ['default' => ['font-size' => '1rem', 'padding' => '1rem']],
    ], Address::fromString('block_type:testimonial')));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes['field(field_quote).default.padding']);
    $this->assertArrayNotHasKey('field(field_quote).default.font-size', $codes);

    // The container slot resolves the universal container kind — its
    // declared properties differ from the per-layout root's constraints.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['padding' => '1rem']],
      'container(1)' => ['default' => ['color' => 'red']],
    ], Address::fromString('layout:layout_twocol_section')));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes['container(1).default.color']);
    $this->assertArrayNotHasKey('default.padding', $codes);

    // An unknown part on a slot errors against the SLOT's declared parts,
    // naming the slot, not the scope.
    $errors = $this->validator->validateAt([
      'block(testimonial,1)::.mystery' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('layout:layout_twocol_section'));
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_ELEMENT_NOT_DECLARED', $errors[0]->getCode());
    $this->assertSame(['.testimonial__quote'], $errors[0]->getContext()['declared_elements']);
    $this->assertStringContainsString('block(testimonial,1)', $errors[0]->getMessage());
  }

  /**
   * Slot paths the root kind does not structurally contain are rejected.
   */
  public function testSlotGrammarPerScope(): void {
    $cases = [
      // Container roots open at their children, never fields; the retired
      // region segment is gone from the grammar.
      ['field(field_quote)', 'layout:layout_twocol_section'],
      ['region(first)', 'layout:layout_twocol_section'],
      ['section(1)', 'kind:container'],
      // Block roots open at fields, never containers.
      ['container(1)', 'block_type:testimonial'],
      // Field and entity roots carry no paths at all.
      ['field(field_quote)', 'kind:field'],
      ['container(1)', 'kind:entity'],
      // Malformed segments: a block slot requires its index, a container
      // forbids a name.
      ['block(testimonial)', 'layout:layout_twocol_section'],
      ['container(first)', 'layout:layout_twocol_section'],
    ];
    foreach ($cases as [$key, $scope]) {
      $codes = $this->codes($this->validator->validateAt([
        $key => ['default' => ['padding' => '1rem']],
      ], Address::fromString($scope)));
      $this->assertSame(['INVALID_STYLE_KEY'], $codes[$key], "$key on $scope");
    }
  }

  /**
   * The seam is its own grammar gate: structural checks run here.
   */
  public function testStructuralGrammar(): void {
    // A relative key is '' or '::.class-name'; a selectable key is not one.
    $codes = $this->codes($this->validator->validateAt([
      '.loose' => ['default' => ['padding' => '1rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['.loose']);

    // Entries are condition-keyed maps, never scalars or lists.
    $codes = $this->codes($this->validator->validateAt([
      '' => 'padding: 2rem;',
      '::.inner' => ['a', 'b'],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['']);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['::.inner']);

    // Nested references are entry-shaped fragments (one grammar with the
    // style map lane), so the list and item shapes are the map's: a bare
    // string is not the structured reference shape, and an empty list is
    // not a list.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['variants' => ['wide_section']],
      '::.inner' => ['variants' => []],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['variants.0']);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['::.inner.variants']);

    // Conditions are the closed plugin vocabulary, and each maps properties
    // to scalar CSS values from recognized property groups.
    $codes = $this->codes($this->validator->validateAt([
      '' => [
        'sparkle' => ['padding' => '1rem'],
        'default' => ['padding' => ['1rem'], 'bling' => 'lots'],
        'hover' => 'padding: 1rem',
      ],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_CONDITION'], $codes['sparkle']);
    $this->assertSame(['INVALID_STYLE_VALUE'], $codes['default.padding']);
    $this->assertSame(['INVALID_STYLE_PROPERTY'], $codes['default.bling']);
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['hover']);

    // The retired freeform slot is rejected as malformed, never silently
    // ignored (no raw-CSS escape hatch).
    $codes = $this->codes($this->validator->validateAt([
      '' => ['freeform' => 'outline: none;'],
    ], Address::fromString('kind:container')));
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['freeform']);
  }

  /**
   * Entry-shaped fragments: nested "variants" lists validate as entries.
   *
   * One grammar with the style map lane: the same reference shape, dedupe,
   * and one-per-axis rules, with the '' fragment scope-pairing against the
   * scope the entry's SLOT addresses (the variant's own scope at the root,
   * the slot's element scope on a path).
   */
  public function testNestedVariantReferences(): void {
    $this->createVariant('root_mood', 'kind:container');
    $this->createVariant('inner_look', 'kind:container');
    $this->createVariant('quote_look', 'block_type:testimonial');
    $this->createVariant('outer_comp', 'kind:container', [
      'container(1)' => ['variants' => [['variant' => 'inner_look', 'fragment' => '']]],
    ]);

    // Valid: refs at the root (the variant's own scope), at a container
    // slot (kind:container), at a block slot (its bundle's scope), a
    // non-empty fragment reference (a bound lookup key), all coexisting
    // with ordinary condition blocks.
    $errors = $this->validator->validateAt([
      '' => [
        'variants' => [['variant' => 'root_mood', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
      ],
      'container(1)' => ['variants' => [['variant' => 'inner_look', 'fragment' => '']]],
      'container(2)' => ['variants' => [['variant' => 'outer_comp', 'fragment' => 'container(1)']]],
      'block(testimonial,1)' => [
        'variants' => [['variant' => 'quote_look', 'fragment' => '']],
        'default' => ['padding' => '1rem'],
      ],
    ], Address::fromString('kind:container'));
    $this->assertSame([], $this->codes($errors));

    // Dangling: a nested reference must name an EXISTING variant.
    $codes = $this->codes($this->validator->validateAt([
      'container(1)' => ['variants' => [['variant' => 'ghost', 'fragment' => '']]],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $codes['container(1).variants.0']);

    // Scope pairing is the family rule against the SLOT's element scope: a
    // container-scoped variant cannot ride a block slot, a block-scoped
    // variant cannot ride the container root or a container slot.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['variants' => [['variant' => 'quote_look', 'fragment' => '']]],
      'container(1)' => ['variants' => [['variant' => 'quote_look', 'fragment' => '']]],
      'block(testimonial,1)' => ['variants' => [['variant' => 'inner_look', 'fragment' => '']]],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $codes['variants.0']);
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $codes['container(1).variants.0']);
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $codes['block(testimonial,1).variants.0']);

    // One reference per axis and KIND, exactly as on a map entry:
    // root_mood and inner_look are both ROOT references on the built-in
    // style axis.
    $codes = $this->codes($this->validator->validateAt([
      'container(1)' => [
        'variants' => [
          ['variant' => 'inner_look', 'fragment' => ''],
          ['variant' => 'root_mood', 'fragment' => ''],
        ],
      ],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_VARIANT_AXIS_CONFLICT'], $codes['container(1).variants.1']);

    // The composition pair: within one axis a ROOT reference and a
    // FRAGMENT reference COMPOSE — apply distributes a fragment's nested
    // root references beside the fragment reference itself, and the
    // fragment's values (captured overrides) deliver over the nested
    // variant. Same grammar on both lanes.
    $this->assertSame([], $this->codes($this->validator->validateAt([
      'container(1)' => [
        'variants' => [
          ['variant' => 'inner_look', 'fragment' => ''],
          ['variant' => 'outer_comp', 'fragment' => 'container(1)'],
        ],
      ],
    ], Address::fromString('kind:container'))));

    // Condition blocks beside a "variants" list still validate — the list
    // is never mistaken for a condition.
    $codes = $this->codes($this->validator->validateAt([
      '' => [
        'variants' => [['variant' => 'root_mood', 'fragment' => '']],
        'default' => ['padding' => '6rem'],
      ],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['default.padding']);
  }

  /**
   * The saved variant's own axis is reserved at its ROOT entry.
   *
   * Apply places the variant's own reference on that axis for every
   * element that wears it, so a root '' reference sharing the axis could
   * never be worn (apply skips it loudly); the save-time gate keeps the
   * shape out of storage. The axis threads through as the "variant_axis"
   * context (field handler and VariantManager both pass it).
   */
  public function testRootEntryOwnAxisReference(): void {
    $this->createVariant('root_mood', 'kind:container');

    $codes = $this->codes($this->validator->validateAt([
      '' => ['variants' => [['variant' => 'root_mood', 'fragment' => '']]],
    ], Address::fromString('kind:container'), ['variant_axis' => 'style']));
    $this->assertSame(['STYLE_VARIANT_AXIS_CONFLICT'], $codes['variants.0']);

    // A slot entry's same-axis root reference is the flagship composition
    // case: it lands on the resolved child beside the fragment reference.
    $this->assertSame([], $this->codes($this->validator->validateAt([
      'container(1)' => ['variants' => [['variant' => 'root_mood', 'fragment' => '']]],
    ], Address::fromString('kind:container'), ['variant_axis' => 'style'])));

    // A root reference on ANOTHER axis composes with the variant's own.
    $this->assertSame([], $this->codes($this->validator->validateAt([
      '' => ['variants' => [['variant' => 'root_mood', 'fragment' => '']]],
    ], Address::fromString('kind:container'), ['variant_axis' => 'proportions'])));

    // Without the context (a caller that cannot know the axis) the gate
    // stays off — never a false rejection.
    $this->assertSame([], $this->codes($this->validator->validateAt([
      '' => ['variants' => [['variant' => 'root_mood', 'fragment' => '']]],
    ], Address::fromString('kind:container'))));
  }

  /**
   * The reference closure must stay a tree: cycles fail at save.
   */
  public function testNestedReferenceCycles(): void {
    $inner = $this->createVariant('inner_look', 'kind:container');
    $this->createVariant('outer_comp', 'kind:container', [
      'container(1)' => ['variants' => [['variant' => 'inner_look', 'fragment' => '']]],
    ]);

    // Saving styles INTO inner_look that reference outer_comp closes
    // inner_look -> outer_comp -> inner_look; the identity is recognized
    // by machine name and by uuid alike.
    $styles = [
      'container(1)' => ['variants' => [['variant' => 'outer_comp', 'fragment' => '']]],
    ];
    foreach (['inner_look', $inner->uuid()] as $identity) {
      $codes = $this->codes($this->validator->validateAt($styles, Address::fromString('kind:container'), ['variant_identity' => [$identity]]));
      $this->assertSame(['STYLE_VARIANT_REFERENCE_CYCLE'], $codes['container(1).variants.0'], $identity);
    }

    // A direct self-reference is the one-hop cycle.
    $codes = $this->codes($this->validator->validateAt([
      'container(1)' => ['variants' => [['variant' => 'inner_look', 'fragment' => '']]],
    ], Address::fromString('kind:container'), ['variant_identity' => ['inner_look']]));
    $this->assertSame(['STYLE_VARIANT_REFERENCE_CYCLE'], $codes['container(1).variants.0']);

    // The same styles saved into a DIFFERENT (or brand-new) variant are a
    // plain tree: no cycle.
    $this->assertSame([], $this->codes($this->validator->validateAt($styles, Address::fromString('kind:container'))));
    $this->assertSame([], $this->codes($this->validator->validateAt($styles, Address::fromString('kind:container'), ['variant_identity' => ['some_new_variant']])));
  }

  /**
   * A string that is not a scope names no address: the door's gate refuses it.
   *
   * The address door takes the object; the string gate ahead of it
   * (variantScope()) is what the untrusted callers (a blueprint payload's
   * scope field) run, and its corrective errors at "scope", loudly and
   * first. kind:region and an illegal base tag are addresses but not
   * scopes; a part address is never a scope.
   */
  public function testInvalidScope(): void {
    foreach ([
      'kind:widget',
      'kind:region',
      'testimonial',
      'block_type:Not-Valid',
      'block_type:',
      'layout:Not-Valid',
      'layout:',
      'base:div',
      'kind:block::.part',
    ] as $scope) {
      $this->assertNull(StyleMapValidator::variantScope($scope), $scope);
    }
    foreach ([
      'kind:container',
      'block_type:testimonial',
      'block_type:undeclared',
      'block_plugin:system_branding_block',
      'layout:layout_twocol_section',
      'base:a',
    ] as $scope) {
      $this->assertSame($scope, (string) StyleMapValidator::variantScope($scope), $scope);
    }
    $error = StyleMapValidator::invalidScopeError('kind:widget');
    $this->assertSame('scope', $error->getPath());
    $this->assertSame('INVALID_STYLE_SCOPE', $error->getCode());
    $this->assertSame(StyleMapValidator::SCOPE_KINDS, $error->getContext()['valid_kinds']);
    $this->assertStringContainsString('kind:region is not a scope', $error->getHint());

    // The door itself refuses a suffixed address as a caller bug: the key
    // carries the part, the address is the root the styles are worn at.
    $this->expectException(\InvalidArgumentException::class);
    $this->validator->validateAt(['' => ['default' => ['padding' => '1rem']]], Address::fromString('kind:block::.part'));
  }

  /**
   * Base:{tag} is a variant scope: tag treatments validate and pair.
   *
   * The base family (skin defaults tier) doubles as a VARIANT scope so
   * tag treatments — an anchor variant is a link style — mint, save, and
   * apply like element-scoped ones. Bare markup is the path-less key
   * space ('' and '::.class' only), the tag vocabulary stays closed, and
   * the scope pairs with EVERY element scope: whether an element renders
   * as the tag is a live-DOM fact, gated at OFFER time (the client's tag
   * filter), never at validation — the stencil-ownership posture.
   */
  public function testBaseScope(): void {
    // An undeclared (but legal) tag validates permissively, exactly like
    // the skin defaults lane: grammar-checked only.
    $this->assertSame([], $this->validator->validateAt([
      '' => [
        'default' => ['color' => '#336699'],
        'hover' => ['color' => '#003366'],
      ],
    ], Address::fromString('base:a')));

    // Bare markup has no structural children: slot paths are not keys.
    $codes = $this->codes($this->validator->validateAt([
      'block(text,1)' => ['default' => ['color' => '#336699']],
    ], Address::fromString('base:a')));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['block(text,1)']);

    // The tag vocabulary is closed: base:div is not a scope at all (the
    // string gate refuses it before any address reaches the door).
    $this->assertNull(StyleMapValidator::variantScope('base:div'));

    // The pairing rule: a base-scoped variant pairs with every element
    // scope (soft enforcement — offering filters by rendered tag).
    foreach ([
      'kind:block',
      'block_type:testimonial',
      'kind:container',
      'layout:layout_twocol_section',
      'kind:field',
      'kind:entity',
    ] as $element_scope) {
      $this->assertTrue(MaterialsRoster::scopesPair('base:a', $element_scope), $element_scope);
    }
    // No element scope is ever base:{tag} in the other direction: an
    // element-scoped variant never pairs with a base carrier scope.
    $this->assertFalse(MaterialsRoster::scopesPair('kind:block', 'base:a'));
  }

  /**
   * Strict mode closes the world exactly as element-keyed validation does.
   */
  public function testStrictMode(): void {
    $this->enforce('strict');

    // Undeclared sub-element on a declared scope.
    $codes = $this->codes($this->validator->validateAt([
      '::.mystery' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('block_type:testimonial')));
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes['::.mystery']);

    // Only declared property groups may be styled: section declares spacing,
    // not typography.
    $codes = $this->codes($this->validator->validateAt([
      '' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('kind:container')));
    $this->assertSame(['STYLE_PROPERTY_NOT_DECLARED'], $codes['default.font-size']);
  }

}
