<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests writer-minted section identity on add_section at any depth.
 *
 * Batch writers (the stencil expander, AI batches) need to anchor child
 * placements on a section that does not exist until their add operation
 * runs, so add_section accepts a client-minted uuid that becomes the new
 * section's identity, top-level (root entity anchor) and nested (placement
 * 'inside' a section) alike. Follow-up operations in the same batch can
 * anchor on it or place children inside it. Blocks hold no sections: the
 * retired data.section_uuid key on block adds is refused.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSectionUuidTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The daedalus_layout UUID of the root section components are placed into.
   */
  protected string $rootSectionUuid;

  /**
   * The host entity holding the root section.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_layout',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');

    $this->rootSectionUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * A fresh nested-aware section storage for the host entity.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   The storage.
   */
  protected function getStorage(): object {
    return $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->getSectionStorage($this->entity);
  }

  /**
   * Tests the minted uuid names a new top-level section.
   */
  public function testMintedUuidNamesTopLevelSection(): void {
    $section_uuid = (new PhpUuid())->generate();

    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $this->entity->uuid(), 'placement' => 'inside'],
      $section_uuid,
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(2, $sections);
    $this->assertSame($section_uuid, $sections[1]->getThirdPartySetting('daedalus_layout', 'uuid'));

    // The minted identity is immediately addressable.
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $section_uuid, 'placement' => 'after'],
      NULL,
      'layout_twocol_section',
    );
    $this->assertTrue($result->isSuccess());
    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertSame('layout_twocol_section', $sections[2]->getLayoutId());
    $this->assertSame($section_uuid, $sections[1]->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests the minted uuid names a new nested section.
   *
   * Placement 'inside' a section nests: the new section arrives wrapped in
   * a container component whose uuid IS the section uuid (one identity).
   */
  public function testMintedUuidNamesNestedSection(): void {
    $section_uuid = (new PhpUuid())->generate();

    $result = $this->operations->addSection($this->entity, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'inside',
      'region' => 'content',
    ], $section_uuid);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $container = $sections[0]->getComponent($section_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->getPluginId());
    $this->assertSame('content', $container->getRegion());

    $nested = $this->getStorage()->getSectionByUuid($section_uuid);
    $this->assertNotNull($nested);
    $this->assertSame('layout_onecol', $nested->getLayoutId());
    $this->assertSame($section_uuid, $nested->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests data.section_uuid on a block add is refused.
   */
  public function testSectionUuidOnBlockAddRefused(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'section_uuid' => (new PhpUuid())->generate(),
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertSame('NO_LAYOUT', $errors[0]->getCode());
    $this->assertSame('data.section_uuid', $errors[0]->getPath());
    $this->assertStringContainsString('add_section', $errors[0]->getHint());
  }

  /**
   * Tests the stencil shape: one batch builds and fills a nested section.
   *
   * Add_section mints the identity born as its explicit layout, and a
   * child add_element anchors inside it, all in one atomic batch against
   * a section that did not exist before the batch ran.
   */
  public function testBatchAnchorsChildrenOnNamedSection(): void {
    $section_uuid = (new PhpUuid())->generate();
    $child_uuid = (new PhpUuid())->generate();

    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => [
          'anchor' => $this->rootSectionUuid,
          'placement' => 'inside',
          'region' => 'content',
        ],
        'layout' => 'layout_twocol_section',
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $child_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => 'first'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $storage = $this->getStorage();
    $nested = $storage->getSectionByUuid($section_uuid);
    $this->assertNotNull($nested);
    $this->assertSame('layout_twocol_section', $nested->getLayoutId());
    $this->assertSame($section_uuid, $nested->getThirdPartySetting('daedalus_layout', 'uuid'));
    $children = $nested->getComponentsByRegion('first');
    $this->assertCount(1, $children);
    $this->assertSame($child_uuid, reset($children)->getUuid());
  }

}
