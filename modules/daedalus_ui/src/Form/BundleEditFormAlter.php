<?php

namespace Drupal\daedalus_ui\Form;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * The bundle form's "Page builder" section.
 *
 * One vertical tab on every bundle edit form whose bundle config type
 * carries the settings (::hasSettingsSchema()). Daedalus UI owns the
 * section: its leading switch ("the page builder is on for this bundle",
 * the one boolean ShouldNotEditMode and daedalus_stylish's gate read) and
 * the groups under it, named by outcome and never by module. On a page
 * bundle the groups are the layout (a fact with a link, not a control),
 * when a page is created (the initial mode as one checkbox; daedalus_layout
 * adds its creation setting here) and styling (daedalus_stylish's box); on a
 * block type they are the Add panel (daedalus_layout's icon), the page (the
 * presentation) and styling. Another module adds its control into a group
 * through ::ensureGroup() and never parks a section of its own. Nothing
 * shows under a switch that is off (core's #states on every group), and
 * the vertical tab's summary line states the setup (js/bundle-form.js, fed
 * by the data-daedalus-ui-summary attribute a control may carry).
 *
 * The settings are the bundle config entity's `daedalus_ui` third-party
 * settings: `enabled`, `initial_mode` (a mode id or `none`; the checkbox
 * writes `edit`, the one per-bundle mode) and, on a block type,
 * `presentation`. One schema type (`daedalus_ui.bundle.options`) is named
 * for each bundle config type that carries them (daedalus_ui.schema.yml),
 * and ::hasSettingsSchema() is the one probe of it, so the section serves
 * every bundle config type so named and never writes a key with no schema.
 */
class BundleEditFormAlter {

  use StringTranslationTrait;

  /**
   * The flat #parents name of the leading switch.
   */
  public const SWITCH = 'daedalus_ui_enabled';

  /**
   * The groups of a page bundle's section, in order.
   */
  public const GROUP_LAYOUT = 'layout';
  public const GROUP_CREATION = 'creation';
  public const GROUP_STYLING = 'styling';

  /**
   * The groups of a block type's section, in order (styling is shared).
   */
  public const GROUP_ADD_PANEL = 'add_panel';
  public const GROUP_ON_PAGE = 'on_page';

  /**
   * The attribute a checked control contributes to the tab's summary line.
   *
   * Its value is one short sentence without the final stop ("Styling on");
   * js/bundle-form.js joins the checked ones after "On".
   */
  public const SUMMARY_ATTRIBUTE = 'data-daedalus-ui-summary';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedConfigManagerInterface $typedConfigManager,
    protected RouteProviderInterface $routeProvider,
    protected PageHosts $pageHosts,
    protected DaedalusUi $ui,
  ) {}

  /**
   * Implements hook_form_alter() for bundle entity edit forms.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    if (!$this->appliesTo($form_state)) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    $block_type = $bundle_of === 'block_content';
    self::ensurePageBuilderSection($form);
    $form['page_builder']['#attached']['library'][] = 'daedalus_ui/bundle_form';
    // Every input below sets explicit flat #parents: the submit handler
    // reads the flat values, and a value must not ride
    // copyFormValuesToEntity() (a third_party_settings #parents route
    // would replace the entity's TPS wholesale, clobbering keys no form
    // element carries).
    $form['page_builder'][self::SWITCH] = [
      '#weight' => -10,
      '#type' => 'checkbox',
      '#parents' => [self::SWITCH],
      '#title' => $block_type
        ? $this->t('Use @bundle blocks in the page builder', ['@bundle' => $bundle_entity->label()])
        : $this->t('Build @bundle content in the page builder', ['@bundle' => $bundle_entity->label()]),
      '#description' => $block_type
        ? $this->t('Editors place and edit these blocks in place, on the page.')
        : $this->t('Editors edit this content in place, on the page. Turn it off and it is edited through the form alone.'),
      '#default_value' => (bool) $bundle_entity->getThirdPartySetting('daedalus_ui', 'enabled', FALSE),
    ];
    if ($block_type) {
      $this->ensureGroup($form, $form_state, self::GROUP_ON_PAGE);
      // The site-builder tier of the element model's presentation decision:
      // this setting outranks page_builder declarations and the derived
      // default (see ElementModel::bundlePresentation()).
      $form['page_builder'][self::GROUP_ON_PAGE][ElementModel::SETTING] = [
        '#type' => 'radios',
        '#parents' => [ElementModel::SETTING],
        '#title' => $this->t('How the block is selected'),
        '#options' => [
          'auto' => $this->t('Decide automatically'),
          Presentation::UNIT => $this->t('As one element'),
          Presentation::MERGED => $this->t('As one element, merged'),
          Presentation::OPEN => $this->t('Show its structure'),
        ],
        '#default_value' => $bundle_entity->getThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'auto'),
        'auto' => [
          '#description' => $this->t("One element when the block's content is one thing; its structure otherwise."),
          '#attributes' => [self::SUMMARY_ATTRIBUTE => $this->t('Selected automatically')],
        ],
        Presentation::UNIT => [
          '#description' => $this->t('Its inner parts are reached by drilling in.'),
          '#attributes' => [self::SUMMARY_ATTRIBUTE => $this->t('Selected as one element')],
        ],
        Presentation::MERGED => [
          '#description' => $this->t('For blocks whose fields compose into one visual thing; the parts are not reachable.'),
          '#attributes' => [self::SUMMARY_ATTRIBUTE => $this->t('Selected as one element, merged')],
        ],
        Presentation::OPEN => [
          '#description' => $this->t('Every field is its own element.'),
          '#attributes' => [self::SUMMARY_ATTRIBUTE => $this->t('Its structure shown')],
        ],
      ];
    }
    else {
      $this->ensureGroup($form, $form_state, self::GROUP_LAYOUT);
      $form['page_builder'][self::GROUP_LAYOUT]['fact'] = $this->layoutFact($bundle_entity, $bundle_of);
      $this->ensureGroup($form, $form_state, self::GROUP_CREATION);
      // One per-bundle mode exists (Edit Mode) and it has no configuration,
      // so the stored mode id is one checkbox; a future mode with real
      // configuration adds its own fields to this group.
      $form['page_builder'][self::GROUP_CREATION]['initial_mode'] = [
        '#weight' => -10,
        '#type' => 'checkbox',
        '#parents' => ['initial_mode'],
        '#title' => $this->t('Open it in the page builder'),
        '#description' => $this->t('After the form is saved the author lands on the new page, ready to edit. Off: the normal view of the page.'),
        '#default_value' => $bundle_entity->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none') !== 'none',
        '#attributes' => [self::SUMMARY_ATTRIBUTE => $this->t('New pages open in it')],
      ];
    }
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   */
  public function submit(array &$form, FormStateInterface $form_state) {
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_entity->setThirdPartySetting('daedalus_ui', 'enabled', (bool) $form_state->getValue(self::SWITCH));
    if ($form_state->hasValue('initial_mode')) {
      $bundle_entity->setThirdPartySetting('daedalus_ui', 'initial_mode', $form_state->getValue('initial_mode') ? 'edit' : 'none');
    }
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
   * of form alter order; callers add their controls into a group of the
   * section through ::ensureGroup().
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
   * Ensures a group of the section exists, and says whether the form has one.
   *
   * The door for another module's control: it asks for the group named by
   * the outcome its control belongs to (one of the GROUP_* constants) and
   * adds the control under $form['page_builder'][$group] when this answers
   * TRUE. FALSE means the section does not belong on this form
   * (::appliesTo()), so the caller adds nothing. Create-if-missing, so the
   * order of the modules' form alters does not matter; a group is hidden
   * while the leading switch is off, through core's #states.
   *
   * @param array $form
   *   The bundle type edit form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $group
   *   One of the GROUP_* constants.
   *
   * @return bool
   *   TRUE when the group exists on the form.
   */
  public function ensureGroup(array &$form, FormStateInterface $form_state, string $group): bool {
    $definitions = [
      self::GROUP_LAYOUT => [$this->t('Layout'), 10],
      self::GROUP_ADD_PANEL => [$this->t('In the Add panel'), 10],
      self::GROUP_CREATION => [$this->t('When a page is created'), 20],
      self::GROUP_ON_PAGE => [$this->t('On the page'), 20],
      self::GROUP_STYLING => [$this->t('Styling'), 30],
    ];
    if (!isset($definitions[$group])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a group of the Page builder section.', $group));
    }
    if (!$this->appliesTo($form_state)) {
      return FALSE;
    }
    self::ensurePageBuilderSection($form);
    if (!isset($form['page_builder'][$group])) {
      [$title, $weight] = $definitions[$group];
      $form['page_builder'][$group] = [
        '#type' => 'fieldset',
        '#title' => $title,
        '#weight' => $weight,
        '#attributes' => [
          'class' => ['daedalus-ui-bundle-group'],
          'data-daedalus-ui-group' => $group,
        ],
        '#states' => [
          'visible' => [
            ':input[name="' . self::SWITCH . '"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
    return TRUE;
  }

  /**
   * The layout fact of a page bundle, with its links.
   *
   * Read from the bundle's host display (PageHosts::bundleHost(), the one
   * display resolution rule): pages own their layout (overrides on), share
   * the template's (Layout Builder on, overrides off), or have none to
   * build (Layout Builder off). The links are field_ui's manage-display
   * routes and are left out on a site without them.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity
   *   The bundle config entity.
   * @param string $bundle_of
   *   The content entity type the bundle belongs to.
   *
   * @return array
   *   A render array.
   */
  protected function layoutFact(ConfigEntityBundleBase $bundle_entity, string $bundle_of): array {
    $display = $this->pageHosts->bundleHost($bundle_of, (string) $bundle_entity->id())?->display();
    $layout_builder = $display instanceof LayoutEntityDisplayInterface && $display->isLayoutBuilderEnabled();
    $display_route = "entity.entity_view_display.$bundle_of.default";
    $display_url = $this->routeProvider->getRoutesByNames([$display_route]) !== []
      ? Url::fromRoute($display_route, [$bundle_entity->getEntityTypeId() => $bundle_entity->id()])
      : NULL;
    $template_link = $layout_builder && $this->ui->hasEditingUrl($display)
      ? Link::fromTextAndUrl($this->t('Edit the template'), $this->ui->getEditingUrl($display))->toString()
      : NULL;
    if ($layout_builder && $display->isOverridable()) {
      $fact = $this->t('Each page has its own layout, starting from the template.');
      $links = $template_link;
    }
    elseif ($layout_builder) {
      $fact = $this->t("Every page shows the template's layout; a page has no layout of its own.");
      $links = $template_link && $display_url
        ? $this->t('@edit or @own.', [
          '@edit' => $template_link,
          '@own' => Link::fromTextAndUrl($this->t('give pages their own layout'), $display_url)->toString(),
        ])
        : $template_link;
    }
    else {
      $fact = $this->t('Pages of this type have no layout to build: Layout Builder is off on their display.');
      $links = $display_url ? Link::fromTextAndUrl($this->t('Manage the display'), $display_url)->toString() : NULL;
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-ui-bundle-fact']],
      'fact' => ['#markup' => $fact],
    ];
    if ($links !== NULL) {
      $build['links'] = ['#markup' => $links, '#prefix' => ' '];
    }
    return $build;
  }

  /**
   * Whether the form edits a bundle the page builder can be on for.
   *
   * An existing bundle (the add form has nothing to enable yet) whose
   * bundle config type carries the settings (::hasSettingsSchema()).
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE when the section belongs on this form.
   */
  public function appliesTo(FormStateInterface $form_state): bool {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof BundleEntityFormBase) {
      return FALSE;
    }
    $bundle_entity = $form_object->getEntity();
    if (empty($bundle_entity->id())) {
      return FALSE;
    }
    return $this->hasSettingsSchema($bundle_entity->getEntityType());
  }

  /**
   * Whether a bundle config type carries a module's page builder settings.
   *
   * The probe is the exact schema name core resolves a third-party section
   * by, `{config prefix}.*.third_party.{module}`, matched on the resolved
   * definition's own type: the typed config manager falls back through
   * wildcard names, so a bare lookup of an undeclared name answers the
   * bundle config type's own mapping and would read as a yes. The section
   * itself is daedalus_ui's; a module that adds a control into a group
   * asks for its own settings the same way before writing a key.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $bundle_entity_type
   *   The bundle config entity type.
   * @param string $module
   *   The module whose third-party section is asked for; daedalus_ui's by
   *   default.
   *
   * @return bool
   *   TRUE when the module's schema names the type's third-party section.
   */
  public function hasSettingsSchema(ConfigEntityTypeInterface $bundle_entity_type, string $module = 'daedalus_ui'): bool {
    $schema_name = $bundle_entity_type->getConfigPrefix() . '.*.third_party.' . $module;
    $definition = $this->typedConfigManager->getDefinition($schema_name, FALSE);
    return is_array($definition) && ($definition['type'] ?? NULL) === $schema_name;
  }

}
