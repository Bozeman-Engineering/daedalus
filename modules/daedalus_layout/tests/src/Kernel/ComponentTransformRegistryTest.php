<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout_transform_test\TransformLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry;

/**
 * Tests the component transform contract and tagged-service registry.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class ComponentTransformRegistryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (its services are
    // autowired into daedalus_ui's container); kernel tests do not
    // resolve info.yml dependencies, so the chain is listed explicitly.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_layout_transform_test',
  ];

  /**
   * The registry under test.
   */
  protected ComponentTransformRegistry $registry;

  /**
   * A host entity for transforms to read.
   */
  protected EntityTest $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TransformLog::reset();
    $this->registry = $this->container->get('daedalus_layout.component_transform_registry');
    $this->host = EntityTest::create(['name' => 'Host page']);
  }

  /**
   * Transforms chain in priority order within one pass.
   *
   * The legacy transform (priority 10) rewrites legacy to modern; the
   * annotator (priority 0, registered FIRST in services.yml) then matches
   * the rewritten state. This proves priority ordering beats declaration
   * order and that applies() is re-evaluated against current state.
   */
  public function testTransformChainAndPriorityOrder(): void {
    $uuid = '10000000-0000-0000-0000-000000000001';
    $component = new SectionComponent($uuid, 'content', [
      'id' => 'inline_block:legacy',
      'label' => 'A legacy block',
    ]);

    $this->assertTrue($this->registry->transform($component, $this->host));

    $configuration = $component->get('configuration');
    $this->assertSame('inline_block:modern', $configuration['id']);
    $this->assertSame('A legacy block', $configuration['label'], 'Unrelated configuration is preserved.');
    $this->assertSame('Host page', $configuration['migrated_from'], 'The host entity is available to transforms.');
    $this->assertTrue($configuration['annotated']);
    $this->assertSame($uuid, $component->getUuid(), 'The component UUID is preserved.');
    $this->assertSame(["legacy:$uuid", "annotator:$uuid"], TransformLog::$calls);
  }

  /**
   * Only matching transforms run.
   */
  public function testOnlyMatchingTransformsApply(): void {
    $uuid = '20000000-0000-0000-0000-000000000002';
    $component = new SectionComponent($uuid, 'content', [
      'id' => 'inline_block:modern',
    ]);

    $this->assertTrue($this->registry->transform($component, $this->host));
    $this->assertSame(["annotator:$uuid"], TransformLog::$calls, 'The legacy transform never matched a modern component.');
  }

  /**
   * A component no transform matches is left untouched.
   */
  public function testNoMatchLeavesComponentUntouched(): void {
    $configuration = [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
    ];
    $component = new SectionComponent('30000000-0000-0000-0000-000000000003', 'content', $configuration);

    $this->assertFalse($this->registry->transform($component, $this->host));
    $this->assertSame($configuration, $component->get('configuration'));
    $this->assertSame([], TransformLog::$calls);
  }

  /**
   * An empty registry reports no transforms and never dirties anything.
   */
  public function testEmptyRegistry(): void {
    $this->assertTrue($this->registry->hasTransforms(), 'The tagged test transforms were collected.');

    $empty = new ComponentTransformRegistry($this->container, []);
    $this->assertFalse($empty->hasTransforms());
    $component = new SectionComponent('40000000-0000-0000-0000-000000000004', 'content', ['id' => 'inline_block:legacy']);
    $this->assertFalse($empty->transform($component, $this->host));
    $this->assertSame([], TransformLog::$calls);
  }

}
