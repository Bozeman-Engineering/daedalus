<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore zzzzqx
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the vocabulary roster: what one address may style, resolved.
 *
 * The roster's answers per address over the guide-test declarations
 * (daedalus_stylish_guide_test layered under daedalus_stylish_guide_test_extra and the
 * sub theme): grants with allowlists resolved, value spaces resolved node
 * then site, axes with their depth stamps, conditions, parts, the
 * applicable materials, enforcement, cache metadata, the correctives,
 * and the builder's memo; and the lock of the client twin (style-guide.js
 * over the payload ClientStyleGuide serves) against the roster.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VocabularyRosterTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use NodeSuitesTrait;
  use UserCreationTrait;

  /**
   * The sub theme; its base chain is daedalus_stylish_guide_test_base_theme.
   */
  protected const THEME = 'daedalus_stylish_guide_test_theme';

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
    'daedalus_stylish_guide_test',
    'daedalus_stylish_guide_test_extra',
    // Root-only qualified axes on layout_onecol.
    'daedalus_stylish_section_defaults_test',
    // The space scale, a site constraint default and a scale-constrained
    // block padding.
    'daedalus_stylish_scale_constraint_test',
  ];

  /**
   * The roster builder under test.
   */
  protected VocabularyRosterBuilder $builder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
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
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->builder = $this->container->get('daedalus_stylish.vocabulary_roster');
  }

  /**
   * Builds the roster for a printed address under the test theme.
   */
  protected function roster(string $address): VocabularyRoster {
    return $this->builder->build(Address::fromString($address), self::THEME);
  }

  /**
   * Grants resolve per address: kinds, refinements, their degradations.
   */
  public function testGrants(): void {
    // The container kind: the fixture's spacing plus the extra layer's
    // background, whole groups.
    $container = $this->roster('kind:container');
    $this->assertSame(['spacing', 'background'], $container->groups());
    $this->assertContains('padding', $container->granted());
    $this->assertContains('padding-left', $container->granted());
    $this->assertContains('background-color', $container->granted());
    $this->assertNotContains('font-size', $container->granted());
    $this->assertTrue($container->grantsProperty('gap'));
    $this->assertFalse($container->grantsProperty('font-size'));
    $this->assertSame('spacing', $container->groupOf('gap'));
    $this->assertNull($container->groupOf('font-size'));
    $this->assertTrue($container->declared());
    $this->assertSame(self::THEME, $container->theme());
    $this->assertSame('kind:container', (string) $container);
    $this->assertTrue($container->address()->equals(Address::kind('container')));

    // The block kind: daedalus_stylish's own motion, effects and shadow, then the
    // fixture's spacing, in authoring order.
    $block = $this->roster('kind:block');
    $this->assertSame(['motion', 'effects', 'shadow', 'spacing'], $block->groups());

    // A block type refines the block kind (typography added); an
    // undeclared bundle degrades to the kind; a block plugin refines the
    // same way.
    $testimonial = $this->roster('block_type:testimonial');
    $this->assertSame(['motion', 'effects', 'shadow', 'spacing', 'typography'], $testimonial->groups());
    $this->assertSame($block->granted(), $this->roster('block_type:undeclared')->granted());
    $this->assertContains('font-size', $this->roster('block_plugin:fancy_menu')->granted());
    $this->assertSame($block->granted(), $this->roster('block_plugin:undeclared')->granted());

    // A layout refines the container kind (sizing added); an undeclared
    // layout degrades to the kind.
    $this->assertContains('max-width', $this->roster('layout:layout_twocol_section')->granted());
    $this->assertSame($container->granted(), $this->roster('layout:layout_onecol')->granted());

    // A base tag: the site declaration's base section (the paragraph's
    // block-level prose groups); a legal undeclared tag grants nothing
    // yet is a place.
    $this->assertSame(['typography', 'spacing', 'background', 'border'], $this->roster('base:p')->groups());
    $em = $this->roster('base:em');
    $this->assertTrue($em->declared());
    $this->assertSame([], $em->granted());

    // The granted list is unique and flat.
    $this->assertSame(array_values(array_unique($container->granted())), $container->granted());
  }

  /**
   * An allowlist narrows the grant at every depth; the kind's roster is one.
   */
  public function testAllowlistResolved(): void {
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap']],
        ],
      ],
    ]);
    // The guide's reset drops the rosters built over the old layering.
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    // The allowlist's longhands in group order, plus the gap shorthand the
    // family rule grants over its two longhands.
    $container = $this->roster('kind:container');
    $this->assertSame(['padding-top', 'padding-bottom', 'gap', 'row-gap', 'column-gap'], $container->grants()['spacing']);
    $this->assertNotContains('padding', $container->granted());
    $this->assertNotContains('padding-left', $container->granted());
    $this->assertContains('gap', $container->granted());
    // Other groups still contribute whole.
    $this->assertContains('background-color', $container->granted());
    // The layout rides the same narrowed kind.
    $this->assertNotContains('padding', $this->roster('layout:layout_onecol')->granted());
  }

  /**
   * A shorthand is granted iff every longhand it sets is granted.
   *
   * Grants are stated over longhands (the Spacing and Border groups
   * declare their families, PropertyGroupInterface::getShorthands()); the
   * roster derives the shorthand like an allowlist, in group order, so
   * every consumer reads the answer from the granted names and none
   * decides it from the sides. A miss on a partly granted family names
   * the missing longhands; a whole group grants its shorthands with the
   * rest.
   */
  public function testShorthandFamilies(): void {
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => ['properties' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'row-gap']],
          'border' => [
            'properties' => [
              'border-top-left-radius',
              'border-top-right-radius',
              'border-bottom-right-radius',
              'border-top-width',
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    $container = $this->roster('kind:container');
    $this->assertSame(['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'row-gap'], $container->grants()['spacing'], 'All four sides grant the shorthand; one gap longhand does not grant gap.');
    $this->assertSame([
      'border-top-width',
      'border-top-left-radius',
      'border-top-right-radius',
      'border-bottom-right-radius',
    ], $container->grants()['border'], 'Three corners grant no border-radius; one side width grants no border-width.');
    $this->assertTrue($container->grantsProperty('padding'));
    $this->assertFalse($container->grantsProperty('gap'));
    $this->assertFalse($container->grantsProperty('border-radius'));
    $this->assertFalse($container->grantsProperty('margin'), 'Nothing of the margin family is granted.');

    // The corrective names the missing longhands of a partly granted
    // family, then the neighbors, then the roster's home; a family the
    // address grants nothing of is a stranger like any other name.
    $this->assertSame(
      '"gap" sets row-gap and column-gap and is granted only where all of them are; column-gap is not granted here. Did you mean row-gap? ' . VocabularyRoster::ROSTER_HOME,
      $container->corrective('gap'),
    );
    $this->assertSame(
      '"border-radius" sets border-top-left-radius, border-top-right-radius, border-bottom-right-radius and border-bottom-left-radius and is granted only where all of them are; border-bottom-left-radius is not granted here. Did you mean border-top-width, border-top-left-radius, border-top-right-radius or border-bottom-right-radius? ' . VocabularyRoster::ROSTER_HOME,
      $container->corrective('border-radius'),
    );
    $this->assertStringStartsWith('Nothing granted here is close to "margin".', $container->corrective('margin'));
    $this->assertSame(['border-top-width', 'border-top-left-radius', 'border-top-right-radius'], $container->candidates('border-radius', 3));

    // Two missing sides read as a pair; the shorthand never rides the
    // did-you-mean list of its own miss.
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => ['property_groups' => ['spacing' => ['properties' => ['margin-top', 'margin-bottom']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->assertSame(
      '"margin" sets margin-top, margin-right, margin-bottom and margin-left and is granted only where all of them are; margin-right and margin-left are not granted here. Did you mean margin-top or margin-bottom? ' . VocabularyRoster::ROSTER_HOME,
      $this->roster('kind:container')->corrective('margin'),
    );

    // The whole group: every family's shorthand rides with its longhands.
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => ['property_groups' => ['spacing' => [], 'border' => []]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $whole = $this->roster('kind:container');
    foreach (['padding', 'margin', 'gap', 'border-width', 'border-style', 'border-color', 'border-radius'] as $shorthand) {
      $this->assertTrue($whole->grantsProperty($shorthand), $shorthand);
    }
    // The outline longhands ride the border grant (curated into the Border
    // group, no family of their own); the `outline` shorthand stays wide,
    // so a whole-group grant never reaches it.
    foreach (['outline-width', 'outline-style', 'outline-color', 'outline-offset'] as $name) {
      $this->assertTrue($whole->grantsProperty($name), $name);
      $this->assertSame('border', $whole->groupOf($name));
    }
    $this->assertContains('outline-width', $whole->grants()['border']);
    $this->assertFalse($whole->grantsProperty('outline'), 'The outline shorthand is wide-tier, not part of the border grant.');

    // A hand-built roster without the family table corrects by neighbors
    // alone: the families are the manager's vocabulary, handed in by the
    // builder.
    $bare = new VocabularyRoster(Address::kind('block'), 'spec', ['spacing' => ['padding-top', 'padding-bottom']]);
    $this->assertSame('Did you mean padding-top or padding-bottom? ' . VocabularyRoster::ROSTER_HOME, $bare->corrective('padding'));
    $this->assertSame(
      ['padding' => ['padding-top', 'padding-right', 'padding-bottom', 'padding-left']],
      array_intersect_key($this->container->get('plugin.manager.daedalus_stylish_property_group')->getShorthands(), ['padding' => TRUE]),
    );
  }

  /**
   * Parts, the tag tier and the children pseudo-part resolve as addresses.
   */
  public function testPartsAndChildren(): void {
    $testimonial = $this->roster('block_type:testimonial');
    $this->assertSame(['.testimonial__quote' => 'Quote'], $testimonial->parts());
    $this->assertFalse($testimonial->childrenDeclared());

    $quote = $this->roster('block_type:testimonial::.testimonial__quote');
    $this->assertTrue($quote->declared());
    $this->assertSame(['typography'], $quote->groups());
    $this->assertSame('1.75rem', $quote->valueSpace('font-size')['max']);
    $this->assertSame([], $quote->parts());

    // Parts nest: the layout's part declares its own children.
    $layout = $this->roster('layout:layout_twocol_section');
    $this->assertSame(['.band-content' => 'Content container'], $layout->parts());
    $band = $this->roster('layout:layout_twocol_section::.band-content');
    $this->assertSame(['sizing'], $band->groups());
    $this->assertTrue($band->childrenDeclared());
    $children = $this->roster('layout:layout_twocol_section::.band-content > *');
    $this->assertTrue($children->declared());
    $this->assertSame(['layout'], $children->groups());

    // The container kind declares children; the block kind does not.
    $this->assertSame(['layout'], $this->roster('kind:container::> *')->groups());
    $this->assertTrue($this->roster('kind:container')->childrenDeclared());
    $undeclared = $this->roster('kind:block::> *');
    $this->assertFalse($undeclared->declared());
    $this->assertSame([], $undeclared->granted());

    // An undeclared part is a place the declaration does not carry.
    $nope = $this->roster('kind:block::.nope');
    $this->assertFalse($nope->declared());
    $this->assertSame([], $nope->granted());

    // The tag tier: the site's base declaration of the tag on any
    // element; an undeclared legal tag is no tier.
    $tier = $this->roster('kind:block::p');
    $this->assertTrue($tier->declared());
    $this->assertSame(['typography', 'spacing', 'background', 'border'], $tier->groups());
    $this->assertFalse($this->roster('kind:block::em')->declared());

    // A part or children address carries its element's axes.
    $this->assertSame($testimonial->axisNames(), $quote->axisNames());
    $this->assertSame($layout->axisNames(), $children->axisNames());
  }

  /**
   * A terminal pseudo-element lands on its host's generated box.
   *
   * Existence is universal, vocabulary declared-or-derived: undeclared,
   * the box carries its host's grant (allowlists and constraints with it)
   * plus the pseudo-only generated_content group and reports itself
   * undeclared as a KIND_PSEUDO compound after the chain, so a strict
   * site can refuse it while an open one grants the derivation; declared
   * by the host's pseudo section, the box carries that section's grant
   * plus generated_content. The anchor lands on the owner's root, a part
   * pseudo on the part, a type's on the type's root, a tag's on the base
   * tag; an undeclared host ends the walk before the box; no variant
   * pairs at a box (landingScope() NULL); generated_content is never
   * granted on a real box.
   */
  public function testPseudoElements(): void {
    // Derived on the owner's root: the testimonial's groups plus the
    // existence switch, the host's constraints riding along.
    $before = $this->roster('block_type:testimonial::&::before');
    $this->assertFalse($before->declared());
    $this->assertSame(['combinator' => NULL, 'kind' => VocabularyRoster::KIND_PSEUDO, 'name' => 'before', 'qualifiers' => [], 'position' => 0], $before->undeclared());
    $this->assertSame(['motion', 'effects', 'shadow', 'spacing', 'typography', 'generated_content'], $before->groups());
    $this->assertSame(['content'], $before->grants()['generated_content']);
    $this->assertTrue($before->grantsProperty('content'));
    $this->assertSame('generated_content', $before->groupOf('content'));
    $this->assertSame('2rem', $before->valueSpace('font-size')['max'], 'The host\'s constraints ride the derivation.');
    $this->assertSame([], $before->valueSpace('content'));
    $this->assertSame([], $before->parts());
    $this->assertFalse($before->childrenDeclared());
    $this->assertNull($before->landingScope());
    $this->assertSame([], $before->variants());
    $this->assertSame($this->roster('block_type:testimonial')->axisNames(), $before->axisNames(), 'A box carries its element\'s axes.');
    $this->assertSame('block_type:testimonial::&::before', (string) $before);

    // Derived on a part: the part's grant and constraint.
    $quote = $this->roster('block_type:testimonial::.testimonial__quote::after');
    $this->assertFalse($quote->declared());
    $this->assertSame('after', $quote->undeclared()['name']);
    $this->assertSame(1, $quote->undeclared()['position']);
    $this->assertSame(['typography', 'generated_content'], $quote->groups());
    $this->assertSame('1.75rem', $quote->valueSpace('font-size')['max']);
    $this->assertNull($quote->landingScope());

    // Derived on a type's root and on a base tag: the landing's grant, and
    // still no element scope to pair at.
    $type = $this->roster('kind:container::> pb-testimonial::before');
    $this->assertSame(['motion', 'effects', 'shadow', 'spacing', 'typography', 'generated_content'], $type->groups());
    $this->assertNull($type->landingScope());
    $this->assertSame([], $type->variants());
    $tag = $this->roster('kind:block::p::after');
    $this->assertSame(['typography', 'spacing', 'background', 'border', 'generated_content'], $tag->groups());
    $this->assertNull($tag->landingScope());

    // An undeclared host ends the walk before the box: the compound is
    // named, the box grants nothing.
    $nope = $this->roster('kind:block::.nope::before');
    $this->assertFalse($nope->declared());
    $this->assertSame(RelativeSelector::CLASS_PART, $nope->undeclared()['kind']);
    $this->assertSame('.nope', $nope->undeclared()['name']);
    $this->assertSame([], $nope->granted());
    $this->assertSame(RelativeSelector::TAG, $this->roster('kind:block::em::before')->undeclared()['kind']);

    // A real box never grants the existence switch.
    $this->assertFalse($this->roster('block_type:testimonial')->grantsProperty('content'));
    $this->assertFalse($this->roster('block_type:testimonial::.testimonial__quote')->grantsProperty('content'));
    $this->assertFalse($this->roster('kind:container::> *')->grantsProperty('content'));

    // Declared: the host's pseudo section is the box's grant, the
    // existence switch appended; the other box stays derived; a layout
    // over the kind carries the section like any map.
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'pseudo' => [
          'before' => [
            'property_groups' => [
              'background' => ['constraints' => ['background-color' => ['allowed' => ['gold']]]],
              'sizing' => ['properties' => ['width', 'height']],
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $declared = $this->roster('kind:container::&::before');
    $this->assertTrue($declared->declared());
    $this->assertNull($declared->undeclared());
    $this->assertSame(['background', 'sizing', 'generated_content'], $declared->groups());
    $this->assertSame(['width', 'height'], $declared->grants()['sizing'], 'An allowlist narrows a box\'s grant like any node\'s.');
    $this->assertSame(['allowed' => ['gold']], $declared->valueSpace('background-color'));
    $this->assertFalse($declared->grantsProperty('padding'), 'A declared box grants its section, not its host.');
    $this->assertTrue($declared->grantsProperty('content'));
    $this->assertNull($declared->landingScope());
    $after = $this->roster('kind:container::&::after');
    $this->assertFalse($after->declared());
    $this->assertSame(['spacing', 'background', 'generated_content'], $after->groups(), 'The undeclared box derives the host.');
    $this->assertTrue($this->roster('layout:layout_onecol::&::before')->declared());
    $this->assertSame(['background', 'sizing', 'generated_content'], $this->roster('layout:layout_onecol::&::before')->groups());
    $this->assertSame(['spacing', 'background'], $this->roster('kind:container')->groups(), 'The host itself is untouched.');
  }

  /**
   * Value spaces resolve node first, then the site's authored defaults.
   */
  public function testValueSpaces(): void {
    // The node's own block, layered: the extra module's max over the
    // fixture's, the sub theme's units; the padding family expanded
    // over the sides that author none (testNodeFamilyExpansion()).
    $container = $this->roster('kind:container');
    $this->assertSame(['max' => '6rem', 'units' => ['rem']], $container->valueSpace('padding'));
    $this->assertSame(['max' => '3rem'], $container->valueSpace('padding-left'));
    $this->assertSame(['max' => '6rem', 'units' => ['rem']], $container->valueSpace('padding-top'), 'A side authoring no block carries the shorthand\'s.');
    $this->assertSame([], $container->valueSpace('margin'), 'An unconstrained granted property has no block.');
    $this->assertSame([], $container->valueSpace('font-size'), 'An ungranted property has no value space.');
    // The suite's gap binding reaches the container through its layout
    // grant, the family expanded like the node's padding.
    $this->assertSame(['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'gap', 'row-gap', 'column-gap'], array_keys($container->valueSpaces()));

    // The site's authored constraint default binds where the node is
    // silent (border-radius on the paragraph's border group) and only
    // where the property is granted (the container grants no border).
    // The fixture's block is a delta over the suite's (site blocks merge
    // across layers): its scale replaces the suite's radius, the suite's
    // zero literal rides along.
    $this->assertSame(['scale' => 'space', 'allowed' => ['0']], $this->roster('base:p')->valueSpace('border-radius'));
    $this->assertSame([], $container->valueSpace('border-radius'));

    // A scale constraint's closed list is the scale's minted tokens plus
    // the allowed entries: empty before a skin mints, the var()
    // spellings after.
    $block = $this->roster('kind:block');
    $this->assertSame(['scale' => 'space'], $block->valueSpace('padding'));
    $this->assertSame([], $block->scaleValues('padding'));
    $this->assertSame([], $block->scaleValues('margin'), 'Not scale-constrained: no closed list.');

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem', 'gutter' => '1.5rem']])->save();
    $this->activateSkin($skin);
    $this->assertSame(['var(--space-4)', 'var(--space-gutter)'], $this->roster('kind:block')->scaleValues('padding'));
  }

  /**
   * A node's block on a shorthand reaches the family's longhands.
   *
   * The scale fixture's block kind closes padding over the space scale
   * with one block; the roster serves that block on padding-top,
   * padding-right, padding-bottom and padding-left too, with the same
   * closed list once a skin mints (the guide expands node constraints
   * exactly as it expands the site's, StyleGuide::expandConstraintFamilies()).
   * The node-over-site resolution then holds per longhand: the
   * container's own padding-left cap wins over its padding's expansion,
   * and the site's border-radius binding fills a paragraph's corners
   * where the node authors nothing.
   */
  public function testNodeFamilyExpansion(): void {
    $block = $this->roster('kind:block');
    foreach (['padding-top', 'padding-right', 'padding-bottom', 'padding-left'] as $side) {
      $this->assertSame(['scale' => 'space'], $block->valueSpace($side), "the block kind's padding block is $side's value space");
      $this->assertSame([], $block->scaleValues($side), "no skin, so $side's closed list is honestly empty");
    }
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem', 'gutter' => '1.5rem']])->save();
    $this->activateSkin($skin);
    $this->assertSame(['var(--space-4)', 'var(--space-gutter)'], $this->roster('kind:block')->scaleValues('padding-left'), 'the minted tokens are padding-left\'s closed list through the expanded block');

    $container = $this->roster('kind:container');
    $this->assertSame(['max' => '3rem'], $container->valueSpace('padding-left'), 'a longhand\'s own block wins over its shorthand\'s expansion');
    $this->assertSame(['max' => '6rem', 'units' => ['rem']], $container->valueSpace('padding-bottom'), 'a side authoring nothing carries the node\'s padding block');

    $paragraph = $this->roster('base:p');
    $corners = [
      'border-top-left-radius',
      'border-top-right-radius',
      'border-bottom-right-radius',
      'border-bottom-left-radius',
    ];
    foreach ($corners as $corner) {
      $this->assertSame(['scale' => 'space', 'allowed' => ['0']], $paragraph->valueSpace($corner), "the site's border-radius binding is $corner's value space where the node is silent");
    }
  }

  /**
   * The row a scale-bound property offers: curated stops, else offered tokens.
   *
   * The offer half of scaleValues(): scaleStops() is the server twin of
   * the client's stopsFor (style-guide.js scaleRow): the element's
   * curated stops in list order where its block declares them, else the
   * scale's tokens the skin marks offered, in stored order (a shipped
   * token opted out of the rows stays legal and referenceable); on a
   * curated row the site-minted tokens (origin user: the skin's site
   * layer minted them, the package ships none by that name) follow the
   * curated ones while offered on open and guarded systems and are
   * withheld on strict; the allowed literals append after the tokens.
   * Site-wide stops left the guide (a build error there), so an
   * uncurated row on every posture is the offered flag alone.
   */
  public function testScaleStops(): void {
    $this->enableModules(['daedalus_stylish_control_test']);
    $this->container->get('state')->set('daedalus_stylish_control_test.site_layer', [
      'token_contract' => ['space' => ['8', '4']],
    ]);
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'block' => [
        'property_groups' => [
          'spacing' => ['constraints' => ['padding-left' => ['scale' => 'space', 'stops' => ['8', '4']]]],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->builder = $this->container->get('daedalus_stylish.vocabulary_roster');

    // No skin: the rows are honestly empty but for the literal.
    $this->assertSame([], $this->roster('kind:block')->scaleStops('padding'));
    $this->assertSame([], $this->roster('kind:block')->scaleStops('padding-left'));
    $this->assertSame(['0'], $this->roster('kind:container')->scaleStops('gap'), 'the suite\'s gap binding keeps its zero literal');
    $this->assertSame([], $this->roster('kind:block')->scaleStops('margin'), 'not scale-bound: no row');

    // A shipped skin: two package steps offered, the gutter opted out of
    // the rows, and a site layer minting one offered token and one not.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens([
      'space' => ['4' => '1rem', 'gutter' => ['value' => '1.5rem', 'offered' => FALSE], '8' => '2rem'],
    ]);
    $skin->setSiteTokens([
      'space' => ['hero' => ['value' => '6rem'], 'quiet' => ['value' => '7rem', 'offered' => FALSE]],
    ]);
    $skin->setShippedSource('daedalus_stylish_guide_test', [
      'tokens' => $skin->packageTokens(),
      'defaults' => $skin->packageDefaults(),
    ]);
    $skin->save();
    $this->activateSkin($skin);
    $records = $this->roster('kind:block')->materialsRoster()->tokens();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $records['space/4']->origin, 'a package token is shipped');
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $records['space/gutter']->origin);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $records['space/hero']->origin, 'a site-minted token is the site\'s');
    $this->assertFalse($records['space/gutter']->offered);
    $this->assertFalse($records['space/quiet']->offered);

    $offered = ['var(--space-4)', 'var(--space-8)', 'var(--space-hero)'];
    $curated = ['var(--space-8)', 'var(--space-4)'];
    // Guarded (the fixture's mode): the uncurated row is the offered
    // tokens in stored order, the curated row its list with the
    // site-minted offered token after it.
    $block = $this->roster('kind:block');
    $this->assertSame($offered, $block->scaleStops('padding'), 'uncurated: the offered flag alone, stored order');
    $this->assertSame([...$curated, 'var(--space-hero)'], $block->scaleStops('padding-left'), 'curated: the list, then the site-minted offered token');
    $this->assertSame([...$offered, '0'], $this->roster('kind:container')->scaleStops('gap'), 'the allowed literal appends after the tokens');
    $this->assertSame(
      ['var(--space-4)', 'var(--space-gutter)', 'var(--space-8)', 'var(--space-hero)', 'var(--space-quiet)'],
      $block->scaleValues('padding'),
      'legality is the whole scale, offered or not',
    );

    $this->enforce('strict');
    $block = $this->roster('kind:block');
    $this->assertSame($offered, $block->scaleStops('padding'), 'strict, uncurated: the offered flag still decides');
    $this->assertSame($curated, $block->scaleStops('padding-left'), 'strict, curated: site-minted tokens are withheld from the row');

    $this->enforce('open');
    $block = $this->roster('kind:block');
    $this->assertSame($offered, $block->scaleStops('padding'));
    $this->assertSame([...$curated, 'var(--space-hero)'], $block->scaleStops('padding-left'), 'open, curated: the site-minted offered token follows');

    // A curated row none of whose names are minted falls back to the
    // offered tokens; a curated name the skin does not mint drops.
    $this->container->get('state')->set('daedalus_stylish_control_test.site_layer', [
      'token_contract' => ['space' => ['8', '4', 'xl']],
    ]);
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'block' => [
        'property_groups' => [
          'spacing' => [
            'constraints' => [
              'padding-left' => ['scale' => 'space', 'stops' => ['xl']],
              'padding-top' => ['scale' => 'space', 'stops' => ['xl', '4']],
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->builder->reset();
    $block = $this->roster('kind:block');
    $this->assertSame($offered, $block->scaleStops('padding-left'), 'no curated name minted: the offered tokens stand in');
    $this->assertSame(['var(--space-4)', 'var(--space-hero)'], $block->scaleStops('padding-top'), 'the unminted name drops, the minted one leads');
  }

  /**
   * Axes carry labels, stamps, descriptions and owns; conditions ride whole.
   */
  public function testAxesAndConditions(): void {
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'variant_axes' => [
          [
            'width' => [
              'depth' => 'root',
              'label' => 'Width',
              'description' => 'How wide a band lets its content run.',
              'owns' => ['padding-left', 'padding-right'],
            ],
          ],
          'flow',
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    $container = $this->roster('kind:container');
    $this->assertSame([
      'style' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
      'width' => [
        'label' => 'Width',
        'root_only' => TRUE,
        'description' => 'How wide a band lets its content run.',
        'owns' => ['padding-left', 'padding-right'],
      ],
      'flow' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
    ], $container->axes());
    $this->assertSame(['style', 'width', 'flow'], $container->axisNames());
    $this->assertSame(['width'], $container->rootOnlyAxes());
    $this->assertSame(['width' => 'How wide a band lets its content run.'], $container->axisDescriptions());
    $this->assertSame(['width' => ['padding-left', 'padding-right']], $container->axisOwns());
    $this->assertSame('width', $container->axisOwning('padding-right'));
    $this->assertNull($container->axisOwning('padding-top'));

    // A layout's own list replaces the kind's wholesale, facts included.
    $onecol = $this->roster('layout:layout_onecol');
    $this->assertSame(['style', 'width', 'flow'], $onecol->axisNames());
    $this->assertSame(['width'], $onecol->rootOnlyAxes());
    $this->assertNull($onecol->axes()['width']['label']);
    $this->assertSame([], $onecol->axisDescriptions());
    $this->assertSame([], $onecol->axisOwns());

    // The block kind's shipped axes; a block type's own list replaces
    // them wholesale (its declared axis beside the built-in one), no
    // stamps on either.
    $this->assertSame(['style', 'animation', 'hover'], $this->roster('kind:block')->axisNames());
    $testimonial = $this->roster('block_type:testimonial');
    $this->assertSame(['style', 'emphasis'], $testimonial->axisNames());
    $this->assertSame([], $testimonial->rootOnlyAxes());

    // Conditions are the condition type plugins' roster, definitions
    // whole, the same on every address.
    $conditions = $container->conditions();
    $this->assertSame($this->container->get('plugin.manager.daedalus_stylish_condition_type')->getConditions(), $conditions);
    $this->assertSame(':hover', $conditions['hover']['wrapper']);
    $this->assertSame($conditions, $testimonial->conditions());
  }

  /**
   * The applicable materials are read from the materials roster.
   */
  public function testMaterials(): void {
    $this->assertSame([], $this->roster('kind:container')->materials(), 'No materials, nothing applies.');

    foreach ([
      ['band', 'kind:container'],
      ['columns', 'layout:layout_twocol_section'],
      ['quiet', 'block_type:testimonial'],
      ['link', 'base:a'],
    ] as [$name, $scope]) {
      DaedalusStylishVariant::create(['label' => $name, 'machine_name' => $name, 'scope' => $scope])->save();
    }
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);

    // The kind pairs with every container variant; the tag treatment
    // pairs with everything; tokens apply everywhere.
    $container = $this->roster('kind:container');
    $this->assertSame(['band', 'columns', 'link'], array_keys($container->variants()));
    $ids = array_map(static fn ($record): string => $record->kind->value . ':' . $record->id, $container->materials());
    $this->assertContains('token:space/4', $ids);
    $this->assertNotContains('variant:quiet', $ids);

    // A layout pairs with its own and the kind's, never a sibling's.
    $this->assertSame(['band', 'columns', 'link'], array_keys($this->roster('layout:layout_twocol_section')->variants()));
    $this->assertSame(['band', 'link'], array_keys($this->roster('layout:layout_onecol')->variants()));
    // The block kind's rosters carry the suite's shipped block variants
    // too (Fade in, Lift, Rise, Scale in: the activation above synced every
    // enabled module), interleaved by label with the fixture's own.
    $block = ['block_fade_in', 'block_lift', 'link', 'quiet', 'block_rise', 'block_scale_in'];
    $this->assertSame($block, array_keys($this->roster('block_type:testimonial')->variants()), 'Roster order: by label.');
    // The kind is the family wildcard on either side: the block kind
    // wears the block type's variants too.
    $this->assertSame($block, array_keys($this->roster('kind:block')->variants()));
    // Variants pair by the LANDING: a part is no element scope and wears
    // nothing (its element wears); a rule wears at its matches, so a type
    // compound lands on that type's roster and a tag compound on the base
    // tag's (the tag treatment alone).
    $this->assertNull($this->roster('block_type:testimonial::.testimonial__quote')->landingScope());
    $this->assertSame([], array_keys($this->roster('block_type:testimonial::.testimonial__quote')->variants()));
    $this->assertSame([], array_keys($this->roster('kind:container::> *')->variants()));
    $this->assertSame('block_type:testimonial', (string) $this->roster('kind:container::> pb-testimonial')->landingScope());
    $this->assertSame($block, array_keys($this->roster('kind:container::> pb-testimonial')->variants()));
    $this->assertSame('base:a', (string) $this->roster('kind:container::a')->landingScope());
    $this->assertSame(['link'], array_keys($this->roster('kind:container::a')->variants()));
    // A type rule's matches may be any layout: the kind landing pairs
    // with every container variant, the owner's own layout notwithstanding.
    $this->assertSame('kind:container', (string) $this->roster('layout:layout_onecol::> pb-container')->landingScope());
    $this->assertSame(['band', 'columns', 'link'], array_keys($this->roster('layout:layout_onecol::> pb-container')->variants()));

    // The read is the materials builder's live memo: a mint after the
    // roster was built is visible without a rebuild.
    DaedalusStylishVariant::create(['label' => 'late', 'machine_name' => 'late', 'scope' => 'kind:container'])->save();
    $this->assertSame(['band', 'columns', 'late', 'link'], array_keys($container->variants()));
  }

  /**
   * Enforcement rides the roster, and the memo follows the mode.
   */
  public function testEnforcementAndMemo(): void {
    $guarded = $this->roster('kind:container');
    $this->assertSame(StyleGuide::ENFORCEMENT_GUARDED, $guarded->enforcement());
    $this->assertFalse($guarded->propertiesClosed());
    $this->assertTrue($guarded->constraintsAreLaw());
    $this->assertSame($guarded, $this->roster('kind:container'), 'Memoized per address and theme.');
    $this->assertNotSame($guarded, $this->roster('kind:block'));

    $this->enforce('strict');
    $strict = $this->roster('kind:container');
    $this->assertNotSame($guarded, $strict, 'The memo keys on the mode.');
    $this->assertTrue($strict->propertiesClosed());
    $this->assertSame($guarded->granted(), $strict->granted(), 'The grant is the same in every mode; only the posture differs.');

    $this->enforce('open');
    $open = $this->roster('kind:container');
    $this->assertFalse($open->propertiesClosed());
    $this->assertFalse($open->constraintsAreLaw());

    $this->builder->reset();
    $this->assertNotSame($open, $this->roster('kind:container'));

    // The site default theme answers when no theme is given.
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->assertSame(self::THEME, $this->builder->build(Address::kind('block'))->theme());
  }

  /**
   * What a cached product of the roster depends on.
   */
  public function testCacheMetadata(): void {
    $tags = $this->roster('kind:container')->getCacheableMetadata()->getCacheTags();
    $this->assertContains(PageBuilderDeclarations::CACHE_TAG, $tags, 'The declarations.');
    $this->assertContains('config:' . ActiveDesignSystem::CONFIG_NAME, $tags, 'The design-system pointer.');
    $this->assertSame(['config:' . ActiveDesignSystem::CONFIG_NAME], $this->container->get('daedalus_stylish.style_guide')->enforcementCacheTags(), "Enforcement's source with no system active is the pointer alone.");
    // Enforcement is the active system's property: its entity tag rides
    // the roster once one is active, so an enforcement edit invalidates.
    $this->enforce('strict');
    $this->assertContains('config:daedalus_blueprint.design_system.site', $this->roster('kind:container')->getCacheableMetadata()->getCacheTags(), "Enforcement's source: the active system.");
    // The materials roster's own metadata is merged in.
    $materials = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->getCacheableMetadata();
    foreach ($materials->getCacheTags() as $tag) {
      $this->assertContains($tag, $tags);
    }
  }

  /**
   * Addresses outside the site's vocabulary are refused loudly.
   */
  public function testOutsideTheVocabulary(): void {
    try {
      $this->roster('base:div');
      $this->fail('A base tag outside BASE_TAGS is not a place.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('outside the base tag vocabulary', $exception->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->roster('kind:paragraph');
  }

  /**
   * The correctives: the nearest granted names and the one hint.
   *
   * Two tiers: the property's family first in roster order, then the
   * edit-distance and containment neighbors, closest first; nothing for
   * a stranger. On the object only: the validator reads them from the
   * roster of the address it validates at.
   */
  public function testCorrectives(): void {
    $roster = new VocabularyRoster(Address::kind('container'), 'spec', [
      'spacing' => [
        'padding',
        'padding-top',
        'padding-bottom',
        'padding-left',
        'padding-right',
        'padding-inline',
        'margin',
        'margin-top',
        'margin-bottom',
        'gap',
      ],
      'color' => ['background-color', 'color'],
    ]);
    $this->assertSame(['margin', 'margin-top', 'margin-bottom'], $roster->candidates('margin-inline'));
    $this->assertSame(['color'], $roster->candidates('font-color'));
    $this->assertSame([], $roster->candidates('zzzzqx'));
    $this->assertSame(['margin', 'margin-top'], $roster->candidates('margin-inline', 2));

    $this->assertSame('Did you mean margin, margin-top or margin-bottom?', $roster->didYouMean('margin-inline'));
    $this->assertSame('Did you mean color?', $roster->didYouMean('font-color'));
    $this->assertSame('', $roster->didYouMean('zzzzqx'), "The materials roster's shape: '' when nothing is close.");

    $this->assertSame('Did you mean margin, margin-top or margin-bottom? ' . VocabularyRoster::ROSTER_HOME, $roster->corrective('margin-inline'));
    $this->assertSame('Nothing granted here is close to "zzzzqx". ' . VocabularyRoster::ROSTER_HOME, $roster->corrective('zzzzqx'));

    // The live roster corrects against its own grant: font-size on the
    // guide-test container (spacing and background) is a stranger; a
    // margin miss names the family.
    $container = $this->roster('kind:container');
    $this->assertStringStartsWith('Nothing granted here is close to "font-size".', $container->corrective('font-size'));
    $this->assertSame(['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'], $container->candidates('margin-inline'));
  }

  /**
   * The roster and its client twin answer the same battery identically.
   *
   * ClientStyleGuide serves every node as the roster's answers at its
   * address, and style-guide.js (with variant-scope.js for the axes)
   * reads them back for an element key plus its bundle: the client's
   * address derivation (declarationForKey) over the served nodes must
   * answer exactly what the builder answers for the address, for kinds,
   * refinements, degraded refinements, parts at any depth, the children
   * pseudo-part, the tag tier, an instance namespace, an undeclared
   * place and the generated boxes (a declared one served as a node, the
   * derived ones the client builds from the host node); a narrowed
   * grant, node and site value spaces, root-only axes, axis descriptions
   * and owns, the declared() verdict and the enforcement predicates
   * included. PHP is authoritative;
   * tests/js/vocabulary-cases.mjs is the client side of the pipe.
   */
  public function testClientTwinEquivalence(): void {
    // A narrowed grant, a root-only axis and a declared ::before box on
    // the container kind, and a site instance namespace, so the battery
    // exercises every lane the payload serves.
    $this->container->get('state')->set('daedalus_stylish_guide_test_extra.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => [
            'properties' => ['padding-top', 'padding-bottom', 'row-gap', 'column-gap'],
            // A curated row beside the uncurated ones, so the battery
            // locks the row resolution (scaleStops) in both shapes.
            'constraints' => ['padding-top' => ['scale' => 'space', 'stops' => ['4']]],
          ],
        ],
        'pseudo' => [
          'before' => [
            'property_groups' => [
              'background' => ['constraints' => ['background-color' => ['allowed' => ['gold']]]],
              'sizing' => ['properties' => ['width', 'height']],
            ],
          ],
        ],
        'variant_axes' => [
          [
            'width' => [
              'depth' => 'root',
              'label' => 'Width',
              'description' => 'The band column.',
              'owns' => ['padding-top', 'padding-bottom'],
            ],
          ],
          'flow',
        ],
      ],
    ]);
    $this->enableModules(['daedalus_stylish_control_test']);
    $this->container->get('state')->set('daedalus_stylish_control_test.instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'property_groups' => ['typography' => ['properties' => ['font-size', 'color']]],
      ],
    ]);
    // The curated scale's contract, and a shipped skin whose rows the
    // battery resolves: two package steps offered, the gutter opted out
    // of the rows, and a site-minted token (withheld from the curated
    // row under strict, on the uncurated rows by its own flag).
    $this->container->get('state')->set('daedalus_stylish_control_test.site_layer', ['token_contract' => ['space' => ['4']]]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->builder = $this->container->get('daedalus_stylish.vocabulary_roster');
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens([
      'space' => ['4' => '1rem', 'gutter' => ['value' => '1.5rem', 'offered' => FALSE], '8' => '2rem'],
    ]);
    $skin->setSiteTokens(['space' => ['hero' => ['value' => '6rem']]]);
    $skin->setShippedSource('daedalus_stylish_guide_test', [
      'tokens' => $skin->packageTokens(),
      'defaults' => $skin->packageDefaults(),
    ]);
    $skin->save();
    $this->activateSkin($skin);
    $this->enforce('strict');

    $uuid = '11111111-1111-1111-1111-111111111111';
    $properties = [
      'padding',
      'padding-top',
      'padding-left',
      'gap',
      'font-size',
      'color',
      'max-width',
      'background-color',
      'border-radius',
      'display',
      'text-wrap',
      'content',
      'width',
    ];
    $battery = [
      ['kind:container', "container:$uuid", NULL],
      ['kind:container::> *', "container:$uuid::> *", NULL],
      ['kind:block', "block:$uuid", NULL],
      ['kind:block::> *', "block:$uuid::> *", NULL],
      ['kind:field', "field:$uuid:body", NULL],
      ['kind:entity', "entity:$uuid", NULL],
      ['block_type:testimonial', "block:$uuid", 'testimonial'],
      ['block_type:testimonial::.testimonial__quote', "block:$uuid::.testimonial__quote", 'testimonial'],
      ['block_type:undeclared', "block:$uuid", 'undeclared'],
      ['block_plugin:fancy_menu', "block:$uuid", 'plugin:fancy_menu'],
      ['block_plugin:undeclared', "block:$uuid", 'plugin:undeclared'],
      ['layout:layout_twocol_section', "container:$uuid", 'layout_twocol_section'],
      ['layout:layout_twocol_section::.band-content', "container:$uuid::.band-content", 'layout_twocol_section'],
      ['layout:layout_twocol_section::.band-content > *', "container:$uuid::.band-content > *", 'layout_twocol_section'],
      ['layout:layout_onecol', "container:$uuid", 'layout_onecol'],
      ['kind:block::.nope', "block:$uuid::.nope", NULL],
      ['kind:block::.s-a3', "block:$uuid::.s-a3", NULL],
      ['block_type:testimonial::.s-a3', "block:$uuid::.s-a3", 'testimonial'],
      ['kind:field::.s-a3', "field:$uuid:body::.s-a3", NULL],
      ['kind:block::p', "block:$uuid::p", NULL],
      ['kind:field::a', "field:$uuid:body::a", NULL],
      ['kind:block::em', "block:$uuid::em", NULL],
      // The generated boxes: the container's declared ::before (served),
      // its derived ::after, and derived boxes on a refinement's root, a
      // part at depth, the children, a type, a tag, an instance part, a
      // degraded refinement; an undeclared host ends the walk before
      // either box.
      ['kind:container::&::before', "container:$uuid::&::before", NULL],
      ['kind:container::&::after', "container:$uuid::&::after", NULL],
      ['layout:layout_twocol_section::&::before', "container:$uuid::&::before", 'layout_twocol_section'],
      ['block_type:testimonial::&::before', "block:$uuid::&::before", 'testimonial'],
      ['block_type:testimonial::.testimonial__quote::after', "block:$uuid::.testimonial__quote::after", 'testimonial'],
      ['layout:layout_twocol_section::.band-content > *::before', "container:$uuid::.band-content > *::before", 'layout_twocol_section'],
      ['kind:container::> pb-testimonial::before', "container:$uuid::> pb-testimonial::before", NULL],
      ['kind:block::p::after', "block:$uuid::p::after", NULL],
      ['kind:block::.s-a3::before', "block:$uuid::.s-a3::before", NULL],
      ['block_type:undeclared::&::after', "block:$uuid::&::after", 'undeclared'],
      ['kind:block::.nope::before', "block:$uuid::.nope::before", NULL],
      ['kind:block::em::after', "block:$uuid::em::after", NULL],
    ];

    $php = [];
    foreach ($battery as [$address]) {
      $roster = $this->roster($address);
      $spaces = [];
      $stops = [];
      foreach ($properties as $property) {
        $spaces[$property] = $roster->valueSpace($property) ?: NULL;
        $stops[$property] = isset($spaces[$property]['scale']) ? $roster->scaleStops($property) : NULL;
      }
      $php[] = [
        'declared' => $roster->declared(),
        'groups' => $roster->groups(),
        'granted' => array_values(array_filter($properties, $roster->grantsProperty(...))),
        'spaces' => $spaces,
        'stops' => $stops,
        'axes' => $roster->axisNames(),
        'rootOnly' => $roster->rootOnlyAxes(),
        'descriptions' => $roster->axisDescriptions(),
        'owns' => $roster->axisOwns(),
      ];
    }

    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $input = json_encode([
      'guide' => $payload,
      'materials' => array_values(array_map(static fn (MaterialRecord $record): array => $record->toArray(), $this->roster('kind:block')->materialsRoster()->tokens())),
      'cases' => array_map(static fn (array $case): array => [
        'key' => $case[1],
        'bundle' => $case[2],
        'properties' => $properties,
      ], $battery),
    ], JSON_THROW_ON_ERROR);
    $js = json_decode($this->runNodeScript('tests/js/vocabulary-cases.mjs', $input), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertIsArray($js['cases'] ?? NULL);
    $this->assertSameSize($php, $js['cases']);
    foreach ($php as $index => $expected) {
      $this->assertSame($expected, $js['cases'][$index], sprintf('Case %d (%s as %s) diverged between the roster and the client twin.', $index, $battery[$index][0], $battery[$index][1]));
    }
    $this->assertSame($this->roster('kind:block')->propertiesClosed(), $js['closed']);
    $this->assertSame($this->roster('kind:block')->constraintsAreLaw(), $js['law']);

    // Guard against a silently inert battery: the narrowed grant (gap
    // derived from its two longhands, padding withheld), a node value
    // space, a site value space, an instance grant, a root-only axis and
    // an undeclared place all answered.
    $this->assertSame(['padding-top', 'gap', 'background-color'], $php[0]['granted']);
    // The rows answered on both sides: the curated padding-top exact
    // under strict (the site-minted token withheld), the uncurated
    // block padding and the site's gap the offered tokens in stored
    // order (the gutter opted out, the site-minted token on by its
    // flag) with gap's zero literal after them, and no row where no
    // scale binds.
    $this->assertSame(['var(--space-4)'], $php[0]['stops']['padding-top']);
    $this->assertSame(['var(--space-4)', 'var(--space-8)', 'var(--space-hero)', '0'], $php[0]['stops']['gap']);
    $this->assertSame(['var(--space-4)', 'var(--space-8)', 'var(--space-hero)'], $php[2]['stops']['padding']);
    $this->assertNull($php[2]['stops']['display']);
    $this->assertNull($php[2]['stops']['font-size'], 'ungranted: no value space, no row');
    $this->assertSame([], $php[16]['stops']['font-size'], 'a bound scale minting nothing is the honest empty row');
    $this->assertSame('1.75rem', $php[7]['spaces']['font-size']['max']);
    $this->assertSame(['scale' => 'space', 'allowed' => ['0']], $php[19]['spaces']['border-radius']);
    $this->assertSame(['font-size', 'color'], $php[16]['granted']);
    $this->assertSame(['width'], $php[0]['rootOnly']);
    $this->assertSame(['width' => 'The band column.'], $php[0]['descriptions']);
    $this->assertSame(['width' => ['padding-top', 'padding-bottom']], $php[0]['owns']);
    $this->assertSame([], $php[2]['owns']);
    $this->assertSame([], $php[15]['groups']);
    $this->assertFalse($php[15]['declared']);
    $this->assertTrue($php[8]['declared'], 'A degraded refinement is a declared place.');
    $this->assertTrue($js['closed']);
    // The boxes answered: the declared ::before serves its section
    // (allowlist, constraint, the existence switch appended) and is
    // declared; a derived box carries its host's grant plus the switch
    // and is not; the layout over the kind inherits the section; an
    // undeclared host grants nothing at the box.
    $this->assertTrue($php[22]['declared']);
    $this->assertSame(['background', 'sizing', 'generated_content'], $php[22]['groups']);
    $this->assertSame(['background-color', 'content', 'width'], $php[22]['granted']);
    $this->assertSame(['allowed' => ['gold']], $php[22]['spaces']['background-color']);
    $this->assertFalse($php[23]['declared']);
    $this->assertSame(['spacing', 'background', 'generated_content'], $php[23]['groups']);
    $this->assertSame(['padding-top', 'gap', 'background-color', 'content'], $php[23]['granted']);
    $this->assertTrue($php[24]['declared']);
    $this->assertSame($php[22]['groups'], $php[24]['groups']);
    $this->assertFalse($php[25]['declared']);
    $this->assertSame('2rem', $php[25]['spaces']['font-size']['max'], 'The host\'s constraints ride the derivation.');
    $this->assertContains('content', $php[25]['granted']);
    $this->assertNotContains('content', $php[7]['granted'], 'A real box never grants the switch.');
    $this->assertSame(['typography', 'generated_content'], $php[26]['groups']);
    $this->assertSame(['layout', 'generated_content'], $php[27]['groups']);
    $this->assertSame(['typography', 'generated_content'], $php[30]['groups']);
    $this->assertSame([...$php[2]['groups'], 'generated_content'], $php[31]['groups'], 'A degraded refinement\'s box derives the kind.');
    $this->assertFalse($php[32]['declared']);
    $this->assertSame([], $php[32]['groups']);
    $this->assertSame([], $php[33]['groups']);
  }

  /**
   * The entity's layout root: daedalus_layout's anatomy, daedalus_stylish's groups.
   *
   * The part is the box that arranges the root bands, so it gets the
   * vocabulary a list's box gets (.field__items' shape): spacing, for the
   * gap between bands, and layout. The anatomy (label, tag) is daedalus_layout's
   * and the grant daedalus_stylish's, merged over the one declaration file family,
   * so the same address the AI writes (entity:{uuid}::.daedalus-layout-layout)
   * grants gap. daedalus_layout is enabled inside the case so the class's other
   * rosters stay as the module list declares them.
   */
  public function testEntityLayoutRoot(): void {
    // daedalus_stylish's layer alone, no daedalus_layout: the grant is declared and the
    // label degrades to the selector; no markup ever carries the part.
    $this->assertSame(['.daedalus-layout-layout' => '.daedalus-layout-layout'], $this->roster('kind:entity')->parts());

    $this->enableModules(['block', 'daedalus_layout']);
    $this->container->get('daedalus_ui.page_builder_declarations')->resetCache();
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->builder = $this->container->get('daedalus_stylish.vocabulary_roster');

    $entity = $this->roster('kind:entity');
    $this->assertSame(['.daedalus-layout-layout' => 'Layout'], $entity->parts());
    $this->assertFalse($entity->childrenDeclared(), 'The sections are containers of their own, never a children pseudo-part of the entity.');

    $layout = $this->roster('kind:entity::.daedalus-layout-layout');
    $this->assertTrue($layout->declared());
    $this->assertSame(['spacing', 'layout'], $layout->groups());
    $this->assertTrue($layout->grantsProperty('gap'));
    $this->assertTrue($layout->grantsProperty('row-gap'));
    $this->assertTrue($layout->grantsProperty('display'));
    $this->assertFalse($layout->grantsProperty('font-size'), 'The page\'s look is the entity root\'s; the layout root is air between bands.');
    $this->assertFalse($layout->grantsProperty('background-color'));
    $this->assertSame([], $layout->parts());
    $this->assertSame($entity->axisNames(), $layout->axisNames());
  }

  /**
   * A hand-built roster refuses the shapes the builder never produces.
   */
  public function testConstruction(): void {
    $empty = new VocabularyRoster(Address::kind('block'), 'spec');
    $this->assertSame([], $empty->granted());
    $this->assertSame([], $empty->materials());
    $this->assertSame([], $empty->getCacheableMetadata()->getCacheTags());
    $this->assertTrue($empty->materialsRoster()->isEmpty(), 'No materials source is the empty roster.');

    try {
      new VocabularyRoster(Address::kind('block'), 'spec', enforcement: 'loose');
      $this->fail('An unknown enforcement mode is refused.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('not an enforcement mode', $exception->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    new VocabularyRoster(Address::kind('block'), 'spec', ['padding', 'margin']);
  }

}
