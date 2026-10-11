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
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_layout\IconPickerOpener;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryUiBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\IconResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Renders the Save as blueprint…, Update blueprint, and Edit details… dialogs.
 *
 * All three dialogs are inert markup, the Change layout chooser pattern:
 * the client owns the interaction, so no form submission and no
 * controller-owned mutation. The commit button's data attribute is how
 * the client listeners stay apart:
 * - Save as blueprint… (js/verbs/blueprint.js) collects the label and
 *   description a blueprint requires and submits the save_material spine
 *   op (kind blueprint) through Blueprint.mutate; its buttons wear
 *   data-blueprint-save / data-blueprint-cancel. The verb offers itself
 *   on containers only: a blueprint's root is always a container. The
 *   same dialog serves Save page as blueprint… (the root verb behind the
 *   top bar's Save menu): the query's element is then the host's own
 *   uuid, and build() words the dialog for a page-scope capture.
 * - Update blueprint (js/verbs/blueprint.js) is the push gesture's confirm
 *   step; no fields of its own (details editing is the Add panel's
 *   dialog), its commit button wears data-blueprint-update and submits
 *   the update_material op.
 * - Edit details… (js/src/blueprint-gestures.js) shares the save
 *   dialog's label and description fields (prefilled client-side from the
 *   record) and adds what the blueprint is material for: the scope shown
 *   read-only (identity, never edited), the subject as a select over the
 *   site's page-builder bundles and the allow-list as checkboxes over the
 *   same bundles, both prefilled server-side from the resolved blueprint.
 *   It POSTs to daedalus_blueprint_library's CSRF-protected details
 *   route; its buttons wear data-blueprint-details-save /
 *   data-blueprint-details-cancel, and its wrapper carries
 *   daedalus-layout-blueprint-details as the prefill guard.
 *
 * The dialogs carry no stylesheet of their own: they speak core's shared
 * form vocabulary (form-item, form-text/form-textarea/form-select,
 * description, form-actions) and the one dialog skin (daedalus_ui's
 * cosmetic tier in css/theme/dialog-skin.css) styles that vocabulary for
 * every dialog. The daedalus-layout-blueprint-save root is a JS hook, not
 * styling. The dialogs render no message area: a refused gesture reports
 * through the one edit-mode message display, and input validity lives at
 * the field (the inputs carry `required`; the scripts call
 * reportValidity()).
 *
 * The save and details dialogs additionally offer an ICON picker where the
 * media library is installed: the same markup contract as the
 * daedalus_layout_icon_picker form element (IconPickerOpener writes the
 * picked `media:<uuid>` into the hidden input, icon-picker.js refreshes the
 * preview), hand-composed here because the dialogs are not FormAPI forms.
 * The client folds the picker's value into the gesture: save_material's
 * optional `icon`, the details route's presence-semantics `icon` key. The
 * update dialog has NO picker: the icon is edited in the Add panel's
 * details dialog, the palette face's home. The gestures speak only the
 * media spelling, and the picker can only produce `media:<uuid>` or empty,
 * so the two contracts meet by construction.
 *
 * The save and update dialogs additionally offer ADOPTION where
 * daedalus_stylish and the library module are installed: the adoptable
 * style variants the captured subtree references render as checkboxes
 * (checked by default, data-blueprint-adopt), and the client folds the
 * picked ones into the gesture op's adopt_variants: they become the
 * blueprint's own, offered only on its placements. The update dialog also
 * lists what the blueprint ALREADY owns as an inert roster (ownership
 * query, both lanes), so adopted-but-unworn looks stay visible. Still
 * inert markup: the query names the element and host entity, the server
 * only reads.
 *
 * All three routes require the same permission the gestures enforce;
 * without it none of the entry gestures render either (honest chrome).
 */
