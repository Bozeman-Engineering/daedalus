<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint_stencil\Exception\StencilExportException;
use Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException;
use Drupal\daedalus_blueprint_stencil\StencilInterface;
use Drupal\daedalus_layout\IconPickerOpener;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryUiBuilder;
use Drupal\daedalus_blueprint\IconResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the Save as stencil…, Update stencil, and Edit details… dialogs.
 *
 * All three dialogs are inert markup, the Change layout chooser pattern:
 * the client owns the interaction, so no form submission and no
 * controller-owned mutation. The commit button's data attribute is how
 * the client listeners stay apart:
 * - Save as stencil… (js/verbs/stencil.js) collects the label and
 *   description a stencil requires and submits the save_material spine op
 *   (kind stencil) through Blueprint.mutate; its buttons wear
 *   data-stencil-save / data-stencil-cancel.
 * - Update stencil (js/verbs/stencil.js) is the push gesture's confirm
 *   step; no fields of its own (details editing is the Add panel's dialog),
 *   its commit button wears data-stencil-update and submits the
 *   update_material op.
 * - Edit details… (js/add-panel/stencil-items.js) shares the save dialog's
 *   fields, prefills the current values client-side and POSTs to
 *   daedalus_blueprint_stencil's CSRF-protected details route; its buttons
 *   wear data-stencil-details-save / data-stencil-details-cancel, and its
 *   wrapper carries daedalus-layout-stencil-details as the prefill guard.
 *
 * The dialogs carry no stylesheet of their own: they speak core's shared
 * form vocabulary (form-item, form-text/form-textarea, description,
 * form-actions) and the one dialog skin (daedalus_ui's cosmetic tier
 * in css/theme/dialog-skin.css) styles that vocabulary for every dialog.
 * The daedalus-layout-stencil-save root is a JS hook, not styling. The dialogs
 * render no message area: a refused gesture reports through the one
 * edit-mode message display, and input validity lives at the field (the
 * inputs carry `required`; the scripts call reportValidity()).
 *
 * The save and details dialogs additionally offer an ICON picker where the
 * media library is installed: the same markup contract as the
 * daedalus_layout_icon_picker form element (IconPickerOpener writes the picked
 * `media:<uuid>` into the hidden input, icon-picker.js refreshes the
 * preview), hand-composed here because the dialogs are not FormAPI forms.
 * The client folds the picker's value into the gesture: save_material's
 * optional `icon`, the details route's presence-semantics `icon` key. The
 * update dialog has NO picker: the icon is edited in the Add panel's
 * details dialog, the palette face's home. The gestures
 * speak only the media spelling, and the picker can only produce
 * `media:<uuid>` or empty, so the two contracts meet by construction.
 *
 * The save and update dialogs additionally offer ADOPTION where daedalus_stylish
 * and the stencil module are installed: the adoptable style variants the
 * captured subtree references render as checkboxes (checked by default,
 * data-stencil-adopt), and the client folds the picked ones into the
 * gesture op's adopt_variants — they become the stencil's own, offered
 * only on its placements. The update dialog also lists what the stencil
 * ALREADY owns as an inert roster (ownership query, both lanes), so
 * adopted-but-unworn looks stay visible. Still inert markup: the query
 * names the element and host entity, the server only reads.
 *
 * All three routes require the same "save stencils" permission the
 * gestures enforce; without it none of the entry gestures render either
 * (honest chrome).
 */
class StencilSaveDialog implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The daedalus_stylish variant manager, or NULL without daedalus_stylish.
   *
   * Untyped on purpose: the class lives in a module daedalus_stylish is not a
   * dependency of.
   *
   * @var \Drupal\daedalus_stylish\VariantManager|null
   */
  protected $variantManager;

  /**
   * The stencil manager, or NULL without daedalus_blueprint_stencil.
   *
   * @var \Drupal\daedalus_blueprint_stencil\StencilManager|null
   */
  protected $stencilManager;

  /**
   * The stencil exporter, or NULL without daedalus_blueprint_stencil.
   *
   * @var \Drupal\daedalus_blueprint_stencil\StencilExporterInterface|null
   */
  protected $stencilExporter;

  /**
   * The icon picker opener, or NULL without media_library.
   *
   * @var \Drupal\daedalus_layout\IconPickerOpener|null
   */
  protected $iconPickerOpener;

  public function __construct(
    $variant_manager,
    $stencil_manager,
    $stencil_exporter,
    $icon_picker_opener,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IconResolver $iconResolver,
    protected ModuleExtensionList $moduleExtensionList,
  ) {
    $this->variantManager = $variant_manager;
    $this->stencilManager = $stencil_manager;
    $this->stencilExporter = $stencil_exporter;
    $this->iconPickerOpener = $icon_picker_opener;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $optional = static fn (string $id) => $container->has($id) ? $container->get($id) : NULL;
    return new static(
      $optional('daedalus_stylish.variant_manager'),
      $optional('daedalus_blueprint_stencil.manager'),
      $optional('daedalus_blueprint_stencil.exporter'),
      $optional(IconPickerOpener::SERVICE_ID),
      $container->get('entity_type.manager'),
      $container->get('daedalus_blueprint.icon_resolver'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Builds the Save as stencil dialog contents.
   *
   * @return array
   *   A render array of the save-as-stencil inputs and actions.
   */
  public function build(Request $request): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-stencil-save']],
      'intro' => $this->intro($this->t("Save this element as a reusable stencil, one of the site's materials. It appears in the Add panel for every editor; placing it stamps a fresh copy.")),
    ] + $this->fields($this->iconField($this->hostEntity($request), NULL, 'stencil-save-icon')) + $this->adoption($request) + [
      'actions' => $this->actions('data-stencil-save', 'data-stencil-cancel', $this->t('Save stencil')),
    ];
  }

  /**
   * Builds the Update stencil dialog contents.
   *
   * The push gesture's confirm step, grown into the save dialog's
   * inert-markup pattern for adoption PARITY: update is when new looks
   * usually arrive on a stencil, so the same checked data-stencil-adopt
   * checkboxes offer the capture's unowned variants and the client folds
   * the picks into the update_material op's adopt_variants. No fields:
   * update pushes the composed element, not the label/description (the
   * Add panel's Edit details… dialog owns those).
   *
   * Update additionally shows the stencil's CURRENT owned roster — the
   * looks it already offers — as inert read-only display (the save dialog
   * has no roster: a stencil being born owns nothing).
   *
   * @return array
   *   A render array of the update confirm copy, owned roster, adoption,
   *   and actions.
   */
  public function update(Request $request): array {
    $stencil = $this->updateTarget($request);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-stencil-save']],
      'intro' => $this->intro($this->updateIntro($stencil)),
    ] + $this->ownedRoster($stencil) + $this->adoption($request) + [
      'actions' => $this->actions('data-stencil-update', 'data-stencil-cancel', $this->t('Update stencil')),
    ];
  }

  /**
   * Resolves the update dialog's target stencil, or NULL.
   *
   * The query's `stencil` reference (machine name or uuid) resolves
   * through the same resolver the op uses. Resolution failure degrades to
   * NULL — the dialog is chrome (the intro just doesn't name the stencil,
   * the owned roster doesn't render), and a stale reference fails loudly
   * through the update_material op on commit (same posture as the adoption
   * section's capture read).
   *
   * @return \Drupal\daedalus_blueprint_stencil\StencilInterface|null
   *   The target content stencil, or NULL when it cannot resolve.
   */
  protected function updateTarget(Request $request): ?StencilInterface {
    $reference = $request->query->get('stencil');
    if ($this->stencilManager !== NULL && is_string($reference) && $reference !== '') {
      try {
        return $this->stencilManager->resolveUpdatable($reference);
      }
      catch (StencilGestureException) {
      }
    }
    return NULL;
  }

  /**
   * The update dialog's confirm copy, naming the target when it resolved.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The intro copy.
   */
  protected function updateIntro(?StencilInterface $stencil): TranslatableMarkup {
    if ($stencil !== NULL) {
      return $this->t('Update the stencil "@label" with this element? Future placements use the updated version; existing placements keep what they have.', ['@label' => $stencil->label()]);
    }
    return $this->t('Update this stencil with the selected element? Future placements use the updated version; existing placements keep what they have.');
  }

  /**
   * Builds the Edit stencil details dialog contents.
   *
   * The same fields as the save dialog (the Add panel menu's client script
   * prefills them with the stencil's current label and description); the
   * extra wrapper class is the prefill guard that keeps the save-as
   * dialog opening empty. The icon picker is the exception to client
   * prefill: the query's `stencil` reference resolves server-side (the
   * update dialog's resolver) so the picker opens showing the stored icon,
   * with the stencil itself as the opener's access subject. An
   * unresolvable reference just renders no picker — the client then omits
   * the icon key and the stored icon stands.
   *
   * @return array
   *   A render array of the edit-details inputs and actions.
   */
  public function details(Request $request): array {
    $stencil = $this->updateTarget($request);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-stencil-save', 'daedalus-layout-stencil-details']],
      'intro' => $this->intro($this->t('Rename this stencil or rewrite its description. The Add panel and the AI read both; existing placements keep what they have.')),
    ] + $this->fields($this->iconField($stencil, $stencil?->getIcon(), 'stencil-details-icon')) + [
      'actions' => $this->actions('data-stencil-details-save', 'data-stencil-details-cancel', $this->t('Save details')),
    ];
  }

  /**
   * The dialog's intro paragraph.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The intro copy.
   *
   * @return array
   *   The intro render array.
   */
  protected function intro($text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $text,
    ];
  }

  /**
   * The label/description inputs.
   *
   * The ids are the client contract both dialog scripts read values
   * through; only one stencil dialog is ever open at a time, so sharing
   * them is safe. Both inputs carry `required`: the scripts' precheck is
   * reportValidity() on them, so the browser's own message shows at the
   * field.
   *
   * @param array $icon_field
   *   (optional) The icon picker section (see iconField()), rendered
   *   after the description, or [] for none.
   *
   * @return array
   *   The field render arrays, keyed label_field/description_field (plus
   *   icon_field when given).
   */
  protected function fields(array $icon_field = []): array {
    return [
      'label_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['for' => 'daedalus-layout-stencil-label'],
          '#value' => $this->t('Name'),
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'type' => 'text',
            // Core's form-control class: the dialog skin styles this
            // shared vocabulary, not bespoke selectors.
            'class' => ['form-text'],
            'id' => 'daedalus-layout-stencil-label',
            'maxlength' => '255',
            'required' => 'required',
            'placeholder' => $this->t('How editors find it in the Add panel'),
          ],
        ],
      ],
      'description_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['for' => 'daedalus-layout-stencil-description'],
          '#value' => $this->t('Description'),
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'textarea',
          '#attributes' => [
            'class' => ['form-textarea'],
            'id' => 'daedalus-layout-stencil-description',
            'rows' => '3',
            'required' => 'required',
            'placeholder' => $this->t('What it is and when to reach for it; the AI reads this when deciding what to place'),
          ],
          // A void #value renders <textarea/> and breaks the DOM; an empty
          // string keeps the element properly closed.
          '#value' => '',
        ],
      ],
    ] + $icon_field;
  }

  /**
   * The icon picker section: the daedalus_layout_icon_picker markup, dialog-composed.
   *
   * The dialogs are inert markup, not FormAPI forms, so the picker's
   * contract is hand-composed here instead of rendering the form element:
   * the same wrapper/preview/hidden-input/open/remove structure
   * IconPicker::processIconPicker() builds, driven by the same
   * icon-picker.js behavior and IconPickerOpener selection response. The
   * hidden input is the value the dialog scripts read; it only ever holds
   * `media:<uuid>` (a pick) or '' (unset/removed), which is exactly the
   * spelling the stencil gestures accept.
   *
   * Renders nothing when the opener is absent (no media_library), the host
   * cannot be resolved, or no visual media types exist — the dialogs stay
   * on their two-field contract and the client omits the icon key.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $host
   *   The opener's access subject: the entity being laid out for the save
   *   dialog, the stencil itself for the details dialog.
   * @param \Drupal\media\MediaInterface|null $icon
   *   The currently referenced icon media, or NULL when unset.
   * @param string $picker_key
   *   The picker instance key the opener's selection response targets.
   *
   * @return array
   *   A render array keyed 'icon_field', or [] when no picker can render.
   */
  protected function iconField(?EntityInterface $host, ?MediaInterface $icon, string $picker_key): array {
    if ($this->iconPickerOpener === NULL || $host === NULL) {
      return [];
    }
    $library_url = $this->iconPickerOpener->buildUrl($host, $picker_key, $icon?->bundle());
    if ($library_url === NULL) {
      return [];
    }

    $value = $icon !== NULL ? IconResolver::MEDIA_PREFIX . strtolower($icon->uuid()) : NULL;
    $cache_tags = [];
    $url = $this->iconResolver->resolve($value, $cache_tags);
    $default_icon = base_path() . $this->moduleExtensionList->getPath('daedalus_layout') . '/assets/default-block-icon.svg';

    return [
      'icon_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        '#cache' => ['tags' => $cache_tags],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Icon'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t('Shown on the Add panel item; leave empty for the default icon.'),
        ],
        'picker' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => array_filter([
              'daedalus-layout-icon-picker',
              $value !== NULL ? 'has-icon' : NULL,
            ]),
            'data-daedalus-layout-icon-picker' => $picker_key,
          ],
          '#attached' => [
            'library' => ['daedalus_layout/icon_picker', 'core/drupal.dialog.ajax'],
          ],
          'preview' => [
            '#type' => 'html_tag',
            '#tag' => 'img',
            '#attributes' => [
              'class' => ['daedalus-layout-icon-picker__preview'],
              'src' => $url ?? $default_icon,
              'alt' => '',
              'data-default-icon' => $default_icon,
            ],
          ],
          // No name attribute: nothing submits this input, the dialog
          // scripts read .value and fold it into the gesture payload.
          'value' => [
            '#type' => 'html_tag',
            '#tag' => 'input',
            '#attributes' => [
              'type' => 'hidden',
              'value' => $value ?? '',
              'data-daedalus-layout-icon-picker-value' => $picker_key,
            ],
          ],
          'open' => [
            '#type' => 'html_tag',
            '#tag' => 'a',
            '#value' => $this->t('Choose icon'),
            '#attributes' => [
              'href' => $library_url,
              'class' => ['button', 'use-ajax', 'daedalus-layout-icon-picker__open'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode(MediaLibraryUiBuilder::dialogOptions()),
            ],
          ],
          // Visibility is inline style (mirrored by icon-picker.js): the
          // admin theme layers module CSS (@layer), so a class-driven
          // display rule would lose to the theme's unlayered .button styles.
          'remove' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Remove'),
            '#attributes' => [
              'type' => 'button',
              'class' => ['button', 'daedalus-layout-icon-picker__remove'],
            ] + ($value === NULL ? ['style' => 'display:none'] : []),
          ],
        ],
      ],
    ];
  }

  /**
   * Resolves the save dialog's host entity off the query, or NULL.
   *
   * The same entity_type/entity_id coordinates the adoption section rides
   * (the entity being laid out): the opener offers the media library to
   * whoever may update it, the same person composing the layout. Without
   * coordinates (or an unknown type) the save dialog renders no picker.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The host entity, or NULL when it cannot resolve.
   */
  protected function hostEntity(Request $request): ?EntityInterface {
    $entity_type = $request->query->get('entity_type');
    $entity_id = $request->query->get('entity_id');
    if (!is_string($entity_type) || !is_string($entity_id) || $entity_id === '' || !$this->entityTypeManager->hasDefinition($entity_type)) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
  }

  /**
   * The owned roster: the stencil's current looks, inert display.
   *
   * The update dialog's honesty line: every variant the stencil already
   * owns (either lane), including adopted-but-unworn looks the capture
   * walk would never surface — the roster comes from the spine's ownership
   * query, not from the capture. Read-only BY DESIGN, not laziness: the
   * dialog commits through the op lane, while release is deliberately a
   * CSRF route the op spine cannot reach, so an uncheck-to-release here
   * would compose a route call with an op non-atomically. Release stays
   * the Materials card's gesture.
   *
   * @return array
   *   A render array keyed 'owned', or [] when the stencil owns nothing
   *   (or did not resolve).
   */
  protected function ownedRoster(?StencilInterface $stencil): array {
    if ($stencil === NULL) {
      return [];
    }
    $variants = [];
    foreach ($this->stencilManager->ownedVariants($stencil) as $variant) {
      $variants[] = [
        'machine_name' => (string) $variant->get('machine_name')->value,
        'label' => (string) $variant->label(),
      ];
    }
    if ($variants === []) {
      return [];
    }
    return [
      'owned' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Already its own'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t('The looks this stencil offers on its placements. Release or transfer them from Materials in the style panel.'),
        ],
        // A plain list: the dialog skin's bare-element guards style it,
        // no bespoke classes.
        'items' => [
          '#type' => 'inline_template',
          '#template' => '<ul>{% for variant in variants %}<li>{{ variant.label }}</li>{% endfor %}</ul>',
          '#context' => ['variants' => $variants],
        ],
      ],
    ];
  }

  /**
   * The adoption section: the capture's adoptable variants as checkboxes.
   *
   * Shared by the save and update dialogs (adoption parity): the variants
   * the captured subtree references, minus what the spine would refuse to
   * adopt (the dialogs curate what they LIST; the filter is honesty, the
   * refusal lives in StencilManager::adoptVariants()). Checked by default
   * — Tim's real flow mints the looks BEFORE claiming them, at save or at
   * the update that follows a new look. Empty (and the dialog renders
   * without the section) when the cross-module services are absent, the
   * query carries no element context, or the capture references nothing
   * adoptable.
   *
   * @return array
   *   A render array keyed 'adopt', or [] when there is nothing to offer.
   */
  protected function adoption(Request $request): array {
    $variants = $this->adoptableVariants($request);
    if ($variants === []) {
      return [];
    }
    $items = [];
    foreach ($variants as $machine_name => $label) {
      $items[] = [
        '#type' => 'inline_template',
        // A label wrapping a checkbox: the dialog skin's inline choice
        // row, no class needed.
        '#template' => '<label><input type="checkbox" checked data-stencil-adopt="{{ machine_name }}"/> {{ label }}</label>',
        '#context' => ['machine_name' => $machine_name, 'label' => $label],
      ];
    }
    return [
      'adopt' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Own its style variants'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t("Checked variants become this stencil's own: offered only on its placements, traveling with it among the site's materials."),
        ],
        'items' => $items,
      ],
    ];
  }

  /**
   * The adoptable variants the captured subtree references.
   *
   * The same capture the gesture op will run (the exporter is the ONE
   * capture walk), read-only. A capture failure renders the dialog without
   * the section: the gesture itself will fail loudly through the op; the
   * dialog's job is only to offer what a successful capture would
   * reference. Adoptable mirrors what adoptVariants() accepts: shipped
   * (disk-lane) variants never list (adoption would be irreversible by
   * gesture), and neither does a variant owned on EITHER lane (one owner
   * max; an update's capture wearing looks the stencil already owns has
   * nothing to adopt — they show in the owned roster instead).
   *
   * @return array<string, string>
   *   machine name => label, in capture order.
   */
  protected function adoptableVariants(Request $request): array {
    if ($this->variantManager === NULL || $this->stencilManager === NULL || $this->stencilExporter === NULL) {
      return [];
    }
    $element = $request->query->get('element');
    if (!is_string($element) || $element === '') {
      return [];
    }
    $entity_type = $request->query->get('entity_type');
    $entity_id = $request->query->get('entity_id');
    $entity = $this->variantManager->resolveEntity(
      is_string($entity_type) ? $entity_type : NULL,
      is_string($entity_id) ? $entity_id : NULL,
    );
    if ($entity === NULL) {
      return [];
    }
    try {
      $export = $this->stencilExporter->export($entity, $element);
    }
    catch (StencilExportException) {
      return [];
    }
    $variants = [];
    foreach ($this->stencilManager->referencedVariants($export->definition['element'] ?? []) as $reference) {
      $variant = $this->variantManager->loadVariant($reference);
      if ($variant === NULL || $variant->isShipped() || !$variant->get('stencil')->isEmpty() || !$variant->get('stencil_file')->isEmpty()) {
        continue;
      }
      $variants[$variant->getMachineName()] = (string) $variant->label();
    }
    return $variants;
  }

  /**
   * The dialog's commit/cancel buttons.
   *
   * @param string $save_attribute
   *   The commit button's data attribute (the client listener's hook).
   * @param string $cancel_attribute
   *   The cancel button's data attribute.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $save_label
   *   The commit button's label.
   *
   * @return array
   *   The actions render array.
   */
  protected function actions(string $save_attribute, string $cancel_attribute, $save_label): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
      'save' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['button', 'button--primary'],
          $save_attribute => '',
        ],
        '#value' => $save_label,
      ],
      'cancel' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['button'],
          $cancel_attribute => '',
        ],
        '#value' => $this->t('Cancel'),
      ],
    ];
  }

}
