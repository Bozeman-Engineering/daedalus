<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Event\LoadEditablePageEvent;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Makes the page title inline editable when it IS the root entity's label.
 *
 * The element model's derived root rule says an entity's label field
 * presents AS the entity row (ElementModel::rootPresentation, the
 * `_element` annotation's edit_target). On many sites, though, the label
 * never renders inside the entity: the H1 is the theme's page-title block
 * (core's title resolver printing the label outside [data-main-entity]),
 * so nothing on the canvas answers to field:{root}:{label} and the title
 * has no in-place edit. This service closes that gap the same way
 * daedalus_edit_preprocess_block() closes it for rendered block labels: it
 * stamps the page-title element with the selectable key and the daedalus_edit
 * leaf identity, making the H1 the label field's canvas render, a leaf
 * that edits on its own click and commits the ROOT row as the selection.
 * It is a leaf, not a route: the root row's Edit verb opens the
 * entity-form panel on every theme and never looks for this element, so a
 * theme that places no page-title block simply has no in-place title edit.
 *
 * Only ONE render of the label may carry the leaf identity (duplicate ids
 * would leave querySelector-based form-swap and markup replacement
 * ambiguous), so attribution is gated on the label rendering OUTSIDE the
 * entity, answered from proven render truths only (see
 * outsideLabelField()). When the label renders (or may render) inside the
 * entity, the ordinary field attribution path owns the edit surface and
 * this service stays inert.
 *
 * The rule is currently daedalus_edit-only vocabulary (leaf ids, field-value
 * wrappers). If a second consumer needs "does the root label render outside
 * the entity" (e.g. Daedalus Stylish styling the H1), promote outsideLabelField()
 * into daedalus_ui's element model rather than duplicating it.
 */
final class PageTitleEditing implements EventSubscriberInterface {

  /**
   * The page-title element's marker, stamped for PERMITTED users in every mode.
   *
   * Entering (or leaving) Edit Mode is an AJAX swap of the ENTITY
   * (LoadEditablePage): the page-title block outside it keeps its previous
   * render, so the mode transition must replace the title element too, and
   * needs a theme-independent selector for it in the pre-transition markup.
   * The marker is inert bytes gated on the inline-editing permission (the
   * user.permissions cache context already varies this render), never on the
   * mode cookie, so it survives both directions of the transition.
   */
  public const MARKER = 'data-daedalus-edit-page-title';

  public function __construct(
    private readonly ElementModel $elementModel,
    private readonly DaedalusUi $daedalusUi,
    private readonly EntityTempstoreRepository $tempstoreRepository,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * The label field the page title stands in for, when it renders outside.
   *
   * Answers with the entity's label field name when BOTH hold: the element
   * model routes the root's editing to the label (rootPresentation's
   * edit_target: the label is a real bundle field and existent in the
   * routed view mode), and the entity render itself does not produce the
   * field on the canonical page (so the page-title element is the label's
   * ONE canvas render; a second attributed render would make leaf
   * identities ambiguous).
   *
   * "Renders outside" is answered only for PROVEN render truths, so unknown
   * entity types degrade to today's behavior (an inert page title) instead
   * of risking a duplicate leaf:
   * - A node's non-display-configurable title never renders inside the
   *   entity on its canonical page: NodeThemeHooks::preprocessNode() pulls
   *   it out of content into the `label` variable, and node.html.twig
   *   prints label only on non-page views. The theme's page title is the
   *   one render, Layout Builder or not.
   * - An LB display renders no unpreserved display-configurable root field
   *   (daedalus_layout's preserved_fields is the render truth, the same one the
   *   element model's existence feeder consumes).
   * Other roots (media's name, for example, IS rendered inside content by
   * its display) stay inert; extend this list as render truths are proven.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The routed root entity.
   *
   * @return string|null
   *   The label field name, or NULL when the page title is not the
   *   outside-rendered label (no routable label, or the label may render
   *   inside the entity where ordinary field attribution owns it).
   */
  public function outsideLabelField(FieldableEntityInterface $entity): ?string {
    $view_mode = _daedalus_ui_get_view_mode($entity);
    $target = $this->elementModel
      ->rootPresentation($entity->getEntityTypeId(), $entity->bundle(), $view_mode)
      ->editTarget;
    if ($target === NULL) {
      return NULL;
    }
    $definition = $entity->getFieldDefinition($target);
    if ($entity->getEntityTypeId() === 'node' && !$definition->isDisplayConfigurable('view')) {
      return $target;
    }
    $display = EntityViewDisplay::collectRenderDisplay($entity, $view_mode);
    if ($display->getThirdPartySetting('layout_builder', 'enabled', FALSE)) {
      $preserved = $display->getThirdPartySetting('daedalus_layout', 'preserved_fields', []);
      return in_array($target, $preserved, TRUE) ? NULL : $target;
    }
    return NULL;
  }

  /**
   * Preprocesses the page_title element (hook_preprocess_page_title).
   *
   * In Edit Mode, when the rendered title is the routed entity's
   * outside-rendered label, stamps the title element with the same
   * vocabulary a rendered field carries: the selectable key
   * (field:{uuid}:{label}), the 9-part data-daedalus-edit-id leaf, and the
   * field-value wrapper around it, so the armed edit session resolves the
   * H1 as an editable leaf on click and Tab (committing the root row as
   * the selection) and the commit round-trip can replace the markup.
   *
   * @param array $variables
   *   The page_title theme variables.
   */
  public function preprocessPageTitle(array &$variables): void {
    // Only a permitted user's output depends on the mode cookie: everyone
    // else renders one uniform page title.
    $variables['#cache']['contexts'][] = 'user.permissions';
    if (!$this->currentUser->hasPermission('access inline editing')) {
      return;
    }
    $variables['#cache']['contexts'][] = 'daedalus_ui_mode';
    $route_entity = $this->daedalusUi->deriveEntityFromRoute();
    if (!$route_entity instanceof FieldableEntityInterface) {
      return;
    }
    // The page usually renders the tempstore version (staged edits
    // included), so the comparison below must accept it. The route entity
    // can lag either way: within the update round-trip the response renders
    // after the submit handler staged the new label while route conversion
    // ran before (route = stored, rendered = tempstore), and a live render
    // with staged-but-unswapped changes is the reverse, so match EITHER
    // label.
    $entity = $this->tempstoreRepository->get($route_entity);
    $label_field = $this->outsideLabelField($entity);
    if ($label_field === NULL) {
      return;
    }
    // The title must BE the label: routes can override the title callback
    // (admin pages, custom titles), and claiming an H1 that prints something
    // else would attach the edit to the wrong markup. Compared as TEXT:
    // core's EntityViewController::buildTitle() can deliver the title as the
    // rendered label FIELD (#is_page_title: inline markup, newline padding,
    // marker spans), so tags are stripped and entities decoded before the
    // comparison.
    $title = $variables['title'];
    if (!is_string($title) && !$title instanceof \Stringable) {
      return;
    }
    $title = trim(Html::decodeEntities(strip_tags((string) $title)));
    if ($title !== trim((string) $entity->label()) && $title !== trim((string) $route_entity->label())) {
      return;
    }

    // The mode-independent marker (see MARKER) rides the OUTERMOST element
    // of the title render (the bare H1 on a live render, the field-value
    // wrapper div in Edit Mode), so a mode transition's ReplaceCommand swaps
    // the whole render either direction without nesting stale wrappers.
    if ($this->daedalusUi->getMode() !== 'edit') {
      $variables['title_attributes'][self::MARKER] = '';
      return;
    }

    $definition = $entity->getFieldDefinition($label_field);
    $main_property = method_exists($definition, 'getMainPropertyName') ? $definition->getMainPropertyName() : 'value';
    $id = daedalus_ui_entity_identifier($entity);
    $entity_type_id = $entity->getEntityTypeId();

    // The same shape RenderEditableFieldAttributes stamps on field renders:
    // the title element is the leaf, a wrapper div is the field-value
    // wrapper (the throbber/replacement handle), title_attributes carry the
    // selection identity so the element competes as itself.
    $variables['title_attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::field($entity->uuid(), $label_field);
    $variables['title_attributes']['class'][] = 'daedalus-edit-field-value';
    $variables['title_attributes']['data-daedalus-edit-id'] = sprintf('%s::%s::%s::%s::%s::%s::%s::%s::%s', $entity_type_id, $entity->bundle(), $id, $label_field, 0, $entity->language()->getId(), $definition->getType(), 'string', $main_property);
    $variables['title_attributes']['data-daedalus-edit-page-element-id'] = sprintf('%s::%s::%s::%s::%s', $entity_type_id, $id, $label_field, 0, $main_property);
    $variables['title_prefix']['daedalus_edit'] = [
      '#markup' => sprintf('<div %s="" data-daedalus-edit-field-value-wrapper="%s::%s::%s::%s">', self::MARKER, $entity_type_id, $id, $label_field, $main_property),
    ];
    $variables['title_suffix']['daedalus_edit'] = ['#markup' => '</div>'];
    $variables['#cache']['tags'][] = daedalus_edit_cache_tag($entity);
  }

  /**
   * A fresh page-title render for the update round-trip.
   *
   * When an edit commits, DaedalusEditFormTrait::updatePage() re-renders the
   * entity and replaces the edited element by its field-value-wrapper
   * selector, but an outside-rendered label is not IN the entity render,
   * and updateElement treats a live-only selector as a removal (the H1
   * would vanish). This render rides beside the entity content so the
   * selector matches: it goes through the active theme's page-title
   * template and this service's preprocess, so the replacement H1 arrives
   * attributed exactly like the one it replaces.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The form's entity (the tempstore version with the staged edit).
   * @param string $field_name
   *   The field the update targets.
   *
   * @return array|null
   *   A page_title render array, or NULL when the updated field is not the
   *   entity's outside-rendered label.
   */
  public function titleRender(FieldableEntityInterface $entity, string $field_name): ?array {
    if ($this->outsideLabelField($entity) !== $field_name) {
      return NULL;
    }
    return [
      '#type' => 'page_title',
      '#title' => (string) $entity->label(),
    ];
  }

  /**
   * Swaps the page title across the Edit Mode AJAX transition.
   *
   * LoadEditablePage replaces only the ENTITY; the page-title block outside
   * it keeps its previous render, so entering Edit Mode would leave the H1
   * unattributed (and leaving would strand the editing attribution). Ride
   * the transition's response with a replacement render targeted by the
   * mode-independent MARKER: the render goes through the theme's page-title
   * template and this service's preprocess in the CURRENT request's mode, so
   * each direction produces exactly the markup a full page load would.
   *
   * @param \Drupal\daedalus_ui\Event\LoadEditablePageEvent $event
   *   The transition event.
   */
  public function onLoadEditablePage(LoadEditablePageEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $label_field = $this->outsideLabelField($entity);
    if ($label_field === NULL) {
      return;
    }
    $event->getResponse()->addCommand(new ReplaceCommand(
      sprintf('[%s]', self::MARKER),
      $this->titleRender($entity, $label_field),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LoadEditablePageEvent::class => 'onLoadEditablePage',
    ];
  }

}
