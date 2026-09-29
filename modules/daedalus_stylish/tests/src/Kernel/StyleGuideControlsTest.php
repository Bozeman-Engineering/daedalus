<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests controls: declarations — parsing, layering, guide-build validation.
 *
 * Controls are the basic-mode curation layer: {label, widget, element}
 * bindings in declaration YAML that lift a target's properties onto the
 * inspector surface as one semantic widget. The element key is an ordered
 * candidate list (StyleGuide::controlCandidates()): each entry '' for the
 * root or a declared part, a string the one-candidate shorthand, an
 * omitted key the root. Every candidate is validated on its own (declared,
 * and its property groups covering the widget), the root against the
 * declaration's effective groups. A binding that cannot work (unknown
 * widget, malformed list, undeclared candidate, a candidate whose property
 * groups do not cover the widget's owned properties) must fail LOUDLY when
 * the guide is built, not ship a control whose writes bounce at runtime.
 * The lane covers every refinement of the block kind: block types and
 * block plugins alike.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StyleGuideControlsTest extends KernelTestBase {

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
    $this->installConfig(['daedalus_stylish']);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * Layers a declaration over the media block type and rebuilds the guide.
   *
   * The daedalus_stylish_control_test alter hook merges state-injected declarations
   * as the highest layer, so failure cases never live in YAML that would
   * break every other guide build.
   */
  protected function layerOverMedia(array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', ['media' => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * Layers a declaration over an element kind and rebuilds the guide.
   */
  protected function layerOverKind(string $kind, array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [$kind => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * Layers a declaration over a block plugin and rebuilds the guide.
   *
   * No fixture ships a plugin declaration here, so the injected layer IS
   * the plugin's declaration; the block kind merges underneath it the way
   * getBlockPlugin() serves it.
   */
  protected function layerOverBlockPlugin(string $plugin_id, array $declaration): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.block_plugins', [$plugin_id => $declaration]);
    $this->styleGuide->resetCache();
  }

  /**
   * Layers instance-namespace declarations and rebuilds the guide.
   *
   * Each injected namespace carries the spine's required tags map so the
   * declarations build itself passes; what these tests exercise is
   * daedalus_stylish's control validation on top.
   */
  protected function layerInstances(array $instances): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.instances', $instances);
    $this->styleGuide->resetCache();
  }

  /**
   * A valid controls: declaration parses into the block type declaration.
   */
  public function testControlsParse(): void {
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
      'element' => '.media__frame',
    ], $media['controls']['alignment']);
  }

  /**
   * Controls layer like everything else: maps merge, NULL withdraws.
   */
  public function testControlsLayer(): void {
    // A higher layer relabels the control without restating the binding.
    $this->layerOverMedia(['controls' => ['alignment' => ['label' => 'Position']]]);
    $alignment = $this->styleGuide->getBlockType('media', self::THEME)['controls']['alignment'];
    $this->assertSame('Position', $alignment['label']);
    $this->assertSame('test_alignment', $alignment['widget']);
    $this->assertSame('.media__frame', $alignment['element']);

    // NULL withdraws the control entirely — and the guide still builds,
    // proving validation runs against the final layered view.
    $this->layerOverMedia(['controls' => ['alignment' => NULL]]);
    $this->assertArrayNotHasKey('alignment', $this->styleGuide->getBlockType('media', self::THEME)['controls'] ?? []);
  }

  /**
   * A control naming a widget plugin that does not exist fails the build.
   */
  public function testUnknownWidget(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'no_such_widget', 'element' => '.media__frame'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" names unknown control widget "no_such_widget"/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A binding may move its control to another rostered section.
   *
   * The key rides through the effective declaration as authored (the
   * client resolves binding over widget); the guide only vouches that
   * the name is one basic mode has a header for.
   */
  public function testSectionOverride(): void {
    $this->layerOverMedia(['controls' => ['alignment' => ['section' => 'surface']]]);
    $alignment = $this->styleGuide->getBlockType('media', self::THEME)['controls']['alignment'];
    $this->assertSame('surface', $alignment['section']);
    $this->assertSame('test_alignment', $alignment['widget']);
  }

  /**
   * A control naming a section outside the roster fails the build.
   */
  public function testUnknownSection(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => [
          'label' => 'Broken',
          'widget' => 'test_alignment',
          'element' => '.media__frame',
          'section' => 'sparkle',
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" names unknown control section "sparkle" under its section key; the sections are layout, text, surface, motion/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A control binding an element that is not a declared part fails.
   */
  public function testUndeclaredElement(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => '.media__nope'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/binds element "\.media__nope", which is not a declared part/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * The bound part's property groups must cover the widget's properties.
   *
   * The test_alignment owns justify-content/align-items (the layout group);
   * .media__caption declares only typography, so the binding fails.
   */
  public function testUncoveredProperties(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => '.media__caption'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/the part does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A control without an element key binds the block root and validates.
   *
   * The widget's owned properties are checked against the block type's
   * effective property groups instead of a part's; when they cover the
   * widget, the guide builds and the control parses with no element key.
   */
  public function testRootBoundControl(): void {
    $this->layerOverMedia([
      'property_groups' => ['layout' => []],
      'controls' => [
        'root_alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment'],
      ],
    ]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
    ], $media['controls']['root_alignment']);
  }

  /**
   * A root-bound widget's properties must be covered by the block's groups.
   *
   * The media block type declares no block-level property groups here, so a
   * root binding of test_alignment (layout group) fails with a message that
   * names the block root, not a part.
   */
  public function testRootBoundUncoveredProperties(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/binds widget "test_alignment" to the block root, but the block type does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A widget owning a property no property group provides fails.
   */
  public function testOrphanWidgetProperty(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_orphan', 'element' => '.media__frame'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/widget "test_orphan", which owns "sparkle-intensity", but no property group provides that property/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A control missing its widget key fails; a missing element does not.
   *
   * The element key is optional (its absence means a root binding), so the
   * only structural gap in a {label}-only control is the widget.
   */
  public function testMalformedControl(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken'],
      ],
    ]);
    try {
      $this->styleGuide->getBlockType('media', self::THEME);
      $this->fail('Expected a StyleGuideException.');
    }
    catch (StyleGuideException $e) {
      $this->assertMatchesRegularExpression('/does not name a control widget plugin/', $e->getMessage());
      $this->assertDoesNotMatchRegularExpression('/element/', $e->getMessage());
    }
  }

  /**
   * The normalizer: omitted is the root, a string is one candidate, a list rides.
   *
   * The one grammar every consumer reads through (the validator per
   * candidate, the client payload as served), so the shorthands are pinned
   * here once. '' spells the root, the declared-defaults grammar's spelling.
   */
  public function testControlCandidates(): void {
    $this->assertSame([''], StyleGuide::controlCandidates(['label' => 'Alignment', 'widget' => 'test_alignment']));
    $this->assertSame([''], StyleGuide::controlCandidates(['widget' => 'test_alignment', 'element' => '']));
    $this->assertSame(['.media__frame'], StyleGuide::controlCandidates([
      'widget' => 'test_alignment',
      'element' => '.media__frame',
    ]));
    $this->assertSame(['.media__frame', ''], StyleGuide::controlCandidates([
      'widget' => 'test_alignment',
      'element' => ['.media__frame', ''],
    ]));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/not a candidate list/');
    StyleGuide::controlCandidates(['widget' => 'test_alignment', 'element' => TRUE]);
  }

  /**
   * An element key of '' spells the block root, exactly like omitting it.
   *
   * The declared-defaults grammar's root spelling, so a candidate list may
   * name the root after a part; alone it is the root binding and validates
   * against the block's effective groups, riding through as authored.
   */
  public function testRootSpelledElement(): void {
    $this->layerOverMedia([
      'property_groups' => ['layout' => []],
      'controls' => [
        'root_alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment', 'element' => ''],
      ],
    ]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertSame('', $media['controls']['root_alignment']['element']);
  }

  /**
   * A '' root binding is covered by the block's groups, or it fails as root.
   */
  public function testRootSpelledElementUncoveredProperties(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => ''],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" binds widget "test_alignment" to the block root, but the block type does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A candidate list binds a part first and the root second, and rides as authored.
   *
   * Both candidates cover the widget (.media__frame declares layout, the
   * block root gains it here), so the guide builds and the effective
   * declaration carries the list exactly as written: normalization is the
   * client payload's business (ClientStyleGuideTest), never the
   * declaration's.
   */
  public function testCandidateList(): void {
    $this->layerOverMedia([
      'property_groups' => ['layout' => []],
      'controls' => [
        'alignment' => ['element' => ['.media__frame', '']],
      ],
    ]);
    $alignment = $this->styleGuide->getBlockType('media', self::THEME)['controls']['alignment'];
    $this->assertSame(['.media__frame', ''], $alignment['element']);
    $this->assertSame('test_alignment', $alignment['widget']);
  }

  /**
   * Every candidate must be declared; the error names the offending one.
   *
   * The first candidate is the fixture's declared frame, so only the
   * second can fail, and the message must say which selector is not a
   * part: a list is only as good as its worst candidate, since the
   * resolver may route any property to any of them.
   */
  public function testCandidateListUndeclared(): void {
    $this->layerOverMedia([
      'controls' => [
        'alignment' => ['element' => ['.media__frame', '.media__nope']],
      ],
    ]);
    try {
      $this->styleGuide->getBlockType('media', self::THEME);
      $this->fail('Expected a StyleGuideException.');
    }
    catch (StyleGuideException $e) {
      $this->assertMatchesRegularExpression('/control "alignment" of block type "media" binds element "\.media__nope", which is not a declared part of the block type/', $e->getMessage());
      $this->assertDoesNotMatchRegularExpression('/"\.media__frame"/', $e->getMessage(), 'The declared candidate is not named as a problem.');
    }
  }

  /**
   * Every candidate's groups must cover the widget; the error names the candidate.
   *
   * .media__frame covers the layout widget, .media__caption declares only
   * typography: the second candidate fails coverage on its own, named by
   * selector, while the first passes silently.
   */
  public function testCandidateListUncoveredSecond(): void {
    $this->layerOverMedia([
      'controls' => [
        'alignment' => ['element' => ['.media__frame', '.media__caption']],
      ],
    ]);
    try {
      $this->styleGuide->getBlockType('media', self::THEME);
      $this->fail('Expected a StyleGuideException.');
    }
    catch (StyleGuideException $e) {
      $this->assertMatchesRegularExpression('/control "alignment" of block type "media" binds widget "test_alignment" to element "\.media__caption", but the part does not declare the "layout" property group that owns "justify-content"/', $e->getMessage());
      $this->assertDoesNotMatchRegularExpression('/to element "\.media__frame"/', $e->getMessage(), 'The covered candidate is not named as a problem.');
    }
  }

  /**
   * An element key that is not a string, a list of distinct strings, or absent fails.
   *
   * @param mixed $element
   *   The malformed element key.
   */
  #[DataProvider('malformedElementProvider')]
  public function testMalformedElement(mixed $element): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => $element],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" has .* under its element key, which is not a candidate list/s');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * Malformed element keys: an empty list, a non-string entry, a map, a duplicate.
   */
  public static function malformedElementProvider(): array {
    return [
      'empty list' => [[]],
      'non-string entry' => [['.media__frame', 5]],
      'map' => [['first' => '.media__frame']],
      'duplicate candidate' => [['.media__frame', '.media__frame']],
      'boolean' => [TRUE],
    ];
  }

  /**
   * A block plugin binding validates in the lane getBlockPlugin() serves.
   *
   * The block kind's other refinement: the kind merges underneath, the
   * plugin declares its parts and the binding lists a part first and the
   * root second, both covering the widget. The effective declaration
   * carries the list as authored, the same as a block type's.
   */
  public function testBlockPluginControlValidates(): void {
    $this->layerOverBlockPlugin('fancy_menu', [
      'property_groups' => ['layout' => []],
      'elements' => [
        '.menu-item' => ['label' => 'Menu item', 'property_groups' => ['layout' => []]],
      ],
      'controls' => [
        'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment', 'element' => ['.menu-item', '']],
      ],
    ]);
    $plugin = $this->styleGuide->getBlockPlugin('fancy_menu', self::THEME);
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
      'element' => ['.menu-item', ''],
    ], $plugin['controls']['alignment']);
  }

  /**
   * A block plugin binding whose candidate cannot cover the widget fails.
   *
   * Before this lane a theme's plugin binding reached the client
   * unvalidated; now the same per-candidate check names the plugin, the
   * part and the missing group.
   */
  public function testBlockPluginControlUncoveredProperties(): void {
    $this->layerOverBlockPlugin('fancy_menu', [
      'elements' => [
        '.menu-item' => ['label' => 'Menu item', 'property_groups' => ['typography' => []]],
      ],
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => ['.menu-item']],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block plugin "fancy_menu" binds widget "test_alignment" to element "\.menu-item", but the part does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getBlockPlugin('fancy_menu', self::THEME);
  }

  /**
   * A block plugin binding an undeclared part fails, naming the plugin.
   */
  public function testBlockPluginControlUndeclaredElement(): void {
    $this->layerOverBlockPlugin('fancy_menu', [
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => '.menu-nope'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block plugin "fancy_menu" binds element "\.menu-nope", which is not a declared part of the block plugin/');
    $this->styleGuide->getBlockPlugin('fancy_menu', self::THEME);
  }

  /**
   * A control that is not a map at all fails structurally.
   */
  public function testNonMapControl(): void {
    $this->layerOverMedia(['controls' => ['broken' => 'test_alignment']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" must be a map/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * A valid root-bound kind control passes validation and parses.
   *
   * Kind controls are covered by the kind's own property groups; a control
   * whose widget those groups cover builds cleanly.
   */
  public function testKindControlValidates(): void {
    $this->layerOverKind('region', [
      'property_groups' => ['layout' => []],
      'controls' => [
        'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment'],
      ],
    ]);
    $region = $this->styleGuide->getKind('region', self::THEME);
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
    ], $region['controls']['alignment']);
  }

  /**
   * A kind control may bind a part the kind's own declaration declares.
   *
   * The entity kind's layout root: daedalus_stylish's own layer declares
   * `.daedalus-layout-layout` on the entity kind with the spacing and layout
   * groups (over the anatomy daedalus_layout ships), so the shipped `gap` widget
   * (gap, row-gap, column-gap: the spacing group) binds there and the
   * guide builds with the element key riding through as authored. This
   * is the binding daedalus_edit ships; the client renders it only while the
   * part is present.
   */
  public function testKindControlBindsDeclaredElement(): void {
    $this->layerOverKind('entity', [
      'controls' => [
        'gap' => ['label' => 'Gap', 'widget' => 'gap', 'element' => '.daedalus-layout-layout', 'section' => 'layout'],
      ],
    ]);
    $entity = $this->styleGuide->getKind('entity', self::THEME);
    $this->assertArrayHasKey('spacing', $entity['elements']['.daedalus-layout-layout']['property_groups']);
    $this->assertSame([
      'label' => 'Gap',
      'widget' => 'gap',
      'element' => '.daedalus-layout-layout',
      'section' => 'layout',
    ], $entity['controls']['gap']);
  }

  /**
   * A nested part of the kind's elements tree is bindable too.
   *
   * findElement() walks the nested tree, the same rule block types get, so
   * a kind declaring `.outer > .inner` anatomy binds `.inner` directly.
   */
  public function testKindControlBindsNestedElement(): void {
    $this->layerOverKind('region', [
      'elements' => [
        '.region__outer' => [
          'elements' => [
            '.region__inner' => ['property_groups' => ['layout' => []]],
          ],
        ],
      ],
      'controls' => [
        'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment', 'element' => '.region__inner'],
      ],
    ]);
    $region = $this->styleGuide->getKind('region', self::THEME);
    $this->assertSame('.region__inner', $region['controls']['alignment']['element']);
  }

  /**
   * A kind control binding a part the kind does not declare fails.
   *
   * Only the kind's own effective declaration vouches for a part: a
   * selector a template might print somewhere is not anatomy the kind
   * asserts, so nothing can be bound to it.
   */
  public function testKindControlUndeclaredElement(): void {
    $this->layerOverKind('region', [
      'property_groups' => ['layout' => []],
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => '.region__inner'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of kind "region" binds element "\.region__inner", which is not a declared part of the kind/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * The bound part's property groups must cover the widget's properties.
   *
   * Coverage is checked against the PART's groups, not the kind root's:
   * the region root declares spacing here, but the part it binds declares
   * only typography, so the gap widget's family is uncovered at the part.
   */
  public function testKindControlElementUncoveredProperties(): void {
    $this->layerOverKind('region', [
      'property_groups' => ['spacing' => []],
      'elements' => [
        '.region__inner' => ['property_groups' => ['typography' => []]],
      ],
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'gap', 'element' => '.region__inner'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of kind "region" binds widget "gap" to element "\.region__inner", but the part does not declare the "spacing" property group that owns "gap"/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * A kind control may list a part and the '' root; the root is the kind's groups.
   *
   * The region root declares layout and the nested part declares it too,
   * so both candidates cover the widget and the list rides as authored.
   * Withdrawing the root's group fails the root candidate by name.
   */
  public function testKindControlCandidateList(): void {
    $this->layerOverKind('region', [
      'property_groups' => ['layout' => []],
      'elements' => [
        '.region__inner' => ['property_groups' => ['layout' => []]],
      ],
      'controls' => [
        'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment', 'element' => ['.region__inner', '']],
      ],
    ]);
    $region = $this->styleGuide->getKind('region', self::THEME);
    $this->assertSame(['.region__inner', ''], $region['controls']['alignment']['element']);

    $this->layerOverKind('region', [
      'elements' => [
        '.region__inner' => ['property_groups' => ['layout' => []]],
      ],
      'controls' => [
        'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment', 'element' => ['.region__inner', '']],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "alignment" of kind "region" binds widget "test_alignment" to the kind root, but the kind does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * A kind control's widget must be covered by the kind's property groups.
   */
  public function testKindControlUncoveredProperties(): void {
    $this->layerOverKind('region', [
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment'],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/binds widget "test_alignment" to the kind root, but the kind does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * A kind control that is a non-map scalar other than FALSE fails.
   */
  public function testKindNonMapControl(): void {
    $this->layerOverKind('region', ['controls' => ['broken' => 'test_alignment']]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of kind "region" must be a map/');
    $this->styleGuide->getKind('region', self::THEME);
  }

  /**
   * A FALSE control at kind level is a valid tombstone, skipped as-is.
   *
   * A higher provider layer can suppress a lower layer's kind control with
   * scalar FALSE; the guide builds and the FALSE survives to the kind
   * declaration, keeping "suppressed" distinct from "never declared".
   */
  public function testKindFalseTombstone(): void {
    $this->layerOverKind('region', ['controls' => ['ghost' => FALSE]]);
    $this->assertFalse($this->styleGuide->getKind('region', self::THEME)['controls']['ghost']);
  }

  /**
   * FALSE at block type level suppresses an inherited kind control.
   *
   * The block kind declares a root-bound control every block type inherits;
   * a block type sets the whole control to scalar FALSE and the guide still
   * builds. FALSE, not NULL, because NULL vanishes in merge() and the kind's
   * version would resurface; FALSE survives to the effective declaration.
   */
  public function testFalseTombstoneSuppressesKindControl(): void {
    $this->layerOverKind('block', [
      'property_groups' => ['layout' => []],
      'controls' => [
        'kind_alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment'],
      ],
    ]);
    $this->layerOverMedia(['controls' => ['kind_alignment' => FALSE]]);
    $media = $this->styleGuide->getBlockType('media', self::THEME);
    $this->assertFalse($media['controls']['kind_alignment']);
    // The block type's own YAML control is untouched by the tombstone.
    $this->assertSame('test_alignment', $media['controls']['alignment']['widget']);
  }

  /**
   * Withdrawing a group a kind control needs fails without a tombstone.
   *
   * The block kind declares the layout group and a control needing it; a
   * block type withdraws the group with NULL but still inherits the kind
   * control, so the effective declaration cannot cover the widget and the
   * build fails loudly, forcing the author to tombstone the control too.
   */
  public function testWithdrawnGroupFailsInheritedKindControl(): void {
    $this->layerOverKind('block', [
      'property_groups' => ['layout' => []],
      'controls' => [
        'kind_alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment'],
      ],
    ]);
    $this->layerOverMedia(['property_groups' => ['layout' => NULL]]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "kind_alignment" of block type "media" binds widget "test_alignment" to the block root, but the block type does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getBlockType('media', self::THEME);
  }

  /**
   * An instance-namespace control validates and rides the declaration.
   *
   * Instance controls are the basic-mode curated surface for a selected
   * instance part (a minted text element): root-bound onto each instance,
   * covered by the namespace's own property groups. The client projects
   * them per part key (controls.test.mjs).
   */
  public function testInstanceControlValidates(): void {
    $this->layerInstances([
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'property_groups' => ['layout' => []],
        'controls' => [
          'alignment' => ['label' => 'Alignment', 'widget' => 'test_alignment'],
        ],
      ],
    ]);
    $instances = $this->styleGuide->getInstances(self::THEME);
    $this->assertSame([
      'label' => 'Alignment',
      'widget' => 'test_alignment',
    ], $instances['.s-*']['controls']['alignment']);
  }

  /**
   * An instance control declaring an element key fails the build.
   *
   * An instance is CONTENT carrying its own markup — a namespace declares
   * no parts of its own, so there is nothing an element key could bind;
   * the control targets the instance itself, always.
   */
  public function testInstanceControlElementRejected(): void {
    $this->layerInstances([
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'property_groups' => ['layout' => []],
        'controls' => [
          'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => '.inner'],
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of instance namespace "\.s-\*" declares an element key, but an instance control binds the instance itself/');
    $this->styleGuide->getInstances(self::THEME);
  }

  /**
   * The namespace's property groups must cover the widget's properties.
   */
  public function testInstanceControlUncoveredProperties(): void {
    $this->layerInstances([
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'property_groups' => ['typography' => []],
        'controls' => [
          'broken' => ['label' => 'Broken', 'widget' => 'test_alignment'],
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of instance namespace "\.s-\*" binds widget "test_alignment" to the instance, but the namespace does not declare the "layout" property group that owns "justify-content"/');
    $this->styleGuide->getInstances(self::THEME);
  }

  /**
   * An instance control naming an unknown widget plugin fails the build.
   */
  public function testInstanceControlUnknownWidget(): void {
    $this->layerInstances([
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph'],
        'controls' => [
          'broken' => ['label' => 'Broken', 'widget' => 'no_such_widget'],
        ],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of instance namespace "\.s-\*" names unknown control widget "no_such_widget"/');
    $this->styleGuide->getInstances(self::THEME);
  }

}
