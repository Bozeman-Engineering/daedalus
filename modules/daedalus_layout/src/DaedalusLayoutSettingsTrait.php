<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;

/**
 * Trait for retrieving Daedalus Layout settings.
 */
trait DaedalusLayoutSettingsTrait {

  /**
   * Get a Daedalus Layout setting.
   *
   * Gets a daedalus layout setting from the entity_view_display third
   * party settings.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param string $setting
   *   The setting key to retrieve.
   *
   * @return mixed
   *   The setting.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getDaedalusLayoutSetting(SectionStorageInterface $section_storage, string $setting) {
    $entity_view_display = $this->loadEntityViewDisplay($section_storage);
    return $entity_view_display->getThirdPartySetting('daedalus_layout', $setting, []);
  }

  /**
   * Load entity view display.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay|null
   *   The entity view display for this section storage based on the current
   *   contexts.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadEntityViewDisplay(SectionStorageInterface $section_storage): ?LayoutBuilderEntityViewDisplay {
    $contexts = $section_storage->getContexts();
    if (!empty($contexts['entity'])) {
      // Load the entity view display for the entity layout being edited.
      $entity = $contexts['entity']->getContextValue();
      $view_mode = $section_storage->getContext('view_mode')->getContextValue();
      $view_mode = _daedalus_ui_get_view_mode($entity, $view_mode);
      $entity_view_display_id = sprintf('%s.%s.%s', $entity->getEntityTypeId(), $entity->bundle(), $view_mode);
    }
    else {
      // We are editing the default layout for this entity type.
      $entity_view_display_id = $contexts['display']->getContextValue()->id();
    }

    return $this->entityTypeManager()->getStorage('entity_view_display')->load($entity_view_display_id);
  }

  /**
   * Gets the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

}
