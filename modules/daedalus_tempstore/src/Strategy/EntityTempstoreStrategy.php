<?php

namespace Drupal\daedalus_tempstore\Strategy;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\daedalus_tempstore\SectionStorageHelper;
use Drupal\daedalus_tempstore\WorkspaceKeyTrait;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Entity tempstore strategy.
 *
 * Provides temporary storage for entity drafts being edited. This is the
 * base strategy for entity-based tempstore, handling any content entity type.
 *
 * Storage format: ['entity' => EntityInterface]
 */
class EntityTempstoreStrategy implements TempstoreStrategyInterface {

  use WorkspaceKeyTrait;

  /**
   * Constructs an EntityTempstoreStrategy.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The shared tempstore factory.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, or NULL if workspaces module is not enabled.
   * @param \Drupal\daedalus_tempstore\History\HistoryRecorder|null $historyRecorder
   *   The history recorder capturing writes for undo.
   */
  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?HistoryRecorder $historyRecorder = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports($subject): bool {
    // A config entity that is its own section list (a Layout Builder
    // enabled display, a site frame) is the layout strategy's: it stages
    // as its own section storage, so the staged layout, Save, Discard and
    // History share one key.
    if ($subject instanceof EntityInterface && SectionStorageHelper::isSectionListConfig($subject)) {
      return FALSE;
    }
    return $subject instanceof EntityInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function get($subject) {
    $key = $this->getKey($subject);
    $tempstore = $this->getTempstore($subject)->get($key);

    if (!empty($tempstore['entity'])) {
      $entity = $tempstore['entity'];

      if (!($entity instanceof EntityInterface)) {
        throw new \UnexpectedValueException(sprintf('The "%s" entry is invalid', $key));
      }

      return $entity;
    }

    return $subject;
  }

  /**
   * {@inheritdoc}
   */
  public function set($subject): void {
    $key = $this->getKey($subject);
    $envelope = ['entity' => $subject];
    // The history tee: capture the envelope at the one place every mutation
    // path writes staged state, just before it lands.
    $this->historyRecorder?->recordWrite($this->getCollection($subject), $key, $envelope);
    $this->getTempstore($subject)->set($key, $envelope);
  }

  /**
   * {@inheritdoc}
   */
  public function has($subject): bool {
    $key = $this->getKey($subject);
    $tempstore = $this->getTempstore($subject)->get($key);
    return !empty($tempstore['entity']);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($subject): void {
    $key = $this->getKey($subject);
    // Staged state is ending (save or discard) — its history goes with it.
    $this->historyRecorder?->recordDelete($this->getCollection($subject), $key);
    $this->getTempstore($subject)->delete($key);
  }

  /**
   * {@inheritdoc}
   */
  public function getKey($subject): string {
    $key = $subject->getEntityTypeId() . '.' . $subject->id();

    if ($subject instanceof TranslatableInterface) {
      $key .= '.' . $subject->language()->getId();
    }
    else {
      // Use 'und' (undefined) for non-translatable entities.
      $key .= '.und';
    }

    return $this->appendWorkspaceToKey($key);
  }

  /**
   * {@inheritdoc}
   */
  public function getCollection($subject): string {
    return 'entity_tempstore.entity_storage';
  }

  /**
   * Gets the shared tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   The tempstore.
   */
  protected function getTempstore(EntityInterface $entity) {
    return $this->tempStoreFactory->get($this->getCollection($entity));
  }

}
