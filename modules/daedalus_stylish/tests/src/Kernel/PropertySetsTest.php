<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\StyleGuide;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the factored property-set spine of the style guide.
 *
 * The property group plugins ARE the shared sets: every declaration node
 * projects to set references plus constraint deltas
 * (StyleGuide::propertySetRefs()) instead of an inline expansion of the
 * groups' vocabulary per entry — the AI fragment serves bare set NAMES
 * only (the stylable section), never property enumerations. These tests
 * pin the reference spine: references name only installed groups, deltas
 * stay the declaration's own authored data, and the media-capable names
 * the fragment's guidance paragraph derives from
 * (StyleGuide::mediaProperties()) track the referenced sets.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PropertySetsTest extends KernelTestBase {

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
    'daedalus_stylish_wide_guide_test',
    'daedalus_stylish_property_sets_test',
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
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->styleGuide = $this->container->get('daedalus_stylish.style_guide');
  }

  /**
   * The media-capable names track the guide's referenced sets.
   */
  public function testMediaProperties(): void {
    // The media-capable names the guidance paragraph derives from:
    // background is referenced (kinds, block types, and daedalus_stylish's shipped
    // site base declaration), and background-image is its one
    // media-flagged property. A site whose referenced sets grant no media
    // slot would serve [] and never be taught the token.
    $this->assertSame(['background-image'], $this->styleGuide->mediaProperties(self::THEME));

    // Built once: the walk memoizes per theme.
    $this->assertSame(['background-image'], $this->styleGuide->mediaProperties(self::THEME));
  }

  /**
   * Declaration nodes project to set references plus constraint deltas.
   */
  public function testSetReferences(): void {
    // The container kind: four referenced sets, deltas flattened across
    // groups into one property-keyed map, as authored (the effective
    // padding constraints after layering: max from the extra module, units
    // replaced by the sub theme).
    $container = $this->styleGuide->getKind('container', self::THEME);
    $refs = $this->styleGuide->propertySetRefs($container);
    $this->assertEqualsCanonicalizing(['spacing', 'background', 'wide:filter_effects', 'wide:scroll_snap'], $refs['sets']);
    $this->assertSame('6rem', $refs['constraints']['padding']['max']);
    $this->assertSame(['rem'], $refs['constraints']['padding']['units']);
    $this->assertSame('3rem', $refs['constraints']['padding-left']['max']);
    $this->assertSame(['0', '1rem'], $refs['constraints']['scroll-margin']['allowed']);

    // The children pseudo-part is an ordinary node: set refs, and no
    // constraints key when the node carries no deltas.
    $this->assertSame(['sets' => ['layout']], $this->styleGuide->propertySetRefs($container['children']));

    // A referenced group no installed module provides is skipped: the
    // base-theme field kind declares typography and the unprovided
    // "color" group.
    $field = $this->styleGuide->getKind('field', self::BASE_THEME);
    $this->assertSame(['sets' => ['typography']], $this->styleGuide->propertySetRefs($field));

    // Block types project their EFFECTIVE declaration (universal block
    // kind underneath), and parts project like any node.
    $testimonial = $this->styleGuide->getBlockType('testimonial', self::THEME);
    $refs = $this->styleGuide->propertySetRefs($testimonial);
    $this->assertEqualsCanonicalizing(['effects', 'motion', 'shadow', 'spacing', 'typography'], $refs['sets']);
    $this->assertSame(['Arial'], $refs['constraints']['font-family']['allowed']);
    $this->assertSame('2rem', $refs['constraints']['font-size']['max']);
    $quote = $this->styleGuide->propertySetRefs($testimonial['elements']['.testimonial__quote']);
    $this->assertSame(['typography'], $quote['sets']);
    $this->assertSame('1.75rem', $quote['constraints']['font-size']['max']);

    // A node referencing no installed sets projects to nothing.
    $this->assertSame([], $this->styleGuide->propertySetRefs([]));
  }

  /**
   * Every set any declaration node references names an installed group.
   *
   * The invariant reference emission depends on: a set id the fragments
   * serialize must always be enforceable (an installed property group
   * plugin), at any nesting depth, children pseudo-parts and base tags
   * included — the unprovided-group skip is total.
   */
  public function testEveryReferenceResolves(): void {
    $groups = $this->container->get('plugin.manager.daedalus_stylish_property_group');

    $nodes = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      $nodes[] = $this->styleGuide->getKind($kind, self::THEME);
    }
    foreach ($this->styleGuide->getBlockTypes(self::THEME) as $bundle) {
      $nodes[] = $this->styleGuide->getBlockType($bundle, self::THEME);
    }
    foreach ($this->styleGuide->getBlockPlugins(self::THEME) as $plugin_id) {
      $nodes[] = $this->styleGuide->getBlockPlugin($plugin_id, self::THEME);
    }
    foreach ($this->styleGuide->getLayouts(self::THEME) as $layout_id) {
      $nodes[] = $this->styleGuide->getLayout($layout_id, self::THEME);
    }
    foreach ($this->styleGuide->getBaseElements(self::THEME) as $declaration) {
      $nodes[] = $declaration;
    }

    $checked = 0;
    while ($nodes !== []) {
      $node = array_pop($nodes);
      foreach ($this->styleGuide->propertySetRefs($node)['sets'] ?? [] as $set) {
        $this->assertTrue($groups->hasDefinition($set), "Set \"$set\" names an installed property group.");
        $checked++;
      }
      if (is_array($node['children'] ?? NULL)) {
        $nodes[] = $node['children'];
      }
      foreach ($node['elements'] ?? [] as $part) {
        if (is_array($part)) {
          $nodes[] = $part;
        }
      }
    }
    $this->assertGreaterThan(0, $checked);
  }

}
