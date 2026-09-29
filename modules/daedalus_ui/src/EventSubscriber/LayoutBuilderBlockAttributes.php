<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
 * the presentation), stamped for editors, with the selectable key alone
 * also reaching visitors' markup where a live-attribution provider
 * demands it.
 */
final class LayoutBuilderBlockAttributes implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LiveAttributionDemand $liveAttribution,
    protected ElementModel $elementModel,
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
      $entity = $this->contextEntity($event);
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
    // on reload, and the derived default reads the component's own
    // label_display (an absent key means 'visible', core's block plugin
    // default).
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $bundle = substr($plugin_id, strlen('inline_block:'));
      $block_type = $this->entityTypeManager->getStorage('block_content_type')->load($bundle);
      if ($block_type) {
        $build['#cache']['tags'] = array_merge($build['#cache']['tags'] ?? [], $block_type->getCacheTags());
        $configuration = $component->get('configuration');
        $label_visible = ($configuration['label_display'] ?? 'visible') === 'visible';
        $presentation = $this->elementModel->bundlePresentation('block_content', $bundle, $label_visible);
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
    elseif (str_starts_with($plugin_id, 'field_block:')) {
      $entity = $this->contextEntity($event);
      $definition = $entity instanceof FieldableEntityInterface
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
