<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\SectionStorage;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\daedalus_layout\TreeIndexInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Unit tests for NestedAwareSectionStorage over embedded-section containers.
 *
 * Under the sections-all-the-way-down model the whole tree is plain values
 * (Sections and SectionComponents), so these tests run against REAL section
 * objects with only the root storage mocked.
 */
#[CoversClass(NestedAwareSectionStorage::class)]
#[CoversClass(EmbeddedSectionList::class)]
#[Group('daedalus_layout')]
class NestedAwareSectionStorageTest extends UnitTestCase {

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

    // Identity stamping mints uuids through the container, and
    // Section::toArray() resolves its layout plugin for settings.
    $container = new ContainerBuilder();
    $container->set('uuid', new PhpUuid());
    $container->set('plugin.manager.core.layout', new class {

      /**
       * Creates a stand-in layout plugin that only carries its configuration.
       */
      public function createInstance($id, array $configuration = []) {
        return new class($configuration) {

          public function __construct(protected array $configuration) {}

          /**
           * Gets the layout configuration.
           */
          public function getConfiguration(): array {
            return $this->configuration;
          }

        };
      }

    });
    \Drupal::setContainer($container);
  }

  /**
   * Creates a real Section carrying an daedalus_layout uuid.
   */
  protected function realSection(string $uuid, array $components = [], string $layout_id = 'layout_onecol'): Section {
    $section = new Section($layout_id, [], $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    return $section;
  }

  /**
   * Creates a real plain block component.
   */
  protected function realBlock(string $uuid, string $region = 'content', int $weight = 0): SectionComponent {
    $component = new SectionComponent($uuid, $region, ['id' => 'system_powered_by_block']);
    $component->setWeight($weight);
    return $component;
  }

  /**
   * Creates a mocked root storage over real sections.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The revealed mock.
   */
  protected function mockRootStorage(array $sections, string $storage_id = 'test_id'): SectionStorageInterface {
    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getStorageId()->willReturn($storage_id);
    $storage->getSections()->willReturn($sections);
    foreach ($sections as $delta => $section) {
      $storage->getSection($delta)->willReturn($section);
    }
    return $storage->reveal();
  }

  /**
   * Builds root > nested > deep with one block per level.
   *
   * @return array{storage: NestedAwareSectionStorage, sections: Section[]}
   *   The wrapper and the real section objects by name.
   */
  protected function buildDepthTwoTree(): array {
    $deep_block = $this->realBlock('deep-block');
    $deep = $this->realSection('deep-section', [$deep_block]);

    $nested_block = $this->realBlock('nested-block');
    $nested = $this->realSection('nested-section', [
      $nested_block,
      SectionBlock::createComponent($deep, 'content', 1),
    ]);

    $root_block = $this->realBlock('root-block');
    $root = $this->realSection('root-section', [
      $root_block,
      SectionBlock::createComponent($nested, 'content', 1),
    ]);

    $storage = new NestedAwareSectionStorage(
      $this->mockRootStorage([$root]),
      $this->entityTypeManager->reveal(),
    );

    return ['storage' => $storage, 'root' => $root, 'nested' => $nested, 'deep' => $deep];
  }

  /**
   * Tests constructor unwraps nested storage.
   */
  public function testConstructorUnwrapsNestedStorage(): void {
    $rootStorage = $this->mockRootStorage([]);

    $wrapped = new NestedAwareSectionStorage($rootStorage, $this->entityTypeManager->reveal());
    $doubleWrapped = new NestedAwareSectionStorage($wrapped, $this->entityTypeManager->reveal());

    $this->assertSame($rootStorage, $wrapped->getRootStorage());
    $this->assertSame($rootStorage, $doubleWrapped->getRootStorage());
  }

  /**
   * Tests tree index builds correctly.
   */
  public function testTreeIndexBuildsCorrectly(): void {
    $section = $this->realSection('section-uuid-1', [$this->realBlock('block-uuid-1')]);
    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([$section]),
      $this->entityTypeManager->reveal(),
    );

    $index = $nested->getTreeIndex();
    $this->assertNotNull($index);

    $this->assertEquals([0, 'block-uuid-1'], $nested->getPath('block-uuid-1'));
    $this->assertTrue($index->isBlock('block-uuid-1'));

    $this->assertEquals([0], $nested->getPath('section-uuid-1'));
    $this->assertTrue($index->isSection('section-uuid-1'));
  }

  /**
   * Tests tree index recurses embedded sections.
   */
  public function testTreeIndexRecursesEmbeddedSections(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();
    $index = $storage->getTreeIndex();

    // One identity per nested section: indexed as a SECTION, never a block.
    $this->assertTrue($index->isSection('nested-section'));
    $this->assertFalse($index->isBlock('nested-section'));
    $this->assertTrue($index->isSection('deep-section'));

    // Paths: [delta, containerUuid, 0, ...] all the way down.
    $this->assertEquals([0], $index->getPath('root-section'));
    $this->assertEquals([0, 'nested-section', 0], $index->getPath('nested-section'));
    $this->assertEquals([0, 'nested-section', 0, 'nested-block'], $index->getPath('nested-block'));
    $this->assertEquals([0, 'nested-section', 0, 'deep-section', 0], $index->getPath('deep-section'));
    $this->assertEquals([0, 'nested-section', 0, 'deep-section', 0, 'deep-block'], $index->getPath('deep-block'));
  }

  /**
   * Tests get component returns null for missing uuid.
   */
  public function testGetComponentReturnsNullForMissingUuid(): void {
    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([]),
      $this->entityTypeManager->reveal(),
    );
    $this->assertNull($nested->getComponent('non-existent-uuid'));
  }

  /**
   * Tests get component resolves nested blocks.
   */
  public function testGetComponentResolvesNestedBlocks(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    $this->assertSame('deep-block', $storage->getComponent('deep-block')->getUuid());
    // Container components are sections, not blocks.
    $this->assertNull($storage->getComponent('nested-section'));
  }

  /**
   * Tests get container component.
   */
  public function testGetContainerComponent(): void {
    ['storage' => $storage, 'root' => $root] = $this->buildDepthTwoTree();

    $container = $storage->getContainerComponent('nested-section');
    $this->assertNotNull($container);
    $this->assertSame('nested-section', $container->getUuid());
    $this->assertSame($root->getComponent('nested-section'), $container);

    // Root sections have no container.
    $this->assertNull($storage->getContainerComponent('root-section'));
    $this->assertNull($storage->getContainerComponent('missing'));
  }

  /**
   * Tests get section by uuid returns hydrated embedded section.
   */
  public function testGetSectionByUuidReturnsHydratedEmbeddedSection(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    $deep = $storage->getSectionByUuid('deep-section');
    $this->assertNotNull($deep);
    $this->assertSame('deep-section', $deep->getThirdPartySetting('daedalus_layout', 'uuid'));

    // Identity-stable: repeated access returns the SAME object.
    $this->assertSame($deep, $storage->getSectionByUuid('deep-section'));
    $this->assertSame($deep, $storage->getSectionFor('deep-block'));

    // Blocks resolve NULL here.
    $this->assertNull($storage->getSectionByUuid('deep-block'));
  }

  /**
   * Tests get sections for semantics.
   */
  public function testGetSectionsForSemantics(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    // Root section: the root storage contains it.
    $this->assertSame($storage->getRootStorage(), $storage->getSectionsFor('root-section'));

    // Nested section: its container's embedded list contains it at delta 0.
    $list = $storage->getSectionsFor('nested-section');
    $this->assertInstanceOf(EmbeddedSectionList::class, $list);
    $this->assertSame($storage->getSectionByUuid('nested-section'), $list->getSection(0));
    $this->assertCount(1, $list);

    // Blocks contain no sections.
    $this->assertNull($storage->getSectionsFor('deep-block'));

    // Unknown uuids resolve NULL.
    $this->assertNull($storage->getSectionsFor('missing-uuid'));
  }

  /**
   * Tests get nested context.
   */
  public function testGetNestedContext(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    // Root items have no nested context.
    $this->assertNull($storage->getNestedContext('root-section'));
    $this->assertNull($storage->getNestedContext('root-block'));

    // A nested section's scope is its OWN uuid at delta 0.
    $this->assertSame(
      ['sectionUuid' => 'nested-section', 'localDelta' => 0],
      $storage->getNestedContext('nested-section'),
    );

    // A nested block's scope is its parent section.
    $this->assertSame(
      ['sectionUuid' => 'deep-section', 'localDelta' => 0],
      $storage->getNestedContext('deep-block'),
    );
  }

  /**
   * Tests for section scoping.
   */
  public function testForSectionScoping(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    // Default scope is NULL and forSection(NULL) is a no-op.
    $this->assertNull($storage->getSectionScope());
    $this->assertSame($storage, $storage->forSection(NULL));

    $scoped = $storage->forSection('deep-section');
    $this->assertNotSame($storage, $scoped);
    $this->assertSame('deep-section', $scoped->getSectionScope());
    $this->assertNull($storage->getSectionScope());

    // Same scope returns the same instance; scope changes clone again.
    $this->assertSame($scoped, $scoped->forSection('deep-section'));
    $rescoped = $scoped->forSection('nested-section');
    $this->assertNotSame($scoped, $rescoped);
    $this->assertSame('nested-section', $rescoped->getSectionScope());
    $this->assertNull($scoped->forSection(NULL)->getSectionScope());

    // Root storage is preserved across scoping.
    $this->assertSame($storage->getRootStorage(), $scoped->getRootStorage());

    // The scoped list serves the embedded section at delta 0, and it is the
    // same object every route hands out.
    $this->assertCount(1, $scoped);
    $this->assertSame($storage->getSectionByUuid('deep-section'), $scoped->getSection(0));
  }

  /**
   * Tests scope miss throws.
   */
  public function testScopeMissThrows(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();
    $scoped = $storage->forSection('no-such-section');

    $this->expectException(\OutOfBoundsException::class);
    $scoped->getSection(0);
  }

  /**
   * Tests bubble flushes in place mutations.
   */
  public function testBubbleFlushesInPlaceMutations(): void {
    ['storage' => $storage, 'root' => $root] = $this->buildDepthTwoTree();

    // A core form's submit: mutate the hydrated deep section in place, no
    // setter call anywhere.
    $section = $storage->getSectionByUuid('deep-section');
    $section->setLayoutSettings(['label' => 'Mutated']);

    $storage->bubbleChangesToRoot();

    // The write-back landed in the actual component configurations: the
    // nested container's raw config now embeds the mutated deep section.
    $nested_config = $root->getComponent('nested-section')->get('configuration');
    $deep_value = $nested_config[SectionBlock::SECTION_KEY]['components']['deep-section']['configuration'][SectionBlock::SECTION_KEY] ?? NULL;
    $this->assertNotNull($deep_value);
    $this->assertSame('Mutated', $deep_value['layout_settings']['label']);
  }

  /**
   * Tests remove section through scope removes container.
   */
  public function testRemoveSectionThroughScopeRemovesContainer(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();
    $hydrated_nested = $storage->getSectionByUuid('nested-section');

    $scoped = $storage->forSection('deep-section');
    $scoped->removeSection(0);

    // Removing the only section removes the container: the container
    // component is gone from its parent immediately.
    $this->assertArrayNotHasKey('deep-section', $hydrated_nested->getComponents());

    $storage->bubbleChangesToRoot();
    $this->assertNull($storage->getSectionByUuid('deep-section'));
    $this->assertNull($storage->getComponent('deep-block'));
    $this->assertNull($storage->getContainerComponent('deep-section'));
  }

  /**
   * Tests embedded list cardinality violation throws at flush.
   */
  public function testEmbeddedListCardinalityViolationThrowsAtFlush(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    $list = $storage->getSectionsFor('deep-section');
    $list->appendSection($this->realSection('second-section'));

    $this->expectException(\LogicException::class);
    $storage->bubbleChangesToRoot();
  }

  /**
   * Tests replace pattern rekeys container on new identity.
   */
  public function testReplacePatternRekeysContainerOnNewIdentity(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();
    $hydrated_nested = $storage->getSectionByUuid('nested-section');

    // The insert-new/remove-displaced replace pattern, with a NEW identity.
    $list = $storage->getSectionsFor('deep-section');
    $list->insertSection(0, $this->realSection('replacement-section'));
    $list->removeSection(1);

    $storage->bubbleChangesToRoot();

    // The wrapper followed the section: one identity, re-keyed in place.
    $this->assertArrayNotHasKey('deep-section', $hydrated_nested->getComponents());
    $container = $storage->getContainerComponent('replacement-section');
    $this->assertNotNull($container);
    $this->assertSame('replacement-section', $container->getUuid());
    $this->assertNull($storage->getSectionByUuid('deep-section'));
    $this->assertNotNull($storage->getSectionByUuid('replacement-section'));
  }

  /**
   * Tests get all sections includes embedded.
   */
  public function testGetAllSectionsIncludesEmbedded(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    $this->assertCount(3, $storage->getAllSections());
  }

  /**
   * Tests delegated methods.
   */
  public function testDelegatedMethods(): void {
    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getSections()->willReturn([]);
    $storage->getStorageId()->willReturn('test_storage_id');
    $storage->getStorageType()->willReturn('test_type');

    $nested = new NestedAwareSectionStorage($storage->reveal(), $this->entityTypeManager->reveal());

    $this->assertEquals('test_storage_id', $nested->getStorageId());
    $this->assertEquals('test_type', $nested->getStorageType());
  }

  /**
   * Tests section list delegation.
   */
  public function testSectionListDelegation(): void {
    $section1 = $this->realSection('sect-1');
    $section2 = $this->realSection('sect-2');

    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getSections()->willReturn([$section1, $section2]);
    $storage->getStorageId()->willReturn('test_id');
    $storage->getSection(0)->willReturn($section1);
    $storage->getSection(1)->willReturn($section2);
    $storage->count()->willReturn(2);

    $nested = new NestedAwareSectionStorage($storage->reveal(), $this->entityTypeManager->reveal());

    $this->assertCount(2, $nested->getSections());
    $this->assertSame($section1, $nested->getSection(0));
    $this->assertSame($section2, $nested->getSection(1));
    $this->assertEquals(2, $nested->count());
  }

  /**
   * Tests invalidate cache rebuilds index.
   */
  public function testInvalidateCacheRebuildsIndex(): void {
    $section = $this->realSection('section-1', [$this->realBlock('block-1')]);
    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([$section]),
      $this->entityTypeManager->reveal(),
    );

    $index1 = $nested->getTreeIndex();
    $nested->invalidateCache();
    $index2 = $nested->getTreeIndex();

    $this->assertNotSame($index1, $index2);
  }

  /**
   * Tests invalidate cache preserves hydrated sections.
   */
  public function testInvalidateCachePreservesHydratedSections(): void {
    ['storage' => $storage] = $this->buildDepthTwoTree();

    $deep = $storage->getSectionByUuid('deep-section');
    $deep->setLayoutSettings(['label' => 'Survives invalidation']);

    $storage->invalidateCache();

    // The same hydrated object is re-bound, not re-hydrated from config.
    $this->assertSame($deep, $storage->getSectionByUuid('deep-section'));
    $this->assertSame('Survives invalidation', $storage->getSectionByUuid('deep-section')->getLayoutSettings()['label']);
  }

  /**
   * Tests missing section uuid is stamped.
   */
  public function testMissingSectionUuidIsStamped(): void {
    $section = new Section('layout_onecol');
    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([$section]),
      $this->entityTypeManager->reveal(),
    );

    $this->assertFalse($nested->isDirty());
    $nested->getTreeIndex();
    $this->assertTrue($nested->isDirty());
    $this->assertNotEmpty($section->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests mismatched container uuid follows section.
   */
  public function testMismatchedContainerUuidFollowsSection(): void {
    // A copy that re-minted only the wrapper: component uuid differs from
    // the embedded section's uuid. The SECTION uuid wins (styles and
    // stencils key on it) and the component is re-keyed in lockstep.
    $inner = $this->realSection('inner-section', [$this->realBlock('inner-block')]);
    $container = new SectionComponent('drifted-wrapper-uuid', 'content', [
      'id' => SectionBlock::PLUGIN_ID,
      'label_display' => '0',
      SectionBlock::SECTION_KEY => $inner->toArray(),
      'context_mapping' => SectionBlock::CONTEXT_MAPPING,
    ]);
    $root = $this->realSection('root-section', [$container]);

    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([$root]),
      $this->entityTypeManager->reveal(),
    );

    $index = $nested->getTreeIndex();
    $this->assertTrue($nested->isDirty());
    $this->assertTrue($index->isSection('inner-section'));
    $this->assertFalse($index->has('drifted-wrapper-uuid'));
    $this->assertArrayNotHasKey('drifted-wrapper-uuid', $root->getComponents());
    $this->assertSame('inner-section', $nested->getContainerComponent('inner-section')->getUuid());
  }

  /**
   * Tests duplicate container uuids are resolved.
   */
  public function testDuplicateContainerUuidsAreResolved(): void {
    // Duplicated container config duplicates the embedded identity: same
    // section uuid, same component uuid, in two different sections.
    $inner_value = $this->realSection('shared-inner', [$this->realBlock('inner-block-1')])->toArray();
    $inner_value_2 = $this->realSection('shared-inner', [$this->realBlock('inner-block-2')])->toArray();

    $make_container = fn(array $value) => new SectionComponent('shared-inner', 'content', [
      'id' => SectionBlock::PLUGIN_ID,
      'label_display' => '0',
      SectionBlock::SECTION_KEY => $value,
      'context_mapping' => SectionBlock::CONTEXT_MAPPING,
    ]);

    $root1 = $this->realSection('root-1', [$make_container($inner_value)]);
    $root2 = $this->realSection('root-2', [$make_container($inner_value_2)]);

    $nested = new NestedAwareSectionStorage(
      $this->mockRootStorage([$root1, $root2]),
      $this->entityTypeManager->reveal(),
    );

    $index = $nested->getTreeIndex();

    // The first occurrence keeps the identity; the second was re-minted
    // deterministically, in lockstep for section AND component.
    $this->assertEquals([0, 'shared-inner', 0], $index->getPath('shared-inner'));
    $sections = array_keys($index->getAllOfType(TreeIndexInterface::TYPE_SECTION));
    $this->assertCount(4, $sections);
    $replacement = array_values(array_diff($sections, ['root-1', 'root-2', 'shared-inner']))[0];
    $this->assertEquals([1, $replacement, 0], $index->getPath($replacement));
    $this->assertSame($replacement, $nested->getContainerComponent($replacement)->getUuid());

    // Both inner blocks stay addressable under their respective parents.
    $this->assertEquals([0, 'shared-inner', 0, 'inner-block-1'], $index->getPath('inner-block-1'));
    $this->assertEquals([1, $replacement, 0, 'inner-block-2'], $index->getPath('inner-block-2'));
  }

  /**
   * Tests deterministic repair is consistent across builds.
   */
  public function testDeterministicRepairIsConsistentAcrossBuilds(): void {
    // Two independent object graphs from the same arrays (two requests
    // before tempstore is populated) must repair to the same uuids.
    $build = function (): array {
      $inner_value = $this->realSection('shared-inner', [$this->realBlock('inner-block-1')])->toArray();
      $inner_value_2 = $this->realSection('shared-inner', [$this->realBlock('inner-block-2')])->toArray();
      $make_container = fn(array $value) => new SectionComponent('shared-inner', 'content', [
        'id' => SectionBlock::PLUGIN_ID,
        'label_display' => '0',
        SectionBlock::SECTION_KEY => $value,
        'context_mapping' => SectionBlock::CONTEXT_MAPPING,
      ]);
      $root1 = $this->realSection('root-1', [$make_container($inner_value)]);
      $root2 = $this->realSection('root-2', [$make_container($inner_value_2)]);
      $storage = new NestedAwareSectionStorage(
        $this->mockRootStorage([$root1, $root2]),
        $this->entityTypeManager->reveal(),
      );
      $sections = array_keys($storage->getTreeIndex()->getAllOfType(TreeIndexInterface::TYPE_SECTION));
      sort($sections);
      return $sections;
    };

    $this->assertEquals($build(), $build());
  }

}
