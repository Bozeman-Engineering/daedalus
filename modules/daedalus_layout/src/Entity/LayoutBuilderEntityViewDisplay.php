<?php

namespace Drupal\daedalus_layout\Entity;

use Drupal\Core\Render\Element;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay as BaseEntityViewDisplay;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\Render\ElementAddress;

/**
 * Provides an entity view display entity that has a layout.
 */
class LayoutBuilderEntityViewDisplay extends BaseEntityViewDisplay {

  /**
   * The class of the layout root, the one element the root sections render in.
   *
   * Declared as entity anatomy in daedalus_layout.page_builder.yml
   * (entity.elements.'.daedalus-layout-layout'); the string here and the key there
   * are one fact. Two renders wear it, one per mode: buildSections() below
   * (the view render) and the layout_builder_plus element's container
   * (the edit canvas, \Drupal\daedalus_layout\Element\LayoutBuilderPlus::layout()),
   * so the page's arranging box is the same element live and edit.
   */
  public const LAYOUT_ROOT_CLASS = 'daedalus-layout-layout';

  /**
   * {@inheritdoc}
   *
   * Renders the root sections inside ONE element, the layout root
   * (div.daedalus-layout-layout), in every view mode and for every host shape.
   * This is the VIEW render's root; on the edit canvas the route entity's
   * sections render through the layout_builder_plus element instead
   * (daedalus_ui's EditableUiBuilder event, daedalus_layout's subscriber
   * swapping this list out), whose container wears the same class.
   * Core returns the sections as bare siblings keyed by delta and the
   * theme's entity template wraps {{ content }} however it likes, so
   * nothing the suite owned was the box that lays out the page's bands: a
   * gap between them (the container model's "a box arranges its children",
   * reaching the page) had no element to act on. The section list is
   * markup daedalus_layout builds, so daedalus_layout gives it its box: the list itself
   * becomes a container element wearing the class, the deltas staying its
   * children (getSectionAttributes() and core's shape are untouched).
   *
   * Only while sections exist: an empty list stays the bare `#cache`-only
   * array core returns, so Element::isEmpty() in core's buildMultiple()
   * keeps rendering the display's fields on a section-less entity, and the
   * declared part is presence-gated exactly as its anatomy says (an entity
   * with no layout has no layout root). The drag placeholder for a new
   * root section lands inside the root beside its siblings (daedalus_ui
   * inserts against a section's own parent) and a blank page's drop lands
   * in the entity root; either way the placement is delivered as a
   * whole-entity re-render, so the root needs no client insertion logic.
   *
   * The sections render against the HOST'S SUBJECT (getContextsForEntity()
   * states it), not against core's choice of the rendered entity: core's
   * buildSections() hands every section the entity itself as
   * layout_builder.entity, which is right for a page (its subject is itself)
   * and wrong for a saved blueprint, whose document is material for hosts of
   * another kind: with the blueprint as layout_builder.entity the subject's
   * field blocks are forbidden (FieldBlock::blockAccess asks hasField() on
   * the blueprint) and a live view shows only the non-field blocks while
   * the canvas shows them all. So the loop is core's, over our contexts.
   *
   * A slot section of the document (a fill of a slot the template or the
   * site frame around it declares) is left out: its place on the page is
   * the slot's, where the slot block renders it
   * (InnerRender::renderedBySlot()).
   */
  protected function buildSections(FieldableEntityInterface $entity) {
    $contexts = $this->getContextsForEntity($entity);

    $cacheability = new CacheableMetadata();
    $storage = $this->getSectionStorage()->findByContext($contexts, $cacheability);

    $build = [];
    if ($storage) {
      $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromSectionStorage($storage);
      /** @var \Drupal\daedalus_blueprint\Host\InnerRender $inner_render */
      $inner_render = \Drupal::service('daedalus_blueprint.inner_render');
      foreach ($storage->getSections() as $delta => $section) {
        if ($host !== NULL && $inner_render->renderedBySlot($host, $section)) {
          continue;
        }
        $build[$delta] = $section->toRenderArray($contexts);
        $this->getSectionAttributes($delta, $build[$delta], $storage, $section, $entity);
      }
    }
    // The render array is built on decisions the section storage plugins
    // made, so it depends on their accumulated cacheability.
    $cacheability->applyTo($build);
    $build['#cache']['contexts'][] = 'user.permissions';

    if (Element::children($build) !== []) {
      $build['#type'] = 'container';
      $build['#attributes']['class'][] = self::LAYOUT_ROOT_CLASS;
    }
    return $build;
  }

