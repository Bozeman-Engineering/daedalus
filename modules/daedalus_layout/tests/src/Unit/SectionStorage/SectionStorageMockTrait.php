<?php

namespace Drupal\Tests\daedalus_layout\Unit\SectionStorage;

use Drupal\layout_builder\SectionStorageInterface;

/**
 * Provides common mock creation methods for section storage tests.
 *
 * Use this trait in PHPUnit test classes that need to create mock
 * section storages.
 */
trait SectionStorageMockTrait {

  /**
   * Creates a mock section storage.
   *
   * @param string $storageId
   *   The storage ID.
   * @param array $sections
   *   Optional array of Section objects.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|\Prophecy\Prophecy\ObjectProphecy
   *   The mock section storage (revealed).
   */
  protected function createMockSectionStorage(string $storageId, array $sections = []) {
    $sectionStorage = $this->prophesize(SectionStorageInterface::class);
    $sectionStorage->getStorageId()->willReturn($storageId);
    $sectionStorage->getSections()->willReturn($sections);

    foreach ($sections as $delta => $section) {
      $sectionStorage->getSection($delta)->willReturn($section);
    }

    return $sectionStorage;
  }

}
