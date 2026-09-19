<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationDeleteBatch;
use Drupal\daedalus_stylish\AnimationManager;
use Drupal\daedalus_stylish\AnimationStylesheet;
use Drupal\daedalus_stylish\AnimationUsage;
use Drupal\daedalus_stylish\Controller\AnimationApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\AnimationValidationException;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the animation gestures: mint, the lifecycle, and the routes.
 *
 * The swatch gesture manager's twin at structure scope: minting validates
 * through the steps seam (offset grammar, step declarations) and stores
 * canonical sorted offsets; rename, description and step edits and
 * revision restore are revisioned edits that never touch identity
 * (machine name), so every motion-{name} reference survives; the distro
 * lock makes animations with a living disk source read-only with
 * revert-to-shipped as their one restore; a worn animation deletes only
 * through the rewrite-to-none batch behind the in-use grade.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationManagerTest extends KernelTestBase {

  use UserCreationTrait;

  protected const FADE = ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']];

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
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // The importer (the shipped-lock tests) switches to an administrative
    // account; give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * The manager, resolved late (module installs rebuild the container).
   */
  protected function manager(): AnimationManager {
    return \Drupal::service('daedalus_stylish.animation_manager');
  }

  /**
   * Collects error codes keyed by path from a validation exception.
   */
  protected function catchErrors(callable $gesture): array {
    try {
      $gesture();
    }
    catch (AnimationValidationException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()][] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('Expected an AnimationValidationException.');
  }

  /**
   * Minting: steps seam validation, minted name, published, payload shape.
   */
  public function testMint(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE, 'A soft entrance.');

    $this->assertSame('fade_in', $animation->getMachineName());
    // Offsets store canonical and sorted whatever the write spelled.
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $animation->getSteps());
    $this->assertSame('A soft entrance.', $animation->getDescription());
    $this->assertTrue($animation->isPublished());
    $this->assertNull($animation->getProvider());
    $this->assertSame('user', $animation->getOrigin());

    $payload = $this->manager()->animationPayload($animation);
    $this->assertSame('fade_in', $payload['machine_name']);
    $this->assertSame($animation->uuid(), $payload['uuid']);
    $this->assertSame('Fade in', $payload['label']);
    $this->assertSame('A soft entrance.', $payload['description']);
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $payload['steps']);
    $this->assertSame('motion-fade_in', $payload['keyframes']);
    $this->assertNull($payload['provider']);
    // Site-minted: no disk source, so no shipped state and never a revert.
    $this->assertNull($payload['shipped_state']);

    // The roster serves the mint at once as an animation record spelled in
    // the animation slot: what the validator and the client read.
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $record = $roster->animations()['fade_in'];
    $this->assertSame(['animation' => 'motion-fade_in'], $record->spellings);
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $record->value);
    $this->assertSame('A soft entrance.', $record->description);
  }

  /**
   * Minting refusals: the gesture's parameter space, seam codes included.
   */
  public function testMintValidation(): void {
    $errors = $this->catchErrors(fn () => $this->manager()->mint('  ', self::FADE));
    $this->assertSame(['MISSING_PARAMETER'], $errors['label']);

    $errors = $this->catchErrors(fn () => $this->manager()->mint('Nope', 'fade'));
    $this->assertSame(['INVALID_ANIMATION_STEPS'], $errors['steps']);

    $errors = $this->catchErrors(fn () => $this->manager()->mint('Nope', ['middle' => ['opacity' => '0']]));
    $this->assertSame(['INVALID_ANIMATION_OFFSET'], $errors['steps.middle']);

    // A keyframe cannot re-time the animation running it.
    $errors = $this->catchErrors(fn () => $this->manager()->mint('Nope', ['0%' => ['animation-duration' => '1s']]));
    $this->assertSame(['INVALID_ANIMATION_STEP_PROPERTY'], $errors['steps.0%.animation-duration']);

    // Nothing persisted: refusals happen before any save.
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('daedalus_animation')->loadMultiple());
  }

  /**
   * Rename and description: revisioned, identity untouched.
   */
  public function testRename(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $first_revision = (int) $animation->getRevisionId();

    $this->manager()->update($animation, 'Soft fade');
    $this->assertSame('Soft fade', (string) $animation->label());
    // Identity never changes: every motion-fade_in reference survives.
    $this->assertSame('fade_in', $animation->getMachineName());
    $this->assertGreaterThan($first_revision, (int) $animation->getRevisionId());
    $this->assertStringContainsString('Renamed', (string) $animation->getRevisionLogMessage());

    // Renaming to the current label is a no-op, not a new revision.
    $revision_id = (int) $animation->getRevisionId();
    $this->manager()->update($animation, 'Soft fade');
    $this->assertSame($revision_id, (int) $animation->getRevisionId());

    // The description is the AI face; its edit is its own revision.
    $this->manager()->update($animation, NULL, NULL, 'For page bands.');
    $this->assertSame('For page bands.', $animation->getDescription());
    $this->assertGreaterThan($revision_id, (int) $animation->getRevisionId());

    $errors = $this->catchErrors(fn () => $this->manager()->update($animation, ' '));
    $this->assertSame(['MISSING_PARAMETER'], $errors['label']);
    $errors = $this->catchErrors(fn () => $this->manager()->update($animation));
    $this->assertSame(['MISSING_PARAMETER'], $errors['']);
  }

  /**
   * Update steps: the live link (the steps ARE the delivered keyframes).
   */
  public function testUpdateSteps(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $first_revision = (int) $animation->getRevisionId();

    $updated = $this->manager()->update($animation, NULL, [
      '0%' => ['opacity' => '0.2'],
      '50%' => ['opacity' => '0.6'],
      '100%' => ['opacity' => '1'],
    ]);
    $this->assertSame(['0%' => ['opacity' => '0.2'], '50%' => ['opacity' => '0.6'], '100%' => ['opacity' => '1']], $updated->getSteps());
    $this->assertGreaterThan($first_revision, (int) $updated->getRevisionId());
    $this->assertStringContainsString('Updated the steps', (string) $updated->getRevisionLogMessage());
    $this->assertSame('fade_in', $updated->getMachineName());

    // The compiled keyframes file follows the edit.
    $uri = $this->container->get('state')->get(AnimationStylesheet::STATE_KEY);
    $this->assertStringContainsString('50% {', file_get_contents($uri));

    // The steps seam gates the edit: a bad step leaves the stored steps.
    $errors = $this->catchErrors(fn () => $this->manager()->update($animation, NULL, ['0%' => ['opacity' => 'lots']]));
    $this->assertSame(['INVALID_STYLE_VALUE'], $errors['steps.0%.opacity']);
    $this->assertSame(['0%' => ['opacity' => '0.2'], '50%' => ['opacity' => '0.6'], '100%' => ['opacity' => '1']], $this->loadAnimation('fade_in')->getSteps());
  }

  /**
   * Revisions list and restore: a new default revision, never a rollback.
   */
  public function testRevisionsAndRestore(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $first_revision = (int) $animation->getRevisionId();

    $this->manager()->update($animation, NULL, ['0%' => ['opacity' => '0.5'], '100%' => ['opacity' => '1']]);

    $revisions = $this->manager()->revisionsList($animation);
    $this->assertCount(2, $revisions);
    // Newest first; only the default revision reads current.
    $this->assertSame([(int) $animation->getRevisionId(), $first_revision], array_column($revisions, 'revision_id'));
    $this->assertSame([TRUE, FALSE], array_column($revisions, 'current'));

    // Restore lands the OLD content as a NEW revision on top: the restored
    // steps are live again and the restore is itself one restore away
    // from undo.
    $restored = $this->manager()->restoreRevision($animation, $first_revision);
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $restored->getSteps());
    $this->assertGreaterThan((int) $animation->getRevisionId(), (int) $restored->getRevisionId());
    $this->assertCount(3, $this->manager()->revisionsList($restored));

    $errors = $this->catchErrors(fn () => $this->manager()->restoreRevision($restored, 99999));
    $this->assertSame(['STYLE_ANIMATION_REVISION_NOT_FOUND'], $errors['revision']);
  }

  /**
   * Delete: immediate for unworn animations, on the base delete grade.
   */
  public function testDelete(): void {
    $this->setUpCurrentUser([], ['delete animations']);
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $this->assertSame(0, $this->manager()->deleteAnimation($animation));
    $this->assertNull($this->manager()->loadAnimation('fade_in'));
  }

  /**
   * A worn animation refuses the base grade: no "delete anyway" exists.
   */
  public function testDeleteWornNeedsInUseGrade(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $this->createWearingHost('fade_in');
    $this->setUpCurrentUser([], ['delete animations']);

    $errors = $this->catchErrors(fn () => $this->manager()->deleteAnimation($animation));
    $this->assertSame(['STYLE_ANIMATION_IN_USE'], $errors['animation']);
    $this->assertNotNull($this->manager()->loadAnimation('fade_in'));
  }

  /**
   * The worn delete: batch staged, references rewritten, then the entity goes.
   *
   * References are rewritten to none per store.
   */
  public function testDeleteWornBatch(): void {
    $animation = $this->manager()->mint('Fade in', self::FADE);
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $key => ['default' => ['animation' => 'motion-fade_in 600ms ease-out both']],
      ]),
    ]);
    $host->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['animation-name' => 'motion-fade_in']]]);
    $variant->save();
    $skin = DaedalusStylishSkin::create(['label' => 'Site skin', 'machine_name' => 'site_skin']);
    $skin->setDefaults(['kind:block' => ['' => ['default' => ['animation-name' => 'motion-fade_in, spin']]]]);
    $skin->save();

    // The gesture stages the Batch API job (one rewrite per wearing source
    // from the usage table's work list, then the delete) and reports the
    // loud count. Nothing is deleted in this request.
    $this->setUpCurrentUser([], ['delete animations in use']);
    $this->assertSame(3, $this->manager()->deleteAnimation($animation));
    $this->assertNotNull($this->manager()->loadAnimation('fade_in'));
    $batch = batch_get();
    $operations = $batch['sets'][0]['operations'] ?? [];
    $this->assertCount(4, $operations);
    $this->assertSame([AnimationDeleteBatch::class, 'rewriteSource'], $operations[0][0]);
    $this->assertSame([AnimationDeleteBatch::class, 'deleteAnimation'], $operations[3][0]);

    // Run the staged operations the way the batch runner would.
    $context = ['results' => []];
    foreach ($operations as [$callback, $arguments]) {
      if ($callback[1] === 'rewriteSource') {
        AnimationDeleteBatch::rewriteSource($arguments[0], $arguments[1], $arguments[2], $arguments[3], $context);
      }
      else {
        AnimationDeleteBatch::deleteAnimation($arguments[0], $context);
      }
    }

    // Every reference is `none` now, the rest of each value verbatim, in
    // all three stores; the animation and its usage rows are gone.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame('none 600ms ease-out both', $map[$key]['default']['animation']);
    $variant_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $variant_storage->resetCache([$variant->id()]);
    $this->assertSame(['' => ['default' => ['animation-name' => 'none']]], $variant_storage->load($variant->id())->getStyles());
    $skin_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $skin_storage->resetCache([$skin->id()]);
    $this->assertSame(['kind:block' => ['' => ['default' => ['animation-name' => 'none, spin']]]], $skin_storage->load($skin->id())->getDefaults());
    $this->assertNull($this->manager()->loadAnimation('fade_in'));
    $this->assertSame(3, $context['results']['rewritten']);
    $this->assertSame('Fade in', $context['results']['deleted']);
    $rows = (int) $this->container->get('database')->select(AnimationUsage::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame(0, $rows);
  }

  /**
   * The delete endpoint: the two grades and the batch handoff.
   */
  public function testDeleteEndpoint(): void {
    $controller = AnimationApiController::create($this->container);

    $this->setUpCurrentUser([], ['delete animations']);
    $response = $controller->delete('missing');
    $this->assertSame(404, $response->getStatusCode());

    // Unworn: deleted in this request.
    $this->manager()->mint('Loner', self::FADE);
    $response = $controller->delete('loner');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['deleted']);
    $this->assertSame(0, $payload['references']);
    // The route lane answers the materials delta every write carries: the
    // mint and the delete both touched the id, and the roster the delta
    // resolves against no longer has it, so the client store removes it.
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'animation', 'id' => 'loner']]], $payload['materials']);

    // Worn without the in-use grade: 403 with the corrective, untouched.
    $this->manager()->mint('Fade in', self::FADE);
    $this->createWearingHost('fade_in');
    $response = $controller->delete('fade_in');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STYLE_ANIMATION_IN_USE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertNotNull($this->manager()->loadAnimation('fade_in'));

    // Worn with the grade: 202 and the batch URL for the client to drive.
    $this->setUpCurrentUser([], ['delete animations in use']);
    $response = $controller->delete('fade_in');
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertSame(1, $payload['references']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    $this->assertNotNull($this->manager()->loadAnimation('fade_in'));
  }

  /**
   * The usage, rename, revisions and restore endpoints.
   */
  public function testManagementEndpoints(): void {
    $controller = AnimationApiController::create($this->container);

    $response = $controller->usage('missing');
    $this->assertSame(404, $response->getStatusCode());

    $animation = $this->manager()->mint('Fade in', self::FADE);
    $this->createWearingHost('fade_in');
    $response = $controller->usage('fade_in');
    $this->assertSame(200, $response->getStatusCode());
    // The management read: the live count plus the shipped facts (a
    // site-minted animation has no provider and no shipped state).
    $this->assertSame(
      ['machine_name' => 'fade_in', 'label' => 'Fade in', 'usages' => 1, 'provider' => NULL, 'shipped_state' => NULL],
      json_decode((string) $response->getContent(), TRUE),
    );

    // Rename is an adapter over the provider's update: the delta carries
    // the relabeled record and nothing else.
    $this->container->get('daedalus_blueprint.materials_change_set')->flush();
    $request = Request::create('/daedalus_stylish/animations/fade_in/rename', 'POST', [], [], [], [], json_encode(['label' => 'Soft fade']));
    $response = $controller->rename('fade_in', $request);
    $this->assertSame(200, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['materials'], array_keys($body));
    $this->assertSame('Soft fade', $body['materials']['upsert'][0]['label']);
    $this->assertSame(['animation' => 'motion-fade_in'], $body['materials']['upsert'][0]['spellings']);

    $response = $controller->revisions('fade_in');
    $revisions = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('Soft fade', $revisions['label']);
    $this->assertCount(2, $revisions['revisions']);
    $first = $revisions['revisions'][1]['revision_id'];
    $this->assertSame((int) $animation->getRevisionId(), $first);

    $response = $controller->restore('fade_in', $first);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('Fade in', json_decode((string) $response->getContent(), TRUE)['materials']['upsert'][0]['label']);
    $this->assertSame(422, $controller->restore('fade_in', 99999)->getStatusCode());
  }

  /**
   * Creates a saved map host wearing the given animation.
   */
  protected function createWearingHost(string $machine_name): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $this->container->get('uuid')->generate() => ['default' => ['animation-name' => sprintf('motion-%s', $machine_name)]],
      ]),
    ]);
    $host->save();
    return $host;
  }

  /**
   * The distro lock and revert: disk is the one author of shipped animations.
   */
  public function testShippedLockAndRevert(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $shipped = $this->loadAnimation('test_fade');

    // Rename, step edits, restore, and delete all refuse on a living disk
    // source.
    $errors = $this->catchErrors(fn () => $this->manager()->update($shipped, 'Mine now'));
    $this->assertSame(['STYLE_ANIMATION_SHIPPED_LOCKED'], $errors['animation']);
    $errors = $this->catchErrors(fn () => $this->manager()->update($shipped, NULL, ['0%' => ['opacity' => '0.5']]));
    $this->assertSame(['STYLE_ANIMATION_SHIPPED_LOCKED'], $errors['animation']);
    $errors = $this->catchErrors(fn () => $this->manager()->restoreRevision($shipped, (int) $shipped->getRevisionId()));
    $this->assertSame(['STYLE_ANIMATION_SHIPPED_LOCKED'], $errors['animation']);
    $errors = $this->catchErrors(fn () => $this->manager()->deleteAnimation($shipped));
    $this->assertSame(['STYLE_ANIMATION_SHIPPED_LOCKED'], $errors['animation']);

    // A user edit reads as modified (blueprint and gesture lanes refuse it;
    // direct entity writes are the AI/test escape hatch) and revert is the
    // convergence: the disk steps land as an ordinary new revision and the
    // animation reads unmodified again.
    $shipped->setSteps(['0%' => ['opacity' => '0.5'], '100%' => ['opacity' => '1']]);
    $shipped->setNewRevision(TRUE);
    $shipped->save();
    $this->assertSame('modified', $this->manager()->animationPayload($shipped)['shipped_state']);
    $reverted = $this->manager()->revert($shipped);
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $reverted->getSteps());
    $this->assertFalse($reverted->isModified());
    $this->assertSame('unmodified', $this->manager()->animationPayload($reverted)['shipped_state']);
    $this->assertStringContainsString('daedalus_stylish_animations_test', (string) $reverted->getRevisionLogMessage());

    // Site-minted animations have no disk defaults to revert to.
    $mine = $this->manager()->mint('Mine', self::FADE);
    $errors = $this->catchErrors(fn () => $this->manager()->revert($mine));
    $this->assertSame(['STYLE_ANIMATION_NOT_SHIPPED'], $errors['animation']);

    // A provider that stopped shipping the entry releases the lock (the
    // animation lives on as ordinary site content) and loses the revert.
    $reverted->set('provider', 'system');
    $reverted->save();
    $this->assertNull($this->manager()->animationPayload($reverted)['shipped_state']);
    $this->manager()->update($reverted, 'Orphaned fade');
    $errors = $this->catchErrors(fn () => $this->manager()->revert($reverted));
    $this->assertSame(['SHIPPED_DEFAULTS_MISSING'], $errors['animation']);
  }

  /**
   * Loads an animation by machine name, bypassing caches.
   */
  protected function loadAnimation(string $machine_name): ?DaedalusStylishAnimationInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_animation');
    $storage->resetCache();
    $animations = $storage->loadByProperties(['machine_name' => $machine_name]);
    $animation = reset($animations) ?: NULL;
    assert($animation === NULL || $animation instanceof DaedalusStylishAnimationInterface);
    return $animation;
  }

  /**
   * The developer gate lifts the lock: shipped animations edit in place.
   *
   * On a development site (`$settings['daedalus_blueprint_developer'] =
   * TRUE`, read live by DistroLock) the package author edits shipped
   * animations through the gestures; the entity then reads as modified
   * against the disk and keeps its provider, so revert stays available.
   */
  public function testShippedEditsLandUnderTheDeveloperGate(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $shipped = $this->loadAnimation('test_fade');
    $this->assertSame('unmodified', $this->manager()->animationPayload($shipped)['shipped_state']);

    $steps = ['0%' => ['opacity' => '0.5'], '100%' => ['opacity' => '1']];
    $updated = $this->manager()->update($shipped, 'Test fade (soft)', $steps);
    $this->assertSame('Test fade (soft)', (string) $updated->label());
    $this->assertSame($steps, $updated->getSteps());
    $this->assertSame('daedalus_stylish_animations_test', $updated->getProvider());
    $this->assertSame('modified', $this->manager()->animationPayload($this->loadAnimation('test_fade'))['shipped_state']);

    // Closing the gate again locks the (still shipped) animation.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $errors = $this->catchErrors(fn () => $this->manager()->update($this->loadAnimation('test_fade'), 'Mine now'));
    $this->assertSame(['STYLE_ANIMATION_SHIPPED_LOCKED'], $errors['animation']);
  }

}
