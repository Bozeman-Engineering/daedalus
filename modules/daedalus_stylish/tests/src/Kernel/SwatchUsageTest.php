<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\SwatchUsage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_swatch_usage table and its three-store chokepoint.
 *
 * VariantUsage's hard twin: swatch references live INSIDE CSS VALUES —
 * var(--swatch-{name}) — across three stores (host style maps, variant
 * styles, skin tokens/defaults), so the table carries a source-kind column
 * and the save chokepoint listens on three entity families. Default
 * revisions only, rebuild parity, and the value-level grammar (scan and
 * rewrite, the two directions of one rule).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchUsageTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
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
   * The usage tracker under test.
   */
  protected SwatchUsage $usage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->usage = $this->container->get('daedalus_stylish.swatch_usage');
  }

  /**
   * Creates a saved swatch to count against.
   */
  protected function createSwatch(string $label, string|array $value = '#e94560'): DaedalusStylishSwatchInterface {
    $swatch = DaedalusStylishSwatch::create(['label' => $label, 'kind' => 'color']);
    $swatch->setSwatchValue($value);
    $swatch->save();
    return $swatch;
  }

  /**
   * Creates a saved map host referencing swatches in its values.
   */
  protected function createHost(array $map): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map),
    ]);
    $host->save();
    return $host;
  }

  /**
   * Creates a saved variant whose styles carry the given entries.
   */
  protected function createVariant(string $machine_name, array $styles): DaedalusStylishVariantInterface {
    $variant = DaedalusStylishVariant::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => 'kind:block',
    ]);
    $variant->setStyles($styles);
    $variant->save();
    return $variant;
  }

  /**
   * Creates a saved skin with the given tokens and defaults.
   */
  protected function createSkin(string $machine_name, array $tokens, array $defaults = []): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create(['label' => $machine_name, 'machine_name' => $machine_name]);
    $skin->setPackageTokens($tokens)->setPackageDefaults($defaults);
    $skin->save();
    return $skin;
  }

  /**
   * All usage table rows, ordered, for parity comparisons.
   */
  protected function allRows(): array {
    return $this->container->get('database')->select(SwatchUsage::TABLE, 'u')
      ->fields('u', ['swatch', 'source', 'entity_type', 'entity_id'])
      ->orderBy('u.swatch')
      ->orderBy('u.source')
      ->orderBy('u.entity_type')
      ->orderBy('u.entity_id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * The chokepoint: all three stores maintain the table on save and delete.
   */
  public function testChokepointTracking(): void {
    $red = $this->createSwatch('Brand red');
    $fade = $this->createSwatch('Hero fade');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    // A host referencing the swatch in two entries counts once, and a
    // value-level reference is found INSIDE a composed value, fallback and
    // whitespace tolerated.
    $host = $this->createHost([
      $key() => ['default' => ['background-image' => 'linear-gradient(var(--swatch-brand_red), #fff)']],
      $key() => ['hover' => ['color' => 'var( --swatch-brand_red , #000)']],
    ]);
    $this->assertSame(1, $this->usage->countReferences($red));

    // A variant's styles are the second store.
    $variant = $this->createVariant('card', ['' => ['default' => ['border-color' => 'var(--swatch-brand_red)']]]);
    $this->assertSame(2, $this->usage->countReferences($red));

    // A skin is the third: tokens AND defaults, merged as ONE source
    // entity (one row per referenced name, one entity to rewrite).
    $skin = $this->createSkin('site_skin',
      ['color' => ['accent' => ['default' => 'var(--swatch-brand_red)', 'dark' => '#000']]],
      ['kind:container' => ['' => ['default' => ['background-image' => 'var(--swatch-hero_fade)']]]],
    );
    $this->assertSame(3, $this->usage->countReferences($red));
    $this->assertSame(1, $this->usage->countReferences($fade));

    // Lookalikes are no ghosts: a var() outside the reserved namespace and
    // a machine name mentioned as plain text never count.
    $this->createHost([
      $key() => ['default' => ['color' => 'var(--swatchy-brand_red)', 'content' => '"brand_red"']],
    ]);
    $this->assertSame(3, $this->usage->countReferences($red));

    // The work list names every source once, source-kind attached, ordered.
    $this->assertSame([
      ['source' => 'map', 'entity_type' => 'entity_test', 'entity_id' => (string) $host->id()],
      ['source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
      ['source' => 'variant', 'entity_type' => 'daedalus_variant', 'entity_id' => (string) $variant->id()],
    ], $this->usage->references($red));

    // Removing the reference on save drops the rows, per store.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([$key() => ['default' => ['color' => '#111']]]));
    $host->save();
    $this->assertSame(2, $this->usage->countReferences($red));
    $variant->setStyles(['' => ['default' => ['border-color' => '#222']]]);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($red));

    // Deleting a source drops its rows.
    $skin->delete();
    $this->assertSame(0, $this->usage->countReferences($red));
    $this->assertSame(0, $this->usage->countReferences($fade));
    $this->assertSame([], $this->usage->references($red));
  }

  /**
   * Only default revisions are tracked: staging never moves the counts.
   */
  public function testDefaultRevisionsOnly(): void {
    $red = $this->createSwatch('Brand red');
    $key = 'block:' . $this->container->get('uuid')->generate();

    $host = EntityTestRev::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([$key => ['default' => ['color' => 'var(--swatch-brand_red)']]]),
    ]);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($red));

    // A pending (non-default) revision dropping the reference changes
    // nothing: the table mirrors the default revision the live custom
    // property serves.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $host->setNewRevision(TRUE);
    $host->isDefaultRevision(FALSE);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($red));

    // The same posture on the variant store: a staged revision gaining a
    // reference stays invisible until it becomes the default.
    $variant = $this->createVariant('card', ['' => ['default' => ['color' => '#111']]]);
    $variant->setStyles(['' => ['default' => ['color' => 'var(--swatch-brand_red)']]]);
    $variant->setNewRevision(TRUE);
    $variant->isDefaultRevision(FALSE);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($red));

    // A new DEFAULT revision dropping the host reference untracks it.
    $default = EntityTestRev::load($host->id());
    $default->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $default->setNewRevision(TRUE);
    $default->save();
    $this->assertSame(0, $this->usage->countReferences($red));
  }

  /**
   * Rebuild parity: rebuild() reproduces incremental maintenance exactly.
   */
  public function testRebuildParity(): void {
    $this->createSwatch('Brand red');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    $this->createHost([$key() => ['default' => ['color' => 'var(--swatch-brand_red)']]]);
    $this->createVariant('card', [
      '' => ['default' => ['color' => 'var(--swatch-brand_red)', 'background' => 'var(--swatch-hero_fade)']],
    ]);
    $this->createSkin('site_skin', ['color' => ['accent' => 'var(--swatch-brand_red)']]);
    // Sources without references contribute no rows.
    $this->createHost([$key() => ['default' => ['padding' => '2rem']]]);
    $this->createVariant('plain', ['' => ['default' => ['padding' => '1rem']]]);

    $maintained = $this->allRows();
    $this->assertCount(4, $maintained);

    // Corrupt the table both ways: a lost row and a ghost row.
    $database = $this->container->get('database');
    $database->delete(SwatchUsage::TABLE)->condition('source', 'variant')->execute();
    $database->insert(SwatchUsage::TABLE)->fields([
      'swatch' => 'ghost',
      'source' => 'map',
      'entity_type' => 'entity_test',
      'entity_id' => '999999',
    ])->execute();
    $this->assertNotSame($maintained, $this->allRows());

    // Rebuild restores exactly what the chokepoint had written.
    $this->assertSame(3, $this->usage->rebuild());
    $this->assertSame($maintained, $this->allRows());
  }

  /**
   * The resyncPublished(): the workspace-publish repair, per source family.
   */
  public function testResyncPublished(): void {
    $red = $this->createSwatch('Brand red');
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([$key => ['default' => ['color' => 'var(--swatch-brand_red)']]]);
    $variant = $this->createVariant('card', ['' => ['default' => ['color' => 'var(--swatch-brand_red)']]]);

    // Simulate the publish drift: the default revisions changed without
    // the save hooks firing, so the rows are stale.
    $this->container->get('database')->delete(SwatchUsage::TABLE)->execute();
    $this->assertSame(0, $this->usage->countReferences($red));

    // The publish payload names revision ids mapped to entity ids; only
    // source families are touched, unknown types skipped harmlessly.
    $this->usage->resyncPublished([
      'entity_test' => [1 => (string) $host->id()],
      'daedalus_variant' => [2 => (string) $variant->id()],
      'daedalus_swatch' => [3 => (string) $red->id()],
    ]);
    $this->assertSame(2, $this->usage->countReferences($red));
  }

  /**
   * The value grammar's two directions: the scan and the rewrite.
   */
  public function testValueGrammar(): void {
    // The scan: fallbacks, whitespace, duplicates, nested references, and
    // out-of-namespace variables.
    $this->assertSame(['brand_red'], SwatchUsage::valueSwatches('var(--swatch-brand_red)'));
    $this->assertSame(['brand_red'], SwatchUsage::valueSwatches('var( --swatch-brand_red , #fff)'));
    $this->assertSame(['a', 'b'], SwatchUsage::valueSwatches('linear-gradient(var(--swatch-a), var(--swatch-b))'));
    $this->assertSame(['x'], SwatchUsage::valueSwatches('var(--color-accent, var(--swatch-x))'));
    $this->assertSame([], SwatchUsage::valueSwatches('var(--swatchy-nope) var(--color-swatch)'));
    $this->assertSame([], SwatchUsage::valueSwatches('#e94560'));

    // The rewrite: the whole var() goes — fallback included, the reference
    // is gone, not unresolved — and everything else is byte-identical.
    $this->assertSame('#e94560', SwatchUsage::rewriteValue('var(--swatch-brand_red)', 'brand_red', '#e94560'));
    $this->assertSame('#e94560', SwatchUsage::rewriteValue('var( --swatch-brand_red , #000)', 'brand_red', '#e94560'));
    $this->assertSame(
      'linear-gradient(45deg, #e94560, var(--color-accent))',
      SwatchUsage::rewriteValue('linear-gradient(45deg, var(--swatch-brand_red, rgb(0, 0, 0)), var(--color-accent))', 'brand_red', '#e94560'),
    );
    // A fallback may itself reference the swatch: the outer var() stays,
    // its fallback rewrites.
    $this->assertSame(
      'var(--color-accent, #e94560)',
      SwatchUsage::rewriteValue('var(--color-accent, var(--swatch-brand_red))', 'brand_red', '#e94560'),
    );
    // Another swatch's reference is not touched.
    $this->assertSame(
      'var(--swatch-other)',
      SwatchUsage::rewriteValue('var(--swatch-other)', 'brand_red', '#e94560'),
    );
    // A malformed value (unbalanced parens) passes through verbatim.
    $this->assertSame(
      'var(--swatch-brand_red',
      SwatchUsage::rewriteValue('var(--swatch-brand_red', 'brand_red', '#e94560'),
    );
  }

}
