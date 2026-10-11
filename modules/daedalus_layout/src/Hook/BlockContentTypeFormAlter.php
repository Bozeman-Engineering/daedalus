<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_layout\AddPanel\BlockTypeFace;
use Drupal\daedalus_ui\Form\BundleEditFormAlter;

/**
 * Adds the block type's Add panel face to the block type edit form.
 *
 * The face is a property of the block TYPE, stored under
 * `block_content.type.*.third_party.daedalus_layout`: `icon` in the icon
 * value grammar (`media:<uuid>` or a root-relative path, see
 * \Drupal\daedalus_blueprint\IconResolver), `favorite` (the site's
 * default: listed under Favorites for everyone where blocks are offered;
 * each editor keeps their own marks over it) and `groups` (the ids of the
 * site's group vocabulary the type is filed under, a filter and nothing
 * else). A blueprint carries the same three in its details dialog
 * (BlueprintSaveDialog), and the two forms render them alike: the groups
 * are checkboxes over the catalog's declared groups
 * (DialectCatalog::groups(), declaration order) plus any stored id the
 * site no longer declares (rendered checked and labelled by its id, so a
 * save never drops it silently; a surface that filters ignores it), shown
 * only when there is something to offer or something stored. The controls
 * sit in the "In the Add panel" group of the shared "Page builder" section
 * Daedalus UI owns (BundleEditFormAlter::ensureGroup()), which also
 * decides whether the section belongs on the form at all.
 *
 * Each key's absence is the one spelling of its default (no icon, not a
 * favorite, no group), so the submit handler unsets a key the form leaves
 * at its default rather than writing an empty value that would ship in
 * config and read as configured (the favorite through BlockTypeFace, the
 * writer the Add panel's "Favorite for everyone" shares).
 *
 * Value routing is FLAT (explicit #parents + own submit handler doing
 * setThirdPartySetting()), never a third_party_settings #parents route:
 * copyFormValuesToEntity() would replace the entity's third-party settings
 * wholesale, clobbering keys no form element carries.
 */
class BlockContentTypeFormAlter {

  use StringTranslationTrait;

  /**
   * The flat form value of each control.
   */
  public const ICON_CONTROL = 'daedalus_layout_icon';
  public const FAVORITE_CONTROL = 'daedalus_layout_favorite';
  public const GROUPS_CONTROL = 'daedalus_layout_groups';

  public function __construct(
    protected BundleEditFormAlter $sectionOwner,
    protected DialectCatalogBuilder $catalogBuilder,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for block_content_type_edit_form.
   */
  #[Hook('form_block_content_type_edit_form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id): void {
    if (!$this->sectionOwner->ensureGroup($form, $form_state, BundleEditFormAlter::GROUP_ADD_PANEL)) {
      return;
    }
    /** @var \Drupal\block_content\BlockContentTypeInterface $block_type */
    $block_type = $form_state->getFormObject()->getEntity();
    $group = &$form['page_builder'][BundleEditFormAlter::GROUP_ADD_PANEL];
    $group['icon'] = [
      '#type' => 'daedalus_layout_icon_picker',
      '#title' => $this->t('Icon'),
      '#description' => $this->t('Represents this block type in the page builder, e.g. on its item in the Add panel. Leave unset for the default block icon.'),
      // Explicit flat #parents: the submit handler reads the flat value.
      '#parents' => [self::ICON_CONTROL],
      '#default_value' => $block_type->getThirdPartySetting('daedalus_layout', 'icon'),
      '#host' => $block_type,
    ];
    $group['favorite'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Favorite'),
      '#description' => $this->t('Listed under Favorites in the Add panel for everyone. Each editor keeps their own favorites over this.'),
      '#parents' => [self::FAVORITE_CONTROL],
      '#default_value' => (bool) $block_type->getThirdPartySetting('daedalus_layout', 'favorite', FALSE),
      '#attributes' => [BundleEditFormAlter::SUMMARY_ATTRIBUTE => $this->t('A favorite in the Add panel')],
    ];

    $stored = $block_type->getThirdPartySetting('daedalus_layout', 'groups', []);
    $options = array_map(static fn (array $group): string => $group['label'], $this->catalogBuilder->build()->groups());
    foreach ($stored as $id) {
      $options += [$id => $id];
    }
    if ($options !== []) {
      $group['groups'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Groups'),
        '#description' => $this->t('The groups the block type is listed under in the Add panel. Filters only; nothing checked lists it under none.'),
        '#parents' => [self::GROUPS_CONTROL],
        '#options' => $options,
        '#default_value' => $stored,
      ];
    }
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\block_content\BlockContentTypeInterface $block_type */
    $block_type = $form_state->getFormObject()->getEntity();
    $icon = trim((string) $form_state->getValue(self::ICON_CONTROL));
    if ($icon !== '') {
      $block_type->setThirdPartySetting('daedalus_layout', 'icon', $icon);
    }
    else {
      // Unset entirely: "no icon" is the key's absence, not an empty string
      // (an empty value would still ship in config and read as configured).
      $block_type->unsetThirdPartySetting('daedalus_layout', 'icon');
    }
    BlockTypeFace::setFavorite($block_type, (bool) $form_state->getValue(self::FAVORITE_CONTROL));
    // The groups control renders only when there is something to show; an
    // absent control leaves the stored list alone (which is then empty).
    if ($form_state->hasValue(self::GROUPS_CONTROL)) {
      $groups = array_values(Checkboxes::getCheckedCheckboxes($form_state->getValue(self::GROUPS_CONTROL)));
      if ($groups !== []) {
        $block_type->setThirdPartySetting('daedalus_layout', 'groups', $groups);
      }
      else {
        $block_type->unsetThirdPartySetting('daedalus_layout', 'groups');
      }
    }
  }

}
