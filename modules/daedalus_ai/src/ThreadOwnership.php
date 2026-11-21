<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\daedalus_blueprint\Dialect\ConversationOwnershipInterface;

/**
 * Knows which chat threads were handed to which user.
 *
 * A thread id reaches the browser from the server (the chat sidebar's
 * settings, the chat reset's answer) and comes back on requests that key
 * shared state by it: the attach route's held files and names, the op
 * endpoint's aliases. Those doors accept the id only when it was handed to
 * the user sending it, which is what this answers, from two records:
 *
 * - The sidebar's own: ChatSidebar records the id it hands out (record()),
 *   kept per user for LIFETIME and renewed on every build. It covers every
 *   way the assistant mints an id, including one no message has used yet.
 * - The assistant's pointer to the user's ongoing thread, which is where an
 *   id minted by the chat reset lives before the page is built again. An
 *   assistant that keeps no such pointer (a pool of threads) hands its
 *   reset id out unrecorded: that thread takes attachments once the page
 *   has been loaded again.
 *
 * An anonymous user owns no thread: every anonymous request shares one
 * user id, so a record under it would be everyone's.
 */
final class ThreadOwnership implements ConversationOwnershipInterface {

  /**
   * The expirable key-value collection of handed-out threads.
   *
   * Keyed "<uid>:<thread id>".
   */
  private const COLLECTION = 'daedalus_ai.thread_owner';

  /**
   * How long a handed-out thread stays the user's without being handed again.
   */
  public const LIFETIME = 604800;

  /**
   * The private tempstore collection ai_assistant_api keeps its pointer in.
   *
   * AiAssistantApiRunner::getTempStore() opens it and keeps the user's
   * ongoing thread under "current_thread_id_<assistant id>".
   */
  private const ASSISTANT_COLLECTION = 'ai_assistant_api';

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Records that the thread was handed to the current user.
   */
  public function record(string $thread): void {
    if ($thread === '' || $this->currentUser->isAnonymous()) {
      return;
    }
    $this->keyValueExpirable->get(self::COLLECTION)->setWithExpire($this->key($thread), TRUE, self::LIFETIME);
  }

  /**
   * {@inheritdoc}
   */
  public function owns(string $conversation): bool {
    if ($conversation === '' || $this->currentUser->isAnonymous()) {
      return FALSE;
    }
    if ($this->keyValueExpirable->get(self::COLLECTION)->has($this->key($conversation))) {
      return TRUE;
    }
    $assistant = $this->configFactory->get('daedalus_ai.settings')->get('assistant_id');
    return is_string($assistant) && $assistant !== ''
      && $this->tempStoreFactory->get(self::ASSISTANT_COLLECTION)->get('current_thread_id_' . $assistant) === $conversation;
  }

  /**
   * The record's key: the current user and the thread.
   */
  private function key(string $thread): string {
    return $this->currentUser->id() . ':' . $thread;
  }

}
