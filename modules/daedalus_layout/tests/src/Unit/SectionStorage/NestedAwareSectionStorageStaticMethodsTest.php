<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\SectionStorage;

use Drupal\Tests\UnitTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Core\Block\BlockPluginInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\daedalus_layout\SectionStorage\NestedAwareDefaultsSectionStorage;
use Drupal\daedalus_layout\SectionStorage\NestedAwareOverridesSectionStorage;

/**
 * Unit tests for static methods on NestedAwareSectionStorage.
 *
 * Methods requiring serializable entities or the Drupal container
 * (extractBlockContent with revision IDs, getSectionStorageForEntity) are
 * tested in kernel tests.
 */
#[CoversClass(NestedAwareSectionStorage::class)]
#[CoversClass(NestedAwareOverridesSectionStorage::class)]
#[CoversClass(NestedAwareDefaultsSectionStorage::class)]
#[Group('daedalus_layout')]
class NestedAwareSectionStorageStaticMethodsTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
  }

  /**
   * Tests wrap() is idempotent and always returns a nested-aware storage.
   */
  public function testWrapIsIdempotent(): void {
    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('test_id');

    // Wrapping an unwrapped storage returns a NestedSectionStorageInterface.
    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $wrapped);

    // Wrapping again returns the same instance.
    $doubleWrapped = NestedAwareSectionStorage::wrap($wrapped);
    $this->assertSame($wrapped, $doubleWrapped);

    // Triple wrap also returns the same instance.
    $tripleWrapped = NestedAwareSectionStorage::wrap($doubleWrapped);
    $this->assertSame($wrapped, $tripleWrapped);
  }

  /**
   * Tests wrap() returns NestedAwareOverridesSectionStorage for overrides root.
   */
  public function testWrapOverridesStorage(): void {
    $storage = $this->prophesize(OverridesSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('overrides_id');
    $storage->getDefaultSectionStorage()->willReturn(NULL);
    $storage->isOverridden()->willReturn(FALSE);

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());

    $this->assertInstanceOf(NestedAwareOverridesSectionStorage::class, $wrapped);
    $this->assertInstanceOf(OverridesSectionStorageInterface::class, $wrapped);
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $wrapped);
    $this->assertInstanceOf(NestedAwareSectionStorage::class, $wrapped);
  }

  /**
   * Tests wrap() returns NestedAwareDefaultsSectionStorage for defaults root.
   */
  public function testWrapDefaultsStorage(): void {
    $storage = $this->prophesize(DefaultsSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('defaults_id');
    $storage->isLayoutBuilderEnabled()->willReturn(TRUE);

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());

    $this->assertInstanceOf(NestedAwareDefaultsSectionStorage::class, $wrapped);
    $this->assertInstanceOf(DefaultsSectionStorageInterface::class, $wrapped);
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $wrapped);
    $this->assertInstanceOf(NestedAwareSectionStorage::class, $wrapped);
  }

  /**
   * Tests wrap() returns base class for plain SectionStorageInterface.
   */
  public function testWrapPlainStorage(): void {
    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('plain_id');

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());

    $this->assertInstanceOf(NestedAwareSectionStorage::class, $wrapped);
    $this->assertNotInstanceOf(NestedAwareOverridesSectionStorage::class, $wrapped);
    $this->assertNotInstanceOf(NestedAwareDefaultsSectionStorage::class, $wrapped);
  }

  /**
   * Tests wrap() idempotency for overrides subclass.
   */
  public function testWrapOverridesIdempotent(): void {
    $storage = $this->prophesize(OverridesSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('overrides_id');

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());
    $doubleWrapped = NestedAwareSectionStorage::wrap($wrapped);

    $this->assertSame($wrapped, $doubleWrapped);
  }

  /**
   * Tests wrap() idempotency for defaults subclass.
   */
  public function testWrapDefaultsIdempotent(): void {
    $storage = $this->prophesize(DefaultsSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('defaults_id');

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());
    $doubleWrapped = NestedAwareSectionStorage::wrap($wrapped);

    $this->assertSame($wrapped, $doubleWrapped);
  }

  /**
   * Tests overrides delegation methods forward to root storage.
   */
  public function testOverridesDelegation(): void {
    $defaults_storage = $this->prophesize(DefaultsSectionStorageInterface::class)->reveal();

    $storage = $this->prophesize(OverridesSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('overrides_id');
    $storage->getDefaultSectionStorage()->willReturn($defaults_storage);
    $storage->isOverridden()->willReturn(TRUE);
    $storage->isSupported('node', 'page', 'full')->willReturn(TRUE);

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());

    $this->assertSame($defaults_storage, $wrapped->getDefaultSectionStorage());
    $this->assertTrue($wrapped->isOverridden());
    $this->assertTrue($wrapped->isSupported('node', 'page', 'full'));
  }

  /**
   * Tests defaults delegation methods forward to root storage.
   */
  public function testDefaultsDelegation(): void {
    $storage = $this->prophesize(DefaultsSectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('defaults_id');
    $storage->isLayoutBuilderEnabled()->willReturn(TRUE);
    $storage->isOverridable()->willReturn(FALSE);
    $storage->getThirdPartySetting('test_module', 'test_key', NULL)->willReturn('test_value');
    $storage->isSupported('node', 'page', 'full')->willReturn(TRUE);

    $wrapped = NestedAwareSectionStorage::wrap($storage->reveal());

    $this->assertTrue($wrapped->isLayoutBuilderEnabled());
    $this->assertFalse($wrapped->isOverridable());
    $this->assertEquals('test_value', $wrapped->getThirdPartySetting('test_module', 'test_key'));
    $this->assertTrue($wrapped->isSupported('node', 'page', 'full'));
  }

  /**
   * Tests extractBlockContent() with non-InlineBlock returns null.
   */
  public function testExtractBlockContentWithNonInlineBlockReturnsNull(): void {
    $plugin = $this->prophesize(BlockPluginInterface::class);

    $result = NestedAwareSectionStorage::extractBlockContent($plugin->reveal());

    $this->assertNull($result);
  }

  /**
   * Tests extractBlockContent() with empty configuration returns null.
   */
  public function testExtractBlockContentWithEmptyConfigReturnsNull(): void {
    $plugin = $this->prophesize(InlineBlock::class);
    $plugin->getConfiguration()->willReturn([]);

    $result = NestedAwareSectionStorage::extractBlockContent($plugin->reveal());

    $this->assertNull($result);
  }

}
