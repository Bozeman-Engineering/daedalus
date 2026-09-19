<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the aggregation-ON wrapping of optimizeGroup() output.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LayeredAggregateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_stylish_layers',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish_layers']);
  }

  /**
   * Optimizes the fixture stylesheet through the decorated service.
   *
   * Groups arrive stamped (or not) by hook_css_alter() + the grouper
   * decorator; optimizeGroup() keys purely on the group-level stamp.
   */
  private function optimizeFixtureGroup(?string $stamp = 'site'): string {
    $fixture = dirname(__DIR__, 2) . '/fixtures/layered_aggregate.css';
    $this->assertFileExists($fixture);
    $item = [
      'type' => 'file',
      'data' => $fixture,
      'media' => 'all',
      'preprocess' => TRUE,
      'license' => FALSE,
    ];
    $group = ['items' => [$item]];
    if ($stamp !== NULL) {
      $group['daedalus_stylish_layers'] = $stamp;
      $group['items'][0]['daedalus_stylish_layers'] = $stamp;
    }
    return $this->container->get('asset.css.collection_optimizer')->optimizeGroup($group);
  }

  /**
   * The aggregate is wrapped and surviving imports are layered before it.
   */
  public function testAggregateWrappedInLayer(): void {
    $aggregate = $this->optimizeFixtureGroup();

    // Surviving imports (external, media-qualified) are re-emitted with
    // layer(<wrapper>) between the URL and the import conditions, in their
    // original hoisted order, all BEFORE the wrapper block — @import is
    // invalid inside a block and would be dropped by browsers.
    // Local import URLs get base-path rewritten by CssOptimizer, so match on
    // the file name rather than the full rewritten path.
    $this->assertStringContainsString('@import url("https://example.com/external.css") layer(site);', $aggregate);
    $this->assertStringContainsString('print-only.css) layer(site) print;', $aggregate);
    $wrapper_pos = strpos($aggregate, '@layer site {');
    $this->assertNotFalse($wrapper_pos);
    $this->assertSame($wrapper_pos, strpos($aggregate, '@layer'), 'No layer construct precedes the wrapper other than the layered imports.');
    $last_import = strrpos($aggregate, '@import');
    $this->assertLessThan($wrapper_pos, $last_import, 'All @import rules precede the @layer wrapper.');
    $this->assertLessThan(
      strpos($aggregate, 'print-only.css'),
      strpos($aggregate, '@import url("https://example.com/external.css")'),
      'Hoisted import order is preserved.'
    );

    // Everything else — including CSS inlined from resolvable local imports —
    // lands inside the wrapper block, which closes at the end of the file.
    $this->assertGreaterThan($wrapper_pos, strpos($aggregate, 'body{color:red;}'));
    $this->assertGreaterThan($wrapper_pos, strpos($aggregate, '.inlined{color:blue;}'));
    $this->assertStringEndsWith("}\n", $aggregate);

    // @charset is invalid inside a block; core strips it and the wrapper must
    // not resurrect it.
    $this->assertStringNotContainsString('@charset', $aggregate);
  }

  /**
   * An import that already targets a layer becomes a wrapper sublayer.
   */
  public function testPreLayeredImportBecomesSublayer(): void {
    $aggregate = $this->optimizeFixtureGroup();

    // `layer(base)` re-emitted as `layer(site.base)`: a sublayer can never
    // outrank unlayered styles, and doubling layer() would be invalid CSS.
    $this->assertStringContainsString('prelayered.css) layer(site.base);', $aggregate);
    $this->assertStringNotContainsString('layer(base)', $aggregate);
  }

  /**
   * The group's stamp names the wrapper layer.
   */
  public function testStampNamesTheWrapper(): void {
    $aggregate = $this->optimizeFixtureGroup('my_site');

    $this->assertStringContainsString('@layer my_site {', $aggregate);
    $this->assertStringContainsString('@import url("https://example.com/external.css") layer(my_site);', $aggregate);
    $this->assertStringNotContainsString('layer(site)', $aggregate);
  }

  /**
   * An unstamped (disabled or exempt) group is core's untouched aggregate.
   */
  public function testUnstampedPassThrough(): void {
    $aggregate = $this->optimizeFixtureGroup(NULL);

    $this->assertStringNotContainsString('@layer', $aggregate);
    $this->assertStringNotContainsString('layer(site', $aggregate);
    // Core behavior intact: imports hoisted unlayered (the fixture's own
    // layer(base) import passes through untouched), charset stripped.
    $this->assertStringContainsString('@import url("https://example.com/external.css");', $aggregate);
    $this->assertStringContainsString('prelayered.css) layer(base);', $aggregate);
    $this->assertStringContainsString('body{color:red;}', $aggregate);
    $this->assertStringNotContainsString('@charset', $aggregate);
  }

}
