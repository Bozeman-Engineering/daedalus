<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\Showing;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\FieldBlock;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\Render\BlockStandIn;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\daedalus_ui\Render\LiveAttributionDemand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\layout_builder\Event\SectionComponentBuildRenderArrayEvent;

/**
 * Stamps a Layout Builder placement's attributes on its block build.
 *
 * Two tiers ride the block template root (or a composed block's composed
 * root, which hoists the same bag): the element address (data-element,
 * see ElementAddress), stamped in every mode for every visitor, and the
 * editing attributes (the selectable key, the component uuid and region,
 * the presentation, the portal of a placement whose content is edited
 * elsewhere, and in the editing posture the stand-in a block that built
 * nothing wears with its reason), stamped for editors, with the
 * selectable key alone also reaching visitors' markup where a
 * live-attribution provider demands it.
 *
 * The portal and the claim are one projection of the spine's answers
 * (stampShowing()). A join (a slot door, a linked placement) shows another
 * document by reference, and what it shows is the showing
 * (VerdictStamper::showing()): the portal to the shown blueprint when the
 * actor may open it, and the claim when this canvas may detach it into the
 * slot. Every other foreign node (a template's element on a locked page
 * other than a field block presenting the page's own field, any node of
 * the site frame around the route, a door showing the page's own fill or
 * nothing) is its holder's, and the content verdict's FOREIGN_DOCUMENT
 * refusal names the holder as the portal. Either way the placement wears
 * the portal host's editing url, label and kind word, and the Portal
 * component (portal-model.js) does the rest. Which host's document holds
 * the placement is the render's fact (the root entity context the
 * primitive sets), and the spine's one door for a node seen from a canvas
 * is VerdictStamper::verdictsOn().
 */
