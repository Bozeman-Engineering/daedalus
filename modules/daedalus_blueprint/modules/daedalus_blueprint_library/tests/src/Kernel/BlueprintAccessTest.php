<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint_library\Access\BlueprintTypeAccess;
use Drupal\daedalus_blueprint_library\BlueprintAccessControlHandler;
use Drupal\daedalus_blueprint_library\BlueprintListBuilder;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\BlueprintPermissions;
use Drupal\daedalus_blueprint_library\Controller\BlueprintApiController;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_blueprint_library\Entity\BlueprintType;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests blueprint access along the type axis.
 *
 * Every permission over a blueprint is its TYPE's (BlueprintPermissions:
 * create, edit any, delete any, three per blueprint_type config entity),
 * the entity's access handler judges each blueprint by its type, and the
 * surfaces about no one blueprint (the listing, the add page, the
 * gesture routes) open to an actor who holds the operation on ANY type
 * (BlueprintTypeAccess). The library ships `section` and `site_chrome`;
 * the two personas here are the recipe's: an editor holding `section`,
 * a site builder holding `site_chrome`.
 */
#[CoversClass(BlueprintAccessControlHandler::class)]
#[CoversClass(BlueprintApiController::class)]
#[CoversClass(BlueprintManager::class)]
#[CoversClass(BlueprintPermissions::class)]
#[CoversClass(BlueprintTypeAccess::class)]
#[CoversClass(BlueprintType::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    'field',
    'file',
    'image',
    'media',
    'options',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_library',
  ];

  /**
   * The editor: the section type's three permissions.
   */
  protected AccountInterface $editor;

  /**
   * The site builder: the site chrome type's three permissions.
   */
  protected AccountInterface $siteBuilder;

  /**
   * An account holding no blueprint permission.
   */
  protected AccountInterface $viewer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    EntityTestBundle::create(['id' => 'page', 'label' => 'Page'])->save();
    // The first created user is uid 1; the personas must not be it.
    $this->createUser([]);
    $this->editor = $this->createUser([
      'create section blueprint',
      'edit any section blueprint',
      'delete any section blueprint',
    ]);
    $this->siteBuilder = $this->createUser([
      'create site_chrome blueprint',
      'edit any site_chrome blueprint',
      'delete any site_chrome blueprint',
    ]);
    $this->viewer = $this->createUser([]);
  }

  /**
   * Creates and saves a published blueprint of a type.
   */
  protected function createBlueprint(string $type, array $values = []): Blueprint {
    $blueprint = Blueprint::create($values + [
      'type' => $type,
      'label' => ucfirst($type),
      'description' => 'A ' . $type . ' blueprint.',
      'scope' => 'block',
      'subject_type' => 'entity_test_with_bundle',
      'subject_bundle' => 'page',
      'status' => 1,
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * Three permissions per shipped type, each depending on its type's config.
   */
  public function testPermissionsPerType(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();
    foreach (['section', 'site_chrome'] as $type) {
      foreach (['create', 'update', 'delete'] as $operation) {
        $name = BlueprintPermissions::permission($type, $operation);
        $this->assertArrayHasKey($name, $permissions, "$name is defined.");
        $this->assertSame('daedalus_blueprint_library', $permissions[$name]['provider']);
        $this->assertSame(['daedalus_blueprint_library.blueprint_type.' . $type], $permissions[$name]['dependencies']['config'], 'The permission follows its type.');
      }
    }
    $this->assertSame('create section blueprint', BlueprintPermissions::permission('section', 'create'));
    $this->assertSame('edit any site_chrome blueprint', BlueprintPermissions::permission('site_chrome', 'update'));
    $this->assertSame('delete any section blueprint', BlueprintPermissions::permission('section', 'delete'));
    // The per-type set is ALL the module defines: no umbrella grant.
    $library = array_keys(array_filter($permissions, static fn (array $permission): bool => $permission['provider'] === 'daedalus_blueprint_library'));
    sort($library);
    $this->assertSame([
      'create section blueprint',
      'create site_chrome blueprint',
      'delete any section blueprint',
      'delete any site_chrome blueprint',
      'edit any section blueprint',
      'edit any site_chrome blueprint',
    ], $library);

    // A type added by config is grantable at once.
    BlueprintType::create(['id' => 'banner', 'label' => 'Banner'])->save();
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey('create banner blueprint', $permissions);
    $this->assertArrayHasKey('edit any banner blueprint', $permissions);
    $this->assertArrayHasKey('delete any banner blueprint', $permissions);

    // The shipped types, and what a type answers for.
    $section = BlueprintType::load('section');
    $this->assertSame('Section', $section->label());
    $this->assertNotSame('', $section->getDescription());
    $this->assertTrue($section->shouldCreateNewRevision(), 'Every commit of a blueprint is a revision: the storage handler asks the type.');
    $this->assertTrue($section->getThirdPartySetting('daedalus_ui', 'enabled', FALSE), 'A blueprint type is a page-builder bundle by construction.');
    $this->assertSame([], $section->getThirdPartyProviders(), 'Answered, not stored: the type carries no daedalus_ui dependency.');
    $this->assertSame('Site chrome', BlueprintType::load('site_chrome')->label());
  }

  /**
   * The access handler judges each blueprint by its own type.
   */
  public function testEntityAccessJudgesPerType(): void {
    $section = $this->createBlueprint('section');
    $chrome = $this->createBlueprint('site_chrome', ['machine_name' => 'header']);

    // Update and delete: the blueprint's type's grant, no other.
    $this->assertTrue($section->access('update', $this->editor));
    $this->assertTrue($section->access('delete', $this->editor));
    $this->assertFalse($section->access('update', $this->siteBuilder));
    $this->assertFalse($section->access('delete', $this->siteBuilder));
    $this->assertTrue($chrome->access('update', $this->siteBuilder));
    $this->assertTrue($chrome->access('delete', $this->siteBuilder));
    $this->assertFalse($chrome->access('update', $this->editor));
    $this->assertFalse($chrome->access('delete', $this->editor));
    $this->assertFalse($section->access('update', $this->viewer));
    $this->assertFalse($chrome->access('delete', $this->viewer));

    // Published blueprints are viewable by everyone: the palette lists them
    // for every editor, and the document is not a secret.
    $this->assertTrue($section->access('view', $this->viewer));
    $this->assertTrue($chrome->access('view', $this->editor));

    // Unpublished blueprints are visible only to those who may edit the type.
    $chrome->setUnpublished()->save();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('blueprint');
    $handler->resetCache();
    $this->assertFalse($chrome->access('view', $this->viewer));
    $this->assertFalse($chrome->access('view', $this->editor));
    $this->assertTrue($chrome->access('view', $this->siteBuilder));

    // Create: per type, and nothing without one (a blueprint is created
    // AS a type).
    $this->assertTrue($handler->createAccess('section', $this->editor));
    $this->assertFalse($handler->createAccess('site_chrome', $this->editor));
    $this->assertTrue($handler->createAccess('site_chrome', $this->siteBuilder));
    $this->assertFalse($handler->createAccess('section', $this->siteBuilder));
    $this->assertFalse($handler->createAccess(NULL, $this->editor), 'A create question naming no type is not allowed.');
    $this->assertFalse($handler->createAccess('banner', $this->editor), 'An unknown type has no grant.');
  }

  /**
   * The any-type gate: the listing, the add page and the gesture routes.
   */
  public function testAnyTypeGate(): void {
    $gate = $this->container->get('daedalus_blueprint_library.type_access');
    $this->assertInstanceOf(BlueprintTypeAccess::class, $gate);
    $this->assertTrue($gate->anyType($this->editor, 'create')->isAllowed());
    $this->assertTrue($gate->anyType($this->siteBuilder, 'create')->isAllowed());
    $this->assertTrue($gate->anyType($this->siteBuilder, 'update', 'delete')->isAllowed());
    $this->assertFalse($gate->anyType($this->viewer, 'create', 'update', 'delete')->isAllowed());
    $result = $gate->anyType($this->viewer, 'update');
    assert($result instanceof AccessResult);
    $this->assertTrue($result->isNeutral(), 'No grant on any type is neutral, never forbidden.');
    $this->assertContains('user.permissions', $result->getCacheContexts());
    $this->assertContains('config:blueprint_type_list', $result->getCacheTags(), 'The answer varies with the set of types.');

    // An account holding a grant on one type only still passes: the
    // surface opens, and the blueprint it touches is judged by its type.
    $one = $this->createUser(['delete any site_chrome blueprint']);
    $this->assertTrue($gate->anyType($one, 'delete')->isAllowed());
    $this->assertFalse($gate->anyType($one, 'create', 'update')->isAllowed());

    $provider = $this->container->get('router.route_provider');
    $access = $this->container->get('access_manager');

    // The listing: any operation on any type.
    $collection = $provider->getRouteByName('entity.blueprint.collection');
    $this->assertSame('/admin/content/blueprints', $collection->getPath());
    $this->assertSame('create,update,delete', $collection->getRequirement('_blueprint_type_access'));
    $this->assertSame('blueprint', $collection->getDefault('_entity_list'));
    $this->assertTrue($collection->getOption('_admin_route'));
    $this->assertTrue($access->checkNamedRoute('entity.blueprint.collection', [], $this->editor));
    $this->assertTrue($access->checkNamedRoute('entity.blueprint.collection', [], $one));
    $this->assertFalse($access->checkNamedRoute('entity.blueprint.collection', [], $this->viewer));

    // The add page (core's: a card per type the actor may create) and the
    // per-type add form behind that type's create access.
    $this->assertTrue($access->checkNamedRoute('entity.blueprint.add_page', [], $this->editor));
    $this->assertFalse($access->checkNamedRoute('entity.blueprint.add_page', [], $this->viewer));
    $this->assertTrue($access->checkNamedRoute('entity.blueprint.add_form', ['blueprint_type' => 'section'], $this->editor));
    $this->assertFalse($access->checkNamedRoute('entity.blueprint.add_form', ['blueprint_type' => 'site_chrome'], $this->editor));
    $this->assertTrue($access->checkNamedRoute('entity.blueprint.add_form', ['blueprint_type' => 'site_chrome'], $this->siteBuilder));

    // The gesture routes: the operation on any type (the provider asks the
    // resolved blueprint's own type for real).
    $this->assertTrue($access->checkNamedRoute('daedalus_blueprint_library.update_details', ['reference' => 'x'], $this->siteBuilder));
    $this->assertFalse($access->checkNamedRoute('daedalus_blueprint_library.update_details', ['reference' => 'x'], $one), 'A delete grant opens no details route.');
    $this->assertTrue($access->checkNamedRoute('daedalus_blueprint_library.delete', ['reference' => 'x'], $one));
    $this->assertFalse($access->checkNamedRoute('daedalus_blueprint_library.delete', ['reference' => 'x'], $this->viewer));
  }

  /**
   * The listing: one row per blueprint, operations judged by its type.
   */
  public function testListingJudgesPerType(): void {
    $section = $this->createBlueprint('section');
    $chrome = $this->createBlueprint('site_chrome', ['machine_name' => 'header']);
    $this->container->get('current_user')->setAccount($this->editor);
    $builder = $this->container->get('entity_type.manager')->getListBuilder('blueprint');
    assert($builder instanceof BlueprintListBuilder);

    $this->assertArrayHasKey('type', $builder->buildHeader());
    $this->assertSame('Section', (string) $builder->buildRow($section)['type']);
    $this->assertSame('Site chrome', (string) $builder->buildRow($chrome)['type']);

    $this->assertArrayHasKey('edit', $builder->getOperations($section));
    $this->assertArrayHasKey('delete', $builder->getOperations($section));
    $this->assertArrayNotHasKey('edit', $builder->getOperations($chrome), 'The editor has no operation on site chrome.');
    $this->assertArrayNotHasKey('delete', $builder->getOperations($chrome));
  }

  /**
   * Installs daedalus_stylish, which owns the variant entity type.
   *
   * The ownership fields this module provides exist only on
   * daedalus_variant, so the gestures over them need the module; kernel
   * tests do not resolve dependencies, so its chain is listed.
   */
  protected function installVariants(): void {
    $this->enableModules([
      'text',
      'block',
      'block_content',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_stylish',
    ]);
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    // With block_content installed, Layout Builder tracks inline block
    // usage on every content entity delete.
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
  }

  /**
   * Creates a saved, unowned, site-made variant.
   */
  protected function createVariant(string $machine_name): ContentEntityInterface {
    $variant = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['background-color' => 'aliceblue']]],
    ]);
    $variant->save();
    assert($variant instanceof ContentEntityInterface);
    return $variant;
  }

  /**
   * The id of the blueprint that owns a variant in storage, or NULL.
   */
  protected function storedOwner(ContentEntityInterface $variant): ?int {
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $storage->resetCache();
    $owner = $storage->load($variant->id())->get('blueprint')->target_id;
    return $owner === NULL ? NULL : (int) $owner;
  }

  /**
   * Runs a gesture as an account and answers the refusal's code and path.
   *
   * @return array{0: string, 1: string}|null
   *   The first error's code and path, or NULL when the gesture ran.
   */
  protected function refusal(AccountInterface $account, callable $gesture): ?array {
    $this->container->get('current_user')->setAccount($account);
    try {
      $gesture($this->container->get('daedalus_blueprint_library.manager'));
    }
    catch (BlueprintGestureException $exception) {
      $error = $exception->getErrors()[0];
      return [$error->getCode(), $error->getPath()];
    }
    return NULL;
  }

  /**
   * Adopt, release and transfer ask update on everything they touch.
   *
   * An ownership gesture rewrites a field of the variant and changes what
   * one or two blueprints offer, so it asks the variant's access handler
   * and each of those blueprints' own, whichever road reached it (the
   * capture ops for adopt, the routes for release and transfer). The
   * route's gate stays the any-type question; these are the answers about
   * the things themselves.
   */
  public function testVariantGesturesAskWhatTheyTouch(): void {
    $this->installVariants();
    $section_grants = ['create section blueprint', 'edit any section blueprint'];
    $curator = $this->createUser([...$section_grants, 'update variants']);
    $no_variant = $this->createUser($section_grants);
    $chrome_curator = $this->createUser(['edit any site_chrome blueprint', 'update variants']);

    $card = $this->createBlueprint('section', ['machine_name' => 'card']);
    $teaser = $this->createBlueprint('section', ['machine_name' => 'teaser']);
    $header = $this->createBlueprint('site_chrome', ['machine_name' => 'header']);
    $look = $this->createVariant('look');

    // Adopt: the variant, and the blueprint taking it.
    $adopt = static fn (BlueprintManager $manager) => $manager->adoptVariants($card, ['look']);
    $this->assertSame(['BLUEPRINT_VARIANT_FORBIDDEN', 'adopt_variants'], $this->refusal($no_variant, $adopt), 'Adopting asks update on the variant.');
    $this->assertSame(['BLUEPRINT_FORBIDDEN', 'id'], $this->refusal($chrome_curator, $adopt), 'Adopting asks update on the blueprint.');
    $this->assertNull($this->storedOwner($look), 'A refused adoption writes nothing.');
    $this->assertNull($this->refusal($curator, $adopt));
    $this->assertSame((int) $card->id(), $this->storedOwner($look));

    // Release: the variant, and the blueprint that owns it.
    $release = static fn (BlueprintManager $manager) => $manager->releaseVariant('look');
    $this->assertSame(['BLUEPRINT_VARIANT_FORBIDDEN', 'variant'], $this->refusal($no_variant, $release), 'Releasing asks update on the variant.');
    $this->assertSame(['BLUEPRINT_FORBIDDEN', 'variant'], $this->refusal($chrome_curator, $release), 'Releasing asks update on the owner.');
    $this->assertSame((int) $card->id(), $this->storedOwner($look), 'A refused release writes nothing.');

    // Transfer: the variant, the blueprint that owns it and the one
    // taking it.
    $to_header = static fn (BlueprintManager $manager) => $manager->transferVariant('look', $header);
    $to_teaser = static fn (BlueprintManager $manager) => $manager->transferVariant('look', $teaser);
    $this->assertSame(['BLUEPRINT_VARIANT_FORBIDDEN', 'variant'], $this->refusal($no_variant, $to_teaser), 'Transferring asks update on the variant.');
    $this->assertSame(['BLUEPRINT_FORBIDDEN', 'variant'], $this->refusal($chrome_curator, $to_header), 'Transferring asks update on the owner it takes from.');
    $this->assertSame(['BLUEPRINT_FORBIDDEN', 'blueprint'], $this->refusal($curator, $to_header), 'Transferring asks update on the blueprint taking it.');
    $this->assertSame((int) $card->id(), $this->storedOwner($look), 'A refused transfer writes nothing.');
    $this->assertNull($this->refusal($curator, $to_teaser));
    $this->assertSame((int) $teaser->id(), $this->storedOwner($look));

    // The routes answer both refusals as a 403, and write nothing.
    $controller = BlueprintApiController::create($this->container);
    $this->container->get('current_user')->setAccount($no_variant);
    $response = $controller->releaseVariant('look', Request::create('/daedalus-blueprint/blueprint-variant/look/release', 'POST'));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_VARIANT_FORBIDDEN', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->container->get('current_user')->setAccount($chrome_curator);
    $response = $controller->transferVariant('look', Request::create('/daedalus-blueprint/blueprint-variant/look/transfer', 'POST', [], [], [], [], json_encode(['blueprint' => 'header'])));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_FORBIDDEN', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertSame((int) $teaser->id(), $this->storedOwner($look));

    // An orphan has no owner left to ask: whoever may update the variant
    // releases it, which is the gesture that makes it usable again.
    $teaser->delete();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $this->assertNull($this->refusal($chrome_curator, $release));
    $this->assertNull($this->storedOwner($look));
  }

  /**
   * A gesture route's canvas is an entity the actor may update.
   *
   * The query names the page the Add panel is open on, which is a page
   * its user is editing. An entity the actor may not update is answered
   * in the words of one that does not exist, before the write.
   */
  public function testTheNamedCanvasAsksUpdate(): void {
    $this->installEntitySchema('entity_test');
    $this->installConfig(['daedalus_blueprint']);
    $grants = ['edit any section blueprint', 'view test entity'];
    $composer = $this->createUser([...$grants, 'administer entity_test content']);
    $visitor = $this->createUser($grants);
    $this->createBlueprint('section', ['machine_name' => 'card']);
    $page = EntityTest::create(['name' => 'A page']);
    $page->save();

    $details = static fn (string $label, string $id): Request => Request::create(
      '/daedalus-blueprint/blueprint/card/details?entity_type=entity_test&entity_id=' . $id,
      'POST',
      [],
      [],
      [],
      [],
      json_encode(['label' => $label, 'description' => 'A card.']),
    );
    $stored_label = fn (): string => (string) $this->container->get('entity_type.manager')->getStorage('blueprint')->loadUnchanged(1)->label();

    $this->container->get('current_user')->setAccount($visitor);
    $controller = BlueprintApiController::create($this->container);
    $refused = $controller->updateDetails('card', $details('Renamed', (string) $page->id()));
    $missing = $controller->updateDetails('card', $details('Renamed', '999'));
    $this->assertSame(422, $refused->getStatusCode(), (string) $refused->getContent());
    $error = json_decode((string) $refused->getContent(), TRUE)['errors'][0];
    $this->assertSame(['INVALID_PARAMETER', 'entity_id'], [$error['code'], $error['path']]);
    $this->assertSame(
      str_replace('999', (string) $page->id(), json_decode((string) $missing->getContent(), TRUE)['errors'][0]['message']),
      $error['message'],
      'A canvas the actor may not update reads as one that does not exist.',
    );
    $this->assertSame('Section', $stored_label(), 'Nothing was written.');

    $this->container->get('current_user')->setAccount($composer);
    $response = BlueprintApiController::create($this->container)->updateDetails('card', $details('Renamed', (string) $page->id()));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame('Renamed', $stored_label());
  }

}
