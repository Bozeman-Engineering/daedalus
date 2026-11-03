<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

/**
 * The types of entry a segment of the transcript holds, in wire order.
 *
 * Each value is the drupal/ai ChatMessage role the entry renders to and
 * absorbs from; the transcript keeps the typed view, the wrapper's history
 * the flat one.
 */
enum EntryType: string {

  // A user turn: the user's words with the per-turn blocks beside them, or
  // a user message the wrapper itself appended (screenshots from a tool).
  case User = 'user';

  // An assistant turn: text, tool calls and the opaque per-provider slot.
  case Assistant = 'assistant';

  // The result of one tool call, keyed when it states a known fact.
  case ToolResult = 'tool';

  // An instruction the wrapper appended mid-conversation.
  case System = 'system';

  /**
   * The entry type a ChatMessage role maps to.
   *
   * @param string $role
   *   The role as ChatMessage::getRole() answers it.
   *
   * @throws \InvalidArgumentException
   *   When the role is none the transcript knows.
   */
  public static function fromRole(string $role): self {
    return self::tryFrom($role)
      ?? throw new \InvalidArgumentException(sprintf('Unknown chat message role "%s"; the transcript holds user, assistant, tool and system entries.', $role));
  }

  /**
   * The ChatMessage role this type renders to.
   */
  public function role(): string {
    return $this->value;
  }

}
