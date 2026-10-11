<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Access;

use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
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

}
