<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

/**
 * A provider plugin that keeps a system-role history message in place.
 *
 * An operator can speak in the middle of a conversation: a ChatMessage with
 * the system role then sits inside the history, between the turns before it
 * and the turns after. The instruction belongs where it was given. Folding
 * it into the top-level system prompt loses that position, and providers
 * that fold disagree about how (one appends every such message to the
 * prompt, another keeps only the last one). A provider implementing this
 * interface renders the message at its place in the transcript, the way
 * Anthropic's API accepts a system-role entry in messages[]. A provider
 * without it gets the floor: the spine writes the instruction as text at
 * the head of the next user message instead, so nothing is lost, only the
 * role.
 */
interface MidConversationSystemInterface {

  /**
   * Whether the provider renders a system-role history message in place.
   *
   * @param string $model_id
   *   The model id the request will run on; support can differ per model.
   */
  public function supportsMidConversationSystem(string $model_id): bool;

}
