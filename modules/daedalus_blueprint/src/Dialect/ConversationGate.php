<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * The one question a door asks before it accepts a conversation id.
 *
 * The op spine's 'conversation' option names whose aliases and held files
 * a write resolves against. The page tools take it from the running chat
 * turn; a door that reads it from a request body (the HTTP op endpoint)
 * accepts it only when this gate says the current user owns it. On a site
 * where nothing hands conversations out (no implementer collected) nobody
 * owns one, so such a door accepts none.
 */
final class ConversationGate {

  /**
   * Who knows the conversations, or NULL on a site without one.
   */
  private ?ConversationOwnershipInterface $ownership = NULL;

  /**
   * Takes the implementer (the service collector's call).
   */
  public function setOwnership(ConversationOwnershipInterface $ownership): void {
    $this->ownership = $ownership;
  }

  /**
   * Whether the current user owns the conversation.
   */
  public function owns(string $conversation): bool {
    return $conversation !== '' && $this->ownership !== NULL && $this->ownership->owns($conversation);
  }

}
