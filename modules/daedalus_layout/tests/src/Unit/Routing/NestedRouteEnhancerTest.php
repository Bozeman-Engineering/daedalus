<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\Routing;

use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\daedalus_layout\Routing\NestedRouteEnhancer;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Unit tests for NestedRouteEnhancer.
 */
#[CoversClass(NestedRouteEnhancer::class)]
#[Group('daedalus_layout')]
class NestedRouteEnhancerTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The route enhancer under test.
   *
   * @var \Drupal\daedalus_layout\Routing\NestedRouteEnhancer
   */
  protected $enhancer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enhancer = new NestedRouteEnhancer();
  }

  /**
   * Tests that a block route without section storage keeps its defaults.
   */
  public function testEnhanceReturnsDefaultsWhenNoSectionStorage(): void {
    $defaults = ['uuid' => 'some-uuid'];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance returns defaults when no uuid and no section uuid.
   */
  public function testEnhanceReturnsDefaultsWhenNoUuidAndNoSectionUuid(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    $defaults = ['section_storage' => $storage->reveal()];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance returns defaults when not nested section storage.
   */
  public function testEnhanceReturnsDefaultsWhenNotNestedSectionStorage(): void {
    $storage = $this->prophesize(SectionStorageInterface::class);
    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'some-uuid',
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance returns defaults when context not found.
   */
  public function testEnhanceReturnsDefaultsWhenContextNotFound(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // getNestedContext returns NULL for unknown UUIDs.
    $storage->getNestedContext('non-existent-uuid')->willReturn(NULL);

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'non-existent-uuid',
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance returns defaults for root level block.
   */
  public function testEnhanceReturnsDefaultsForRootLevelBlock(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Root level block: getNestedContext returns NULL (not nested).
    $storage->getNestedContext('root-block-uuid')->willReturn(NULL);

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'root-block-uuid',
      'delta' => 0,
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    // Should return defaults unchanged for root level.
    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance transforms nested block.
   */
  public function testEnhanceTransformsNestedBlock(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Nested block: getNestedContext returns the parent section scope and
    // local delta.
    $storage->getNestedContext('nested-block-uuid')->willReturn([
      'sectionUuid' => 'parent-section-uuid',
      'localDelta' => 1,
    ]);
    $storage->forSection('parent-section-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'nested-block-uuid',
      'delta' => 0,
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    // Section storage should be scoped.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
    // Delta should be transformed to local coordinates.
    $this->assertEquals(1, $result['delta']);
  }

  /**
   * Tests enhance transforms deeply nested block.
   */
  public function testEnhanceTransformsDeeplyNestedBlock(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Deeply nested block: immediate parent is layout-2-uuid, local delta is 2.
    $storage->getNestedContext('deep-block-uuid')->willReturn([
      'sectionUuid' => 'parent-section-2-uuid',
      'localDelta' => 2,
    ]);
    $storage->forSection('parent-section-2-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'deep-block-uuid',
      'delta' => 0,
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    // Section storage should be scoped to the immediate parent section.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
    // Delta should be 2 (from context).
    $this->assertEquals(2, $result['delta']);
  }

  /**
   * Tests enhance without delta parameter.
   */
  public function testEnhanceWithoutDeltaParameter(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    $storage->getNestedContext('nested-block-uuid')->willReturn([
      'sectionUuid' => 'parent-section-uuid',
      'localDelta' => 1,
    ]);
    $storage->forSection('parent-section-uuid')->willReturn($scopedStorage->reveal());

    // No delta in defaults.
    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'nested-block-uuid',
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    // Section storage should still be scoped.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
    // Delta should not be set since it wasn't in defaults.
    $this->assertArrayNotHasKey('delta', $result);
  }

  /**
   * Tests enhance preserves other defaults.
   */
  public function testEnhancePreservesOtherDefaults(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    $storage->getNestedContext('nested-block-uuid')->willReturn([
      'sectionUuid' => 'parent-section-uuid',
      'localDelta' => 1,
    ]);
    $storage->forSection('parent-section-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'nested-block-uuid',
      'delta' => 0,
      'region' => 'content',
      'custom_param' => 'custom_value',
    ];
    $request = new Request();

    $result = $this->enhancer->enhance($defaults, $request);

    // Other parameters should be preserved.
    $this->assertEquals('nested-block-uuid', $result['uuid']);
    $this->assertEquals('content', $result['region']);
    $this->assertEquals('custom_value', $result['custom_param']);
  }

  /**
   * Tests that a root section route (section_uuid query) keeps its delta.
   */
  public function testEnhanceTransformsRootSectionRoute(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Root section: getNestedContext returns NULL (not nested).
    $storage->getNestedContext('section-uuid-1')->willReturn(NULL);
    // Fallback to getPath for root delta.
    $storage->getPath('section-uuid-1')->willReturn([0]);

    $defaults = [
      'section_storage' => $storage->reveal(),
      'delta' => 99,
    ];
    // section_uuid comes from query parameter.
    $request = new Request(['section_uuid' => 'section-uuid-1']);

    $result = $this->enhancer->enhance($defaults, $request);

    // Delta should be updated from path (0), not left at 99.
    $this->assertEquals(0, $result['delta']);
    // Storage should NOT be scoped (root level).
    $this->assertSame($storage->reveal(), $result['section_storage']);
  }

  /**
   * Tests enhance transforms nested section route.
   */
  public function testEnhanceTransformsNestedSectionRoute(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Nested section: getNestedContext returns parent and local delta.
    $storage->getNestedContext('nested-section-uuid')->willReturn([
      'sectionUuid' => 'parent-section-uuid',
      'localDelta' => 1,
    ]);
    $storage->forSection('parent-section-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'delta' => 99,
    ];
    $request = new Request(['section_uuid' => 'nested-section-uuid']);

    $result = $this->enhancer->enhance($defaults, $request);

    // Delta should be the local delta from context (1).
    $this->assertEquals(1, $result['delta']);
    // Storage should be scoped.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
  }

  /**
   * Tests enhance transforms deeply nested section route.
   */
  public function testEnhanceTransformsDeeplyNestedSectionRoute(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Deeply nested section: the immediate parent is layout-2-uuid and the
    // local delta is 2.
    $storage->getNestedContext('deep-section-uuid')->willReturn([
      'sectionUuid' => 'parent-section-2-uuid',
      'localDelta' => 2,
    ]);
    $storage->forSection('parent-section-2-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'delta' => 99,
    ];
    $request = new Request(['section_uuid' => 'deep-section-uuid']);

    $result = $this->enhancer->enhance($defaults, $request);

    // Delta should be the local delta from context (2).
    $this->assertEquals(2, $result['delta']);
    // Storage should be scoped to immediate parent.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
  }

  /**
   * Tests enhance ignores section uuid when no delta.
   */
  public function testEnhanceIgnoresSectionUuidWhenNoDelta(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);

    // No delta in defaults - section_uuid query param should be ignored.
    $defaults = [
      'section_storage' => $storage->reveal(),
    ];
    $request = new Request(['section_uuid' => 'some-uuid']);

    $result = $this->enhancer->enhance($defaults, $request);

    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance section route path not found.
   */
  public function testEnhanceSectionRoutePathNotFound(): void {
    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // getNestedContext returns NULL (not found or root).
    $storage->getNestedContext('nonexistent-uuid')->willReturn(NULL);
    // getPath also returns NULL (truly not found).
    $storage->getPath('nonexistent-uuid')->willReturn(NULL);

    $defaults = [
      'section_storage' => $storage->reveal(),
      'delta' => 0,
    ];
    $request = new Request(['section_uuid' => 'nonexistent-uuid']);

    $result = $this->enhancer->enhance($defaults, $request);

    // Defaults should be unchanged when path not found.
    $this->assertSame($defaults, $result);
  }

  /**
   * Tests enhance block route has priority over section uuid.
   */
  public function testEnhanceBlockRouteHasPriorityOverSectionUuid(): void {
    $scopedStorage = $this->prophesize(NestedSectionStorageInterface::class);

    $storage = $this->prophesize(NestedSectionStorageInterface::class);
    // Block route: {uuid} is present in defaults — uses getNestedContext.
    $storage->getNestedContext('block-uuid')->willReturn([
      'sectionUuid' => 'parent-section-uuid',
      'localDelta' => 1,
    ]);
    $storage->forSection('parent-section-uuid')->willReturn($scopedStorage->reveal());

    $defaults = [
      'section_storage' => $storage->reveal(),
      'uuid' => 'block-uuid',
      'delta' => 0,
    ];
    // Also has section_uuid query param (shouldn't be used).
    $request = new Request(['section_uuid' => 'section-uuid']);

    $result = $this->enhancer->enhance($defaults, $request);

    // Block route processing should take precedence.
    $this->assertSame($scopedStorage->reveal(), $result['section_storage']);
    $this->assertEquals(1, $result['delta']);
  }

}
