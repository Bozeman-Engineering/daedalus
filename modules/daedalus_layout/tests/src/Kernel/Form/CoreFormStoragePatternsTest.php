<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel\Form;

use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives core's form storage patterns generically against nested scopes.
 *
 * Core Layout Builder forms never know about embedding: they receive a
 * (section storage, delta) pair from routing and mutate
 * getSection($delta) IN PLACE with no setter call (ConfigureSectionForm,
 * ConfigureBlockFormBase) or restructure the list by delta
 * (RemoveSectionForm, the change-layout replace pattern, MoveBlockForm).
 * The scoped-storage contract promises that ANY core-shaped form — custom
 * or contrib, not just the two this suite routes to — works at any depth
 * through forSection() + the identity-stable EmbeddedSectionList, with the
 * flush happening at the tempstore set() seam.
 *
 * Every scenario here runs at depth 2 (a section inside a section inside a
 * root section) and persists through the REAL tempstore repository, because
 * the classic failure mode is precisely a mutation that looks applied
 * in-memory and silently vanishes at the flush.
 */
#[CoversClass(\Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class CoreFormStoragePatternsTest extends NestedStorageKernelTestBase {

  /**
   * The uuid of the depth-2 section the scenarios operate on.
   */
  protected string $deepSectionUuid;

  /**
   * The uuid of the depth-1 section.
   */
  protected string $nestedSectionUuid;

  /**
   * The uuid of the root section.
   */
  protected string $rootSectionUuid;

  /**
   * The uuid of the block living in the depth-2 section.
   */
  protected string $deepBlockUuid;

  /**
   * The entity under test.
   *
   * @var \Drupal\entity_test\Entity\EntityTest
   */
  protected $entity;

  /**
   * Builds root > nested > deep, with one block in the deep section.
   */
  protected function buildDepthTwoLayout(): void {
    $this->deepBlockUuid = $this->uuid->generate();
    $deep_block = $this->createBasicBlockComponent($this->deepBlockUuid);

    $this->deepSectionUuid = $this->uuid->generate();
    $deep_section = $this->createSection('layout_twocol', [$deep_block], $this->deepSectionUuid);

    $this->nestedSectionUuid = $this->uuid->generate();
    $nested_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($deep_section),
    ], $this->nestedSectionUuid);

    $this->rootSectionUuid = $this->uuid->generate();
    $root_section = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested_section),
    ], $this->rootSectionUuid);

    $this->entity = $this->createTestEntity([$root_section]);
  }

  /**
   * The storage a core form receives: tempstore-backed, scoped by routing.
   *
   * Mirrors NestedRouteEnhancer: resolve the nested context for the target
   * uuid and scope the tempstore-tracked storage to it.
   *
   * @param string $uuid
   *   The uuid the form route addresses (a block or section uuid).
   *
   * @return array{storage: \Drupal\daedalus_layout\NestedSectionStorageInterface, delta: int}
   *   The scoped storage and the local delta, as route defaults would carry.
   */
  protected function formStorageFor(string $uuid): array {
    $storage = $this->container->get('layout_builder.tempstore_repository')
      ->get($this->getNestedStorage($this->entity));
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $storage);
    $context = $storage->getNestedContext($uuid);
    if ($context === NULL) {
      return ['storage' => $storage, 'delta' => $storage->getPath($uuid)[0]];
    }
    return [
      'storage' => $storage->forSection($context['sectionUuid']),
      'delta' => $context['localDelta'],
    ];
  }

  /**
   * Persists the way a core form submit does: tempstore set() flushes.
   */
  protected function submitToTempstore(NestedSectionStorageInterface $storage): void {
    $this->container->get('layout_builder.tempstore_repository')->set($storage);
  }

  /**
   * Re-reads the layout from tempstore with a completely fresh wrapper.
   */
  protected function freshFromTempstore(): NestedSectionStorageInterface {
    // Reset entity cache so nothing rides along in memory.
    $this->entityTypeManager->getStorage('entity_test')->resetCache([$this->entity->id()]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($this->entity->id());
    $storage = $this->container->get('layout_builder.tempstore_repository')
      ->get($this->getNestedStorage($entity));
    $this->assertInstanceOf(NestedSectionStorageInterface::class, $storage);
    return $storage;
  }

  /**
   * ConfigureSectionForm (update mode): mutate the section in place.
   *
   * The form calls getSection($delta), applies layout settings on the
   * returned object, and never calls a setter. A fromArray-per-call storage
   * silently loses this submit; the memoized list must not.
   */
  public function testConfigureSectionAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepSectionUuid);
    $this->assertSame(0, $delta);

    // The form's submit: in-place mutation, no setter, then tempstore set.
    $section = $scoped->getSection($delta);
    $settings = $section->getLayoutSettings();
    $settings['label'] = 'Renamed deep section';
    $section->setLayoutSettings($settings);
    $section->setThirdPartySetting('test_module', 'marker', 'configured');
    $this->submitToTempstore($scoped);

    // A fresh wrapper over tempstore sees the mutation at depth 2.
    $fresh = $this->freshFromTempstore();
    $reloaded = $fresh->getSectionByUuid($this->deepSectionUuid);
    $this->assertSame('Renamed deep section', $reloaded->getLayoutSettings()['label']);
    $this->assertSame('configured', $reloaded->getThirdPartySetting('test_module', 'marker'));
  }

  /**
   * UpdateBlockForm (ConfigureBlockFormBase): reconfigure a nested block.
   *
   * The form resolves getSection($delta)->getComponent($uuid) on the scoped
   * storage and writes through the component.
   */
  public function testUpdateBlockAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepBlockUuid);
    $this->assertSame(0, $delta);

    $component = $scoped->getSection($delta)->getComponent($this->deepBlockUuid);
    $configuration = $component->get('configuration');
    $configuration['label'] = 'Updated label';
    $component->setConfiguration($configuration);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $reloaded = $fresh->getComponent($this->deepBlockUuid);
    $this->assertSame('Updated label', $reloaded->get('configuration')['label']);
  }

  /**
   * AddBlockForm: append a new component into a depth-2 section.
   */
  public function testAddBlockAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepSectionUuid);

    $new_uuid = $this->uuid->generate();
    $component = new SectionComponent($new_uuid, 'second', [
      'id' => 'system_powered_by_block',
      'label' => 'Added at depth two',
      'label_display' => 'visible',
    ]);
    $scoped->getSection($delta)->appendComponent($component);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $this->assertNotNull($fresh->getComponent($new_uuid));
    $this->assertEquals(
      [0, $this->nestedSectionUuid, 0, $this->deepSectionUuid, 0, $new_uuid],
      $fresh->getPath($new_uuid),
    );
  }

  /**
   * RemoveBlockForm: remove a block from a depth-2 section.
   */
  public function testRemoveBlockAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepBlockUuid);

    $scoped->getSection($delta)->removeComponent($this->deepBlockUuid);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $this->assertNull($fresh->getComponent($this->deepBlockUuid));
    $reloaded_deep = $fresh->getSectionByUuid($this->deepSectionUuid);
    $this->assertCount(0, $reloaded_deep->getComponents());
  }

  /**
   * MoveBlockForm within one scope: region move by delta.
   *
   * Real moves are move_element; core's MoveBlockForm keeps within-scope
   * parity only. Within a one-section scope that is a region/order move.
   */
  public function testMoveBlockWithinScopeAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepBlockUuid);

    // MoveBlockForm's submit shape: fetch from the "from" delta, remove,
    // re-insert at the "to" delta (same scope, new region).
    $section = $scoped->getSection($delta);
    $component = $section->getComponent($this->deepBlockUuid);
    $section->removeComponent($this->deepBlockUuid);
    $component->setRegion('second');
    $scoped->getSection($delta)->appendComponent($component);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $this->assertSame('second', $fresh->getComponent($this->deepBlockUuid)->getRegion());
  }

  /**
   * RemoveSectionForm: removing the depth-2 section removes its container.
   */
  public function testRemoveSectionAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepSectionUuid);

    $scoped->removeSection($delta);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $this->assertNull($fresh->getSectionByUuid($this->deepSectionUuid));
    $this->assertNull($fresh->getComponent($this->deepBlockUuid));
    // The depth-1 section survives, emptied.
    $nested = $fresh->getSectionByUuid($this->nestedSectionUuid);
    $this->assertCount(0, $nested->getComponents());
  }

  /**
   * The change-layout replace pattern: insert new, remove displaced.
   *
   * A section rebuild swaps a replacement in via insertSection($delta,
   * $new) + removeSection($delta + 1). The embedded list must tolerate the
   * transient second entry and keep the one-identity invariant (same uuid,
   * same container) at flush.
   */
  public function testReplaceSectionPatternAtDepthTwo(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepSectionUuid);

    $old = $scoped->getSection($delta);
    $component = $old->getComponents()[$this->deepBlockUuid];
    $component->setRegion('content');
    // Same uuid: identity is preserved through the layout change.
    $replacement = $this->createSection('layout_onecol', [], $this->deepSectionUuid);
    $replacement->appendComponent($component);

    $scoped->insertSection($delta, $replacement);
    $scoped->removeSection($delta + 1);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $reloaded = $fresh->getSectionByUuid($this->deepSectionUuid);
    $this->assertNotNull($reloaded);
    $this->assertSame('layout_onecol', $reloaded->getLayoutId());
    $this->assertCount(1, $reloaded->getComponents());
    // The container component still carries the same identity.
    $this->assertNotNull($fresh->getContainerComponent($this->deepSectionUuid));
    $this->assertSame(
      $this->deepSectionUuid,
      $fresh->getContainerComponent($this->deepSectionUuid)->getUuid(),
    );
  }

  /**
   * Replacing a section with a NEW identity re-keys the container.
   *
   * Component uuid == section uuid is the one-identity invariant; when an op
   * swaps in a section carrying a different uuid, the wrapper follows the
   * section at flush.
   */
  public function testReplaceSectionWithNewIdentityRekeysContainer(): void {
    $this->buildDepthTwoLayout();

    ['storage' => $scoped, 'delta' => $delta] = $this->formStorageFor($this->deepSectionUuid);

    $new_uuid = $this->uuid->generate();
    $replacement = $this->createSection('layout_onecol', [], $new_uuid);
    $scoped->insertSection($delta, $replacement);
    $scoped->removeSection($delta + 1);
    $this->submitToTempstore($scoped);

    $fresh = $this->freshFromTempstore();
    $this->assertNull($fresh->getSectionByUuid($this->deepSectionUuid));
    $reloaded = $fresh->getSectionByUuid($new_uuid);
    $this->assertNotNull($reloaded);
    $container = $fresh->getContainerComponent($new_uuid);
    $this->assertNotNull($container);
    $this->assertSame($new_uuid, $container->getUuid());
  }

  /**
   * A scope that no longer resolves throws instead of falling back.
   *
   * A silent root fallback would be data corruption: a form scoped to a
   * removed section would transparently edit the ROOT layout.
   */
  public function testScopeMissThrows(): void {
    $this->buildDepthTwoLayout();

    $storage = $this->getNestedStorage($this->entity);
    $scoped = $storage->forSection($this->uuid->generate());

    $this->expectException(\OutOfBoundsException::class);
    $scoped->getSection(0);
  }

  /**
   * The other half: the scope vanishing AFTER scoping also throws.
   */
  public function testScopeRemovedUnderneathThrows(): void {
    $this->buildDepthTwoLayout();

    $storage = $this->getNestedStorage($this->entity);
    $scoped = $storage->forSection($this->deepSectionUuid);
    $this->assertCount(1, $scoped->getSections());

    // Another actor removes the whole depth-1 subtree.
    $storage->getSectionByUuid($this->rootSectionUuid)
      ->removeComponent($this->nestedSectionUuid);
    $storage->invalidateCache();

    $this->expectException(\OutOfBoundsException::class);
    $scoped->getSection(0);
  }

  /**
   * Identity stability: every access route hands out the same Section.
   *
   * GetSection($delta) on the scope, getSectionByUuid(), and getSectionFor()
   * of a contained block must all return the SAME object, or an in-place
   * mutation through one route is invisible to (and clobbered by) another.
   */
  public function testHydrationIsIdentityStableAcrossRoutes(): void {
    $this->buildDepthTwoLayout();

    $storage = $this->getNestedStorage($this->entity);
    $scoped = $storage->forSection($this->deepSectionUuid);

    $via_scope = $scoped->getSection(0);
    $via_uuid = $storage->getSectionByUuid($this->deepSectionUuid);
    $via_block = $storage->getSectionFor($this->deepBlockUuid);

    $this->assertSame($via_scope, $via_uuid);
    $this->assertSame($via_scope, $via_block);
  }

}
