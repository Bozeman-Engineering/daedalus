<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\SectionStorage;

use Drupal\Component\Plugin\Discovery\CachedDiscoveryInterface;
use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorageManager;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorage\SupportAwareSectionStorageManagerInterface;

/**
 * Unit tests for NestedAwareSectionStorageManager.
 */
#[CoversClass(NestedAwareSectionStorageManager::class)]
#[Group('daedalus_layout')]
class NestedAwareSectionStorageManagerTest extends UnitTestCase {

  use ProphecyTrait;
  use SectionStorageMockTrait;

  /**
   * Mock inner section storage manager.
   *
   * @var \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $innerManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Create a mock that implements both interfaces.
    $this->innerManager = $this->prophesize(SupportAwareSectionStorageManagerInterface::class);
  }

  /**
   * Tests load passes through to inner manager.
   */
  public function testLoadPassesThroughToInnerManager(): void {
    $storage = $this->createMockSectionStorage('test_id');

    $this->innerManager->load('overrides', ['context' => 'value'])
      ->willReturn($storage->reveal())
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $result = $manager->load('overrides', ['context' => 'value']);

    // Verify it passes through without wrapping.
    $this->assertSame($storage->reveal(), $result);
  }

  /**
   * Tests load returns null when inner returns null.
   */
  public function testLoadReturnsNullWhenInnerReturnsNull(): void {
    $this->innerManager->load('overrides', [])
      ->willReturn(NULL)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $result = $manager->load('overrides', []);

    $this->assertNull($result);
  }

  /**
   * Tests load empty passes through to inner manager.
   */
  public function testLoadEmptyPassesThroughToInnerManager(): void {
    $storage = $this->createMockSectionStorage('empty_id');

    $this->innerManager->loadEmpty('overrides')
      ->willReturn($storage->reveal())
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $result = $manager->loadEmpty('overrides');

    // Verify it passes through without wrapping.
    $this->assertSame($storage->reveal(), $result);
  }

  /**
   * Tests find by context passes through to inner manager.
   */
  public function testFindByContextPassesThroughToInnerManager(): void {
    $storage = $this->createMockSectionStorage('context_id');
    $cacheability = $this->prophesize(RefinableCacheableDependencyInterface::class);

    $this->innerManager->findByContext(['context'], $cacheability->reveal())
      ->willReturn($storage->reveal())
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $result = $manager->findByContext(['context'], $cacheability->reveal());

    // Verify it passes through without wrapping.
    $this->assertSame($storage->reveal(), $result);
  }

  /**
   * Tests find by context returns null when inner returns null.
   */
  public function testFindByContextReturnsNullWhenInnerReturnsNull(): void {
    $cacheability = $this->prophesize(RefinableCacheableDependencyInterface::class);

    $this->innerManager->findByContext([], $cacheability->reveal())
      ->willReturn(NULL)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $result = $manager->findByContext([], $cacheability->reveal());

    $this->assertNull($result);
  }

  /**
   * Tests has definition delegates.
   */
  public function testHasDefinitionDelegates(): void {
    $this->innerManager->hasDefinition('overrides')
      ->willReturn(TRUE)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $this->assertTrue($manager->hasDefinition('overrides'));
  }

  /**
   * Tests get definition delegates.
   */
  public function testGetDefinitionDelegates(): void {
    $definition = ['id' => 'overrides', 'label' => 'Overrides'];
    $this->innerManager->getDefinition('overrides', TRUE)
      ->willReturn($definition)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $this->assertEquals($definition, $manager->getDefinition('overrides', TRUE));
  }

  /**
   * Tests get definitions delegates.
   */
  public function testGetDefinitionsDelegates(): void {
    $definitions = [
      'overrides' => ['id' => 'overrides'],
      'defaults' => ['id' => 'defaults'],
    ];
    $this->innerManager->getDefinitions()
      ->willReturn($definitions)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $this->assertEquals($definitions, $manager->getDefinitions());
  }

  /**
   * Tests not supported delegates to support aware manager.
   */
  public function testNotSupportedDelegatesToSupportAwareManager(): void {
    $this->innerManager->notSupported('node', 'article', 'full')
      ->willReturn(FALSE)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $this->innerManager->reveal()
    );

    $this->assertFalse($manager->notSupported('node', 'article', 'full'));
  }

  /**
   * Tests not supported returns false for non support aware manager.
   */
  public function testNotSupportedReturnsFalseForNonSupportAwareManager(): void {
    // Use a mock that does not implement the support-aware manager interface.
    $innerManager = $this->prophesize(SectionStorageManagerInterface::class);

    $manager = new NestedAwareSectionStorageManager(
      $innerManager->reveal()
    );

    // Should return FALSE when inner manager doesn't implement the interface.
    $this->assertFalse($manager->notSupported('node', 'article', 'full'));
  }

  /**
   * Tests clear cached definitions delegates.
   */
  public function testClearCachedDefinitionsDelegates(): void {
    // A mock inner manager that also implements CachedDiscoveryInterface.
    $innerManager = $this->prophesize(SectionStorageManagerInterface::class)
      ->willImplement(CachedDiscoveryInterface::class);

    $innerManager->clearCachedDefinitions()
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $innerManager->reveal()
    );

    $manager->clearCachedDefinitions();
  }

  /**
   * Tests use caches delegates.
   */
  public function testUseCachesDelegates(): void {
    // A mock inner manager that also implements CachedDiscoveryInterface.
    $innerManager = $this->prophesize(SectionStorageManagerInterface::class)
      ->willImplement(CachedDiscoveryInterface::class);

    $innerManager->useCaches(TRUE)
      ->shouldBeCalled();

    $manager = new NestedAwareSectionStorageManager(
      $innerManager->reveal()
    );

    $manager->useCaches(TRUE);
  }

}
