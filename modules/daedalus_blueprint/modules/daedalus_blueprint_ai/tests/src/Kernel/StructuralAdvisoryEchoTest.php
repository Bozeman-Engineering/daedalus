<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint_markup_test\StateMarkupProjector;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests structural advisories on the write echo.
 *
 * The post-write structural audit runs over the markup projection of the
 * write's affected targets and appends corrective-shaped NON-blocking
 * advisory lines to the echo: a skipped heading level and a "#" dead link
 * are reported, a clean projection reports nothing, and the advisories
 * never flip the result's success — saves never fail on structure. The
 * state-driven fixture projector supplies exact HTML, which is the point:
 * the audit is block-type agnostic because it reads the projection's real
 * tags and hrefs. The auditor's own line grammar is locked by
 * StructuralAuditorTest in the base module.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class StructuralAdvisoryEchoTest extends BlueprintKernelTestBase {

  /**
   * The advisory block's header, present exactly when advisories exist.
   */
  protected const ADVISORY_HEADER = 'Structural advisories (non-blocking; the write is saved):';

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency; the
   * markup test module plugs the state-driven fixture projector into the
   * socket.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_markup_test',
  ];

  /**
   * Runs eb_edit renaming a fresh host entity and returns the output.
   *
   * A root-field write projects the whole entity, which the fixture
   * projector answers with the state-stored HTML.
   */
  protected function runWrite(): string {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Host']);
    $entity->save();

    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('eb_edit');
    $this->assertNotNull($tool, 'The eb_edit tool is available.');
    $tool->setContextValue('entity_type', 'entity_test');
    $tool->setContextValue('entity_id', (string) $entity->id());
    $tool->setContextValue('save', TRUE);
    $tool->setContextValue('operations', [
      ['op' => 'update_element', 'properties' => ['name' => 'Renamed host']],
    ]);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Tests a skipped heading and a "#" link echo both advisories.
   */
  public function testEchoCarriesBothAdvisories(): void {
    $this->container->get('state')->set(
      StateMarkupProjector::PROJECTION_KEY,
      '<div data-key="entity:host"><h2>Hero</h2><h4>Skipped</h4><a href="#">CTA</a></div>',
    );

    $output = $this->runWrite();

    // The advisory never flips the result: the save succeeded.
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ADVISORY_HEADER, $output);
    $this->assertStringContainsString('- h4 follows h2; use h3 or promote the heading.', $output);
    $this->assertStringContainsString('- uri "#" is a dead link; use <nolink> or <button> for an actionless button, or a real path.', $output);
    // Advisories ride AFTER the echoed markup they audit.
    $this->assertGreaterThan(
      strpos($output, 'Key-annotated markup'),
      strpos($output, self::ADVISORY_HEADER),
    );
  }

  /**
   * Tests a structurally clean projection echoes no advisories.
   */
  public function testCleanProjectionEchoesNoAdvisories(): void {
    $this->container->get('state')->set(
      StateMarkupProjector::PROJECTION_KEY,
      '<div data-key="entity:host"><h2>Hero</h2><h3>Sub</h3><a href="/about">About</a></div>',
    );

    $output = $this->runWrite();

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString('Key-annotated markup', $output);
    $this->assertStringNotContainsString('Structural advisories', $output);
  }

}
