<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * One message of a parsed chat prompt.
 *
 * A NULL role marks text that sits outside the chat structure: the whole
 * body of a non-chat prompt (text_to_image and the like), or any preamble
 * that precedes the first role line of a chat prompt.
 */
final readonly class Message {

  /**
   * Constructs a Message.
   *
   * @param string|null $role
   *   The role line that opened the message, or NULL for free text.
   * @param string $text
   *   The message text, without the trailing newline ChatInput appends.
   */
  public function __construct(
    public ?string $role,
    public string $text,
  ) {}

  /**
   * Renders the message back into the prompt column's shape.
   *
   * @return string
   *   "role\ntext\n" for a chat message, "text\n" for free text.
   */
  public function toString(): string {
    return ($this->role === NULL ? '' : $this->role . "\n") . $this->text . "\n";
  }

}
