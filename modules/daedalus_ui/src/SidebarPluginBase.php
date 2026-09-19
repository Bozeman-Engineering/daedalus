<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for sidebar plugins.
 */
abstract class SidebarPluginBase extends PluginBase implements SidebarInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return [];
  }

  /**
   * Builds the panel header: the one header grammar every rail panel wears.
   *
   * The row is the shared .daedalus-ui-panel__header anatomy (title left, action
   * cluster right; panel-controls.css owns the look, sidebar.css the rail
   * anatomy). The title is an h3 built from a string, or the given render
   * array when a plugin needs its own heading element (an id the client
   * writes into, a local class). The .daedalus-ui-panel__actions cluster holds the
   * plugin's actions in the order given, followed always by the close
   * control, so every panel closes from the same corner.
   *
   * @param array|string|\Drupal\Core\StringTranslation\TranslatableMarkup $title
   *   The title text, or a render array for the heading element.
   * @param array $actions
   *   Render arrays for the header's actions, keyed; 'close' is reserved
   *   for the control this grammar appends.
   *
   * @return array
   *   The header render array.
   */
  protected function buildPanelHeader(array|string|\Stringable $title, array $actions = []): array {
    $title_element = is_array($title) ? $title : [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $title,
    ];
    $actions['close'] = $this->buildPanelClose();
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-ui-panel__header']],
      'title' => $title_element,
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['daedalus-ui-panel__actions']],
      ] + $actions,
    ];
  }

  /**
   * Builds the panel's close control.
   *
   * A kit icon button carrying data-sidebar-close: the sidebar-close
   * behavior (daedalus_ui/sidebar) resolves the panel it sits in and
   * closes it through SidebarManager.closeSidebar(), the same door the
   * top-bar toggles use (cookie, the plugin's own close(), displace). It
   * conceals at clean density only: a close that vanished at minimal would
   * be a discoverability trap. The glyph is the pack's minus, rendered the
   * way every top-bar button renders its icon.
   *
   * @return array
   *   The close control render array.
   */
  private function buildPanelClose(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      // Always last in the cluster, whatever weights a plugin's actions carry.
      '#weight' => 1000,
      '#attributes' => [
        'type' => 'button',
        'class' => ['daedalus-ui-panel__close', 'daedalus-ui-icon-button', 'daedalus-ui-icon-button--muted'],
        'data-sidebar-close' => '',
        'data-daedalus-ui-conceal' => 'clean',
        'aria-label' => $this->t('Close @panel', ['@panel' => $this->label()]),
        'data-daedalus-ui-tooltip' => $this->t('Close'),
      ],
      'icon' => [
        '#type' => 'inline_template',
        '#template' => "{{ icon('daedalus_ui', 'minus', { class: 'daedalus-ui-panel__close-icon', size: 16 }) }}",
      ],
    ];
  }

}
