<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishFontInterface;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\FontDeleteBatch;
use Drupal\daedalus_stylish\FontFamilies;
use Drupal\daedalus_stylish\FontUsage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_font_usage table, its chokepoint and the batch.
 *
 * AnimationUsage's twin at family scope: a font is referenced INSIDE CSS
 * VALUES as the quoted family of a property-scoped font-family stack
 * across the three stores (host style maps, variant styles, skin
 * defaults), plus the skin's tokens on the scale the guide binds to
 * font-family (the suite binds it to the font scale), so the table
 * carries a source-kind column and the save chokepoint listens on three
 * entity families. Default revisions only, rebuild parity, the
 * value-level grammar (scan and rewrite, the two directions of one rule),
 * and the remove-the-family delete batch end to end.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FontUsageTest extends KernelTestBase {

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
  protected FontUsage $usage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
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
      'daedalus_font_usage',
      'daedalus_token_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->usage = $this->container->get('daedalus_stylish.font_usage');
  }

  /**
   * Creates a saved font to count against.
   */
  protected function createFont(string $family): DaedalusStylishFontInterface {
    $file = $this->container->get('daedalus_stylish.font_files')->fromBytes(strtolower(str_replace(' ', '-', $family)) . '.woff2', 'wOF2' . $family);
    $font = DaedalusStylishFont::create(['label' => $family, 'family' => $family]);
    $font->setFaces([['source' => ['file' => $file->uuid()]]]);
    $font->save();
    return $font;
  }

  /**
   * Creates a saved map host naming families in its values.
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
    return $this->container->get('database')->select(FontUsage::TABLE, 'u')
      ->fields('u', ['family', 'source', 'entity_type', 'entity_id'])
      ->orderBy('u.family')
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
    $inter = $this->createFont('Inter');
    $mono = $this->createFont('Mono');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();
    // The suite binds font-family to the font scale: its tokens are a
    // family source, a token has that property through the binding.
    $this->assertSame(['font'], $this->usage->fontScales());

    // A host naming the family in two entries counts once; a stack's
    // component and a sole quoted family both read.
    $host = $this->createHost([
      $key() => ['default' => ['font-family' => '"Inter", sans-serif']],
      $key() => ['hover' => ['font-family' => "'Inter'"]],
    ]);
    $this->assertSame(1, $this->usage->countReferences($inter));

    // A variant's styles are the second store.
    $variant = $this->createVariant('card', ['' => ['default' => ['font-family' => 'Georgia, "Inter"']]]);
    $this->assertSame(2, $this->usage->countReferences($inter));

    // A skin's DEFAULTS and its FONT-scale tokens are the third; a token
    // on any other scale is not a source (no property binds it).
    $skin = $this->createSkin('site_skin',
      [
        'font' => ['body' => '"Inter", system-ui', 'code' => ['default' => '"Mono"', 'dark' => '"Mono", monospace']],
        'transition' => ['fast' => '"Inter"'],
      ],
      ['base:h2' => ['' => ['default' => ['font-family' => '"Inter"']]]],
    );
    $this->assertSame(3, $this->usage->countReferences($inter));
    $this->assertSame(1, $this->usage->countReferences($mono));

    // Lookalikes are no ghosts: an unquoted family (a theme's or the
    // system's own), a family in a non-family property, a var() stack
    // and the machine name as plain text never count.
    $this->createHost([
      $key() => [
        'default' => [
          'font-family' => 'Inter, sans-serif',
          'content' => '"Inter"',
          'animation-name' => 'inter',
        ],
        'hover' => ['font-family' => 'var(--font-body)'],
      ],
    ]);
    $this->assertSame(3, $this->usage->countReferences($inter));

    // The work list names every source once, source-kind attached, ordered.
    $this->assertSame([
      ['source' => 'map', 'entity_type' => 'entity_test', 'entity_id' => (string) $host->id()],
      ['source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
      ['source' => 'variant', 'entity_type' => 'daedalus_variant', 'entity_id' => (string) $variant->id()],
    ], $this->usage->references($inter));

    // Removing the reference on save drops the rows, per store.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([$key() => ['default' => ['font-family' => 'sans-serif']]]));
    $host->save();
    $this->assertSame(2, $this->usage->countReferences($inter));
    $variant->setStyles(['' => ['default' => ['color' => '#222']]]);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($inter));

    // Deleting a source drops its rows.
    $skin->delete();
    $this->assertSame(0, $this->usage->countReferences($inter));
    $this->assertSame(0, $this->usage->countReferences($mono));
    $this->assertSame([], $this->usage->references($inter));
  }

  /**
   * Only default revisions are tracked: staging never moves the counts.
   */
  public function testDefaultRevisionsOnly(): void {
    $inter = $this->createFont('Inter');
    $key = 'block:' . $this->container->get('uuid')->generate();

    $host = EntityTestRev::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([$key => ['default' => ['font-family' => '"Inter"']]]),
    ]);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($inter));

    // A pending (non-default) revision dropping the reference changes
    // nothing: the table mirrors the default revision the live file serves.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $host->setNewRevision(TRUE);
    $host->isDefaultRevision(FALSE);
    $host->save();
    $this->assertSame(1, $this->usage->countReferences($inter));

    // The same posture on the variant store: a staged revision gaining a
    // reference stays invisible until it becomes the default.
    $variant = $this->createVariant('card', ['' => ['default' => ['color' => '#111']]]);
    $variant->setStyles(['' => ['default' => ['font-family' => '"Inter"']]]);
    $variant->setNewRevision(TRUE);
    $variant->isDefaultRevision(FALSE);
    $variant->save();
    $this->assertSame(1, $this->usage->countReferences($inter));

    // A new DEFAULT revision dropping the host reference untracks it.
    $default = EntityTestRev::load($host->id());
    $default->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $default->setNewRevision(TRUE);
    $default->save();
    $this->assertSame(0, $this->usage->countReferences($inter));
  }

  /**
   * Rebuild parity: rebuild() reproduces incremental maintenance exactly.
   */
  public function testRebuildParity(): void {
    $this->createFont('Inter');
    $key = fn (): string => 'block:' . $this->container->get('uuid')->generate();

    $this->createHost([$key() => ['default' => ['font-family' => '"Inter", sans-serif']]]);
    $this->createVariant('card', [
      '' => ['default' => ['font-family' => '"Inter"', 'transition' => 'opacity 1s']],
    ]);
    $this->createSkin('site_skin', ['font' => ['body' => '"Mono", monospace']], ['kind:block' => ['' => ['default' => ['font-family' => '"Inter"']]]]);
    // Sources without references contribute no rows.
    $this->createHost([$key() => ['default' => ['padding' => '2rem']]]);
    $this->createVariant('plain', ['' => ['default' => ['font-family' => 'serif']]]);

    $maintained = $this->allRows();
    $this->assertCount(4, $maintained);

    // Corrupt the table both ways: a lost row and a ghost row.
    $database = $this->container->get('database');
    $database->delete(FontUsage::TABLE)->condition('source', 'variant')->execute();
    $database->insert(FontUsage::TABLE)->fields([
      'family' => 'Ghost',
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
    $inter = $this->createFont('Inter');
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([$key => ['default' => ['font-family' => '"Inter"']]]);
    $variant = $this->createVariant('card', ['' => ['default' => ['font-family' => '"Inter", serif']]]);

    // Simulate the publish drift: the default revisions changed without
    // the save hooks firing, so the rows are stale.
    $this->container->get('database')->delete(FontUsage::TABLE)->execute();
    $this->assertSame(0, $this->usage->countReferences($inter));

    // The publish payload names revision ids mapped to entity ids; only
    // source families are touched, unknown types skipped harmlessly.
    $this->usage->resyncPublished([
      'entity_test' => [1 => (string) $host->id()],
      'daedalus_variant' => [2 => (string) $variant->id()],
      'daedalus_font' => [3 => (string) $inter->id()],
    ]);
    $this->assertSame(2, $this->usage->countReferences($inter));
  }

  /**
   * The value grammar's two directions: the scan and the rewrite.
   */
  public function testValueGrammar(): void {
    // The scan: property-scoped, quoted components only, deduplicated,
    // both quote kinds, escapes unescaped, functions and idents never names.
    $this->assertSame(['Inter'], FontUsage::valueFamilies('font-family', '"Inter"'));
    $this->assertSame(['Inter', 'Source Serif'], FontUsage::valueFamilies('font-family', ' "Inter" , Georgia, \'Source Serif\', "Inter", serif'));
    $this->assertSame(['Say "Hi"'], FontUsage::valueFamilies('font-family', '"Say \\"Hi\\""'));
    $this->assertSame([], FontUsage::valueFamilies('font-family', 'Inter, sans-serif'));
    $this->assertSame([], FontUsage::valueFamilies('font-family', 'var(--font-body, "Inter")'));
    $this->assertSame([], FontUsage::valueFamilies('font-family', '"Inter'));
    $this->assertSame([], FontUsage::valueFamilies('font-family', '""'));
    $this->assertSame([], FontUsage::valueFamilies('content', '"Inter"'));
    $this->assertSame([], FontUsage::valueFamilies('font', '1rem "Inter"'));
    $this->assertSame([], FontFamilies::inValue('font-family', 'inherit'));

    // The rewrite: the family's components leave the stack, every other
    // byte survives, an emptied stack is the initial value.
    $this->assertSame('sans-serif', FontUsage::rewriteValue('font-family', '"Inter", sans-serif', 'Inter'));
    $this->assertSame('Georgia, serif', FontUsage::rewriteValue('font-family', 'Georgia, "Inter", serif', 'Inter'));
    $this->assertSame('Georgia,  serif', FontUsage::rewriteValue('font-family', 'Georgia,  "Inter",  serif', 'Inter'));
    $this->assertSame('Georgia', FontUsage::rewriteValue('font-family', 'Georgia, \'Inter\', "Inter"', 'Inter'));
    $this->assertSame('initial', FontUsage::rewriteValue('font-family', '"Inter"', 'Inter'));
    $this->assertSame('initial', FontUsage::rewriteValue('font-family', ' "Inter" , "Inter" ', 'Inter'));
    // Another family, an unquoted spelling, and a non-family property
    // are not touched.
    $this->assertSame('"Mono", monospace', FontUsage::rewriteValue('font-family', '"Mono", monospace', 'Inter'));
    $this->assertSame('Inter, sans-serif', FontUsage::rewriteValue('font-family', 'Inter, sans-serif', 'Inter'));
    $this->assertSame('"Inter"', FontUsage::rewriteValue('content', '"Inter"', 'Inter'));
    // A var() fallback is a function, never a component of the stack.
    $this->assertSame('var(--font-body, "Inter")', FontUsage::rewriteValue('font-family', 'var(--font-body, "Inter")', 'Inter'));
  }

  /**
   * The delete batch removes the family from every stack, then deletes.
   *
   * The Batch API job FontManager stages for a named font, run here the
   * way the batch runner would: one rewrite per referencing source from
   * the usage table's work list (each an ordinary revisioned save
   * re-entering the chokepoint), then the sweep-and-delete.
   */
  public function testDeleteBatch(): void {
    $inter = $this->createFont('Inter');
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = $this->createHost([
      $key => [
        'default' => ['font-family' => '"Inter", sans-serif', 'font-size' => '1rem'],
        'hover' => ['font-family' => '"Inter"'],
      ],
    ]);
    $variant = $this->createVariant('card', ['' => ['hover' => ['font-family' => 'Georgia, "Inter"']]]);
    $skin = $this->createSkin('site_skin',
      ['font' => ['body' => '"Inter", system-ui', 'code' => ['default' => '"Mono"', 'dark' => '"Inter", "Mono"']]],
      ['kind:container' => ['' => ['default' => ['font-family' => '"Inter"']]]],
    );
    $references = $this->usage->references($inter);
    $this->assertCount(3, $references);

    $context = ['results' => []];
    foreach ($references as $reference) {
      FontDeleteBatch::rewriteSource((int) $inter->id(), $reference['source'], $reference['entity_type'], $reference['entity_id'], $context);
    }
    FontDeleteBatch::deleteFont((int) $inter->id(), $context);

    // Every stack lost the family, in place, in all three stores and the
    // font-scale tokens; the other components survive; the font and its
    // usage rows are gone.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame(['font-family' => 'sans-serif', 'font-size' => '1rem'], $map[$key]['default']);
    $this->assertSame(['font-family' => 'initial'], $map[$key]['hover']);
    $variant_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $variant_storage->resetCache([$variant->id()]);
    $this->assertSame(['' => ['hover' => ['font-family' => 'Georgia']]], $variant_storage->load($variant->id())->getStyles());
    $skin_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $skin_storage->resetCache([$skin->id()]);
    $rewritten = $skin_storage->load($skin->id());
    $this->assertSame(['kind:container' => ['' => ['default' => ['font-family' => 'initial']]]], $rewritten->packageDefaults());
    $this->assertSame('system-ui', $rewritten->packageTokens()['font']['body']['value']);
    $this->assertSame(['default' => '"Mono"', 'dark' => '"Mono"'], $rewritten->packageTokens()['font']['code']['value']);
    $this->assertNull(DaedalusStylishFont::load($inter->id()));
    $this->assertSame(3, $context['results']['rewritten']);
    $this->assertSame('Inter', $context['results']['deleted']);
    // The rewritten saves re-entered the chokepoint: the deleted family
    // has no rows left, and the skin's untouched "Mono" reference is the
    // one row remaining (a dangling name, tracked like any other).
    $this->assertSame([
      ['family' => 'Mono', 'source' => 'skin', 'entity_type' => 'daedalus_skin', 'entity_id' => (string) $skin->id()],
    ], $this->allRows());
  }

}
