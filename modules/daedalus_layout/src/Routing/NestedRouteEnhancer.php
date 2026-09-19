<?php

namespace Drupal\daedalus_layout\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\daedalus_layout\NestedSectionStorageInterface;

/**
 * Route enhancer that auto-detects nested context for Layout Builder routes.
 *
 * Handles two scenarios:
 *
 * 1. Block routes with {uuid} parameter:
 *    Look up block UUID in TreeIndex → if nested, scope storage and fix delta.
 *
 * 2. Section routes with section_uuid query parameter:
 *    Look up section UUID in TreeIndex → derive local delta, scope storage.
 *    This enables core section forms (ConfigureSectionForm, RemoveSectionForm)
 *    to work transparently with nested layouts when links include
 *    ?section_uuid=<uuid>.
 *
 * This enables core and contrib Layout Builder routes to work transparently
 * with nested layouts without requiring route/form overrides.
 */
class NestedRouteEnhancer implements EnhancerInterface {

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array {
    if (!isset($defaults['section_storage'])) {
      return $defaults;
    }

    $storage = $defaults['section_storage'];
    if (!$storage instanceof NestedSectionStorageInterface) {
      return $defaults;
    }

    // Scenario 1: Block routes with {uuid} parameter.
    if (isset($defaults['uuid'])) {
      return $this->enhanceBlockRoute($defaults, $request, $storage);
    }

    // Scenario 2: Section routes with section_uuid query parameter.
    $sectionUuid = $request->query->get('section_uuid');
    if ($sectionUuid && isset($defaults['delta'])) {
      return $this->enhanceSectionRoute($defaults, $request, $storage, $sectionUuid);
    }

    return $defaults;
  }

  /**
   * Enhances block routes with {uuid} parameter.
   *
   * @param array $defaults
   *   The route defaults.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\daedalus_layout\NestedSectionStorageInterface $storage
   *   The nested-aware section storage.
   *
   * @return array
   *   The enhanced route defaults.
   */
  protected function enhanceBlockRoute(array $defaults, Request $request, NestedSectionStorageInterface $storage): array {
    $context = $storage->getNestedContext($defaults['uuid']);
    if (!$context) {
      return $defaults;
    }

    // Use local delta coordinates.
    if (isset($defaults['delta'])) {
      $defaults['delta'] = $context['localDelta'];
    }

    // Scope the storage to the block's parent section.
    $defaults['section_storage'] = $storage->forSection($context['sectionUuid']);

    return $defaults;
  }

  /**
   * Enhances section routes with section_uuid query parameter.
   *
   * Looks up the section UUID in TreeIndex, derives the local delta and
   * section scope, then transforms the route defaults so core section
   * forms work transparently with nested sections.
   *
   * @param array $defaults
   *   The route defaults.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\daedalus_layout\NestedSectionStorageInterface $storage
   *   The nested-aware section storage.
   * @param string $sectionUuid
   *   The section UUID from the query parameter.
   *
   * @return array
   *   The enhanced route defaults.
   */
  protected function enhanceSectionRoute(array $defaults, Request $request, NestedSectionStorageInterface $storage, string $sectionUuid): array {
    $context = $storage->getNestedContext($sectionUuid);
    if (!$context) {
      // Root-level section: still need to fix delta from UUID.
      $path = $storage->getPath($sectionUuid);
      if ($path) {
        $defaults['delta'] = $path[count($path) - 1];
      }
      return $defaults;
    }

    $defaults['delta'] = $context['localDelta'];

    // Scope the storage to the nested section (its embedded list holds it
    // at the local delta).
    $defaults['section_storage'] = $storage->forSection($context['sectionUuid']);

    return $defaults;
  }

}
