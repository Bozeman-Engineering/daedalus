<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint_stencil\Controller\StencilApiController;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint_stencil\StencilManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the stencil gesture routes outside the op spine: details and delete.
 *
 * These gestures are deliberately NOT blueprint ops (on the spine, "is an
 * op" IS "the AI can call it", and ops mutate tempstore-staged state with
 * a save/discard net; deletion is site-wide and net-less), so the coverage
 * here is the route contract itself: the dedicated endpoints, their CSRF +
 * permission requirements, the entity-access gate, the loud refusal of
 * file-shipped stencils, and discovery staying in step through the one
 * cache tag.
 */
#[CoversClass(StencilApiController::class)]
#[CoversClass(StencilManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilApiTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'field',
    'file',
    'image',
    'media',
    'daedalus_blueprint',
    'daedalus_blueprint_stencil',
    'daedalus_blueprint_stencil_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('stencil');
    // setUpCurrentUser() seeds a throwaway uid 1 first, so this user's
    // "save stencils" permission is what entity access actually evaluates.
    $this->setUpCurrentUser([], ['save stencils']);
  }

  /**
   * The controller under test, container-built like the route would.
   */
  protected function controller(): StencilApiController {
    return StencilApiController::create($this->container);
  }

  /**
   * Creates and saves a content stencil ("promo_card" by default).
   *
   * The fixture module already ships FILE stencils named call_to_action
   * and hero, and stencil IDs are one flat vocabulary, so the content
   * stencil under test needs a name of its own.
   */
  protected function createStencil(array $values = []): Stencil {
    $stencil = Stencil::create($values + [
      'label' => 'Promo Card',
      'description' => 'Two-column card: heading beside an image.',
    ]);
    $stencil->setElement([
      'kind' => 'container',
      'layout_id' => 'layout_twocol_section',
      'children' => [
        [
          'kind' => 'region',
          'region' => 'first',
          'children' => [['kind' => 'block', 'block_type' => 'text']],
        ],
      ],
    ]);
    $stencil->save();
    return $stencil;
  }

  /**
   * A details-update request body.
   */
  protected function detailsRequest(array $payload): Request {
    return Request::create('/daedalus-blueprint/stencil/x/details', 'POST', [], [], [], [], json_encode($payload));
  }

  /**
   * Decodes a JsonResponse.
   */
  protected function decode(JsonResponse $response): array {
    return json_decode((string) $response->getContent(), TRUE);
  }

  /**
   * Sets a cache probe on the discovery tag; deleted means invalidated.
   */
  protected function setCacheProbe(): void {
    \Drupal::cache()->set('stencil_api_test_probe', TRUE, Cache::PERMANENT, [StencilDiscovery::CACHE_TAG]);
  }

  /**
   * Whether the probe survived (FALSE means the tag was invalidated).
   */
  protected function cacheProbeAlive(): bool {
    return \Drupal::cache()->get('stencil_api_test_probe') !== FALSE;
  }

  /**
   * The routes are POSTs behind CSRF and the one stencil permission.
   *
   * The requirements ARE the design: dedicated routes the AI's batch door
   * structurally cannot reach, gated like every other stencil mutation.
   */
  public function testRouteContract(): void {
    $provider = \Drupal::service('router.route_provider');
    foreach ([
      'daedalus_blueprint_stencil.update_details' => '/daedalus-blueprint/stencil/{reference}/details',
      'daedalus_blueprint_stencil.delete' => '/daedalus-blueprint/stencil/{reference}/delete',
      'daedalus_blueprint_stencil.release_variant' => '/daedalus-blueprint/stencil-variant/{reference}/release',
      'daedalus_blueprint_stencil.transfer_variant' => '/daedalus-blueprint/stencil-variant/{reference}/transfer',
    ] as $name => $path) {
      $route = $provider->getRouteByName($name);
      $this->assertSame($path, $route->getPath());
      $this->assertSame(['POST'], $route->getMethods());
      $this->assertSame('save stencils', $route->getRequirement('_permission'));
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
      $this->assertSame('TRUE', $route->getOption('no_cache'));
    }
  }

  /**
   * Details-update relabels the palette face, revisioned, identity intact.
   */
  public function testUpdateDetails(): void {
    $stencil = $this->createStencil();
    $this->setCacheProbe();

    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'offered' => FALSE,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->decode($response)['stencil'];
    $this->assertSame('Banner', $payload['label']);
    $this->assertSame('A wide banner.', $payload['description']);
    $this->assertFalse($payload['offered'], 'The face gesture flips the AI opt-out.');
    // The machine name is identity (provenance stamps reference it) and
    // survives any relabel.
    $this->assertSame('promo_card', $payload['machine_name']);

    // The route lane answers the same materials delta the op lane does:
    // the write's entity hook recorded the stencil, and the response
    // carries its fresh record (the relabeled face) as an upsert.
    $materials = $this->decode($response)['materials'];
    $this->assertSame([], $materials['remove']);
    $this->assertCount(1, $materials['upsert']);
    $this->assertSame('stencil', $materials['upsert'][0]['kind']);
    $this->assertSame('promo_card', $materials['upsert'][0]['id']);
    $this->assertSame('Banner', $materials['upsert'][0]['label']);
    $this->assertSame('A wide banner.', $materials['upsert'][0]['description']);
    $this->assertTrue($materials['upsert'][0]['updatable']);
    $this->assertFalse($materials['upsert'][0]['offered']);

    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    assert($storage instanceof RevisionableStorageInterface);
    $reloaded = $storage->loadUnchanged($stencil->id());
    $this->assertSame('Banner', (string) $reloaded->label());
    $this->assertSame('A wide banner.', $reloaded->getDescription());
    $this->assertFalse($reloaded->isOffered());
    // Revisioned like push: a bad rename is one revert away.
    $revisions = $storage->getQuery()
      ->allRevisions()
      ->condition('id', $stencil->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(2, $revisions);

    // Stencil CRUD invalidates the one vocabulary tag (StencilCacheHooks),
    // so discovery and every palette consumer refresh without the routes
    // knowing about caches.
    $this->assertFalse($this->cacheProbeAlive());
    $discovered = \Drupal::service(StencilDiscoveryInterface::class)->getStencil('promo_card');
    $this->assertSame('Banner', $discovered['label']);
    $this->assertSame(StencilDiscoveryInterface::SOURCE_CONTENT, $discovered['source']);
  }

  /**
   * Details-update refuses empty fields and malformed bodies loudly.
   */
  public function testUpdateDetailsValidation(): void {
    $this->createStencil();

    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => '   ',
      'description' => 'Still described.',
    ]));
    $this->assertSame(422, $response->getStatusCode());
    $errors = $this->decode($response)['errors'];
    $this->assertSame('MISSING_PARAMETER', $errors[0]['code']);
    $this->assertSame('label', $errors[0]['path']);

    // The description is half the palette entry's face; it cannot be
    // blanked through this route any more than through the save gesture.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => '',
    ]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('description', $this->decode($response)['errors'][0]['path']);

    $malformed = Request::create('/daedalus-blueprint/stencil/x/details', 'POST', [], [], [], [], 'not json');
    $response = $this->controller()->updateDetails('promo_card', $malformed);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('INVALID_PAYLOAD', $this->decode($response)['errors'][0]['code']);

    // Nothing stuck: the refusals happened before any save.
    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    $reloaded = $storage->loadByProperties(['machine_name' => 'promo_card']);
    $this->assertSame('Promo Card', (string) reset($reloaded)->label());
  }

  /**
   * The details route's icon presence semantics: write, clear, leave.
   *
   * PRESENT writes (NULL or '' clears the icon back to the palette
   * default), ABSENT leaves the stored icon standing, so a client speaking
   * only the older two-field face never wipes an icon it did not offer to
   * edit. The response payload speaks the catalog's media spelling.
   */
  public function testUpdateDetailsIconSemantics(): void {
    $media = $this->setUpIconMedia();
    $stencil = $this->createStencil();
    $reference = 'media:' . $media->uuid();

    // Present writes: the payload and the stored entity both carry it.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Promo Card',
      'description' => 'Two-column card: heading beside an image.',
      'icon' => $reference,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($reference, $this->decode($response)['stencil']['icon']);
    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    $reloaded = $storage->loadUnchanged($stencil->id());
    $this->assertSame($media->uuid(), $reloaded->getIcon()?->uuid());

    // Absent leaves the stored icon standing through a relabel.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($reference, $this->decode($response)['stencil']['icon']);
    $this->assertSame($media->uuid(), $storage->loadUnchanged($stencil->id())->getIcon()?->uuid());

    // NULL clears back to the palette default.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'icon' => NULL,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull($this->decode($response)['stencil']['icon']);
    $this->assertNull($storage->loadUnchanged($stencil->id())->getIcon());

    // The empty string clears too (an emptied picker submits '').
    $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'icon' => $reference,
    ]));
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'icon' => '',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull($this->decode($response)['stencil']['icon']);
    $this->assertNull($storage->loadUnchanged($stencil->id())->getIcon());
  }

  /**
   * The details route refuses bad icon values loudly, storing nothing.
   */
  public function testUpdateDetailsIconRefusals(): void {
    $this->setUpIconMedia();
    $stencil = $this->createStencil();
    $face = ['label' => 'Promo Card', 'description' => 'Two-column card: heading beside an image.'];

    // A non-string, non-null icon is a malformed body.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => 123]));
    $this->assertSame(422, $response->getStatusCode());
    $error = $this->decode($response)['errors'][0];
    $this->assertSame('INVALID_PARAMETER', $error['code']);
    $this->assertSame('icon', $error['path']);

    // The path spelling belongs to module-shipped stencil files.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => '/modules/x/assets/icon.svg']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('INVALID_PARAMETER', $this->decode($response)['errors'][0]['code']);

    // Unknown media refuses at write time.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => 'media:11111111-2222-3333-4444-555555555555']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STENCIL_ICON_UNKNOWN_MEDIA', $this->decode($response)['errors'][0]['code']);

    // Nothing stuck: the refusals happened before any save.
    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    $reloaded = $storage->loadUnchanged($stencil->id());
    $this->assertNull($reloaded->getIcon());
    $this->assertSame('Promo Card', (string) $reloaded->label());
  }

  /**
   * Installs the media stack and returns a saved media item for icon tests.
   *
   * The icon reference needs no source file: the route stores and refuses
   * on the media ENTITY; URL resolution is IconResolver's separately tested
   * job.
   */
  protected function setUpIconMedia(): Media {
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    $type = $this->createMediaType('image');
    $media = Media::create(['bundle' => $type->id(), 'name' => 'Stencil icon']);
    $media->save();
    return $media;
  }

  /**
   * File-shipped stencils are refused loudly; unknown references 404.
   *
   * The resolver speaks for both routes: a file stencil gets its own
   * READ_ONLY error (never a bare not-found) because the client should say
   * WHY, and the fixture 'hero' ships from daedalus_blueprint_stencil_test.
   */
  public function testFileShippedAndUnknownReferences(): void {
    $response = $this->controller()->updateDetails('hero', $this->detailsRequest([
      'label' => 'New Hero',
      'description' => 'Nope.',
    ]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STENCIL_READ_ONLY', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('hero');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STENCIL_READ_ONLY', $this->decode($response)['errors'][0]['code']);
    $this->assertNotNull(\Drupal::service(StencilDiscoveryInterface::class)->getStencil('hero'));

    $response = $this->controller()->updateDetails('nonexistent', $this->detailsRequest([
      'label' => 'X',
      'description' => 'Y',
    ]));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('STENCIL_NOT_FOUND', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('nonexistent');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('STENCIL_NOT_FOUND', $this->decode($response)['errors'][0]['code']);
  }

  /**
   * Delete removes the entity and the palette entry through the one tag.
   */
  public function testDelete(): void {
    $stencil = $this->createStencil();
    $this->setCacheProbe();

    $response = $this->controller()->delete('promo_card');
    $this->assertSame(200, $response->getStatusCode());
    // The response echoes the deleted face so the palette can patch itself.
    $deleted = $this->decode($response)['deleted'];
    $this->assertSame('promo_card', $deleted['machine_name']);
    $this->assertSame('Promo Card', $deleted['label']);
    // And as a material: the delta resolves the touched id against the
    // fresh roster, which no longer carries it, so the store removes it.
    $this->assertSame(
      ['upsert' => [], 'remove' => [['kind' => 'stencil', 'id' => 'promo_card']]],
      $this->decode($response)['materials'],
    );

    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    $this->assertNull($storage->loadUnchanged($stencil->id()));
    $this->assertFalse($this->cacheProbeAlive());
    $this->assertNull(\Drupal::service(StencilDiscoveryInterface::class)->getStencil('promo_card'));
  }

  /**
   * The entity-access gate holds even past the route permission.
   *
   * In real traffic the route's _permission requirement rejects first;
   * the controller still asks the stencil's own access handler so the
   * handler stays the single authority on stencil mutation.
   */
  public function testEntityAccessGate(): void {
    $stencil = $this->createStencil();
    $this->container->get('current_user')->setAccount($this->createUser());

    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Hijacked',
      'description' => 'Nope.',
    ]));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STENCIL_FORBIDDEN', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('promo_card');
    $this->assertSame(403, $response->getStatusCode());

    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    $reloaded = $storage->loadUnchanged($stencil->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('Promo Card', (string) $reloaded->label());
  }

}
