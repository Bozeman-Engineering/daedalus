<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Message;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Utility\Xss;
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

  /**
   * The tags a message's display markup may carry.
   */
  public const DISPLAY_TAGS = ['a', 'em', 'strong'];

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
   * The client's notification display shows a message as text, so each one
   * leaves here as text: see text().
   *
   * @return array
   *   Stored messages keyed by type, each an array with 'text' (the message
   *   as plain text) and 'html' (its display markup, or NULL when the
   *   message is text only).
   */
  public function getStoredMessages(): array {
    return array_map(
      static fn(array $messages): array => array_map(
        static fn(MarkupInterface|string $message): array => [
          'text' => self::text($message),
          'html' => self::html($message),
        ],
        $messages,
      ),
      $this->innerMessenger->all(),
    );
  }

  /**
   * A stored message as the text the client's notification display shows.
   *
   * Core's messenger holds two kinds of value and its template tells them
   * apart: a markup object is printed as it is, a plain string is escaped,
   * so a plain string is already text. A markup object becomes text by
   * dropping its tags and decoding its entities.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   A message as the messenger stored it.
   *
   * @return string
   *   The message as plain text.
   */
  public static function text(MarkupInterface|string $message): string {
    return $message instanceof MarkupInterface
      ? PlainTextOutput::renderFromHtml($message)
      : $message;
  }

  /**
   * A stored message's display markup: its links and emphasis, nothing else.
   *
   * Only a markup object has any (core's template escapes a plain string),
   * and only the tags in DISPLAY_TAGS are kept: every other tag is dropped,
   * as is every event handler attribute, style attribute and link protocol
   * core's filter does not allow. The client writes this value into the
   * notification as it is, so nothing may be added to it after this filter.
   *
   * @param \Drupal\Component\Render\MarkupInterface|string $message
   *   A message as the messenger stored it.
   *
   * @return string|null
   *   The filtered markup, or NULL when the message is a plain string or
   *   carries none of the kept tags (its text says everything).
   */
  public static function html(MarkupInterface|string $message): ?string {
    if (!$message instanceof MarkupInterface) {
      return NULL;
    }
    $html = Xss::filter((string) $message, self::DISPLAY_TAGS);
    return strip_tags($html) === $html ? NULL : $html;
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
