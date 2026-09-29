<?php

namespace Drupal\daedalus_ui\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Bundle type edit form alter.
 *
 * Daedalus UI settings related to the bundle type. The section lives in
 * the shared "Page builder" vertical tab (::ensurePageBuilderSection()) so
 * every page-builder bundle setting (this one, Daedalus Stylish's enable checkbox)
 * is configured in one place.
 */
class BundleEditFormAlter {

  public function __construct(
    protected ModePluginManager $modeManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedConfigManagerInterface $typedConfigManager,
  ) {}

  use StringTranslationTrait;

  /**
   * Implements hook_form_FORM_ID_alter() for the 'field_config_edit' form ID.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    if (!$this->editModeIsEnabled($form_state)) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    if (empty($bundle_entity->id())) {
      return;
    }
    $bundle_definition = $this->entityTypeManager->getDefinition($bundle_entity->getEntityTypeId());
    $modes = $this->modeManager->getDefinitions();
    self::ensurePageBuilderSection($form);
    $form['page_builder']['daedalus_ui'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus UI'),
      '#open' => TRUE,
    ];
    $form['page_builder']['daedalus_ui']['modes'] = [
      '#weight' => 10,
      '#type' => 'table',
      '#header' => [
        'plugin_id' => $this->t('Plugin ID'),
        'summary' => $this->t('Configuration summary'),
        'operations' => $this->t('Operations'),
      ],
      '#title' => $this->t('Navigation modes configuration'),
      '#rows' => [],
      '#empty' => $this->t('There are no navigation modes to configure.'),
    ];
    $options = ['none' => $this->t('None')];
    $configured_modes = $bundle_entity->getThirdPartySetting('daedalus_ui', 'modes', []);
    foreach ($modes as $plugin_id => $plugin_definition) {
      if (empty($plugin_definition['per_bundle'])) {
        continue;
      }
      $options[$plugin_id] = $plugin_definition['label'];
      $plugin = $this->modeManager->createInstance($plugin_id, $configured_modes[$plugin_id] ?? []);
      $form['page_builder']['daedalus_ui']['modes'][$plugin_id] = [
        'plugin_id' => [
          '#markup' => $plugin_definition['label'],
        ],
        'summary' => [
          '#markup' => $plugin->getSummary(),
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => $this->getOperations($plugin_id, $bundle_definition->getBundleOf(), $bundle_entity->id()),
          // Allow links to use modals.
          '#attached' => [
            'library' => ['core/drupal.dialog.ajax'],
          ],
        ],
      ];
    }
    if ($bundle_definition->getBundleOf() === 'block_content') {
      // The site-builder tier of the element model's presentation decision:
      // this setting outranks page_builder declarations and the derived
      // default (see ElementModel::bundlePresentation()).
      $form['page_builder']['daedalus_ui'][ElementModel::SETTING] = [
        '#weight' => 5,
        '#type' => 'select',
        // Explicit flat #parents: the submit handler reads the flat value,
        // and the value must not ride copyFormValuesToEntity() (a
        // third_party_settings #parents route would replace the entity's
        // TPS wholesale, clobbering keys no form element carries, e.g.
        // daedalus_ui 'modes'/'status').
        '#parents' => [ElementModel::SETTING],
        '#title' => $this->t('Presentation'),
        '#description' => $this->t('How the page builder presents blocks of this type. "Auto" derives it: a block whose meaningful content is exactly one thing presents as that one element. "One element" always presents the block as a single element with its inner structure reachable by drilling in. "One element (merged)" absorbs the inner structure entirely — for blocks whose render composes their fields into one visual element. "Show structure" always exposes the inner structure.'),
        '#options' => [
          'auto' => $this->t('Auto'),
          Presentation::UNIT => $this->t('One element'),
          Presentation::MERGED => $this->t('One element (merged)'),
          Presentation::OPEN => $this->t('Show structure'),
        ],
        '#default_value' => $bundle_entity->getThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'auto'),
      ];
    }
    $form['page_builder']['daedalus_ui']['initial_mode'] = [
      '#weight' => 0,
      '#type' => 'radios',
      '#parents' => ['initial_mode'],
      '#title' => $this->t('Initial mode'),
      '#description' => $this->t('Any mode can be initialized after the first save of this bundle. Choose a mode, or select "none" for the normal Drupal behavior.'),
      '#options' => $options,
      '#default_value' => $bundle_entity->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none'),
    ];
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   */
  public function submit(array &$form, FormStateInterface $form_state) {
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_entity->setThirdPartySetting('daedalus_ui', 'initial_mode', $form_state->getValue('initial_mode'));
    if ($form_state->hasValue(ElementModel::SETTING)) {
      $bundle_entity->setThirdPartySetting('daedalus_ui', ElementModel::SETTING, $form_state->getValue(ElementModel::SETTING));
      // The boolean 'atomic' predecessor is inert since the presentation
      // cutover (pre-release, no migration); sweep it off configs as they
      // re-save so nothing carries a schema-less key.
      $bundle_entity->unsetThirdPartySetting('daedalus_ui', 'atomic');
    }
  }

  /**
   * Ensures the shared "Page builder" vertical tab exists on the form.
   *
   * The bundle-form counterpart of
   * FieldConfigFormAlter::ensurePageBuilderSection(): page-builder modules
   * co-locate their bundle settings in one "Page builder" tab instead of
   * each adding their own. Create-if-missing keeps the result independent
   * of form alter order; callers park their sections under
   * $form['page_builder'].
   *
   * Not every bundle form ships core's vertical tabs container (node types
   * do, block content types don't), so the container itself is also
   * create-if-missing. Value routing stays FLAT: nested sections must set
   * explicit flat #parents on their inputs and persist via their own
   * submit handlers, never via a third_party_settings #parents route
   * (copyFormValuesToEntity() would replace the entity's third-party
   * settings wholesale, clobbering keys no form element carries).
   *
   * @param array $form
   *   The bundle type edit form.
   */
  public static function ensurePageBuilderSection(array &$form): void {
    if (!isset($form['additional_settings'])) {
      $form['additional_settings'] = [
        '#type' => 'vertical_tabs',
        '#weight' => 99,
      ];
      // Forms without the container rely on array order to keep actions
      // last; the weighted container would sort below them without this.
      if (isset($form['actions']) && !isset($form['actions']['#weight'])) {
        $form['actions']['#weight'] = 100;
      }
    }
    if (!isset($form['page_builder'])) {
      $form['page_builder'] = [
        '#type' => 'details',
        '#title' => t('Page builder'),
        '#group' => 'additional_settings',
      ];
    }
  }

  /**
   * Builds the enable, disable and configure operations for a bundle's modes.
   */
  public static function getOperations(string $plugin_id, string $entity_type_id, string $entity_bundle_id) {
    $bundled_entity = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    $bundle_entity_type = $bundled_entity->getBundleEntityType();
    $statuses = [];
    if ($bundle_entity_type) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $bundle_entity */
      $bundle_entity = \Drupal::entityTypeManager()
        ->getStorage($bundle_entity_type)
        ->load($entity_bundle_id);
      $statuses = $bundle_entity->getThirdPartySetting('daedalus_ui', "status", []);
    }
    $operations = [];
    if (empty($statuses[$plugin_id])) {
      $operations['enable'] = [
        'title' => new TranslatableMarkup('Enable'),
        'weight' => 10,
        'url' => Url::fromRoute('daedalus_ui.mode.enable', [
          'plugin_id' => $plugin_id,
          'entity_type_id' => $entity_type_id,
          'entity_bundle_id' => $entity_bundle_id,
        ]),
        'attributes' => [
          'class' => [
            'use-ajax',
          ],
        ],
      ];
    }
    else {
      // Only a mode with a configuration form has anything to configure;
      // the enable and disable operations are every per-bundle mode's.
      $definition = \Drupal::service('plugin.manager.modes')->getDefinition($plugin_id);
      if (is_subclass_of($definition['class'], PluginFormInterface::class)) {
        $operations['configure'] = [
          'title' => new TranslatableMarkup('Configure'),
        'weight' => 10,
        'url' => Url::fromRoute('daedalus_ui.mode.configure', [
          'plugin_id' => $plugin_id,
          'entity_type_id' => $entity_type_id,
          'entity_bundle_id' => $entity_bundle_id,
        ]),
        'attributes' => [
          'class' => [
            'use-ajax',
          ],
        ],
          'ajax' => [
            'dialogType' => 'modal',
            'dialog' => ['height' => 400, 'width' => 700],
          ],
        ];
      }

      $operations['disable'] = [
        'title' => new TranslatableMarkup('Disable'),
        'weight' => 10,
        'url' => Url::fromRoute('daedalus_ui.mode.disable', [
          'plugin_id' => $plugin_id,
          'entity_type_id' => $entity_type_id,
          'entity_bundle_id' => $entity_bundle_id,
        ]),
        'attributes' => [
          'class' => [
            'use-ajax',
          ],
        ],
      ];
    }

    return $operations;
  }

  /**
   * Edit mode is enabled.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when the bundle's third-party settings carry daedalus_ui.
   */
  public function editModeIsEnabled(FormStateInterface $form_state): bool {
    $edit_mode_is_enabled = FALSE;
    $object = $form_state->getFormObject();
    if (is_subclass_of($object, BundleEntityFormBase::class)) {
      $schema_name = $object->getEntity()->getEntityType()->getConfigPrefix() . '.*.third_party.daedalus_ui';
      $definition = $this->typedConfigManager->getDefinition($schema_name, FALSE);
      if (!$definition || $definition['type'] !== $schema_name) {
        $edit_mode_is_enabled = FALSE;
      }
      else {
        $edit_mode_is_enabled = TRUE;
      }
    }
    return $edit_mode_is_enabled;
  }

}
