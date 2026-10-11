<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\TokenUsage;
use Drupal\daedalus_stylish\ValueUsageBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_token_usage table and its three-store chokepoint.
 *
 * SwatchUsage's twin for the material that lives on the skin: a token
 * delivers as a custom property and is referenced as var() of it INSIDE
 * CSS VALUES across three stores (host style maps, variant styles, skin
 * tokens/defaults). The table stores the custom property verbatim (which
 * scale it belongs to is the guide's answer at lookup, never the row's),
 * leaves the swatch namespace to the swatch table, mirrors default
 * revisions only, rebuilds to parity, and the value-level grammar runs
 * both ways: the scan, the literal rewrite the delete batch runs, and
 * the respelling the rename batch runs.
 */
#[CoversClass(TokenUsage::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class TokenUsageTest extends KernelTestBase {

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
  protected TokenUsage $usage;

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
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->usage = $this->container->get('daedalus_stylish.token_usage');
  }

  /**
   * Creates a saved map host referencing tokens in its values.
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
   * Creates a saved skin with the given package tokens and defaults.
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
    return $this->container->get('database')->select(TokenUsage::TABLE, 'u')
      ->fields('u', ['token', 'source', 'entity_type', 'entity_id'])
      ->orderBy('u.token')
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
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    // A host referencing the token in two entries counts once, and a
    // value-level reference is found INSIDE a composed value, fallback and
    // whitespace tolerated. The row is the custom property verbatim.
    $host = $this->createHost([
      $key() => ['default' => ['padding' => 'calc(var(--space-4) * 2)']],
      $key() => ['hover' => ['margin' => 'var( --space-4 , 1rem)']],
    ]);
    $this->assertSame(1, $this->usage->countReferencesTo('--space-4'));

    // A variant's styles are the second store.
    $variant = $this->createVariant('card', ['' => ['default' => ['gap' => 'var(--space-4)']]]);
    $this->assertSame(2, $this->usage->countReferencesTo('--space-4'));

    // A skin is the third: tokens (a token may reference a token of its
    // skin) AND defaults, merged as ONE source entity, and its SITE layer
    // as stored beside the package's.
    $skin = $this->createSkin('site_skin',
      ['space' => ['double' => ['default' => 'calc(var(--space-4) * 2)', 'narrow' => 'var(--space-2)']]],
      ['kind:container' => ['' => ['default' => ['padding' => 'var(--space-8)']]]],
    );
    $this->assertSame(3, $this->usage->countReferencesTo('--space-4'));
    $this->assertSame(1, $this->usage->countReferencesTo('--space-2'));
    $this->assertSame(1, $this->usage->countReferencesTo('--space-8'));
    $skin->setSiteTokens(['space' => ['4' => ['value' => 'var(--space-6)']]]);
    $skin->setSiteDefaults(['kind:block' => ['' => ['default' => ['gap' => 'var(--space-6)']]]]);
    $skin->save();
    $this->assertSame(1, $this->usage->countReferencesTo('--space-6'), 'the site layer is scanned as stored');

    // The swatch namespace is the swatch table's: a swatch reference is
    // never a token row, and a name mentioned as plain text never counts.
    $this->createHost([
      $key() => ['default' => ['color' => 'var(--swatch-brand_red)', 'content' => '"--space-4"']],
    ]);
    $this->assertSame(0, $this->usage->countReferencesTo('--swatch-brand_red'));
    $this->assertSame(3, $this->usage->countReferencesTo('--space-4'));

    // The work list names every source once, source-kind attached, ordered.
    $this->assertSame([
      ['source' => 'map', 'entity_type' => 'entity_test', 'entity_id' => (string) $host->id()],
      ['source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
      ['source' => 'variant', 'entity_type' => 'daedalus_variant', 'entity_id' => (string) $variant->id()],
    ], $this->usage->referencesTo('--space-4'));

    // Removing the reference on save drops the rows, per store.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([$key() => ['default' => ['padding' => '2rem']]]));
    $host->save();
    $this->assertSame(2, $this->usage->countReferencesTo('--space-4'));
    $variant->setStyles(['' => ['default' => ['gap' => '1rem']]]);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferencesTo('--space-4'));

    // Deleting a source drops its rows.
    $skin->delete();
    $this->assertSame(0, $this->usage->countReferencesTo('--space-4'));
    $this->assertSame(0, $this->usage->countReferencesTo('--space-8'));
    $this->assertSame([], $this->usage->referencesTo('--space-4'));
  }

  /**
   * Only default revisions are tracked: staging never moves the counts.
   */
  public function testDefaultRevisionsOnly(): void {
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = EntityTestRev::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([$key => ['default' => ['padding' => 'var(--space-4)']]]),
    ]);
    $host->save();
    $this->assertSame(1, $this->usage->countReferencesTo('--space-4'));

    // A pending (non-default) revision dropping the reference changes
    // nothing: the table mirrors the default revision the live custom
    // property serves.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $host->setNewRevision(TRUE);
    $host->isDefaultRevision(FALSE);
    $host->save();
    $this->assertSame(1, $this->usage->countReferencesTo('--space-4'));

    // A new DEFAULT revision dropping the reference untracks it.
    $default = EntityTestRev::load($host->id());
    $default->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $default->setNewRevision(TRUE);
    $default->save();
    $this->assertSame(0, $this->usage->countReferencesTo('--space-4'));
  }

  /**
   * Rebuild parity: rebuild() reproduces incremental maintenance exactly.
   */
  public function testRebuildParity(): void {
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();
    $this->createHost([$key() => ['default' => ['padding' => 'var(--space-4)']]]);
    $this->createVariant('card', [
      '' => ['default' => ['gap' => 'var(--space-4)', 'color' => 'var(--color-accent)']],
    ]);
    $this->createSkin('site_skin', ['space' => ['double' => 'calc(var(--space-4) * 2)']]);
    // Sources without references contribute no rows.
    $this->createHost([$key() => ['default' => ['padding' => '2rem']]]);
    $this->createVariant('plain', ['' => ['default' => ['padding' => '1rem']]]);

    $maintained = $this->allRows();
    $this->assertCount(4, $maintained);

    // Corrupt the table both ways: a lost row and a ghost row.
    $database = $this->container->get('database');
    $database->delete(TokenUsage::TABLE)->condition('source', 'variant')->execute();
    $database->insert(TokenUsage::TABLE)->fields([
      'token' => '--ghost',
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
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([$key => ['default' => ['padding' => 'var(--space-4)']]]);
    $variant = $this->createVariant('card', ['' => ['default' => ['gap' => 'var(--space-4)']]]);
    $skin = $this->createSkin('site_skin', ['space' => ['double' => 'calc(var(--space-4) * 2)']]);

    // Simulate the publish drift: the default revisions changed without
    // the save hooks firing, so the rows are stale.
    $this->container->get('database')->delete(TokenUsage::TABLE)->execute();
    $this->assertSame(0, $this->usage->countReferencesTo('--space-4'));

    // The publish payload names revision ids mapped to entity ids; only
    // source families are touched, unknown types skipped harmlessly. The
    // skin subscriber runs this resync on publish.
    $this->container->get('daedalus_stylish.skin_workspace_subscriber')->onPostPublish(new class([
      'entity_test' => [1 => (string) $host->id()],
      'daedalus_variant' => [2 => (string) $variant->id()],
      'daedalus_skin' => [3 => (string) $skin->id()],
      'user' => [4 => '1'],
    ]) {

      public function __construct(private readonly array $published) {}

      /**
       * The WorkspacePostPublishEvent payload.
       */
      public function getPublishedRevisionIds(): array {
        return $this->published;
      }

    });
    $this->assertSame(3, $this->usage->countReferencesTo('--space-4'));
  }

  /**
   * The value grammar's directions: the scan, the literal rewrite, the respelling.
   */
  public function testValueGrammar(): void {
    // The scan: every custom property but the swatch namespace, verbatim,
    // fallbacks, whitespace, duplicates and nested references included.
    $this->assertSame(['--space-4'], TokenUsage::valueVariables('var(--space-4)'));
    $this->assertSame(['--space-4'], TokenUsage::valueVariables('var( --space-4 , 1rem)'));
    $this->assertSame(['--space-4', '--space-8'], TokenUsage::valueVariables('calc(var(--space-4) + var(--space-8))'));
    $this->assertSame(['--color-accent'], TokenUsage::valueVariables('var(--color-accent, var(--swatch-x))'));
    $this->assertSame(['--np-chrome'], TokenUsage::valueVariables('var(--np-chrome)'), 'an undeclared namespace is tracked; it matches no token at lookup');
    $this->assertSame([], TokenUsage::valueVariables('var(--swatch-brand_red)'));
    $this->assertSame([], TokenUsage::valueVariables('1rem'));

    // The literal rewrite (the delete batch's): the whole var() goes,
    // fallback included, and everything else is byte-identical; the
    // shared grammar the swatch rewrite runs through too.
    $this->assertSame('1rem', ValueUsageBase::rewriteVariable('var(--space-4)', '--space-4', '1rem'));
    $this->assertSame('1rem', ValueUsageBase::rewriteVariable('var( --space-4 , 2rem)', '--space-4', '1rem'));
    $this->assertSame(
      'calc(1rem * 2 + var(--space-8))',
      ValueUsageBase::rewriteVariable('calc(var(--space-4, var(--space-2)) * 2 + var(--space-8))', '--space-4', '1rem'),
    );
    $this->assertSame(
      'var(--space-8, 1rem)',
      ValueUsageBase::rewriteVariable('var(--space-8, var(--space-4))', '--space-4', '1rem'),
      'a fallback may itself reference the token: the outer var() stays, its fallback rewrites',
    );
    $this->assertSame('var(--space-40)', ValueUsageBase::rewriteVariable('var(--space-40)', '--space-4', '1rem'), 'a longer name is another token');
    $this->assertSame('var(--space-4', ValueUsageBase::rewriteVariable('var(--space-4', '--space-4', '1rem'), 'a malformed value passes through verbatim');

    // The respelling (the rename batch's): the property moves, the
    // fallback and everything else stay.
    $this->assertSame('var(--space-gutter)', TokenUsage::renameValue('var(--space-4)', '--space-4', '--space-gutter'));
    $this->assertSame('var( --space-gutter , 1rem)', TokenUsage::renameValue('var( --space-4 , 1rem)', '--space-4', '--space-gutter'));
    $this->assertSame(
      'calc(var(--space-gutter) + var(--space-40) + var(--space-8, var(--space-gutter)))',
      TokenUsage::renameValue('calc(var(--space-4) + var(--space-40) + var(--space-8, var(--space-4)))', '--space-4', '--space-gutter'),
    );
    $this->assertSame('--space-4', TokenUsage::renameValue('--space-4', '--space-4', '--space-gutter'), 'only a var() is a reference');
  }

}
