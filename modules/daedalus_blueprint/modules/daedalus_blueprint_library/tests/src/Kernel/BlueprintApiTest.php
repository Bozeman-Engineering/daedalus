<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint_library\Controller\BlueprintApiController;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the blueprint gesture routes outside the op spine: details, delete.
 *
 * These gestures are deliberately NOT blueprint ops (on the spine, "is an
 * op" IS "the AI can call it", and ops mutate tempstore-staged state with
 * a save/discard net; deletion is site-wide and net-less), so the coverage
 * here is the route contract itself: the dedicated endpoints, their CSRF +
 * permission requirements, the entity-access gate, the loud refusal of
 * file-shipped blueprints, the subject and allow-list the details face
 * rewrites, the materials delta resolved for the canvas the request names,
 * and discovery staying in step through the one cache tag.
 */
#[CoversClass(BlueprintApiController::class)]
#[CoversClass(BlueprintManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintApiTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    // The blueprint entity's icon field references media, its scope is a
    // list_string and its document is core's Layout Builder override
    // field, so installing the blueprint schema needs their modules.
    'field',
    'file',
    'image',
    'media',
    'options',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_blueprint_library',
    'daedalus_blueprint_library_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    // setUpCurrentUser() seeds a throwaway uid 1 first, so this user's
    // section permissions are what entity access actually evaluates.
    $this->setUpCurrentUser([], ['create section blueprint', 'edit any section blueprint', 'delete any section blueprint']);
  }

  /**
   * The controller under test, container-built like the route would.
   */
  protected function controller(): BlueprintApiController {
    return BlueprintApiController::create($this->container);
  }

  /**
   * Creates and saves a content blueprint ("promo_card" by default).
   *
   * The fixture module already ships FILE blueprints named call_to_action
   * and hero, and blueprint IDs are one flat vocabulary, so the content
   * blueprint under test needs a name of its own.
   */
  protected function createBlueprint(array $values = []): Blueprint {
    $section = new Section('layout_twocol_section');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', \Drupal::service('uuid')->generate());
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
      'label' => 'Promo Card',
      'description' => 'Two-column card: heading beside an image.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      'layout_builder__layout' => [$section],
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * A details-update request body, naming the canvas the query carries.
   *
   * @param array $payload
   *   The JSON body.
   * @param array $query
   *   The query: the canvas coordinates (entity_type, entity_id) the
   *   client carries, or [] for a request naming no canvas.
   */
  protected function detailsRequest(array $payload, array $query = []): Request {
    return Request::create('/daedalus-blueprint/blueprint/x/details' . $this->queryString($query), 'POST', [], [], [], [], json_encode($payload));
  }

  /**
   * A delete request (no body), naming the canvas the query carries.
   */
  protected function deleteRequest(array $query = []): Request {
    return Request::create('/daedalus-blueprint/blueprint/x/delete' . $this->queryString($query), 'POST');
  }

  /**
   * The query string for a request's canvas coordinates, or ''.
   */
  protected function queryString(array $query): string {
    return $query === [] ? '' : '?' . http_build_query($query);
  }

  /**
   * The canvas coordinates of a saved entity_test entity.
   *
   * As the client names them: the root entity's entity_type and entity_id.
   */
  protected function createCanvas(): array {
    $entity = EntityTest::create(['name' => 'A canvas']);
    $entity->save();
    return ['entity_type' => 'entity_test', 'entity_id' => (string) $entity->id()];
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
    \Drupal::cache()->set('blueprint_api_test_probe', TRUE, Cache::PERMANENT, [BlueprintDiscovery::CACHE_TAG]);
  }

  /**
   * Whether the probe survived (FALSE means the tag was invalidated).
   */
  protected function cacheProbeAlive(): bool {
    return \Drupal::cache()->get('blueprint_api_test_probe') !== FALSE;
  }

  /**
   * The routes are POSTs behind CSRF and the one blueprint permission.
   *
   * The requirements ARE the design: dedicated routes the AI's batch door
   * structurally cannot reach, gated like every other blueprint mutation.
   */
  public function testRouteContract(): void {
    $provider = \Drupal::service('router.route_provider');
    foreach ([
      'daedalus_blueprint_library.update_details' => ['/daedalus-blueprint/blueprint/{reference}/details', 'update'],
      'daedalus_blueprint_library.delete' => ['/daedalus-blueprint/blueprint/{reference}/delete', 'delete'],
      'daedalus_blueprint_library.release_variant' => ['/daedalus-blueprint/blueprint-variant/{reference}/release', 'update'],
      'daedalus_blueprint_library.transfer_variant' => ['/daedalus-blueprint/blueprint-variant/{reference}/transfer', 'update'],
    ] as $name => [$path, $operation]) {
      $route = $provider->getRouteByName($name);
      $this->assertSame($path, $route->getPath());
      $this->assertSame(['POST'], $route->getMethods());
      // The any-type gate for the operation; the provider asks the
      // resolved blueprint's own type for real.
      $this->assertSame($operation, $route->getRequirement('_blueprint_type_access'));
      $this->assertNull($route->getRequirement('_permission'));
      $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
      $this->assertSame('TRUE', $route->getOption('no_cache'));
    }
    // The listing's "Add blueprint" door: core's add page (a type to pick,
    // behind any type's create access) then the per-type add form behind
    // that type's create access, landing on the canvas (BlueprintAddForm).
    $page = $provider->getRouteByName('entity.blueprint.add_page');
    $this->assertSame('/admin/content/blueprints/add', $page->getPath());
    $this->assertSame('blueprint', $page->getRequirement('_entity_create_any_access'));
    $add = $provider->getRouteByName('entity.blueprint.add_form');
    $this->assertSame('/admin/content/blueprints/add/{blueprint_type}', $add->getPath());
    $this->assertSame('blueprint:{blueprint_type}', $add->getRequirement('_entity_create_access'));
    $this->assertSame('blueprint.add', $add->getDefault('_entity_form'));
  }

  /**
   * Details-update relabels the palette face, revisioned, identity intact.
   */
  public function testUpdateDetails(): void {
    $blueprint = $this->createBlueprint();
    $this->setCacheProbe();

    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'offered' => FALSE,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $payload = $this->decode($response)['blueprint'];
    $this->assertSame('Banner', $payload['label']);
    $this->assertSame('A wide banner.', $payload['description']);
    $this->assertFalse($payload['offered'], 'The face gesture flips the AI opt-out.');
    // The machine name is identity (provenance stamps reference it) and
    // survives any relabel; so are the scope and, absent from the body,
    // the subject.
    $this->assertSame('promo_card', $payload['machine_name']);
    $this->assertSame('block', $payload['scope']);
    $this->assertSame('entity_test:entity_test', $payload['subject']);

    // The route lane answers the same materials delta the op lane does:
    // the write's entity hook recorded the blueprint, and the response
    // carries its fresh record (the relabeled face) as an upsert.
    $materials = $this->decode($response)['materials'];
    $this->assertSame([], $materials['remove']);
    $this->assertCount(1, $materials['upsert']);
    $this->assertSame('blueprint', $materials['upsert'][0]['kind']);
    $this->assertSame('promo_card', $materials['upsert'][0]['id']);
    $this->assertSame('Banner', $materials['upsert'][0]['label']);
    $this->assertSame('A wide banner.', $materials['upsert'][0]['description']);
    $this->assertTrue($materials['upsert'][0]['updatable']);
    $this->assertFalse($materials['upsert'][0]['offered']);

    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    assert($storage instanceof RevisionableStorageInterface);
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame('Banner', (string) $reloaded->label());
    $this->assertSame('A wide banner.', $reloaded->getDescription());
    $this->assertFalse($reloaded->isOffered());
    // Revisioned like push: a bad rename is one revert away.
    $revisions = $storage->getQuery()
      ->allRevisions()
      ->condition('id', $blueprint->id())
      ->accessCheck(FALSE)
      ->execute();
    $this->assertCount(2, $revisions);

    // Blueprint CRUD invalidates the one vocabulary tag
    // (BlueprintCacheHooks), so discovery and every palette consumer
    // refresh without the routes knowing about caches.
    $this->assertFalse($this->cacheProbeAlive());
    $discovered = \Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('promo_card');
    $this->assertSame('Banner', $discovered['label']);
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_CONTENT, $discovered['source']);
  }

  /**
   * The details face rewrites the subject and the allow-list, loudly.
   *
   * The subject must name a fieldable bundle on the site (the vocabulary
   * BlueprintSubjects speaks); the allow-list is shape-checked and []
   * clears it. Both ride presence semantics: absent keys leave the stored
   * values standing, as the icon does.
   */
  public function testUpdateDetailsSubjectAndHosts(): void {
    $blueprint = $this->createBlueprint();
    $face = ['label' => 'Promo Card', 'description' => 'Two-column card: heading beside an image.'];
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');

    // entity_test_rev is a fieldable bundle on this site; a page-builder
    // display is what OFFERS it in the dialog, not what the gesture needs.
    $this->installEntitySchema('entity_test_rev');
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + [
      'subject' => 'entity_test_rev:entity_test_rev',
      'bundles' => ['entity_test_rev:entity_test_rev', 'node:page', 'node:page'],
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $payload = $this->decode($response)['blueprint'];
    $this->assertSame('entity_test_rev:entity_test_rev', $payload['subject']);
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame('entity_test_rev', $reloaded->getSubjectType());
    $this->assertSame('entity_test_rev', $reloaded->getSubjectBundle());
    $this->assertSame(['entity_test_rev:entity_test_rev', 'node:page'], $reloaded->getBundles(), 'The allow-list is stored de-duplicated, in order.');
    $this->assertStringContainsString('Subject changed to entity_test_rev:entity_test_rev.', (string) $reloaded->getRevisionLogMessage());

    // Absent keys leave both standing through a relabel.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame('entity_test_rev', $reloaded->getSubjectType());
    $this->assertSame(['entity_test_rev:entity_test_rev', 'node:page'], $reloaded->getBundles());

    // An empty list clears the allow-list: any host it fits.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => []]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $storage->loadUnchanged($blueprint->id())->getBundles());

    // Groups ride the same presence semantics: a list of ids is stored
    // de-duplicated in order and logged, an absent key leaves it, an empty
    // list clears it, and a malformed list refuses naming the path.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + [
      'groups' => ['heroes', 'heroes', 'bands'],
    ]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame(['heroes', 'bands'], $reloaded->getGroups());
    $this->assertStringContainsString('Groups: heroes, bands.', (string) $reloaded->getRevisionLogMessage());
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['heroes', 'bands'], $storage->loadUnchanged($blueprint->id())->getGroups());
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['groups' => ['Heroes!']]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('groups', $this->decode($response)['errors'][0]['path']);
    $this->assertSame(['heroes', 'bands'], $storage->loadUnchanged($blueprint->id())->getGroups(), 'Nothing stuck.');
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['groups' => []]));
    $this->assertSame(200, $response->getStatusCode());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame([], $reloaded->getGroups());
    $this->assertStringContainsString('Groups cleared.', (string) $reloaded->getRevisionLogMessage());

    // The favorite rides the same presence semantics: a boolean is stored
    // and logged, an absent key leaves it, a non-boolean refuses naming
    // the path.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['favorite' => TRUE]));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertTrue($reloaded->isFavorite());
    $this->assertStringContainsString('Marked a favorite.', (string) $reloaded->getRevisionLogMessage());
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($storage->loadUnchanged($blueprint->id())->isFavorite(), 'An absent key leaves the favorite standing.');
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['favorite' => 'yes']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('favorite', $this->decode($response)['errors'][0]['path']);
    $this->assertTrue($storage->loadUnchanged($blueprint->id())->isFavorite(), 'Nothing stuck.');
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['favorite' => FALSE]));
    $this->assertSame(200, $response->getStatusCode());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertFalse($reloaded->isFavorite());
    $this->assertStringContainsString('No longer a favorite.', (string) $reloaded->getRevisionLogMessage());

    // A malformed subject, an unknown bundle and a malformed allow-list
    // refuse before any save, naming the path.
    foreach ([
      ['subject' => 'node'],
      ['subject' => 'node:nope'],
      ['subject' => 'not_a_type:x'],
    ] as $bad) {
      $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + $bad));
      $this->assertSame(422, $response->getStatusCode());
      $error = $this->decode($response)['errors'][0];
      $this->assertSame('INVALID_PARAMETER', $error['code']);
      $this->assertSame('subject', $error['path']);
    }
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => ['node']]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('bundles', $this->decode($response)['errors'][0]['path']);
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => 'node:page']));
    $this->assertSame(422, $response->getStatusCode());
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame('entity_test_rev', $reloaded->getSubjectType(), 'Nothing stuck.');
  }

  /**
   * The subject vocabulary offers the page-builder bundles, labelled.
   */
  public function testSubjectOptions(): void {
    $subjects = $this->container->get('daedalus_blueprint_library.subjects');
    // Only the blueprint type's own display is Layout Builder enabled so
    // far, and a blueprint is never material for blueprints.
    $this->assertSame([], $subjects->options());

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $this->assertSame(['entity_test:entity_test' => 'Test entity: Entity Test Bundle'], $subjects->options());
    $this->assertSame('Test entity: Entity Test Bundle', $subjects->label('entity_test', 'entity_test'));
    $this->assertNull($subjects->label('entity_test', 'nope'));
    $this->assertNull($subjects->label('user_role', 'user_role'), 'A config entity type is not fieldable.');
    $this->assertSame(['entity_test', 'entity_test'], $subjects->parse('entity_test:entity_test'));
    $this->assertSame(['node:page', 'node:article'], $subjects->parseBundles(['node:page', 'node:article', 'node:page']));
    $this->assertSame([], $subjects->parseBundles(NULL));
  }

  /**
   * Details-update refuses empty fields and malformed bodies loudly.
   */
  public function testUpdateDetailsValidation(): void {
    $this->createBlueprint();

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

    $malformed = Request::create('/daedalus-blueprint/blueprint/x/details', 'POST', [], [], [], [], 'not json');
    $response = $this->controller()->updateDetails('promo_card', $malformed);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('INVALID_PAYLOAD', $this->decode($response)['errors'][0]['code']);

    // Nothing stuck: the refusals happened before any save.
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
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
    $blueprint = $this->createBlueprint();
    $reference = 'media:' . $media->uuid();

    // Present writes: the payload and the stored entity both carry it.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Promo Card',
      'description' => 'Two-column card: heading beside an image.',
      'icon' => $reference,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($reference, $this->decode($response)['blueprint']['icon']);
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertSame($media->uuid(), $reloaded->getIcon()?->uuid());

    // Absent leaves the stored icon standing through a relabel.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame($reference, $this->decode($response)['blueprint']['icon']);
    $this->assertSame($media->uuid(), $storage->loadUnchanged($blueprint->id())->getIcon()?->uuid());

    // NULL clears back to the palette default.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Banner',
      'description' => 'A wide banner.',
      'icon' => NULL,
    ]));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertNull($this->decode($response)['blueprint']['icon']);
    $this->assertNull($storage->loadUnchanged($blueprint->id())->getIcon());

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
    $this->assertNull($this->decode($response)['blueprint']['icon']);
    $this->assertNull($storage->loadUnchanged($blueprint->id())->getIcon());
  }

  /**
   * The details route refuses bad icon values loudly, storing nothing.
   */
  public function testUpdateDetailsIconRefusals(): void {
    $this->setUpIconMedia();
    $blueprint = $this->createBlueprint();
    $face = ['label' => 'Promo Card', 'description' => 'Two-column card: heading beside an image.'];

    // A non-string, non-null icon is a malformed body.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => 123]));
    $this->assertSame(422, $response->getStatusCode());
    $error = $this->decode($response)['errors'][0];
    $this->assertSame('INVALID_PARAMETER', $error['code']);
    $this->assertSame('icon', $error['path']);

    // The path spelling belongs to module-shipped blueprint files.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => '/modules/x/assets/icon.svg']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('INVALID_PARAMETER', $this->decode($response)['errors'][0]['code']);

    // Unknown media refuses at write time.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['icon' => 'media:11111111-2222-3333-4444-555555555555']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_ICON_UNKNOWN_MEDIA', $this->decode($response)['errors'][0]['code']);

    // Nothing stuck: the refusals happened before any save.
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $reloaded = $storage->loadUnchanged($blueprint->id());
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
    $media = Media::create(['bundle' => $type->id(), 'name' => 'Blueprint icon']);
    $media->save();
    return $media;
  }

  /**
   * File-shipped blueprints are refused loudly; unknown references 404.
   *
   * The resolver speaks for both routes: a file blueprint gets its own
   * READ_ONLY error (never a bare not-found) because the client should say
   * WHY, and the fixture 'hero' ships from daedalus_blueprint_library_test.
   */
  public function testFileShippedAndUnknownReferences(): void {
    $response = $this->controller()->updateDetails('hero', $this->detailsRequest([
      'label' => 'New Hero',
      'description' => 'Nope.',
    ]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_READ_ONLY', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('hero', $this->deleteRequest());
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_READ_ONLY', $this->decode($response)['errors'][0]['code']);
    $this->assertNotNull(\Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('hero'));

    $response = $this->controller()->updateDetails('nonexistent', $this->detailsRequest([
      'label' => 'X',
      'description' => 'Y',
    ]));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_NOT_FOUND', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('nonexistent', $this->deleteRequest());
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_NOT_FOUND', $this->decode($response)['errors'][0]['code']);
  }

  /**
   * Delete removes the entity and the palette entry through the one tag.
   */
  public function testDelete(): void {
    $blueprint = $this->createBlueprint();
    $this->setCacheProbe();

    $response = $this->controller()->delete('promo_card', $this->deleteRequest());
    $this->assertSame(200, $response->getStatusCode());
    // The response echoes the deleted face so the palette can patch itself.
    $deleted = $this->decode($response)['deleted'];
    $this->assertSame('promo_card', $deleted['machine_name']);
    $this->assertSame('Promo Card', $deleted['label']);
    // And as a material: the delta resolves the touched id against the
    // fresh roster, which no longer carries it, so the store removes it.
    $this->assertSame(
      ['upsert' => [], 'remove' => [['kind' => 'blueprint', 'id' => 'promo_card']]],
      $this->decode($response)['materials'],
    );

    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $this->assertNull($storage->loadUnchanged($blueprint->id()));
    $this->assertFalse($this->cacheProbeAlive());
    $this->assertNull(\Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('promo_card'));
  }

  /**
   * The delta is resolved for the canvas the request names.
   *
   * The Add panel is open on a page; a details change that ends the
   * blueprint's fit there (an allow-list excluding the canvas) must leave
   * the panel at once, so the response lists the blueprint under remove,
   * as it would one the roster lost. The same change named from no canvas
   * is the upsert it always was: with no canvas the delta is unfiltered.
   * A canvas that does not exist is a client bug and refuses before the
   * write, nothing stuck.
   */
  public function testTheDeltaIsResolvedForTheNamedCanvas(): void {
    $blueprint = $this->createBlueprint();
    $canvas = $this->createCanvas();
    $face = ['label' => 'Promo Card', 'description' => 'Two-column card: heading beside an image.'];
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');

    // The allow-list excludes the canvas: a remove on this canvas.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => ['node:page']], $canvas));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(
      ['upsert' => [], 'remove' => [['kind' => 'blueprint', 'id' => 'promo_card']]],
      $this->decode($response)['materials'],
      'A touched blueprint the named canvas does not offer is a remove.',
    );
    $this->assertSame(['node:page'], $storage->loadUnchanged($blueprint->id())->getBundles(), 'The write landed; only the delta is judged for the canvas.');

    // The same post naming no canvas: an unfiltered upsert.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => ['node:page']]));
    $this->assertSame(200, $response->getStatusCode());
    $materials = $this->decode($response)['materials'];
    $this->assertSame([], $materials['remove']);
    $this->assertSame(['promo_card'], array_column($materials['upsert'], 'id'), 'With no canvas the delta is unfiltered.');

    // An allow-list that includes the canvas: the upsert, judged for it.
    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest($face + ['bundles' => ['entity_test:entity_test']], $canvas));
    $this->assertSame(200, $response->getStatusCode());
    $materials = $this->decode($response)['materials'];
    $this->assertSame([], $materials['remove']);
    $this->assertSame(['promo_card'], array_column($materials['upsert'], 'id'), 'A touched blueprint the named canvas offers is an upsert.');

    // A canvas that does not exist refuses before the write.
    foreach ([
      [['entity_type' => 'not_a_type', 'entity_id' => '1'], 'entity_type'],
      [['entity_type' => 'entity_test', 'entity_id' => '999'], 'entity_id'],
      [['entity_type' => 'entity_test'], 'entity_id'],
    ] as [$query, $path]) {
      $request = $this->detailsRequest(['label' => 'Nope', 'description' => 'Nope.'], $query);
      $response = $this->controller()->updateDetails('promo_card', $request);
      $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());
      $error = $this->decode($response)['errors'][0];
      $this->assertSame('INVALID_PARAMETER', $error['code']);
      $this->assertSame($path, $error['path']);
    }
    $this->assertSame('Promo Card', (string) $storage->loadUnchanged($blueprint->id())->label(), 'Nothing stuck.');

    // Delete names its canvas too; a removal is a remove on any canvas.
    $response = $this->controller()->delete('promo_card', $this->deleteRequest($canvas));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      ['upsert' => [], 'remove' => [['kind' => 'blueprint', 'id' => 'promo_card']]],
      $this->decode($response)['materials'],
    );
  }

  /**
   * The entity-access gate holds even past the route permission.
   *
   * In real traffic the route's _permission requirement rejects first;
   * the controller still asks the blueprint's own access handler so the
   * handler stays the single authority on blueprint mutation.
   */
  public function testEntityAccessGate(): void {
    $blueprint = $this->createBlueprint();
    $this->container->get('current_user')->setAccount($this->createUser());

    $response = $this->controller()->updateDetails('promo_card', $this->detailsRequest([
      'label' => 'Hijacked',
      'description' => 'Nope.',
    ]));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_FORBIDDEN', $this->decode($response)['errors'][0]['code']);

    $response = $this->controller()->delete('promo_card', $this->deleteRequest());
    $this->assertSame(403, $response->getStatusCode());

    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $reloaded = $storage->loadUnchanged($blueprint->id());
    $this->assertNotNull($reloaded);
    $this->assertSame('Promo Card', (string) $reloaded->label());
  }

}
