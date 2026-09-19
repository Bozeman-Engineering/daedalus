<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\Core\File\FileSystemInterface;
use Drupal\daedalus_blueprint\FieldHandler\FileFieldHandler;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the FileFieldHandler.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(FileFieldHandler::class)]
#[Group('daedalus_blueprint')]
class FileFieldHandlerTest extends BlueprintKernelTestBase {

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
    'file',
    'image',
  ];

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\FileFieldHandler
   */
  protected FileFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

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
      'settings' => [
        'file_extensions' => 'png gif jpg jpeg',
      ],
    ])->save();

    $this->handler = $this->container->get('daedalus_blueprint.field_handler.file');
  }

  /**
   * Creates a file entity.
   *
   * @param string $filename
   *   The filename.
   * @param string $uri
   *   The file URI.
   *
   * @return \Drupal\file\Entity\File
   *   The created file entity.
   */
  protected function createFile(string $filename, string $uri = ''): File {
    if (!$uri) {
      $uri = 'public://' . $filename;
    }
    // Create the physical file.
    $directory = dirname($uri);
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($uri, 'test content');

    $file = File::create([
      'uri' => $uri,
      'filename' => $filename,
      'status' => 1,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Tests serializing a file reference.
   */
  public function testSerializeFileReference(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity([
      'field_attachment' => [
        'target_id' => $file->id(),
        'description' => 'Important document',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_attachment');
    $result = $this->handler->serialize($entity->get('field_attachment'), $definition);

    $this->assertSame((int) $file->id(), $result['target_id']);
    $this->assertSame('document.pdf', $result['_label']);
    $this->assertSame('Important document', $result['description']);
  }

  /**
   * Tests serializing an image reference includes alt and title.
   */
  public function testSerializeImageReference(): void {
    $file = $this->createFile('hero.jpg');
    $entity = $this->createTestEntity([
      'field_image' => [
        'target_id' => $file->id(),
        'alt' => 'Hero image',
        'title' => 'Welcome',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_image');
    $result = $this->handler->serialize($entity->get('field_image'), $definition);

    $this->assertSame((int) $file->id(), $result['target_id']);
    $this->assertSame('hero.jpg', $result['_label']);
    $this->assertSame('Hero image', $result['alt']);
    $this->assertSame('Welcome', $result['title']);
  }

  /**
   * Tests serializing empty file field returns NULL.
   */
  public function testSerializeEmptyFile(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');
    $result = $this->handler->serialize($entity->get('field_attachment'), $definition);

    $this->assertNull($result);
  }

  /**
   * Tests deserializing by target_id.
   */
  public function testDeserializeByTargetId(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize(
      ['target_id' => (int) $file->id()],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $file->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests deserializing by _label (filename).
   */
  public function testDeserializeByLabel(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize(
      ['_label' => 'document.pdf'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $file->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests deserializing image with alt and title passes through properties.
   */
  public function testDeserializeImageWithProperties(): void {
    $file = $this->createFile('photo.jpg');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_image');

    $result = $this->handler->deserialize(
      [
        'target_id' => (int) $file->id(),
        'alt' => 'Photo alt',
        'title' => 'Photo title',
      ],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $value = $result->getValue();
    $this->assertSame((int) $file->id(), $value['target_id']);
    $this->assertSame('Photo alt', $value['alt']);
    $this->assertSame('Photo title', $value['title']);
  }

  /**
   * Tests REFERENCE_NOT_FOUND for nonexistent target_id.
   */
  public function testFileNotFoundByTargetId(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize(
      ['target_id' => 99999],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests REFERENCE_NOT_FOUND for nonexistent filename.
   */
  public function testFileNotFoundByLabel(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize(
      ['_label' => 'nonexistent.pdf'],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests REFERENCE_AMBIGUOUS when multiple files match filename.
   */
  public function testFileAmbiguous(): void {
    $this->createFile('report.pdf', 'public://report.pdf');
    $this->createFile('report.pdf', 'public://archive/report.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize(
      ['_label' => 'report.pdf'],
      $definition,
    );

    $this->assertTrue($result->hasErrors());
    $this->assertSame('REFERENCE_AMBIGUOUS', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests deserializing integer shorthand as target_id.
   */
  public function testDeserializeIntegerShorthand(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $result = $this->handler->deserialize((int) $file->id(), $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame((int) $file->id(), $result->getValue()['target_id']);
  }

  /**
   * Tests deserializing empty values.
   */
  public function testDeserializeEmpty(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_attachment');

    $this->assertFalse($this->handler->deserialize(NULL, $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize('', $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize([], $definition)->hasErrors());
  }

  /**
   * Tests round-trip serialization/deserialization.
   */
  public function testRoundTrip(): void {
    $file = $this->createFile('document.pdf');
    $entity = $this->createTestEntity([
      'field_attachment' => ['target_id' => $file->id()],
    ]);

    $definition = $entity->getFieldDefinition('field_attachment');
    $serialized = $this->handler->serialize($entity->get('field_attachment'), $definition);
    $deserialized = $this->handler->deserialize($serialized, $definition);

    $this->assertFalse($deserialized->hasErrors());
    $this->assertSame((int) $file->id(), $deserialized->getValue()['target_id']);
  }

}