final class LayoutBuilderBlockAttributes implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LiveAttributionDemand $liveAttribution,
    protected ElementModel $elementModel,
    protected DaedalusUi $daedalusUi,
    protected VerdictStamper $verdictStamper,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * On section component build render array.
   */
  public function onBuildRender(SectionComponentBuildRenderArrayEvent $event): void {
    $build = $event->getBuild();
    $build['#cache']['contexts'][] = 'user.permissions';
    // Components that rendered empty are a cache-metadata-only stub with no
    // themed output, so no stamped attribute could ever reach the DOM.
    // Worse, stamping one makes Element::isEmpty() report the stub as
    // non-empty, so layouts that theme their children as blocks (core
    // navigation's NavigationLayout) send it through block preprocess
    // without any block properties, emitting a warning per missing key.
    if (Element::isEmpty($build['content'] ?? [])) {
      $event->setBuild($build);
      return;
    }
    $component = $event->getComponent();
    $plugin_id = $component->getPluginId();
    // The element address, in every mode and for every visitor: the
    // declaration the placement resolves through. An entity-backed block
    // (inline_block:{bundle}, block_content:{uuid}) is its bundle's
    // declaration, read off the content entity the plugin built; any other
    // plugin is its base plugin's. Inert bytes on the block template root
    // (a composed block hoists the same bag onto its composed root), the
    // one attribute the site's defaults tier and type-addressed rules
    // select by.
    $content_entity = $build['content']['#block_content'] ?? NULL;
    $build['#attributes'][ElementAddress::ATTRIBUTE] = $content_entity instanceof BlockContentInterface
      ? ElementAddress::blockType($content_entity->bundle())
      : ElementAddress::blockPlugin($plugin_id);
    if (!$this->currentUser->hasPermission('use daedalus ui edit mode')) {
      // Live render: stamp only the selection identity, and only when a
      // provider demands it (e.g. the block, a sub-element, or one of its
      // fields carries styles whose CSS scopes to this key). The prefix
      // check covers the whole "block:{uuid}" key family — the wrapper is
      // the @scope root for the block and its sub-elements, and it carries
      // the component uuid the inline-block field-key rewrite needs.
      $key = SelectableKey::block($component->getUuid());
      $entity = $this->demandEntity($event);
      if ($entity !== NULL && $this->liveAttribution->demandsKeyPrefix($key, $entity)) {
        $build['#attributes'][SelectableKey::ATTRIBUTE] = $key;
      }
      $event->setBuild($build);
      return;
    }

    // Attribute the rendered blocks so editing tools are aware of them.
    $build['#attributes']['data-layout-builder-block-uuid'] = $component->getUuid();
    $build['#attributes']['data-layout-builder-region'] = $component->getRegion();
    // The placement's selection identity: what move/delete/duplicate target.
    $build['#attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::block($component->getUuid());

    // The editing posture (core's preview flag) gives a block that built
    // nothing core's placeholder sentence under `content`; that text wears
    // daedalus_ui's stand-in instead: the label and the reason, the reason
    // stamped on the root as a state attribute. The block's own access
    // answer for the editor decides the reason (BlockStandIn), except for
    // a slot, whose emptiness is its state. Core's label attribute stays
    // on the root. Detected by the build's shape, never the string, and
    // only here, under the editor's permission: the preview flag is the
    // editing canvas's and the peek door's, never a visitor's.
    // A slot block is a door: the place the document rendered inside this
    // one fills. Its root wears the slot id (the drag layer's empty-door
    // target, gaps.js), and an empty slot's stand-in says what the slot is
    // and, where the route host may fill it from this canvas (the spine's
    // insert verdict on the door), that a block may be dropped there.
    $slot = SlotBlock::slotIdOf($plugin_id);
    if ($slot !== NULL) {
      $build['#attributes'][SlotBlock::DOOR_ATTRIBUTE] = $slot;
    }
    // The placement's portal and claim, when its node shows or belongs to
    // a foreign document seen from this canvas: the attributes the Portal
    // component reads (portal-model.js), so the portal chip, its claim and
    // the double-click leave follow. The holder of the node is the
    // render's fact; the showing and the verdict are the spine's.
    $canvas = $this->daedalusUi->deriveHostFromRoute();
    $holder = $this->holderOf($event) ?? $canvas;
    // A route with no host (a view page, the login form) has no canvas,
    // and its only foreign document is the frame around it: outside a
    // frame render such a render (a kernel render, a listing) stamps no
    // portal, as before.
    if ($canvas === NULL && !$this->inFrameRender($event)) {
      $holder = NULL;
    }
    $this->stampShowing($build, $canvas, $holder, $component->getUuid(), $plugin_id);
    $block = $event->getPlugin();
    if (!empty($build['#in_preview']) && $block instanceof BlockPluginInterface && BlockStandIn::isPlaceholder($build['content'])) {
      if ($slot !== NULL) {
        // The door is open when the route host may fill the slot from
        // this canvas: the spine's insert verdict on the door, seen from
        // here (a template's door on a locked page, the frame's on any
        // page).
        $open = $holder !== NULL
          && $this->verdictStamper->verdictsOn($canvas, $holder, $component->getUuid())['can']['insert'];
        $reason = $open ? BlockStandIn::SLOT_OPEN : BlockStandIn::SLOT;
      }
      else {
        $reason = $block->access($this->currentUser, TRUE)->isForbidden()
          ? BlockStandIn::VISITORS_ONLY
          : BlockStandIn::EMPTY;
      }
      $build['content'] = BlockStandIn::build((string) $block->label(), $reason);
      $build['#attributes'][BlockStandIn::ATTRIBUTE] = $reason;
    }

    // Content blocks: the block_content entity's UI wrapper attributes (the
    // canonical AJAX-refresh id + view mode) ride the block template root,
    // beside the placement key. block_content has no entity template of its
    // own, so the entity-level stamp (DaedalusUiHooks::entityView())
    // skips it and this is its delivery seam. The entity selectable key is
    // deliberately NOT stamped: a content block's selection identity is the
    // placement (block:{component_uuid}), not its block_content entity.
    $entity_info = $build['content']['#daedalus_ui_entity'] ?? NULL;
    if (is_array($entity_info)) {
      $content_entity = $build['content']["#{$entity_info['entity_type']}"] ?? NULL;
      if ($content_entity instanceof EntityInterface) {
        $build['#attributes']['class'][] = 'daedalus-ui-entity-wrapper';
        $build['#attributes']['data-daedalus-ui-entity-wrapper'] = daedalus_ui_entity_wrapper_id($content_entity);
        $build['#attributes']['data-daedalus-ui-view-mode'] = $entity_info['view_mode'];
      }
    }

    // The bundle tier of the presentation chain: the ONE element model
    // decides, from a configured three-way setting (auto / unit / open), a
    // page_builder declaration, or the derived merge rule. UNIT presents the
    // block as one element with its structure reachable by drilling in; MERGED
    // absorbs a single-content block into its content (selection key = this
    // placement, edit routes to the inner leaf). OPEN stamps nothing. The block
    // render carries the bundle config's cache tag so a settings change lands
    // on reload, and the derived default reads whether the component's
    // label renders (NodeTreeBuilder::labelRendered, the one rule, the
    // same fact the delivered tree spells as `label_rendered`).
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $bundle = substr($plugin_id, strlen('inline_block:'));
      $block_type = $this->entityTypeManager->getStorage('block_content_type')->load($bundle);
      if ($block_type) {
        $build['#cache']['tags'] = array_merge($build['#cache']['tags'] ?? [], $block_type->getCacheTags());
        $label_rendered = NodeTreeBuilder::labelRendered($component->get('configuration'));
        $presentation = $this->elementModel->bundlePresentation('block_content', $bundle, $label_rendered);
        if ($presentation->mode !== Presentation::OPEN) {
          $build['#attributes'][Presentation::ATTRIBUTE] = $presentation->mode;
        }
      }
    }
    // A FIELD BLOCK presents one root field inside its placement: the same
    // ONE model decides (ElementModel::fieldBlockPresentation). The
    // placement carries the merged mode like a single-content inline block,
    // so the canvas resolver promotes the inner field leaf's edit to the
    // placement, and names the field it presents (the field's selectable
    // key), the DOM twin of the delivered annotation's presented_field that
    // folds the placement row and the field row as one in the panel.
    // Only the route host's OWN fields entity is presented: a field block
    // on a template canvas renders core's sample entity's field, a
    // placement whose content is preview and never edited, so it names no
    // field (the sample is inert by construction, DaedalusUi::ownsFields).
    elseif (FieldBlock::isFieldBlock($plugin_id)) {
      $entity = $this->contextEntity($event);
      $definition = $entity instanceof FieldableEntityInterface && $entity->uuid() === $this->daedalusUi->deriveHostFromRoute()?->fields()?->uuid()
        ? $this->elementModel->presentedField($plugin_id, $entity)
        : NULL;
      if ($definition !== NULL) {
        $build['#attributes'][Presentation::ATTRIBUTE] = $this->elementModel->fieldBlockPresentation($definition)->mode;
        $build['#attributes'][Presentation::PRESENTED_FIELD_ATTRIBUTE] = SelectableKey::field($entity->uuid(), $definition->getName());
      }
    }
    $event->setBuild($build);
  }

  /**
   * Stamps what a placement shows and where it is edited: the chip's facts.
   *
   * One projection of the spine's answers onto the placement root, the
   * attributes the Portal component reads (portal-model.js). The showing
   * (VerdictStamper::showing(): what a join shows, seen from this canvas by
   * this actor) gives a slot door its claim (SlotBlock::CLAIM_ATTRIBUTE,
   * the machine name of the default it shows, exactly when a claim,
   * detach_blueprint with `slot`, would land, so the chip offers "Detach
   * into this slot" there and nowhere else) and a join showing a blueprint
   * by reference its portal; portalOf() names the portal host for every
   * other foreign node. The portal host's editing surface is the one
   * derivation (DaedalusUi::getEditingUrl), its kind the one word
   * (DaedalusUi::hostKind, the type the chip reads into "Header ·
   * blueprint"), its label the chip's name. A claimable door whose actor
   * may open nothing (neither the blueprint nor the door's holder) has no
   * portal and still needs a name: the shown blueprint's label rides the
   * label attribute alone. No holder to ask about (a render carrying no
   * root and no route host): no stamp, the plain render. Nothing claims
   * on a route with no host: the claim is a canvas's gesture.
   *
   * @param array $build
   *   The block build.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The route host, or NULL on a route with none.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $holder
   *   The host whose document holds the placement, or NULL when the render
   *   names none and the route has no host.
   * @param string $uuid
   *   The component uuid.
   * @param string $plugin_id
   *   The component's block plugin id.
   */
  private function stampShowing(array &$build, ?HostInterface $canvas, ?HostInterface $holder, string $uuid, string $plugin_id): void {
    if ($holder === NULL) {
      return;
    }
    $showing = $this->verdictStamper->showing($canvas, $holder, $uuid);
    $claimable = $canvas !== NULL && $showing !== NULL && $showing->claimable;
    if ($claimable) {
      $build['#attributes'][SlotBlock::CLAIM_ATTRIBUTE] = $showing->blueprint;
    }
    $portal = $this->portalOf($canvas, $holder, $uuid, $plugin_id, $showing);
    $entity = $portal === NULL ? NULL : $this->entityTypeManager->getStorage($portal['entity_type'])->load($portal['entity_id']);
    if ($entity === NULL || !$this->daedalusUi->hasEditingUrl($entity)) {
      if ($claimable) {
        $build['#attributes']['data-daedalus-ui-portal-label'] = $showing->document['label'];
      }
      return;
    }
    $url = $this->daedalusUi->getEditingUrl($entity)->toString(TRUE);
    BubbleableMetadata::createFromRenderArray($build)->addCacheableDependency($url)->applyTo($build);
    $build['#attributes']['data-daedalus-ui-portal-url'] = $url->getGeneratedUrl();
    $build['#attributes']['data-daedalus-ui-portal-label'] = (string) $entity->label();
    $build['#attributes']['data-daedalus-ui-portal-type'] = $this->daedalusUi->hostKind($this->hostResolver->fromEntity($entity));
  }

  /**
   * The host a placement is a portal to, or NULL when it wears none.
   *
   * A join showing a blueprint by reference the actor may open
   * (Showing::DEFAULT with a portal) is a portal to that blueprint: the
   * showing's answer. Every other node's portal is the spine's content
   * verdict on it, seen from this canvas (VerdictStamper::verdictsOn()): a
   * FOREIGN_DOCUMENT refusal's context carries the portal the spine offers
   * (a host the actor may act on: the template a locked page renders
   * inside, the site frame around the route, the holder of a door whose
   * default the actor may not open but whose holder they may, to set
   * another default there). The portal answers for content alone (edit
   * this where its content is owned), so the one refusal covering the
   * content capability decides and the refusals on the other capabilities
   * say nothing about a door: a template's field block presenting the
   * page's own field keeps content on a locked page (the lock's one open
   * capability) and wears none, whatever its structural refusals carry,
   * and a node whose content is refused for a reason other than ownership
   * (a field access denial, a role policy) wears none either, since the
   * block is still the page's.
   *
   * Three placements are never asked. On a canvas whose host owns the
   * whole document it renders, only a linked placement can be foreign, so
   * every other block is left alone (the verdict walks the document per
   * target). A node of a blueprint's document shown by a linked placement
   * is inside the placement's own portal (the inner keys are not in the
   * projection; the block root is the portal), so it carries none of its
   * own. The frame slot hosting the route's output (Showing::ROUTE: the
   * main slot on a page; on a blueprint's canvas the door whose default is
   * that blueprint) holds the page itself, and a portal on it would frame
   * every page's content with the frame's door; the frame is reached
   * through its other nodes.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The route host, or NULL on a route with none.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the placement.
   * @param string $uuid
   *   The component uuid.
   * @param string $plugin_id
   *   The component's block plugin id.
   * @param \Drupal\daedalus_blueprint\Capability\Showing|null $showing
   *   The placement's showing, NULL for a node that is no join.
   *
   * @return array{entity_type: string, entity_id: string}|null
   *   The portal host's identity, or NULL.
   */
  private function portalOf(?HostInterface $canvas, HostInterface $holder, string $uuid, string $plugin_id, ?Showing $showing): ?array {
    // The canvas's own render: the holder is the canvas host, or the host
    // whose document the canvas host renders (its template, on a page
    // never overridden; the template canvas's sample subject renders the
    // template).
    $own_canvas = $canvas !== NULL
      && (self::sameHost($canvas, $holder) || self::sameHost($this->hostResolver->renderHost($canvas), $holder));
    if ($own_canvas && !LinkedBlueprintBlock::isLinked($plugin_id) && $this->hostResolver->foreignOwner($canvas) === NULL) {
      return NULL;
    }
    if (!$own_canvas && $holder instanceof BlueprintHost) {
      return NULL;
    }
    if ($showing !== NULL && $showing->shows === Showing::ROUTE) {
      return NULL;
    }
    if ($showing?->portal !== NULL) {
      return $showing->portal->toArray();
    }
    // Each refused capability sits in exactly one refusal; the one holding
    // content is the portal's, and content allowed (no such refusal) is no
    // portal.
    foreach ($this->verdictStamper->verdictsOn($canvas, $holder, $uuid)['refused'] as $refusal) {
      if (!in_array(Capability::Content->value, $refusal['capabilities'], TRUE)) {
        continue;
      }
      if ($refusal['code'] !== 'FOREIGN_DOCUMENT') {
        return NULL;
      }
      $portal = $refusal['context']['portal'] ?? NULL;
      return is_array($portal) && isset($portal['entity_type'], $portal['entity_id']) ? $portal : NULL;
    }
    return NULL;
  }

  /**
   * The host whose document holds the component being built.
   *
   * The render names the host whose document a section belongs to in
   * daedalus_layout's root entity context (the inner host of the render
   * primitive: the site frame for the frame's own sections, a blueprint for
   * the sections a linked placement shows, the page for its own). Which
   * document that root RENDERS is the resolver's answer (renderHost: a
   * locked page's root renders its template, so a template's node on a
   * page is attributed to the template; the template canvas's sample
   * subject renders the template too). NULL when the render names no root
   * (a render outside daedalus_layout's), which the caller reads as the
   * route host's own.
   */
  private function holderOf(SectionComponentBuildRenderArrayEvent $event): ?HostInterface {
    $root = $event->getContexts()['daedalus_layout.root_entity'] ?? NULL;
    if ($root === NULL || !$root->hasContextValue()) {
      return NULL;
    }
    $entity = $root->getContextValue();
    if (!$entity instanceof EntityInterface || !$this->hostResolver->hosts($entity)) {
      return NULL;
    }
    return $this->hostResolver->renderHost($this->hostResolver->fromEntity($entity));
  }

  /**
   * Whether the component builds inside a site frame's render.
   *
   * The frame render names the frame in its contexts (FrameHost::CONTEXT_ID,
   * set by InnerRender::renderFrame() alone and forwarded through every
   * document the frame shows). Read by its literal id: this module keeps no
   * dependency on the constant's class beyond the spine it already uses.
   */
  private function inFrameRender(SectionComponentBuildRenderArrayEvent $event): bool {
    $frame = $event->getContexts()['daedalus_blueprint.frame'] ?? NULL;
    return $frame !== NULL && $frame->hasContextValue();
  }

  /**
   * Whether two host objects address the same document.
   */
  private static function sameHost(HostInterface $a, HostInterface $b): bool {
    return $a->entityTypeId() === $b->entityTypeId() && $a->id() === $b->id();
  }

  /**
   * The entity whose style map a live-attribution demand for this block asks.
   *
   * The host whose document holds the placement: daedalus_layout's root
   * entity context when the render names one (the inner host of a linked
   * blueprint, the site frame; read by its literal id, as this module keeps
   * no daedalus_layout dependency), else the subject, which within one
   * document is the same entity (and whose maps include its template's).
   */
  private function demandEntity(SectionComponentBuildRenderArrayEvent $event): ?EntityInterface {
    $root = $event->getContexts()['daedalus_layout.root_entity'] ?? NULL;
    if ($root !== NULL && $root->hasContextValue() && $root->getContextValue() instanceof EntityInterface) {
      return $root->getContextValue();
    }
    return $this->contextEntity($event);
  }

  /**
   * The entity this component renders for, from the event's contexts.
   *
   * For nested layout-block content this is the block_content entity, not
   * the page's root — demand providers resolve the entity their data
   * actually lives on.
   */
  private function contextEntity(SectionComponentBuildRenderArrayEvent $event): ?EntityInterface {
    foreach (['layout_builder.entity', 'entity'] as $id) {
      $context = $event->getContexts()[$id] ?? NULL;
      if ($context !== NULL && $context->hasContextValue()) {
        $value = $context->getContextValue();
        if ($value instanceof EntityInterface) {
          return $value;
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[LayoutBuilderEvents::SECTION_COMPONENT_BUILD_RENDER_ARRAY] = ['onBuildRender', -100];
    return $events;
  }

}
