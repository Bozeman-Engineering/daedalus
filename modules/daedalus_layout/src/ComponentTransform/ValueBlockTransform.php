<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\SectionComponent;
use Psr\Log\LoggerInterface;

/**
 * Converts a display's saved inline blocks to value blocks.
 *
 * Before the display became a host, a default layout's inline blocks were
 * block_content entities named by revision id, exactly as a content host's
 * are. A display is config, and config export cannot carry a content id,
 * so a display host stores its blocks as values (InlineBlockValues): this
 * transform rewrites every inline block component a display names by
 * revision id into the value shape, at any depth, and the display save
 * that follows garbage-collects the entities it no longer names.
 *
 * Only the persisted shape is touched: a block riding serialized (the
 * staged shape) is the save seam's to store
 * (InlineBlockEntityOperations), and content hosts keep their entities. A
 * block the grammar cannot spell is left as it is, with a warning, rather
 * than losing a field.
 */
class ValueBlockTransform implements ComponentTransformInterface {

  public function __construct(
    protected DaedalusLayoutAdapter $adapter,
    protected InlineBlockValues $inlineBlockValues,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool {
    if (!$host instanceof LayoutEntityDisplayInterface) {
      return FALSE;
    }
    $configuration = $component->get('configuration');
    return str_starts_with((string) ($configuration['id'] ?? ''), 'inline_block:')
      && !empty($configuration['block_revision_id'])
      && empty($configuration['block_serialized']);
  }

  /**
   * {@inheritdoc}
   */
  public function transform(SectionComponent $component, EntityInterface $host): void {
    $block = $this->adapter->extractBlockContent($component);
    if ($block === NULL) {
      $this->logger->warning('Inline block @uuid on display @display names a block revision that no longer exists; it was left as it is.', [
        '@uuid' => $component->getUuid(),
        '@display' => $host->id(),
      ]);
      return;
    }
    $unspellable = $this->inlineBlockValues->unspellable($block);
    if ($unspellable !== []) {
      $this->logger->warning('Inline block @uuid (@type) on display @display holds fields the field grammar cannot spell (@fields); it stays a block entity.', [
        '@uuid' => $component->getUuid(),
        '@type' => $block->bundle(),
        '@display' => $host->id(),
        '@fields' => implode(', ', $unspellable),
      ]);
      return;
    }
    $this->inlineBlockValues->store($component, $block);
  }

}
