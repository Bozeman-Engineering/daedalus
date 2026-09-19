<?php

namespace Drupal\daedalus_layout_transform_test;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformInterface;

/**
 * Annotates inline_block:modern components that lack the annotation.
 *
 * Matching the OUTPUT of LegacyBlockTransform proves that applies() is
 * re-evaluated against the component's current state within one registry
 * pass (a migration chain).
 */
class ModernAnnotator implements ComponentTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool {
    $configuration = $component->get('configuration');
    return $component->getPluginId() === 'inline_block:modern' && empty($configuration['annotated']);
  }

  /**
   * {@inheritdoc}
   */
  public function transform(SectionComponent $component, EntityInterface $host): void {
    $configuration = $component->get('configuration');
    $configuration['annotated'] = TRUE;
    $component->setConfiguration($configuration);
    TransformLog::$calls[] = 'annotator:' . $component->getUuid();
  }

}
