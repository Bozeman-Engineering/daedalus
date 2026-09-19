<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Applies registered transforms to layout stores.
 *
 * Three transform kinds share one spine (walkers, presave net):
 * - Component transforms (tag daedalus_layout.component_transform) rewrite one
 *   component shape in place.
 * - Section transforms (tag daedalus_layout.section_transform) rewrite one section
 *   shape, returning the section to keep (core sections cannot change
 *   layout id in place); the registry writes replacements back into the
 *   holding list or embedded container configuration.
 * - Host transforms (tag daedalus_layout.host_transform) run once per content host
 *   after its section walk, for host-level state (e.g. style maps).
 *
 * Tagged services are collected by DaedalusLayoutServiceProvider in tag priority
 * order (higher first) and injected here as service IDs. Transforms are
 * lazy-loaded from the container so a registered transform costs nothing
 * until a spine walk or the presave safety net actually runs (same pattern
 * as daedalus_tempstore strategies).
 */
class ComponentTransformRegistry {

  /**
   * Constructs a ComponentTransformRegistry.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param string[] $transformIds
   *   Component transform service IDs in priority order (first = highest).
   * @param string[] $sectionTransformIds
   *   Section transform service IDs in priority order.
   * @param string[] $hostTransformIds
   *   Host transform service IDs in priority order.
   */
  public function __construct(
    protected ContainerInterface $container,
    protected array $transformIds = [],
    protected array $sectionTransformIds = [],
    protected array $hostTransformIds = [],
  ) {}

  /**
   * Whether any transforms are registered.
   *
   * Lets callers (batched walks, the presave net) skip work entirely when
   * no deprecation is in flight.
   */
  public function hasTransforms(): bool {
    return !empty($this->transformIds) || !empty($this->sectionTransformIds) || !empty($this->hostTransformIds);
  }

  /**
   * Applies every matching transform to a component.
   *
   * Transforms run in priority order and each one's applies() is evaluated
   * against the component's current state, so a transform may match the
   * output of an earlier one in the same pass (a migration chain).
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component, rewritten in place by matching transforms.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the component.
   *
   * @return bool
   *   TRUE if at least one transform rewrote the component, so callers know
   *   the containing store is dirty and needs persisting.
   */
  public function transform(SectionComponent $component, EntityInterface $host): bool {
    $transformed = FALSE;
    foreach ($this->transformIds as $transform_id) {
      /** @var \Drupal\daedalus_layout\ComponentTransform\ComponentTransformInterface $transform */
      $transform = $this->container->get($transform_id);
      if ($transform->applies($component, $host)) {
        $transform->transform($component, $host);
        $transformed = TRUE;
      }
    }
    return $transformed;
  }

  /**
   * Applies every matching section transform to a section.
   *
   * Like component transforms, each transform's applies() is evaluated
   * against the section's CURRENT state, so a transform may match the
   * output of an earlier one (a migration chain).
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to examine.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the section.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The section to keep (which may be the given instance, mutated) when
   *   any transform applied; NULL when none did.
   */
  protected function applySectionTransforms(Section $section, EntityInterface $host): ?Section {
    $applied = FALSE;
    foreach ($this->sectionTransformIds as $transform_id) {
      /** @var \Drupal\daedalus_layout\ComponentTransform\SectionTransformInterface $transform */
      $transform = $this->container->get($transform_id);
      if ($transform->applies($section, $host)) {
        $section = $transform->transform($section, $host);
        $applied = TRUE;
      }
    }
    return $applied ? $section : NULL;
  }