  /**
   * Gets the section storage plugin manager.
   *
   * @return \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface
   *   The section storage manager.
   */
  protected function getSectionStorage() {
    return \Drupal::service('plugin.manager.layout_builder.section_storage');
  }

  /**
   * {@inheritdoc}
   *
   * The override field follows Layout Builder being enabled on the bundle,
   * not overrides being allowed. Core adds `layout_builder__layout` when a
   * display becomes overridable and deletes it when the last overridable
   * display of the bundle stops being so; a Daedalus page needs the field
   * on a LOCKED bundle too, because a page rendered inside its template
   * still owns a document there (its slot sections; the host's
   * ContentHost::hasDocument() and HostResolver::foreignOwner() are the
   * two answers). So the field is added whenever the display is enabled,
   * overridable or not (idempotent: core's addSectionField() creates only
   * what is missing), and removed only when no display of the bundle is
   * Layout Builder enabled any more (removeSectionField() below widens
   * core's criterion the same one step). Core's own add and remove run
   * first, inside the parent, so a lock (overridable to not) that core
   * would answer with a delete answers with the field kept.
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $entity_type_id = $this->getTargetEntityTypeId();
    $bundle = $this->getTargetBundle();
    if ($this->isLayoutBuilderEnabled()) {
      $this->addSectionField($entity_type_id, $bundle, OverridesSectionStorage::FIELD_NAME);
    }
    elseif ($this->getOriginal()?->isLayoutBuilderEnabled() ?? FALSE) {
      $this->removeSectionField($entity_type_id, $bundle, OverridesSectionStorage::FIELD_NAME);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Removes the field only when no display of the bundle is Layout Builder
   * enabled: core asks whether another display is overridable, which on a
   * lock answers "none" and would delete the pages' slot sections with the
   * field. This display's own answer is its unsaved state, so a display
   * being disabled counts itself out and one being locked counts itself
   * in.
   */
  protected function removeSectionField($entity_type_id, $bundle, $field_name) {
    if ($this->isLayoutBuilderEnabled()) {
      return;
    }
    $query = $this->entityTypeManager()->getStorage($this->getEntityTypeId())->getQuery()
      ->accessCheck(FALSE)
      ->condition('targetEntityType', $entity_type_id)
      ->condition('bundle', $bundle)
      ->condition('mode', $this->getMode(), '<>')
      ->condition('third_party_settings.layout_builder.enabled', TRUE);
    if ((bool) $query->count()->execute()) {
      return;
    }
    parent::removeSectionField($entity_type_id, $bundle, $field_name);
  }

