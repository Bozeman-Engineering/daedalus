<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the base-at-top width-band style migration (the wide fold).
 *
 * The fold rule under test: a wide-carrying condition folds into the same
 * compound without its wide part, the wide values winning per property,
 * and the value that used to render at 768-1023px in that lane (the fold
 * target's own value, falling through to default) spills into the lane's
 * medium compound so that band keeps rendering as before. Covered stores:
 * host style maps via daedalus_layout's transform spine, daedalus_variant styles
 * across revisions, daedalus_skin tokens and defaults across revisions
 * (never covered by the container migration), and stored stencils.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class WidthBandMigrationTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'file',
    'image',
    'media',
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('stencil');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Persists an entity carrying pre-migration data.
   *
   * A syncing save bypasses the presave safety net (the width-band sweep
   * is registered on daedalus_layout's transform spine), which is exactly how
   * pre-migration data exists on a site the update hooks have not run on
   * yet.
   */
  protected function createLegacyEntity(array $map): EntityTest {
    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => [],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map, JSON_UNESCAPED_SLASHES),
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();
    return $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
  }

  /**
   * Runs the host entity walker to completion.
   */
  protected function walkHosts(): void {
    $walker = $this->container->get('daedalus_layout.host_entity_walker');
    $sandbox = [];
    do {
      $walker->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
  }

  /**
   * Reads an entity's style map.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Host style maps fold via the transform spine, lane by lane.
   */
  public function testHostMapFold(): void {
    $entity = $this->createLegacyEntity([
      // The plain lane: wide wins default per property, the old default
      // spills into medium, narrow and the variants list stay untouched. A
      // wide-only property (margin) has nothing to spill and extends to
      // every width.
      'block:aaa' => [
        'variants' => [['variant' => 'hero', 'fragment' => '']],
        'default' => ['color' => 'black', 'padding' => '1rem'],
        'narrow' => ['color' => 'green'],
        'wide' => ['color' => 'blue', 'margin' => '2rem'],
      ],
      // The compound lane with its own scheme value: wide+dark folds into
      // dark, dark's old value spills into medium+dark.
      'container:bbb' => [
        'default' => ['gap' => '1rem'],
        'dark' => ['gap' => '2rem'],
        'wide+dark' => ['gap' => '3rem'],
      ],
      // The compound lane WITHOUT its own scheme value: what rendered at
      // 768-1023px in dark was the default, so default is the spill source.
      'block:ccc' => [
        'default' => ['font-size' => '1rem'],
        'wide+dark' => ['font-size' => '2rem'],
      ],
    ]);
    $this->walkHosts();

    $this->assertSame([
      'block:aaa' => [
        'variants' => [['variant' => 'hero', 'fragment' => '']],
        'default' => ['color' => 'blue', 'padding' => '1rem', 'margin' => '2rem'],
        'narrow' => ['color' => 'green'],
        'medium' => ['color' => 'black'],
      ],
      'container:bbb' => [
        'default' => ['gap' => '1rem'],
        'dark' => ['gap' => '3rem'],
        'medium+dark' => ['gap' => '2rem'],
      ],
      'block:ccc' => [
        'default' => ['font-size' => '1rem'],
        'dark' => ['font-size' => '2rem'],
        'medium+dark' => ['font-size' => '1rem'],
      ],
    ], $this->styleMap(EntityTest::load($entity->id())));
  }

  /**
   * A user-authored medium value is never clobbered by the spill.
   *
   * Post-vocabulary data can carry real medium values beside still-inert
   * wide entries (authored after chunk 1 landed, before the data
   * migration ran); the user's medium wins over the spill.
   */
  public function testUserMediumWinsOverSpill(): void {
    $migration = $this->container->get('daedalus_stylish.width_band_migration');
    $lines = [];
    $migrated = $migration->migrateStyleMap([
      'block:x' => [
        'default' => ['color' => 'black'],
        'medium' => ['color' => 'red'],
        'wide' => ['color' => 'blue'],
      ],
    ], 'test', $lines);
    $this->assertSame([
      'block:x' => [
        'default' => ['color' => 'blue'],
        'medium' => ['color' => 'red'],
      ],
    ], $migrated);
  }

  /**
   * The fold reports loudly and converges: a second run is a no-op.
   */
  public function testReportsAndIdempotency(): void {
    $migration = $this->container->get('daedalus_stylish.width_band_migration');
    $lines = [];
    $migrated = $migration->migrateStyleMap([
      'block:x' => ['wide' => ['margin' => '2rem']],
    ], 'test', $lines);
    $this->assertSame(['block:x' => ['default' => ['margin' => '2rem']]], $migrated);
    // The wide-only property extends below 1024px: said loudly.
    $this->assertNotEmpty(array_filter($lines, static fn (string $line) => str_contains($line, 'every width')));

    $lines = [];
    $this->assertNull($migration->migrateStyleMap($migrated, 'test', $lines));
    $this->assertSame([], $lines);
  }

  /**
   * Variant styles fold across ALL revisions.
   */
  public function testVariantRevisionsFold(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Hero',
      'machine_name' => 'hero',
      'scope' => 'kind:container',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem'], 'wide' => ['padding' => '4rem']],
    ]);
    $variant->save();
    $first_revision_id = $variant->getRevisionId();

    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem'], 'wide' => ['padding' => '4rem']],
      '::.quote' => ['default' => ['color' => 'gray'], 'wide+dark' => ['color' => 'white']],
    ]);
    $variant->setNewRevision(TRUE);
    $variant->save();

    $this->container->get('daedalus_stylish.width_band_migration')->migrateVariants();
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $old */
    $old = $storage->loadRevision($first_revision_id);
    $this->assertSame([
      '' => ['default' => ['padding' => '4rem'], 'medium' => ['padding' => '2rem']],
    ], $old->getStyles());

    /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $current */
    $current = $storage->loadUnchanged($variant->id());
    $this->assertSame([
      '' => ['default' => ['padding' => '4rem'], 'medium' => ['padding' => '2rem']],
      '::.quote' => [
        'default' => ['color' => 'gray'],
        'dark' => ['color' => 'white'],
        'medium+dark' => ['color' => 'gray'],
      ],
    ], $current->getStyles());
  }

  /**
   * Skin tokens and defaults fold across ALL revisions.
   *
   * The container migration never covered skins; the width-band migration
   * must, because skins can store width-conditioned token values and
   * width-conditioned site default styles.
   */
  public function testSkinTokensAndDefaultsFold(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setTokens([
      'color' => [
        'accent' => ['default' => '#101', 'wide' => '#202', 'dark' => '#404', 'wide+dark' => '#303'],
        'plain' => '#000',
      ],
    ]);
    $skin->setDefaults([
      'kind:container' => [
        '' => ['default' => ['max-width' => '60rem'], 'wide' => ['max-width' => '80rem']],
      ],
    ]);
    $skin->save();
    $first_revision_id = $skin->getRevisionId();

    $skin->setTokens([
      'color' => ['accent' => ['default' => '#111', 'wide' => '#222']],
    ]);
    $skin->setNewRevision(TRUE);
    $skin->save();

    $this->container->get('daedalus_stylish.width_band_migration')->migrateSkins();
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $storage->resetCache();

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $old */
    $old = $storage->loadRevision($first_revision_id);
    $this->assertSame([
      'color' => [
        'accent' => ['default' => '#202', 'dark' => '#303', 'medium' => '#101', 'medium+dark' => '#404'],
        'plain' => '#000',
      ],
    ], $old->getTokens());
    $this->assertSame([
      'kind:container' => [
        '' => ['default' => ['max-width' => '80rem'], 'medium' => ['max-width' => '60rem']],
      ],
    ], $old->getDefaults());

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $current */
    $current = $storage->loadUnchanged($skin->id());
    $this->assertSame([
      'color' => ['accent' => ['default' => '#222', 'medium' => '#111']],
    ], $current->getTokens());
  }

  /**
   * Stored stencils fold recursively, entry order notwithstanding.
   */
  public function testStencilFold(): void {
    $stencil = Stencil::create([
      'label' => 'Legacy band',
      'machine_name' => 'legacy_band',
      'description' => 'Captured before the width-band fold.',
      'status' => TRUE,
    ]);
    $stencil->setElement([
      'layout_id' => 'daedalus_stylish_container',
      'styles' => [
        '' => ['default' => ['background-color' => 'aliceblue'], 'wide' => ['background-color' => 'white']],
      ],
      'regions' => [
        'content' => [
          [
            'block_type' => 'text',
            // Stored wide-first: the fold reads pre-fold values, so order
            // cannot corrupt the spill.
            'styles' => ['::.inner' => ['wide' => ['padding' => '2rem'], 'default' => ['padding' => '1rem']]],
          ],
        ],
      ],
    ]);
    $stencil->save();

    $lines = $this->container->get('daedalus_stylish.width_band_migration')->migrateStencils();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('stencil');
    $storage->resetCache();
    $component = $storage->loadUnchanged($stencil->id())->getElement();

    $this->assertSame([
      '' => ['default' => ['background-color' => 'white'], 'medium' => ['background-color' => 'aliceblue']],
    ], $component['styles']);
    $this->assertSame([
      '::.inner' => ['default' => ['padding' => '2rem'], 'medium' => ['padding' => '1rem']],
    ], $component['regions']['content'][0]['styles']);
  }

}
