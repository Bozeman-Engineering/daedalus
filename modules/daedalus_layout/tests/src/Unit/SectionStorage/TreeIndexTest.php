<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\SectionStorage;

use Drupal\Tests\UnitTestCase;
use Drupal\daedalus_layout\TreeIndexInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\daedalus_layout\SectionStorage\TreeIndex;

/**
 * Unit tests for TreeIndex.
 */
#[CoversClass(TreeIndex::class)]
#[Group('daedalus_layout')]
class TreeIndexTest extends UnitTestCase {

  /**
   * The tree index under test.
   */
  protected TreeIndex $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->index = new TreeIndex();
  }

  /**
   * Tests add and get block.
   */
  public function testAddAndGetBlock(): void {
    $uuid = 'test-block-uuid';
    $path = [0, 'parent-uuid', 1, 'test-block-uuid'];
    $metadata = ['region' => 'content', 'weight' => 5];

    $this->index->add($uuid, $path, TreeIndexInterface::TYPE_BLOCK, $metadata);

    $this->assertTrue($this->index->has($uuid));
    $this->assertTrue($this->index->isBlock($uuid));
    $this->assertFalse($this->index->isSection($uuid));
    $this->assertEquals($path, $this->index->getPath($uuid));
    $this->assertEquals(TreeIndexInterface::TYPE_BLOCK, $this->index->getType($uuid));
  }

  /**
   * Tests add and get section.
   */
  public function testAddAndGetSection(): void {
    $uuid = 'test-section-uuid';
    $path = [0, 'parent-uuid', 1];
    $metadata = ['layout_id' => 'layout_onecol'];

    $this->index->add($uuid, $path, TreeIndexInterface::TYPE_SECTION, $metadata);

    $this->assertTrue($this->index->has($uuid));
    $this->assertTrue($this->index->isSection($uuid));
    $this->assertFalse($this->index->isBlock($uuid));
    $this->assertEquals($path, $this->index->getPath($uuid));
    $this->assertEquals(TreeIndexInterface::TYPE_SECTION, $this->index->getType($uuid));
  }

  /**
   * Tests has returns false for missing.
   */
  public function testHasReturnsFalseForMissing(): void {
    $this->assertFalse($this->index->has('non-existent-uuid'));
  }

  /**
   * Tests is block returns false for missing.
   */
  public function testIsBlockReturnsFalseForMissing(): void {
    $this->assertFalse($this->index->isBlock('non-existent-uuid'));
  }

  /**
   * Tests is section returns false for missing.
   */
  public function testIsSectionReturnsFalseForMissing(): void {
    $this->assertFalse($this->index->isSection('non-existent-uuid'));
  }

  /**
   * Tests get path returns null for missing.
   */
  public function testGetPathReturnsNullForMissing(): void {
    $this->assertNull($this->index->getPath('non-existent-uuid'));
  }

  /**
   * Tests get type returns null for missing.
   */
  public function testGetTypeReturnsNullForMissing(): void {
    $this->assertNull($this->index->getType('non-existent-uuid'));
  }

  /**
   * Tests get all and get all of type.
   */
  public function testGetAllAndGetAllOfType(): void {
    $this->index->add('block1', [0, 'block1'], TreeIndexInterface::TYPE_BLOCK, ['region' => 'content']);
    $this->index->add('block2', [0, 'block2'], TreeIndexInterface::TYPE_BLOCK, ['region' => 'sidebar']);
    $this->index->add('section1', [0], TreeIndexInterface::TYPE_SECTION, ['layout_id' => 'layout_onecol']);
    $this->index->add('section2', [1], TreeIndexInterface::TYPE_SECTION, ['layout_id' => 'layout_twocol']);

    // Test getAll returns everything.
    $all = $this->index->getAll();
    $this->assertCount(4, $all);
    $this->assertArrayHasKey('block1', $all);
    $this->assertArrayHasKey('block2', $all);
    $this->assertArrayHasKey('section1', $all);
    $this->assertArrayHasKey('section2', $all);

    // Test getAllOfType for blocks.
    $allBlocks = $this->index->getAllOfType(TreeIndexInterface::TYPE_BLOCK);
    $this->assertCount(2, $allBlocks);
    $this->assertArrayHasKey('block1', $allBlocks);
    $this->assertArrayHasKey('block2', $allBlocks);
    $this->assertEquals([0, 'block1'], $allBlocks['block1']['path']);
    $this->assertEquals('content', $allBlocks['block1']['metadata']['region']);

    // Test getAllOfType for sections.
    $allSections = $this->index->getAllOfType(TreeIndexInterface::TYPE_SECTION);
    $this->assertCount(2, $allSections);
    $this->assertArrayHasKey('section1', $allSections);
    $this->assertArrayHasKey('section2', $allSections);
    $this->assertEquals([0], $allSections['section1']['path']);
    $this->assertEquals('layout_onecol', $allSections['section1']['metadata']['layout_id']);
  }

  /**
   * Test root level block path.
   */
  public function testRootLevelBlockPath(): void {
    // Block at root: section 0, block uuid.
    $path = [0, 'root-block-uuid'];
    $this->index->add('root-block-uuid', $path, TreeIndexInterface::TYPE_BLOCK);

    $this->assertEquals($path, $this->index->getPath('root-block-uuid'));
    $this->assertTrue($this->index->isBlock('root-block-uuid'));
  }

  /**
   * Test deeply nested block path.
   */
  public function testDeeplyNestedBlockPath(): void {
    // Block nested 3 levels deep:
    // root section 0 -> section container A -> nested section 0
    // -> section container B -> nested section 1 -> block C.
    $path = [0, 'layout-a', 0, 'layout-b', 1, 'deep-block'];
    $this->index->add('deep-block', $path, TreeIndexInterface::TYPE_BLOCK);

    $this->assertEquals($path, $this->index->getPath('deep-block'));
    $this->assertTrue($this->index->isBlock('deep-block'));
  }

  /**
   * Test section at nested level.
   */
  public function testNestedSectionPath(): void {
    // Section inside a section container:
    // root section 0 -> section container A -> nested section 1.
    $path = [0, 'layout-a', 1];
    $this->index->add('nested-section', $path, TreeIndexInterface::TYPE_SECTION);

    $this->assertEquals($path, $this->index->getPath('nested-section'));
    $this->assertTrue($this->index->isSection('nested-section'));
  }

  /**
   * Test overwriting existing entry.
   */
  public function testOverwriteExistingItem(): void {
    $uuid = 'test-uuid';
    $path1 = [0, 'test-uuid'];
    $path2 = [1, 'test-uuid'];

    $this->index->add($uuid, $path1, TreeIndexInterface::TYPE_BLOCK);
    $this->assertEquals($path1, $this->index->getPath($uuid));

    $this->index->add($uuid, $path2, TreeIndexInterface::TYPE_BLOCK);
    $this->assertEquals($path2, $this->index->getPath($uuid));
  }

  /**
   * Test empty metadata.
   */
  public function testEmptyMetadata(): void {
    $this->index->add('uuid', [0, 'uuid'], TreeIndexInterface::TYPE_BLOCK);
    $all = $this->index->getAll();

    $this->assertEquals([], $all['uuid']['metadata']);
    $this->assertEquals(TreeIndexInterface::TYPE_BLOCK, $all['uuid']['type']);
  }

  /**
   * Test that type is stored in the index entry.
   */
  public function testTypeStoredInEntry(): void {
    $this->index->add('block-uuid', [0, 'block-uuid'], TreeIndexInterface::TYPE_BLOCK, ['region' => 'content']);
    $this->index->add('section-uuid', [0], TreeIndexInterface::TYPE_SECTION, ['layout_id' => 'layout_onecol']);

    $all = $this->index->getAll();

    $this->assertEquals('block', $all['block-uuid']['type']);
    $this->assertEquals('section', $all['section-uuid']['type']);
  }

}
