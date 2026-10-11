<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Plugin\Block;

use Drupal\Core\Access\AccessibleInterface;
use Drupal\Core\Access\RefinableDependentAccessInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\block_content\BlockContentInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\layout_builder\Plugin\Block\InlineBlock as CoreInlineBlock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The inline block plugin, hydrating a value block.
 *
 * Serves every inline_block:* derivative (daedalus_layout's block_alter
 * swaps the class). Core's plugin knows two storage shapes for its block:
 * block_serialized (an unsaved block, the staged shape) and
 * block_revision_id (a saved block, a content host's stored shape). This
 * subclass adds the third, block_values (a value block: the block's fields
 * in the field grammar, a display host's stored shape), hydrated to an
 * unsaved block at build so the render, the Configure dialog and the
 * access chain see a block entity as they always did.
 *
 * A value block placed on a content host (a page seeded from its
 * template) becomes a block entity at the host's save, through the same
 * saveBlockContent() core calls for a serialized block: hydrated first,
 * then saved, its revision id recorded and its values dropped.
 *
 * @see \Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues
 */
class InlineBlock extends CoreInlineBlock {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepositoryInterface $entity_display_repository,
    AccountInterface $current_user,
    protected InlineBlockValues $inlineBlockValues,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_display_repository, $current_user);
    if (InlineBlockValues::hasValues($this->configuration)) {
      $this->isNew = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('current_user'),
      $container->get('daedalus_blueprint.inline_block_values'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * A serialized or saved block takes core's road; a value block hydrates.
   */
  protected function getEntity() {
    if (!isset($this->blockContent) && $this->hasValueBlock()) {
      $this->blockContent = $this->inlineBlockValues->hydrate($this->getDerivativeId(), $this->configuration[InlineBlockValues::KEY]);
      if ($this->blockContent instanceof RefinableDependentAccessInterface && $dependee = $this->getAccessDependency()) {
        $this->blockContent->setAccessDependency($dependee);
      }
    }
    return parent::getEntity();
  }

  /**
   * {@inheritdoc}
   *
   * Reaches the block the plugin already materialized. Core hands the
   * block its dependency only when getEntity() first builds it, and a
   * reader ahead of core's render subscriber (the root entity registration,
   * through blockContent()) builds it before the plugin holds one: an
   * unsaved block (staged or hydrated from values) has no usage row to
   * answer for it, so outside the preview it would be denied.
   */
  public function setAccessDependency(AccessibleInterface $access_dependency) {
    parent::setAccessDependency($access_dependency);
    if (isset($this->blockContent) && $this->blockContent instanceof RefinableDependentAccessInterface) {
      $this->blockContent->setAccessDependency($access_dependency);
    }
    return $this;
  }

  /**
   * The block content this placement renders, whatever its stored shape.
   *
   * The one entity instance the plugin builds and renders (serialized,
   * saved or hydrated from values, memoized), so a reader keyed on the
   * rendered entity's identity (the root entity registry, editable
   * attribution) reads the same uuid the render carries. A value block's
   * hydration mints a fresh uuid, so re-hydrating outside the plugin would
   * name an entity nothing rendered.
   */
  public function blockContent(): ?BlockContentInterface {
    $entity = $this->getEntity();
    return $entity instanceof BlockContentInterface ? $entity : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * On a content host a value block is saved as an entity: hydrated into
   * the serialized slot core saves from, its values dropped with the save.
   */
  public function saveBlockContent($new_revision = FALSE, $duplicate_block = FALSE) {
    if ($this->hasValueBlock()) {
      $this->configuration['block_serialized'] = serialize($this->inlineBlockValues->hydrate($this->getDerivativeId(), $this->configuration[InlineBlockValues::KEY]));
      unset($this->configuration[InlineBlockValues::KEY]);
    }
    parent::saveBlockContent($new_revision, $duplicate_block);
  }

  /**
   * Whether the block is stored as values and nothing else names it.
   */
  protected function hasValueBlock(): bool {
    return empty($this->configuration['block_serialized'])
      && empty($this->configuration['block_revision_id'])
      && InlineBlockValues::hasValues($this->configuration);
  }

}
