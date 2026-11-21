<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Access;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint_config\ConfigBlueprintBackend;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the read path filters fields and references by view access.
 *
 * CP1: serialization must drop fields the current user cannot view and
 * references to entities the current user cannot view, so an AI running as
 * the browsing user cannot read past that user's access. The base test class
 * runs as an admin (access-transparent); each test here switches to a
 * restricted user to exercise the denial.
 *
 * The unsaved copy is for editors: a reader's load answers it only to a
 * user who may update the host, the stored entity to anyone else; and a
 * config entity, serialized whole, is read on update access.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class BlueprintReadAccessTest extends BlueprintKernelTestBase {

  /**
   * The blueprint serializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializerInterface
   */
  protected BlueprintSerializerInterface $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // An entity_reference field targeting entity_test, so the referenced
    // entity's view access is gated by the 'view test entity' permission.
    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Reference',
      'settings' => ['handler' => 'default'],
    ])->save();

    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * A field the current user cannot view carries identity, never its value.
   *
   * FULL mode is structure-complete: access gates VALUES (and verbs at
   * use time), not structure, so the restricted field appears as an
   * identity-only stub (the definition label and the form_only marker),
   * with the stored value withheld.
   */
  public function testFieldNotViewableIsFiltered(): void {
    // entity_test forbids 'view' on this field unless the user holds
    // 'view test entity field'.
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    $entity = $this->createTestEntity([
      'field_subtitle' => 'Hidden subtitle',
      'field_count' => 7,
    ]);

    // An editor who can view the entity but not the restricted field.
    $this->setUpCurrentUser([], ['view test entity']);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertSame(7, $result['fields']['field_count']);
    // Exact-shape assertion: the stub carries NOTHING beyond identity — in
    // particular, never the hidden value.
    $this->assertSame(
      [FormOnlyStub::KEY => TRUE, '_label' => 'Subtitle'],
      $result['fields']['field_subtitle'],
    );
  }

  /**
   * The same restricted field is present for a user who can view it.
   */
  public function testFieldViewableIsIncluded(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    $entity = $this->createTestEntity([
      'field_subtitle' => 'Subtitle',
      'field_count' => 7,
    ]);

    // A user holding both the entity- and field-view permissions.
    $this->setUpCurrentUser([], ['view test entity', 'view test entity field']);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertArrayHasKey('field_subtitle', $result['fields']);
    $this->assertSame('Subtitle', $result['fields']['field_subtitle']);
  }

  /**
   * A reference to an entity the current user cannot view leaks nothing.
   *
   * The handler drops the unviewable target, and structure completeness
   * fills the valueless field with an identity-only stub: the field's own
   * definition label (bundle schema, not content) with no target id,
   * uuid, or label anywhere in the output.
   */
  public function testReferenceToUnviewableEntityIsDropped(): void {
    $target = $this->createTestEntity(['name' => 'Secret target']);
    $host = $this->createTestEntity([
      'field_subtitle' => 'Control',
      'field_ref' => ['target_id' => $target->id()],
    ]);

    // A user who cannot view entity_test entities at all.
    $this->setUpCurrentUser([], []);

    $result = $this->serializer->serialize($this->host($host));

    // Plain field access defaults to allowed, so the control field still
    // serializes; the unviewable reference yields only the stub.
    $this->assertSame('Control', $result['fields']['field_subtitle']);
    $this->assertSame(
      [FormOnlyStub::KEY => TRUE, '_label' => 'Reference'],
      $result['fields']['field_ref'],
    );
    $this->assertStringNotContainsString('Secret target', json_encode($result));
  }

  /**
   * A reference to a viewable entity is kept.
   */
  public function testReferenceToViewableEntityIsKept(): void {
    $target = $this->createTestEntity(['name' => 'Visible target']);
    $host = $this->createTestEntity([
      'field_ref' => ['target_id' => $target->id()],
    ]);

    // A user who can view entity_test entities.
    $this->setUpCurrentUser([], ['view test entity']);

    $result = $this->serializer->serialize($this->host($host));

    $this->assertArrayHasKey('field_ref', $result['fields']);
    $this->assertSame((int) $target->id(), $result['fields']['field_ref']['target_id']);
  }

  /**
   * A reader's load answers the unsaved copy only to a user who may update.
   *
   * Fixture: daedalus_tempstore installed, a subtitle staged over a saved
   * one. A user who may only view the entity is answered the stored
   * entity by the storage handler and by the content backend, and what is
   * serialized for them is the saved value; a user who may update it is
   * answered the unsaved copy. The writer's load() answers the unsaved
   * copy whoever asks: its callers have established update access.
   */
  public function testUnsavedCopyIsLoadedForEditorsOnly(): void {
    // daedalus_tempstore's container needs layout_builder.
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_tempstore']);
    $handler = $this->container->get('daedalus_blueprint.storage_handler');
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolve('entity_test');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');

    $entity = $this->createTestEntity(['field_subtitle' => 'Saved']);
    $id = $entity->id();
    $working = $handler->load('entity_test', $id);
    $unsaved = $working->entity();
    assert($unsaved instanceof FieldableEntityInterface);
    $unsaved->set('field_subtitle', 'Unsaved');
    $this->assertSame('tempstore', $handler->persist($working));
    // The working copy of an unstaged entity is the storage's own cached
    // object; a later request loads the stored row afresh.
    $this->container->get('entity_type.manager')->getStorage('entity_test')->resetCache();

    // May view, may not update: the stored entity.
    $this->setUpCurrentUser([], ['view test entity']);
    $this->assertSame('Saved', $this->subtitle($handler->loadReadable('entity_test', $id)->entity()));
    $read = $backend->loadReadable('entity_test', $id);
    $this->assertSame('Saved', $this->subtitle($read));
    $this->assertSame('Saved', $this->serializer->serialize($this->host($read))['fields']['field_subtitle']);
    $this->assertSame('Unsaved', $this->subtitle($handler->load('entity_test', $id)->entity()));

    // May update: the unsaved copy.
    $this->setUpCurrentUser([], ['view test entity', 'administer entity_test content']);
    $this->assertSame('Unsaved', $this->subtitle($handler->loadReadable('entity_test', $id)->entity()));
    $this->assertSame('Unsaved', $this->subtitle($backend->loadReadable('entity_test', $id)));

    // Nothing to read is still nothing.
    $this->assertNull($handler->loadReadable('entity_test', 999));
  }

  /**
   * A content read asks view; a config read asks update.
   *
   * The config serializer answers the entity's whole export, so its
   * backend names the operation the entity's edit form asks for.
   */
  public function testConfigReadAsksUpdate(): void {
    $this->enableModules(['daedalus_blueprint_config']);
    $resolver = $this->container->get('daedalus_blueprint.backend_resolver');

    $this->assertSame('view', $resolver->resolve('entity_test')->readOperation());
    $config = $resolver->resolve('user_role');
    $this->assertInstanceOf(ConfigBlueprintBackend::class, $config);
    $this->assertSame('update', $config->readOperation());
  }

  /**
   * The subtitle of an entity a load answered.
   */
  protected function subtitle(?EntityInterface $entity): ?string {
    assert($entity instanceof FieldableEntityInterface);
    return $entity->get('field_subtitle')->value;
  }

}
