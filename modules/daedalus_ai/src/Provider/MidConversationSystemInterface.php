<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

/**
 * A provider plugin that passes a system-role history message through.
 *
 * A system-role ChatMessage inside the history is a mid-conversation
 * operator instruction: it belongs at its position in the transcript, after
 * the messages before it and before the ones after, never folded into the
 * top-level system prompt (the contrib Anthropic provider concatenates them
 * there; Apple's overwrites the prompt with the last one). A provider
 * implementing this interface renders it in place (Anthropic's role: system
 * message in messages[]). A provider without it gets the floor: the spine
 * renders the instruction as text inside the next user message instead.
 */
interface MidConversationSystemInterface {

  /**
   * Whether the provider keeps a system-role history message in place.
   *
   * @param string $model_id
   *   The model id the request will run on.
   */
  public function supportsMidConversationSystem(string $model_id): bool;

}
