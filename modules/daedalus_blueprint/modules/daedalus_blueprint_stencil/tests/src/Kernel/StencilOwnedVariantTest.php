<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_stencil\Controller\StencilApiController;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilManager;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_layout\Controller\StencilSaveDialog;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\VariantImporter;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Stencil-owned variants: the owner field, adoption, and the disk lane.
 *
 * Ownership is a cross-module fact: daedalus_blueprint_stencil provides the
 * `stencil` entity reference base field on daedalus_variant, the capture ops
 * adopt variants as a stencil's curated looks (at save and at update),
 * release and transfer edit the binding afterwards, daedalus_stylish's variant
 * payload annotates the owner (and orphanhood) for the picker's offering
 * filter, save-as-variant defaults to the placement provenance's owner, and
 * the shipped variants file resolves its `stencil:` key against content
 * stencils.
 */
#[CoversClass(StencilManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilOwnedVariantTest extends StencilKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish', 'daedalus_blueprint_stencil_owned_variant_test'];

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['save stencils'];

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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
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
   * Creates a content stencil whose element wears the given variants.
   */
  protected function createStencil(string $label, array $variants = []): Stencil {
    $element = ['kind' => 'container', 'layout_id' => 'layout_onecol'];
    if ($variants !== []) {
      $element['styles'] = ['' => ['variants' => $variants]];
    }
    $stencil = Stencil::create(['label' => $label, 'description' => 'A test stencil.']);
    $stencil->setElement($element);
    $stencil->save();
    return $stencil;
  }

  /**
   * The ownership fields exist on daedalus_variant, provided by this module.
   */
  public function testOwnerFieldDefinition(): void {
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('daedalus_variant');
    $this->assertArrayHasKey('stencil', $definitions);
    $definition = $definitions['stencil'];
    $this->assertSame('daedalus_blueprint_stencil', $definition->getProvider());
    $this->assertSame('entity_reference', $definition->getType());
    $this->assertSame('stencil', $definition->getSetting('target_type'));
    // Ownership is identity, like machine_name and scope.
    $this->assertFalse($definition->isRevisionable());

    // The FILE lane: module-shipped stencils have no entity to reference,
    // so their owned variants store the owner machine name.
    $this->assertArrayHasKey('stencil_file', $definitions);
    $file_definition = $definitions['stencil_file'];
    $this->assertSame('daedalus_blueprint_stencil', $file_definition->getProvider());
    $this->assertSame('string', $file_definition->getType());
    $this->assertFalse($file_definition->isRevisionable());
  }

  /**
   * Adoption curates looks; one-owner-max is the one invariant.
   */
  public function testAdoptVariants(): void {
    $variant = $this->createVariant('card_look');
    $stencil = $this->createStencil('Card', ['card_look']);
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');

    $manager->adoptVariants($stencil, ['card_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $stencil->id(), (int) $variant->get('stencil')->target_id);

    // Re-adopting into the SAME stencil is a no-op, never a conflict.
    $manager->adoptVariants($stencil, ['card_look']);

    // Unknown references are refused.
    try {
      $manager->adoptVariants($stencil, ['missing_look']);
      $this->fail('Expected STENCIL_ADOPT_UNKNOWN_VARIANT.');
    }
    catch (StencilGestureException $exception) {
      $this->assertSame('STENCIL_ADOPT_UNKNOWN_VARIANT', $exception->getErrors()[0]->getCode());
    }

    // Ownership is CURATION, not provenance: adopting a look the stencil's
    // subtree does not wear is legitimate (curating an unworn look IS
    // adopt-later). There is no wearing check.
    $unworn = $this->createVariant('unrelated_look');
    $manager->adoptVariants($stencil, ['unrelated_look']);
    $unworn = DaedalusStylishVariant::load($unworn->id());
    $this->assertSame((int) $stencil->id(), (int) $unworn->get('stencil')->target_id);

    // A variant another stencil owns is never silently re-owned.
    $rival = $this->createStencil('Rival card', ['card_look']);
    try {
      $manager->adoptVariants($rival, ['card_look']);
      $this->fail('Expected STENCIL_ADOPT_ALREADY_OWNED.');
    }
    catch (StencilGestureException $exception) {
      $this->assertSame('STENCIL_ADOPT_ALREADY_OWNED', $exception->getErrors()[0]->getCode());
      // The hint's promise is now true: release exists.
      $this->assertStringContainsString('release it there first', $exception->getErrors()[0]->getHint());
    }

    // ...and release-then-adopt is exactly the promised path.
    $manager->releaseVariant('card_look');
    $manager->adoptVariants($rival, ['card_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $rival->id(), (int) $variant->get('stencil')->target_id);
  }

  /**
   * Membership: a save joins the active system; owned looks follow the owner.
   *
   * The save gesture defaults the stencil to the active design system
   * (`global: true` opts out, the spelling every capture shares), and a
   * variant the stencil adopts, or takes by transfer, carries the
   * stencil's membership from then on: an owned look is offered only on
   * its owner's placements, so it belongs where its owner belongs.
   */
  public function testMembershipFollowsSaveAndAdoption(): void {
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $this->createVariant('aurora_look');
    $this->createVariant('plain_look');
    $this->createVariant('moving_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // Default: the active system, the adopted look with it.
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $root_section,
        'label' => 'Aurora quote',
        'description' => 'A styled band.',
        'adopt_variants' => ['aurora_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $member = $this->loadStencil('aurora_quote');
    $this->assertSame('aurora', $member->getDesignSystem());
    $this->assertSame('aurora', $this->loadVariant('aurora_look')->getDesignSystem());
    $this->assertSame('aurora', $manager->stencilPayload($member)['design_system']);

    // The opt-out: a global stencil, its adopted look global with it.
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $root_section,
        'label' => 'Plain quote',
        'description' => 'A band for every system.',
        'adopt_variants' => ['plain_look'],
        'global' => TRUE,
      ],
    ]);
    $this->assertBatchSuccess($result);
    $global = $this->loadStencil('plain_quote');
    $this->assertNull($global->getDesignSystem());
    $this->assertNull($this->loadVariant('plain_look')->getDesignSystem());

    // A malformed opt-out refuses at the parameter.
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
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
    $direct = $manager->saveAsStencil($entity, $root_section, 'Direct', 'Saved by code.');
    $this->assertSame('aurora', $direct->stencil->getDesignSystem());
    $direct = $manager->saveAsStencil($entity, $root_section, 'Direct global', 'Saved by code.', NULL, MaterialRecord::ORIGIN_USER, TRUE);
    $this->assertNull($direct->stencil->getDesignSystem());
  }

  /**
   * Loads a content stencil by machine name.
   */
  protected function loadStencil(string $machine_name): Stencil {
    $stencils = $this->container->get('entity_type.manager')->getStorage('stencil')->loadByProperties(['machine_name' => $machine_name]);
    $stencil = reset($stencils);
    $this->assertInstanceOf(Stencil::class, $stencil);
    return $stencil;
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
   * The stencil update op adopts too: adopt-later rides the update lane.
   *
   * Dialog parity with save: new looks usually arrive with a stencil
   * update, so the update gesture carries the same adopt_variants key the
   * save gesture always had.
   */
  public function testUpdateStencilOpAdopts(): void {
    $this->createVariant('late_look');
    $this->createStencil('Band');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'update_material',
        'kind' => 'stencil',
        'id' => 'band',
        'element' => $root_section,
        'adopt_variants' => ['late_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $stencils = $this->container->get('entity_type.manager')->getStorage('stencil')->loadByProperties(['machine_name' => 'band']);
    $stencil = reset($stencils);
    $variants = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->loadByProperties(['machine_name' => 'late_look']);
    $variant = reset($variants);
    $this->assertSame((int) $stencil->id(), (int) $variant->get('stencil')->target_id);
  }

  /**
   * Release returns an owned variant to general-purpose, both lanes.
   */
  public function testReleaseVariant(): void {
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');

    // Content-lane ownership clears.
    $variant = $this->createVariant('released_look');
    $stencil = $this->createStencil('Owner', ['released_look']);
    $manager->adoptVariants($stencil, ['released_look']);
    $released = $manager->releaseVariant('released_look');
    $this->assertTrue($released->get('stencil')->isEmpty());
    $this->assertNull($manager->variantOwnershipPayload($released)['stencil']);
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($reloaded->get('stencil')->isEmpty());

    // An ORPHANED variant releases too: that is the cleanup gesture which
    // makes it usable again (its owner is gone; nothing else can).
    $manager->adoptVariants($stencil, ['released_look']);
    $stencil->delete();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $released = $manager->releaseVariant('released_look');
    $this->assertTrue($released->get('stencil')->isEmpty());

    // File-lane ownership clears through the same gesture.
    $file_owned = $this->createVariant('file_owned_look');
    $file_owned->set('stencil_file', 'hero');
    $file_owned->save();
    $released = $manager->releaseVariant('file_owned_look');
    $this->assertTrue($released->get('stencil_file')->isEmpty());

    // Unowned variants have nothing to release.
    try {
      $manager->releaseVariant('released_look');
      $this->fail('Expected STENCIL_RELEASE_NOT_OWNED.');
    }
    catch (StencilGestureException $exception) {
      $this->assertSame('STENCIL_RELEASE_NOT_OWNED', $exception->getErrors()[0]->getCode());
    }

    // Unknown references are a loud not-found.
    try {
      $manager->releaseVariant('no_such_look');
      $this->fail('Expected STENCIL_VARIANT_NOT_FOUND.');
    }
    catch (StencilGestureException $exception) {
      $this->assertSame('STENCIL_VARIANT_NOT_FOUND', $exception->getErrors()[0]->getCode());
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
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $variant = $this->createVariant('moving_look');
    $first = $this->createStencil('First', ['moving_look']);
    $second = $this->createStencil('Second', ['moving_look']);
    $manager->adoptVariants($first, ['moving_look']);

    // Owned by another: one gesture, no intermediate unowned state exposed.
    $moved = $manager->transferVariant('moving_look', $second);
    $this->assertSame((int) $second->id(), (int) $moved->get('stencil')->target_id);
    $this->assertSame('second', $manager->variantOwnershipPayload($moved)['stencil']);

    // File-lane ownership transfers into the content lane, clearing it.
    $variant = DaedalusStylishVariant::load($variant->id());
    $variant->set('stencil', NULL);
    $variant->set('stencil_file', 'hero');
    $variant->save();
    $moved = $manager->transferVariant('moving_look', $first);
    $this->assertSame((int) $first->id(), (int) $moved->get('stencil')->target_id);
    $this->assertTrue($moved->get('stencil_file')->isEmpty());

    // Unowned transfers too: adopt-later through the route lane.
    $free = $this->createVariant('free_moving_look');
    $moved = $manager->transferVariant('free_moving_look', $first);
    $this->assertSame((int) $first->id(), (int) $moved->get('stencil')->target_id);
    $this->assertSame((int) $free->id(), (int) $moved->id());
  }

  /**
   * Shipped variants refuse ownership edits.
   *
   * The disk lane is authoritative: its stencil key re-adopts at the next
   * import, so a database-side release or transfer would silently undo
   * itself.
   */
  public function testShippedVariantOwnershipLocked(): void {
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $stencil = $this->createStencil('Shipping owner');
    $variant = $this->createVariant('shipped_look');
    $variant->set('stencil', $stencil->id());
    $variant->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $variant->save();

    try {
      $manager->releaseVariant('shipped_look');
      $this->fail('Expected STENCIL_VARIANT_SHIPPED.');
    }
    catch (StencilGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('STENCIL_VARIANT_SHIPPED', $error->getCode());
      $this->assertSame('some_module', $error->getContext()['provider']);
    }
    try {
      $manager->transferVariant('shipped_look', $stencil);
      $this->fail('Expected STENCIL_VARIANT_SHIPPED.');
    }
    catch (StencilGestureException $exception) {
      $this->assertSame('STENCIL_VARIANT_SHIPPED', $exception->getErrors()[0]->getCode());
    }
    $this->assertSame((int) $stencil->id(), (int) DaedalusStylishVariant::load($variant->id())->get('stencil')->target_id);
  }

  /**
   * Shipped variants refuse ADOPTION too, at save and at update.
   *
   * The other half of the disk lane's authority: release and transfer
   * refuse shipped, so a shipped adoption would be irreversible by gesture
   * (the importer's absent stencil key never clears it). The capture ops
   * are where adoption rides, so both gestures refuse before any lane
   * moves, with the hint pointing at the shipping yml or a copy — never at
   * release.
   */
  public function testShippedVariantAdoptionRefused(): void {
    $variant = $this->createVariant('shipped_offer');
    $variant->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $variant->save();
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // At save: save_material's adopt_variants refuses the shipped look.
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $root_section,
        'label' => 'Shipped adopter',
        'description' => 'Tries to adopt a shipped look.',
        'adopt_variants' => ['shipped_offer'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STENCIL_VARIANT_SHIPPED', $error->getCode());
    $this->assertSame('some_module', $error->getContext()['provider']);
    $this->assertStringContainsString('variants file', $error->getHint());
    $this->assertStringContainsString('copy it as your own variant', $error->getHint());

    // At update: the same refusal rides the update lane.
    $this->createStencil('Band');
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'update_material',
        'kind' => 'stencil',
        'id' => 'band',
        'element' => $root_section,
        'adopt_variants' => ['shipped_offer'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('STENCIL_VARIANT_SHIPPED', $result->getErrors()[0]->getCode());

    // No lane moved: the variant stays exactly as the disk shipped it.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($variant->get('stencil')->isEmpty());
    $this->assertTrue($variant->get('stencil_file')->isEmpty());
  }

  /**
   * A stencil_file-owned variant refuses adoption: owned is owned.
   *
   * Either lane counts. Content-lane-only checking was the bug: adoption would
   * set the content reference BESIDE the file lane, violating at-most-one-lane
   * and silently stealing the look from the file stencil (the content lane wins
   * in variantPayload).
   */
  public function testFileOwnedVariantAdoptionRefused(): void {
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $variant = $this->createVariant('hero_owned_look');
    $variant->set('stencil_file', 'hero');
    $variant->save();
    $stencil = $this->createStencil('Poacher');

    try {
      $manager->adoptVariants($stencil, ['hero_owned_look']);
      $this->fail('Expected STENCIL_ADOPT_ALREADY_OWNED.');
    }
    catch (StencilGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('STENCIL_ADOPT_ALREADY_OWNED', $error->getCode());
      $this->assertSame('hero', $error->getContext()['owner']);
      // The hint keeps pointing at release/transfer, which handle the file
      // lane (release clears it, transfer moves it into the content lane).
      $this->assertStringContainsString('release it there first', $error->getHint());
    }

    // Nothing stole from the file stencil: the content lane never set.
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertTrue($variant->get('stencil')->isEmpty());
    $this->assertSame('hero', $variant->get('stencil_file')->value);
  }

  /**
   * The ownership routes speak the gesture contract end to end.
   *
   * Release and transfer are CSRF routes behind "save stencils" (the AI's
   * batch door structurally cannot reach them; adopt rides the ops) —
   * StencilApiTest::testRouteContract locks the route requirements. The
   * controller echoes the new ownership state, 404s unknown references,
   * and relays the resolvers' refusals verbatim.
   */
  public function testOwnershipRoutes(): void {
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $controller = StencilApiController::create($this->container);
    $this->createVariant('routed_look');
    $owner = $this->createStencil('Route owner', ['routed_look']);
    $manager->adoptVariants($owner, ['routed_look']);

    $response = $controller->releaseVariant('routed_look');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE)['variant'];
    $this->assertSame('routed_look', $payload['machine_name']);
    $this->assertNull($payload['stencil']);

    $transfer = static fn (array $body) => Request::create('/daedalus-blueprint/stencil-variant/routed_look/transfer', 'POST', [], [], [], [], json_encode($body));
    $response = $controller->transferVariant('routed_look', $transfer(['stencil' => 'route_owner']));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE)['variant'];
    $this->assertSame('route_owner', $payload['stencil']);

    // Refusals arrive in the blueprint error shape with honest statuses.
    $response = $controller->releaseVariant('no_such_look');
    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame('STENCIL_VARIANT_NOT_FOUND', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);

    $response = $controller->transferVariant('routed_look', $transfer([]));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('MISSING_PARAMETER', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);

    // A file-shipped TARGET refuses: only the disk lane writes file
    // stencil ownership (the 'hero' file stencil ships with the fixture).
    // The controller rebuilds because enableModules() rebuilt the container
    // (a fresh request would too).
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);
    $controller = StencilApiController::create($this->container);
    $response = $controller->transferVariant('routed_look', $transfer(['stencil' => 'hero']));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STENCIL_READ_ONLY', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
  }

  /**
   * The stencil save op adopts through the spine, end to end.
   */
  public function testSaveStencilOpAdopts(): void {
    $this->createVariant('band_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'band_look'],
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $root_section,
        'label' => 'Band',
        'description' => 'A styled band.',
        'adopt_variants' => ['band_look'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $stencils = $this->container->get('entity_type.manager')->getStorage('stencil')->loadByProperties(['machine_name' => 'band']);
    $stencil = reset($stencils);
    $this->assertNotFalse($stencil);
    $variants = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->loadByProperties(['machine_name' => 'band_look']);
    $variant = reset($variants);
    $this->assertSame((int) $stencil->id(), (int) $variant->get('stencil')->target_id);

    // The variant payload annotates ownership for the picker's offering
    // filter; deleting the owner flips the annotation to orphaned (offered
    // nowhere; wearers keep rendering because delivery reads the styles,
    // never the owner).
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertSame('band', $payload['stencil']);
    $this->assertFalse($payload['stencil_orphaned']);

    $stencil->delete();
    // A fresh load (the next request's read): the reference now dangles.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $variant = DaedalusStylishVariant::load($variant->id());
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertNull($payload['stencil']);
    $this->assertTrue($payload['stencil_orphaned']);
  }

  /**
   * The save dialog offers the capture's unowned variants for adoption.
   */
  public function testSaveDialogOffersAdoption(): void {
    $this->createVariant('dialog_look');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'dialog_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
    ]);
    $build = StencilSaveDialog::create($this->container)->build($request);
    $this->assertArrayHasKey('adopt', $build);
    $this->assertSame(['dialog_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));
    // A stencil being born owns nothing: the save dialog never renders an
    // owned roster.
    $this->assertArrayNotHasKey('owned', $build);

    // Shipped and file-lane-owned looks the capture wears never list: the
    // spine refuses both adoptions (irreversible / already owned), and the
    // dialog lists only what the gesture would accept. Each look wears on
    // its own nested section — axes are replacement lanes, so stacking
    // them on the root would replace dialog_look instead of adding.
    $shipped = $this->createVariant('shipped_dialog_look');
    $shipped->setShippedSource('some_module', ['' => ['default' => ['background-color' => 'aliceblue']]]);
    $shipped->save();
    $file_owned = $this->createVariant('file_dialog_look');
    $uuid = \Drupal::service('uuid');
    $nested_a = $uuid->generate();
    $nested_b = $uuid->generate();
    $result = $this->operations()->batchOperations($entity, [
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
    $file_owned->set('stencil_file', 'hero');
    $file_owned->save();
    $build = StencilSaveDialog::create($this->container)->build($request);
    $this->assertSame(['dialog_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));

    // Once owned, the variant stops being offered: adoption is claiming,
    // never annexing another stencil's looks.
    $stencil = $this->createStencil('Dialog owner', ['dialog_look']);
    $this->container->get('daedalus_blueprint_stencil.manager')->adoptVariants($stencil, ['dialog_look']);
    $build = StencilSaveDialog::create($this->container)->build($request);
    $this->assertArrayNotHasKey('adopt', $build);
  }

  /**
   * The update dialog offers the same adoption the save dialog does.
   *
   * Adopt-later's natural doorway: new looks usually arrive with a stencil
   * update, so the update dialog renders the capture's unowned variants as
   * the same data-stencil-adopt checkboxes, its confirm copy names the
   * target stencil, and looks the stencil already owns offer nothing (the
   * dialog curates what it LISTS; already-owned is already curated).
   */
  public function testUpdateDialogOffersAdoption(): void {
    $this->createVariant('update_look');
    $stencil = $this->createStencil('Update target');
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'update_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'stencil' => $stencil->getMachineName(),
    ]);
    $build = StencilSaveDialog::create($this->container)->update($request);
    $this->assertStringContainsString('Update target', (string) $build['intro']['#value']);
    $this->assertArrayHasKey('adopt', $build);
    $this->assertSame(['update_look'], array_map(
      static fn (array $item) => $item['#context']['machine_name'],
      $build['adopt']['items'],
    ));
    $this->assertArrayHasKey('data-stencil-update', $build['actions']['save']['#attributes']);
    // Update pushes the composed element; it collects no label/description
    // of its own (the palette's details dialog owns those).
    $this->assertArrayNotHasKey('label_field', $build);
    $this->assertArrayNotHasKey('description_field', $build);

    // Once this stencil owns the look, the update dialog has nothing left
    // to offer for it — the look moves to the owned roster instead.
    $this->container->get('daedalus_blueprint_stencil.manager')->adoptVariants($stencil, ['update_look']);
    $build = StencilSaveDialog::create($this->container)->update($request);
    $this->assertArrayNotHasKey('adopt', $build);
    $this->assertSame(['update_look'], array_column($build['owned']['items']['#context']['variants'], 'machine_name'));
    $this->assertStringContainsString('Update target', (string) $build['intro']['#value']);
  }

  /**
   * The update dialog's owned roster: honest, both lanes, and inert.
   *
   * The roster is an ownership QUERY, not a capture walk: an adopted look
   * the capture does not wear (adopt-later's normal outcome) shows all the
   * same — this was the defect where adopted-but-unworn looks appeared
   * nowhere. Inert by decision: the dialog commits through the op lane and
   * release is deliberately a CSRF route the ops cannot reach, so the
   * roster renders no checkboxes and no adopt attributes — display only.
   */
  public function testUpdateDialogOwnedRoster(): void {
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $stencil = $this->createStencil('Roster target');
    $this->createVariant('unworn_look');
    $this->createVariant('worn_look');

    // Tim's real order: wear the look first (the apply gate refuses OWNED
    // variants outside the owner's placements), adopt afterwards.
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'worn_look'],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();
    $manager->adoptVariants($stencil, ['unworn_look', 'worn_look']);

    // The FILE lane resolves to the stencil by machine name; owned is
    // owned, either lane.
    $file_owned = $this->createVariant('file_roster_look');
    $file_owned->set('stencil_file', $stencil->getMachineName());
    $file_owned->save();

    $request = new Request([
      'element' => $root_section,
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'stencil' => $stencil->getMachineName(),
    ]);
    $build = StencilSaveDialog::create($this->container)->update($request);

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
    $this->assertStringNotContainsString('data-stencil-adopt', $html);

    // An unresolvable stencil reference degrades to no roster (the op
    // fails loudly on commit; the dialog is chrome).
    $build = StencilSaveDialog::create($this->container)->update(new Request(['stencil' => 'no_such_stencil']));
    $this->assertArrayNotHasKey('owned', $build);
  }

  /**
   * Unowned variants carry no ownership keys at all.
   */
  public function testUnownedPayload(): void {
    $variant = $this->createVariant('free_look');
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertArrayNotHasKey('stencil', $payload);
    $this->assertArrayNotHasKey('stencil_orphaned', $payload);
  }

  /**
   * Save-as-variant defaults to the placement provenance's owner.
   */
  public function testSaveAsVariantDefaultsToProvenance(): void {
    $stencil = $this->createStencil('Provenanced');
    ['root_section' => $root_section] = $this->createHost();
    $manager = $this->container->get('daedalus_stylish.variant_manager');

    $owned = $manager->saveAsVariant('From placement', "container:$root_section", ['' => ['default' => ['background-color' => 'aliceblue']]], NULL, NULL, 'provenanced');
    $this->assertSame((int) $stencil->id(), (int) $owned->get('stencil')->target_id);

    // Dead or file-shipped provenance degrades to unowned, mirroring the
    // client's updatable-stencil posture (never an error).
    $unowned = $manager->saveAsVariant('From dead placement', "container:$root_section", ['' => ['default' => ['background-color' => 'aliceblue']]], NULL, NULL, 'no_such_stencil');
    $this->assertTrue($unowned->get('stencil')->isEmpty());
  }

  /**
   * A section-rooted stencil's own owned variant applies during instantiate.
   *
   * The regression that moved provenance to birth: section-root provenance
   * used to be stamped AFTER the nested batch, while the stencil's shipped
   * apply_variant ops run INSIDE it, so an ownership-aware apply check
   * would refuse the stencil's own looks mid-placement. The stamp now rides
   * the root add_section op (a birth fact, like uuid), so it exists before
   * the first style op runs — with no post-batch fixup.
   */
  public function testOwnedVariantAppliesDuringInstantiate(): void {
    DaedalusStylishVariant::create([
      'label' => 'Owned look',
      'machine_name' => 'owned_look',
      'scope' => 'layout:layout_onecol',
      'styles' => ['' => ['default' => ['background-color' => 'aliceblue']]],
    ])->save();
    $stencil = $this->createStencil('Owner band', ['owned_look']);
    $this->container->get('daedalus_blueprint_stencil.manager')->adoptVariants($stencil, ['owned_look']);

    ['entity' => $entity] = $this->createHost();
    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'owner_band',
        'uuid' => $root,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The placed root carries its provenance stamp, born with the section.
    $this->assertSame('owner_band', $entity->get('layout_builder__layout')->getSection(1)->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));

    // The stencil's own owned look landed through the real apply lane.
    $map = json_decode((string) $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame([['variant' => 'owned_look', 'fragment' => '']], $map["container:$root"]['variants']);
  }

  /**
   * The apply gate: owned variants apply only inside the owner's placements.
   *
   * The server backstop of the client's offer filter (one rule, two
   * implementations): apply_variant consults daedalus_stylish's applicability seam,
   * and this module's ownership checker refuses a NEW apply whose target's
   * placement provenance does not resolve to the owner — naming the owner,
   * so the refusal teaches the rule. Inside a placement the stamp is a
   * birth fact (the add_section stencil param), and every element under a
   * stamped root inherits it, exactly like the client walk.
   */
  public function testApplyGateEnforcesOwnership(): void {
    $this->createVariant('curated_look');
    $stencil = $this->createStencil('Curator', ['curated_look']);
    $this->container->get('daedalus_blueprint_stencil.manager')->adoptVariants($stencil, ['curated_look']);
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    // Outside any placement of the owner: refused, naming the owner.
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'curated_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('VARIANT_OWNED_BY_STENCIL', $error->getCode());
    $this->assertSame('curator', $error->getContext()['stencil']);

    // Inside a stamped placement: allowed on the root AND on a nested
    // element (provenance inherits down the placed subtree). The refused
    // apply above never landed, so the map stays empty until here.
    $uuid = \Drupal::service('uuid');
    $placed = $uuid->generate();
    $inner = $uuid->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $placed,
        'stencil' => 'curator',
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
    $result = $this->operations()->batchOperations($entity, [
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
    $stencil = $this->createStencil('Doomed', ['orphan_look']);
    $this->container->get('daedalus_blueprint_stencil.manager')->adoptVariants($stencil, ['orphan_look']);
    $stencil->delete();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'orphan_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_OWNER_ORPHANED', $result->getErrors()[0]->getCode());
  }

  /**
   * The apply gate speaks the FILE lane too.
   *
   * A stencil_file owner gates by machine name against discovery, alive or
   * orphaned, exactly like the content lane.
   */
  public function testApplyGateFileLaneOwner(): void {
    // daedalus_blueprint_stencil_test ships the 'hero' file stencil.
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);
    $variant = $this->createVariant('hero_look');
    $variant->set('stencil_file', 'hero');
    $variant->save();

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$root_section", 'variant' => 'hero_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('VARIANT_OWNED_BY_STENCIL', $error->getCode());
    $this->assertSame('hero', $error->getContext()['stencil']);

    // Inside a hero placement: allowed.
    $placed = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $placed,
        'stencil' => 'hero',
      ],
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'hero_look'],
    ]);
    $this->assertBatchSuccess($result);

    // An undiscoverable file owner is an orphan: refused everywhere.
    $variant->set('stencil_file', 'no_such_file_stencil');
    $variant->save();
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'hero_look'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_OWNER_ORPHANED', $result->getErrors()[0]->getCode());
  }

  /**
   * The shipped variants file's stencil: key resolves content stencils.
   */
  public function testImporterStencilKey(): void {
    $importer = $this->container->get('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);

    // The owner must exist as a content stencil; a dangling stencil: key is
    // a loud developer error (file-shipped stencil ownership is the ship
    // lane's, not silently dropped ownership).
    $report = $importer->sync(['daedalus_blueprint_stencil_owned_variant_test']);
    $this->assertSame(VariantImporter::FAILED, $report->outcome('daedalus_blueprint_stencil_owned_variant_test', 'shipped_owned_look'));
    $this->assertSame('SHIPPED_VARIANT_OWNER_MISSING', $report->errors('daedalus_blueprint_stencil_owned_variant_test', 'shipped_owned_look')[0]->getCode());

    $stencil = Stencil::create(['label' => 'CTA card', 'machine_name' => 'cta_card', 'description' => 'The owner.']);
    $stencil->setElement(['kind' => 'container', 'layout_id' => 'layout_onecol']);
    $stencil->save();

    $results = $importer->sync(['daedalus_blueprint_stencil_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::CREATED, $results['daedalus_blueprint_stencil_owned_variant_test']['shipped_owned_look']);
    $variants = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->loadByProperties(['machine_name' => 'shipped_owned_look']);
    $variant = reset($variants);
    $this->assertSame((int) $stencil->id(), (int) $variant->get('stencil')->target_id);

    // A re-sync with unchanged styles keeps the ownership in place.
    $results = $importer->sync(['daedalus_blueprint_stencil_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_blueprint_stencil_owned_variant_test']['shipped_owned_look']);
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame((int) $stencil->id(), (int) $variant->get('stencil')->target_id);
  }

  /**
   * The stencil: key resolves module-shipped FILE stencils (the package).
   *
   * The cross-site package shape: a module ships a stencil YAML plus its
   * owned variants YAML. The file stencil has no entity, so ownership lands
   * in the stencil_file machine-name lane, resolved against discovery; the
   * payload annotates it exactly like content ownership (same machine-name
   * vocabulary the provenance stamps speak), and an undiscoverable name
   * reads as orphaned.
   */
  public function testImporterFileStencilKey(): void {
    // daedalus_blueprint_stencil_test ships the 'hero' file stencil the
    // fixture's variant declares as its owner.
    $this->enableModules(['daedalus_blueprint_stencil_test', 'daedalus_blueprint_stencil_file_owned_variant_test']);
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);

    $importer = $this->container->get('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    $results = $importer->sync(['daedalus_blueprint_stencil_file_owned_variant_test'])->outcomes();
    $this->assertSame(VariantImporter::CREATED, $results['daedalus_blueprint_stencil_file_owned_variant_test']['hero_look']);

    $variants = $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->loadByProperties(['machine_name' => 'hero_look']);
    $variant = reset($variants);
    $this->assertNotFalse($variant);
    $this->assertTrue($variant->get('stencil')->isEmpty());
    $this->assertSame('hero', $variant->get('stencil_file')->value);

    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertSame('hero', $payload['stencil']);
    $this->assertFalse($payload['stencil_orphaned']);

    // An owner discovery no longer serves (module uninstalled, file
    // removed) reads as orphaned: offered nowhere, wearers keep rendering.
    $variant->set('stencil_file', 'no_such_file_stencil');
    $variant->save();
    $payload = $this->container->get('daedalus_stylish.variant_manager')->variantPayload($variant);
    $this->assertNull($payload['stencil']);
    $this->assertTrue($payload['stencil_orphaned']);
  }

}
