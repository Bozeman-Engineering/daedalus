<?php

/**
 * @file
 * Install, update and uninstall functions for daedalus_ui_entity_workflow.
 */

/**
 * Uninstall the deprecated daedalus_ui_entity_workflow module.
 */
function daedalus_ui_entity_workflow_update_10001() {
  $module_installer = \Drupal::service('module_installer');
  $module_installer->uninstall(['daedalus_ui_entity_workflow']);
  return t('The daedalus_ui_entity_workflow module has been deprecated and uninstalled.');
}
