<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The round-trip fidelity contract.
 *
 * Serialize -> deserialize -> set preserves every user-settable stored
 * property.
 *
 * The deserializer applies handler output via $entity->set(), which replaces
 * the whole field item list. Any stored property a handler's serialize()
 * omits is therefore invisible to AI consumers AND silently wiped by any AI
 * write to that field. These tests pin the property coverage of each handler
 * so a coverage regression fails loudly instead of surfacing as silent data
 * loss in production.
 *
 * Deliberate exclusions (not gaps):
 * - image width/height: derived from the file and recomputed by
 *   ImageItem::preSave() on save; carrying them through the blueprint would
 *   stamp stale dimensions when a write changes the target image.
 * - computed properties (processed, entity): never stored.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class RoundTripFidelityTest extends BlueprintKernelTestBase {

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
    'link',
    'file',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    $this->createField('field_link', 'link');

    // File field with the display checkbox enabled, so the per-item
    // 'display' property is user-settable.
    FieldStorageConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'entity_test',
      'type' => 'file',
      'cardinality' => 1,
      'settings' => [
        'display_field' => TRUE,
        'display_default' => TRUE,
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_attachment',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Attachment',
    ])->save();

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
  }

  /**
   * Serializes a field, deserializes the result, and re-applies it.
   *
   * This is exactly the read-modify-write path an AI consumer follows, so
   * whatever survives it is the fidelity contract.
   *
   * @param object $entity
   *   The entity carrying the field.
   * @param string $field_name
   *   The field to round-trip.
   *
   * @return mixed
   *   The serialized value, for shape assertions.
   */
  protected function roundTrip(object $entity, string $field_name): mixed {
    $definition = $entity->getFieldDefinition($field_name);
    $handler = $this->fieldHandlerManager->getHandler($definition);

    $serialized = $handler->serialize($entity->get($field_name), $definition);
    $result = $handler->deserialize($serialized, $definition);

    $this->assertFalse($result->hasErrors(), 'Round-trip deserialize produced no errors.');
    $entity->set($field_name, $result->getValue());

    return $serialized;
  }

  /**
   * Creates a file entity backed by a real file on disk.
   */
  protected function createFile(string $filename): File {
    $uri = 'public://' . $filename;
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
   * Link fields round-trip uri, title, AND options.
   *
   * Options carry link attributes (target, rel, class) written by widgets
   * like link_attributes. Dropping them was a live AI bug: any AI edit to a
   * link field silently wiped the attributes.
   */
  public function testLinkRoundTripPreservesOptions(): void {
    $options = [
      'attributes' => [
        'target' => '_blank',
        'class' => ['btn', 'btn-primary'],
      ],
      'query' => ['utm_source' => 'newsletter'],
    ];
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'https://example.com',
        'title' => 'Example',
        'options' => $options,
      ],
    ]);

    $serialized = $this->roundTrip($entity, 'field_link');

    $this->assertSame('https://example.com', $serialized['uri']);
    $this->assertSame('Example', $serialized['title']);
    $this->assertSame($options, $serialized['options'] ?? NULL, 'Serialized link exposes options.');

    $item = $entity->get('field_link')->first()->getValue();
    $this->assertSame('https://example.com', $item['uri']);
    $this->assertSame('Example', $item['title']);
    $this->assertSame($options, $item['options'], 'Options survive the round trip.');
  }

  /**
   * Links without options stay lean: no options key in the serialized shape.
   */
  public function testLinkWithoutOptionsSerializesSparse(): void {
    $entity = $this->createTestEntity([
      'field_link' => ['uri' => 'https://example.com', 'title' => 'Example'],
    ]);

    $serialized = $this->roundTrip($entity, 'field_link');

    $this->assertArrayNotHasKey('options', $serialized);
  }

  /**
   * File fields round-trip description AND the display flag.
   *
   * Display is only meaningful when the storage's display_field setting is
   * on; it defaults truthy, so the wipe corrupts exactly the items a user
   * deliberately unchecked.
   */
  public function testFileRoundTripPreservesDisplay(): void {
    $file = $this->createFile('document.txt');
    $entity = $this->createTestEntity([
      'field_attachment' => [
        'target_id' => $file->id(),
        'description' => 'A document',
        'display' => 0,
      ],
    ]);

    $serialized = $this->roundTrip($entity, 'field_attachment');

    $this->assertSame('A document', $serialized['description']);
    $this->assertSame(0, $serialized['display'] ?? NULL, 'Serialized file exposes the display flag.');

    $item = $entity->get('field_attachment')->first()->getValue();
    $this->assertSame('A document', $item['description']);
    $this->assertEquals(0, $item['display'], 'Display flag survives the round trip.');
  }

  /**
   * Image fields round-trip alt and title.
   */
  public function testImageRoundTripPreservesAltTitle(): void {
    $file = $this->createFile('photo.png');
    $entity = $this->createTestEntity([
      'field_image' => [
        'target_id' => $file->id(),
        'alt' => 'A photo',
        'title' => 'Photo title',
      ],
    ]);

    $this->roundTrip($entity, 'field_image');

    $item = $entity->get('field_image')->first()->getValue();
    $this->assertSame('A photo', $item['alt']);
    $this->assertSame('Photo title', $item['title']);
  }

  /**
   * Text_with_summary fields round-trip value, format, and summary.
   */
  public function testTextWithSummaryRoundTrip(): void {
    $entity = $this->createTestEntity([
      'field_body' => [
        'value' => '<p>Body text</p>',
        'summary' => 'Summary text',
        'format' => 'plain_text',
      ],
    ]);

    $this->roundTrip($entity, 'field_body');

    $item = $entity->get('field_body')->first()->getValue();
    $this->assertSame('<p>Body text</p>', $item['value']);
    $this->assertSame('Summary text', $item['summary']);
    $this->assertSame('plain_text', $item['format']);
  }

}
