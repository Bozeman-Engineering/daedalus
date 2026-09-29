<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\ControlSection;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guide-build validation of the shipped baseline's control bindings.
 *
 * The daedalus_edit baseline (daedalus_edit.page_builder.yml) binds the color
 * ControlWidgets at kind roots, and those widgets own more than their
 * headline property: Background owns background-image plus the media
 * seed's size/repeat/position companions alongside background-color, and
 * TextColor owns the gradient-text recipe's background-image +
 * background-clip alongside color; and it binds the page-level Gap widget
 * (gap plus its two longhands) to the entity kind's layout root part
 * rather than the root. Guide-build validation loudly holds every owned
 * property to the property groups of what the control binds, so this
 * test is the cross-repo contract check: the baseline's declared groups
 * must cover the grown widgets on every kind that binds them (the field
 * kind root declares Background exactly for TextColor's recipe writes;
 * the layout root part declares Spacing for the gap family).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class BaselineControlBindingsTest extends KernelTestBase {

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
    'text',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'field_sample_value',
    'filter',
    'editor',
    'daedalus_edit',
    'link',
    'options',
    'daedalus_edit_text_block',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * The grown widget ownership is covered on every kind, loudly validated.
   */
  public function testBaselineCoversGrownWidgets(): void {
    $widgets = $this->container->get('plugin.manager.daedalus_stylish_control_widget');
    $this->assertSame([
      'background-color',
      'background-image',
      'background-size',
      'background-repeat',
      'background-position',
    ], $widgets->getDefinition('background')['properties']);
    $this->assertSame(['color', 'background-image', 'background-clip'], $widgets->getDefinition('text_color')['properties']);
    // The page-level Gap control's widget: the whole gap family (the
    // spacing group's shorthand over its two longhands), filed under
    // Layout, so the entity kind's layout-root binding holds the part to
    // every spelling the AI or the widget could write.
    $this->assertSame(['gap', 'row-gap', 'column-gap'], $widgets->getDefinition('gap')['properties']);
    $this->assertSame(ControlSection::Layout, $widgets->getDefinition('gap')['section']);

    // Building a kind runs the loud control validation (unknown widget,
    // uncovered owned property, malformed binding all throw
    // StyleGuideException), so a clean build IS the assertion that the
    // baseline covers the grown ownership.
    $style_guide = $this->container->get('daedalus_stylish.style_guide');
    foreach (['entity', 'container', 'field', 'block'] as $kind) {
      $declaration = $style_guide->getKind($kind, self::THEME);
      $this->assertIsArray($declaration, $kind);
      $this->assertArrayHasKey('background', $declaration['property_groups'], "$kind declares the background group its color widgets need");
    }

    // The entity kind's layout root (daedalus_layout's anatomy, daedalus_stylish's groups)
    // declares the spacing group that owns the gap family, so the
    // baseline's page-level Gap (the element-bound kind control the guide
    // accepts) binds the `gap` widget there and is covered at the part:
    // the clean build above validated it, and the binding rides through
    // as authored.
    $entity = $style_guide->getKind('entity', self::THEME);
    $this->assertSame('Layout', $entity['elements']['.daedalus-layout-layout']['label']);
    $this->assertArrayHasKey('spacing', $entity['elements']['.daedalus-layout-layout']['property_groups']);
    $this->assertSame([
      'label' => 'Gap',
      'widget' => 'gap',
      'element' => '.daedalus-layout-layout',
      'section' => 'layout',
    ], $entity['controls']['gap']);
    $this->assertSame(['background', 'gap'], array_keys($entity['controls']));

    // The field kind is the binding that forced the baseline growth:
    // text_color binds at the field ROOT, so the root itself (not just its
    // .s-* parts) must declare Background to cover the recipe's
    // background-image + background-clip writes.
    $field = $style_guide->getKind('field', self::THEME);
    $this->assertSame('text_color', $field['controls']['text_color']['widget']);

    // The '.s-*' instance namespace binds basic mode's curated surface for
    // a selected text part — root-bound onto each instance, covered by
    // the namespace's own property groups (the clean build above already
    // ran the loud validation over these real bindings).
    $instances = $style_guide->getInstances(self::THEME);
    $this->assertSame(
      ['typography', 'text_color', 'text_alignment', 'spacing', 'background', 'corners_border'],
      array_keys($instances['.s-*']['controls']),
    );
    $this->assertSame('typography', $instances['.s-*']['controls']['typography']['widget']);
    $this->assertArrayNotHasKey('element', $instances['.s-*']['controls']['text_color']);
  }

  /**
   * The text block restates the kind's ids over a link-first candidate list.
   *
   * A linked text block is two boxes (the tag root and the anchor inside
   * it, where the shipped `button` variant keys every style), and basic
   * mode shows ONE row per binding: daedalus_edit_text_block restates the
   * universal block kind's ids (so the maps key-merge and no second row
   * appears) with `['.text-block-link', '']` as the address, and the
   * projection resolves each owned property to the first candidate that
   * paints it. The five ids are pinned as authored on the effective
   * declaration (a list, the link first) and as served (controlCandidates
   * is the one normalizer ClientStyleGuide::serveNode() runs, so the
   * served list IS this read); the bindings whose widget the link's
   * groups cannot cover (elevation needs shadow; width sizing; position
   * layout on the kind's terms) stay the kind's root bindings; and no
   * `button_*` id survives. The clean build (getBlockType runs the loud
   * control validation) is the proof that the link's property groups
   * cover every restated widget at the second candidate as well as the
   * first.
   */
  public function testTextBlockBindsTheLinkFirst(): void {
    $style_guide = $this->container->get('daedalus_stylish.style_guide');
    $text = $style_guide->getBlockType('text', self::THEME);
    $this->assertIsArray($text);
    $this->assertSame(['background', 'spacing', 'border', 'typography', 'layout'], array_keys($text['elements']['.text-block-link']['property_groups']));

    $link_first = ['.text-block-link', ''];
    foreach ([
      'background' => ['Background', 'background'],
      'text_color' => ['Text color', 'text_color'],
      'typography' => ['Typography', 'typography'],
      'corners_border' => ['Corners and border', 'corners_border'],
      'spacing' => ['Spacing', 'spacing_scale'],
    ] as $id => [$label, $widget]) {
      $this->assertSame([
        'label' => $label,
        'widget' => $widget,
        'element' => $link_first,
      ], $text['controls'][$id], "$id restates the kind's id over the link-first list");
      $this->assertSame($link_first, StyleGuide::controlCandidates($text['controls'][$id]), "$id is served as the same list");
    }
    foreach (['elevation', 'width', 'position', 'text_alignment'] as $id) {
      $this->assertArrayNotHasKey('element', $text['controls'][$id], "$id stays a root binding");
      $this->assertSame([''], StyleGuide::controlCandidates($text['controls'][$id]), "$id is served as the root list");
    }
    $this->assertSame([], preg_grep('/^button_/', array_keys($text['controls'])), 'no button_* id survives: the link\'s rows are the kind\'s rows');
    $this->assertSame(
      [
        'typography',
        'text_color',
        'background',
        'corners_border',
        'elevation',
        'position',
        'spacing',
        'width',
        'motion_trigger',
        'text_alignment',
      ],
      array_keys($text['controls']),
      'the kind\'s ids merge in the kind\'s order (the suite\'s motion trigger layered after them); the text block adds only its alignment',
    );
  }

}
