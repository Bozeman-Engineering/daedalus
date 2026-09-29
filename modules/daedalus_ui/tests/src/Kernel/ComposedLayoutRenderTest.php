<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the generative composed-layout render (declaration as the render).
 *
 * The container-family half of the composed model: a layout wired to
 * ComposedLayout renders its section through the composed_container
 * element, which prints the tree the element resolver computes from the
 * layout's effective declaration and the section's instance facts — depth
 * (daedalus_layout's nested marker, read by literal string) and emptiness (the
 * regions build's child count). The tree printer is the SAME ComposedTree
 * composed blocks render through; this suite locks the layout entry:
 * - the resolved root form's tag swaps on depth (section at root, div
 *   nested) and the section's stamped attribute bag hoists onto that
 *   root — the root IS the section element, no region wrappers;
 * - children mount at the root when no part claims the regions slot;
 * - an empty section resolves its {empty: true} form: the form's declared
 *   drop-affordance marker prints in every mode, and the translated hint
 *   attribute stamps edit-only, exactly when the empty form matched;
 * - a layout whose effective declaration is not composed fails loud.
 *
 * The declaration comes from daedalus_ui_resolver_test (the mirrored
 * shipped one-box container shape); daedalus_layout is not involved — the nested
 * marker and the attribute bag are stamped by hand at the seam they ride.
 *
 * @group daedalus_ui
 */
#[RunTestsInSeparateProcesses]
class ComposedLayoutRenderTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The section attribute bag stamped for the render, as daedalus_layout stamps it.
   */
  private const SECTION_ATTRIBUTES = [
    'data-selectable' => 'container:11111111-2222-3333-4444-555555555555',
    'data-layout-builder-section-delta' => 0,
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_ui_resolver_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
  }

  /**
   * Root depth, populated: a <section> root that IS the section element.
   */
  public function testRootDepthPopulatedSection(): void {
    $html = $this->renderLayout([
      'content' => ['aaaa' => ['#markup' => '<p>Mounted child</p>']],
    ]);

    $section = $this->htmlXpath($html)->query('//section');
    $this->assertSame(1, $section->length, 'The root form resolved <section> at root depth.');
    // The stamped attribute bag hoisted onto the composed root.
    $this->assertSame(self::SECTION_ATTRIBUTES['data-selectable'], $section->item(0)->getAttribute('data-selectable'));
    $this->assertSame('0', $section->item(0)->getAttribute('data-layout-builder-section-delta'));
    // Children mount at the root (no part claims the regions slot): the
    // child is the section element's direct child, no region wrapper and
    // no second box between them.
    $child = $this->htmlXpath($html)->query('//section/p');
    $this->assertSame(1, $child->length, 'The region child mounts directly at the composed root.');
    $this->assertStringContainsString('Mounted child', $html);
    // A populated section matched no empty form: no marker, no hint.
    $this->assertStringNotContainsString('data-container-empty', $html);
  }

  /**
   * Nested depth: the residual <div> form, still one box.
   */
  public function testNestedDepthRendersDiv(): void {
    $html = $this->renderLayout(
      ['content' => ['aaaa' => ['#markup' => '<p>Nested child</p>']]],
      nested: TRUE,
    );

    $this->assertSame(0, $this->htmlXpath($html)->query('//section')->length, 'The section form is a root-depth fact.');
    $div = $this->htmlXpath($html)->query('//div[@data-selectable]');
    $this->assertSame(1, $div->length, 'Nested, the residual div form resolves and carries the bag.');
    $this->assertSame(1, $this->htmlXpath($html)->query('//div[@data-selectable]/p')->length);
  }

  /**
   * Empty + edit mode: declared marker plus the edit-only translated hint.
   */
  public function testEmptySectionStampsAffordanceInEditMode(): void {
    $this->enterEditMode();
    // The canvas stamps region attributes even on empty regions; an
    // #attributes-only region array has no component children and the
    // section resolves empty.
    $html = $this->renderLayout([
      'content' => ['#attributes' => ['region' => 'content']],
    ]);

    $section = $this->htmlXpath($html)->query('//section');
    $this->assertSame(1, $section->length);
    // The matched {empty: true} form's declared drop-affordance marker,
    // printed by the resolver via the declaration (a bare boolean
    // attribute).
    $this->assertTrue($section->item(0)->hasAttribute('data-container-empty'), 'The empty form\'s declared marker prints.');
    // The render-owned hint: translated, so it cannot live in the
    // declaration, stamped only when the empty form matched.
    $this->assertSame('Empty container', $section->item(0)->getAttribute('data-container-empty-hint'));
  }

  /**
   * Empty + live render: the marker prints, the edit-only hint does not.
   */
  public function testEmptySectionCarriesNoHintLive(): void {
    $html = $this->renderLayout([]);

    $section = $this->htmlXpath($html)->query('//section');
    $this->assertSame(1, $section->length, 'An empty section still renders its root.');
    $this->assertTrue($section->item(0)->hasAttribute('data-container-empty'), 'The declared marker is mode-blind (CSS keys the affordance to edit mode).');
    $this->assertFalse($section->item(0)->hasAttribute('data-container-empty-hint'), 'The hint is edit-only.');
  }

  /**
   * Emptiness comes from the regions build: any component child counts.
   */
  public function testPopulatedRegionSuppressesEmptyForm(): void {
    $this->enterEditMode();
    $html = $this->renderLayout([
      'content' => [
        '#attributes' => ['region' => 'content'],
        'aaaa' => ['#markup' => 'A component'],
      ],
    ]);
    $this->assertStringNotContainsString('data-container-empty', $html);
  }

  /**
   * A non-composed effective declaration fails loud at the render seam.
   */
  public function testNonComposedLayoutFailsLoud(): void {
    $renderer = $this->container->get('daedalus_ui.composed_layout_renderer');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('does not say `composed: true`');
    // The layer merge lets a layout opt OUT of the kind's generative
    // model (resolver_test_uncomposed declares composed: false); wiring
    // such a layout into the composed render path is a configuration bug
    // the renderer refuses rather than inventing markup.
    $renderer->build('resolver_test_uncomposed', []);
  }

  /**
   * Renders the composed test layout to HTML.
   *
   * @param array $regions
   *   The regions build handed to the layout plugin.
   * @param bool $nested
   *   Whether to stamp daedalus_layout's nested marker on the section build.
   */
  private function renderLayout(array $regions, bool $nested = FALSE): string {
    $layout = $this->container->get('plugin.manager.core.layout')->createInstance('resolver_test_container');
    $build = $layout->build($regions);
    // The stamps daedalus_layout lands on the section build after the plugin
    // returns: the section attribute bag and (embedded only) the nested
    // marker, read by literal string.
    $build['#attributes'] = self::SECTION_ATTRIBUTES;
    if ($nested) {
      $build['#daedalus_layout_nested'] = TRUE;
    }
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Puts the request and user into daedalus_ui edit mode.
   */
  private function enterEditMode(): void {
    $this->setUpCurrentUser([], ['use daedalus ui edit mode']);
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
  }

  /**
   * Parses rendered HTML for querying.
   */
  private function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

}
