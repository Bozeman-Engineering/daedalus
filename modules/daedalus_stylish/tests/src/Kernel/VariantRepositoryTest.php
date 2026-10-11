<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the request-lifetime variant reference identity map.
 *
 * Delivery resolves variant references from the innermost render loop
 * (every independently cached fragment re-walks the root style map), so the
 * repository's contract is what keeps a page render at a handful of queries:
 * each distinct reference resolves at most once per request, misses
 * included, and any variant save or delete resets the memo so a gesture
 * that writes and re-reads in one request can never see the pre-write
 * entity.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantRepositoryTest extends KernelTestBase {

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
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves a variant.
   */
  protected function createVariant(array $values = []): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create($values + [
      'label' => 'Bold CTA',
      'scope' => 'block_type:cta',
    ]);
    $variant->setStyles(['' => ['default' => ['padding' => '2rem']]]);
    $variant->save();
    return $variant;
  }

  /**
   * Resolution order, memoization, and negative caching.
   */
  public function testResolutionIsMemoized(): void {
    $variant = $this->createVariant();
    $repository = $this->container->get('daedalus_stylish.variant_repository');

    // Machine name and uuid both resolve, machine name first.
    $this->assertSame($variant->id(), $repository->resolve('bold_cta')->id());
    $this->assertSame($variant->id(), $repository->resolve($variant->uuid())->id());
    $this->assertNull($repository->resolve('does_not_exist'));

    // A resolved reference never queries again, hits and misses alike.
    Database::startLog('variant_repository', 'default');
    $this->assertSame($variant->id(), $repository->resolve('bold_cta')->id());
    $this->assertSame($variant->id(), $repository->resolve($variant->uuid())->id());
    $this->assertNull($repository->resolve('does_not_exist'));
    $this->assertSame([], Database::getLog('variant_repository', 'default'));
  }

  /**
   * The loadVariant() is the repository: one identity per request.
   */
  public function testValidatorDelegates(): void {
    $variant = $this->createVariant();
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $repository = $this->container->get('daedalus_stylish.variant_repository');
    $this->assertSame(
      $repository->resolve('bold_cta'),
      $validator->loadVariant('bold_cta'),
    );
    $this->assertSame($variant->uuid(), $validator->loadVariant('bold_cta')->uuid());
  }

  /**
   * Saves and deletes reset the memo, so re-reads see the write.
   */
  public function testWriteInvalidation(): void {
    $variant = $this->createVariant();
    $repository = $this->container->get('daedalus_stylish.variant_repository');

    // A memoized entity does not survive its own save: the re-resolve
    // returns the fresh revision, not the pre-write snapshot.
    $this->assertSame(['padding' => '2rem'], $repository->resolve('bold_cta')->getStyles()['']['default']);
    $variant->setStyles(['' => ['default' => ['padding' => '4rem']]]);
    $variant->save();
    $this->assertSame(['padding' => '4rem'], $repository->resolve('bold_cta')->getStyles()['']['default']);

    // A memoized miss does not survive the reference coming into existence.
    $this->assertNull($repository->resolve('late_arrival'));
    $this->createVariant(['label' => 'Late', 'machine_name' => 'late_arrival']);
    $this->assertNotNull($repository->resolve('late_arrival'));

    // Deletion turns the memoized hit back into a miss.
    $variant->delete();
    $this->assertNull($repository->resolve('bold_cta'));
  }

  /**
   * The AI roster orders by weight first, label second.
   *
   * The order every surface lists variants in (the roster's records):
   * shipped families order themselves by weight, the default 0 keeps
   * user-minted variants alphabetical among themselves, and unpublished
   * drafts never appear.
   */
  public function testPublishedVariantsWeightThenLabel(): void {
    $definitions = [
      // Shipped width pair, deliberately anti-alphabetical by weight.
      ['Small', 'small', 60],
      ['Full width', 'full_width', 10],
      // User-minted variants at the default weight 0.
      ['Zebra', 'zebra', 0],
      ['Alpha', 'alpha', 0],
    ];
    foreach ($definitions as [$label, $machine_name, $weight]) {
      $variant = $this->createVariant(['label' => $label, 'machine_name' => $machine_name]);
      $variant->setWeight($weight);
      $variant->save();
    }
    $draft = $this->createVariant(['label' => 'Draft', 'machine_name' => 'draft']);
    $draft->setUnpublished();
    $draft->save();

    $repository = $this->container->get('daedalus_stylish.variant_repository');
    $this->assertSame(
      ['alpha', 'zebra', 'full_width', 'small'],
      array_map(
        static fn ($variant) => $variant->getMachineName(),
        $repository->publishedVariants(),
      ),
    );
  }

  /**
   * Same weight and label: the machine name breaks the tie, never the db.
   *
   * The shipped motion variants pair a block_ and a container_ variant on
   * one label ("Rise", "Fade in", "Scale in") at weight 0. Without a total
   * order the database picks the pair's order and its pick drifts between
   * requests; the roster rides the AI's cached system prompt, so a drift
   * rewrites the cached prefix and every other turn reads nothing from the
   * cache. Created container-first so insertion order cannot pass for the
   * tiebreak.
   */
  public function testPublishedVariantsLabelTiesBreakOnMachineName(): void {
    foreach (['container_rise', 'block_rise', 'container_fade_in', 'block_fade_in'] as $machine_name) {
      $this->createVariant([
        'label' => str_starts_with($machine_name, 'block_') ? substr($machine_name, 6) : substr($machine_name, 10),
        'machine_name' => $machine_name,
        'scope' => str_starts_with($machine_name, 'block_') ? 'kind:block' : 'kind:container',
      ]);
    }

    $repository = $this->container->get('daedalus_stylish.variant_repository');
    $this->assertSame(
      ['block_fade_in', 'container_fade_in', 'block_rise', 'container_rise'],
      array_map(
        static fn ($variant) => $variant->getMachineName(),
        $repository->publishedVariants(),
      ),
    );
  }

}
