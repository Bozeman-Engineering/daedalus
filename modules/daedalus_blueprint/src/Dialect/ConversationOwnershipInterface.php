<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * Answers whether a conversation is the current user's.
 *
 * A conversation id keys state that is shared by id (the alias layer's
 * names, the files a conversation holds), so a door that takes the id from
 * a request asks here first. The implementer is whoever hands conversation
 * ids out (daedalus_ai, over the chat's threads), collected onto
 * ConversationGate through the daedalus_blueprint.conversation_ownership
 * service tag.
 */
interface ConversationOwnershipInterface {

  /**
   * Whether the conversation was handed to the current user.
   *
   * @param string $conversation
   *   The conversation (thread) id.
   */
  public function owns(string $conversation): bool;

}
