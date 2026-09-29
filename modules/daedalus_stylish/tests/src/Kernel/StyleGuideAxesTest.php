<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests variant_axes declarations: grammar validation and the vocabulary.
 *
 * Axes are the composition grammar: every scope has the built-in "style"
 * axis, a declaration's variant_axes list adds more, an element wears at
 * most one variant per axis and composes variants across axes. The guide
 * build validates the list loudly (like controls), and StyleGuide::axes()
 * is the one defaulting rule every consumer resolves the vocabulary
 * through, "style" always first.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideAxesTest extends KernelTestBase {

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

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
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
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
    // Every build() consumer builds the materials roster (the minted
    // corpus behind the stop warnings), which reads all three daedalus_stylish
    // material tables.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * Layers a declaration over the media block type and rebuilds the guide.
   */
  protected function layerOverMedia(array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', ['media' => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * Layers a declaration over the section kind and rebuilds the guide.
   *
   * The container kind is the container-family seat for state-injected
   * declarations: the depth qualifier is valid there (and on layouts) but
   * a category error anywhere else.
   */
  protected function layerOverSectionKind(array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', ['container' => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * A valid variant_axes list parses, and axes() prepends the built-in axis.
   */
  public function testAxesVocabulary(): void {
    $this->layerOverMedia(['variant_axes' => ['proportions', 'width']]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame(['proportions', 'width'], $media['variant_axes']);
    // The effective vocabulary: "style" first, then declaration order (the
    // order variant classes are emitted in).
    $this->assertSame(['style', 'proportions', 'width'], StyleGuide::axes($media));

    // A block type without its own variant_axes inherits the universal
    // block kind's list (daedalus_stylish ships the animation axis there); a bare
    // declaration speaks only the built-in axis.
    $this->assertSame(['style', 'animation', 'hover'], StyleGuide::axes($this->styleGuide->getBlockType('undeclared', self::THEME)));
    $this->assertSame(['style'], StyleGuide::axes([]));
  }

  /**
   * The scope resolver serves the same vocabulary, or NULL for non-scopes.
   */
  public function testScopeAxes(): void {
    $this->layerOverMedia(['variant_axes' => ['proportions']]);
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame(['style', 'proportions'], $validator->scopeAxes('block_type:media', self::THEME));
    $this->assertSame(['style'], $validator->scopeAxes('kind:container', self::THEME));
    // The block kind's shipped animation axis reaches undeclared block
    // types; a block type authoring its own list replaces it (above).
    $this->assertSame(['style', 'animation', 'hover'], $validator->scopeAxes('block_type:undeclared', self::THEME));
    $this->assertNull($validator->scopeAxes('media', self::THEME));
  }

  /**
   * A valid children section (the '::> *' pseudo-part) serves as authored.
   */
  public function testChildrenSectionGrammar(): void {
    $this->layerOverSectionKind(['children' => ['property_groups' => ['layout' => [], 'sizing' => []]]]);
    $declaration = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['layout' => [], 'sizing' => []], $declaration['children']['property_groups']);
  }

  /**
   * A children section must be a map.
   */
  public function testNonMapChildrenFails(): void {
    $this->layerOverSectionKind(['children' => 'layout']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/children declaration of kind "container" must be a map/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The children vocabulary is closed: styling-only, no anatomy keys.
   */
  public function testChildrenUnknownKeyFails(): void {
    $this->layerOverSectionKind(['children' => ['label' => 'Columns', 'property_groups' => ['layout' => []]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/children declaration of kind "container" has unknown key\(s\) \'label\'/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * A children section's property_groups must be a map, like any element's.
   */
  public function testChildrenListPropertyGroupsFails(): void {
    $this->layerOverSectionKind(['children' => ['property_groups' => ['layout']]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/children declaration of kind "container" must declare property_groups as a map/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The variant_axes must be a non-empty list.
   */
  public function testNonListAxesFails(): void {
    $this->layerOverMedia(['variant_axes' => 'proportions']);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/variant_axes declaration of block type "media" must be a non-empty list/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * An empty list is a mistake, not "no extra axes": omit the key instead.
   */
  public function testEmptyAxesFails(): void {
    $this->layerOverMedia(['variant_axes' => []]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/must be a non-empty list of axis machine names/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Axis names are machine names.
   */
  public function testMalformedAxisNameFails(): void {
    $this->layerOverMedia(['variant_axes' => ['Content Width']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/contains \'Content Width\', which is not an axis machine name/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * The built-in "style" axis cannot be redeclared.
   */
  public function testBuiltInAxisRejected(): void {
    $this->layerOverMedia(['variant_axes' => ['style', 'width']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/declares the built-in "style" axis/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Duplicate axes fail the build.
   */
  public function testDuplicateAxisFails(): void {
    $this->layerOverMedia(['variant_axes' => ['width', 'width']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/lists "width" more than once/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A depth-qualified entry normalizes in axes() and projects separately.
   *
   * {axis: {depth: root}} is vocabulary-neutral: axes() serves plain names
   * in declaration order no matter how an entry is authored, and
   * rootOnlyAxes() is the separate projection placement-aware consumers
   * gate offering and defaulting on.
   */
  public function testDepthQualifiedAxesNormalize(): void {
    $this->layerOverSectionKind(['variant_axes' => ['proportions', ['width' => ['depth' => StyleGuide::DEPTH_ROOT]]]]);
    $section = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['style', 'proportions', 'width'], StyleGuide::axes($section));
    $this->assertSame(['width'], StyleGuide::rootOnlyAxes($section));

    // Unqualified declarations and declaration-less scopes have none.
    $this->layerOverMedia(['variant_axes' => ['proportions', 'width']]);
    $this->assertSame([], StyleGuide::rootOnlyAxes($this->styleGuide->getBlockType('media', self::THEME)));
    $this->assertSame([], StyleGuide::rootOnlyAxes([]));
  }

  /**
   * The client payload serves the roster's axes with their depth stamps.
   *
   * Every served root node carries `axes` (axis => {label, root_only},
   * VocabularyRoster::axes(), the effective vocabulary "style" first), so
   * the client filter reads the resolved fact and never re-parses entry
   * grammar.
   */
  public function testClientPayloadCarriesRootOnlyAxes(): void {
    $this->layerOverSectionKind(['variant_axes' => ['proportions', ['width' => ['depth' => StyleGuide::DEPTH_ROOT]]]]);
    $this->layerOverMedia(['variant_axes' => ['proportions', 'width']]);
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);

    $section = $payload['kinds']['container'];
    $this->assertSame([
      'style' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
      'proportions' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
      'width' => ['label' => NULL, 'root_only' => TRUE, 'description' => NULL, 'owns' => []],
    ], $section['axes']);
    $this->assertArrayNotHasKey('variant_axes', $section);

    $media = $payload['blockTypes']['media'];
    $this->assertSame(['style', 'proportions', 'width'], array_keys($media['axes']));
    $this->assertSame([], array_keys(array_filter($media['axes'], static fn (array $axis): bool => $axis['root_only'])));
  }

  /**
   * A label-qualified axis serves its label; unlabeled axes serve none.
   *
   * {axis: {label: ...}} is vocabulary-neutral exactly like depth: axes()
   * serves plain names, axisLabels() projects the display strings, and the
   * client payload aggregates them into one flat axisLabels map. Valid on
   * any declaration family (unlike depth), because a display label has no
   * placement semantics.
   */
  public function testLabelQualifiedAxes(): void {
    $this->layerOverSectionKind([
      'variant_axes' => [
        ['container_layout' => ['label' => 'Layout']],
        ['width' => ['depth' => StyleGuide::DEPTH_ROOT]],
      ],
    ]);
    $this->layerOverMedia(['variant_axes' => [['emphasis' => ['label' => 'Emphasis level']]]]);

    $section = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['style', 'container_layout', 'width'], StyleGuide::axes($section));
    $this->assertSame(['container_layout' => 'Layout'], StyleGuide::axisLabels($section));

    // Valid on a block type, where the depth qualifier is a category error.
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame(['emphasis' => 'Emphasis level'], StyleGuide::axisLabels($media));

    // The payload aggregates every root roster's labels into one flat map
    // (an axis machine name means one thing site-wide; the block kind's
    // shipped Animation and Hover labels ride along) beside the per-node
    // axes.
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertEqualsCanonicalizing([
      'container_layout' => 'Layout',
      'animation' => 'Animation',
      'hover' => 'Hover',
      'emphasis' => 'Emphasis level',
    ], $payload['axisLabels']);
    $this->assertSame(['style', 'container_layout', 'width'], array_keys($payload['kinds']['container']['axes']));
    $this->assertSame('Layout', $payload['kinds']['container']['axes']['container_layout']['label']);
  }

  /**
   * A described axis owning granted properties validates and rides everywhere.
   *
   * The two qualifiers no consumer re-parses: the guide projects them
   * (axisDescriptions(), axisOwns()), the vocabulary roster carries them
   * per axis beside the label and the depth stamp, and the client payload
   * serves the roster's entry whole. Valid on any declaration family
   * (like label): a block type's axis may own what the type grants.
   */
  public function testDescribedAxisWithOwns(): void {
    $this->layerOverSectionKind([
      'property_groups' => ['spacing' => [], 'sizing' => []],
      'variant_axes' => [
        [
          'width' => [
            'depth' => StyleGuide::DEPTH_ROOT,
            'description' => 'How wide a band lets its content run.',
            'owns' => ['padding-left', 'padding-right', 'max-width'],
          ],
        ],
        ['flow' => ['label' => 'Flow', 'description' => 'How the children arrange.']],
        'tone',
      ],
    ]);
    $this->layerOverMedia([
      'property_groups' => ['spacing' => []],
      'variant_axes' => [['proportions' => ['owns' => ['padding-top']]]],
    ]);

    $section = $this->styleGuide->getKind('container', self::THEME);
    $this->assertSame(['style', 'width', 'flow', 'tone'], StyleGuide::axes($section));
    $this->assertSame(['width' => 'How wide a band lets its content run.', 'flow' => 'How the children arrange.'], StyleGuide::axisDescriptions($section));
    $this->assertSame(['width' => ['padding-left', 'padding-right', 'max-width']], StyleGuide::axisOwns($section));
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame([], StyleGuide::axisDescriptions($media));
    $this->assertSame(['proportions' => ['padding-top']], StyleGuide::axisOwns($media));
    $this->assertSame([], StyleGuide::axisOwns([]));

    // The roster carries the facts per axis, the payload serves them.
    $roster = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::kind('container'), self::THEME);
    $this->assertSame([
      'style' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
      'width' => [
        'label' => NULL,
        'root_only' => TRUE,
        'description' => 'How wide a band lets its content run.',
        'owns' => ['padding-left', 'padding-right', 'max-width'],
      ],
      'flow' => ['label' => 'Flow', 'root_only' => FALSE, 'description' => 'How the children arrange.', 'owns' => []],
      'tone' => ['label' => NULL, 'root_only' => FALSE, 'description' => NULL, 'owns' => []],
    ], $roster->axes());
    $this->assertSame('width', $roster->axisOwning('max-width'));
    $this->assertNull($roster->axisOwning('padding-top'));
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame($roster->axes(), $payload['kinds']['container']['axes']);
    $this->assertSame(['padding-top'], $payload['blockTypes']['media']['axes']['proportions']['owns']);
  }

  /**
   * The shipped container kind's Layout axis owns the direction it flips.
   *
   * daedalus_stylish_layouts declares every container a flex column and
   * its row variant flips flex-direction, so the axis owns that one
   * property (the width axis's precedent: it owns the inset its stops
   * write). A direct write of flex-direction on a container root then
   * draws the wear-the-axis warning, doctrine rather than legality, and
   * the roster answers axisOwning() for the validator and the client
   * alike. The display itself stays the declared tier's: the axis owns
   * the direction, never the mechanic.
   */
  public function testShippedContainerLayoutAxisOwnsDirection(): void {
    $this->enableModules(['daedalus_stylish_layouts']);
    $this->styleGuide->resetCache();
    $roster = $this->container->get('daedalus_stylish.vocabulary_roster')->build(Address::kind('container'), self::THEME);
    $this->assertSame([
      'width' => ['padding-left', 'padding-right', 'max-width'],
      'container_layout' => ['flex-direction'],
    ], $roster->axisOwns());
    $this->assertSame('container_layout', $roster->axisOwning('flex-direction'));
    $this->assertNull($roster->axisOwning('display'), 'The display is the declared tier\'s, never the axis\'s.');
    $this->assertSame(['flex-direction'], $roster->axes()['container_layout']['owns']);
    $this->assertFalse($roster->axes()['container_layout']['root_only'], 'Columns is offered at every depth, so the direction is owned wherever the container sits.');
  }

  /**
   * An axis may only own a property its scope grants.
   *
   * The grant is the EFFECTIVE one (the kind layered under a refinement),
   * so a block type's axis may own what the block kind grants; a name
   * outside it fails the build naming the declaration and the property.
   */
  public function testOwnsUngrantedFails(): void {
    $this->layerOverSectionKind([
      'property_groups' => ['spacing' => []],
      'variant_axes' => [['width' => ['owns' => ['padding-left', 'max-width']]]],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/The variant_axes declaration of kind "container" says axis "width" owns "max-width", which the kind does not grant; an axis can only own a property its element may style/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Owns is a non-empty list of unique property names.
   */
  public function testOwnsGrammarFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => ['owns' => 'padding-left']]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/owns must be a non-empty list of property names/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * A duplicated owned property fails the build.
   */
  public function testOwnsDuplicateFails(): void {
    $this->layerOverSectionKind([
      'property_groups' => ['spacing' => []],
      'variant_axes' => [['width' => ['owns' => ['padding-left', 'padding-left']]]],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/lists a property more than once in the owns of axis "width"/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * An empty axis description fails the build.
   */
  public function testEmptyAxisDescriptionFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => ['description' => ' ']]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/an axis description must be a non-empty string/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * An empty axis label fails the build.
   */
  public function testEmptyAxisLabelFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['container_layout' => ['label' => '']]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/an axis label must be a non-empty string/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * The qualifier is a category error outside the section family.
   *
   * Depth is a placement fact of sections nesting in sections; a qualified
   * axis on a block type would silently mean nothing, so it fails loudly.
   */
  public function testQualifierOnBlockTypeFails(): void {
    $this->layerOverMedia(['variant_axes' => [['width' => ['depth' => StyleGuide::DEPTH_ROOT]]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/sets the depth of axis "width", but the depth qualifier applies only to container-family declarations/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Unknown qualifier keys fail the build.
   */
  public function testUnknownQualifierKeyFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => ['depth' => StyleGuide::DEPTH_ROOT, 'max' => 2]]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/unknown key\(s\) \'max\'; the axis qualifier keys are "depth", "label", "description", "owns"/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Unknown depth values fail the build.
   */
  public function testUnknownDepthValueFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => ['depth' => 'nested']]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/the only depth value is "root"/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * An empty qualifier map is a mistake, not "no qualifiers".
   */
  public function testEmptyQualifierFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => []]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/the qualifier must be a non-empty map/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * A multi-key map is not an axis entry.
   */
  public function testMalformedQualifierEntryFails(): void {
    $this->layerOverSectionKind(['variant_axes' => [['width' => ['depth' => StyleGuide::DEPTH_ROOT], 'extra' => TRUE]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/not an axis entry \(an axis machine name, or a one-key qualifier map/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Duplicate detection sees through the qualifier form.
   */
  public function testQualifiedDuplicateFails(): void {
    $this->layerOverSectionKind(['variant_axes' => ['width', ['width' => ['depth' => StyleGuide::DEPTH_ROOT]]]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/lists "width" more than once/');
    $this->styleGuide->getKind('container', self::THEME);
  }

  /**
   * Kind declarations validate their axes too.
   */
  public function testKindAxesValidate(): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', ['container' => ['variant_axes' => ['width']]]);
    $this->styleGuide->resetCache();
    $this->assertSame(['style', 'width'], StyleGuide::axes($this->styleGuide->getKind('container', self::THEME)));

    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', ['container' => ['variant_axes' => ['style']]]);
    $this->styleGuide->resetCache();
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/variant_axes declaration of kind "container"/');
    $this->styleGuide->getKind('container', self::THEME);
  }

}
