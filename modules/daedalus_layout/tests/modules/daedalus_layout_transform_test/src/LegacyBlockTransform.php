<?php

namespace Drupal\daedalus_layout_transform_test;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformInterface;

/**
 * Rewrites inline_block:legacy components to inline_block:modern.
 */
class LegacyBlockTransform implements ComponentTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool {
    return $component->getPluginId() === 'inline_block:legacy';
  }

  /**
   * {@inheritdoc}
   */
  public function transform(SectionComponent $component, EntityInterface $host): void {
    $configuration = $component->get('configuration');
    $configuration['id'] = 'inline_block:modern';
    $configuration['migrated_from'] = $host->label();
    // The block_serialized contract (see ComponentTransformInterface): when
    // the component carries a serialized block copy, the replacement entity
    // is written back serialized and unsaved.
    if (!empty($configuration['block_serialized'])) {
      // Layout Builder's own inline-block payload (InlineBlock::blockSubmit()).
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $copy = unserialize($configuration['block_serialized']);
      $copy->set('info', 'Migrated copy');
      $configuration['block_serialized'] = serialize($copy);
    }
    $component->setConfiguration($configuration);
    TransformLog::$calls[] = 'legacy:' . $component->getUuid();
  }

}
