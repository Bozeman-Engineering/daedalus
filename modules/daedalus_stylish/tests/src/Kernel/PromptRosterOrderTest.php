<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the cached prompt's roster order across fresh repository instances.
 *
 * The published variants and swatches are printed into the AI's cached
 * system prompt (DialectCatalogPrompt's design system brief), which precedes
 * the messages, so any byte that moves between two requests throws the
 * whole prompt cache away. Each sidebar poll is its own PHP request, and
 * a roster sorted without a total key leaves same-label ties to the
 * database, whose pick drifts between requests: a sidebar transcript
 * alternated two byte orders of the shipped block_/container_ motion pairs
 * and read 0 cached tokens on every other call. VariantRepository and
 * SwatchRepository now break label ties on the unique machine name; this
 * renders the prompt twice from fresh repository instances (a container
 * rebuild, the kernel-test twin of a new request) over same-label
 * fixtures inserted against machine-name order, and asserts the two
 * renders are byte-identical and list the ties by machine name. A future
 * roster without a total sort key fails here, before it breaks the cache.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PromptRosterOrderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * 'key' is an ai dependency; daedalus_blueprint_ai owns the prompt.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'key',
    'ai',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_blueprint_ai',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    // The materials provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Renders the page-language prompt from fresh service instances.
   *
   * The kernel rebuild discards every service, the two repositories and
   * the roster and catalog builders' memos included, so each render below
   * re-reads the rosters from the database exactly as a new request does.
   */
  protected function renderFresh(): string {
    $this->container->get('kernel')->rebuildContainer();
    return $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
  }

  /**
   * Same-label variants and swatches print in one order, request after request.
   */
  public function testRosterOrderIsTotal(): void {
    // The shipped motion pairs' shape: a block_ and a container_ variant
    // on one label at weight 0. Inserted container-first, then reversed
    // between the pairs, so neither insertion order nor its reverse can
    // pass for the tiebreak.
    $pairs = [
      ['container_rise', 'kind:container', 'Rise'],
      ['block_rise', 'kind:block', 'Rise'],
      ['block_fade_in', 'kind:block', 'Fade in'],
      ['container_fade_in', 'kind:container', 'Fade in'],
    ];
    foreach ($pairs as [$machine_name, $scope, $label]) {
      $variant = DaedalusStylishVariant::create([
        'label' => $label,
        'machine_name' => $machine_name,
        'scope' => $scope,
      ]);
      $variant->setStyles(['' => ['default' => ['opacity' => '1']]]);
      $variant->save();
    }
    foreach (['accent_warm', 'accent_cool'] as $machine_name) {
      $swatch = DaedalusStylishSwatch::create([
        'label' => 'Accent',
        'machine_name' => $machine_name,
        'kind' => 'color',
      ]);
      $swatch->setSwatchValue($machine_name === 'accent_warm' ? '#e94560' : '#1a1a2e');
      $swatch->save();
    }

    $first = $this->renderFresh();
    $second = $this->renderFresh();

    // The design system brief is the roster's own print (the ELEMENTS
    // lines above it list the same variants per element as class
    // rosters, in their own order); the tie pin reads the brief alone.
    $materials = $this->materialsSection($first);

    // The ties print by machine name, never by the database's pick. The
    // brief groups looks per element kind (the container's, then every
    // block's), so each pair's order is pinned within its group and the
    // container group leads. Each spelling is a line head, so the check
    // cannot match a name inside another line.
    $this->assertAscending([
      '- container_fade_in "Fade in"',
      '- container_rise "Rise"',
      '- block_fade_in "Fade in"',
      '- block_rise "Rise"',
    ], $materials);
    $this->assertAscending([
      '--swatch-accent_cool "Accent"',
      '--swatch-accent_warm "Accent"',
    ], $materials);

    // Two requests, one byte order: the cached prefix survives the poll.
    $this->assertSame($first, $second, 'The prompt renders byte-identical from fresh repositories.');
  }

  /**
   * The prompt's design system brief, heading to the line before OPS.
   */
  protected function materialsSection(string $prompt): string {
    $start = strpos($prompt, "\n## Design system: ");
    $end = strpos($prompt, "\nOPS (");
    $this->assertNotFalse($start, 'The prompt carries the design system brief.');
    $this->assertNotFalse($end, 'The prompt carries an OPS section.');
    $this->assertGreaterThan($start, $end);
    return substr($prompt, $start, $end - $start);
  }

  /**
   * Asserts every spelling is in the text, in the given order.
   */
  protected function assertAscending(array $spellings, string $text): void {
    $positions = [];
    foreach ($spellings as $spelling) {
      $position = strpos($text, $spelling);
      $this->assertNotFalse($position, "$spelling is printed.");
      $positions[] = $position;
    }
    $sorted = $positions;
    sort($sorted);
    $this->assertSame($sorted, $positions, 'Printed in order: ' . implode(', ', $spellings));
  }

}
