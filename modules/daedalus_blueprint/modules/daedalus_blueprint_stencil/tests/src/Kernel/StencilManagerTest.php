<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint_stencil\Materials\StencilMaterialsProvider;
use Drupal\daedalus_blueprint_stencil\StencilInterface;
use Drupal\daedalus_blueprint_stencil\StencilManager;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stencil gestures as spine ops.
 *
 * The ops are save_material and update_material with kind stencil.
 *
 * Capture is a SPINE OP, so the UI verb and the AI's batch tools are thin
 * adapters over one grammar. The proof of parity runs here: a subtree is
 * COMPOSED and SAVED in the SAME batch — the capture reads the batch's live
 * entity, which is exactly what the composing operations just staged, never
 * the database copy. The capture rides StencilExporter exactly as the drush
 * export does: media/entity references preserved, inexpressible style keys
 * dropped WITH warnings surfaced through the batch's warning channel.
 */
#[CoversClass(StencilManager::class)]
#[CoversClass(MaterialGestureOperationHandler::class)]
#[CoversClass(StencilMaterialsProvider::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilManagerTest extends StencilKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['save stencils'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The materials delta resolves against the roster, whose daedalus_stylish
    // provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->createReferenceFields();
    $this->createStyleMapField();
  }

  /**
   * The batch operations composing the reference section, uuids pinned.
   *
   * The same shape StencilKernelTestBase::buildReferenceSection() runs, as
   * raw operations so a test can append save_material to the SAME batch.
   *
   * @return array{operations: array[], section: string, text: string}
   *   The operations and the uuids they pin.
   */
  protected function compositionOperations(string $entity_uuid): array {
    $uuid = \Drupal::service('uuid');
    $section = $uuid->generate();
    $text = $uuid->generate();
    $inner = $uuid->generate();
    return [
      'operations' => [
        [
          'op' => 'add_section',
          'location' => ['anchor' => $entity_uuid, 'placement' => 'inside'],
          'uuid' => $section,
          'layout' => 'layout_twocol_section',
          'layout_settings' => ['column_widths' => '33-67'],
        ],
        [
          'op' => 'update_element',
          'element' => $section,
          'properties' => ['label' => 'Call to Action'],
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'first'],
          'block_type' => 'text',
          'uuid' => $text,
          'properties' => [
            'field_heading' => 'Pinned heading',
            'field_ref' => ['target_id' => (int) \Drupal::currentUser()->id()],
            'label' => 'My heading',
          ],
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
          'block_type' => 'basic',
        ],
        [
          'op' => 'add_section',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
          'uuid' => $inner,
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $inner, 'placement' => 'inside'],
          'block_type' => 'basic',
        ],
      ],
      'section' => $section,
      'text' => $text,
    ];
  }

  /**
   * Save-as-stencil: compose AND save in ONE batch (the AI parity proof).
   */
  public function testSaveStencilInComposingBatch(): void {
    ['entity' => $entity] = $this->createHost();
    ['operations' => $operations, 'section' => $section, 'text' => $text] = $this->compositionOperations($entity->uuid());

    // A block field's style entry: expressible since the tree grammar
    // (the ':{field}' local), so the capture ships it warning-free.
    $entity->set('daedalus_stylish_styles', json_encode([
      "block:$text:field_heading" => ['default' => ['color' => 'tomato']],
    ]));

    $operations[] = [
      'op' => 'save_material',
      'kind' => 'stencil',
      'element' => $section,
      'label' => 'My CTA',
      'description' => 'A composed call to action.',
    ];
    $result = $this->operations()->batchOperations($entity, $operations);
    $this->assertBatchSuccess($result);

    // The capture saw the batch's OWN compositions: nothing was ever saved
    // to the database copy.
    $stencils = \Drupal::entityTypeManager()->getStorage('stencil')->loadByProperties(['machine_name' => 'my_cta']);
    $stencil = reset($stencils);
    $this->assertInstanceOf(StencilInterface::class, $stencil);
    $this->assertSame('My CTA', (string) $stencil->label());
    $this->assertSame('A composed call to action.', $stencil->getDescription());
    $this->assertTrue($stencil->isPublished());
    $expected_element = $this->expectedReferenceElement();
    $expected_element['children'][0]['children'][0]['styles'] = [
      ':field_heading' => ['default' => ['color' => 'tomato']],
    ];
    $this->assertEquals($expected_element, $stencil->getElement());

    // Nothing warned onto the batch's warning channel: every subtree style
    // key has a tree-local form.
    $warnings = array_filter(
      $result->getWarnings(),
      static fn ($warning) => $warning->getCode() === MaterialGestureOperationHandler::CAPTURE_WARNING,
    );
    $this->assertCount(0, $warnings);

    // The save reached the client's channel: the stencil's entity hook
    // recorded it on the request's materials change set, so the mutation
    // response carries its record as an upsert (the delivery contract).
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->delta();
    $this->assertSame([], $delta['remove']);
    $this->assertSame([['stencil', 'my_cta']], array_map(static fn (array $record): array => [
      $record['kind'],
      $record['id'],
    ], $delta['upsert']));
    $this->assertSame('My CTA', $delta['upsert'][0]['label']);
    $this->assertSame('section', $delta['upsert'][0]['root_kind']);
    // A person minted it through the op lane: the origin base field stamps
    // user (the AI tools stamp ai, testOriginStampedByLane).
    $this->assertSame('user', $delta['upsert'][0]['origin']);
    $this->assertSame('user', $stencil->getOrigin());

    // The save IS a palette entry: discovery serves it as a content stencil.
    $definition = \Drupal::service(StencilDiscoveryInterface::class)->getStencil('my_cta');
    $this->assertNotNull($definition);
    $this->assertSame(StencilDiscoveryInterface::SOURCE_CONTENT, $definition['source']);
    $this->assertEquals($expected_element, $definition['element']);
  }

  /**
   * The ops enforce the "save stencils" permission (never validation-exempt).
   */
  public function testPermissionGate(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $this->setUpCurrentUser([], [], FALSE);
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'kind' => 'stencil', 'element' => $section, 'label' => 'X', 'description' => 'Y'],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('PERMISSION_DENIED', $result->getErrors()[0]->getCode());
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('stencil')->loadMultiple(), 'No stencil was created.');
  }

  /**
   * Malformed save gestures fail loudly with blueprint-shaped errors.
   */
  public function testSaveValidation(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'kind' => 'stencil', 'element' => $section, 'label' => '', 'description' => ''],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);
    $this->assertSame(['MISSING_PARAMETER'], $codes['description']);

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'kind' => 'stencil', 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['element']);

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => '11111111-2222-3333-4444-555555555555',
        'label' => 'CTA',
        'description' => 'A CTA.',
      ],
    ]));
    $this->assertSame(['STENCIL_CAPTURE_FAILED'], $codes['element']);

    // The family's own envelope: a missing or gesture-less kind, a missing
    // id on update, an empty update.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'element' => $section, 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['kind']);
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'kind' => 'token', 'element' => $section, 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['NO_GESTURE'], $codes['kind']);
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'element' => $section],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['id']);
  }

  /**
   * The origin base field is the lane's stamp: user here, ai on the AI door.
   *
   * The AI tools pass MaterialGestureOperationHandler::ORIGIN_OPTION = ai on
   * every batch (DaedalusBlueprintToolBase::blueprintOptions()); every other
   * lane stamps user. The roster record carries it for the life of the
   * site, and the delta the response carries says so at once.
   */
  public function testOriginStampedByLane(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'By the AI',
        'description' => 'Minted on request.',
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertBatchSuccess($result);
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->flush();
    $this->assertSame('ai', $delta['upsert'][0]['origin']);
    $stencils = \Drupal::entityTypeManager()->getStorage('stencil')->loadByProperties(['machine_name' => 'by_the_ai']);
    $stencil = reset($stencils);
    $this->assertSame('ai', $stencil->getOrigin());

    // A later update by a person never rewrites who minted it.
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'by_the_ai', 'label' => 'Renamed by a person'],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertSame('ai', \Drupal::entityTypeManager()->getStorage('stencil')->loadUnchanged($stencil->id())->getOrigin());

    // remove_material deletes it; the delta says remove.
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'remove_material', 'kind' => 'stencil', 'id' => 'by_the_ai'],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'stencil', 'id' => 'by_the_ai']]], \Drupal::service('daedalus_blueprint.materials_change_set')->flush());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('stencil')->loadUnchanged($stencil->id()));

    // A malformed origin option is a programming error, never a stamp.
    $this->expectException(\InvalidArgumentException::class);
    $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'Bad',
        'description' => 'Bad origin.',
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'robot']);
  }

  /**
   * Push: a new revision carrying the captured element, metadata untouched.
   */
  public function testUpdateStencil(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    assert($storage instanceof RevisionableStorageInterface);
    $stencil = $storage->create([
      'label' => 'My CTA',
      'description' => 'A composed call to action.',
      'uid' => \Drupal::currentUser()->id(),
    ]);
    assert($stencil instanceof StencilInterface);
    $stencil->setElement(['kind' => 'block', 'block_type' => 'basic']);
    $stencil->save();
    $first_revision = (int) $stencil->getRevisionId();

    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'my_cta', 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    $updated = $storage->loadUnchanged($stencil->id());
    assert($updated instanceof StencilInterface);
    $this->assertEquals($this->expectedReferenceElement(), $updated->getElement());
    $this->assertGreaterThan($first_revision, (int) $updated->getRevisionId(), 'The push is REVISIONED: a bad push is one revert away.');
    $this->assertSame('my_cta', $updated->getMachineName());
    $this->assertSame('My CTA', (string) $updated->label());
    $this->assertStringContainsString('entity_test', (string) $updated->getRevisionLogMessage());

    // The old element survives as the previous revision.
    $previous = $storage->loadRevision($first_revision);
    assert($previous instanceof StencilInterface);
    $this->assertEquals(['kind' => 'block', 'block_type' => 'basic'], $previous->getElement());

    // The uuid reference form resolves too.
    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => $stencil->uuid(), 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    // The palette face rides the same verb: label, description and icon
    // land in ONE revision beside a push, metadata no longer a separate
    // lane (the details route is an adapter over the same provider).
    $before = (int) $storage->loadUnchanged($stencil->id())->getRevisionId();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'update_material',
        'kind' => 'stencil',
        'id' => 'my_cta',
        'element' => $section,
        'label' => 'Our CTA',
        'description' => 'Renamed while pushing.',
      ],
    ]);
    $this->assertBatchSuccess($result);
    $renamed = $storage->loadUnchanged($stencil->id());
    $this->assertSame('Our CTA', (string) $renamed->label());
    $this->assertSame('Renamed while pushing.', $renamed->getDescription());
    $this->assertSame($before + 1, (int) $renamed->getRevisionId(), 'Push and face land in one revision.');

    // An update naming nothing to change refuses.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'my_cta'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
  }

  /**
   * Update targets resolve loudly: read-only files and unknown references.
   */
  public function testUpdateRefusals(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    // The hero stencil ships with the fixture module: a FILE stencil,
    // read-only in every UI. The refusal names the provider instead of
    // hiding behind a not-found.
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['daedalus_blueprint_stencils']);

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'hero', 'element' => $section],
    ]));
    $this->assertSame(['STENCIL_READ_ONLY'], $codes['id']);

    // The hint follows the distro lock. While it holds (a consuming site)
    // the site forks; under the developer gate the refusal names the
    // file's update lane, drush stencil-ship from a saved placement in the
    // provider module. The code and the refusal itself are the same under
    // both: a file stencil never gains an entity to update.
    $this->assertStringContainsString('NEW stencil', $this->readOnlyHint('hero'));
    $this->assertStringNotContainsString('stencil-ship', $this->readOnlyHint('hero'));
    $this->setSetting(DistroLock::SETTING, TRUE);
    $hint = $this->readOnlyHint('hero');
    $this->assertStringContainsString('drush daedalus-blueprint:stencil-ship', $hint);
    $this->assertStringContainsString('daedalus_blueprint_stencil_test', $hint, 'The lane names the provider module.');
    $this->assertStringContainsString('Save the page', $hint, 'The command reads the saved page.');
    $this->assertStringNotContainsString('NEW stencil', $hint);
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'hero', 'element' => $section],
    ]));
    $this->assertSame(['STENCIL_READ_ONLY'], $codes['id'], 'The gate changes the hint, never the refusal.');
    $this->setSetting(DistroLock::SETTING, FALSE);
    $this->assertStringContainsString('NEW stencil', $this->readOnlyHint('hero'));

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'nope', 'element' => $section],
    ]));
    $this->assertSame(['STENCIL_NOT_FOUND'], $codes['id']);

    // Removal refuses the same targets the same way.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      ['op' => 'remove_material', 'kind' => 'stencil', 'id' => 'hero'],
    ]));
    $this->assertSame(['STENCIL_READ_ONLY'], $codes['id']);
  }

  /**
   * An unpublished stencil is its author's draft: push keeps working.
   */
  public function testUpdateUnpublished(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $stencil = \Drupal::entityTypeManager()->getStorage('stencil')->create([
      'label' => 'Draft CTA',
      'description' => 'Still staging.',
      'uid' => \Drupal::currentUser()->id(),
      'status' => FALSE,
    ]);
    assert($stencil instanceof StencilInterface);
    $stencil->setElement(['kind' => 'block', 'block_type' => 'basic']);
    $stencil->save();

    $result = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => 'draft_cta', 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    $updated = \Drupal::entityTypeManager()->getStorage('stencil')->loadUnchanged($stencil->id());
    assert($updated instanceof StencilInterface);
    $this->assertFalse($updated->isPublished());
    $this->assertEquals($this->expectedReferenceElement(), $updated->getElement());
  }

  /**
   * The icon round-trip on the op spine: save with icon, catalog serves it.
   *
   * One vocabulary end to end: the gesture takes the icon value grammar's
   * media spelling, the entity stores the media REFERENCE, the discovery
   * catalog serves the spelling back, and the material record the response
   * delivers carries the icon RESOLVED (the client palette paints it and
   * cannot resolve a media reference itself).
   */
  public function testSaveStencilIconRoundTrip(): void {
    $media = $this->setUpIconMedia();
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'My CTA',
        'description' => 'A composed call to action.',
        'icon' => 'media:' . $media->uuid(),
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The entity stores the media reference itself.
    $stencils = \Drupal::entityTypeManager()->getStorage('stencil')->loadByProperties(['machine_name' => 'my_cta']);
    $stencil = reset($stencils);
    $this->assertInstanceOf(StencilInterface::class, $stencil);
    $this->assertSame($media->uuid(), $stencil->getIcon()?->uuid());

    // The material record the response delivers to the client carries the
    // icon resolved through IconResolver: this fixture's media has no
    // source file, so it degrades to NULL (the default icon) and the
    // media's tag rides the roster so a later file lands on the record.
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->delta();
    $this->assertNull($delta['upsert'][0]['icon']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());

    // The catalog serves the content lane in the grammar's media spelling;
    // resolution to a URL is the provider's job (IconResolver).
    $definition = \Drupal::service(StencilDiscoveryInterface::class)->getStencil('my_cta');
    $this->assertSame('media:' . $media->uuid(), $definition['icon']);
  }

  /**
   * The icon gestures refuse bad spellings loudly, on save and on update.
   *
   * The gesture lane accepts ONLY the media spelling (the path lane belongs
   * to module-shipped stencil files) and unknown media refuses at write
   * time; update_material takes the icon too (one verb rewrites the whole
   * material, capture and face alike) and refuses it the same way.
   */
  public function testIconGestureRefusals(): void {
    $this->setUpIconMedia();
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    // The path spelling is the stencil FILES' lane; a path here has
    // nowhere to live.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => '/modules/x/assets/icon.svg',
      ],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['icon']);

    // Unknown media refuses at write time; the gesture never creates media.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => 'media:11111111-2222-3333-4444-555555555555',
      ],
    ]));
    $this->assertSame(['STENCIL_ICON_UNKNOWN_MEDIA'], $codes['icon']);

    // A non-string icon is a malformed gesture, not a silent drop.
    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => 123,
      ],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['icon']);

    // No refusal left a half-saved stencil behind.
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('stencil')->loadMultiple());

    // update_material refuses a bad icon the same way, storing nothing.
    $stencil = \Drupal::entityTypeManager()->getStorage('stencil')->create([
      'label' => 'My CTA',
      'description' => 'A composed call to action.',
      'uid' => \Drupal::currentUser()->id(),
    ]);
    assert($stencil instanceof StencilInterface);
    $stencil->setElement(['kind' => 'block', 'block_type' => 'basic']);
    $stencil->save();

    $codes = $this->errorCodes($this->operations()->batchOperations($entity, [
      [
        'op' => 'update_material',
        'kind' => 'stencil',
        'id' => 'my_cta',
        'element' => $section,
        'icon' => 'media:11111111-2222-3333-4444-555555555555',
      ],
    ]));
    $this->assertSame(['STENCIL_ICON_UNKNOWN_MEDIA'], $codes['icon']);
    $unchanged = \Drupal::entityTypeManager()->getStorage('stencil')->loadUnchanged($stencil->id());
    assert($unchanged instanceof StencilInterface);
    $this->assertEquals(['kind' => 'block', 'block_type' => 'basic'], $unchanged->getElement(), 'The refused push changed nothing.');
    $this->assertNull($unchanged->getIcon());
  }

  /**
   * Installs the media stack and returns a saved media item for icon tests.
   *
   * The icon reference needs no source file: the gestures store and refuse
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
   * Collects error codes keyed by path from a failed batch result.
   */
  protected function errorCodes(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'Expected the batch to fail.');
    $codes = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The hint a file stencil's read-only refusal carries right now.
   */
  protected function readOnlyHint(string $id): string {
    try {
      $this->container->get('daedalus_blueprint_stencil.manager')->resolveUpdatable($id);
    }
    catch (StencilGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('STENCIL_READ_ONLY', $error->getCode());
      return $error->getHint();
    }
    $this->fail("The \"{$id}\" file stencil must refuse to resolve as updatable.");
  }

}
