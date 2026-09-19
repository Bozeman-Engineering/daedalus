<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\SwatchRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the request-lifetime swatch reference identity map.
 *
 * The variant repository's contract at value scope: each distinct swatch
 * reference resolves at most once per request (misses included), the
 * published-name universe — what every var(--swatch-*) check validates
 * against — loads once, and any swatch save or delete resets the memo so a
 * gesture that mints and references in one request can never see the
 * pre-write universe.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves a swatch.
   */
  protected function createSwatch(array $values = [], string|array $value = '#e94560'): DaedalusStylishSwatch {
    $swatch = DaedalusStylishSwatch::create($values + [
      'label' => 'Brand red',
      'kind' => 'color',
    ]);
    $swatch->setSwatchValue($value);
    $swatch->save();
    return $swatch;
  }

  /**
   * Resolution order, memoization, and negative caching.
   */
  public function testResolutionIsMemoized(): void {
    $swatch = $this->createSwatch();
    $repository = $this->container->get('daedalus_stylish.swatch_repository');

    // Machine name and uuid both resolve, machine name first.
    $this->assertSame($swatch->id(), $repository->resolve('brand_red')->id());
    $this->assertSame($swatch->id(), $repository->resolve($swatch->uuid())->id());
    $this->assertNull($repository->resolve('does_not_exist'));

    // Repeat asks hit the memo, misses included: no further queries.
    Database::startLog('swatch_repository');
    $repository->resolve('brand_red');
    $repository->resolve($swatch->uuid());
    $repository->resolve('does_not_exist');
    $this->assertSame([], Database::getLog('swatch_repository'));
  }

  /**
   * The published set's machine names, in the repository's label order.
   *
   * @return string[]
   *   The names.
   */
  protected function publishedNames(SwatchRepository $repository): array {
    return array_map(static fn (DaedalusStylishSwatchInterface $swatch): string => $swatch->getMachineName(), $repository->publishedSwatches());
  }

  /**
   * The published set: published swatches only, loaded once.
   */
  public function testPublishedNames(): void {
    $this->createSwatch();
    $this->createSwatch(['label' => 'Hero fade', 'kind' => 'gradient'], 'linear-gradient(#1a1a2e, #e94560)');
    $draft = $this->createSwatch(['label' => 'Draft']);
    $draft->setUnpublished()->save();

    $repository = $this->container->get('daedalus_stylish.swatch_repository');
    $this->assertSame(['brand_red', 'hero_fade'], $this->publishedNames($repository));

    // The set is memoized for the request.
    Database::startLog('swatch_repository');
    $repository->publishedSwatches();
    $this->assertSame([], Database::getLog('swatch_repository'));
  }

  /**
   * Same label: the machine name breaks the tie, never the database.
   *
   * The roster rides the AI's cached system prompt; an order the database
   * decides drifts between requests and rewrites that cached prefix (the
   * variant repository's twin pin). Created anti-alphabetically so
   * insertion order cannot pass for the tiebreak.
   */
  public function testPublishedNamesLabelTiesBreakOnMachineName(): void {
    $this->createSwatch(['label' => 'Accent', 'machine_name' => 'accent_warm'], '#e94560');
    $this->createSwatch(['label' => 'Accent', 'machine_name' => 'accent_cool'], '#1a1a2e');

    $repository = $this->container->get('daedalus_stylish.swatch_repository');
    $this->assertSame(['accent_cool', 'accent_warm'], $this->publishedNames($repository));
  }

  /**
   * The save/delete chokepoints reset the memo (the staleness contract).
   */
  public function testChangeHookResets(): void {
    $repository = $this->container->get('daedalus_stylish.swatch_repository');
    $this->assertNull($repository->resolve('brand_red'));
    $this->assertSame([], $this->publishedNames($repository));

    // Minting mid-request heals the negative entry and the universe.
    $swatch = $this->createSwatch();
    $this->assertSame($swatch->id(), $repository->resolve('brand_red')->id());
    $this->assertSame(['brand_red'], $this->publishedNames($repository));

    // Unpublishing drops the name from the universe (the entity still
    // resolves: resolution is identity, publication is the delivery gate).
    $swatch->setUnpublished()->save();
    $this->assertSame([], $this->publishedNames($repository));
    $this->assertSame($swatch->id(), $repository->resolve('brand_red')->id());

    // Deletion turns the resolution into a miss.
    $swatch->delete();
    $this->assertNull($repository->resolve('brand_red'));
  }

}
