<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Element;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\daedalus_layout\IconPickerOpener;
use Drupal\media_library\MediaLibraryUiBuilder;
use Drupal\daedalus_blueprint\IconResolver;

/**
 * A form element for choosing an icon: the icon grammar's picker.
 *
 * The element's value is ONE string in the icon value grammar
 * (see \Drupal\daedalus_blueprint\IconResolver): `media:<uuid>` (written by
 * the media library pick) or a root-relative path (the shipped lane —
 * values migrated from configs pass through this element untouched when
 * the user saves without re-picking). Empty means "no icon": the consumer
 * renders its default icon.
 *
 * Structure: a hidden input carries the canonical value; a preview shows
 * the resolved icon (the element's #default_icon when unset); "Choose
 * icon" opens the media library as a core AJAX modal via a server-built
 * media_library.ui URL (IconPickerOpener); "Remove" clears back to the
 * default. Without the media_library module the element degrades to a
 * textfield holding the raw grammar value.
 *
 * Properties:
 * - #default_value: the stored icon value (grammar string) or NULL.
 * - #host: the config entity whose icon is edited (the opener's access
 *   subject). Without a saved host the element degrades to the textfield.
 * - #default_icon: root-relative path previewed when no icon is set.
 *   Defaults to daedalus_layout's default block icon.
 *
 * Usage:
 * @code
 * $form['icon'] = [
 *   '#type' => 'daedalus_layout_icon_picker',
 *   '#title' => $this->t('Icon'),
 *   '#default_value' => $type->getThirdPartySetting('daedalus_layout', 'icon'),
 *   '#host' => $type,
 * ];
 * @endcode
 *
 * @see \Drupal\daedalus_layout\IconPickerOpener
 * @see \Drupal\daedalus_blueprint\IconResolver
 */
#[FormElement('daedalus_layout_icon_picker')]
class IconPicker extends FormElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#input' => TRUE,
      '#tree' => TRUE,
      '#default_value' => NULL,
      '#host' => NULL,
      '#default_icon' => NULL,
      '#process' => [
        [$class, 'processIconPicker'],
      ],
      '#theme_wrappers' => ['form_element'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input !== FALSE && $input !== NULL) {
      $value = trim((string) $input);
      return $value === '' ? NULL : $value;
    }
    $default = trim((string) ($element['#default_value'] ?? ''));
    return $default === '' ? NULL : $default;
  }

  /**
   * Builds the picker: preview, hidden value input, choose/remove buttons.
   */
  public static function processIconPicker(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    // FormBuilder substitutes '' for a NULL element value (its final
    // default-value catch), so normalize back: unset is NULL here.
    $value = trim((string) $element['#value']);
    $value = $value === '' ? NULL : $value;
    // Deterministic across rebuilds: the opener's selection response targets
    // this key, and the media library modal outlives any one form build.
    $key = implode('--', str_replace('_', '-', $element['#parents']));

    $cache_tags = [];
    /** @var \Drupal\daedalus_blueprint\IconResolver $resolver */
    $resolver = \Drupal::service(IconResolver::class);
    $url = $resolver->resolve($value, $cache_tags);
    $element['#cache']['tags'] = array_merge($element['#cache']['tags'] ?? [], $cache_tags);

    $default_icon = $element['#default_icon']
      ?? base_path() . \Drupal::service('extension.list.module')->getPath('daedalus_layout') . '/assets/default-block-icon.svg';

    // The media library lane needs the opener (media_library installed) and
    // a saved host to check access against.
    $library_url = NULL;
    $host = $element['#host'];
    if ($host && \Drupal::hasService(IconPickerOpener::SERVICE_ID)) {
      /** @var \Drupal\daedalus_layout\IconPickerOpener $opener */
      $opener = \Drupal::service(IconPickerOpener::SERVICE_ID);
      $library_url = $opener->buildUrl($host, $key, self::selectedMediaTypeId($value));
    }

    $element['picker'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => array_filter([
          'daedalus-layout-icon-picker',
          $value !== NULL ? 'has-icon' : NULL,
        ]),
        'data-daedalus-layout-icon-picker' => $key,
      ],
      '#attached' => [
        'library' => ['daedalus_layout/icon_picker'],
      ],
    ];
    $element['picker']['preview'] = [
      '#type' => 'html_tag',
      '#tag' => 'img',
      '#attributes' => [
        'class' => ['daedalus-layout-icon-picker__preview'],
        'src' => $url ?? $default_icon,
        'alt' => '',
        'data-default-icon' => $default_icon,
      ],
    ];

    if ($library_url) {
      // The canonical value rides a raw hidden input deliberately outside
      // FormAPI's input pipeline: the ELEMENT is the input (flat value at
      // its own #parents), so consumers read one scalar and no child write
      // competes with the element's value callback.
      $element['picker']['value'] = [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'hidden',
          'name' => self::inputName($element['#parents']),
          'value' => $value ?? '',
          'data-daedalus-layout-icon-picker-value' => $key,
        ],
      ];
      // A plain anchor (not '#type' => 'link') because the opener's URL is
      // already generated, query string and hash included; round-tripping it
      // through a Url object would re-parse a finished URL.
      $element['picker']['open'] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => t('Choose icon'),
        '#attributes' => [
          'href' => $library_url,
          'class' => ['button', 'use-ajax', 'daedalus-layout-icon-picker__open'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode(MediaLibraryUiBuilder::dialogOptions()),
        ],
        '#attached' => [
          'library' => ['core/drupal.dialog.ajax'],
        ],
      ];
      // Visibility is inline style (mirrored by icon-picker.js): the admin
      // theme layers module CSS (@layer), so a class-driven display rule
      // would lose to the theme's unlayered .button styles.
      $element['picker']['remove'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => t('Remove'),
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'daedalus-layout-icon-picker__remove'],
        ] + ($value === NULL ? ['style' => 'display:none'] : []),
      ];
    }
    else {
      // No media library lane (module absent or unsaved host): degrade to
      // the raw grammar value, still one flat scalar at the element parents.
      $element['picker']['value'] = [
        '#type' => 'textfield',
        '#title' => t('Icon value'),
        '#title_display' => 'invisible',
        '#parents' => $element['#parents'],
        '#default_value' => $value,
        '#description' => t('A root-relative path (e.g. <code>/modules/contrib/daedalus/modules/daedalus_layout/assets/block.svg</code>) or <code>media:&lt;uuid&gt;</code>.'),
        '#attributes' => [
          'data-daedalus-layout-icon-picker-value' => $key,
        ],
      ];
    }

    return $element;
  }

  /**
   * The media type of the currently referenced media, for the library tab.
   */
  protected static function selectedMediaTypeId(?string $value): ?string {
    if ($value === NULL || !str_starts_with($value, IconResolver::MEDIA_PREFIX)) {
      return NULL;
    }
    $uuid = strtolower(substr($value, strlen(IconResolver::MEDIA_PREFIX)));
    $media = \Drupal::service('entity.repository')->loadEntityByUuid('media', $uuid);
    return $media?->bundle();
  }

  /**
   * The HTML input name for a #parents lineage.
   */
  protected static function inputName(array $parents): string {
    $root = array_shift($parents);
    return $parents ? $root . '[' . implode('][', $parents) . ']' : $root;
  }

}
