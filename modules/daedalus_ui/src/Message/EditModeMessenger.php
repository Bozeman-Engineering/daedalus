<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Message;

use Drupal\daedalus_ui\ModeState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Decorates the Messenger service to intercept messages in Edit Mode.
 *
 * When in Edit Mode, this decorator:
 * - Intercepts addMessage() and related methods
 * - Filters unwanted messages (e.g., "You have unsaved changes")
 * - Stores filtered messages for later delivery via EditModeMessageCommands
 * - Prevents messages from reaching inner messenger.
 */
class EditModeMessenger implements MessengerInterface {

  use StringTranslationTrait;

  public function __construct(
    protected MessengerInterface $innerMessenger,
    protected ModeState $modeState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function addMessage($message, $type = self::TYPE_STATUS, $repeat = FALSE) {
    if ((string) $message === (string) $this->t('You have unsaved changes.')) {
      return $this;
    }
    return $this->innerMessenger->addMessage($message, $type, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addStatus($message, $repeat = FALSE) {
    return $this->addMessage($message, self::TYPE_STATUS, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addError($message, $repeat = FALSE) {
    return $this->addMessage($message, self::TYPE_ERROR, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addWarning($message, $repeat = FALSE) {
    return $this->addMessage($message, self::TYPE_WARNING, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function all() {
    if ($this->modeState->getMode() === 'edit') {
      return [];
    }
    return $this->innerMessenger->all();
  }

  /**
   * {@inheritdoc}
   */
  public function messagesByType($type) {
    if ($this->modeState->getMode() === 'edit') {
      return [];
    }
    return $this->innerMessenger->messagesByType($type);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    if ($this->modeState->getMode() === 'edit') {
      return [];
    }
    return $this->innerMessenger->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByType($type) {
    if ($this->modeState->getMode() === 'edit') {
      return [];
    }
    return $this->innerMessenger->deleteByType($type);
  }

  /**
   * Gets stored messages for delivery (non-destructive read).
   *
   * Drupal core will call ->all() which is ignored in Edit Mode. Things in Edit
   * Mode use this method instead.
   *
   * @return array
   *   Stored messages keyed by type.
   */
  public function getStoredMessages(): array {
    return $this->innerMessenger->all();
  }

  /**
   * Clears stored messages.
   *
   * Drupal core will call ->deleteAll() which is ignored in Edit Mode. Things
   * in Edit Mode use this method instead.
   */
  public function clearStoredMessages(): void {
    $this->innerMessenger->deleteAll();
  }

}