class BlueprintSaveDialog implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * The details dialog's subject select id (the client reads its value).
   */
  public const SUBJECT_INPUT_ID = 'daedalus-layout-blueprint-subject';

  /**
   * The details dialog's allow-list checkbox attribute (the client reads it).
   */
  public const BUNDLE_ATTRIBUTE = 'data-blueprint-bundle';

  /**
   * The details dialog's group checkbox attribute (the client reads it).
   */
  public const GROUP_ATTRIBUTE = 'data-blueprint-group';

  /**
   * The details dialog's favorite checkbox attribute (the client reads it).
   */
  public const FAVORITE_ATTRIBUTE = 'data-blueprint-favorite';

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
   * The blueprint manager, or NULL without daedalus_blueprint_library.
   *
   * @var \Drupal\daedalus_blueprint_library\BlueprintManager|null
   */
  protected $blueprintManager;

  /**
   * The subject vocabulary, or NULL without daedalus_blueprint_library.
   *
   * @var \Drupal\daedalus_blueprint_library\BlueprintSubjects|null
   */
  protected $subjects;

  /**
   * The icon picker opener, or NULL without media_library.
   *
   * @var \Drupal\daedalus_layout\IconPickerOpener|null
   */
  protected $iconPickerOpener;

  public function __construct(
    $variant_manager,
    $blueprint_manager,
    $subjects,
    $icon_picker_opener,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IconResolver $iconResolver,
    protected ModuleExtensionList $moduleExtensionList,
    protected DialectCatalogBuilder $catalogBuilder,
  ) {
    $this->variantManager = $variant_manager;
    $this->blueprintManager = $blueprint_manager;
    $this->subjects = $subjects;
    $this->iconPickerOpener = $icon_picker_opener;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $optional = static fn (string $id) => $container->has($id) ? $container->get($id) : NULL;
    return new static(
      $optional('daedalus_stylish.variant_manager'),
      $optional('daedalus_blueprint_library.manager'),
      $optional('daedalus_blueprint_library.subjects'),
      $optional(IconPickerOpener::SERVICE_ID),
      $container->get('entity_type.manager'),
      $container->get('daedalus_blueprint.icon_resolver'),
      $container->get('extension.list.module'),
      $container->get('daedalus_blueprint.dialect_catalog_builder'),
    );
  }

  /**
   * Builds the Save as blueprint dialog contents.
   *
   * One dialog for both capture verbs, worded by what the query's
   * `element` names, the way the spine reads it (BlueprintManager::
   * capture()): the host's own uuid is the whole document, a page-scope
   * blueprint ("Save page as blueprint…" on the root, from the top bar's
   * Save menu); any other uuid is one container, block scope. The scope
   * renders as the read-only line the details dialog shows for a saved
   * blueprint, so an editor learns the word where the blueprint is born.
   *
   * @return array
   *   A render array of the save-as-blueprint inputs and actions.
   */
  public function build(Request $request): array {
    $host = $this->hostEntity($request);
    $element = $request->query->get('element');
    $scope = $host !== NULL && is_string($element) && $element === $host->uuid()
      ? BlueprintInterface::SCOPE_PAGE
      : BlueprintInterface::SCOPE_BLOCK;
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-blueprint-save']],
      'intro' => $this->intro($scope === BlueprintInterface::SCOPE_PAGE
        ? $this->t("Save this whole page as a reusable page blueprint, one of the site's materials. A page blueprint starts new pages of this kind; it is not offered in the Add panel, and it has a canvas of its own to edit.")
        : $this->t("Save this container as a reusable blueprint, one of the site's materials. It appears in the Add panel for every editor; placing it stamps a fresh copy, and the blueprint has a canvas of its own to edit.")),
    ] + $this->fields($this->iconField($host, NULL, 'blueprint-save-icon', $scope), $scope) + $this->scopeField($scope) + $this->adoption($request) + [
      'actions' => $this->actions('data-blueprint-save', 'data-blueprint-cancel', $this->t('Save blueprint')),
    ];
  }

  /**
   * Builds the Update blueprint dialog contents.
   *
   * The push gesture's confirm step, grown into the save dialog's
   * inert-markup pattern for adoption PARITY: update is when new looks
   * usually arrive on a blueprint, so the same checked data-blueprint-adopt
   * checkboxes offer the capture's unowned variants and the client folds
   * the picks into the update_material op's adopt_variants. No fields:
   * update pushes the composed element, not the label/description (the
   * Add panel's Edit details… dialog owns those).
   *
   * Update additionally shows the blueprint's CURRENT owned roster (the
   * looks it already offers) as inert read-only display (the save dialog
   * has no roster: a blueprint being born owns nothing).
   *
   * @return array
   *   A render array of the update confirm copy, owned roster, adoption,
   *   and actions.
   */
  public function update(Request $request): array {
    $blueprint = $this->updateTarget($request);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-blueprint-save']],
      'intro' => $this->intro($this->updateIntro($blueprint)),
    ] + $this->ownedRoster($blueprint) + $this->adoption($request) + [
      'actions' => $this->actions('data-blueprint-update', 'data-blueprint-cancel', $this->t('Update blueprint')),
    ];
  }

  /**
   * Resolves the update and details dialogs' target blueprint, or NULL.
   *
   * The query's `blueprint` reference (machine name or uuid) resolves
   * through the same resolver the op uses. Resolution failure degrades to
   * NULL: the dialog is chrome (the intro just doesn't name the blueprint,
   * the owned roster and the details' subject section don't render), and a
   * stale reference fails loudly through the op or the route on commit
   * (same posture as the adoption section's capture read).
   *
   * @return \Drupal\daedalus_blueprint_library\BlueprintInterface|null
   *   The target content blueprint, or NULL when it cannot resolve.
   */
  protected function updateTarget(Request $request): ?BlueprintInterface {
    $reference = $request->query->get('blueprint');
    if ($this->blueprintManager !== NULL && is_string($reference) && $reference !== '') {
      try {
        return $this->blueprintManager->resolveUpdatable($reference);
      }
      catch (BlueprintGestureException) {
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
  protected function updateIntro(?BlueprintInterface $blueprint): TranslatableMarkup {
    if ($blueprint !== NULL) {
      return $this->t('Update the blueprint "@label" with this element? Future placements use the updated version; existing placements keep what they have.', ['@label' => $blueprint->label()]);
    }
    return $this->t('Update this blueprint with the selected element? Future placements use the updated version; existing placements keep what they have.');
  }

  /**
   * Builds the Edit blueprint details dialog contents.
   *
   * The save dialog's label and description fields (the Add panel menu's
   * client script prefills them with the blueprint's current values); the
   * extra wrapper class is the prefill guard that keeps the save-as
   * dialog opening empty. What the blueprint is material for is prefilled
   * server-side: the query's `blueprint` reference resolves (the update
   * dialog's resolver) so the icon picker opens showing the stored icon
   * (with the blueprint itself as the opener's access subject), the scope
   * reads as inert text, the subject select carries the current subject
   * and the allow-list checkboxes the current entries, and the favorite
   * checkbox carries the stored flag. An unresolvable reference renders
   * none of those sections: the client then omits their keys and the
   * stored values stand (the details route's presence semantics).
   *
   * @return array
   *   A render array of the edit-details inputs and actions.
   */
  public function details(Request $request): array {
    $blueprint = $this->updateTarget($request);
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-layout-blueprint-save', 'daedalus-layout-blueprint-details']],
      'intro' => $this->intro($this->t('Rename this blueprint, rewrite its description or change what it is material for. The Add panel and the AI read these; existing placements keep what they have.')),
    ] + $this->fields($this->iconField($blueprint, $blueprint?->getIcon(), 'blueprint-details-icon')) + $this->materialFor($blueprint) + $this->favoriteField($blueprint) + [
      'actions' => $this->actions('data-blueprint-details-save', 'data-blueprint-details-cancel', $this->t('Save details')),
    ];
  }

  /**
   * The favorite: the site's default, as one checkbox.
   *
   * Renders only for a resolved blueprint, prefilled with the stored flag
   * (BlueprintInterface::isFavorite()). The client sends the box's state
   * as `favorite` only when the section rendered, so an unresolved
   * reference leaves the stored flag standing (the details route's
   * presence semantics, as the allow-list and the groups). The flag is
   * the site's default: an offering surface lists the blueprint under
   * Favorites for everyone, and each editor keeps their own marks over
   * it. A listing fact and nothing else.
   *
   * @return array
   *   A render array keyed favorite_field, or [] when the blueprint did
   *   not resolve.
   */
  protected function favoriteField(?BlueprintInterface $blueprint): array {
    if ($blueprint === NULL) {
      return [];
    }
    return [
      'favorite_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'input' => [
          '#type' => 'inline_template',
          // A label wrapping a checkbox: the dialog skin's inline choice
          // row (the allow-list's pattern).
          '#template' => '<label><input type="checkbox" ' . self::FAVORITE_ATTRIBUTE . '{% if checked %} checked{% endif %}/> {{ label }}</label>',
          '#context' => ['label' => $this->t('Favorite'), 'checked' => $blueprint->isFavorite()],
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t('Listed under Favorites for everyone where blueprints are offered. Each editor keeps their own favorites over this.'),
        ],
      ],
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
   * through; only one blueprint dialog is ever open at a time, so sharing
   * them is safe. Both inputs carry `required`: the scripts' precheck is
   * reportValidity() on them, so the browser's own message shows at the
   * field.
   *
   * @param array $icon_field
   *   (optional) The icon picker section (see iconField()), rendered
   *   after the description, or [] for none.
   * @param string $scope
   *   (optional) The blueprint's scope, which words the name's placeholder:
   *   a block blueprint is found in the Add panel, a page blueprint among
   *   the page blueprints. Defaults to block.
   *
   * @return array
   *   The field render arrays, keyed label_field/description_field (plus
   *   icon_field when given).
   */
  protected function fields(array $icon_field = [], string $scope = BlueprintInterface::SCOPE_BLOCK): array {
    return [
      'label_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['for' => 'daedalus-layout-blueprint-label'],
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
            'id' => 'daedalus-layout-blueprint-label',
            'maxlength' => '255',
            'required' => 'required',
            'placeholder' => $scope === BlueprintInterface::SCOPE_PAGE
              ? $this->t('How editors find it among the page blueprints')
              : $this->t('How editors find it in the Add panel'),
          ],
        ],
      ],
      'description_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['for' => 'daedalus-layout-blueprint-description'],
          '#value' => $this->t('Description'),
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'textarea',
          '#attributes' => [
            'class' => ['form-textarea'],
            'id' => 'daedalus-layout-blueprint-description',
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
   * What the blueprint is material for: scope (read-only), subject, hosts, groups.
   *
   * Renders only for a resolved blueprint with the subject vocabulary at
   * hand. The scope is identity (a placement's provenance and the
   * blueprint's one-root rule both rest on it), so it is shown, never
   * edited. The subject select offers the site's page-builder bundles
   * (BlueprintSubjects::options()) with the current subject selected (and
   * listed even when no display lays it out any more, so the select always
   * carries the stored value); the allow-list offers the same bundles as
   * checkboxes, the stored entries checked (a stored entry no option names
   * still renders checked, so saving the dialog never drops it silently).
   * The client reads the select's value as `subject` and the checked boxes
   * as `bundles`, both only when the section rendered.
   *
   * The groups: the site's group vocabulary (the dialect catalog's
   * groups, DialectCatalog::groups(), contributed from the site
   * declaration's groups map) as checkboxes with the stored ids checked,
   * plus any stored id the site no longer declares (rendered checked and
   * labelled by its id, so saving never drops it silently; a surface that
   * filters ignores it). A group is a filter
   * category and nothing else. The section renders only when there is
   * something to offer or something stored, so a site without groups
   * never shows an empty control and the client omits the key.
   *
   * @return array
   *   A render array keyed scope_field, subject_field, bundles_field and,
   *   when there are groups to show, groups_field, or [] when the
   *   blueprint did not resolve or the vocabulary is absent.
   */
  protected function materialFor(?BlueprintInterface $blueprint): array {
    if ($blueprint === NULL || $this->subjects === NULL) {
      return [];
    }
    $options = $this->subjects->options();
    $current = $this->subjects::spell($blueprint->getSubjectType(), $blueprint->getSubjectBundle());
    if (!isset($options[$current])) {
      $options = [$current => $this->subjects->label($blueprint->getSubjectType(), $blueprint->getSubjectBundle()) ?? $current] + $options;
    }
    $bundles = $blueprint->getBundles();
    $host_options = $options;
    foreach ($bundles as $entry) {
      $host_options += [$entry => $entry];
    }

    $subject_options = [];
    foreach ($options as $value => $label) {
      $subject_options[] = [
        '#type' => 'html_tag',
        '#tag' => 'option',
        '#value' => $label,
        '#attributes' => ['value' => $value] + ($value === $current ? ['selected' => 'selected'] : []),
      ];
    }
    $host_items = [];
    foreach ($host_options as $value => $label) {
      $host_items[] = [
        '#type' => 'inline_template',
        // A label wrapping a checkbox: the dialog skin's inline choice
        // row, no class needed (the adoption section's pattern).
        '#template' => '<label><input type="checkbox" ' . self::BUNDLE_ATTRIBUTE . '="{{ value }}"{% if checked %} checked{% endif %}/> {{ label }}</label>',
        '#context' => ['value' => $value, 'label' => $label, 'checked' => in_array($value, $bundles, TRUE)],
      ];
    }

    $groups = $blueprint->getGroups();
    $group_options = array_map(static fn (array $group): string => $group['label'], $this->catalogBuilder->build()->groups());
    foreach ($groups as $group) {
      $group_options += [$group => $group];
    }
    $group_items = [];
    foreach ($group_options as $value => $label) {
      $group_items[] = [
        '#type' => 'inline_template',
        '#template' => '<label><input type="checkbox" ' . self::GROUP_ATTRIBUTE . '="{{ value }}"{% if checked %} checked{% endif %}/> {{ label }}</label>',
        '#context' => ['value' => $value, 'label' => $label, 'checked' => in_array($value, $groups, TRUE)],
      ];
    }
    $groups_field = $group_items === [] ? [] : [
      'groups_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Groups'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t('The groups the blueprint is listed under where blueprints are offered. Filters only; nothing checked lists it under none.'),
        ],
        'items' => $group_items,
      ],
    ];

    return $this->scopeField($blueprint->getScope()) + [
      'subject_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => ['for' => self::SUBJECT_INPUT_ID],
          '#value' => $this->t('Subject'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t("The kind of page the blueprint is material for: its canvas renders against a sample of this bundle, and its field blocks name that bundle's fields."),
        ],
        'input' => [
          '#type' => 'html_tag',
          '#tag' => 'select',
          '#attributes' => [
            'class' => ['form-select'],
            'id' => self::SUBJECT_INPUT_ID,
          ],
          'options' => $subject_options,
        ],
      ],
      'bundles_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Allowed hosts'),
        ],
        'help' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['description']],
          '#value' => $this->t('Where the blueprint may be placed. Nothing checked means any page it fits.'),
        ],
        'items' => $host_items,
      ],
    ] + $groups_field;
  }

  /**
   * The scope line: what a blueprint of this scope is, read-only.
   *
   * Identity, never edited (a placement's provenance and the blueprint's
   * one-root rule both rest on it): the save dialog states the scope the
   * capture will mint, the details dialog the scope the blueprint has.
   * The client reads nothing from it; the data attribute is for tests and
   * styling.
   *
   * @param string $scope
   *   BlueprintInterface::SCOPE_PAGE or SCOPE_BLOCK.
   *
   * @return array
   *   A render array keyed scope_field.
   */
  protected function scopeField(string $scope): array {
    return [
      'scope_field' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['form-item']],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#value' => $this->t('Scope'),
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['data-blueprint-scope' => $scope],
          '#value' => $scope === BlueprintInterface::SCOPE_PAGE
            ? $this->t("Page: a whole page's document. The scope is identity and does not change.")
            : $this->t('Block: one container placed into a page. The scope is identity and does not change.'),
        ],
      ],
    ];
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
   * spelling the blueprint gestures accept.
   *
   * Renders nothing when the opener is absent (no media_library), the host
   * cannot be resolved, or no visual media types exist: the dialogs stay
   * on their two-field contract and the client omits the icon key.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $host
   *   The opener's access subject: the entity being laid out for the save
   *   dialog, the blueprint itself for the details dialog.
   * @param \Drupal\media\MediaInterface|null $icon
   *   The currently referenced icon media, or NULL when unset.
   * @param string $picker_key
   *   The picker instance key the opener's selection response targets.
   * @param string $scope
   *   (optional) The blueprint's scope, which words the help: a block
   *   blueprint's icon is its Add panel item's face, a page blueprint's the
   *   face of its page blueprint listing. Defaults to block.
   *
   * @return array
   *   A render array keyed 'icon_field', or [] when no picker can render.
   */
  protected function iconField(?EntityInterface $host, ?MediaInterface $icon, string $picker_key, string $scope = BlueprintInterface::SCOPE_BLOCK): array {
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
          '#value' => $scope === BlueprintInterface::SCOPE_PAGE
            ? $this->t('Shown where page blueprints are listed; leave empty for the default icon.')
            : $this->t('Shown on the Add panel item; leave empty for the default icon.'),
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
   * The owned roster: the blueprint's current looks, inert display.
   *
   * The update dialog's honesty line: every variant the blueprint already
   * owns (either lane), including adopted-but-unworn looks the capture
   * walk would never surface (the roster comes from the spine's ownership
   * query, not from the capture). Read-only BY DESIGN, not laziness: the
   * dialog commits through the op lane, while release is deliberately a
   * CSRF route the op spine cannot reach, so an uncheck-to-release here
   * would compose a route call with an op non-atomically. Release stays
   * the Materials card's gesture.
   *
   * @return array
   *   A render array keyed 'owned', or [] when the blueprint owns nothing
   *   (or did not resolve).
   */
  protected function ownedRoster(?BlueprintInterface $blueprint): array {
    if ($blueprint === NULL) {
      return [];
    }
    $variants = [];
    foreach ($this->blueprintManager->ownedVariants($blueprint) as $variant) {
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
          '#value' => $this->t('The looks this blueprint offers on its placements. Release or transfer them from Materials in the style panel.'),
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
   * refusal lives in BlueprintManager::adoptVariants()). Checked by
   * default: the real flow mints the looks BEFORE claiming them, at save or
   * at the update that follows a new look. Empty (and the dialog renders
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
        '#template' => '<label><input type="checkbox" checked data-blueprint-adopt="{{ machine_name }}"/> {{ label }}</label>',
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
          '#value' => $this->t("Checked variants become this blueprint's own: offered only on its placements, traveling with it among the site's materials."),
        ],
        'items' => $items,
      ],
    ];
  }

  /**
   * The adoptable variants the captured subtree references.
   *
   * The same capture the gesture op will run (the manager's one capture
   * walk), read-only. A capture failure renders the dialog without
   * the section: the gesture itself will fail loudly through the op; the
   * dialog's job is only to offer what a successful capture would
   * reference. Adoptable mirrors what adoptVariants() accepts: shipped
   * (disk-lane) variants never list (adoption would be irreversible by
   * gesture), and neither does a variant owned on EITHER lane (one owner
   * max; an update's capture wearing looks the blueprint already owns has
   * nothing to adopt: they show in the owned roster instead).
   *
   * @return array<string, string>
   *   machine name => label, in capture order.
   */
  protected function adoptableVariants(Request $request): array {
    if ($this->variantManager === NULL || $this->blueprintManager === NULL) {
      return [];
    }
    $element = $request->query->get('element');
    if (!is_string($element) || $element === '') {
      return [];
    }
    $entity_type = $request->query->get('entity_type');
    $entity_id = $request->query->get('entity_id');
    $host = $this->variantManager->resolveHost(
      is_string($entity_type) ? $entity_type : NULL,
      is_string($entity_id) ? $entity_id : NULL,
    );
    if ($host === NULL) {
      return [];
    }
    try {
      $references = $this->blueprintManager->capturedVariants($host, $element);
    }
    catch (BlueprintGestureException) {
      return [];
    }
    $variants = [];
    foreach ($references as $reference) {
      $variant = $this->variantManager->loadVariant($reference);
      if ($variant === NULL || $variant->isShipped() || !$variant->get('blueprint')->isEmpty() || !$variant->get('blueprint_file')->isEmpty()) {
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