  /**
   * Applies every matching transform to every component of some sections.
   *
   * Section transforms run first per section (a structural rewrite may mint
   * container components the component walk should then descend), replacing
   * the array entry when a transform returns a new instance. Component
   * transforms then run per component, and the walk recurses into
   * nested-section containers: a container embeds a whole subtree in its
   * configuration, and core's walkers (and the store walkers built on this
   * registry) only see the top level, so descending here once is what makes
   * every present and future transform cover depth for free. The component
   * itself transforms first (a transform may produce a container; the
   * legacy layout-block migration does) and the embedded section is then
   * descended and re-encoded if anything inside changed.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to rewrite; entries are REPLACED in the given array when
   *   a section transform returns a new instance, so callers holding a
   *   plain array (an embedded section, a test fixture) must persist from
   *   this array afterwards. transformSectionList() does that for real
   *   stores.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the sections.
   *
   * @return bool
   *   TRUE if at least one section or component was rewritten.
   */
  public function transformSections(array &$sections, EntityInterface $host): bool {
    $changed = FALSE;
    foreach ($sections as $delta => $section) {
      if ($replacement = $this->applySectionTransforms($section, $host)) {
        $sections[$delta] = $section = $replacement;
        $changed = TRUE;
      }
      foreach ($section->getComponents() as $original_uuid => $component) {
        $changed = $this->transform($component, $host) || $changed;
        // An identity-changing transform (the layout-block migration
        // converges the component uuid onto its section's) strands the
        // component under its old array key; Section::getComponent(), and
        // the host layout field, whose storage serializes the Section
        // OBJECT, keys included, would keep serving the stale key. Re-key
        // by re-adding, restoring the weight appendComponent() re-assigns.
        if ($component->getUuid() !== $original_uuid) {
          $weight = $component->getWeight();
          $section->removeComponent($original_uuid);
          $section->appendComponent($component);
          $component->setWeight($weight);
        }
        $embedded = SectionBlock::embeddedSection($component);
        if ($embedded) {
          $embedded_list = [$embedded];
          if ($this->transformSections($embedded_list, $host)) {
            $configuration = $component->get('configuration');
            $configuration[SectionBlock::SECTION_KEY] = $embedded_list[0]->toArray();
            $component->setConfiguration($configuration);
            $changed = TRUE;
          }
        }
      }
    }
    return $changed;
  }

  /**
   * Applies every matching transform to a section list, writing back.
   *
   * The one entry point for real stores (host layout fields, entity view
   * displays): section-transform replacements are written back through
   * SectionListInterface::setSection(), which is what makes replacement
   * sections persist (in-place component mutations already persist because
   * the list serves live Section objects).
   *
   * @param \Drupal\layout_builder\SectionListInterface $list
   *   The section list (a layout field item list or a display).
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the sections.
   *
   * @return bool
   *   TRUE if at least one section or component was rewritten.
   */
  public function transformSectionList(SectionListInterface $list, EntityInterface $host): bool {
    $sections = $list->getSections();
    if (!$this->transformSections($sections, $host)) {
      return FALSE;
    }
    foreach ($sections as $delta => $section) {
      $list->setSection($delta, $section);
    }
    return TRUE;
  }

  /**
   * Applies every matching transform to a host's layout section fields.
   *
   * An untranslatable layout field shares its values across translations, so
   * it is processed once on the given host object. A translatable layout
   * field is walked per translation, and the translation object is the host
   * the transforms receive, so per-language host state (labels, style
   * fields) resolves correctly. Host transforms run last, once for the
   * given host object, after every section walk converged the tree.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $host
   *   The host entity (or revision).
   * @param string[] $field_names
   *   The layout_section field names to process; names the host does not
   *   carry are skipped.
   *
   * @return bool
   *   TRUE if anything was rewritten, so the host is dirty.
   */
  public function transformLayoutFields(ContentEntityInterface $host, array $field_names): bool {
    $changed = FALSE;
    foreach ($field_names as $field_name) {
      if (!$host->hasField($field_name)) {
        continue;
      }
      $translations = [$host];
      if ($host->getFieldDefinition($field_name)->isTranslatable()) {
        $translations = [];
        foreach (array_keys($host->getTranslationLanguages()) as $langcode) {
          $translations[] = $host->getTranslation($langcode);
        }
      }
      foreach ($translations as $translation) {
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $field */
        $field = $translation->get($field_name);
        if ($field->isEmpty()) {
          continue;
        }
        $changed = $this->transformSectionList($field, $translation) || $changed;
      }
    }
    foreach ($this->hostTransformIds as $transform_id) {
      /** @var \Drupal\daedalus_layout\ComponentTransform\HostTransformInterface $transform */
      $transform = $this->container->get($transform_id);
      $changed = $transform->transformHost($host) || $changed;
    }
    return $changed;
  }

}
