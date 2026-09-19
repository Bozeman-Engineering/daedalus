<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Plugin\Sidebar;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\SidebarPluginBase;

/**
 * The entity-form panel: the subject's cached form, as first-class chrome.
 *
 * The panel has a SUBJECT — the entity whose form it shows — and its default
 * subject is the page's top-level entity. The toggle button (the icon this
 * plugin contributes to the Edit Mode top bar) is deliberately dumb: it
 * toggles the panel on the top-level entity's form under any tool,
 * regardless of the current selection. Form-path edits retarget the subject
 * (the Edit tool's engine routes every form-edited leaf through the panel),
 * the subject reverts to the top-level entity when it stops existing, and
 * blueprint edits never touch the panel at all. The subject model itself is
 * client-side (daedalus-edit-sidebar.js, the 'daedalus_edit_form' JS sidebar
 * plugin); this build is the panel shell plus the form-cache mount that
 * AJAX-fetched entity forms append into.
 *
 * Slot bottom: the transient right-rail panels share the bottom slot so the
 * persistent Styles inspector keeps the top — style and edit coexist, which
 * is the real workflow. First-class identity (a plugin, an icon, a slot) is
 * what graduates the form panel; the slot encodes coexistence, not rank.
 */
#[Sidebar(
  id: 'daedalus_edit_entity_form',
  label: new TranslatableMarkup('Page form'),
  side: 'right',
  weight: 3,
  sidebar_type: 'daedalus_edit_form',
  mode: 'edit',
  slot: 'bottom',
)]
final class EntityFormSidebar extends SidebarPluginBase {

  use ButtonBuilderTrait;

  /**
   * The panel's DOM id (the plugin id, per the sidebar identity rule).
   */
  const PANEL_ID = 'daedalus_edit_entity_form';

  /**
   * The form-cache mount's DOM id.
   *
   * The panel's open contract: every AJAX-fetched entity form (the wrapped
   * form DaedalusEditFormTrait::wrapForm() builds) is appended into this mount
   * and cached there for reuse; the client-side subject model decides which
   * cached form is the one shown.
   */
  const FORM_CACHE_ID = 'daedalus-edit-form-cache';

  /**
   * The pinned header title's DOM id.
   *
   * The title is per-subject but the header pins while the form scrolls, so
   * the text lives in panel chrome, not in the cached forms: the client-side
   * subject model writes it from the subject form's
   * data-daedalus-edit-form-title on every subject change or revert.
   */
  const TITLE_ID = 'daedalus-edit-form-panel-title';

  /**
   * {@inheritdoc}
   *
   * Always present wherever Edit Mode renders (the mode binding gates the
   * panel): the form path must be able to land a form on any editable page,
   * including surfaces where the main entity is not derivable from the
   * route alone, and an empty hidden panel costs nothing.
   */
  public function applies(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      // Chrome contract with the armed edit session's press decision: a
      // press anywhere in the form panel keeps the current edit open.
      // buildSidebarPanel() merges this onto the panel root.
      '#attributes' => [
        'data-keeps-edit' => '',
      ],
      // The shared header grammar (SidebarPluginBase::buildPanelHeader): a
      // pinned header whose title element the client subject model owns
      // (so the h3 is passed as a render array to keep its id), no actions
      // of its own, and the close control the grammar appends; the
      // form-cache mount is the scrolling body.
      'header' => $this->buildPanelHeader([
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => [
          'id' => self::TITLE_ID,
        ],
      ]),
      'forms' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => self::FORM_CACHE_ID,
          'class' => ['daedalus-ui-panel__body'],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-edit-entity-form-toggle',
      $this->t('Page form'),
      $this->t("Edit the page's content in a form"),
      'daedalus_edit',
      'form',
      ['data-sidebar-button-for' => '#' . self::PANEL_ID],
    );
  }

}
