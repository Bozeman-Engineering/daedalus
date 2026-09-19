<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\Markup\StructuralAuditor;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the structural auditor over markup-projection fragments.
 *
 * The auditor reads the element model's HTML print format, so both checks
 * are block-type agnostic by construction: real h1-h6 tags carry the
 * heading ladder, real hrefs carry link integrity. Its lines are
 * advisories, never errors — the write-echo integration (advisories on a
 * successful result) is locked by StructuralAdvisoryEchoTest.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class StructuralAuditorTest extends KernelTestBase {

  /**
   * The dead-link advisory line.
   */
  protected const DEAD_LINK = 'uri "#" is a dead link; use <nolink> or <button> for an actionless button, or a real path.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'daedalus_blueprint'];

  /**
   * The auditor under test.
   */
  protected StructuralAuditor $auditor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->auditor = $this->container->get('daedalus_blueprint.structural_auditor');
  }

  /**
   * Tests a skipped heading level yields the corrective-shaped line.
   */
  public function testSkippedHeadingLevel(): void {
    $advisories = $this->auditor->audit('<div><h2>Hero</h2><p>Copy</p><h4>Card</h4></div>');
    $this->assertSame(['h4 follows h2; use h3 or promote the heading.'], $advisories);
  }

  /**
   * Tests document order across nesting: the ladder reads the projection.
   */
  public function testLadderReadsDocumentOrderAcrossNesting(): void {
    // h2, then a nested h5 two levels deep in wrappers: still h5 after h2.
    $advisories = $this->auditor->audit('<div><h2>Section</h2><div><div><h5>Deep</h5></div></div></div>');
    $this->assertSame(['h5 follows h2; use h3 or promote the heading.'], $advisories);
  }

  /**
   * Tests a clean ladder, climbs back up included, yields nothing.
   */
  public function testCleanLadder(): void {
    $this->assertSame([], $this->auditor->audit(
      '<div><h1>Page</h1><h2>A</h2><h3>A.1</h3><h2>B</h2><h3>B.1</h3></div>'
    ));
  }

  /**
   * Tests the fragment's first heading sets the ladder, never breaks it.
   */
  public function testFirstHeadingAtAnyLevelIsClean(): void {
    $this->assertSame([], $this->auditor->audit('<div><h4>Card title</h4><p>Copy</p></div>'));
  }

  /**
   * Tests identical skips collapse to one line, distinct skips stay.
   */
  public function testSkipLinesDeduplicate(): void {
    $advisories = $this->auditor->audit(
      '<div><h2>A</h2><h4>A card</h4><h2>B</h2><h4>B card</h4><h5>Deeper</h5></div>'
    );
    $this->assertSame(['h4 follows h2; use h3 or promote the heading.'], $advisories);
  }

  /**
   * Tests a "#" href yields the dead-link advisory, once for any count.
   */
  public function testDeadLink(): void {
    $advisories = $this->auditor->audit(
      '<div><a href="#">CTA</a><a href="#">Also CTA</a></div>'
    );
    $this->assertSame([self::DEAD_LINK], $advisories);
  }

  /**
   * Tests real paths and fragment anchors are not dead links.
   */
  public function testRealHrefsAreClean(): void {
    $this->assertSame([], $this->auditor->audit(
      '<div><a href="/about">About</a><a href="#pricing">Pricing</a></div>'
    ));
  }

  /**
   * Tests both checks report together, headings first.
   */
  public function testBothChecksReport(): void {
    $advisories = $this->auditor->audit(
      '<div><h2>Hero</h2><h4>Skipped</h4><a href="#">CTA</a></div>'
    );
    $this->assertSame([
      'h4 follows h2; use h3 or promote the heading.',
      self::DEAD_LINK,
    ], $advisories);
  }

  /**
   * Tests non-element fragments (removal comments, empty) are clean.
   */
  public function testCommentAndEmptyFragmentsAreClean(): void {
    $this->assertSame([], $this->auditor->audit('<!-- abc: removed, no longer on the page -->'));
    $this->assertSame([], $this->auditor->audit(''));
    $this->assertSame([], $this->auditor->audit('   '));
  }

}
