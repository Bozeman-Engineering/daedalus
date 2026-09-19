<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Controller\SwatchApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\SwatchValidationException;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\SwatchDeleteBatch;
use Drupal\daedalus_stylish\SwatchManager;
use Drupal\daedalus_stylish\SwatchUsage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the swatch gestures: mint, palette list, and the lifecycle.
 *
 * The variant gesture manager's twin at value scope: minting validates
 * through the value seam (kind shape, condition axes) and stores canonical
 * condition spellings; rename and revision restore are revisioned edits
 * that never touch identity (machine name, kind), so every var(--swatch-*)
 * reference survives; the distro lock makes swatches with a living disk
 * source read-only with revert-to-shipped as their one restore.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchManagerTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // The importer (the shipped-lock tests) switches to an administrative
    // account; give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * The manager, resolved late (module installs rebuild the container).
   */
  protected function manager(): SwatchManager {
    return \Drupal::service('daedalus_stylish.swatch_manager');
  }

  /**
   * Collects error codes keyed by path from a validation exception.
   */
  protected function catchErrors(callable $gesture): array {
    try {
      $gesture();
    }
    catch (SwatchValidationException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()][] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('Expected a SwatchValidationException.');
  }

  /**
   * Minting: value seam validation, minted name, published, payload shape.
   */
  public function testMint(): void {
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');

    $this->assertSame('brand_red', $swatch->getMachineName());
    $this->assertSame('color', $swatch->getKind());
    $this->assertSame('#e94560', $swatch->getSwatchValue());
    $this->assertTrue($swatch->isPublished());
    $this->assertNull($swatch->getProvider());

    $payload = $this->manager()->swatchPayload($swatch);
    $this->assertSame('brand_red', $payload['machine_name']);
    $this->assertSame($swatch->uuid(), $payload['uuid']);
    $this->assertSame('Brand red', $payload['label']);
    $this->assertSame('color', $payload['kind']);
    $this->assertSame('#e94560', $payload['value']);
    $this->assertSame('--swatch-brand_red', $payload['variable']);
    $this->assertNull($payload['provider']);
    // Site-minted: no disk source, so no shipped state and never a revert.
    $this->assertNull($payload['shipped_state']);

    // Condition-keyed values store CANONICAL compound spellings (part
    // order normalizes on write, the every-write-path posture).
    $fade = $this->manager()->mint('Hero fade', 'gradient', [
      'default' => 'linear-gradient(160deg, #1a1a2e, #e94560)',
      'dark+narrow' => 'linear-gradient(160deg, #0a0a14, #712235)',
    ]);
    $this->assertSame([
      'default' => 'linear-gradient(160deg, #1a1a2e, #e94560)',
      'narrow+dark' => 'linear-gradient(160deg, #0a0a14, #712235)',
    ], $fade->getSwatchValue());
  }

  /**
   * Minting refusals: the gesture's parameter space, seam codes included.
   */
  public function testMintValidation(): void {
    $errors = $this->catchErrors(fn () => $this->manager()->mint('  ', 'color', '#e94560'));
    $this->assertSame(['MISSING_PARAMETER'], $errors['label']);

    $errors = $this->catchErrors(fn () => $this->manager()->mint('Nope', 'sound', 'loud'));
    $this->assertSame(['SWATCH_KIND_UNKNOWN'], $errors['kind']);

    // The kind owns the value's shape: a color is not a gradient.
    $errors = $this->catchErrors(fn () => $this->manager()->mint('Not a gradient', 'gradient', '#e94560'));
    $this->assertSame(['INVALID_STYLE_VALUE'], $errors['value']);

    // A conditioned value without a default would be silently unresolved
    // outside the listed conditions.
    $errors = $this->catchErrors(fn () => $this->manager()->mint('No default', 'color', ['dark' => '#e8e8f0']));
    $this->assertSame(['SWATCH_DEFAULT_REQUIRED'], $errors['value']);

    // Nothing persisted: refusals happen before any save.
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('daedalus_swatch')->loadMultiple());
  }

  /**
   * Rename: revisioned, label-only, identity untouched.
   */
  public function testRename(): void {
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');
    $first_revision = (int) $swatch->getRevisionId();

    $this->manager()->update($swatch, 'Signal red');
    $this->assertSame('Signal red', (string) $swatch->label());
    // Identity never changes: every var(--swatch-brand_red) survives.
    $this->assertSame('brand_red', $swatch->getMachineName());
    $this->assertGreaterThan($first_revision, (int) $swatch->getRevisionId());
    $this->assertStringContainsString('Renamed', (string) $swatch->getRevisionLogMessage());

    // Renaming to the current label is a no-op, not a new revision.
    $revision_id = (int) $swatch->getRevisionId();
    $this->manager()->update($swatch, 'Signal red');
    $this->assertSame($revision_id, (int) $swatch->getRevisionId());

    $errors = $this->catchErrors(fn () => $this->manager()->update($swatch, ' '));
    $this->assertSame(['MISSING_PARAMETER'], $errors['label']);
  }

  /**
   * Update value: the variant push's twin at value scope (the live link).
   */
  public function testUpdateValue(): void {
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');
    $first_revision = (int) $swatch->getRevisionId();

    $updated = $this->manager()->update($swatch, NULL, '#111111');
    $this->assertSame('#111111', $updated->getSwatchValue());
    $this->assertGreaterThan($first_revision, (int) $updated->getRevisionId());
    $this->assertStringContainsString('Updated', (string) $updated->getRevisionLogMessage());
    // Identity never changes: the edit changes what the swatch SAYS.
    $this->assertSame('brand_red', $updated->getMachineName());
    $this->assertSame('color', $updated->getKind());

    // Condition-keyed values ride the same normalization as minting.
    $conditioned = $this->manager()->update($swatch, NULL, ['default' => '#222222', 'dark' => '#e8e8f0']);
    $this->assertSame(['default' => '#222222', 'dark' => '#e8e8f0'], $conditioned->getSwatchValue());

    // The value seam gates against the swatch's OWN kind — a color swatch
    // never quietly becomes a gradient.
    $errors = $this->catchErrors(fn () => $this->manager()->update($swatch, NULL, 'linear-gradient(90deg, red, gold)'));
    $this->assertArrayHasKey('value', $errors);
    $this->assertSame(['default' => '#222222', 'dark' => '#e8e8f0'], $this->loadSwatch('brand_red')->getSwatchValue());
  }

  /**
   * Revisions list and restore: a new default revision, never a rollback.
   */
  public function testRevisionsAndRestore(): void {
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');
    $first_revision = (int) $swatch->getRevisionId();

    // An ordinary revisioned value edit (what a value editor will do).
    $swatch->setSwatchValue('#b3364d');
    $swatch->setNewRevision(TRUE);
    $swatch->save();

    $revisions = $this->manager()->revisionsList($swatch);
    $this->assertCount(2, $revisions);
    // Newest first; only the default revision reads current.
    $this->assertSame([(int) $swatch->getRevisionId(), $first_revision], array_column($revisions, 'revision_id'));
    $this->assertSame([TRUE, FALSE], array_column($revisions, 'current'));

    // Restore lands the OLD content as a NEW revision on top: the restored
    // value is live (it IS the custom property's value again) and the
    // restore is itself one restore away from undo.
    $restored = $this->manager()->restoreRevision($swatch, $first_revision);
    $this->assertSame('#e94560', $restored->getSwatchValue());
    $this->assertGreaterThan((int) $swatch->getRevisionId(), (int) $restored->getRevisionId());
    $this->assertCount(3, $this->manager()->revisionsList($restored));

    $errors = $this->catchErrors(fn () => $this->manager()->restoreRevision($restored, 99999));
    $this->assertSame(['STYLE_SWATCH_REVISION_NOT_FOUND'], $errors['revision']);
  }

  /**
   * Delete: immediate for unreferenced swatches, on the base delete grade.
   */
  public function testDelete(): void {
    $this->setUpCurrentUser([], ['delete swatches']);
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');
    $this->assertSame(0, $this->manager()->deleteSwatch($swatch));
    $this->assertNull($this->manager()->loadSwatch('brand_red'));
  }

  /**
   * A referenced swatch refuses the base grade: no "delete anyway" exists.
   */
  public function testDeleteReferencedNeedsInUseGrade(): void {
    $swatch = $this->manager()->mint('Brand red', 'color', '#e94560');
    $this->createReferencingHost('brand_red');
    $this->setUpCurrentUser([], ['delete swatches']);

    $errors = $this->catchErrors(fn () => $this->manager()->deleteSwatch($swatch));
    $this->assertSame(['STYLE_SWATCH_IN_USE'], $errors['swatch']);
    $this->assertNotNull($this->manager()->loadSwatch('brand_red'));
  }

  /**
   * The referenced delete: batch staged, var() rewritten, then the entity goes.
   */
  public function testDeleteReferencedBatch(): void {
    // A CONDITIONED swatch pins the rewrite's literal rule: the DEFAULT
    // value is what every reference becomes; the conditioned nuance goes
    // with the swatch (host condition math cannot be synthesized safely).
    $swatch = $this->manager()->mint('Brand red', 'color', ['default' => '#e94560', 'dark' => '#111111']);
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $key => ['default' => ['background-image' => 'linear-gradient(var(--swatch-brand_red, #000), transparent)']],
      ]),
    ]);
    $host->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['border-color' => 'var(--swatch-brand_red)']]]);
    $variant->save();
    $skin = DaedalusStylishSkin::create(['label' => 'Site skin', 'machine_name' => 'site_skin']);
    $skin->setTokens(['color' => ['accent' => ['default' => 'var(--swatch-brand_red)', 'dark' => '#000']]]);
    $skin->setDefaults(['kind:container' => ['' => ['default' => ['color' => 'var(--swatch-brand_red)']]]]);
    $skin->save();

    // The gesture stages the Batch API job — one rewrite per referencing
    // source from the usage table's work list, then the delete — and
    // reports the loud count. Nothing is deleted in this request.
    $this->setUpCurrentUser([], ['delete swatches in use']);
    $this->assertSame(3, $this->manager()->deleteSwatch($swatch));
    $this->assertNotNull($this->manager()->loadSwatch('brand_red'));
    $batch = batch_get();
    $operations = $batch['sets'][0]['operations'] ?? [];
    $this->assertCount(4, $operations);
    $this->assertSame([SwatchDeleteBatch::class, 'rewriteSource'], $operations[0][0]);
    $this->assertSame([SwatchDeleteBatch::class, 'deleteSwatch'], $operations[3][0]);

    // Run the staged operations the way the batch runner would.
    $context = ['results' => []];
    foreach ($operations as [$callback, $arguments]) {
      if ($callback[1] === 'rewriteSource') {
        SwatchDeleteBatch::rewriteSource($arguments[0], $arguments[1], $arguments[2], $arguments[3], $context);
      }
      else {
        SwatchDeleteBatch::deleteSwatch($arguments[0], $context);
      }
    }

    // Every reference is the swatch's default literal now — the whole
    // var(), fallback included — in all three stores; the swatch and its
    // usage rows are gone.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame('linear-gradient(#e94560, transparent)', $map[$key]['default']['background-image']);
    $variant_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $variant_storage->resetCache([$variant->id()]);
    $this->assertSame(['' => ['default' => ['border-color' => '#e94560']]], $variant_storage->load($variant->id())->getStyles());
    $skin_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $skin_storage->resetCache([$skin->id()]);
    $fresh_skin = $skin_storage->load($skin->id());
    $this->assertSame(['color' => ['accent' => ['default' => '#e94560', 'dark' => '#000']]], $fresh_skin->getTokens());
    $this->assertSame(['kind:container' => ['' => ['default' => ['color' => '#e94560']]]], $fresh_skin->getDefaults());
    $this->assertNull($this->manager()->loadSwatch('brand_red'));
    $this->assertSame(3, $context['results']['rewritten']);
    $this->assertSame('Brand red', $context['results']['deleted']);
    $rows = (int) $this->container->get('database')->select(SwatchUsage::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame(0, $rows);
  }

  /**
   * The delete endpoint: the two grades and the batch handoff.
   */
  public function testDeleteEndpoint(): void {
    $controller = SwatchApiController::create($this->container);

    $this->setUpCurrentUser([], ['delete swatches']);
    $response = $controller->delete('missing');
    $this->assertSame(404, $response->getStatusCode());

    // Unreferenced: deleted in this request.
    $this->manager()->mint('Loner', 'color', '#123456');
    $response = $controller->delete('loner');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['deleted']);
    $this->assertSame(0, $payload['references']);
    // The route lane answers the materials delta every write carries: the
    // mint and the delete both touched the id, and the roster the delta
    // resolves against no longer has it, so the client store removes it.
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'swatch', 'id' => 'loner']]], $payload['materials']);

    // Referenced without the in-use grade: 403 with the corrective,
    // untouched.
    $this->manager()->mint('Brand red', 'color', '#e94560');
    $this->createReferencingHost('brand_red');
    $response = $controller->delete('brand_red');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STYLE_SWATCH_IN_USE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertNotNull($this->manager()->loadSwatch('brand_red'));

    // Referenced with the grade: 202 and the batch URL for the client to
    // drive.
    $this->setUpCurrentUser([], ['delete swatches in use']);
    $response = $controller->delete('brand_red');
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertSame(1, $payload['references']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    $this->assertNotNull($this->manager()->loadSwatch('brand_red'));
  }

  /**
   * The usage endpoint: the palette card's live count beside management facts.
   */
  public function testUsageEndpoint(): void {
    $controller = SwatchApiController::create($this->container);

    $response = $controller->usage('missing');
    $this->assertSame(404, $response->getStatusCode());

    $this->manager()->mint('Brand red', 'color', '#e94560');
    $this->createReferencingHost('brand_red');
    $response = $controller->usage('brand_red');
    $this->assertSame(200, $response->getStatusCode());
    // The management read: the live count plus the shipped facts (a
    // site-minted swatch has no provider and no shipped state).
    $this->assertSame(
      [
        'machine_name' => 'brand_red',
        'label' => 'Brand red',
        'usages' => 1,
        'provider' => NULL,
        'shipped_state' => NULL,
      ],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Creates a saved map host referencing the given swatch name.
   */
  protected function createReferencingHost(string $machine_name): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $this->container->get('uuid')->generate() => ['default' => ['color' => sprintf('var(--swatch-%s)', $machine_name)]],
      ]),
    ]);
    $host->save();
    return $host;
  }

  /**
   * The distro lock and revert: disk is the one author of shipped swatches.
   */
  public function testShippedLockAndRevert(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $shipped = $this->loadSwatch('brand_red');

    // Rename, value update, restore, and delete all refuse on a living
    // disk source.
    $errors = $this->catchErrors(fn () => $this->manager()->update($shipped, 'Mine now'));
    $this->assertSame(['STYLE_SWATCH_SHIPPED_LOCKED'], $errors['swatch']);
    $errors = $this->catchErrors(fn () => $this->manager()->update($shipped, NULL, '#0000ff'));
    $this->assertSame(['STYLE_SWATCH_SHIPPED_LOCKED'], $errors['swatch']);
    $errors = $this->catchErrors(fn () => $this->manager()->restoreRevision($shipped, (int) $shipped->getRevisionId()));
    $this->assertSame(['STYLE_SWATCH_SHIPPED_LOCKED'], $errors['swatch']);
    $errors = $this->catchErrors(fn () => $this->manager()->deleteSwatch($shipped));
    $this->assertSame(['STYLE_SWATCH_SHIPPED_LOCKED'], $errors['swatch']);

    // A user edit reads as modified (blueprint and gesture lanes refuse it;
    // direct entity writes are the AI/test escape hatch) and revert is the
    // convergence: the disk payload lands as an ordinary new revision and
    // the swatch reads unmodified again.
    $shipped->setSwatchValue('#123456');
    $shipped->setNewRevision(TRUE);
    $shipped->save();
    $this->assertSame('modified', $this->manager()->swatchPayload($shipped)['shipped_state']);
    $reverted = $this->manager()->revert($shipped);
    $this->assertSame('#e94560', $reverted->getSwatchValue());
    $this->assertFalse($reverted->isModified());
    $this->assertSame('unmodified', $this->manager()->swatchPayload($reverted)['shipped_state']);
    $this->assertStringContainsString('daedalus_stylish_swatches_test', (string) $reverted->getRevisionLogMessage());

    // Site-minted swatches have no disk defaults to revert to.
    $mine = $this->manager()->mint('Mine', 'color', '#654321');
    $errors = $this->catchErrors(fn () => $this->manager()->revert($mine));
    $this->assertSame(['STYLE_SWATCH_NOT_SHIPPED'], $errors['swatch']);

    // A provider that stopped shipping the entry releases the lock (the
    // swatch lives on as ordinary site content) and loses the revert.
    $reverted->set('provider', 'system');
    $reverted->save();
    $this->assertNull($this->manager()->swatchPayload($reverted)['shipped_state']);
    $this->manager()->update($reverted, 'Orphaned red');
    $errors = $this->catchErrors(fn () => $this->manager()->revert($reverted));
    $this->assertSame(['SHIPPED_DEFAULTS_MISSING'], $errors['swatch']);
  }

  /**
   * Loads a swatch by machine name, bypassing caches.
   */
  protected function loadSwatch(string $machine_name): ?DaedalusStylishSwatchInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_swatch');
    $storage->resetCache();
    $swatches = $storage->loadByProperties(['machine_name' => $machine_name]);
    $swatch = reset($swatches) ?: NULL;
    assert($swatch === NULL || $swatch instanceof DaedalusStylishSwatchInterface);
    return $swatch;
  }

  /**
   * The developer gate lifts the lock: shipped swatches edit in place.
   *
   * On a development site (`$settings['daedalus_blueprint_developer'] =
   * TRUE`, read live by DistroLock) the package author edits shipped
   * swatches through the gestures; the entity then reads as modified
   * against the disk and keeps its provider, so revert stays available.
   */
  public function testShippedEditsLandUnderTheDeveloperGate(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_swatches_test']);
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $shipped = $this->loadSwatch('brand_red');
    $this->assertSame('unmodified', $this->manager()->swatchPayload($shipped)['shipped_state']);

    $updated = $this->manager()->update($shipped, 'Brand crimson', '#0000ff');
    $this->assertSame('Brand crimson', (string) $updated->label());
    $this->assertSame('#0000ff', $updated->getSwatchValue());
    $this->assertSame('daedalus_stylish_swatches_test', $updated->getProvider());
    $this->assertSame('modified', $this->manager()->swatchPayload($this->loadSwatch('brand_red'))['shipped_state']);

    // Closing the gate again locks the (still shipped) swatch.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $errors = $this->catchErrors(fn () => $this->manager()->update($this->loadSwatch('brand_red'), NULL, '#00ff00'));
    $this->assertSame(['STYLE_SWATCH_SHIPPED_LOCKED'], $errors['swatch']);
  }

}
