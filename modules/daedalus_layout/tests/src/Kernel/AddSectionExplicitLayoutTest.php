<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Kernel tests for add_section's explicit birth layout at every depth.
 *
 * Change_section_layout is retired: a section is born with its real layout
 * and keeps it for life. The explicit "layout" (+ "layout_settings") on
 * add_section is the birth-layout door — the blueprint expansion and any
 * named-slot layout use it — so these tests cover the happy paths that
 * need daedalus_layout section UUIDs: root placement, nesting, deep nesting, the
 * batch wire grammar, and pinned layout settings.
 *
 * @see \Drupal\daedalus_blueprint\BlueprintOperations::addSection()
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class AddSectionExplicitLayoutTest extends NestedStorageKernelTestBase {

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
    // The batch door judges update access on the entity_test host before
    // the op runs (a direct addSection() call passes no door).
    $this->setUpCurrentUser([], ['configure any layout', 'administer entity_test content']);
  }

  /**
   * Tests a top-level section born as an explicit layout.
   */
  public function testExplicitLayoutAtRootLevel(): void {
    $anchor_uuid = $this->uuid->generate();
    $entity = $this->createTestEntity([$this->createSection('layout_onecol', [], $anchor_uuid)]);

    $section_uuid = $this->uuid->generate();
    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $anchor_uuid, 'placement' => 'after'],
      $section_uuid,
      'layout_twocol_section',
      ['column_widths' => '33-67'],
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity->save();
    $reloaded = $this->reloadStorage($entity)->getSectionByUuid($section_uuid);
    $this->assertNotNull($reloaded);
    $this->assertEquals('layout_twocol_section', $reloaded->getLayoutId());
    // The provided settings patched over the plugin defaults.
    $this->assertEquals('33-67', $reloaded->getLayoutSettings()['column_widths']);
  }

  /**
   * Tests a nested section born as an explicit layout.
   *
   * Placement 'inside' wraps the newborn in a container component whose
   * uuid IS the section uuid (one identity); the embedded section carries
   * the explicit layout from birth.
   */
  public function testExplicitLayoutNested(): void {
    $anchor_uuid = $this->uuid->generate();
    $entity = $this->createTestEntity([$this->createSection('layout_onecol', [], $anchor_uuid)]);

    $section_uuid = $this->uuid->generate();
    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $anchor_uuid, 'placement' => 'inside'],
      $section_uuid,
      'layout_twocol_section',
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity->save();
    $reloaded = $this->reloadStorage($entity)->getSectionByUuid($section_uuid);
    $this->assertNotNull($reloaded);
    $this->assertEquals('layout_twocol_section', $reloaded->getLayoutId());
    $this->assertEquals($section_uuid, $reloaded->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests a deeply nested section born as an explicit layout.
   */
  public function testExplicitLayoutDeeplyNested(): void {
    $deep_uuid = $this->uuid->generate();
    $deep_section = $this->createSection('layout_onecol', [], $deep_uuid);
    $nested_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($deep_section),
    ]);
    $root_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested_section),
    ]);
    $entity = $this->createTestEntity([$root_section]);

    $section_uuid = $this->uuid->generate();
    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $deep_uuid, 'placement' => 'inside'],
      $section_uuid,
      'layout_twocol_section',
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity->save();
    $reloaded = $this->reloadStorage($entity)->getSectionByUuid($section_uuid);
    $this->assertNotNull($reloaded);
    $this->assertEquals('layout_twocol_section', $reloaded->getLayoutId());
  }

  /**
   * Tests the op through the batch vocabulary (the endpoint's wire grammar).
   */
  public function testExplicitLayoutViaBatch(): void {
    $anchor_uuid = $this->uuid->generate();
    $entity = $this->createTestEntity([$this->createSection('layout_onecol', [], $anchor_uuid)]);

    $section_uuid = $this->uuid->generate();
    $child_uuid = $this->uuid->generate();
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => ['anchor' => $anchor_uuid, 'placement' => 'inside'],
        'layout' => 'layout_twocol_section',
        'layout_settings' => ['column_widths' => '33-67'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $child_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => 'first'],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entity->save();
    $reloaded = $this->reloadStorage($entity)->getSectionByUuid($section_uuid);
    $this->assertEquals('layout_twocol_section', $reloaded->getLayoutId());
    $this->assertEquals('33-67', $reloaded->getLayoutSettings()['column_widths']);
    $children = $reloaded->getComponentsByRegion('first');
    $this->assertCount(1, $children);
    $this->assertEquals($child_uuid, reset($children)->getUuid());
  }

  /**
   * Tests an unknown explicit layout leaves the entity untouched.
   */
  public function testUnknownExplicitLayoutRejected(): void {
    $anchor_uuid = $this->uuid->generate();
    $entity = $this->createTestEntity([$this->createSection('layout_onecol', [], $anchor_uuid)]);

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $anchor_uuid, 'placement' => 'after'],
      NULL,
      'layout_bogus',
    );

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_LAYOUT', $result->getErrors()[0]->getCode());
    $this->assertCount(1, $entity->get('layout_builder__layout')->getSections());
  }

}
