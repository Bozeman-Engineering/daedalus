<?php

namespace Drupal\daedalus_tempstore;

use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;

/**
 * Layout Builder tempstore repository.
 *
 * Drop-in replacement for core's LayoutTempstoreRepository that uses the
 * strategy pattern to delegate operations. Implements
 * LayoutTempstoreRepositoryInterface for compatibility with core and contrib
 * modules.
 */
class LayoutTempstoreRepository extends TempstoreRepository implements LayoutTempstoreRepositoryInterface {

  /**
   * {@inheritdoc}
   */
  public function get(SectionStorageInterface $section_storage) {
    return $this->getStrategy($section_storage)->get($section_storage);
  }

  /**
   * {@inheritdoc}
   */
  public function set(SectionStorageInterface $section_storage) {
    $this->getStrategy($section_storage)->set($section_storage);
  }

  /**
   * {@inheritdoc}
   */
  public function has(SectionStorageInterface $section_storage) {
    return $this->getStrategy($section_storage)->has($section_storage);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(SectionStorageInterface $section_storage) {
    $this->getStrategy($section_storage)->delete($section_storage);
  }

}
