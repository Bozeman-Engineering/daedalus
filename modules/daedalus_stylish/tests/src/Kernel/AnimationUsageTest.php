<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationDeleteBatch;
use Drupal\daedalus_stylish\AnimationUsage;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_animation_usage table, its chokepoint and the batch.
 *
 * SwatchUsage's twin at ident scope: animation references live INSIDE CSS
 * VALUES as a property-scoped keyframes name (animation-name, or a name
 * component of the animation shorthand) across three stores (host style
 * maps, variant styles, skin defaults), so the table carries a source-kind
 * column and the save chokepoint listens on three entity families.
 * Default revisions only, rebuild parity, the value-level grammar (scan
 * and rewrite, the two directions of one rule), and the rewrite-to-none
 * delete batch end to end.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationUsageTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The usage tracker under test.
   */
  protected AnimationUsage $usage;

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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->usage = $this->container->get('daedalus_stylish.animation_usage');
  }

  /**
   * Creates a saved animation to count against.
   */
  protected function createAnimation(string $label): DaedalusStylishAnimationInterface {
    $animation = DaedalusStylishAnimation::create(['label' => $label]);
    $animation->setSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);
    $animation->save();
    return $animation;
  }

  /**
   * Creates a saved map host wearing animations in its values.
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
    $skin->setTokens($tokens)->setDefaults($defaults);
    $skin->save();
    return $skin;
  }

  /**
   * All usage table rows, ordered, for parity comparisons.
   */
  protected function allRows(): array {
    return $this->container->get('database')->select(AnimationUsage::TABLE, 'u')
      ->fields('u', ['animation', 'source', 'entity_type', 'entity_id'])
      ->orderBy('u.animation')
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
    $fade = $this->createAnimation('Fade in');
    $rise = $this->createAnimation('Rise');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    // A host wearing the animation in two entries counts once; the
    // shorthand's name component and a multi-name list both read.
    $host = $this->createHost([
      $key() => ['default' => ['animation' => 'motion-fade_in 600ms ease-out both']],
      $key() => ['hover' => ['animation-name' => 'spin, motion-fade_in']],
    ]);
    $this->assertSame(1, $this->usage->countReferences($fade));

    // A variant's styles are the second store.
    $variant = $this->createVariant('card', ['' => ['default' => ['animation-name' => 'motion-fade_in']]]);
    $this->assertSame(2, $this->usage->countReferences($fade));

    // A skin's DEFAULTS are the third; its tokens are not a source (a
    // token value has no property, so it cannot carry a keyframes name).
    $skin = $this->createSkin('site_skin',
      ['transition' => ['fast' => 'motion-fade_in']],
      ['kind:container' => ['' => ['default' => ['animation' => 'motion-fade_in 1s, motion-rise 2s']]]],
    );
    $this->assertSame(3, $this->usage->countReferences($fade));
    $this->assertSame(1, $this->usage->countReferences($rise));

    // Lookalikes are no ghosts: a name outside the motion namespace (a
    // theme's keyframes), the name in a non-motion property, and the
    // machine name as plain text never count.
    $this->createHost([
      $key() => [
        'default' => [
          'animation-name' => 'fade_in',
          'transition-property' => 'motion-fade_in',
          'content' => '"motion-fade_in"',
        ],
      ],
    ]);
    $this->assertSame(3, $this->usage->countReferences($fade));

    // The work list names every source once, source-kind attached, ordered.
    $this->assertSame([
      ['source' => 'map', 'entity_type' => 'entity_test', 'entity_id' => (string) $host->id()],
      ['source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
      ['source' => 'variant', 'entity_type' => 'daedalus_variant', 'entity_id' => (string) $variant->id()],
    ], $this->usage->references($fade));

    // Removing the reference on save drops the rows, per store.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([$key() => ['default' => ['animation-name' => 'none']]]));
    $host->save();
    $this->assertSame(2, $this->usage->countReferences($fade));
    $variant->setStyles(['' => ['default' => ['color' => '#222']]]);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($fade));

    // Deleting a source drops its rows.
    $skin->delete();
    $this->assertSame(0, $this->usage->countReferences($fade));
    $this->assertSame(0, $this->usage->countReferences($rise));
    $this->assertSame([], $this->usage->references($fade));
  }

  /**
   * Only default revisions are tracked: staging never moves the counts.
   */
  public function testDefaultRevisionsOnly(): void {
    $fade = $this->createAnimation('Fade in');
    $key = 'block:' . $this->container->get('uuid')->generate();

    $host = EntityTestRev::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([$key => ['default' => ['animation-name' => 'motion-fade_in']]]),
    ]);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($fade));

    // A pending (non-default) revision dropping the reference changes
    // nothing: the table mirrors the default revision the live keyframes
    // serve.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $host->setNewRevision(TRUE);
    $host->isDefaultRevision(FALSE);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($fade));

    // The same posture on the variant store: a staged revision gaining a
    // reference stays invisible until it becomes the default.
    $variant = $this->createVariant('card', ['' => ['default' => ['color' => '#111']]]);
    $variant->setStyles(['' => ['default' => ['animation-name' => 'motion-fade_in']]]);
    $variant->setNewRevision(TRUE);
    $variant->isDefaultRevision(FALSE);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($fade));

    // A new DEFAULT revision dropping the host reference untracks it.
    $default = EntityTestRev::load($host->id());
    $default->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $default->setNewRevision(TRUE);
    $default->save();
    $this->assertSame(0, $this->usage->countReferences($fade));
  }

  /**
   * Rebuild parity: rebuild() reproduces incremental maintenance exactly.
   */
  public function testRebuildParity(): void {
    $this->createAnimation('Fade in');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    $this->createHost([$key() => ['default' => ['animation-name' => 'motion-fade_in']]]);
    $this->createVariant('card', [
      '' => ['default' => ['animation' => 'motion-fade_in 1s', 'transition' => 'opacity 1s']],
    ]);
    $this->createSkin('site_skin', [], ['kind:block' => ['' => ['default' => ['animation-name' => 'motion-rise']]]]);
    // Sources without references contribute no rows.
    $this->createHost([$key() => ['default' => ['padding' => '2rem']]]);
    $this->createVariant('plain', ['' => ['default' => ['animation-name' => 'none']]]);

    $maintained = $this->allRows();
    $this->assertCount(3, $maintained);

    // Corrupt the table both ways: a lost row and a ghost row.
    $database = $this->container->get('database');
    $database->delete(AnimationUsage::TABLE)->condition('source', 'variant')->execute();
    $database->insert(AnimationUsage::TABLE)->fields([
      'animation' => 'ghost',
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
    $fade = $this->createAnimation('Fade in');
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([$key => ['default' => ['animation-name' => 'motion-fade_in']]]);
    $variant = $this->createVariant('card', ['' => ['default' => ['animation' => 'motion-fade_in 1s']]]);

    // Simulate the publish drift: the default revisions changed without
    // the save hooks firing, so the rows are stale.
    $this->container->get('database')->delete(AnimationUsage::TABLE)->execute();
    $this->assertSame(0, $this->usage->countReferences($fade));

    // The publish payload names revision ids mapped to entity ids; only
    // source families are touched, unknown types skipped harmlessly.
    $this->usage->resyncPublished([
      'entity_test' => [1 => (string) $host->id()],
      'daedalus_variant' => [2 => (string) $variant->id()],
      'daedalus_animation' => [3 => (string) $fade->id()],
    ]);
    $this->assertSame(2, $this->usage->countReferences($fade));
  }

  /**
   * The value grammar's two directions: the scan and the rewrite.
   */
  public function testValueGrammar(): void {
    // The scan: property-scoped, namespace-gated, deduplicated, quoted
    // spellings included, keywords and functions never names.
    $this->assertSame(['fade_in'], AnimationUsage::valueAnimations('animation-name', 'motion-fade_in'));
    $this->assertSame(['fade_in', 'rise'], AnimationUsage::valueAnimations('animation-name', ' motion-fade_in , spin, motion-rise, none'));
    $this->assertSame(['fade_in'], AnimationUsage::valueAnimations('animation', '600ms ease-out both motion-fade_in, motion-fade_in 1s'));
    $this->assertSame(['fade_in'], AnimationUsage::valueAnimations('animation', '"motion-fade_in" 1s infinite'));
    $this->assertSame([], AnimationUsage::valueAnimations('animation', 'spin 1s infinite linear'));
    $this->assertSame([], AnimationUsage::valueAnimations('animation-name', 'var(--motion)'));
    $this->assertSame([], AnimationUsage::valueAnimations('transition-property', 'motion-fade_in'));
    $this->assertSame([], AnimationUsage::valueAnimations('animation-duration', 'motion-fade_in'));

    // The rewrite: the name component becomes `none` in place, in either
    // grammar and any position; everything else is byte-identical.
    $this->assertSame('none', AnimationUsage::rewriteValue('animation-name', 'motion-fade_in', 'fade_in'));
    $this->assertSame(' none , spin, motion-rise', AnimationUsage::rewriteValue('animation-name', ' motion-fade_in , spin, motion-rise', 'fade_in'));
    $this->assertSame('none 600ms ease-out both', AnimationUsage::rewriteValue('animation', 'motion-fade_in 600ms ease-out both', 'fade_in'));
    $this->assertSame(
      '1s cubic-bezier(0.4, 0, 0.2, 1) none, spin 2s, none 3s',
      AnimationUsage::rewriteValue('animation', '1s cubic-bezier(0.4, 0, 0.2, 1) motion-fade_in, spin 2s, "motion-fade_in" 3s', 'fade_in'),
    );
    // Another animation's reference, a theme keyframes, and a non-motion
    // property are not touched.
    $this->assertSame('motion-rise 1s', AnimationUsage::rewriteValue('animation', 'motion-rise 1s', 'fade_in'));
    $this->assertSame('fade_in', AnimationUsage::rewriteValue('animation-name', 'fade_in', 'fade_in'));
    $this->assertSame('motion-fade_in', AnimationUsage::rewriteValue('transition-property', 'motion-fade_in', 'fade_in'));
    // A malformed value passes through, the name still rewritten.
    $this->assertSame('none,,none', AnimationUsage::rewriteValue('animation-name', 'motion-fade_in,,motion-fade_in', 'fade_in'));
  }

  /**
   * The delete batch rewrites every reference to `none`, then deletes.
   *
   * The Batch API job AnimationManager stages for a worn animation, run
   * here the way the batch runner would: one rewrite per referencing
   * source from the usage table's work list (each an ordinary revisioned
   * save re-entering the chokepoint), then the sweep-and-delete.
   */
  public function testDeleteBatch(): void {
    $fade = $this->createAnimation('Fade in');
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([
      $key => [
        'default' => ['animation' => 'motion-fade_in 600ms ease-out both', 'animation-name' => 'spin, motion-fade_in'],
      ],
    ]);
    $variant = $this->createVariant('card', ['' => ['hover' => ['animation-name' => 'motion-fade_in']]]);
    $skin = $this->createSkin('site_skin', [], ['kind:container' => ['' => ['default' => ['animation' => 'motion-fade_in 1s, motion-rise 2s']]]]);
    $references = $this->usage->references($fade);
    $this->assertCount(3, $references);

    $context = ['results' => []];
    foreach ($references as $reference) {
      AnimationDeleteBatch::rewriteSource((int) $fade->id(), $reference['source'], $reference['entity_type'], $reference['entity_id'], $context);
    }
    AnimationDeleteBatch::deleteAnimation((int) $fade->id(), $context);

    // Every reference is `none` now, in place, in all three stores; the
    // other names and the timing declarations survive; the animation and
    // its usage rows are gone.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame(['animation' => 'none 600ms ease-out both', 'animation-name' => 'spin, none'], $map[$key]['default']);
    $variant_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $variant_storage->resetCache([$variant->id()]);
    $this->assertSame(['' => ['hover' => ['animation-name' => 'none']]], $variant_storage->load($variant->id())->getStyles());
    $skin_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $skin_storage->resetCache([$skin->id()]);
    $this->assertSame(['kind:container' => ['' => ['default' => ['animation' => 'none 1s, motion-rise 2s']]]], $skin_storage->load($skin->id())->getDefaults());
    $this->assertNull(DaedalusStylishAnimation::load($fade->id()));
    $this->assertSame(3, $context['results']['rewritten']);
    $this->assertSame('Fade in', $context['results']['deleted']);
    // The rewritten saves re-entered the chokepoint: the deleted name has
    // no rows left, and the skin's untouched `motion-rise` reference is
    // the one row remaining (a dangling name, tracked like any other).
    $this->assertSame([
      ['animation' => 'rise', 'source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
    ], $this->allRows());
  }

}
