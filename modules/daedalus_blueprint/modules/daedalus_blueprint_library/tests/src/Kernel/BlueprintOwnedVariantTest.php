<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\Controller\BlueprintApiController;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\daedalus_layout\Controller\BlueprintSaveDialog;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\VariantImporter;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Blueprint-owned variants: the owner fields, adoption, and the disk lane.
 *
 * Ownership is a cross-module fact: daedalus_blueprint_library provides the
 * `blueprint` entity reference and `blueprint_file` base fields on
 * daedalus_variant, the capture ops adopt variants as a blueprint's curated
 * looks (at save and at update), release and transfer edit the binding
 * afterwards, daedalus_stylish's variant payload annotates the owner (and
 * orphanhood) for the picker's offering filter, save-as-variant defaults to
 * the placement provenance's owner, and the shipped variants file resolves
 * its owner key against content blueprints.
 */
#[CoversClass(BlueprintManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintOwnedVariantTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish', 'daedalus_blueprint_library_owned_variant_test'];

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['create section blueprint', 'edit any section blueprint', 'delete any section blueprint'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Variant references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
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
  }

  /**
   * Creates a container-scoped variant.
   */
  protected function createVariant(string $machine_name): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['background-color' => 'aliceblue']]],
    ]);
    $variant->save();
    return $variant;
  }

  /**
   * Creates a content blueprint whose one root wears the given variants.
   *
   * The document is instantiated the way a capture writes it, so a worn
   * variant must apply to the blueprint's root: a look another blueprint
   * already owns is refused there (the apply gate), exactly as on a page.
   */
  protected function createOwningBlueprint(string $label, array $variants = []): Blueprint {
    $root = ['kind' => 'container', 'layout_id' => 'layout_onecol'];
    if ($variants !== []) {
      $root['styles'] = ['' => ['variants' => $variants]];
    }
    return $this->createBlueprintFromTree(['label' => $label, 'description' => 'A test blueprint.'], [$root]);
  }

  /**
   * The ownership fields exist on daedalus_variant, provided by this module.
   */
  public function testOwnerFieldDefinition(): void {
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('daedalus_variant');
    $this->assertArrayHasKey('blueprint', $definitions);
    $definition = $definitions['blueprint'];
    $this->assertSame('daedalus_blueprint_library', $definition->getProvider());
    $this->assertSame('entity_reference', $definition->getType());
    $this->assertSame('blueprint', $definition->getSetting('target_type'));
    // Ownership is identity, like machine_name and scope.
    $this->assertFalse($definition->isRevisionable());

    // The FILE lane: module-shipped blueprints have no entity to
    // reference, so their owned variants store the owner machine name.
    $this->assertArrayHasKey('blueprint_file', $definitions);
    $file_definition = $definitions['blueprint_file'];
    $this->assertSame('daedalus_blueprint_library', $file_definition->getProvider());
    $this->assertSame('string', $file_definition->getType());
    $this->assertFalse($file_definition->isRevisionable());
  }

  /**
   * Adoption curates looks; one-owner-max is the one invariant.
   */
  public function testAdoptVariants(): void {
    $variant = $this->createVariant('card_look');
    $blueprint = $this->createOwningBlueprint('Card', ['card_look']);
    $manager = $this->container->get('daedalus_blueprint_library.manager');

    $manager->adoptVariants($blueprint, ['card_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $blueprint->id(), (int) $variant->get('blueprint')->target_id);

    // Re-adopting into the SAME blueprint is a no-op, never a conflict.
    $manager->adoptVariants($blueprint, ['card_look']);

    // Unknown references are refused.
    try {
      $manager->adoptVariants($blueprint, ['missing_look']);
      $this->fail('Expected BLUEPRINT_ADOPT_UNKNOWN_VARIANT.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_ADOPT_UNKNOWN_VARIANT', $exception->getErrors()[0]->getCode());
    }

    // Ownership is CURATION, not provenance: adopting a look the
    // blueprint's document does not wear is legitimate (curating an
    // unworn look IS adopt-later). There is no wearing check.
    $unworn = $this->createVariant('unrelated_look');
    $manager->adoptVariants($blueprint, ['unrelated_look']);
    $unworn = DaedalusStylishVariant::load($unworn->id());
    $this->assertSame((int) $blueprint->id(), (int) $unworn->get('blueprint')->target_id);

    // A variant another blueprint owns is never silently re-owned.
    $rival = $this->createOwningBlueprint('Rival card');
    try {
      $manager->adoptVariants($rival, ['card_look']);
      $this->fail('Expected BLUEPRINT_ADOPT_ALREADY_OWNED.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_ADOPT_ALREADY_OWNED', $exception->getErrors()[0]->getCode());
      // The hint's promise is now true: release exists.
      $this->assertStringContainsString('release it there first', $exception->getErrors()[0]->getHint());
    }

    // ...and release-then-adopt is exactly the promised path.
    $manager->releaseVariant('card_look');
    $manager->adoptVariants($rival, ['card_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $rival->id(), (int) $variant->get('blueprint')->target_id);
  }

  /**
   * Membership: a save joins the active system; owned looks follow the owner.
   *
   * The save gesture defaults the blueprint to the active design system
   * (`global: true` opts out, the spelling every capture shares), and a
   * variant the blueprint adopts, or takes by transfer, carries the
   * blueprint's membership from then on: an owned look is offered only on
   * its owner's placements, so it belongs where its owner belongs.
   */
  public function testMembershipFollowsSaveAndAdoption(): void {
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $this->createVariant('aurora_look');
    $this->createVariant('plain_look');
    $this->createVariant('moving_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // Default: the active system, the adopted look with it.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $root_section,
        'label' => 'Aurora quote',
        'description' => 'A styled band.',
        'adopt_variants' => ['aurora_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $member = $this->loadBlueprint('aurora_quote');
    $this->assertSame('aurora', $member->getDesignSystem());
    $this->assertSame('aurora', $this->loadVariant('aurora_look')->getDesignSystem());
    $this->assertSame('aurora', $manager->blueprintPayload($member)['design_system']);

    // The opt-out: a global blueprint, its adopted look global with it.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $root_section,
        'label' => 'Plain quote',
        'description' => 'A band for every system.',
        'adopt_variants' => ['plain_look'],
        'global' => TRUE,
      ],
    ]);
    $this->assertBatchSuccess($result);
    $global = $this->loadBlueprint('plain_quote');
    $this->assertNull($global->getDesignSystem());
    $this->assertNull($this->loadVariant('plain_look')->getDesignSystem());

    // A malformed opt-out refuses at the parameter.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $root_section,
        'label' => 'Bad',
        'description' => 'x',
        'global' => 'yes',
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PARAMETER', $result->getErrors()[0]->getCode());
    $this->assertSame('global', $result->getErrors()[0]->getPath());

    // Adopt-later and transfer stamp the new owner's membership too.
    $manager->adoptVariants($member, ['moving_look']);
    $this->assertSame('aurora', $this->loadVariant('moving_look')->getDesignSystem());
    $manager->transferVariant('moving_look', $global);
    $this->assertNull($this->loadVariant('moving_look')->getDesignSystem());
    $manager->transferVariant('moving_look', $member);
    $this->assertSame('aurora', $this->loadVariant('moving_look')->getDesignSystem());

    // The manager door without a gesture: the same default and opt-out.
    $direct = $manager->saveAsBlueprint($this->host($entity), $root_section, 'Direct', 'Saved by code.');
    $this->assertSame('aurora', $direct->blueprint->getDesignSystem());
    $direct = $manager->saveAsBlueprint($this->host($entity), $root_section, 'Direct global', 'Saved by code.', NULL, MaterialRecord::ORIGIN_USER, TRUE);
    $this->assertNull($direct->blueprint->getDesignSystem());
  }

  /**
   * Loads a variant by machine name, uncached.
   */
  protected function loadVariant(string $machine_name): DaedalusStylishVariant {
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants);
    $this->assertInstanceOf(DaedalusStylishVariant::class, $variant);
    return $variant;
  }

  /**
   * The blueprint update op adopts too: adopt-later rides the update lane.
   *
   * Dialog parity with save: new looks usually arrive with a blueprint
   * update, so the update gesture carries the same adopt_variants key the
   * save gesture always had.
   */
  public function testUpdateBlueprintOpAdopts(): void {
    $this->createVariant('late_look');
    $this->createOwningBlueprint('Band');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'update_material',
        'kind' => 'blueprint',
        'id' => 'band',
        'element' => $root_section,
        'adopt_variants' => ['late_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $blueprint = $this->loadBlueprint('band');
    $this->assertSame((int) $blueprint->id(), (int) $this->loadVariant('late_look')->get('blueprint')->target_id);
  }

  /**
   * Release returns an owned variant to general-purpose, both lanes.
   */
  public function testReleaseVariant(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');

    // Content-lane ownership clears.
    $variant = $this->createVariant('released_look');
    $blueprint = $this->createOwningBlueprint('Owner', ['released_look']);
    $manager->adoptVariants($blueprint, ['released_look']);
    $released = $manager->releaseVariant('released_look');
    $this->assertTrue($released->get('blueprint')->isEmpty());
    $this->assertNull($manager->variantOwnershipPayload($released)['blueprint']);
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($reloaded->get('blueprint')->isEmpty());

    // An ORPHANED variant releases too: that is the cleanup gesture which
    // makes it usable again (its owner is gone; nothing else can).
    $manager->adoptVariants($blueprint, ['released_look']);
    $blueprint->delete();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $released = $manager->releaseVariant('released_look');
    $this->assertTrue($released->get('blueprint')->isEmpty());

    // File-lane ownership clears through the same gesture.
    $file_owned = $this->createVariant('file_owned_look');
    $file_owned->set('blueprint_file', 'hero');
    $file_owned->save();
    $released = $manager->releaseVariant('file_owned_look');
    $this->assertTrue($released->get('blueprint_file')->isEmpty());

    // Unowned variants have nothing to release.
    try {
      $manager->releaseVariant('released_look');
      $this->fail('Expected BLUEPRINT_RELEASE_NOT_OWNED.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_RELEASE_NOT_OWNED', $exception->getErrors()[0]->getCode());
    }

    // Unknown references are a loud not-found.
    try {
      $manager->releaseVariant('no_such_look');
      $this->fail('Expected BLUEPRINT_VARIANT_NOT_FOUND.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_VARIANT_NOT_FOUND', $exception->getErrors()[0]->getCode());
    }
  }

  /**
   * Transfer re-owns a variant in one atomic gesture.
   *
   * Release + adopt composed server-side: the owned-by-another case never
   * exposes an intermediate unowned state, and the previous state never
   * gates the outcome (a transfer onto an unowned variant is adopt-later
   * by route).
   */
  public function testTransferVariant(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $variant = $this->createVariant('moving_look');
    $first = $this->createOwningBlueprint('First', ['moving_look']);
    $second = $this->createOwningBlueprint('Second');
    $manager->adoptVariants($first, ['moving_look']);

    // Owned by another: one gesture, no intermediate unowned state exposed.
    $moved = $manager->transferVariant('moving_look', $second);
    $this->assertSame((int) $second->id(), (int) $moved->get('blueprint')->target_id);
    $this->assertSame('second', $manager->variantOwnershipPayload($moved)['blueprint']);

    // File-lane ownership transfers into the content lane, clearing it.
    $variant = DaedalusStylishVariant::load($variant->id());
    $variant->set('blueprint', NULL);
    $variant->set('blueprint_file', 'hero');
    $variant->save();
    $moved = $manager->transferVariant('moving_look', $first);
    $this->assertSame((int) $first->id(), (int) $moved->get('blueprint')->target_id);
    $this->assertTrue($moved->get('blueprint_file')->isEmpty());

    // Unowned transfers too: adopt-later through the route lane.
    $free = $this->createVariant('free_moving_look');
    $moved = $manager->transferVariant('free_moving_look', $first);
    $this->assertSame((int) $first->id(), (int) $moved->get('blueprint')->target_id);
    $this->assertSame((int) $free->id(), (int) $moved->id());
  }

  /**
   * Shipped variants refuse ownership edits.
   *
   * The disk lane is authoritative: its owner key re-adopts at the next
   * import, so a database-side release or transfer would silently undo
   * itself.
   */
  public function testShippedVariantOwnershipLocked(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $blueprint = $this->createOwningBlueprint('Shipping owner');
    $variant = $this->createVariant('shipped_look');
    $variant->set('blueprint', $blueprint->id());
    $variant->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $variant->save();

    try {
      $manager->releaseVariant('shipped_look');
      $this->fail('Expected BLUEPRINT_VARIANT_SHIPPED.');
    }
    catch (BlueprintGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('BLUEPRINT_VARIANT_SHIPPED', $error->getCode());
      $this->assertSame('some_module', $error->getContext()['provider']);
    }
    try {
      $manager->transferVariant('shipped_look', $blueprint);
      $this->fail('Expected BLUEPRINT_VARIANT_SHIPPED.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_VARIANT_SHIPPED', $exception->getErrors()[0]->getCode());
    }
    $this->assertSame((int) $blueprint->id(), (int) DaedalusStylishVariant::load($variant->id())->get('blueprint')->target_id);
  }

  /**
   * Shipped variants refuse ADOPTION too, at save and at update.
   *
   * The other half of the disk lane's authority: release and transfer
   * refuse shipped, so a shipped adoption would be irreversible by gesture
   * (the importer's absent owner key never clears it). The capture ops
   * are where adoption rides, so both gestures refuse before any lane
   * moves, with the hint pointing at the shipping yml or a copy, never at
   * release.
   */
  public function testShippedVariantAdoptionRefused(): void {
    $variant = $this->createVariant('shipped_offer');
    $variant->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $variant->save();
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // At save: save_material's adopt_variants refuses the shipped look.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $root_section,
        'label' => 'Shipped adopter',
        'description' => 'Tries to adopt a shipped look.',
        'adopt_variants' => ['shipped_offer'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('BLUEPRINT_VARIANT_SHIPPED', $error->getCode());
    $this->assertSame('some_module', $error->getContext()['provider']);
    $this->assertStringContainsString('variants file', $error->getHint());
    $this->assertStringContainsString('copy it as your own variant', $error->getHint());

    // At update: the same refusal rides the update lane.
    $this->createOwningBlueprint('Band');
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'update_material',
        'kind' => 'blueprint',
        'id' => 'band',
        'element' => $root_section,
        'adopt_variants' => ['shipped_offer'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('BLUEPRINT_VARIANT_SHIPPED', $result->getErrors()[0]->getCode());

    // No lane moved: the variant stays exactly as the disk shipped it.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($variant->get('blueprint')->isEmpty());
    $this->assertTrue($variant->get('blueprint_file')->isEmpty());
  }

  /**
   * A blueprint_file-owned variant refuses adoption: owned is owned.
   *
   * Either lane counts. Content-lane-only checking was the bug: adoption
   * would set the content reference BESIDE the file lane, violating
   * at-most-one-lane and silently stealing the look from the file
   * blueprint (the content lane wins in variantPayload).
   */
  public function testFileOwnedVariantAdoptionRefused(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $variant = $this->createVariant('hero_owned_look');
    $variant->set('blueprint_file', 'hero');
    $variant->save();
    $blueprint = $this->createOwningBlueprint('Poacher');

    try {
      $manager->adoptVariants($blueprint, ['hero_owned_look']);
      $this->fail('Expected BLUEPRINT_ADOPT_ALREADY_OWNED.');
    }
    catch (BlueprintGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('BLUEPRINT_ADOPT_ALREADY_OWNED', $error->getCode());
      $this->assertSame('hero', $error->getContext()['owner']);
      // The hint keeps pointing at release/transfer, which handle the file
      // lane (release clears it, transfer moves it into the content lane).
      $this->assertStringContainsString('release it there first', $error->getHint());
    }

    // Nothing stole from the file blueprint: the content lane never set.
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($variant->get('blueprint')->isEmpty());
    $this->assertSame('hero', $variant->get('blueprint_file')->value);
  }

  /**
   * The ownership routes speak the gesture contract end to end.
   *
   * Release and transfer are CSRF routes behind the manage permission (the
   * AI's batch door structurally cannot reach them; adopt rides the ops).
   * The controller echoes the new ownership state, 404s unknown
   * references, and relays the resolvers' refusals verbatim.
   */
  public function testOwnershipRoutes(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $controller = BlueprintApiController::create($this->container);
    $this->createVariant('routed_look');
    $owner = $this->createOwningBlueprint('Route owner', ['routed_look']);
    $manager->adoptVariants($owner, ['routed_look']);

    $response = $controller->releaseVariant('routed_look', Request::create('/daedalus-blueprint/blueprint-variant/routed_look/release', 'POST'));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE)['variant'];
    $this->assertSame('routed_look', $payload['machine_name']);
    $this->assertNull($payload['blueprint']);

    $transfer = static fn (array $body) => Request::create('/daedalus-blueprint/blueprint-variant/routed_look/transfer', 'POST', [], [], [], [], json_encode($body));
    $response = $controller->transferVariant('routed_look', $transfer(['blueprint' => 'route_owner']));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE)['variant'];
    $this->assertSame('route_owner', $payload['blueprint']);

    // Refusals arrive in the blueprint error shape with honest statuses.
    $response = $controller->releaseVariant('no_such_look', Request::create('/daedalus-blueprint/blueprint-variant/no_such_look/release', 'POST'));
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_VARIANT_NOT_FOUND', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);

    $response = $controller->transferVariant('routed_look', $transfer([]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('MISSING_PARAMETER', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);

    // A file-shipped TARGET refuses: only the disk lane writes file
    // blueprint ownership (the 'hero' file blueprint ships with the
    // fixture). The controller rebuilds because enableModules() rebuilt
    // the container (a fresh request would too).
    $this->enableModules(['daedalus_blueprint_library_test']);
    Cache::invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $controller = BlueprintApiController::create($this->container);
    $response = $controller->transferVariant('routed_look', $transfer(['blueprint' => 'hero']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('BLUEPRINT_READ_ONLY', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
  }

  /**
   * The blueprint save op adopts through the spine, end to end.
   */
  public function testSaveBlueprintOpAdopts(): void {
    $this->createVariant('band_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'band_look'],
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $root_section,
        'label' => 'Band',
        'description' => 'A styled band.',
        'adopt_variants' => ['band_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $blueprint = $this->loadBlueprint('band');
    $variant = $this->loadVariant('band_look');
    $this->assertSame((int) $blueprint->id(), (int) $variant->get('blueprint')->target_id);
    // The blueprint's own root wears the look it captured.
    $this->assertSame([['variant' => 'band_look', 'fragment' => '']], $this->definitionOf($blueprint)[0]['styles']['']['variants']);

    // The variant payload annotates ownership for the picker's offering
    // filter; deleting the owner flips the annotation to orphaned (offered
    // nowhere; wearers keep rendering because delivery reads the styles,
    // never the owner).
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertSame('band', $payload['blueprint']);
    $this->assertFalse($payload['blueprint_orphaned']);

    $blueprint->delete();
    // A fresh load (the next request's read): the reference now dangles.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $variant = DaedalusStylishVariant::load($variant->id());
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertNull($payload['blueprint']);
    $this->assertTrue($payload['blueprint_orphaned']);
  }

  /**
   * The save dialog offers the capture's unowned variants for adoption.
   */
  public function testSaveDialogOffersAdoption(): void {
    $this->createVariant('dialog_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'dialog_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
    ]);
    $build = BlueprintSaveDialog::create($this->container)->build($request);
    $this->assertArrayHasKey('adopt', $build);
    $this->assertSame(['dialog_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));
    // A blueprint being born owns nothing: the save dialog never renders
    // an owned roster.
    $this->assertArrayNotHasKey('owned', $build);

    // Shipped and file-lane-owned looks the capture wears never list: the
    // spine refuses both adoptions (irreversible / already owned), and the
    // dialog lists only what the gesture would accept. Each look wears on
    // its own nested section: axes are replacement lanes, so stacking
    // them on the root would replace dialog_look instead of adding.
    $shipped = $this->createVariant('shipped_dialog_look');
    $shipped->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $shipped->save();
    $file_owned = $this->createVariant('file_dialog_look');
    $uuid = \Drupal::service('uuid');
    $nested_a = $uuid->generate();
    $nested_b = $uuid->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'add_section', 'location' => ['anchor' => $root_section, 'placement' => 'inside'], 'uuid' => $nested_a],
      ['op' => 'add_section', 'location' => ['anchor' => $root_section, 'placement' => 'inside'], 'uuid' => $nested_b],
      ['op' => 'apply_variant', 'key' => "container:$nested_a", 'variant' => 'shipped_dialog_look'],
      ['op' => 'apply_variant', 'key' => "container:$nested_b", 'variant' => 'file_dialog_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();
    // Ownership lands AFTER the wear: the apply gate refuses owned variants
    // outside the owner's placements, but the dialog filter reads the
    // ownership as of NOW, however the look got worn.
    $file_owned->set('blueprint_file', 'hero');
    $file_owned->save();
    $build = BlueprintSaveDialog::create($this->container)->build($request);
    $this->assertSame(['dialog_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));

    // Once owned, the variant stops being offered: adoption is claiming,
    // never annexing another blueprint's looks.
    $blueprint = $this->createOwningBlueprint('Dialog owner', ['dialog_look']);
    $this->container->get('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['dialog_look']);
    $build = BlueprintSaveDialog::create($this->container)->build($request);
    $this->assertArrayNotHasKey('adopt', $build);
  }

  /**
   * The update dialog offers the same adoption the save dialog does.
   *
   * Adopt-later's natural doorway: new looks usually arrive with a
   * blueprint update, so the update dialog renders the capture's unowned
   * variants as the same data-blueprint-adopt checkboxes, its confirm copy
   * names the target blueprint, and looks the blueprint already owns offer
   * nothing (the dialog curates what it LISTS; already-owned is already
   * curated).
   */
  public function testUpdateDialogOffersAdoption(): void {
    $this->createVariant('update_look');
    $blueprint = $this->createOwningBlueprint('Update target');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'update_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'blueprint' => $blueprint->getMachineName(),
    ]);
    $build = BlueprintSaveDialog::create($this->container)->update($request);
    $this->assertStringContainsString('Update target', (string) $build['intro']['#value']);
    $this->assertArrayHasKey('adopt', $build);
    $this->assertSame(['update_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));
    $this->assertArrayHasKey('data-blueprint-update', $build['actions']['save']['#attributes']);
    // Update pushes the composed element; it collects no label/description
    // of its own (the palette's details dialog owns those).
    $this->assertArrayNotHasKey('label_field', $build);
    $this->assertArrayNotHasKey('description_field', $build);

    // Once this blueprint owns the look, the update dialog has nothing
    // left to offer for it: the look moves to the owned roster instead.
    $this->container->get('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['update_look']);
    $build = BlueprintSaveDialog::create($this->container)->update($request);
    $this->assertArrayNotHasKey('adopt', $build);
    $this->assertSame(['update_look'], array_column($build['owned']['items']['#context']['variants'], 'machine_name'));
    $this->assertStringContainsString('Update target', (string) $build['intro']['#value']);
  }

  /**
   * The update dialog's owned roster: honest, both lanes, and inert.
   *
   * The roster is an ownership QUERY, not a capture walk: an adopted look
   * the capture does not wear (adopt-later's normal outcome) shows all the
   * same; this was the defect where adopted-but-unworn looks appeared
   * nowhere. Inert by decision: the dialog commits through the op lane and
   * release is deliberately a CSRF route the ops cannot reach, so the
   * roster renders no checkboxes and no adopt attributes: display only.
   */
  public function testUpdateDialogOwnedRoster(): void {
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $blueprint = $this->createOwningBlueprint('Roster target');
    $this->createVariant('unworn_look');
    $this->createVariant('worn_look');

    // Tim's real order: wear the look first (the apply gate refuses OWNED
    // variants outside the owner's placements), adopt afterwards.
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'worn_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();
    $manager->adoptVariants($blueprint, ['unworn_look', 'worn_look']);

    // The FILE lane resolves to the blueprint by machine name; owned is
    // owned, either lane.
    $file_owned = $this->createVariant('file_roster_look');
    $file_owned->set('blueprint_file', $blueprint->getMachineName());
    $file_owned->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'blueprint' => $blueprint->getMachineName(),
    ]);
    $build = BlueprintSaveDialog::create($this->container)->update($request);

    // All three owned looks list, label-sorted, worn or not; the capture
    // wearing only worn_look changes nothing.
    $this->assertSame(['file_roster_look', 'unworn_look', 'worn_look'], array_column($build['owned']['items']['#context']['variants'], 'machine_name'));
    // Everything the capture wears is owned, so nothing offers to adopt.
    $this->assertArrayNotHasKey('adopt', $build);

    // Inert markup: a plain list of labels (the dialog skin's bare-element
    // guards style it, no bespoke classes), no checkbox, no adopt attribute.
    $html = (string) $this->container->get('renderer')->renderInIsolation($build['owned']);
    $this->assertStringContainsString('<li>Unworn look</li>', $html);
    $this->assertStringContainsString('<li>Worn look</li>', $html);
    $this->assertStringContainsString('<li>File roster look</li>', $html);
    $this->assertStringNotContainsString('<input', $html);
    $this->assertStringNotContainsString('data-blueprint-adopt', $html);

    // An unresolvable blueprint reference degrades to no roster (the op
    // fails loudly on commit; the dialog is chrome).
    $build = BlueprintSaveDialog::create($this->container)->update(new Request(['blueprint' => 'no_such_blueprint']));
    $this->assertArrayNotHasKey('owned', $build);
  }

  /**
   * Unowned variants carry no ownership keys at all.
   */
  public function testUnownedPayload(): void {
    $variant = $this->createVariant('free_look');
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertArrayNotHasKey('blueprint', $payload);
    $this->assertArrayNotHasKey('blueprint_orphaned', $payload);
  }

  /**
   * Save-as-variant defaults to the placement provenance's owner.
   */
  public function testSaveAsVariantDefaultsToProvenance(): void {
    $blueprint = $this->createOwningBlueprint('Provenanced');
    ['root_section' => $root_section] = $this->createHost();
    $manager = $this->container->get('daedalus_stylish.variant_manager');

    $owned = $manager->saveAsVariant('From placement', "container:$root_section", ['' => ['default' => ['background-color' => 'aliceblue']]], NULL, NULL, 'provenanced');
    $this->assertSame((int) $blueprint->id(), (int) $owned->get('blueprint')->target_id);

    // Dead or file-shipped provenance degrades to unowned, mirroring the
    // client's updatable posture (never an error).
    $unowned = $manager->saveAsVariant('From dead placement', "container:$root_section", ['' => ['default' => ['background-color' => 'aliceblue']]], NULL, NULL, 'no_such_blueprint');
    $this->assertTrue($unowned->get('blueprint')->isEmpty());
  }

  /**
   * A blueprint's own owned variant applies during placement.
   *
   * The regression that moved provenance to birth: section-root provenance
   * used to be stamped AFTER the nested batch, while the blueprint's
   * apply_variant ops run INSIDE it, so an ownership-aware apply check
   * would refuse the blueprint's own looks mid-placement. The stamp rides
   * the root add_section op (a birth fact, like uuid), so it exists before
   * the first style op runs, with no post-batch fixup.
   */
  public function testOwnedVariantAppliesDuringInstantiate(): void {
    DaedalusStylishVariant::create([
      'label' => 'Owned look',
      'machine_name' => 'owned_look',
      'scope' => 'layout:layout_onecol',
      'styles' => ['' => ['default' => ['background-color' => 'aliceblue']]],
    ])->save();
    $blueprint = $this->createOwningBlueprint('Owner band', ['owned_look']);
    $this->container->get('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['owned_look']);

    ['entity' => $entity] = $this->createHost();
    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'owner_band',
        'uuid' => $root,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The placed root carries its provenance stamp, born with the section.
    $this->assertSame('owner_band', $entity->get('layout_builder__layout')->getSection(1)->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));

    // The blueprint's own owned look landed through the real apply lane.
    $map = json_decode((string) $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame([['variant' => 'owned_look', 'fragment' => '']], $map["container:$root"]['variants']);
  }

  /**
   * The apply gate: owned variants apply only inside the owner's placements.
   *
   * The server backstop of the client's offer filter (one rule, two
   * implementations): apply_variant consults daedalus_stylish's
   * applicability seam, and this module's ownership checker refuses a NEW
   * apply whose target's placement provenance does not resolve to the
   * owner, naming the owner, so the refusal teaches the rule. Inside a
   * placement the stamp is a birth fact (the add_section blueprint
   * param), and every element under a stamped root inherits it, exactly
   * like the client walk.
   */
  public function testApplyGateEnforcesOwnership(): void {
    $this->createVariant('curated_look');
    $blueprint = $this->createOwningBlueprint('Curator', ['curated_look']);
    $this->container->get('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['curated_look']);
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // Outside any placement of the owner: refused, naming the owner.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'curated_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('VARIANT_OWNED_BY_BLUEPRINT', $error->getCode());
    $this->assertSame('curator', $error->getContext()['blueprint']);

    // Inside a stamped placement: allowed on the root AND on a nested
    // element (provenance inherits down the placed subtree). The refused
    // apply above never landed, so the map stays empty until here.
    $uuid = \Drupal::service('uuid');
    $placed = $uuid->generate();
    $inner = $uuid->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $placed,
        'blueprint' => 'curator',
      ],
      [
        'op' => 'add_section',
        'location' => ['anchor' => $placed, 'placement' => 'inside'],
        'uuid' => $inner,
      ],
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'curated_look'],
      ['op' => 'apply_variant', 'key' => "container:$inner", 'variant' => 'curated_look'],
    ]);
    $this->assertBatchSuccess($result);
    $map = json_decode((string) $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame([['variant' => 'curated_look', 'fragment' => '']], $map["container:$placed"]['variants']);
    $this->assertSame([['variant' => 'curated_look', 'fragment' => '']], $map["container:$inner"]['variants']);

    // Unowned variants are untouched by the gate: they apply anywhere
    // their scope matches, provenance or none.
    $this->createVariant('free_look');
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'free_look'],
    ]);
    $this->assertBatchSuccess($result);
  }

  /**
   * The apply gate: orphaned variants refuse everywhere.
   *
   * Offer-nowhere parity: the owner is gone, so no placement can ever match
   * it; existing wearers keep rendering because delivery never consults
   * the gate.
   */
  public function testApplyGateRefusesOrphans(): void {
    $this->createVariant('orphan_look');
    $blueprint = $this->createOwningBlueprint('Doomed', ['orphan_look']);
    $this->container->get('daedalus_blueprint_library.manager')->adoptVariants($blueprint, ['orphan_look']);
    $blueprint->delete();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'orphan_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_OWNER_ORPHANED', $result->getErrors()[0]->getCode());
  }

  /**
   * The apply gate speaks the FILE lane too.
   *
   * A blueprint_file owner gates by machine name against discovery, alive
   * or orphaned, exactly like the content lane.
   */
  public function testApplyGateFileLaneOwner(): void {
    // daedalus_blueprint_library_test ships the 'hero' file blueprint.
    $this->enableModules(['daedalus_blueprint_library_test']);
    Cache::invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $variant = $this->createVariant('hero_look');
    $variant->set('blueprint_file', 'hero');
    $variant->save();

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'hero_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('VARIANT_OWNED_BY_BLUEPRINT', $error->getCode());
    $this->assertSame('hero', $error->getContext()['blueprint']);

    // Inside a hero placement: allowed.
    $placed = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $placed,
        'blueprint' => 'hero',
      ],
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'hero_look'],
    ]);
    $this->assertBatchSuccess($result);

    // An undiscoverable file owner is an orphan: refused everywhere.
    $variant->set('blueprint_file', 'no_such_file_blueprint');
    $variant->save();
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'hero_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_OWNER_ORPHANED', $result->getErrors()[0]->getCode());
  }

  /**
   * The shipped variants file's owner key resolves content blueprints.
   */
  public function testImporterBlueprintKey(): void {
    $importer = $this->container->get('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);

    // The owner must exist as a content blueprint; a dangling owner key is
    // a loud developer error (file-shipped blueprint ownership is the ship
    // lane's, not silently dropped ownership).
    $report = $importer->sync(['daedalus_blueprint_library_owned_variant_test']);
    $this->assertSame(VariantImporter::FAILED, $report->outcome('daedalus_blueprint_library_owned_variant_test', 'shipped_owned_look'));
    $this->assertSame('SHIPPED_VARIANT_OWNER_MISSING', $report->errors('daedalus_blueprint_library_owned_variant_test', 'shipped_owned_look')[0]->getCode());

    $blueprint = $this->createBlueprint(['label' => 'CTA card', 'machine_name' => 'cta_card', 'description' => 'The owner.']);

    $results = $importer->sync(['daedalus_blueprint_library_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::CREATED, $results['daedalus_blueprint_library_owned_variant_test']['shipped_owned_look']);
    $variant = $this->loadVariant('shipped_owned_look');
    $this->assertSame((int) $blueprint->id(), (int) $variant->get('blueprint')->target_id);

    // A re-sync with unchanged styles keeps the ownership in place.
    $results = $importer->sync(['daedalus_blueprint_library_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_blueprint_library_owned_variant_test']['shipped_owned_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $blueprint->id(), (int) $variant->get('blueprint')->target_id);
  }

  /**
   * The owner key resolves module-shipped FILE blueprints (the package).
   *
   * The cross-site package shape: a module ships a blueprint YAML plus its
   * owned variants YAML. The file blueprint has no entity, so ownership
   * lands in the blueprint_file machine-name lane, resolved against
   * discovery; the payload annotates it exactly like content ownership
   * (same machine-name vocabulary the provenance stamps speak), and an
   * undiscoverable name reads as orphaned.
   */
  public function testImporterFileBlueprintKey(): void {
    // daedalus_blueprint_library_test ships the 'hero' file blueprint the
    // fixture's variant declares as its owner.
    $this->enableModules(['daedalus_blueprint_library_test', 'daedalus_blueprint_library_file_owned_variant_test']);
    Cache::invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    $importer = $this->container->get('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    $results = $importer->sync(['daedalus_blueprint_library_file_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::CREATED, $results['daedalus_blueprint_library_file_owned_variant_test']['hero_look']);

    $variant = $this->loadVariant('hero_look');
    $this->assertTrue($variant->get('blueprint')->isEmpty());
    $this->assertSame('hero', $variant->get('blueprint_file')->value);

    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertSame('hero', $payload['blueprint']);
    $this->assertFalse($payload['blueprint_orphaned']);

    // An owner discovery no longer serves (module uninstalled, file
    // removed) reads as orphaned: offered nowhere, wearers keep rendering.
    $variant->set('blueprint_file', 'no_such_file_blueprint');
    $variant->save();
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertNull($payload['blueprint']);
    $this->assertTrue($payload['blueprint_orphaned']);
  }

  /**
   * The details dialog says what a blueprint is material for, prefilled.
   *
   * The scope reads as inert text (identity), the subject select offers
   * the page-builder bundles with the stored subject selected, and the
   * allow-list renders the same bundles as checkboxes with the stored
   * entries checked (an entry no display lays out any more still renders,
   * checked, so saving never drops it silently). Without a resolvable
   * reference none of the three sections renders, so the client omits the
   * keys and the stored values stand.
   */
  public function testDetailsDialogMaterialFor(): void {
    $blueprint = $this->createOwningBlueprint('Details target');
    $blueprint->setBundles(['entity_test:entity_test', 'node:retired'])->save();

    // No declared groups and none stored: the groups section does not
    // render, so the client omits the key.
    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => $blueprint->getMachineName()]));
    $this->assertArrayNotHasKey('groups_field', $build);

    // The site declares groups (the fixture's heroes and bands) and the
    // blueprint carries one of them beside an id the site does not
    // declare: the declared ones render labelled, the stored undeclared id
    // renders checked under its id, so saving never drops it silently.
    $this->enableModules(['daedalus_layout_site_groups_test']);
    $this->container->get('daedalus_ui.page_builder_declarations')->resetCache();
    $blueprint->setGroups(['bands', 'retired_group'])->save();

    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => $blueprint->getMachineName()]));
    $this->assertSame('block', $build['scope_field']['value']['#attributes']['data-blueprint-scope']);
    $this->assertStringContainsString('does not change', (string) $build['scope_field']['value']['#value']);

    $options = $build['subject_field']['input']['options'];
    $this->assertSame(BlueprintSaveDialog::SUBJECT_INPUT_ID, $build['subject_field']['input']['#attributes']['id']);
    $this->assertSame(['entity_test:entity_test'], array_column(array_column($options, '#attributes'), 'value'), 'The page-builder bundles of this site: the entity_test display the base enables.');
    $this->assertSame('selected', $options[0]['#attributes']['selected']);

    $hosts = array_map(static fn (array $item): array => $item['#context'], $build['bundles_field']['items']);
    $this->assertSame(['entity_test:entity_test', 'node:retired'], array_column($hosts, 'value'));
    $this->assertSame([TRUE, TRUE], array_column($hosts, 'checked'));
    $groups = array_map(static fn (array $item): array => $item['#context'], $build['groups_field']['items']);
    $this->assertSame(['heroes', 'bands', 'retired_group'], array_column($groups, 'value'));
    $this->assertSame(['Heroes', 'Bands', 'retired_group'], array_column($groups, 'label'));
    $this->assertSame([FALSE, TRUE, TRUE], array_column($groups, 'checked'));
    // The favorite: one checkbox carrying the stored flag, off here.
    $this->assertFalse($build['favorite_field']['input']['#context']['checked']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString(BlueprintSaveDialog::BUNDLE_ATTRIBUTE . '="node:retired" checked', $html);
    $this->assertStringContainsString(BlueprintSaveDialog::GROUP_ATTRIBUTE . '="bands" checked', $html);
    $this->assertStringContainsString(BlueprintSaveDialog::GROUP_ATTRIBUTE . '="heroes"/>', $html);
    $this->assertStringContainsString('value="entity_test:entity_test" selected', $html);
    $this->assertStringContainsString(BlueprintSaveDialog::FAVORITE_ATTRIBUTE . '/> Favorite', $html);

    // A site favorite renders the box checked.
    $blueprint->setFavorite(TRUE)->save();
    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => $blueprint->getMachineName()]));
    $this->assertTrue($build['favorite_field']['input']['#context']['checked']);
    $this->assertStringContainsString(BlueprintSaveDialog::FAVORITE_ATTRIBUTE . ' checked/> Favorite', (string) $this->container->get('renderer')->renderInIsolation($build));

    // A cleared allow-list renders every option unchecked.
    $blueprint->setBundles([])->save();
    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => $blueprint->getMachineName()]));
    $this->assertSame([FALSE], array_column(array_map(static fn (array $item): array => $item['#context'], $build['bundles_field']['items']), 'checked'));

    // Cleared groups on a site that declares some: every group unchecked.
    $blueprint->setGroups([])->save();
    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => $blueprint->getMachineName()]));
    $this->assertSame([FALSE, FALSE], array_column(array_map(static fn (array $item): array => $item['#context'], $build['groups_field']['items']), 'checked'));

    $build = BlueprintSaveDialog::create($this->container)->details(new Request(['blueprint' => 'no_such_blueprint']));
    $this->assertArrayNotHasKey('scope_field', $build);
    $this->assertArrayNotHasKey('subject_field', $build);
    $this->assertArrayNotHasKey('bundles_field', $build);
    $this->assertArrayNotHasKey('groups_field', $build);
    $this->assertArrayNotHasKey('favorite_field', $build);
  }

}
