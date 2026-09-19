<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Exception\StyleGuideException;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests controls: declarations — parsing, layering, guide-build validation.
 *
 * Controls are the basic-mode curation layer: {label, widget, element}
 * bindings in block-type YAML that lift a declared part's properties onto
 * the block's inspector surface as one semantic widget. The element key is
 * optional: omitting it binds the control to the block root, validated
 * against the block's effective property groups. A binding that cannot work
 * (unknown widget, undeclared part, bound target whose property groups do
 * not cover the widget's owned properties) must fail LOUDLY when the guide
 * is built, not ship a control whose writes bounce at runtime.
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
    $this->expectExceptionMessageMatches('/control "broken" of block type "media" names unknown control section "sparkle" under its section key; the sections are text, surface, layout, motion/');
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
   * An element key that is present but empty is still malformed.
   *
   * Only a genuinely absent key means "bind the block root"; an empty string
   * is a mistake, not a root binding.
   */
  public function testEmptyElement(): void {
    $this->layerOverMedia([
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => ''],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/must name a part selector under its element key, or omit the key to bind the block root/');
    $this->styleGuide->getBlockType('media', self::THEME);
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
   * An empty element key on a kind control is malformed, not a root binding.
   */
  public function testKindControlEmptyElement(): void {
    $this->layerOverKind('region', [
      'property_groups' => ['layout' => []],
      'controls' => [
        'broken' => ['label' => 'Broken', 'widget' => 'test_alignment', 'element' => ''],
      ],
    ]);
    $this->expectException(StyleGuideException::class);
    $this->expectExceptionMessageMatches('/control "broken" of kind "region" must name a part selector under its element key, or omit the key to bind the kind root/');
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
