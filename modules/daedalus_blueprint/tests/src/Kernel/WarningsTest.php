<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

// cspell:ignore Newz Sportz
use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\FieldHandler\EntityReferenceFieldHandler;
use Drupal\daedalus_blueprint\FieldHandler\FileFieldHandler;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests warning generation throughout the blueprint pipeline.
 *
 * Covers LABEL_MISMATCH warnings from EntityReferenceFieldHandler and
 * FileFieldHandler, and verifies warnings flow through the deserializer
 * into BlueprintResult.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(EntityReferenceFieldHandler::class)]
#[CoversClass(FileFieldHandler::class)]
#[CoversClass(BlueprintDeserializer::class)]
#[CoversClass(BlueprintResult::class)]
#[Group('daedalus_blueprint')]
class WarningsTest extends BlueprintKernelTestBase {

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
    'file',
    'image',
  ];

  /**
   * The entity reference handler.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\EntityReferenceFieldHandler
   */
  protected EntityReferenceFieldHandler $refHandler;

  /**
   * The file handler.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\FileFieldHandler
   */
  protected FileFieldHandler $fileHandler;

  /**
   * The deserializer.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializerInterface
   */
  protected BlueprintDeserializerInterface $deserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    // Create vocabulary.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    // Entity reference field (single).
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

    // Multi-value entity reference field.
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

    // File field.
    FieldStorageConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'entity_test',
      'type' => 'file',
      'cardinality' => 1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Attachment',
    ])->save();

    // Image field.
    FieldStorageConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'entity_test',
      'type' => 'image',
      'cardinality' => 1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Image',
    ])->save();

    $this->refHandler = $this->container->get('daedalus_blueprint.field_handler.entity_reference');
    $this->fileHandler = $this->container->get('daedalus_blueprint.field_handler.file');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
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
    $term = Term::create(['vid' => 'tags', 'name' => $name]);
    $term->save();
    return $term;
  }

  /**
   * Creates a file entity.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\file\Entity\File
   *   The created file.
   */
  protected function createFile(string $filename): File {
    $file = File::create([
      'filename' => $filename,
      'uri' => "public://{$filename}",
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Tests LABEL_MISMATCH warning when target_id + stale _label.
   */
  public function testEntityReferenceLabelMismatch(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->refHandler->deserialize(
      ['target_id' => (int) $term->id(), '_label' => 'Newz'],
      $definition,
    );

    // Reference resolves successfully.
    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $term->id(), $result->getValue()['target_id']);

    // Warning generated.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertSame('fields.field_category', $warnings[0]->getPath());
    $this->assertStringContainsString('Newz', $warnings[0]->getMessage());
    $this->assertStringContainsString('News', $warnings[0]->getMessage());
  }

  /**
   * Tests no warning when _label matches actual label.
   */
  public function testEntityReferenceNoWarningOnMatch(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->refHandler->deserialize(
      ['target_id' => (int) $term->id(), '_label' => 'News'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertEmpty($result->getWarnings());
  }

  /**
   * Tests no warning when target_id provided without _label.
   */
  public function testEntityReferenceNoWarningWithoutLabel(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_category');

    $result = $this->refHandler->deserialize(
      ['target_id' => (int) $term->id()],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertEmpty($result->getWarnings());
  }

  /**
   * Tests LABEL_MISMATCH in multi-value field with correct path indexing.
   */
  public function testEntityReferenceMultiValueLabelMismatch(): void {
    $term1 = $this->createTerm('News');
    $term2 = $this->createTerm('Sports');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_tags');

    $result = $this->refHandler->deserialize(
      [
        ['target_id' => (int) $term1->id(), '_label' => 'News'],
        ['target_id' => (int) $term2->id(), '_label' => 'Sportz'],
      ],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertSame('fields.field_tags[1]', $warnings[0]->getPath());
  }

  /**
   * Tests warnings still collected alongside errors.
   */
  public function testEntityReferenceWarningsWithErrors(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_tags');

    $result = $this->refHandler->deserialize(
      [
        ['target_id' => (int) $term->id(), '_label' => 'Newz'],
        ['target_id' => 99999],
      ],
      $definition,
    );

    // Has errors (second item not found).
    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());

    // Warning still collected from first item.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertSame('fields.field_tags[0]', $warnings[0]->getPath());
  }

  /**
   * Tests LABEL_MISMATCH warning for file field.
   */
  public function testFileLabelMismatch(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->fileHandler->deserialize(
      ['target_id' => (int) $file->id(), '_label' => 'old-document.pdf'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $file->id(), $result->getValue()['target_id']);

    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertSame('fields.field_attachment', $warnings[0]->getPath());
    $this->assertStringContainsString('old-document.pdf', $warnings[0]->getMessage());
    $this->assertStringContainsString('document.pdf', $warnings[0]->getMessage());
  }

  /**
   * Tests no warning when file _label matches actual filename.
   */
  public function testFileNoWarningOnMatch(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->fileHandler->deserialize(
      ['target_id' => (int) $file->id(), '_label' => 'document.pdf'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertEmpty($result->getWarnings());
  }

  /**
   * Tests LABEL_MISMATCH for image field with alt text preserved.
   */
  public function testImageLabelMismatchWithAlt(): void {
    $file = $this->createFile('hero-banner.jpg');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_image');

    $result = $this->fileHandler->deserialize(
      [
        'target_id' => (int) $file->id(),
        '_label' => 'old-hero.jpg',
        'alt' => 'Hero image',
      ],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    // Alt preserved.
    $this->assertSame('Hero image', $result->getValue()['alt']);

    // Warning generated.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
  }

  /**
   * Tests warnings flow through the full deserializer pipeline.
   */
  public function testWarningsInDeserializerPipeline(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'field_category' => [
          'target_id' => (int) $term->id(),
          '_label' => 'Stale Label',
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());

    // Warning present on result.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
    $this->assertSame('fields.field_category', $warnings[0]->getPath());
  }

  /**
   * Tests warnings appear in result toArray() output.
   */
  public function testWarningsInToArrayOutput(): void {
    $term = $this->createTerm('Sports');
    $entity = $this->createTestEntity();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'field_category' => [
          'target_id' => (int) $term->id(),
          '_label' => 'Sportz',
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $array = $result->toArray();

    $this->assertTrue($array['success']);
    $this->assertArrayHasKey('warnings', $array);
    $this->assertCount(1, $array['warnings']);
    $this->assertSame('LABEL_MISMATCH', $array['warnings'][0]['code']);
    $this->assertSame('fields.field_category', $array['warnings'][0]['path']);
    $this->assertStringContainsString('Sportz', $array['warnings'][0]['message']);
  }

  /**
   * Tests successful deserialization with no warnings has empty warnings.
   */
  public function testNoWarningsOnCleanDeserialization(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'field_category' => [
          'target_id' => (int) $term->id(),
          '_label' => 'News',
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $this->assertEmpty($result->getWarnings());

    // toArray() should not have warnings key.
    $array = $result->toArray();
    $this->assertArrayNotHasKey('warnings', $array);
  }

  /**
   * Tests warnings alongside semantic failure.
   */
  public function testWarningsOnSemanticFailure(): void {
    $term = $this->createTerm('News');
    $entity = $this->createTestEntity();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [
        'field_category' => [
          'target_id' => (int) $term->id(),
          '_label' => 'Stale',
        ],
        'field_tags' => [
          ['target_id' => 99999],
        ],
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());

    // Errors from field_tags.
    $errors = $result->getErrors();
    $has_ref_error = FALSE;
    foreach ($errors as $error) {
      if ($error->getCode() === 'REFERENCE_NOT_FOUND') {
        $has_ref_error = TRUE;
      }
    }
    $this->assertTrue($has_ref_error);

    // Warnings still collected from field_category.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('LABEL_MISMATCH', $warnings[0]->getCode());
  }

}
