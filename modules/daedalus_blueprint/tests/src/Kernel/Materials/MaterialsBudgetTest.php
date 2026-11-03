<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsWeights;
use Drupal\daedalus_blueprint\Materials\MaterialsWeightSourceInterface;
use Drupal\daedalus_blueprint_materials_test\TestGroupsContributor;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * The AI context budget: the reading sums its sources, the guard refuses full.
 *
 * The schema fragment is the shipped source; a stub source stands in for
 * the brief (daedalus_blueprint_ai's, registered under the same tag), so
 * the sum, the system figure, the estimate at the site's ratio and the
 * cache metadata are pinned over two sources. A tiny budget flips
 * `over`, and assertRoom() then speaks the one refusal (AI_CONTEXT_FULL,
 * the reading as context, the dialog's path in the hint); a budget
 * change re-derives the cached reading through the config's tag.
 */
#[CoversClass(MaterialsBudget::class)]
#[CoversClass(BudgetReading::class)]
#[CoversClass(MaterialsWeights::class)]
#[Group('daedalus_blueprint')]
class MaterialsBudgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_materials_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_blueprint']);
    TestMaterialsProvider::$records = NULL;
    TestGroupsContributor::$groups = [];
  }

  /**
   * A stub source: fixed weights (records, shared, system) and its own cache tag.
   */
  protected function stubSource(array $records, array $shared, int $system): MaterialsWeightSourceInterface {
    return new class($records, $shared, $system) implements MaterialsWeightSourceInterface {

      public function __construct(private array $records, private array $shared, private int $system) {}

      /**
       * {@inheritdoc}
       */
      public function weights(): MaterialsWeights {
        return new MaterialsWeights($this->records, $this->shared, $this->system);
      }

      /**
       * {@inheritdoc}
       */
      public function cacheMetadata(): CacheableMetadata {
        return (new CacheableMetadata())->addCacheTags(['stub_weight_source']);
      }

    };
  }

  /**
   * The reading is the sources' sum against the shipped budget.
   *
   * Summed at every level: a record the two sources both render (the
   * fixture's token, which the stub weighs too) adds up under its id, a
   * record only the stub renders rides beside the schema's, a kind's
   * shared adds up, and every kind's figure is its shared plus its
   * records' in the reading as in each source.
   */
  public function testReadingSumsSources(): void {
    $budget = $this->container->get('daedalus_blueprint.materials_budget');
    $budget->addSource($this->stubSource(
      [MaterialKind::Token->value => ['color/accent' => 60, 'stub_token' => 40], MaterialKind::Blueprint->value => ['hero' => 45]],
      [MaterialKind::Blueprint->value => 5],
      10,
    ));
    $schema = $this->container->get('daedalus_blueprint.materials_schema')->weights();

    $reading = $budget->reading();
    $this->assertSame($schema->of(MaterialKind::Token) + 100, $reading->of(MaterialKind::Token));
    $this->assertSame($schema->of(MaterialKind::Blueprint) + 50, $reading->of(MaterialKind::Blueprint));
    $this->assertSame($schema->of(MaterialKind::Variant), $reading->of(MaterialKind::Variant));
    $this->assertSame(0, $reading->of(MaterialKind::Font));
    $this->assertSame($schema->system + 10, $reading->system);
    $this->assertSame($schema->total() + 160, $reading->total);
    // Every kind is present in the shape, MaterialKind order.
    $kinds = array_map(static fn (MaterialKind $kind): string => $kind->value, MaterialKind::cases());
    $this->assertSame($kinds, array_keys($reading->characters));
    $this->assertSame($kinds, array_keys($reading->records));
    $this->assertSame($kinds, array_keys($reading->shared));

    // The records and the shared: summed by id and by kind, and a kind's
    // figure is its shared plus its records' everywhere.
    $this->assertGreaterThan(0, $schema->record(MaterialKind::Token, 'color/accent'));
    $this->assertSame($schema->record(MaterialKind::Token, 'color/accent') + 60, $reading->record(MaterialKind::Token, 'color/accent'));
    $this->assertSame(40, $reading->record(MaterialKind::Token, 'stub_token'));
    $this->assertSame([
      'color/accent' => $schema->record(MaterialKind::Token, 'color/accent') + 60,
      'color/lime-green' => $schema->record(MaterialKind::Token, 'color/lime-green'),
      'stub_token' => 40,
    ], $reading->records[MaterialKind::Token->value]);
    $this->assertSame($schema->record(MaterialKind::Blueprint, 'hero') + 45, $reading->record(MaterialKind::Blueprint, 'hero'));
    $this->assertSame($schema->shared[MaterialKind::Blueprint->value] + 5, $reading->shared[MaterialKind::Blueprint->value]);
    $this->assertSame($schema->shared[MaterialKind::Token->value], $reading->shared[MaterialKind::Token->value]);
    $this->assertSame([], $reading->records[MaterialKind::Font->value]);
    $this->assertSame(0, $reading->shared[MaterialKind::Font->value]);
    $this->assertSame(0, $reading->record(MaterialKind::Font, 'nothing'));
    foreach (MaterialKind::cases() as $kind) {
      $this->assertSame($schema->shared[$kind->value] + array_sum($schema->records[$kind->value]), $schema->of($kind), $kind->value);
      $this->assertSame($reading->shared[$kind->value] + array_sum($reading->records[$kind->value]), $reading->of($kind), $kind->value);
    }

    // The shipped budget and ratio; the estimate rounds up; the site
    // has room.
    $this->assertSame(MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_BUDGET], $reading->budgetTokens);
    $this->assertSame(MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_RATIO], $reading->charactersPerToken);
    $this->assertSame((int) ceil($reading->total / 4), $reading->estimatedTokens);
    $this->assertSame($reading->estimatedTokens / 24000, $reading->fraction);
    $this->assertFalse($reading->over);
    $budget->assertRoom();

    // The reading derives from both sources' facts and the config.
    $tags = $reading->getCacheTags();
    $this->assertContains('stub_weight_source', $tags);
    $this->assertContains('materials_test_list', $tags);
    $this->assertContains('config:daedalus_blueprint.settings', $tags);
    $this->assertContains('user.permissions', $reading->getCacheContexts());

    // The plain shape every consumer reads, the records and the shared
    // beside the kinds.
    $this->assertSame([
      'characters' => $reading->characters,
      'records' => $reading->records,
      'shared' => $reading->shared,
      'system' => $reading->system,
      'total' => $reading->total,
      'estimated_tokens' => $reading->estimatedTokens,
      'budget_tokens' => 24000,
      'characters_per_token' => 4,
      'fraction' => $reading->fraction,
      'over' => FALSE,
    ], $reading->toArray());
  }

  /**
   * A tiny budget flips over; the guard refuses with the one wording.
   */
  public function testTinyBudgetRefuses(): void {
    $budget = $this->container->get('daedalus_blueprint.materials_budget');
    $this->assertFalse($budget->reading()->over);

    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $reading = $budget->reading();
    $this->assertSame(1, $reading->budgetTokens);
    $this->assertTrue($reading->over);
    $this->assertGreaterThan(1.0, $reading->fraction);

    try {
      $budget->assertRoom('offered');
      $this->fail('A full site refuses.');
    }
    catch (MaterialGestureException $e) {
      $errors = $e->getErrors();
      $this->assertCount(1, $errors);
      $this->assertSame(MaterialsBudget::ERROR_CODE, $errors[0]->getCode());
      $this->assertSame('offered', $errors[0]->getPath());
      $this->assertStringContainsString('The AI context budget is full', $errors[0]->getMessage());
      $this->assertStringContainsString(number_format($reading->estimatedTokens) . ' tokens of 1', $errors[0]->getMessage());
      $this->assertStringContainsString(MaterialsBudget::DIALOG_PATH, $errors[0]->getHint());
      $this->assertStringContainsString('Offer to the AI', $errors[0]->getHint());
      $this->assertSame($reading->toArray(), $errors[0]->getContext());
    }

    // Exactly full is full; one token of room is room. The ratio is the
    // other lever: a ratio large enough makes the estimate fit again.
    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, $reading->estimatedTokens)->save();
    $this->assertTrue($budget->reading()->over);
    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, $reading->estimatedTokens + 1)->save();
    $this->assertFalse($budget->reading()->over);
    $budget->assertRoom();
    $this->config('daedalus_blueprint.settings')
      ->set(MaterialsBudget::KEY_BUDGET, 1)
      ->set(MaterialsBudget::KEY_RATIO, $reading->total)
      ->save();
    $this->assertSame(1, $budget->reading()->estimatedTokens);
    $this->assertTrue($budget->reading()->over);
  }

  /**
   * The weights are cached on the sources' tags; a roster change re-derives.
   */
  public function testReadingIsCachedOnTheRoster(): void {
    $budget = $this->container->get('daedalus_blueprint.materials_budget');
    $before = $budget->reading();
    $this->assertGreaterThan(0, $before->of(MaterialKind::Token));

    // Every token opted out: until its tag is invalidated, the cached
    // weights stand; after, the tokens weigh nothing.
    TestMaterialsProvider::$records = array_values(array_filter(
      TestMaterialsProvider::referenceRecords(),
      static fn ($record): bool => $record->kind !== MaterialKind::Token,
    ));
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->container->get('daedalus_blueprint.dialect_catalog_builder')->reset();
    $this->assertSame($before->of(MaterialKind::Token), $budget->reading()->of(MaterialKind::Token));
    $this->container->get('cache_tags.invalidator')->invalidateTags(['materials_test_list']);
    $this->assertSame(0, $budget->reading()->of(MaterialKind::Token));
  }

  /**
   * A site without the keys is told to run its updates, never read as zero.
   */
  public function testMissingBudgetIsLoud(): void {
    $this->config('daedalus_blueprint.settings')->clear(MaterialsBudget::KEY_BUDGET)->save();
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('run the module updates');
    $this->container->get('daedalus_blueprint.materials_budget')->reading();
  }

}
