<?php

namespace Drupal\daedalus_layout\Entity;

use Drupal\Core\Render\Element;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
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
   * array core returns, so Element::isEmpty() in buildMultiple() keeps
   * rendering the display's fields on a section-less entity, and the
   * declared part is presence-gated exactly as its anatomy says (an entity
   * with no layout has no layout root). The drag placeholder for a new
   * root section lands inside the root beside its siblings (daedalus_ui
   * inserts against a section's own parent) and a blank page's drop lands
   * in the entity root; either way the placement is delivered as a
   * whole-entity re-render, so the root needs no client insertion logic.
   */
  protected function buildSections(FieldableEntityInterface $entity) {
    $build = parent::buildSections($entity);
    $build['#cache']['contexts'][] = 'user.permissions';

    $contexts = $this->getContextsForEntity($entity);
    $label = new TranslatableMarkup('@entity being viewed', [
      '@entity' => $entity->getEntityType()->getSingularLabel(),
    ]);
    $contexts['layout_builder.entity'] = EntityContext::fromEntity($entity, $label);

    $cacheability = new CacheableMetadata();
    $storage = $this->getSectionStorage()->findByContext($contexts, $cacheability);

    if ($storage) {
      foreach ($storage->getSections() as $delta => $section) {
        $this->getSectionAttributes($delta, $build[$delta], $storage, $section, $entity);
      }
    }
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
   * Adds the stable root-entity context to every Layout Builder render.
   * For an inline block's content rendering its own Layout Builder display
   * the root comes from the RootEntityRegistry (recorded when the
   * inline-block component built in its host render); for a root entity it
   * is the entity itself. This is what lets a field block mapped to
   * ROOT_ENTITY_CONTEXT_ID (e.g. a node title block moved into a nested
   * layout) render at any depth.
   */
  protected function getContextsForEntity(FieldableEntityInterface $entity) {
    $contexts = parent::getContextsForEntity($entity);
    $root = \Drupal::service('daedalus_layout.root_entity_registry')->getRoot($entity) ?? $entity;
    $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = EntityContext::fromEntity($root);
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
  public static function getSectionAttributes(int|string $delta, array &$build, $storage = NULL, ?Section $section = NULL, ?FieldableEntityInterface $entity = NULL): array {
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

  /**
   * Build Multiple.
   *
   * Fields configured in the "Daedalus Edit: Preserved Fields" section will not be
   * removed from the render array, allowing them to be edited inline with
   * in Edit Mode while Layout Builder manages the rest of the page structure.
   */
  public function buildMultiple(array $entities) {
    $fields_that_should_render = $this->getThirdPartySetting('daedalus_layout', 'preserved_fields', []);

    // Call grandparent to build the initial render array with all fields.
    $build_list = EntityViewDisplay::buildMultiple($entities);

    // Override parent below.
    // Layout Builder can not be enabled for the '_custom' view mode that is
    // used for on-the-fly rendering of fields in isolation from the entity.
    if ($this->isCustomMode()) {
      return $build_list;
    }

    foreach ($entities as $id => $entity) {
      $build_list[$id]['_layout_builder'] = $this->buildSections($entity);

      // If there are any sections, remove all fields with configurable display
      // from the existing build EXCEPT those configured to be preserved.
      // Preserved fields can be edited inline using Daedalus Edit.
      if (!Element::isEmpty($build_list[$id]['_layout_builder'])) {
        foreach ($build_list[$id] as $name => $build_part) {
          $field_definition = $this->getFieldDefinition($name);
          if (
            $field_definition &&
            $field_definition->isDisplayConfigurable($this->displayContext) &&
            // Omit removal of configured fields.
            !in_array($name, $fields_that_should_render, TRUE)
          ) {
            unset($build_list[$id][$name]);
          }
        }
      }
    }

    return $build_list;
  }

}
