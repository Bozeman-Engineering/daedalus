<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Access;

use Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the write path enforces field-edit access on nested content.
 *
 * CP2a: deserialization must honor per-field edit access where core's widgets
 * do, on content the root field-access check never reaches — inline entities
 * (paragraphs via entity_reference_revisions) and inline blocks inside a
 * layout. Owned children get field-level checks only; there is no independent
 * entity-level access on them.
 *
 * (The layout_builder__layout field itself is deliberately NOT gated: core
 * hardcodes its edit access to forbidden for everyone, so per-entity layout
 * access is carried by the entity 'update' check, not by that field.)
 *
 * The base test class runs as an admin (access-transparent). Each test here
 * switches to a restricted user and uses entity_test's
 * `views_field_access_test-field` state hook to forbid a single named field
 * for users lacking 'view test entity field'.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class BlueprintWriteAccessTest extends BlueprintKernelTestBase {

  /**
   * The layout deserializer.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer
   */
  protected LayoutDeserializer $layoutDeserializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Layout Builder with an inline block type carrying a restrictable field.
    // entity_reference_revisions is enabled per-method (it is optional and
    // absent in some builds), so it must not gate the whole class here.
    $this->enableLayoutBuilder();
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_heading', 'string', 'card', ['max_length' => 255]);

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
  }

  /**
   * Sets up the inline (owned) paragraph fixtures, skipping if ERR is absent.
   *
   * @return \Drupal\daedalus_blueprint\FieldHandler\InlineEntityFieldHandler
   *   The inline entity field handler, with services refreshed after the
   *   entity_reference_revisions module is enabled.
   */
  protected function setUpInlineParagraphs(): InlineEntityFieldHandler {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev', ['max_length' => 255]);
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
      ['entity_test_rev'],
    );

    // Refresh service references after enabling the module.
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    return $this->container->get('daedalus_blueprint.field_handler.inline_entity');
  }

  /**
   * A field on a paragraph the user cannot edit blocks the write.
   */
  public function testInlineEntityFieldEditDenied(): void {
    $handler = $this->setUpInlineParagraphs();
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    // A user who can write entity_test content but lacks the field permission.
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Should be blocked'],
      ],
    ];

    $result = $handler->deserialize($value, $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame(
      'fields.field_paragraphs[0].fields.field_heading',
      $errors[0]->getPath(),
    );
  }

  /**
   * The same paragraph field applies for a user who can edit it.
   */
  public function testInlineEntityFieldEditAllowed(): void {
    $handler = $this->setUpInlineParagraphs();
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    // A user holding the field permission.
    $this->setUpCurrentUser([], ['view test entity field']);

    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host'])
      ->getFieldDefinition('field_paragraphs');

    $value = [
      [
        'type' => 'entity_test_rev',
        'fields' => ['field_heading' => 'Allowed'],
      ],
    ];

    $result = $handler->deserialize($value, $definition);

    $this->assertFalse($result->hasErrors());
    $entity = $result->getValue()[0]['entity'];
    $this->assertSame('Allowed', $entity->get('field_heading')->value);
  }

  /**
   * A field on an inline block the user cannot edit blocks the layout write.
   */
  public function testInlineBlockFieldEditDenied(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    $this->setUpCurrentUser([], ['administer entity_test content']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $result = $this->layoutDeserializer->deserialize($this->cardLayout('Should be blocked'), $entity);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame(
      'layout_builder__layout[0].regions.content[0].block_fields.field_heading',
      $errors[0]->getPath(),
    );
  }

  /**
   * The same inline block field applies for a user who can edit it.
   */
  public function testInlineBlockFieldEditAllowed(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_heading');

    $this->setUpCurrentUser([], ['view test entity field']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $result = $this->layoutDeserializer->deserialize($this->cardLayout('Allowed'), $entity);

    $this->assertFalse($result->hasErrors());
    $sections = $entity->get('layout_builder__layout')->getSections();
    $block = $this->unserializeBlock($sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration')['block_serialized']);
    $this->assertSame('Allowed', $block->get('field_heading')->value);
  }

  /**
   * A restricted root field blocks the surgical updateFields write.
   *
   * Exercises BlueprintOperations::validateAndApplyFields — the single
   * chokepoint for all surgical write ops (updateElement,
   * updateFields, addElement, batch). CP2a covered the deserialize/inline/
   * layout paths; this covers the surgical-operations path.
   */
  public function testSurgicalUpdateFieldsEditDenied(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    // A user who can write entity_test content but lacks the field permission.
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $result = $operations->updateFields($entity, ['field_subtitle' => 'Blocked']);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_FIELD', $errors[0]->getCode());
    $this->assertSame('fields.field_subtitle', $errors[0]->getPath());
  }

  /**
   * The same surgical updateFields write applies for a permitted user.
   */
  public function testSurgicalUpdateFieldsEditAllowed(): void {
    \Drupal::state()->set('views_field_access_test-field', 'field_subtitle');

    // A user holding the field permission.
    $this->setUpCurrentUser([], ['view test entity field']);

    $entity = $this->entityTypeManager->getStorage('entity_test')
      ->create(['name' => 'host']);

    $operations = $this->container->get('daedalus_blueprint.operations');
    $result = $operations->updateFields($entity, ['field_subtitle' => 'Allowed']);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('Allowed', $entity->get('field_subtitle')->value);
  }

  /**
   * Builds a one-section layout with a single 'card' inline block.
   *
   * @param string $heading
   *   The field_heading value for the card.
   *
   * @return array
   *   The layout sections array for LayoutDeserializer::deserialize().
   */
  protected function cardLayout(string $heading): array {
    return [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'card',
              'label' => 'Card',
              'view_mode' => 'full',
              'block_fields' => ['field_heading' => $heading],
            ],
          ],
        ],
      ],
    ];
  }

}
