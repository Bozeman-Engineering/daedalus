<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\EntityReferenceFieldHandler;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the EntityReferenceFieldHandler.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(EntityReferenceFieldHandler::class)]
#[Group('daedalus_blueprint')]
class EntityReferenceFieldHandlerTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    'taxonomy',
  ];

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\EntityReferenceFieldHandler
   */
  protected EntityReferenceFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');

    // Create vocabulary.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    // Multi-value entity reference.
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => -1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Tags',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['tags' => 'tags'],
        ],
      ],
    ])->save();

    // Single-value entity reference.
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Category',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['tags' => 'tags'],
        ],
      ],
    ])->save();

    // Single-value reference to entity_test, whose view access is gated by the
    // 'view test entity' permission. Used to exercise access-aware resolution.
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

    $this->handler = $this->container->get('daedalus_blueprint.field_handler.entity_reference');
  }

  /**
   * Creates a taxonomy term.
   *
   * @param string $name
   *   The term name.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   The created term.
   */
  protected function createTerm(string $name): Term {
    $term = Term::create([
      'vid' => 'tags',
      'name' => $name,
    ]);
    $term->save();
    return $term;
  }

  /**
   * Tests serializing entity references includes target_id and _label.
   */
  public function testSerializeSingleReference(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity([
      'field_category' => ['target_id' => $term->id()],
    ]);

    $definition = $entity->getFieldDefinition('field_category');
    $result = $this->handler->serialize($entity->get('field_category'), $definition);

    $this->assertIsArray($result);
    $this->assertSame((int) $term->id(), $result['target_id']);
    $this->assertSame('News', $result['_label']);
  }

  /**
   * Tests serializing multi-value entity references.
   */
  public function testSerializeMultipleReferences(): void {
    $term1 = $this->createTerm('News');
    $term2 = $this->createTerm('Sports');
    $entity = $this->createTestEntity([
      'field_tags' => [
        ['target_id' => $term1->id()],
        ['target_id' => $term2->id()],
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_tags');
    $result = $this->handler->serialize($entity->get('field_tags'), $definition);

    $this->assertIsArray($result);
    $this->assertCount(2, $result);
    $this->assertSame('News', $result[0]['_label']);
    $this->assertSame('Sports', $result[1]['_label']);
  }

  /**
   * Tests serializing empty reference returns NULL.
   */
  public function testSerializeEmptyReference(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');
    $result = $this->handler->serialize($entity->get('field_category'), $definition);

    $this->assertNull($result);
  }

  /**
   * Tests deserializing by target_id.
   */
  public function testDeserializeByTargetId(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['target_id' => (int) $term->id()],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $term->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests deserializing by _label.
   */
  public function testDeserializeByLabel(): void {
    $term = $this->createTerm('Technology');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['_label' => 'Technology'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $term->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests deserializing with bare string resolves as _label.
   */
  public function testDeserializeBareString(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize('News', $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $term->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests REFERENCE_NOT_FOUND with similar labels.
   */
  public function testReferenceNotFoundWithSimilarLabels(): void {
    $this->createTerm('Technology');
    $this->createTerm('Tech News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['_label' => 'Tech'],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('REFERENCE_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame('fields.field_category', $errors[0]->getPath());
    $this->assertNotEmpty($errors[0]->getContext()['similar']);
  }

  /**
   * Tests REFERENCE_NOT_FOUND for nonexistent target_id.
   */
  public function testReferenceNotFoundByTargetId(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['target_id' => 99999],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests REFERENCE_AMBIGUOUS when multiple entities match _label.
   */
  public function testReferenceAmbiguous(): void {
    $this->createTerm('News');
    $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['_label' => 'News'],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertSame('REFERENCE_AMBIGUOUS', $errors[0]->getCode());
    $this->assertCount(2, $errors[0]->getContext()['matches']);
  }

  /**
   * Tests an unmatched label returns not-found and fabricates nothing.
   */
  public function testUnmatchedLabelDoesNotCreate(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_tags');

    $result = $this->handler->deserialize(
      [['_label' => 'Brand New Tag']],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());

    // No term was fabricated from the unmatched label.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $term_storage->loadByProperties(['name' => 'Brand New Tag']);
    $this->assertCount(0, $terms);
  }

  /**
   * Tests an unmatched label on a single-value reference returns not-found.
   */
  public function testUnmatchedLabelSingleReference(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->handler->deserialize(
      ['_label' => 'Nonexistent Category'],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests multi-value deserialization.
   */
  public function testDeserializeMultiValue(): void {
    $term1 = $this->createTerm('News');
    $term2 = $this->createTerm('Sports');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_tags');

    $result = $this->handler->deserialize(
      [
        ['target_id' => (int) $term1->id()],
        ['_label' => 'Sports'],
      ],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $values = $result->getValue();
    $this->assertCount(2, $values);
    $this->assertSame((int) $term1->id(), $values[0]['target_id']);
    $this->assertSame((int) $term2->id(), $values[1]['target_id']);
  }

  /**
   * Tests multi-value error path includes array index.
   */
  public function testMultiValueErrorPath(): void {
    $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_tags');

    $result = $this->handler->deserialize(
      [
        ['_label' => 'News'],
        ['target_id' => 99999],
      ],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('fields.field_tags[1]', $result->getErrors()[0]->getPath());
  }

  /**
   * Tests round-trip serialization/deserialization.
   */
  public function testRoundTrip(): void {
    $term1 = $this->createTerm('Alpha');
    $term2 = $this->createTerm('Beta');
    $entity = $this->createTestEntity([
      'field_tags' => [
        ['target_id' => $term1->id()],
        ['target_id' => $term2->id()],
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_tags');
    $serialized = $this->handler->serialize($entity->get('field_tags'), $definition);

    $deserialized = $this->handler->deserialize($serialized, $definition);
    $this->assertFalse($deserialized->hasErrors());
    $values = $deserialized->getValue();
    $this->assertCount(2, $values);
    $this->assertSame((int) $term1->id(), $values[0]['target_id']);
    $this->assertSame((int) $term2->id(), $values[1]['target_id']);
  }

  /**
   * Tests deserializing NULL/empty returns passthrough.
   */
  public function testDeserializeEmpty(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $this->assertFalse($this->handler->deserialize(NULL, $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize('', $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize([], $definition)->hasErrors());
  }

  /**
   * Resolving an unviewable target by label returns not-found, not the entity.
   *
   * RF1: label resolution must be access-aware. An exact-label match the
   * current user cannot view is excluded, so resolution falls through to
   * REFERENCE_NOT_FOUND rather than silently resolving a reference to an entity
   * outside the user's access.
   */
  public function testResolveByLabelToUnviewableTargetReturnsNotFound(): void {
    $this->createTestEntity(['name' => 'Secret Ref']);

    // A user who cannot view entity_test entities.
    $this->setUpCurrentUser([], []);

    $result = $this->handler->deserialize(['_label' => 'Secret Ref'], $this->refDefinition());

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Unviewable matches are excluded from the AMBIGUOUS candidate list.
   *
   * RF1: the candidates list echoes each match's label. With every match
   * unviewable, the result must not surface them — it falls through to
   * REFERENCE_NOT_FOUND with no 'matches' context (previously it would have
   * returned REFERENCE_AMBIGUOUS leaking both labels).
   */
  public function testResolveByLabelExcludesUnviewableFromCandidates(): void {
    $this->createTestEntity(['name' => 'Dup Ref']);
    $this->createTestEntity(['name' => 'Dup Ref']);

    $this->setUpCurrentUser([], []);

    $result = $this->handler->deserialize(['_label' => 'Dup Ref'], $this->refDefinition());

    $this->assertTrue($result->hasErrors());
    $error = $result->getErrors()[0];
    $this->assertSame('REFERENCE_NOT_FOUND', $error->getCode());
    $this->assertArrayNotHasKey('matches', $error->getContext());
  }

  /**
   * A viewable target still resolves by label.
   */
  public function testResolveByLabelToViewableTargetResolves(): void {
    $target = $this->createTestEntity(['name' => 'Visible Ref']);

    // A user who can view entity_test entities.
    $this->setUpCurrentUser([], ['view test entity']);

    $result = $this->handler->deserialize(['_label' => 'Visible Ref'], $this->refDefinition());

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $target->id(), $result->getValue()['target_id']);
  }

  /**
   * A target_id whose target is unviewable resolves without echoing its label.
   *
   * RF1: the LABEL_MISMATCH warning discloses the entity's real label.
   * Resolving an explicit, AI-supplied target_id stays valid, but must not
   * surface the actual label of an entity the current user cannot view.
   */
  public function testTargetIdMismatchSuppressesUnviewableLabel(): void {
    $target = $this->createTestEntity(['name' => 'Real Name']);

    $this->setUpCurrentUser([], []);

    $result = $this->handler->deserialize(
      ['target_id' => (int) $target->id(), '_label' => 'Wrong Name'],
      $this->refDefinition(),
    );

    // Resolves by the explicit id, but the real label is not disclosed.
    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $target->id(), $result->getValue()['target_id']);
    $this->assertEmpty($result->getWarnings());
  }

  /**
   * The LABEL_MISMATCH warning is still emitted when the target is viewable.
   */
  public function testTargetIdMismatchEchoesViewableLabel(): void {
    $target = $this->createTestEntity(['name' => 'Real Name']);

    $this->setUpCurrentUser([], ['view test entity']);

    $result = $this->handler->deserialize(
      ['target_id' => (int) $target->id(), '_label' => 'Wrong Name'],
      $this->refDefinition(),
    );

    $this->assertFalse($result->hasErrors());
    $warnings = $result->getWarnings();
    $this->assertNotEmpty($warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
  }

  /**
   * Returns the field_ref field definition for access-gated resolution tests.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The entity_test field_ref definition.
   */
  private function refDefinition(): FieldDefinitionInterface {
    return $this->entityTypeManager->getStorage('entity_test')
      ->create()
      ->getFieldDefinition('field_ref');
  }

}
