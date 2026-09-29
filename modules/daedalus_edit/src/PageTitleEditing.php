<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint_api\Render\TargetKeyMapper;
use Drupal\daedalus_edit\EditIdentity;
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
 * The H1 is outside the entity, so the mutation-response contract's
 * whole-entity render never carries it: a label write on the op spine (an
 * endpoint op, an AI tool, an undo) would leave the H1 stale. This service
 * rides the contract (onMutationResponse) and delivers the page-title
 * render under the label field's own key, the same key the H1 carries on
 * the canvas, so every road that answers the contract swaps the H1 by
 * construction.
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

  /**
   * The warning code a response carries when the H1 could not be delivered.
   *
   * The page-title render attributes itself only under the edit-mode
   * response convention (the mode on the request, the entity on the route
   * match, the rendered title being the staged label); a render that came
   * back without the label's key would swap an unattributed H1 over the
   * leaf, so the payload says the title was left alone instead.
   */
  public const WARNING_UNATTRIBUTED = 'PAGE_TITLE_UNATTRIBUTED';

  public function __construct(
    private readonly ElementModel $elementModel,
    private readonly DaedalusUi $daedalusUi,
    private readonly EntityTempstoreRepository $tempstoreRepository,
    private readonly AccountInterface $currentUser,
    private readonly RendererInterface $renderer,
    private readonly SubtreeRenderer $subtreeRenderer,
    private readonly TargetKeyMapper $targetKeyMapper,
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
    $main_property = EditIdentity::mainProperty($definition);

    // The same identities RenderEditableFieldAttributes stamps on field
    // renders (EditIdentity, the one minter): the title element is the
    // leaf, a wrapper div is the field-value wrapper (the
    // throbber/replacement handle), title_attributes carry the selection
    // identity so the element competes as itself.
    $variables['title_attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::field($entity->uuid(), $label_field);
    $variables['title_attributes']['class'][] = 'daedalus-edit-field-value';
    $variables['title_attributes']['data-daedalus-edit-id'] = EditIdentity::editId($entity, $label_field, 0, $entity->language()->getId(), $definition->getType(), 'string', $main_property);
    $variables['title_attributes']['data-daedalus-edit-page-element-id'] = EditIdentity::itemId($entity, $label_field, 0, $main_property);
    $variables['title_prefix']['daedalus_edit'] = [
      '#markup' => sprintf('<div %s="" data-daedalus-edit-field-value-wrapper="%s">', self::MARKER, EditIdentity::fieldWrapperId($entity, $label_field, $main_property)),
    ];
    $variables['title_suffix']['daedalus_edit'] = ['#markup' => '</div>'];
    $variables['#cache']['tags'][] = daedalus_edit_cache_tag($entity);
  }

  /**
   * A fresh page-title render for a label that just changed.
   *
   * An outside-rendered label is not IN the entity render, so every
   * round-trip that re-renders the entity needs this render beside it: the
   * mutation response (onMutationResponse, which every write rides, the
   * form transport's commits included) and the mode transition
   * (onLoadEditablePage). It goes through the active
   * theme's page-title template and this service's preprocess, so the
   * replacement H1 arrives attributed exactly like the one it replaces.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity carrying the new label (the tempstore version with the
   *   staged edit, in the language being rendered).
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
   * Delivers the page title on the mutation-response contract.
   *
   * When a successful mutation's targets name the root's outside-rendered
   * label, the payload's markup gains the page-title render under the
   * label field's key (field:{root}:{label}), the key the H1 carries on
   * the canvas, so the client's swap-by-key replaces the H1 like any field.
   *
   * The key is CLAIMED, never merged: the whole-entity render behind the
   * contract can print the label inside the entity too (a node template
   * printing its label under a non-full view mode, or on the endpoint's
   * route where core's page test fails), and daedalus_ui stamps that inner
   * render with the same key. Left alone, the contract would deliver the
   * inner render under the H1's key and the client would swap the H1 for
   * it. Overwriting the key with the H1 render neutralizes the duplicate
   * whatever the theme prints.
   *
   * A label-only mutation drops the whole-entity fallback the renderer
   * produced for want of the key (the label is outside the entity by
   * definition here, so the entity swap carries nothing the H1 does not):
   * the response swaps the H1 alone, as the entity-form path did.
   *
   * @param \Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent $event
   *   The mutation response assembly event.
   */
  public function onMutationResponse(BlueprintMutationResponseEvent $event): void {
    $result = $event->result;
    if (!$result->isSuccess() || !$result instanceof BlueprintResult) {
      return;
    }
    $entity = $result->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $payload = $event->getPayload();
    // The response renders one language for the whole round (the mutate
    // endpoint's rule); the H1 must carry that translation's label.
    $langcode = $payload['langcode'] ?? NULL;
    if (is_string($langcode) && $entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    $label_field = $this->outsideLabelField($entity);
    if ($label_field === NULL) {
      return;
    }
    $label_key = SelectableKey::field($entity->uuid(), $label_field);
    $keys = $this->targetKeyMapper->map($entity, $result->getTargets());
    if (!in_array($label_key, $keys, TRUE)) {
      return;
    }

    $render = $this->titleRender($entity, $label_field);
    $html = (string) $this->renderer->renderInIsolation($render);
    $title = $this->subtreeRenderer->subtree($html, $label_key);
    if ($title === NULL) {
      $payload['warnings'][] = [
        'path' => 'markup',
        'code' => self::WARNING_UNATTRIBUTED,
        'message' => 'The label changed but the page-title render carried no editable leaf, so the page title was not re-rendered.',
      ];
      $event->setPayload($payload);
      return;
    }

    // An empty markup channel is a stdClass so it encodes as an object.
    $markup = (array) $payload['markup'];
    $markup[$label_key] = $title;
    if ($keys === [$label_key]) {
      unset($markup[SelectableKey::entity($entity->uuid())]);
    }
    $payload['markup'] = $markup;
    $event->setPayload($payload);
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
      BlueprintMutationResponseEvent::class => 'onMutationResponse',
    ];
  }

}
