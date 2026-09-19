<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsDelivery;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The materials delivery contract: the delta channel and the boot payload.
 *
 * One record shape, two halves: every mutation response carries the
 * {upsert, remove} delta of the materials the request changed
 * (MaterialsChangeSet, resolved against the fresh roster at response time),
 * and the page-load payload carries the whole roster as {records}
 * (MaterialsDelivery). Pinned here against the hand-built test provider so
 * the contract's shape is locked independently of any real provider's
 * entity hooks (the stencil and daedalus_stylish suites cover those lanes).
 */
#[CoversClass(MaterialsChangeSet::class)]
#[CoversClass(MaterialsDelivery::class)]
#[CoversClass(MutationResponseBuilder::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class MutationResponseMaterialsTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_blueprint_api',
    'daedalus_blueprint_materials_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
  }

  /**
   * The change set records identity; the delta resolves against the roster.
   *
   * A touched id the roster carries is an upsert (its full record), a
   * touched id the roster lacks is a remove; kinds in MaterialKind order.
   * delta() keeps the set, flush() clears it.
   */
  public function testDeltaResolvesAgainstTheRoster(): void {
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $this->assertTrue($change_set->isEmpty());
    $this->assertSame(['upsert' => [], 'remove' => []], $change_set->delta());

    // Touch order within a kind is kept; kind order is the enum's, whatever
    // the touch order.
    $change_set->touch(MaterialKind::Animation, 'fade_in');
    $change_set->touch(MaterialKind::Stencil, 'hero');
    $change_set->touch(MaterialKind::Swatch, 'gone', 'lime_green');
    $change_set->touch(MaterialKind::Token, 'color/accent');
    // Touching twice is one change.
    $change_set->touch(MaterialKind::Swatch, 'lime_green');
    $this->assertFalse($change_set->isEmpty());

    $delta = $change_set->delta();
    $this->assertSame(
      [['token', 'color/accent'], ['swatch', 'lime_green'], ['stencil', 'hero'], ['animation', 'fade_in']],
      array_map(static fn (array $record): array => [$record['kind'], $record['id']], $delta['upsert']),
    );
    $this->assertSame([['kind' => 'swatch', 'id' => 'gone']], $delta['remove']);

    // An upsert is the record as the boot payload spells it: every field.
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame($roster->get(MaterialKind::Swatch, 'lime_green')->toArray(), $delta['upsert'][1]);
    $this->assertSame('var(--swatch-lime_green)', $delta['upsert'][1]['spellings']['style']);

    // delta() is a read; flush() is the one-response drain.
    $this->assertFalse($change_set->isEmpty());
    $this->assertSame($delta, $change_set->flush());
    $this->assertTrue($change_set->isEmpty());
    $this->assertSame(['upsert' => [], 'remove' => []], $change_set->flush());

    // A material that leaves the roster after the touch (a delete, an
    // unpublish) resolves as a remove at response time: the delta is the
    // truth at the end of the request, not at the hook.
    $change_set->touch(MaterialKind::Variant, 'button');
    TestMaterialsProvider::$records = array_values(array_filter(
      TestMaterialsProvider::referenceRecords(),
      static fn ($record) => $record->id !== 'button',
    ));
    $this->container->get('daedalus_blueprint.materials_roster_builder')->reset();
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'variant', 'id' => 'button']]], $change_set->flush());

    // A staged removal (the batched delete lanes) states a removal the
    // response cannot resolve yet: never resolved against the roster and
    // never merged into the touched set.
    $this->assertSame([
      'upsert' => [],
      'remove' => [['kind' => 'swatch', 'id' => 'lime_green'], ['kind' => 'swatch', 'id' => 'coral']],
    ], $change_set->stagedRemoval(MaterialKind::Swatch, 'lime_green', 'coral'));
    $this->assertTrue($change_set->isEmpty());

    $this->expectException(\InvalidArgumentException::class);
    $change_set->touch(MaterialKind::Swatch, '');
  }

  /**
   * Every mutation response carries the delta, once.
   *
   * The builder flushes the change set into the payload it builds (success
   * or failure: a failed batch may still have minted), so a second
   * response in the same request carries the empty delta; the detached
   * materials-only payload is the contract shape with no entity keys.
   */
  public function testMutationResponseCarriesTheDelta(): void {
    $builder = $this->container->get('daedalus_blueprint_api.mutation_response_builder');
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $entity = $this->createTestEntity();
    $request = Request::create('/daedalus-blueprint/entity_test/' . $entity->id(), 'POST');

    $change_set->touch(MaterialKind::Swatch, 'lime_green', 'gone');
    $response = $builder->build($request, $entity, BlueprintResult::success($entity), NULL, [], 'full');
    $payload = Json::decode($response->getContent());
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('lime_green', $payload['materials']['upsert'][0]['id']);
    $this->assertSame([['kind' => 'swatch', 'id' => 'gone']], $payload['materials']['remove']);
    $this->assertTrue($change_set->isEmpty());

    // Flushed once: the next response of the request carries nothing.
    $response = $builder->build($request, $entity, BlueprintResult::success($entity), NULL, [], 'full');
    $this->assertSame(['upsert' => [], 'remove' => []], Json::decode($response->getContent())['materials']);

    // Failures carry it too (the stencil gestures' side-effect semantics: a
    // mint before the failing op stands).
    $change_set->touch(MaterialKind::Stencil, 'hero');
    $failure = BlueprintResult::structuralFailure([new BlueprintError('operations[1]', 'UNKNOWN_REF', 'No such element.')]);
    $response = $builder->build($request, $entity, $failure, NULL, [], 'full');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('hero', Json::decode($response->getContent())['materials']['upsert'][0]['id']);

    // The out-of-operations error shape keeps the key so the client handles
    // every failure uniformly.
    $change_set->touch(MaterialKind::Stencil, 'hero');
    $error = Json::decode($builder->error('WORKSPACE_REQUIRED', 'Nope.', 409)->getContent());
    $this->assertSame('hero', $error['materials']['upsert'][0]['id']);

    // The materials-only payload: an AI tool that persisted a non-page
    // entity answers the contract shape with no entity, blueprint or
    // markup behind it.
    $change_set->touch(MaterialKind::Variant, 'button');
    $detached = $builder->materialsPayload();
    $this->assertSame(
      ['success', 'targets', 'errors', 'warnings', 'markup', 'attachments', 'drupal_commands', 'materials'],
      array_keys($detached),
    );
    $this->assertTrue($detached['success']);
    $this->assertSame('button', $detached['materials']['upsert'][0]['id']);
    $this->assertSame('{}', Json::encode($detached['markup']));
    $this->assertTrue($change_set->isEmpty());
  }

  /**
   * The boot payload is the whole roster in the delta's record shape.
   */
  public function testDeliveryEnvelope(): void {
    $delivery = $this->container->get('daedalus_blueprint.materials_delivery');
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();

    $payload = $delivery->payload();
    $this->assertSame(['records'], array_keys($payload));
    $this->assertSame(
      array_map(static fn ($record): array => $record->toArray(), $roster->all()),
      $payload['records'],
    );
    $this->assertSame(
      [
        'color/accent',
        'lime_green',
        'button',
        'container_full',
        'block_full',
        'hero_glow',
        'link_quiet',
        'hero',
        'fade_in',
      ],
      array_column($payload['records'], 'id'),
    );

    // A JSON string, replaced atomically by the settings merge, decoding to
    // the payload.
    $envelope = $delivery->envelope();
    $this->assertIsString($envelope);
    $this->assertSame($payload, Json::decode($envelope));

    // The render that attaches it carries the roster's own invalidation.
    $this->assertContains('materials_test_list', $delivery->cacheMetadata()->getCacheTags());
    $this->assertContains('user.permissions', $delivery->cacheMetadata()->getCacheContexts());
  }

}
