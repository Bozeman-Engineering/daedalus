<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Controller\VariantApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantDeleteBatch;
use Drupal\daedalus_stylish\VariantUsage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the variant lifecycle gestures: delete, details, revision restore.
 *
 * Delete is detach-then-delete, always: an unworn variant deletes
 * immediately, a worn one stages the Batch API job whose per-wearer step
 * is the op spine's own detach math
 * (DaedalusStylishOperationHandler::detachVariantEverywhere()), so every wearer
 * keeps the delivered look as its own styles — "delete anyway" does not
 * exist. Two permission grades gate the gesture ("delete variants" /
 * "delete variants in use"); details (label, description) and restore
 * ride the update grade and are ordinary revisioned saves. Shipped
 * variants refuse all three (the distro lock).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantLifecycleTest extends KernelTestBase {

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
    'daedalus_stylish_guide_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * The variant manager, resolved late (installs rebuild the container).
   */
  protected function manager(): object {
    return $this->container->get('daedalus_stylish.variant_manager');
  }

  /**
   * Creates a saved block-scoped variant.
   */
  protected function createVariant(string $label, string $machine_name, ?array $styles = NULL): DaedalusStylishVariantInterface {
    $variant = DaedalusStylishVariant::create(['label' => $label, 'machine_name' => $machine_name, 'scope' => 'kind:block']);
    $variant->setStyles($styles ?? ['' => ['default' => ['padding' => '1rem']]]);
    $variant->save();
    return $variant;
  }

  /**
   * Creates a saved host entity with the given style map.
   */
  protected function createWearer(array $map): EntityTest {
    $entity = EntityTest::create([
      'name' => 'wearer',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map),
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Reads a host entity's saved style map, fresh from storage.
   */
  protected function savedMap(EntityTest $entity): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$entity->id()]);
    $item = $storage->load($entity->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Collects error codes keyed by path from a failing gesture.
   */
  protected function catchErrors(callable $gesture): array {
    try {
      $gesture();
    }
    catch (VariantValidationException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()][] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('Expected a VariantValidationException.');
  }

  /**
   * Details: revisioned, label and/or description, identity untouched.
   */
  public function testDetails(): void {
    $this->setUpCurrentUser();
    $variant = $this->createVariant('Card', 'card');
    $first_revision = (int) $variant->getRevisionId();

    // Label alone.
    $this->manager()->updateDetails($variant, '  Card, bold  ');
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('Card, bold', (string) $reloaded->label());
    $this->assertSame('card', $reloaded->getMachineName());
    $this->assertSame('', $reloaded->getDescription());
    $this->assertGreaterThan($first_revision, (int) $reloaded->getRevisionId());
    $this->assertSame('Renamed from "Card" to "Card, bold".', (string) $reloaded->getRevisionLogMessage());

    // Description alone: the label holds, one revision, its own log line.
    $labeled_revision = (int) $reloaded->getRevisionId();
    $this->manager()->updateDetails($reloaded, NULL, '  A framed teaser with a shadow.  ');
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('Card, bold', (string) $reloaded->label());
    $this->assertSame('A framed teaser with a shadow.', $reloaded->getDescription());
    $this->assertGreaterThan($labeled_revision, (int) $reloaded->getRevisionId());
    $this->assertSame('Description updated.', (string) $reloaded->getRevisionLogMessage());

    // Both at once: one revision carrying both log lines.
    $described_revision = (int) $reloaded->getRevisionId();
    $this->manager()->updateDetails($reloaded, 'Card, framed', 'Use on teasers.');
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('Card, framed', (string) $reloaded->label());
    $this->assertSame('Use on teasers.', $reloaded->getDescription());
    $this->assertGreaterThan($described_revision, (int) $reloaded->getRevisionId());
    $this->assertSame('Renamed from "Card, bold" to "Card, framed". Description updated.', (string) $reloaded->getRevisionLogMessage());

    // The current values are a no-op: no ghost revision. An empty
    // description clears it.
    $unchanged = (int) $reloaded->getRevisionId();
    $this->manager()->updateDetails($reloaded, 'Card, framed', 'Use on teasers.');
    $this->assertSame($unchanged, (int) DaedalusStylishVariant::load($variant->id())->getRevisionId());
    $this->manager()->updateDetails($reloaded, NULL, '');
    $this->assertSame('', DaedalusStylishVariant::load($variant->id())->getDescription());

    // Nothing to change is refused, and an empty label is the same
    // corrective save-as gives it.
    $codes = $this->catchErrors(fn () => $this->manager()->updateDetails($reloaded));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
    $codes = $this->catchErrors(fn () => $this->manager()->updateDetails($reloaded, '   '));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);
  }

  /**
   * Revision list and restore: a new default revision, never a rollback.
   */
  public function testRevisionsAndRestore(): void {
    $this->setUpCurrentUser();
    $variant = $this->createVariant('Card', 'card', ['' => ['default' => ['padding' => '1rem']]]);
    $first_revision = (int) $variant->getRevisionId();
    $this->manager()->push($variant, '', ['' => ['default' => ['padding' => '3rem']]]);
    $second_revision = (int) DaedalusStylishVariant::load($variant->id())->getRevisionId();

    // One wearer, so restore reports a loud count of 1.
    $this->createWearer([
      'block:' . $this->container->get('uuid')->generate() => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);

    $list = $this->manager()->revisionsList(DaedalusStylishVariant::load($variant->id()));
    $this->assertSame([$second_revision, $first_revision], array_column($list, 'revision_id'));
    $this->assertSame([TRUE, FALSE], array_column($list, 'current'));
    $this->assertSame('Styles edited in the variant code view.', $list[0]['log_message']);

    // Restoring the first revision lands its content in a NEW revision on
    // top — the restore is itself one restore away from undo.
    $usages = $this->manager()->restoreRevision(DaedalusStylishVariant::load($variant->id()), $first_revision);
    $this->assertSame(1, $usages);
    $restored = DaedalusStylishVariant::load($variant->id());
    $this->assertSame(['' => ['default' => ['padding' => '1rem']]], $restored->getStyles());
    $this->assertGreaterThan($second_revision, (int) $restored->getRevisionId());
    $this->assertSame(sprintf('Restored revision %d.', $first_revision), (string) $restored->getRevisionLogMessage());
    $this->assertCount(3, $this->manager()->revisionsList($restored));

    // A revision id the variant does not have — junk, or ANOTHER
    // variant's — is the same corrective.
    $codes = $this->catchErrors(fn () => $this->manager()->restoreRevision($restored, 999999));
    $this->assertSame(['STYLE_VARIANT_REVISION_NOT_FOUND'], $codes['revision']);
    $other = $this->createVariant('Other', 'other');
    $codes = $this->catchErrors(fn () => $this->manager()->restoreRevision($restored, (int) $other->getRevisionId()));
    $this->assertSame(['STYLE_VARIANT_REVISION_NOT_FOUND'], $codes['revision']);
  }

  /**
   * The detachVariantEverywhere: the whole map, both forms, look preserved.
   */
  public function testDetachVariantEverywhere(): void {
    $this->setUpCurrentUser();
    $variant = $this->createVariant('Card', 'card', [
      '' => ['default' => ['padding' => '1rem', 'margin' => '2rem']],
      '::.inner' => ['default' => ['color' => 'red']],
    ]);
    $uuid = fn (): string => $this->container->get('uuid')->generate();
    [$k1, $k2, $k3] = ['block:' . $uuid(), 'block:' . $uuid(), 'block:' . $uuid()];
    $wearer = $this->createWearer([
      // A wearing with an own override: the override keeps winning.
      $k1 => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['padding' => '3rem']],
      // A wearing by uuid: the other reference form detaches too.
      $k2 => ['variants' => [['variant' => $variant->uuid(), 'fragment' => '']]],
      // An orphaned fragment wearing (rode out on a moved element): the
      // variant holds no such fragment, so it materializes nothing and
      // the reference simply drops.
      $k3 => ['variants' => [['variant' => 'card', 'fragment' => 'block(text,1)']]],
    ]);

    $handler = $this->container->get('daedalus_stylish.operation_handler');
    $entity = $this->container->get('entity_type.manager')->getStorage('entity_test')->load($wearer->id());
    $this->assertTrue($handler->detachVariantEverywhere($entity, $variant));
    $entity->save();

    $this->assertSame([
      $k1 => ['default' => ['padding' => '3rem', 'margin' => '2rem']],
      $k2 => ['default' => ['padding' => '1rem', 'margin' => '2rem']],
      $k1 . '::.inner' => ['default' => ['color' => 'red']],
      $k2 . '::.inner' => ['default' => ['color' => 'red']],
    ], $this->savedMap($wearer));

    // Detached means detached: nothing left to do, and the usage table's
    // chokepoint dropped the wearer on save.
    $this->assertFalse($handler->detachVariantEverywhere($entity, $variant));
    $this->assertSame(0, $this->manager()->usages($variant));
  }

  /**
   * Unworn variants delete immediately, on the base delete grade.
   */
  public function testDeleteUnwornImmediate(): void {
    $this->setUpCurrentUser([], ['delete variants']);
    $variant = $this->createVariant('Card', 'card');
    $id = $variant->id();

    $this->assertSame(0, $this->manager()->deleteVariant($variant));
    $this->assertNull(DaedalusStylishVariant::load($id));
  }

  /**
   * A worn variant refuses the base grade: no "delete anyway" exists.
   */
  public function testDeleteWornNeedsInUseGrade(): void {
    $this->setUpCurrentUser([], ['delete variants']);
    $variant = $this->createVariant('Card', 'card');
    $this->createWearer([
      'block:' . $this->container->get('uuid')->generate() => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);

    $codes = $this->catchErrors(fn () => $this->manager()->deleteVariant($variant));
    $this->assertSame(['STYLE_VARIANT_IN_USE'], $codes['variant']);
    $this->assertNotNull(DaedalusStylishVariant::load($variant->id()));
  }

  /**
   * The worn delete: batch staged, wearers detached, then the entity goes.
   */
  public function testDeleteWornBatch(): void {
    $this->setUpCurrentUser([], ['delete variants in use']);
    $variant = $this->createVariant('Card', 'card', ['' => ['default' => ['padding' => '1rem']]]);
    $uuid = fn (): string => $this->container->get('uuid')->generate();
    $keys = ['block:' . $uuid(), 'block:' . $uuid()];
    $first = $this->createWearer([$keys[0] => ['variants' => [['variant' => 'card', 'fragment' => '']]]]);
    $second = $this->createWearer([
      $keys[1] => ['variants' => [['variant' => $variant->uuid(), 'fragment' => '']], 'default' => ['margin' => '2rem']],
    ]);

    // The gesture stages the Batch API job — one detach per wearer from
    // the usage table's work list, then the delete — and reports the loud
    // count. Nothing is deleted in this request.
    $this->assertSame(2, $this->manager()->deleteVariant($variant));
    $this->assertNotNull(DaedalusStylishVariant::load($variant->id()));
    $batch = batch_get();
    $operations = $batch['sets'][0]['operations'] ?? [];
    $this->assertCount(3, $operations);
    $this->assertSame([VariantDeleteBatch::class, 'detachWearer'], $operations[0][0]);
    $this->assertSame([VariantDeleteBatch::class, 'deleteVariant'], $operations[2][0]);

    // Run the staged operations the way the batch runner would.
    $context = ['results' => []];
    foreach ($operations as [$callback, $arguments]) {
      if ($callback[1] === 'detachWearer') {
        VariantDeleteBatch::detachWearer($arguments[0], $arguments[1], $arguments[2], $context);
      }
      else {
        VariantDeleteBatch::deleteVariant($arguments[0], $context);
      }
    }

    // Every wearer keeps the delivered look as its own styles; the
    // variant and its usage rows are gone.
    $this->assertSame([$keys[0] => ['default' => ['padding' => '1rem']]], $this->savedMap($first));
    $this->assertSame([$keys[1] => ['default' => ['padding' => '1rem', 'margin' => '2rem']]], $this->savedMap($second));
    $this->assertNull(DaedalusStylishVariant::load($variant->id()));
    $this->assertSame(2, $context['results']['detached']);
    $this->assertSame('Card', $context['results']['deleted']);
    $rows = (int) $this->container->get('database')->select(VariantUsage::TABLE)->countQuery()->execute()->fetchField();
    $this->assertSame(0, $rows);
  }

  /**
   * The delete endpoint: 404, the two grades, and the batch handoff.
   */
  public function testDeleteEndpoint(): void {
    $controller = VariantApiController::create($this->container);

    $this->setUpCurrentUser([], ['delete variants']);
    $response = $controller->delete('missing');
    $this->assertSame(404, $response->getStatusCode());

    // Unworn: deleted in this request.
    $this->createVariant('Unworn', 'unworn');
    $response = $controller->delete('unworn');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue(json_decode((string) $response->getContent(), TRUE)['deleted']);

    // Worn without the in-use grade: 403 with the corrective, untouched.
    $variant = $this->createVariant('Card', 'card');
    $this->createWearer([
      'block:' . $this->container->get('uuid')->generate() => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);
    $response = $controller->delete('card');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STYLE_VARIANT_IN_USE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertNotNull(DaedalusStylishVariant::load($variant->id()));

    // Worn with the grade: 202 and the batch URL for the client to drive.
    $this->setUpCurrentUser([], ['delete variants in use']);
    $response = $controller->delete('card');
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertSame(1, $payload['wearers']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    $this->assertNotNull(DaedalusStylishVariant::load($variant->id()));
    // The batched path cannot flush its removal (the entity goes in the
    // batch's last request), so the 202 STATES the removal the staged
    // batch is committed to; the client applies it after driving the
    // batch, and both windows forget the variant.
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'variant', 'id' => 'card']]], $payload['materials']);
  }

  /**
   * The usage endpoint: the count beside the management facts.
   *
   * Usages, the shipped state and the provider are per-open management
   * reads (no list carries them; the roster record says only where the
   * variant came from), so the one route answers all three.
   */
  public function testUsageEndpoint(): void {
    $this->setUpCurrentUser([], ['update variants']);
    $controller = VariantApiController::create($this->container);
    $this->assertSame(404, $controller->usage('missing')->getStatusCode());

    $this->createVariant('Card', 'card');
    $this->createWearer([
      'block:' . $this->container->get('uuid')->generate() => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);
    $response = $controller->usage('card');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'machine_name' => 'card',
      'label' => 'Card',
      'usages' => 1,
      'provider' => NULL,
      'shipped_state' => NULL,
    ], json_decode((string) $response->getContent(), TRUE));
  }

  /**
   * The details and restore endpoints speak the standard shapes.
   */
  public function testDetailsEndpoint(): void {
    // The route's permission is now the provider's own gate (one gate for
    // the op lane and the route lane), so the controller test grants it.
    $this->setUpCurrentUser([], ['update variants']);
    $controller = VariantApiController::create($this->container);
    $this->createVariant('Card', 'card');
    $details = static fn (array $body): Request => new Request(content: json_encode($body));
    $face = static fn (array $record): array => [
      $record['kind'],
      $record['id'],
      $record['label'],
      $record['description'],
    ];

    // Label alone.
    $response = $controller->details('card', $details(['label' => 'Card, bold']));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    // The route lane answers the materials delta every write carries and
    // nothing else: the updated variant's fresh record, as the client
    // store applies it and the card reads it (no echo payload).
    $this->assertSame(['materials'], array_keys($payload));
    $this->assertSame([], $payload['materials']['remove']);
    $this->assertSame([['variant', 'card', 'Card, bold', '']], array_map($face, $payload['materials']['upsert']));

    // Description alone.
    $response = $controller->details('card', $details(['description' => 'A framed teaser.']));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([['variant', 'card', 'Card, bold', 'A framed teaser.']], array_map($face, $payload['materials']['upsert']));

    // Both in one call.
    $response = $controller->details('card', $details(['label' => 'Card, framed', 'description' => 'Use on teasers.']));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([['variant', 'card', 'Card, framed', 'Use on teasers.']], array_map($face, $payload['materials']['upsert']));

    // A body naming neither field is refused (the spine's own
    // MISSING_PARAMETER), as is a body that is not a JSON object; the
    // variant is untouched either way. An empty label is the label
    // corrective.
    $response = $controller->details('card', $details([]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame(['MISSING_PARAMETER'], array_column(json_decode((string) $response->getContent(), TRUE)['errors'], 'code'));
    $response = $controller->details('card', new Request(content: 'not json'));
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame(['INVALID_PAYLOAD'], array_column(json_decode((string) $response->getContent(), TRUE)['errors'], 'code'));
    $response = $controller->details('card', $details(['label' => '  ']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame(['label'], array_column(json_decode((string) $response->getContent(), TRUE)['errors'], 'path'));
    $this->assertSame(404, $controller->details('missing', $details(['label' => 'x']))->getStatusCode());
    $this->assertSame('Card, framed', (string) $this->manager()->loadVariant('card')->label());

    $response = $controller->revisions('card');
    $revisions = json_decode((string) $response->getContent(), TRUE)['revisions'];
    $this->assertCount(4, $revisions);
    $restore = $controller->restore('card', $revisions[3]['revision_id']);
    $this->assertSame(200, $restore->getStatusCode());
    $restored = json_decode((string) $restore->getContent(), TRUE);
    $this->assertSame(['usages', 'materials'], array_keys($restored));
    $this->assertSame('Card', $restored['materials']['upsert'][0]['label']);
  }

  /**
   * Shipped variants refuse details, restore, and delete: the distro lock.
   */
  public function testShippedLock(): void {
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('module_installer')->install(['daedalus_stylish_variants_test']);
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $variants = $storage->loadByProperties(['machine_name' => 'wide_section']);
    $variant = reset($variants);
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);

    foreach ([
      fn () => \Drupal::service('daedalus_stylish.variant_manager')->updateDetails($variant, 'Mine now'),
      fn () => \Drupal::service('daedalus_stylish.variant_manager')->restoreRevision($variant, (int) $variant->getRevisionId()),
      fn () => \Drupal::service('daedalus_stylish.variant_manager')->deleteVariant($variant),
    ] as $gesture) {
      $codes = $this->catchErrors($gesture);
      $this->assertSame(['STYLE_VARIANT_SHIPPED_LOCKED'], $codes['variant']);
    }
    $this->assertNotNull($storage->loadUnchanged($variant->id()));
  }

  /**
   * The workspace-publish resync: published hosts land in the usage table.
   */
  public function testPublishResync(): void {
    $this->setUpCurrentUser();
    $variant = $this->createVariant('Card', 'card');
    $wearer = $this->createWearer([
      'block:' . $this->container->get('uuid')->generate() => ['variants' => [['variant' => 'card', 'fragment' => '']]],
    ]);

    // Simulate publish-time staleness: the default revision changed with
    // no update hook, so the table still shows the pre-publish state.
    $this->container->get('database')->delete(VariantUsage::TABLE)->execute();
    $this->assertSame(0, $this->manager()->usages($variant));

    // The subscriber replays the chokepoint for the published hosts (the
    // event class is workspace's own; a stand-in with the same payload
    // shape exercises the path without the workspaces module).
    $event = new class (['entity_test' => [7 => (string) $wearer->id()], 'user' => [3 => '1']]) {

      public function __construct(protected array $ids) {}

      /**
       * {@inheritdoc}
       */
      public function getPublishedRevisionIds(): array {
        return $this->ids;
      }

    };
    $this->container->get('daedalus_stylish.variant_workspace_subscriber')->onPostPublish($event);
    $this->assertSame(1, $this->manager()->usages($variant));
    $this->assertSame([
      ['entity_type' => 'entity_test', 'entity_id' => (string) $wearer->id()],
    ], $this->manager()->wearers($variant));
  }

}
