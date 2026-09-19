<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\layout_builder\SectionComponent;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Kernel tests for root-entity context resolution inside nested sections.
 *
 * Under the embedded-section model there is no entity boundary inside the
 * tree: the container plugin forwards the root entity into the embedded
 * section's render under core's own ids (layout_builder.entity, entity) AND
 * the stable daedalus_layout.root_entity id. Field blocks therefore keep their
 * default core mapping at every depth — no remap machinery is required for
 * new placements — while legacy mappings against the stable id keep
 * resolving.
 *
 * @see \Drupal\daedalus_layout\Plugin\Block\SectionBlock::forwardedContexts()
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class RootEntityContextTest extends NestedStorageKernelTestBase {

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    // FieldBlock::blockAccess() checks entity view access; without it the
    // nested field block renders nothing regardless of context resolution.
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
  }

  /**
   * Renders an entity's default view to HTML.
   */
  protected function renderEntity(EntityTest $entity): string {
    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Asserts text renders INSIDE a nested section (a layout within a layout).
   *
   * A plain substring assertion would pass vacuously: the entity renders its
   * own name field outside the layout, so the sentinel must be found under
   * two nested layout wrappers to prove the nested field block resolved its
   * context and rendered.
   */
  protected function assertRendersNested(string $html, string $text, string $message): void {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new \DOMXPath($dom);
    $matches = $xpath->query(sprintf(
      '//div[contains(@class,"layout")]//div[contains(@class,"layout")]//text()[contains(., "%s")]',
      $text,
    ));
    $this->assertGreaterThan(0, $matches->length, $message);
  }

  /**
   * Moving a parent field block into a nested section keeps the core id.
   *
   * The moved block must actually RENDER inside the nested section's plain
   * render — the container forwards the root entity under core's id, so no
   * remap happens and none is needed.
   */
  public function testMoveIntoNestedSectionRendersWithCoreId(): void {
    $field_block_uuid = $this->uuid->generate();
    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [], $nested_section_uuid);
    $container = $this->createNestedSectionComponent($nested_section);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [$this->createFieldBlockComponent($field_block_uuid), $container]),
    ]);
    $entity->setName('Root entity name sentinel');

    $result = $this->operations->moveElement($entity, $field_block_uuid, [
      'anchor' => $nested_section_uuid,
      'placement' => 'inside',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $component = $this->getNestedStorage($entity)->getComponent($field_block_uuid);
    $this->assertSame(
      'layout_builder.entity',
      $component->toArray()['configuration']['context_mapping']['entity'],
    );

    // The field block now lives only inside the nested section, so the name
    // appearing INSIDE it proves the forwarded contexts resolved.
    $this->assertRendersNested(
      $this->renderEntity($entity),
      'Root entity name sentinel',
      'The moved field block renders the root name inside the nested section.',
    );
  }

  /**
   * Legacy mappings against the stable id keep resolving at depth.
   *
   * Pre-unification content mapped nested field blocks to
   * daedalus_layout.root_entity; the container forwards the root entity under that
   * id too, so those mappings render unchanged until migration heals them.
   */
  public function testLegacyStableIdStillRendersNested(): void {
    $field_block_uuid = $this->uuid->generate();
    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [
      $this->createFieldBlockComponent($field_block_uuid, NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID),
    ], $nested_section_uuid);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [$this->createNestedSectionComponent($nested_section)]),
    ]);
    $entity->setName('Legacy mapping sentinel');

    $this->assertRendersNested(
      $this->renderEntity($entity),
      'Legacy mapping sentinel',
      'A field block mapped to the stable root-entity id renders inside the nested section.',
    );
  }

  /**
   * Adding a field block into a nested section keeps the default mapping.
   *
   * The add path resolves the default context mapping against root contexts;
   * under the embedded-section model that mapping is correct at any depth.
   */
  public function testAddIntoNestedSectionRendersWithCoreId(): void {
    $nested_section_uuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [], $nested_section_uuid);
    $entity = $this->createTestEntity([
      $this->createSection('layout_onecol', [$this->createNestedSectionComponent($nested_section)]),
    ]);
    $entity->setName('Added block name sentinel');

    $new_uuid = $this->uuid->generate();
    $result = $this->operations->addElement($entity, [
      'anchor' => $nested_section_uuid,
      'placement' => 'inside',
    ], [
      'plugin_id' => 'field_block:entity_test:entity_test:name',
      'uuid' => $new_uuid,
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $component = $this->getNestedStorage($entity)->getComponent($new_uuid);
    $this->assertSame(
      'layout_builder.entity',
      $component->toArray()['configuration']['context_mapping']['entity'],
    );
    $this->assertRendersNested(
      $this->renderEntity($entity),
      'Added block name sentinel',
      'The added field block renders the root name inside the nested section.',
    );
  }

  /**
   * Creates a field block component rendering the root entity's name.
   *
   * @param string $uuid
   *   The component UUID.
   * @param string $entity_context_id
   *   The context id to map the entity slot to.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The field block component.
   */
  protected function createFieldBlockComponent(string $uuid, string $entity_context_id = 'layout_builder.entity') {
    return new SectionComponent($uuid, 'content', [
      'id' => 'field_block:entity_test:entity_test:name',
      'label' => 'Name',
      'label_display' => FALSE,
      'context_mapping' => [
        'entity' => $entity_context_id,
        'view_mode' => 'view_mode',
      ],
    ]);
  }

}
