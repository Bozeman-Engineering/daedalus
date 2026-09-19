<?php

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\layout_builder\DefaultsSectionStorageInterface;

/**
 * Nested-aware wrapper for defaults section storages.
 *
 * This subclass implements DefaultsSectionStorageInterface so that
 * instanceof checks pass through correctly when the root storage is a
 * defaults storage.
 */
class NestedAwareDefaultsSectionStorage extends NestedAwareSectionStorage implements DefaultsSectionStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function isSupported(string $entity_type_id, string $bundle, string $view_mode): bool {
    return $this->rootStorage->isSupported($entity_type_id, $bundle, $view_mode);
  }

  /**
   * {@inheritdoc}
   */
  public function setThirdPartySetting($module, $key, $value) {
    return $this->rootStorage->setThirdPartySetting($module, $key, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartySetting($module, $key, $default = NULL) {
    return $this->rootStorage->getThirdPartySetting($module, $key, $default);
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartySettings($module) {
    return $this->rootStorage->getThirdPartySettings($module);
  }

  /**
   * {@inheritdoc}
   */
  public function unsetThirdPartySetting($module, $key) {
    return $this->rootStorage->unsetThirdPartySetting($module, $key);
  }

  /**
   * {@inheritdoc}
   */
  public function getThirdPartyProviders() {
    return $this->rootStorage->getThirdPartyProviders();
  }

  /**
   * {@inheritdoc}
   */
  public function isLayoutBuilderEnabled() {
    return $this->rootStorage->isLayoutBuilderEnabled();
  }

  /**
   * {@inheritdoc}
   */
  public function enableLayoutBuilder() {
    return $this->rootStorage->enableLayoutBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public function disableLayoutBuilder() {
    return $this->rootStorage->disableLayoutBuilder();
  }

  /**
   * {@inheritdoc}
   */
  public function isOverridable() {
    return $this->rootStorage->isOverridable();
  }

  /**
   * {@inheritdoc}
   */
  public function setOverridable($overridable = TRUE) {
    return $this->rootStorage->setOverridable($overridable);
  }

}
