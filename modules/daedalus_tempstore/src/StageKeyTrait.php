<?php

namespace Drupal\daedalus_tempstore;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

/**
 * The parts of a stage key every tempstore strategy shares.
 *
 * A stage is one per host, and a host is the whole entity: every
 * translation of a page shares one unsaved copy. The key is therefore
 * computed from the host's own object (its default translation), never
 * from the translation a caller happens to hold, so a route that upcasts
 * the page in the visitor's language addresses the same stage as a writer
 * that loaded the page from storage. The workspace suffix isolates the
 * stage per workspace, following the same pattern as core's langcode
 * handling in getTempstoreKey().
 */
trait StageKeyTrait {

  /**
   * The object a stage is keyed by: the host's own, whatever was handed in.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity as a caller holds it, in any translation.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity's default translation; the entity itself when it is not
   *   translatable.
   */
  protected function stageHost(EntityInterface $entity): EntityInterface {
    return $entity instanceof TranslatableInterface ? $entity->getUntranslated() : $entity;
  }

  /**
   * Answers a staged entity in the language its asker holds the host in.
   *
   * The stage is shared by every translation, so a get() by a translation
   * answers that translation of the unsaved copy.
   *
   * @param \Drupal\Core\Entity\EntityInterface $staged
   *   The unsaved copy.
   * @param \Drupal\Core\Entity\EntityInterface $subject
   *   The entity the caller asked with.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The unsaved copy in the subject's language when it has that
   *   translation, else as it was staged.
   */
  protected function inLanguageOf(EntityInterface $staged, EntityInterface $subject): EntityInterface {
    if (!$staged instanceof TranslatableInterface || !$subject instanceof TranslatableInterface) {
      return $staged;
    }
    $langcode = $subject->language()->getId();
    if ($staged->language()->getId() === $langcode || !$staged->hasTranslation($langcode)) {
      return $staged;
    }
    return $staged->getTranslation($langcode);
  }

  /**
   * Appends workspace ID to a tempstore key.
   *
   * Requires $this->workspaceManager property to be available (can be NULL).
   *
   * If workspaces module is enabled and there's an active workspace,
   * appends ".{workspace_id}" to the key. Otherwise appends ".live"
   * to indicate the live workspace.
   *
   * @param string $key
   *   The base tempstore key.
   *
   * @return string
   *   The key with workspace ID appended.
   */
  protected function appendWorkspaceToKey(string $key): string {
    if ($this->workspaceManager === NULL) {
      return $key;
    }

    if ($this->workspaceManager->hasActiveWorkspace()) {
      return $key . '.workspace:' . $this->workspaceManager->getActiveWorkspace()->id();
    }

    return $key . '.live';
  }

}
