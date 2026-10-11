<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\daedalus_blueprint\FieldHandler\LinkFieldHandler;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the LinkFieldHandler.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(LinkFieldHandler::class)]
#[Group('daedalus_blueprint')]
class LinkFieldHandlerTest extends BlueprintKernelTestBase {

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
  ];

  /**
   * The handler under test.
   *
   * @var \Drupal\daedalus_blueprint\FieldHandler\LinkFieldHandler
   */
  protected LinkFieldHandler $handler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Single-value link field.
    FieldStorageConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'entity_test',
      'type' => 'link',
      'cardinality' => 1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_link',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Link',
    ])->save();

    // Multi-value link field.
    FieldStorageConfig::create([
      'field_name' => 'field_links',
      'entity_type' => 'entity_test',
      'type' => 'link',
      'cardinality' => -1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_links',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Links',
    ])->save();

    $this->handler = new LinkFieldHandler($this->entityTypeManager);
  }

  /**
   * Tests serializing a link with uri and title.
   */
  public function testSerializeLinkWithTitle(): void {
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'https://example.com',
        'title' => 'Example',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_link');
    $result = $this->handler->serialize($entity->get('field_link'), $definition);

    $this->assertSame('https://example.com', $result['uri']);
    $this->assertSame('Example', $result['title']);
  }

  /**
   * Tests serializing a link with uri only omits empty title.
   */
  public function testSerializeLinkWithoutTitle(): void {
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'https://example.com',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_link');
    $result = $this->handler->serialize($entity->get('field_link'), $definition);

    $this->assertSame('https://example.com', $result['uri']);
    $this->assertArrayNotHasKey('title', $result);
  }

  /**
   * Tests serializing empty link returns NULL.
   */
  public function testSerializeEmptyLink(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');
    $result = $this->handler->serialize($entity->get('field_link'), $definition);

    $this->assertNull($result);
  }

  /**
   * Tests serializing multi-value links.
   */
  public function testSerializeMultiValueLinks(): void {
    $entity = $this->createTestEntity([
      'field_links' => [
        ['uri' => 'https://one.com', 'title' => 'One'],
        ['uri' => 'https://two.com', 'title' => 'Two'],
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_links');
    $result = $this->handler->serialize($entity->get('field_links'), $definition);

    $this->assertCount(2, $result);
    $this->assertSame('https://one.com', $result[0]['uri']);
    $this->assertSame('https://two.com', $result[1]['uri']);
  }

  /**
   * Tests _displayable serialization of external and non-web uris.
   */
  public function testSerializeDisplayableExternal(): void {
    $entity = $this->createTestEntity([
      'field_links' => [
        ['uri' => 'https://example.com/path?q=1#frag'],
        ['uri' => 'mailto:someone@example.com'],
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_links');
    $result = $this->handler->serialize($entity->get('field_links'), $definition);

    $this->assertSame('https://example.com/path?q=1#frag', $result[0]['_displayable']);
    $this->assertSame('mailto:someone@example.com', $result[1]['_displayable']);
  }

  /**
   * Tests _displayable serialization of internal: uris as bare paths.
   */
  public function testSerializeDisplayableInternal(): void {
    $cases = [
      'internal:/about' => '/about',
      'internal:?page=2' => '?page=2',
      'internal:#top' => '#top',
      'internal:/' => '<front>',
      'internal:/#foo' => '<front>#foo',
    ];

    $entity = $this->createTestEntity([
      'field_links' => array_map(
        static fn (string $uri): array => ['uri' => $uri],
        array_keys($cases),
      ),
    ]);

    $definition = $entity->getFieldDefinition('field_links');
    $result = $this->handler->serialize($entity->get('field_links'), $definition);

    foreach (array_values($cases) as $index => $expected) {
      $this->assertSame($expected, $result[$index]['_displayable'], $expected);
    }
  }

  /**
   * Tests _displayable serialization of entity: uris as "Label (id)".
   */
  public function testSerializeDisplayableEntityLabel(): void {
    $target = $this->createTestEntity(['name' => 'Target page']);

    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'entity:entity_test/' . $target->id(),
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_link');
    $result = $this->handler->serialize($entity->get('field_link'), $definition);

    $this->assertSame('Target page (' . $target->id() . ')', $result['_displayable']);
  }

  /**
   * Tests unresolvable entity: uris fall back to the raw uri.
   */
  public function testSerializeDisplayableEntityFallback(): void {
    $entity = $this->createTestEntity([
      'field_links' => [
        ['uri' => 'entity:entity_test/9999'],
        ['uri' => 'entity:not_a_type/1'],
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_links');
    $result = $this->handler->serialize($entity->get('field_links'), $definition);

    $this->assertSame('entity:entity_test/9999', $result[0]['_displayable']);
    $this->assertSame('entity:not_a_type/1', $result[1]['_displayable']);
  }

  /**
   * Tests _displayable serialization of route: uris as the nolink family.
   */
  public function testSerializeDisplayableNolink(): void {
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'route:<nolink>',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_link');
    $result = $this->handler->serialize($entity->get('field_link'), $definition);

    $this->assertSame('<nolink>', $result['_displayable']);
  }

  /**
   * Tests deserializing a link object.
   */
  public function testDeserializeLinkObject(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $result = $this->handler->deserialize(
      ['uri' => 'https://example.com', 'title' => 'Example'],
      $definition,
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame('https://example.com', $result->getValue()['uri']);
    $this->assertSame('Example', $result->getValue()['title']);
  }

  /**
   * Tests deserializing a bare string normalizes to {uri}.
   */
  public function testDeserializeBareString(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $result = $this->handler->deserialize('https://example.com', $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame('https://example.com', $result->getValue()['uri']);
  }

  /**
   * Tests schemeless strings normalize to internal: uris.
   */
  public function testDeserializeSchemelessNormalization(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $cases = [
      '/about' => 'internal:/about',
      '?page=2' => 'internal:?page=2',
      '#top' => 'internal:#top',
      'example.com/foo' => 'internal:example.com/foo',
      '<front>' => 'internal:/',
      '<front>#foo' => 'internal:/#foo',
      ' /about ' => 'internal:/about',
    ];

    foreach ($cases as $input => $expected) {
      $result = $this->handler->deserialize($input, $definition);
      $this->assertFalse($result->hasErrors());
      $this->assertSame($expected, $result->getValue()['uri'], "Bare string '$input'");

      $result = $this->handler->deserialize(['uri' => $input], $definition);
      $this->assertFalse($result->hasErrors());
      $this->assertSame($expected, $result->getValue()['uri'], "Object uri '$input'");
    }
  }

  /**
   * Tests the nolink family normalizes to route: uris.
   */
  public function testDeserializeNolinkFamily(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    foreach (['<nolink>', '<none>', '<button>'] as $input) {
      $result = $this->handler->deserialize($input, $definition);
      $this->assertFalse($result->hasErrors());
      $this->assertSame("route:$input", $result->getValue()['uri'], $input);
    }
  }

  /**
   * Tests strings with a scheme pass through unchanged.
   */
  public function testDeserializeSchemedPassthrough(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $uris = [
      'https://example.com/path?q=1#frag',
      'internal:/about',
      'entity:node/42',
      'route:<nolink>',
      'mailto:someone@example.com',
      'tel:+15551234567',
    ];

    foreach ($uris as $uri) {
      $result = $this->handler->deserialize($uri, $definition);
      $this->assertFalse($result->hasErrors());
      $this->assertSame($uri, $result->getValue()['uri'], $uri);
    }
  }

  /**
   * Tests _displayable is stripped from write payloads.
   */
  public function testDeserializeStripsDisplayable(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $result = $this->handler->deserialize([
      'uri' => '/about',
      'title' => 'About',
      '_displayable' => '/about',
    ], $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertArrayNotHasKey('_displayable', $result->getValue());
    $this->assertSame('internal:/about', $result->getValue()['uri']);
    $this->assertSame('About', $result->getValue()['title']);
  }

  /**
   * Tests multi-value payloads normalize each item.
   */
  public function testDeserializeMultiValueNormalization(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_links');

    $result = $this->handler->deserialize([
      '/about',
      ['uri' => '<nolink>', 'title' => 'Nothing', '_displayable' => '<nolink>'],
      ['uri' => 'https://example.com', 'title' => 'Example'],
    ], $definition);

    $this->assertFalse($result->hasErrors());
    $value = $result->getValue();
    $this->assertCount(3, $value);
    $this->assertSame('internal:/about', $value[0]['uri']);
    $this->assertSame('route:<nolink>', $value[1]['uri']);
    $this->assertArrayNotHasKey('_displayable', $value[1]);
    $this->assertSame('Nothing', $value[1]['title']);
    $this->assertSame('https://example.com', $value[2]['uri']);
  }

  /**
   * Tests a single-item list unwraps for single-value fields.
   */
  public function testDeserializeSingleItemListUnwraps(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $result = $this->handler->deserialize([['uri' => '/about']], $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame('internal:/about', $result->getValue()['uri']);
  }

  /**
   * Tests deserializing empty values.
   */
  public function testDeserializeEmpty(): void {
    $entity = $this->createTestEntity();
    $definition = $entity->getFieldDefinition('field_link');

    $this->assertFalse($this->handler->deserialize(NULL, $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize('', $definition)->hasErrors());
    $this->assertFalse($this->handler->deserialize([], $definition)->hasErrors());
  }

  /**
   * Tests round-trip serialization/deserialization.
   */
  public function testRoundTrip(): void {
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'https://example.com',
        'title' => 'My Link',
      ],
    ]);

    $definition = $entity->getFieldDefinition('field_link');
    $serialized = $this->handler->serialize($entity->get('field_link'), $definition);
    $this->assertArrayHasKey('_displayable', $serialized);
    $deserialized = $this->handler->deserialize($serialized, $definition);

    $this->assertFalse($deserialized->hasErrors());
    $this->assertSame('https://example.com', $deserialized->getValue()['uri']);
    $this->assertSame('My Link', $deserialized->getValue()['title']);
    $this->assertArrayNotHasKey('_displayable', $deserialized->getValue());
  }

  /**
   * Tests the serializer's link output round-trips through the deserializer.
   *
   * The whole-entity path: Phase A (the structural validator) admits the
   * read-only "_displayable" enrichment as handler vocabulary and Phase B
   * (this handler) strips it, so a serialized entity with populated single
   * and multi-value link fields deserializes as an update with the stored
   * uris intact and no enrichment written back.
   */
  public function testRoundTripThroughDeserializer(): void {
    $entity = $this->createTestEntity([
      'field_link' => [
        'uri' => 'entity:entity_test/1',
        'title' => 'Self',
      ],
      'field_links' => [
        ['uri' => 'internal:/about', 'title' => 'About'],
        ['uri' => 'route:<nolink>', 'title' => 'Nothing'],
        ['uri' => 'https://example.com', 'options' => ['attributes' => ['target' => '_blank']]],
      ],
    ]);

    $blueprint = $this->container->get('daedalus_blueprint.serializer')->serialize($this->host($entity));
    $this->assertArrayHasKey('_displayable', $blueprint['fields']['field_link']);
    $this->assertArrayHasKey('_displayable', $blueprint['fields']['field_links'][0]);

    $result = $this->container->get('daedalus_blueprint.deserializer')->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), 'Round trip failed: ' . print_r($result->getErrors(), TRUE));

    $updated = $result->getEntity();
    $this->assertSame((int) $entity->id(), (int) $updated->id());
    $this->assertSame('entity:entity_test/1', $updated->get('field_link')->uri);
    $this->assertSame('Self', $updated->get('field_link')->title);
    $this->assertArrayNotHasKey('_displayable', $updated->get('field_link')->getValue());

    $links = array_map(static fn ($item) => $item->getValue(), iterator_to_array($updated->get('field_links')));
    $this->assertCount(3, $links);
    $this->assertSame('internal:/about', $links[0]['uri']);
    $this->assertSame('route:<nolink>', $links[1]['uri']);
    $this->assertSame('https://example.com', $links[2]['uri']);
    $this->assertSame(['attributes' => ['target' => '_blank']], $links[2]['options']);
    foreach ($links as $link) {
      $this->assertArrayNotHasKey('_displayable', $link);
    }
  }

}
