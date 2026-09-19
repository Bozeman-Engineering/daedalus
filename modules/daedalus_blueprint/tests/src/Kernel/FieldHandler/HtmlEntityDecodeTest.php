<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\FieldHandler;

use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The decode_html_entities contract: AI-path entity-escape defense.
 *
 * LLMs sometimes HTML-escape plain-text tool arguments (observed: &amp;
 * emitted inside function-call JSON, stored verbatim, rendered
 * double-escaped). The decode_html_entities deserialization option — set
 * only by daedalus_blueprint_ai's tools — decodes strings headed for
 * plain-text storage. Three lines the tests pin:
 * - plain-text values decode ONLY when the option is set (a literal &amp;
 *   from a human API consumer survives),
 * - rich-text (formatted) values are NEVER decoded, flag or not — there,
 *   &amp; is legitimate HTML source,
 * - the option threads from the public operations API down to handlers.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class HtmlEntityDecodeTest extends BlueprintKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createField('field_link', 'link');
  }

  /**
   * Deserializes a value through the field's handler.
   */
  protected function handlerDeserialize(object $entity, string $field_name, mixed $value, array $options = []): mixed {
    $definition = $entity->getFieldDefinition($field_name);
    $handler = $this->fieldHandlerManager->getHandler($definition);
    $result = $handler->deserialize($value, $definition, $options);
    $this->assertFalse($result->hasErrors());
    return $result->getValue();
  }

  /**
   * Plain-text strings decode when the option is set.
   */
  public function testPlainTextDecodesWithFlag(): void {
    $entity = $this->createTestEntity();

    $value = $this->handlerDeserialize($entity, 'field_subtitle', 'Tom &amp; Jerry &quot;live&quot;', [
      'decode_html_entities' => TRUE,
    ]);

    $this->assertSame('Tom & Jerry "live"', $value);
  }

  /**
   * Without the option, entities pass through verbatim (the human API path).
   */
  public function testPlainTextVerbatimWithoutFlag(): void {
    $entity = $this->createTestEntity();

    $value = $this->handlerDeserialize($entity, 'field_subtitle', 'Tom &amp; Jerry');

    $this->assertSame('Tom &amp; Jerry', $value);
  }

  /**
   * Rich-text values are never decoded, even with the flag.
   */
  public function testRichTextNeverDecodes(): void {
    $entity = $this->createTestEntity();
    $html = '<p>Fish &amp; chips &lt;tags stay escaped&gt;</p>';

    $value = $this->handlerDeserialize($entity, 'field_description', [
      'value' => $html,
      'format' => 'plain_text',
    ], [
      'decode_html_entities' => TRUE,
    ]);

    $this->assertSame($html, $value['value']);
  }

  /**
   * Compound plain-text fields decode recursively (link title and options).
   */
  public function testLinkDecodesRecursively(): void {
    $entity = $this->createTestEntity();

    $value = $this->handlerDeserialize($entity, 'field_link', [
      'uri' => 'https://example.com',
      'title' => 'Fish &amp; chips',
      'options' => ['attributes' => ['title' => 'A &amp; B']],
    ], [
      'decode_html_entities' => TRUE,
    ]);

    $this->assertSame('Fish & chips', $value['title']);
    $this->assertSame('A & B', $value['options']['attributes']['title']);
  }

  /**
   * The option threads from the public operations API to the handlers.
   */
  public function testOptionThreadsThroughOperations(): void {
    $operations = $this->container->get('daedalus_blueprint.operations');
    $entity = $this->createTestEntity(['field_subtitle' => 'Original']);

    $result = $operations->updateFields($entity, [
      'field_subtitle' => 'Tom &amp; Jerry',
    ], ['decode_html_entities' => TRUE]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('Tom & Jerry', $entity->get('field_subtitle')->value);

    $result = $operations->updateFields($entity, [
      'field_subtitle' => 'Tom &amp; Jerry',
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('Tom &amp; Jerry', $entity->get('field_subtitle')->value);
  }

}
