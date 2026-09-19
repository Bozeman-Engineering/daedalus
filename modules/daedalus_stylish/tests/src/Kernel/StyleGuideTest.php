<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the style guide projection over the page builder declarations.
 *
 * Discovery and layering live at daedalus_ui's declaration spine;
 * these tests exercise daedalus_stylish's view of the layered result: styling
 * sections riding PROVIDER.page_builder.yml and its block_type/layout
 * siblings, per-theme resolution, enforcement modes, and cache delegation.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * The sub theme; its base chain is daedalus_stylish_guide_test_base_theme.
   */
  protected const THEME = 'daedalus_stylish_guide_test_theme';

  protected const BASE_THEME = 'daedalus_stylish_guide_test_base_theme';

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_guide_test_extra',
  ];

  /**
   * The style guide under test.
   */
  protected StyleGuide $styleGuide;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Activating a design system (the enforcement door) recompiles the
    // delivery files, which read the four material storages.
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
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * Kind declarations layer module → base theme → sub theme.
   */
  public function testKindLayering(): void {
    // Through the base theme: the later module's padding max (6rem, not
    // 4rem) map-merged with the theme's units — refining one constraint
    // does not restate the group.
    $section = $this->styleGuide->getKind('container', self::BASE_THEME);
    $this->assertSame('6rem', $section['property_groups']['spacing']['constraints']['padding']['max']);
    $this->assertSame(['rem', 'px'], $section['property_groups']['spacing']['constraints']['padding']['units']);
    $this->assertArrayHasKey('background', $section['property_groups']);

    // Through the sub theme: the whole base chain applies, and the sub
    // theme's list REPLACES the base theme's list rather than appending.
    $section = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame('6rem', $section['property_groups']['spacing']['constraints']['padding']['max']);
    $this->assertSame(['rem'], $section['property_groups']['spacing']['constraints']['padding']['units']);

    // NULL withdraws a declaration: the sub theme removes the color group.
    $field = $this->styleGuide->getKind('field', self::THEME);
    $this->assertArrayHasKey('typography', $field['property_groups']);
    $this->assertArrayNotHasKey('color', $field['property_groups']);
    $this->assertArrayHasKey('color', $this->styleGuide->getKind('field', self::BASE_THEME)['property_groups']);

    // The shipped field shell (daedalus_ui.page_builder.yml) is the
    // layer everything above merged over: the core markup chain with the
    // value slot on the item, with daedalus_stylish's property groups riding the
    // same parts (daedalus_stylish.page_builder.yml).
    $this->assertSame('Label', $field['elements']['.field__label']['label']);
    $this->assertSame('value', $field['elements']['.field__items']['elements']['.field__item']['contains']);
    $this->assertArrayHasKey('typography', $field['elements']['.field__label']['property_groups']);

    // Styling sections coexist with the spine's own owned sections in one
    // declaration: the entity kind carries daedalus_stylish's existence declaration
    // for the style map field, a foreign section from the guide's view.
    $entity = $this->styleGuide->getKind('entity', self::THEME);
    $this->assertFalse($entity['fields']['daedalus_stylish_styles']['element']);
  }

  /**
   * Block type declarations layer and sit on the universal block kind.
   */
  public function testBlockType(): void {
    $testimonial = $this->styleGuide->getBlockType('testimonial', self::THEME);

    // The universal block kind is the base layer under the declaration.
    $this->assertArrayHasKey('spacing', $testimonial['property_groups']);

    // The extra module's font-family list replaced the base module's.
    $typography = $testimonial['property_groups']['typography'];
    $this->assertSame(['Arial'], $typography['constraints']['font-family']['allowed']);
    $this->assertSame('2rem', $typography['constraints']['font-size']['max']);

    $this->assertSame(['style' => 'testimonial_default', 'emphasis' => 'testimonial_emphatic'], $testimonial['default_variants']);

    // Sub-element declarations merge across layers: the theme relabeled the
    // part, the module's constraints survive.
    $quote = $testimonial['elements']['.testimonial__quote'];
    $this->assertSame('Quote', $quote['label']);
    $this->assertSame('1.75rem', $quote['property_groups']['typography']['constraints']['font-size']['max']);

    // An undeclared bundle resolves to the universal block declaration, so
    // "everything selectable is stylable" holds without a per-type file.
    $undeclared = $this->styleGuide->getBlockType('undeclared', self::THEME);
    // Grants keep authoring order: daedalus_stylish's own motion, effects and
    // shadow, then the fixture's spacing layered over them.
    $this->assertSame(['motion' => [], 'effects' => [], 'shadow' => [], 'spacing' => []], $undeclared['property_groups']);
    $this->assertArrayNotHasKey('elements', $undeclared);

    $this->assertSame(['testimonial'], $this->styleGuide->getBlockTypes(self::THEME));
  }

  /**
   * Layout declarations layer and sit on the universal section kind.
   */
  public function testLayout(): void {
    $layout = $this->styleGuide->getLayout('layout_twocol_section', self::THEME);

    // The universal section kind is the base layer under the declaration —
    // the fully layered kind, alter included.
    $this->assertSame('6rem', $layout['property_groups']['spacing']['constraints']['padding']['max']);

    // The extra module's max-width ceiling replaced the base module's.
    $this->assertSame('96rem', $layout['property_groups']['sizing']['constraints']['max-width']['max']);

    // Sub-element declarations merge across layers: the theme relabeled the
    // part, the module's constraints survive.
    $container = $layout['elements']['.band-content'];
    $this->assertSame('Content container', $container['label']);
    $this->assertSame('80rem', $container['property_groups']['sizing']['constraints']['max-width']['max']);

    // An undeclared layout resolves to the universal section declaration.
    $undeclared = $this->styleGuide->getLayout('layout_onecol', self::THEME);
    $this->assertSame('6rem', $undeclared['property_groups']['spacing']['constraints']['padding']['max']);
    $this->assertArrayNotHasKey('elements', $undeclared);

    $this->assertSame(['layout_twocol_section'], $this->styleGuide->getLayouts(self::THEME));
  }

  /**
   * The alter hook is the highest-precedence layer.
   */

  /**
   * Base owns a prose tag's styleability; instances own identity.
   *
   * The shipped base section (daedalus_stylish.page_builder.yml) is the tag
   * tier's vocabulary: baseTags() lists its tags in authoring order,
   * baseTagElement() returns the declaration the tier validates against
   * (the widened block-level groups), and a legal-but-undeclared tag or
   * a tag outside BASE_TAGS resolves to NULL. instanceTags() reads only
   * the instance lane's identity map.
   */
  public function testBaseTagVocabulary(): void {
    $tags = $this->styleGuide->baseTags(self::THEME);
    $this->assertSame([
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      'p',
      'a',
      'ul',
      'ol',
      'li',
      'blockquote',
      'pre',
      'code',
      'img',
    ], $tags);
    foreach ($tags as $tag) {
      $this->assertContains($tag, StyleGuide::BASE_TAGS);
    }

    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'ul', 'ol', 'li', 'pre'] as $tag) {
      $groups = array_keys($this->styleGuide->baseTagElement($tag, self::THEME)['property_groups']);
      $this->assertSame(['typography', 'spacing', 'background', 'border'], $groups, "base.$tag carries the block-level prose groups.");
      $this->assertSame($this->styleGuide->getBase($tag, self::THEME), $this->styleGuide->baseTagElement($tag, self::THEME), 'Both prose lanes read one declaration.');
    }
    $this->assertSame(['typography', 'motion'], array_keys($this->styleGuide->baseTagElement('a', self::THEME)['property_groups']));
    $this->assertSame(['typography', 'background'], array_keys($this->styleGuide->baseTagElement('code', self::THEME)['property_groups']));

    // Legal but undeclared: a skin scope that degrades to [], no tag tier.
    $this->assertNull($this->styleGuide->baseTagElement('em', self::THEME));
    $this->assertSame([], $this->styleGuide->getBase('em', self::THEME));
    // Outside the vocabulary: nothing at all.
    $this->assertNull($this->styleGuide->baseTagElement('div', self::THEME));

    // Identity is the instance lane's; no namespace is declared here.
    $this->assertSame([], $this->styleGuide->instanceTags(self::THEME));
  }

  /**
   * The alter hook's edit lands on the kind it targets.
   */
  public function testAlter(): void {
    $region = $this->styleGuide->getKind('region', self::THEME);
    $this->assertSame(['layout' => []], $region['property_groups']);
  }

  /**
   * Only the defined element kinds may be queried.
   */
  public function testInvalidKind(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->styleGuide->getKind('paragraph', self::THEME);
  }

  /**
   * The enforcement mode is the active design system's; none is guarded.
   *
   * A shipped system owns the meaning of its law, so the mode is the
   * design_system entity's property, never site config: a site with no
   * active system (or a pointer naming a departed one) is guarded, an
   * active system answers its own mode, and switching systems switches
   * the mode with no write in between. The predicates answer the
   * questions call sites actually ask: strict closes the property/token
   * world; guarded and strict enforce authored constraints as law; open
   * enforces nothing beyond recognition and grammar.
   */
  public function testEnforcementModes(): void {
    $this->assertNull($this->container->get(ActiveDesignSystem::SERVICE_ID)->activeId());
    $this->assertSame(StyleGuide::ENFORCEMENT_GUARDED, $this->styleGuide->enforcement());
    $this->assertFalse($this->styleGuide->propertiesClosed());
    $this->assertTrue($this->styleGuide->constraintsAreLaw());

    $this->enforce('strict');
    $this->assertSame(StyleGuide::ENFORCEMENT_STRICT, DesignSystem::load('site')->getEnforcement(), 'The mode is written on the active system.');
    $this->assertSame(StyleGuide::ENFORCEMENT_STRICT, $this->styleGuide->enforcement());
    $this->assertTrue($this->styleGuide->propertiesClosed());
    $this->assertTrue($this->styleGuide->constraintsAreLaw());

    $this->enforce('open');
    $this->assertSame(StyleGuide::ENFORCEMENT_OPEN, $this->styleGuide->enforcement());
    $this->assertFalse($this->styleGuide->propertiesClosed());
    $this->assertFalse($this->styleGuide->constraintsAreLaw());

    // A second system carries its own mode: activating it is the switch.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis', 'enforcement' => StyleGuide::ENFORCEMENT_STRICT])->save();
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('borealis');
    $this->assertSame(StyleGuide::ENFORCEMENT_STRICT, $this->styleGuide->enforcement());
    $this->assertSame(StyleGuide::ENFORCEMENT_OPEN, DesignSystem::load('site')->getEnforcement(), 'The inactive system keeps its mode.');

    // A pointer naming a departed system, or none at all, is guarded.
    $this->config(ActiveDesignSystem::CONFIG_NAME)->set(ActiveDesignSystem::KEY, 'gone')->save();
    $this->assertSame(StyleGuide::ENFORCEMENT_GUARDED, $this->styleGuide->enforcement());
    $this->deactivateDesignSystem();
    $this->assertSame(StyleGuide::ENFORCEMENT_GUARDED, $this->styleGuide->enforcement());
  }

  /**
   * A group's properties allowlist narrows the grant, spine to corrective.
   *
   * The container model's motivating case: spacing on a container is
   * vertical rhythm (the horizontal inset IS the width story), so its
   * spacing group grants a subset of the group's vocabulary. The allowlist
   * follows the grant posture exactly — an excluded property is undeclared
   * on that node (rejected strict, grammar-only otherwise) — and the refs
   * projection carries it beside sets and constraints, so every consumer
   * (the client payload, the offer surfaces, the strict corrective) reads
   * the one authored fact.
   */
  public function testPropertyAllowlist(): void {
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']],
        ],
      ],
    ]);
    $this->styleGuide->resetCache();

    // The allowlist layers like any group key and rides the declaration
    // as authored (over longhands; the roster derives gap from its two).
    $container = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['padding-top', 'padding-bottom', 'row-gap', 'column-gap'], $container['property_groups']['spacing']['properties']);

    // The refs projection (the one spine the client payload and the AI
    // fragments read through) carries it keyed by set id.
    $refs = $this->styleGuide->propertySetRefs($container);
    $this->assertContains('spacing', $refs['sets']);
    $this->assertSame(['spacing' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']], $refs['properties']);

    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $context = ['theme' => self::THEME];

    // Guarded (the default): an excluded property keeps the undeclared
    // posture — recognition and grammar only, never a rejection.
    $this->assertSame([], $validator->validateAt(['' => ['default' => ['padding' => '2rem']]], Address::fromString('kind:container'), $context));

    // Strict closes the world to the granted subset. The values are the
    // site's law: padding-top rides the fixture container's own padding
    // cap (a node block wins over the suite's space binding), gap has no
    // node block, so it wears the suite's allowed zero.
    $this->enforce('strict');
    $this->assertSame([], $validator->validateAt(['' => ['default' => ['padding-top' => '2rem', 'gap' => '0']]], Address::fromString('kind:container'), $context));
    $errors = $validator->validateAt(['' => ['default' => ['padding' => '2rem']]], Address::fromString('kind:container'), $context);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $errors[0]->getCode());
    // The corrective teaches the granted subset, never a property the
    // allowlist excludes (other declared groups still contribute whole).
    $legal = $errors[0]->getContext()['declared_properties'];
    $this->assertContains('padding-top', $legal);
    $this->assertContains('gap', $legal);
    $this->assertNotContains('padding', $legal);
    $this->assertNotContains('padding-left', $legal);
    $this->assertNotContains('margin', $legal);
  }

  /**
   * Allowlist grammar problems fail the guide build loudly.
   */
  public function testInvalidPropertyAllowlist(): void {
    $state = $this->container->get('state');

    // Not a list of the group's own property names: the shape error.
    $state->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => ['property_groups' => ['spacing' => ['properties' => []]]],
    ]);
    $this->styleGuide->resetCache();
    try {
      $this->styleGuide->getKind('container', self::THEME);
      $this->fail('An empty properties allowlist must fail the build.');
    }
    catch (StyleGuideException $exception) {
      $this->assertStringContainsString('properties allowlist on the "spacing" group of kind "container"', $exception->getMessage());
      $this->assertStringContainsString('non-empty list', $exception->getMessage());
    }

    // A name the group does not provide: a typo would silently grant
    // nothing, so it is a build error, not dead grammar.
    $state->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => ['property_groups' => ['spacing' => ['properties' => ['padding-tpo']]]],
    ]);
    $this->styleGuide->resetCache();
    try {
      $this->styleGuide->getKind('container', self::THEME);
      $this->fail('An allowlist naming a property outside the group must fail the build.');
    }
    catch (StyleGuideException $exception) {
      $this->assertStringContainsString('names "padding-tpo", which the group does not provide', $exception->getMessage());
    }

    // A shorthand: grants are stated over longhands and the roster grants
    // the shorthand from them, so naming one would grant nothing by that
    // name. The refusal spells the longhands to list instead.
    $state->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => ['property_groups' => ['spacing' => ['properties' => ['padding', 'gap']]]],
    ]);
    $this->styleGuide->resetCache();
    try {
      $this->styleGuide->getKind('container', self::THEME);
      $this->fail('An allowlist naming a shorthand must fail the build.');
    }
    catch (StyleGuideException $exception) {
      $this->assertStringContainsString('names "padding", a shorthand; grants are stated over longhands, so list padding-top, padding-right, padding-bottom, padding-left instead', $exception->getMessage());
      $this->assertStringContainsString('names "gap", a shorthand; grants are stated over longhands, so list row-gap, column-gap instead', $exception->getMessage());
    }
  }

  /**
   * A grant is a function of the declaration alone.
   *
   * Placement curates offers and birth, never legality: an allowlist
   * binds at every placement depth, the declaration serves the map as
   * authored, the refs projection (the client payload's spine) carries
   * the allowlist and no depth projection, and the vocabulary roster (the
   * one grant derivation, VocabularyRosterTest) reads the same answer.
   */
  public function testAllowlistBindsAtEveryDepth(): void {
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']],
          'typography' => [],
        ],
      ],
    ]);
    $this->styleGuide->resetCache();
    $container = $this->styleGuide->getKind('container', self::THEME);

    $this->assertSame(['padding-top', 'padding-bottom', 'row-gap', 'column-gap'], $container['property_groups']['spacing']['properties']);
    $this->assertSame([], $container['property_groups']['typography']);

    $legal = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::kind('container'), self::THEME)->granted();
    $this->assertNotContains('padding', $legal);
    $this->assertNotContains('padding-left', $legal);
    $this->assertContains('gap', $legal);

    $refs = $this->styleGuide->propertySetRefs($container);
    $this->assertSame(['spacing' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']], $refs['properties']);
    $this->assertSame(['sets', 'constraints', 'properties'], array_keys($refs), 'the allowlist rides beside the sets and deltas alone: no depth projection');

    // The strict kind scope refuses the shorthand: the one answer the
    // map-key path gives a page band and a nested container alike
    // (StyleMapValidatorTest::testGrantIsTheSameAtEveryDepth).
    $this->enforce('strict');
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $errors = $validator->validateAt(['' => ['default' => ['padding' => '2rem']]], Address::fromString('kind:container'), ['theme' => self::THEME]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $errors[0]->getCode());
  }

  /**
   * The guide rides the spine's cache; resetCache() delegates to it.
   *
   * Discovery and layering (and their cache, cid
   * daedalus_ui_page_builder:{theme}) belong to daedalus_ui's
   * PageBuilderDeclarations; merge semantics are covered by its own kernel
   * tests. Daedalus Stylish only re-validates its control bindings per request.
   */
  public function testCaching(): void {
    $this->styleGuide->getDefinitions(self::THEME);
    $cache = $this->container->get('cache.discovery');
    $this->assertNotFalse($cache->get('daedalus_ui_page_builder:' . self::THEME));

    $this->styleGuide->resetCache();
    $this->assertFalse($cache->get('daedalus_ui_page_builder:' . self::THEME));
  }

}