  /**
   * {@inheritdoc}
   *
   * Core's removal walk is one level deep and has surgical semantics:
   * remove a section only when its LAYOUT depends on removed config,
   * otherwise remove just the offending components. A nested-section
   * container reports its whole subtree's dependencies
   * (SectionBlock::calculateDependencies — that aggregation is what makes
   * core's calculateDependencies() correct at depth), so without recursion
   * here core would drop an entire container because one descendant block
   * depended on the removed config. Pruning the embedded trees first,
   * deepest first, restores the surgical semantics at every depth; by the
   * time core's own walk runs, a surviving container no longer carries the
   * removed dependency.
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = FALSE;
    foreach ($this->getSections() as $section) {
      $changed = $this->pruneEmbeddedSections($section, $dependencies) || $changed;
    }
    $core_changed = parent::onDependencyRemoval($dependencies);
    return $changed || $core_changed;
  }

  /**
   * Prunes removed dependencies from a section's embedded subtrees.
   *
   * Mirrors core's per-section semantics at every depth (post-order): an
   * embedded section whose layout depends on removed config is removed
   * WITH its container component (a container embeds exactly one section,
   * so removing a nested section means removing its container); otherwise
   * its offending child components are removed individually. Mutated
   * embedded sections are re-encoded into their container's configuration
   * on the way up (fresh toArray() through
   * SectionComponent::setConfiguration(), never through a plugin instance).
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section whose container components to prune. The section's own
   *   layout and non-container components are the caller's business (core's
   *   walk for root sections, the parent recursion level for embedded
   *   ones).
   * @param array $dependencies
   *   The removed dependencies, as passed to onDependencyRemoval().
   *
   * @return bool
   *   TRUE when anything was removed or rewritten.
   */
  protected function pruneEmbeddedSections(Section $section, array $dependencies): bool {
    $changed = FALSE;
    foreach ($section->getComponents() as $uuid => $component) {
      if (!SectionBlock::isSectionComponent($component)) {
        continue;
      }
      $embedded = SectionBlock::embeddedSection($component);
      if ($embedded === NULL) {
        continue;
      }

      // The embedded layout going away takes the section with it, and with
      // it the container component (an empty container is not a state).
      $layout_dependencies = $this->getPluginDependencies($embedded->getLayout());
      if ($this->getPluginRemovedDependencies($layout_dependencies, $dependencies)) {
        $section->removeComponent($uuid);
        $changed = TRUE;
        continue;
      }

      // Post-order: deeper containers first, so their write-backs land in
      // this embedded section before it re-encodes itself.
      $embedded_changed = $this->pruneEmbeddedSections($embedded, $dependencies);
      foreach ($embedded->getComponents() as $child_uuid => $child) {
        // Containers were handled by the recursion above.
        if (SectionBlock::isSectionComponent($child)) {
          continue;
        }
        $plugin_dependencies = $this->getPluginDependencies($child->getPlugin());
        if ($this->getPluginRemovedDependencies($plugin_dependencies, $dependencies)) {
          $embedded->removeComponent($child_uuid);
          $embedded_changed = TRUE;
        }
      }

      if ($embedded_changed) {
        $configuration = $component->get('configuration');
        $configuration[SectionBlock::SECTION_KEY] = $embedded->toArray();
        $component->setConfiguration($configuration);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * {@inheritdoc}
   *
   * The contexts a live render's sections render with, the same rule the
   * edit canvas states (LayoutBuilderPlus::renderContexts()): the host's
   * subject is layout_builder.entity and the stable root-entity context
   * (NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID), one context
   * object for both, over the contexts core resolves the storage with. The
   * subject is the entity itself for a page and for an inline block's
   * content, and the sample entity of the subject bundle for a saved
   * blueprint (HostInterface::subject()); the `entity` context stays the
   * rendered entity, since it is what findByContext resolves the storage
   * by. For an inline block's content rendering its own Layout Builder
   * display the root comes from the RootEntityRegistry instead (recorded
   * when the inline-block component built in its host render, so it is
   * already the host's subject), which is what lets a field block mapped
   * to ROOT_ENTITY_CONTEXT_ID (e.g. a node title block moved into a nested
   * layout) render at any depth.
   */
  protected function getContextsForEntity(FieldableEntityInterface $entity) {
    $contexts = parent::getContextsForEntity($entity);
    $subject = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($entity)->subject();
    // A fieldable entity's host is a content host, whose subject is itself
    // or a sample of its bundle, never absent.
    assert($subject !== NULL);
    $subject_context = EntityContext::fromEntity($subject, new TranslatableMarkup('@entity being viewed', [
      '@entity' => $subject->getEntityType()->getSingularLabel(),
    ]));
    $contexts['layout_builder.entity'] = $subject_context;
    $root = \Drupal::service('daedalus_layout.root_entity_registry')->getRoot($entity);
    $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = $root ? EntityContext::fromEntity($root) : $subject_context;
    return $contexts;
  }

  /**
   * Stamps a section render array with its admin and selection attributes.
   *
   * Serves both render sites: root sections (buildSections and the edit-mode
   * canvas element) and nested sections (NestedSectionRenderArray stamps the
   * container render with delta 0 — the local delta of the container's
   * one-section list; the NestedRouteEnhancer recomputes core-form deltas by
   * UUID, so the local delta is what URLs built from the markup should
   * carry).
   *
   * @param int|string $delta
   *   The section delta within its own list (local delta for nested).
   * @param array $build
   *   The section's layout render array.
   * @param mixed $storage
   *   The section storage, when available. NULL at the container render
   *   seam: the storage type/id attributes describe the ROOT storage and the
   *   JS reads them from drupalSettings['Daedalus Layout'] anyway, so nested sections
   *   carry only the delta.
   * @param \Drupal\layout_builder\Section|null $section
   *   The section, when available. Enables selection-identity stamping.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity the sections render for, when available. Enables
   *   live-attribution demand checks (a styled section's CSS scopes to its
   *   selectable key, which must then reach non-editor markup). At nested
   *   depth this is the ROOT entity — the style-map host that answers
   *   demand.
   *
   * @return array
   *   The section build with its data attributes set.
   */
  public static function getSectionAttributes(int|string $delta, array &$build, $storage = NULL, ?Section $section = NULL, ?EntityInterface $entity = NULL): array {
    // Attribute the rendered sections so that the Daedalus Edit JS can make sense
    // of the page.
    $build['#attributes']['data-layout-builder-section-delta'] = $delta;
    if ($storage !== NULL) {
      $build['#attributes']['data-layout-builder-section-storage-type'] = $storage->getStorageType();
      $build['#attributes']['data-layout-builder-section-storage-id'] = $storage->getStorageId();
    }
    // The section's element address (daedalus_ui ElementAddress), in every
    // mode and for every visitor, at every depth: the layout declaration
    // the section resolves through. It rides the layout template's
    // {{ attributes }} exactly like the selectable key below, and a
    // composed layout hoists the same bag onto its composed root.
    if ($section !== NULL) {
      $build['#attributes'][ElementAddress::ATTRIBUTE] = ElementAddress::layout($section->getLayoutId());
    }

    // Stamp the section's selection identity (its selectable key). $build is
    // the section's layout render array, so the container key rides the
    // layout template's {{ attributes }}. Regions carry no selectable keys:
    // a container's children are one ordered flow, and a multi-region
    // layout's named slots are anatomy, not addressable elements.
    $section_uuid = $section?->getThirdPartySetting('daedalus_layout', 'uuid');
    if ($section_uuid) {
      $editable = \Drupal::currentUser()->hasPermission('use daedalus ui edit mode');
      $edit_mode = $editable && \Drupal::service('daedalus_ui.mode_state')->getMode() === 'edit';
      // Outside the editing permission, keys are stamped per demand: only
      // where live-page consumers (e.g. Daedalus Stylish CSS @scope roots) target
      // them. Attributes ride existing elements — bytes only, no structure
      // change; unstyled renders stay byte-identical.
      $demand = $editable || $entity === NULL ? NULL : \Drupal::service('daedalus_ui.live_attribution_demand');
      $section_key = SelectableKey::container($section_uuid);
      if ($editable || $demand?->demandsKey($section_key, $entity)) {
        $build['#attributes'][SelectableKey::ATTRIBUTE] = $section_key;
      }

      if ($edit_mode) {
        // Emptiness is NOT stamped here: it is a root-form fact the element
        // resolver answers once ({empty: true}), and the composed container
        // render prints the matched empty form's declared drop-affordance
        // attributes with the tree (daedalus_ui ComposedLayoutRenderer).
        foreach (Element::children($build) as $region_name) {
          // The region NAME feeds core-form URLs the editing JS builds
          // (closest('[region]')). Root sections get it from the canvas
          // element's admin wrapper too; stamping here covers nested
          // sections with the identical attribute.
          $build[$region_name]['#attributes']['region'] = (string) $region_name;
        }
      }
    }
    return $build;
  }

}
